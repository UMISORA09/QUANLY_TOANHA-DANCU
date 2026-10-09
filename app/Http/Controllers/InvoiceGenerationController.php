<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InvoiceGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvoiceGenerationController extends Controller
{
    public function __construct(
        protected InvoiceGenerationService $invoiceGenerationService
    ) {}

    /**
     * POST /api/v1/invoices/batch/preview
     * Chạy mô phỏng (Dry-run preview) sinh hóa đơn hàng loạt
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'block_id' => ['nullable', 'string'],
            'include_previous_debt' => ['nullable', 'boolean'],
            'overwrite_existing' => ['nullable', 'boolean'],
        ], [
            'billing_period.required' => 'Vui lòng chọn kỳ hóa đơn (định dạng YYYY-MM).',
            'billing_period.regex' => 'Kỳ hóa đơn không đúng định dạng YYYY-MM (ví dụ 2026-10).',
        ]);

        $preview = $this->invoiceGenerationService->previewBatch($validated);

        return response()->json([
            'success' => true,
            'message' => 'Lấy dữ liệu dự toán sinh hóa đơn thành công.',
            'data' => $preview,
        ]);
    }

    /**
     * POST /api/v1/invoices/batch/generate
     * Thực thi sinh hóa đơn tự động hàng loạt qua DB Transaction
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'block_id' => ['nullable', 'string'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'include_previous_debt' => ['nullable', 'boolean'],
            'overwrite_existing' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'billing_period.required' => 'Vui lòng chọn kỳ hóa đơn (định dạng YYYY-MM).',
            'billing_period.regex' => 'Kỳ hóa đơn không đúng định dạng YYYY-MM (ví dụ 2026-10).',
        ]);

        $executedByUserId = $request->user()?->id;
        if (! $executedByUserId) {
            $adminUser = User::first();
            $executedByUserId = $adminUser ? (string) $adminUser->id : Str::uuid()->toString();
        }

        $result = $this->invoiceGenerationService->generateBatch($validated, (string) $executedByUserId);

        return response()->json([
            'success' => true,
            'message' => "Sinh thành công {$result['total_invoices_created']} hóa đơn cho kỳ {$validated['billing_period']}.",
            'data' => $result,
        ], 201);
    }

    /**
     * GET /api/v1/invoices/batches
     * Danh sách lịch sử các đợt sinh hóa đơn
     */
    public function listBatches(Request $request): JsonResponse
    {
        $filters = $request->only(['billing_period', 'block_id', 'status', 'per_page']);
        $batches = $this->invoiceGenerationService->listBatches($filters);

        return response()->json([
            'success' => true,
            'data' => $batches,
        ]);
    }

    /**
     * GET /api/v1/invoices/batches/{id}
     * Chi tiết một đợt sinh hóa đơn
     */
    public function getBatchDetail(string $id): JsonResponse
    {
        $detail = $this->invoiceGenerationService->getBatchDetail($id);

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }
}
