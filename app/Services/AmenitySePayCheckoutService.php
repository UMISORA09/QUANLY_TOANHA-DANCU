<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class AmenitySePayCheckoutService
{
    public function __construct(public AmenityBookingPaymentService $payments) {}

    public static function available(): bool
    {
        $environment = config('amenity_payments.checkout_environment');

        return in_array($environment, ['sandbox', 'production'], true)
            && ! ($environment === 'sandbox' && app()->environment('production'))
            && filled(config('amenity_payments.checkout_merchant_id'))
            && filled(config('amenity_payments.checkout_secret'))
            && filled(config('amenity_payments.checkout_ipn_secret'));
    }

    private function checkoutId(string $invoice): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'sepay-checkout:'.config('amenity_payments.checkout_environment').':'.config('amenity_payments.checkout_merchant_id').':'.$invoice);
    }

    /** @return array{environment: string, action: string, fields: array<string, string>} */
    public function create(Request $request, string $id): array
    {
        abort_unless(self::available(), 503, 'Chưa cấu hình cổng thanh toán SePay.');
        $this->payments->resident($request, $id);

        return $this->payments->locked($id, function (object $booking, ?object $payment) use ($request): array {
            abort_unless($booking->resident_user_id === $request->user()->id, 404);
            abort_unless($payment && $this->payments->format($payment, $booking)['can_pay'], 409, 'Đăng ký chưa được duyệt hoặc không còn được thanh toán.');
            $environment = config('amenity_payments.checkout_environment');
            $invoice = ($environment === 'sandbox' ? 'SBX-' : 'PG-').$payment->reference;
            $returnUrl = url('/cu-dan').'?'.http_build_query(['tab' => 'amenities', 'booking_id' => $booking->id]);
            $fields = [
                'merchant' => (string) config('amenity_payments.checkout_merchant_id'), 'currency' => 'VND',
                'order_amount' => (string) (int) $payment->amount, 'operation' => 'PURCHASE',
                'order_description' => 'Thanh toan tien ich '.$booking->booking_code,
                'payment_method' => 'BANK_TRANSFER', 'order_invoice_number' => $invoice,
                'success_url' => $returnUrl, 'error_url' => $returnUrl, 'cancel_url' => $returnUrl,
            ];
            // SePay signs comma-separated name=value fields in the submitted order.
            $signed = [];
            foreach ($fields as $name => $value) {
                $signed[] = $name.'='.$value;
            }
            $fields['signature'] = base64_encode(hash_hmac('sha256', implode(',', $signed), (string) config('amenity_payments.checkout_secret'), true));
            $auditId = $this->checkoutId($invoice);
            if (! DB::table('audit_logs')->where('id', $auditId)->exists()) {
                DB::table('audit_logs')->insert([
                    'id' => $auditId, 'table_name' => 'amenity_payment_checkouts', 'record_id' => $payment->id, 'action' => 'INSERT',
                    'performed_by_user_id' => $request->user()->id, 'client_ip_address' => $request->ip(),
                    'new_data' => json_encode(['invoice' => $invoice, 'environment' => $environment, 'merchant' => $fields['merchant'], 'amount' => (int) $payment->amount], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            return ['environment' => $environment, 'action' => $environment === 'sandbox' ? 'https://pay-sandbox.sepay.vn/v1/checkout/init' : 'https://pay.sepay.vn/v1/checkout/init', 'fields' => $fields];
        });
    }

    /** @param array<string, mixed> $data */
    public function receive(Request $request, array $data): void
    {
        $eventId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'sepay-ipn:'.config('amenity_payments.checkout_environment').':'.$data['transaction']['id'].':'.$data['notification_type']);
        try {
            DB::transaction(function () use ($request, $data, $eventId): void {
                $checkout = DB::table('audit_logs')->where('id', $this->checkoutId($data['order']['order_invoice_number']))->where('table_name', 'amenity_payment_checkouts')->first();
                $payment = $checkout ? DB::table('amenity_booking_payments')->where('id', $checkout->record_id)->first() : null;
                $booking = $payment ? DB::table('amenity_bookings')->where('id', $payment->booking_id)->whereNull('deleted_at')->first() : null;
                if ($booking) {
                    DB::table('amenities')->where('id', $booking->amenity_id)->lockForUpdate()->first();
                }
                if (DB::table('audit_logs')->where('id', $eventId)->exists()) {
                    return;
                }
                DB::table('audit_logs')->insert([
                    'id' => $eventId, 'table_name' => 'amenity_payment_ipns', 'record_id' => $eventId, 'action' => 'INSERT',
                    'client_ip_address' => $request->ip(), 'new_data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                $orderAmount = (float) $data['order']['order_amount'];
                $receivedAmount = (float) $data['transaction']['transaction_amount'];
                $expected = $checkout ? json_decode($checkout->new_data, true, flags: JSON_THROW_ON_ERROR) : null;
                if (! $booking || $orderAmount !== (float) ($expected['amount'] ?? 0) || $receivedAmount !== (float) (int) $receivedAmount) {
                    app(ResidentAmenityBookingService::class)->notifyManagers($booking?->amenity_id ?? '', $booking?->booking_code ?? '', 'IPN SePay cần đối soát', 'Thông báo cổng thanh toán không khớp checkout đã tạo hoặc giao dịch không hợp lệ. Kiểm tra SePay trước khi xác nhận.');

                    return;
                }
                if ($data['notification_type'] === 'TRANSACTION_VOID') {
                    $this->payments->reviewVoidedTransaction($booking->id, $data['transaction']['transaction_id']);
                    app(ResidentAmenityBookingService::class)->notifyManagers($booking->amenity_id, $booking->booking_code, 'Giao dịch SePay đã hủy', 'Kiểm tra sao kê và hoàn tiền; không tự khôi phục đăng ký.');

                    return;
                }
                $voidId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'sepay-ipn:'.config('amenity_payments.checkout_environment').':'.$data['transaction']['id'].':TRANSACTION_VOID');
                if (DB::table('audit_logs')->where('id', $voidId)->lockForUpdate()->first()
                    || $data['order']['order_status'] !== 'CAPTURED' || $data['transaction']['transaction_type'] !== 'PAYMENT'
                    || $data['transaction']['transaction_status'] !== 'APPROVED') {
                    app(ResidentAmenityBookingService::class)->notifyManagers($booking->amenity_id, $booking->booking_code, 'IPN SePay cần đối soát', 'Giao dịch đã bị hủy hoặc trạng thái thanh toán không hợp lệ. Không tự xác nhận.');

                    return;
                }
                try {
                    $this->payments->decide($request, $booking->amenity_id, $booking->id, [
                        'bank_transaction_id' => $data['transaction']['transaction_id'],
                        'received_amount' => (int) $receivedAmount, 'received_at' => $data['transaction']['transaction_date'],
                    ], automatic: true);
                } catch (ValidationException $exception) {
                    if (! isset($exception->errors()['bank_transaction_id'])) {
                        throw $exception;
                    }
                    app(ResidentAmenityBookingService::class)->notifyManagers($booking->amenity_id, $booking->booking_code, 'IPN SePay cần đối soát', 'Mã giao dịch đã được sử dụng cho đăng ký khác. Không tự xác nhận.');
                }
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! DB::table('audit_logs')->where('id', $eventId)->exists()) {
                throw $exception;
            }
        }
    }
}
