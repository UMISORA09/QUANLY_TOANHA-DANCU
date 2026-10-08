<?php

namespace App\Http\Controllers;

use App\Services\PaymentCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentCollectionService $paymentService
    ) {}

    /**
     * POST /api/v1/payments/collect
     * Thu tiền và cập nhật gạch nợ tức thời cho hóa đơn
     */
    public function collect(Request $request): JsonResponse
    {
        // Hỗ trợ cả amount và amount_paid, payment_method và payment_gateway
        if ($request->has('amount') && ! $request->has('amount_paid')) {
            $request->merge(['amount_paid' => $request->input('amount')]);
        }
        if ($request->has('payment_method') && ! $request->has('payment_gateway')) {
            $request->merge(['payment_gateway' => $request->input('payment_method')]);
        }
        if ($request->has('transaction_id') && ! $request->has('gateway_transaction_id')) {
            $request->merge(['gateway_transaction_id' => $request->input('transaction_id')]);
        }

        $validated = $request->validate([
            'invoice_id' => ['required', 'string'],
            'amount_paid' => ['required', 'numeric', 'min:1'],
            'payment_gateway' => ['nullable', 'string', 'in:CASH,BANK_TRANSFER,VIETQR_BANK_TRANSFER,VIETQR,VNPAY,MOMO'],
            'gateway_transaction_id' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'payer_user_id' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_bin' => ['nullable', 'string', 'max:20'],
        ], [
            'invoice_id.required' => 'Mã hóa đơn không được để trống.',
            'amount_paid.required' => 'Vui lòng nhập số tiền thanh toán.',
            'amount_paid.min' => 'Số tiền thanh toán phải lớn hơn 0.',
        ]);

        $staffUserId = $request->user()?->id ? (string) $request->user()->id : null;

        try {
            $result = $this->paymentService->collectPayment($validated, $staffUserId);

            $statusMessage = $result['is_fully_paid']
                ? 'Ghi nhận thanh toán và gạch nợ thành công'
                : 'Đã thu tiền và cập nhật gạch nợ một phần thành công.';

            $isDuplicate = $result['is_duplicate'] ?? false;
            $statusCode = $isDuplicate ? 200 : 201;

            return response()->json([
                'success' => true,
                'message' => $statusMessage,
                'data' => $result,
            ], $statusCode);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function collectPayment(Request $request): JsonResponse
    {
        return $this->collect($request);
    }

    /**
     * GET /api/v1/payments
     * Lịch sử các giao dịch thu tiền (Chức năng 10 - Xem lịch sử giao dịch các kỳ trước)
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'invoice_id',
            'apartment_id',
            'payment_gateway',
            'payment_method',
            'payment_status',
            'billing_period',
            'date_from',
            'date_to',
            'block_id',
            'search',
            'per_page',
        ]);

        if (isset($filters['payment_method']) && ! isset($filters['payment_gateway'])) {
            $filters['payment_gateway'] = $filters['payment_method'];
        }

        $payments = $this->paymentService->listPayments($filters);

        return response()->json([
            'success' => true,
            'data' => $payments,
        ]);
    }

    public function listPayments(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    /**
     * GET /api/v1/payments/summary
     * Thống kê tổng hợp số tiền và số lượng giao dịch theo bộ lọc
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->only([
            'apartment_id',
            'payment_gateway',
            'billing_period',
            'date_from',
            'date_to',
        ]);

        $summary = $this->paymentService->getPaymentSummary($filters);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * GET /api/v1/payments/{id}/receipt
     * Xem chi tiết biên lai thu tiền của một giao dịch
     */
    public function receipt(string $id): JsonResponse
    {
        try {
            $data = $this->paymentService->getReceiptDetail($id);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * GET /api/v1/invoices/{id}/vietqr-payload
     * Tạo dữ liệu mã QR VietQR chuyển khoản nhanh
     */
    public function getVietQr(string $id): JsonResponse
    {
        $payload = $this->paymentService->getVietQrPayload($id);

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function getVietQrPayload(string $id): JsonResponse
    {
        return $this->getVietQr($id);
    }
}
