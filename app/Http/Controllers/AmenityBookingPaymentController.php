<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmAmenityBookingPaymentRequest;
use App\Services\AmenityBookingPaymentService;
use App\Services\AmenitySePayCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AmenityBookingPaymentController extends Controller
{
    public function __construct(public AmenityBookingPaymentService $payments) {}

    public function checkout(Request $request, string $id, AmenitySePayCheckoutService $checkout): JsonResponse
    {
        return response()->json($checkout->create($request, $id))->header('Cache-Control', 'private, no-store');
    }

    public function ipn(Request $request, AmenitySePayCheckoutService $checkout): JsonResponse
    {
        abort_unless(AmenitySePayCheckoutService::available(), 503, 'Chưa cấu hình cổng thanh toán.');
        abort_unless(hash_equals((string) config('amenity_payments.checkout_ipn_secret'), $request->header('X-Secret-Key', '')), 401);
        $data = $request->validate([
            'timestamp' => 'required|integer|min:1',
            'notification_type' => 'required|in:ORDER_PAID,TRANSACTION_VOID',
            'order.id' => 'required|string|max:100',
            'order.order_invoice_number' => 'required|string|max:100',
            'order.order_status' => 'required|string|max:30',
            'order.order_currency' => 'required|in:VND',
            'order.order_amount' => 'required|numeric|min:1|max:99999999999999',
            'transaction.id' => 'required|string|max:100',
            'transaction.transaction_id' => 'required|string|max:97',
            'transaction.payment_method' => 'required|in:BANK_TRANSFER',
            'transaction.transaction_type' => 'required|string|max:30',
            'transaction.transaction_status' => 'required|string|max:30',
            'transaction.transaction_currency' => 'required|in:VND',
            'transaction.transaction_amount' => 'required|numeric|min:'.($request->input('notification_type') === 'TRANSACTION_VOID' ? '0' : '1').'|max:99999999999999',
            'transaction.transaction_date' => 'required|date_format:Y-m-d H:i:s|before_or_equal:now',
        ]);
        $checkout->receive($request, $data);

        return response()->json(['success' => true]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('amenity_payments.sepay_webhook_secret');
        abort_if($secret === '', 503, 'Chưa cấu hình xác thực thanh toán tự động.');
        $timestamp = $request->header('X-SePay-Timestamp', '');
        abort_unless(ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= 300, 401);
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($signature, $request->header('X-SePay-Signature', '')), 401);
        $data = $request->validate([
            'id' => 'required|integer|min:0',
            'gateway' => 'required|string|max:80',
            'accountNumber' => 'required|string|max:50',
            'transactionDate' => 'required|date_format:Y-m-d H:i:s|before_or_equal:now',
            'transferType' => 'required|in:in,out',
            'transferAmount' => 'required|integer|min:1|max:99999999999999',
            'content' => 'required|string|max:2000',
            'code' => 'nullable|string|max:100',
            'referenceCode' => 'nullable|string|max:100',
        ]);
        $this->payments->webhook($request, $data);

        return response()->json(['success' => true]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['payment' => $this->payments->resident($request, $id)])->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, string $id): JsonResponse
    {
        return response()->json(['payment' => $this->payments->resident($request, $id, true)]);
    }

    public function confirm(ConfirmAmenityBookingPaymentRequest $request, string $amenityId, string $bookingId): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['payment' => $this->payments->decide($request, $amenityId, $bookingId, $data)]);
    }

    public function reject(Request $request, string $amenityId, string $bookingId): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        return response()->json(['payment' => $this->payments->decide($request, $amenityId, $bookingId, $data, true)]);
    }
}
