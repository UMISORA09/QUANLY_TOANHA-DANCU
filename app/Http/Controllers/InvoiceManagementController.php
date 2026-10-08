<?php

namespace App\Http\Controllers;

use App\Services\InvoiceManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceManagementController extends Controller
{
    public function __construct(
        protected InvoiceManagementService $invoiceService
    ) {}

    /**
     * GET /api/v1/invoices
     * Danh sách hóa đơn phân trang kèm bộ lọc đa chiều
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'billing_period',
            'block_id',
            'issue_date_from',
            'issue_date_to',
            'search',
            'sort_by',
            'sort_order',
            'per_page',
        ]);

        $invoices = $this->invoiceService->listInvoices($filters);

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    /**
     * GET /api/v1/invoices/summary
     * Tổng hợp tài chính KPI hóa đơn theo điều kiện lọc
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'billing_period',
            'block_id',
            'issue_date_from',
            'issue_date_to',
            'search',
        ]);

        $summary = $this->invoiceService->getSummary($filters);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * GET /api/v1/invoices/{id}
     * Chi tiết một hóa đơn và các dòng tính phí dịch vụ
     */
    public function show(string $id): JsonResponse
    {
        $invoice = $this->invoiceService->getInvoiceDetail($id);

        return response()->json([
            'success' => true,
            'data' => $invoice,
        ]);
    }

    /**
     * POST /api/v1/invoices/{id}/cancel
     * Hủy một hóa đơn chưa thanh toán
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $invoice = $this->invoiceService->cancelInvoice($id, $validated['reason'] ?? null);

            return response()->json([
                'success' => true,
                'message' => "Đã hủy thành công hóa đơn {$invoice->invoice_number}.",
                'data' => $invoice,
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/invoices/bulk-cancel
     * Hủy hàng loạt hóa đơn chưa thanh toán
     */
    public function bulkCancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'invoice_ids.required' => 'Vui lòng chọn ít nhất một hóa đơn cần hủy.',
            'invoice_ids.min' => 'Vui lòng chọn ít nhất một hóa đơn cần hủy.',
        ]);

        $result = $this->invoiceService->bulkCancelInvoices(
            $validated['invoice_ids'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => "Đã hủy thành công {$result['cancelled_count']} hóa đơn.",
            'data' => $result,
        ]);
    }
}
