<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentCollectionService
{
    /**
     * API Thu tiền & Cập nhật gạch nợ tức thời bằng Database Transaction
     *
     * @param  array{
     *     invoice_id: string,
     *     amount_paid: float,
     *     payment_gateway?: string,
     *     gateway_transaction_id?: ?string,
     *     idempotency_key?: ?string,
     *     payer_user_id?: ?string,
     *     notes?: ?string,
     *     bank_account_number?: ?string,
     *     bank_bin?: ?string
     * }  $data
     * @return array{
     *     payment: Payment,
     *     receipt: PaymentReceipt,
     *     invoice: Invoice,
     *     is_fully_paid: bool
     * }
     *
     * @throws \DomainException
     */
    public function collectPayment(array $data, ?string $staffUserId = null): array
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        // 1. Kiểm tra Idempotency chống thanh toán 2 lần
        if ($idempotencyKey) {
            $existingPayment = Payment::with(['receipt', 'invoice'])
                ->where('idempotency_key', $idempotencyKey)
                ->where('payment_status', 'SUCCESS')
                ->first();

            if ($existingPayment) {
                return [
                    'payment' => $existingPayment,
                    'receipt' => $existingPayment->receipt,
                    'invoice' => $existingPayment->invoice,
                    'is_fully_paid' => $existingPayment->invoice?->status === 'PAID',
                    'is_duplicate' => true,
                ];
            }
        }

        // 2. Kiểm tra hóa đơn
        $invoice = Invoice::with(['apartment.block', 'residentUser'])->findOrFail($data['invoice_id']);

        if ($invoice->status === 'CANCELLED') {
            throw new \DomainException('Hóa đơn này đã bị hủy bỏ, không thể thu tiền.');
        }

        if ($invoice->status === 'PAID' || $invoice->remaining_balance <= 0) {
            throw new \DomainException('Hóa đơn đã được thanh toán toàn bộ.');
        }

        $amountToPay = (float) $data['amount_paid'];
        if ($amountToPay <= 0) {
            throw new \DomainException('Số tiền thanh toán phải lớn hơn 0 đồng.');
        }

        if ($amountToPay > (float) $invoice->remaining_balance) {
            throw new \DomainException('Số tiền thanh toán không được lớn hơn dư nợ còn lại ('.number_format((float) $invoice->remaining_balance).' đ).');
        }

        $actualPayment = $amountToPay;

        $payerUserId = $data['payer_user_id'] ?? $invoice->resident_user_id;
        $paymentGateway = $data['payment_gateway'] ?? 'CASH';

        return DB::transaction(function () use (
            $invoice,
            $actualPayment,
            $paymentGateway,
            $data,
            $payerUserId,
            $staffUserId,
            $idempotencyKey
        ) {
            // Khóa dòng hóa đơn để đảm bảo concurrency an toàn
            $lockedInvoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

            $cleanPeriod = str_replace('-', '', $lockedInvoice->billing_period);
            $cleanApt = str_replace(['/', ' '], '', $lockedInvoice->apartment?->apartment_number ?? 'APT');
            $refCode = "PAY-{$cleanPeriod}-{$cleanApt}-".Str::upper(Str::random(6));

            // 1. Tạo bản ghi giao dịch Payment
            $payment = Payment::create([
                'payment_reference_code' => $refCode,
                'invoice_id' => $lockedInvoice->id,
                'apartment_id' => $lockedInvoice->apartment_id,
                'payer_user_id' => $payerUserId,
                'amount_paid' => $actualPayment,
                'payment_gateway' => $paymentGateway,
                'gateway_transaction_id' => $data['gateway_transaction_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'payment_status' => 'SUCCESS',
                'payment_time' => Carbon::now(),
                'bank_account_number' => $data['bank_account_number'] ?? null,
                'bank_bin' => $data['bank_bin'] ?? null,
                'recorded_by_staff_id' => $staffUserId,
                'notes' => $data['notes'] ?? "Thu tiền hóa đơn {$lockedInvoice->invoice_number}",
            ]);

            // 2. GẠCH NỢ TỨC THỜI: Cập nhật số tiền đã trả, còn nợ và trạng thái trên Invoice
            $newPaidAmount = round((float) $lockedInvoice->paid_amount + $actualPayment, 2);
            $newRemaining = max(0.00, round((float) $lockedInvoice->total_amount - $newPaidAmount, 2));
            $newStatus = $newRemaining <= 0.001 ? 'PAID' : 'PARTIAL';

            $lockedInvoice->update([
                'paid_amount' => $newPaidAmount,
                'remaining_balance' => $newRemaining,
                'status' => $newStatus,
            ]);

            // 3. TỰ ĐỘNG SINH BIÊN LAI THU TIỀN (PaymentReceipt)
            $receiptNumber = 'BL-'.date('Ym').'-'.str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $payerName = $this->resolvePayerName($payerUserId, $lockedInvoice);
            $amountInWords = $this->convertNumberToWords((int) round($actualPayment)).' đồng chẵn';

            $signatureHash = hash('sha256', "{$receiptNumber}|{$payment->id}|{$actualPayment}|{$lockedInvoice->id}|".config('app.key'));

            $receipt = PaymentReceipt::create([
                'receipt_number' => $receiptNumber,
                'payment_id' => $payment->id,
                'receipt_date' => Carbon::now()->format('Y-m-d'),
                'amount' => $actualPayment,
                'amount_in_words' => $amountInWords,
                'received_from_name' => $payerName,
                'digital_signature_hash' => $signatureHash,
            ]);

            return [
                'payment' => $payment->fresh(['apartment', 'payerUser']),
                'receipt' => $receipt,
                'invoice' => $lockedInvoice->fresh(['apartment.block', 'residentUser']),
                'is_fully_paid' => $newStatus === 'PAID',
                'is_duplicate' => false,
            ];
        });
    }

    /**
     * Lấy thông tin VietQR để cư dân quét mã thanh toán chuyển khoản nhanh
     *
     * @return array<string, mixed>
     */
    public function getVietQrPayload(string $invoiceId): array
    {
        $invoice = Invoice::with(['apartment.block', 'residentUser'])->findOrFail($invoiceId);

        $bankBin = config('services.vietqr.bank_bin', '970422'); // MB Bank
        $bankAccount = config('services.vietqr.bank_account', '0988889999');
        $accountName = config('services.vietqr.account_name', 'BAN QUAN LY TOA NHA SMART');

        $amount = (int) round($invoice->remaining_balance);
        $content = "TT {$invoice->invoice_number}";

        // Link tạo ảnh VietQR nhanh chuẩn NAPAS
        $qrImageUrl = "https://img.vietqr.io/image/{$bankBin}-{$bankAccount}-compact2.png?amount={$amount}&addInfo=".urlencode($content).'&accountName='.urlencode($accountName);

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'apartment_number' => $invoice->apartment?->apartment_number,
            'amount_due' => $amount,
            'transfer_content' => $content,
            'bank_bin' => $bankBin,
            'bank_account_number' => $bankAccount,
            'bank_account_name' => $accountName,
            'qr_image_url' => $qrImageUrl,
        ];
    }

    /**
     * Danh sách lịch sử các giao dịch thu tiền (Chức năng 10 - Xem lịch sử giao dịch các kỳ trước)
     *
     * @param  array<string, mixed>  $filters
     */
    public function listPayments(array $filters = []): LengthAwarePaginator
    {
        $query = Payment::with([
            'invoice',
            'apartment.block',
            'apartment.floor',
            'payerUser',
            'recordedByStaff',
            'receipt',
        ])->orderBy('payment_time', 'desc')->orderBy('created_at', 'desc');

        if (! empty($filters['invoice_id'])) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if (! empty($filters['apartment_id'])) {
            $query->where('apartment_id', $filters['apartment_id']);
        }

        if (! empty($filters['payment_gateway'])) {
            $query->where('payment_gateway', $filters['payment_gateway']);
        }

        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (! empty($filters['billing_period'])) {
            $query->whereHas('invoice', fn ($inv) => $inv->where('billing_period', $filters['billing_period']));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('payment_time', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('payment_time', '<=', $filters['date_to']);
        }

        if (! empty($filters['block_id'])) {
            $query->whereHas('apartment', fn ($apt) => $apt->where('block_id', $filters['block_id']));
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('payment_reference_code', 'like', "%{$search}%")
                    ->orWhere('gateway_transaction_id', 'like', "%{$search}%")
                    ->orWhereHas('invoice', fn ($inv) => $inv->where('invoice_number', 'like', "%{$search}%"))
                    ->orWhereHas('apartment', fn ($apt) => $apt->where('apartment_number', 'like', "%{$search}%"))
                    ->orWhereHas('receipt', fn ($rcp) => $rcp->where('receipt_number', 'like', "%{$search}%"));
            });
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));

        return $query->paginate($perPage);
    }

    /**
     * Thống kê tổng hợp số tiền và số lượng giao dịch theo bộ lọc (Chức năng 10)
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getPaymentSummary(array $filters = []): array
    {
        $query = Payment::query();

        if (! empty($filters['apartment_id'])) {
            $query->where('apartment_id', $filters['apartment_id']);
        }

        if (! empty($filters['payment_gateway'])) {
            $query->where('payment_gateway', $filters['payment_gateway']);
        }

        if (! empty($filters['billing_period'])) {
            $query->whereHas('invoice', fn ($inv) => $inv->where('billing_period', $filters['billing_period']));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('payment_time', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('payment_time', '<=', $filters['date_to']);
        }

        $totalCount = (clone $query)->count();
        $successfulPayments = (clone $query)->where('payment_status', 'SUCCESS');
        $totalAmount = (float) $successfulPayments->sum('amount_paid');
        $successCount = $successfulPayments->count();

        // Thống kê theo phương thức thanh toán
        $byGateway = Payment::where('payment_status', 'SUCCESS')
            ->selectRaw('payment_gateway, count(*) as count, sum(amount_paid) as total')
            ->groupBy('payment_gateway')
            ->get()
            ->keyBy('payment_gateway');

        return [
            'total_transactions' => $totalCount,
            'successful_transactions' => $successCount,
            'total_amount' => $totalAmount,
            'by_gateway' => $byGateway,
        ];
    }

    /**
     * Xem chi tiết biên lai thu tiền theo ID thanh toán
     *
     * @return array<string, mixed>
     */
    public function getReceiptDetail(string $paymentId): array
    {
        $payment = Payment::with([
            'invoice.apartment.block',
            'apartment.block',
            'apartment.floor',
            'payerUser',
            'recordedByStaff',
            'receipt',
        ])->findOrFail($paymentId);

        if (! $payment->receipt) {
            throw new \DomainException("Giao dịch {$payment->payment_reference_code} chưa có biên lai thu tiền.");
        }

        return [
            'payment' => $payment,
            'receipt' => $payment->receipt,
            'invoice' => $payment->invoice,
        ];
    }

    /**
     * Lấy tên người nộp tiền
     */
    private function resolvePayerName(string $payerUserId, Invoice $invoice): string
    {
        $user = User::find($payerUserId);
        if ($user && $user->full_name) {
            return $user->full_name;
        }

        if ($invoice->residentUser && $invoice->residentUser->full_name) {
            return $invoice->residentUser->full_name;
        }

        return "Cư dân căn hộ {$invoice->apartment?->apartment_number}";
    }

    /**
     * Chuyển đổi số tiền thành chữ bằng tiếng Việt
     */
    public function convertNumberToWords(int $number): string
    {
        if ($number === 0) {
            return 'Không';
        }

        $digits = ['', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
        $scales = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ'];

        $numStr = (string) $number;
        $numStr = str_pad($numStr, ceil(strlen($numStr) / 3) * 3, '0', STR_PAD_LEFT);
        $groups = str_split($numStr, 3);
        $totalGroups = count($groups);

        $result = [];

        foreach ($groups as $i => $group) {
            $h = (int) $group[0];
            $t = (int) $group[1];
            $u = (int) $group[2];

            if ($h === 0 && $t === 0 && $u === 0) {
                continue;
            }

            $groupWords = [];

            // Hàng trăm
            if ($h > 0 || count($result) > 0) {
                $groupWords[] = $digits[$h].' trăm';
            }

            // Hàng chục
            if ($t > 1) {
                $groupWords[] = $digits[$t].' mươi';
            } elseif ($t === 1) {
                $groupWords[] = 'mười';
            } elseif ($h > 0 && $u > 0) {
                $groupWords[] = 'lẻ';
            }

            // Hàng đơn vị
            if ($t > 1 && $u === 1) {
                $groupWords[] = 'mốt';
            } elseif ($t > 0 && $u === 5) {
                $groupWords[] = 'lăm';
            } elseif ($u > 0) {
                $groupWords[] = $digits[$u];
            }

            $scaleIndex = $totalGroups - $i - 1;
            if ($scaleIndex > 0) {
                $groupWords[] = $scales[$scaleIndex];
            }

            $result[] = implode(' ', $groupWords);
        }

        $text = implode(' ', $result);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
