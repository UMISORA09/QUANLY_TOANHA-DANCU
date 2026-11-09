<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InvoiceGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceGenerationController extends Controller
{
    public function __construct(
        protected InvoiceGenerationService $invoiceGenerationService
    ) {}

    /**
     * Kiểm tra xác thực và quyền hạn đối với phân hệ Sinh Hóa Đơn
     */
    protected function checkAuthorization(Request $request, string $requiredPermission): ?JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản.',
                'error' => 'UNAUTHORIZED',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 1. Quản trị viên cấp cao có toàn quyền tối cao
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return null;
        }

        // 2. Kiểm tra quyền cụ thể hoặc quyền người đặc biệt phân hệ hóa đơn
        if (
            method_exists($user, 'hasPermission') &&
            ($user->hasPermission($requiredPermission) || $user->hasPermission('INVOICE:SPECIAL'))
        ) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => "Bạn không có quyền thực hiện thao tác này (yêu cầu quyền: {$requiredPermission}).",
            'error' => 'FORBIDDEN',
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * Kiểm tra người dùng có phải nhân viên vận hành / ban quản lý không
     */
    protected function isStaffUser(User $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'hasRole')) {
            return $user->hasRole(['SUPER_ADMIN', 'SUPER_ADMI', 'BUILDING_MANAGER', 'ACCOUNTANT', 'MANAGER', 'ADMIN']);
        }

        return false;
    }

    /**
     * POST /api/v1/invoices/batch/preview
     * Chạy mô phỏng (Dry-run preview) sinh hóa đơn hàng loạt
     */
    public function preview(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:CREATE')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thực hiện mô phỏng sinh hóa đơn.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

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
        if ($authError = $this->checkAuthorization($request, 'INVOICE:CREATE')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền sinh hóa đơn hàng loạt.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

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

        $executedByUserId = (string) $user->id;

        $result = $this->invoiceGenerationService->generateBatch($validated, $executedByUserId);

        return response()->json([
            'success' => true,
            'message' => "Sinh thành công {$result['total_invoices_created']} hóa đơn cho kỳ {$validated['billing_period']}.",
            'data' => $result,
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/invoices/batches
     * Danh sách lịch sử các đợt sinh hóa đơn
     */
    public function listBatches(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem lịch sử đợt sinh hóa đơn.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

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
    public function getBatchDetail(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem chi tiết đợt sinh hóa đơn.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $detail = $this->invoiceGenerationService->getBatchDetail($id);

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }
}
