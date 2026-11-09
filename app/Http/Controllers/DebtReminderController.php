<?php

namespace App\Http\Controllers;

use App\Models\DebtReminderLog;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DebtReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DebtReminderController extends Controller
{
    public function __construct(
        protected DebtReminderService $reminderService
    ) {}

    /**
     * Kiểm tra xác thực và quyền hạn đối với phân hệ Nhắc Nợ
     */
    protected function checkAuthorization(Request $request, string|array $requiredPermissions): ?JsonResponse
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

        // 2. Quyền người đặc biệt phân hệ hóa đơn
        if (
            method_exists($user, 'hasPermission') &&
            $user->hasPermission('INVOICE:SPECIAL')
        ) {
            return null;
        }

        // 3. Kiểm tra danh sách quyền yêu cầu (OR logic)
        $perms = is_array($requiredPermissions) ? $requiredPermissions : [$requiredPermissions];
        foreach ($perms as $perm) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($perm)) {
                return null;
            }
        }

        $permText = implode(' hoặc ', $perms);

        return response()->json([
            'success' => false,
            'message' => "Bạn không có quyền thực hiện thao tác này (yêu cầu quyền: {$permText}).",
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
     * POST /api/v1/invoices/{id}/send-reminder
     * Gửi email nhắc nợ cho một hóa đơn
     */
    public function sendSingle(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, ['INVOICE:UPDATE', 'INVOICE:APPROVE'])) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền gửi nhắc nợ hóa đơn.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $invoice = Invoice::findOrFail($id);
        $staffUserId = (string) $user->id;

        try {
            $log = $this->reminderService->queueReminderForInvoice($invoice, $staffUserId);

            return response()->json([
                'success' => true,
                'message' => "Đã đưa email nhắc nợ cho hóa đơn {$invoice->invoice_number} vào hàng đợi xử lý nền.",
                'data' => $log,
            ], Response::HTTP_ACCEPTED);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * POST /api/v1/invoices/bulk-send-reminders
     * Gửi email nhắc nợ hàng loạt qua Queue
     */
    public function sendBulk(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, ['INVOICE:UPDATE', 'INVOICE:APPROVE'])) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền gửi nhắc nợ hóa đơn hàng loạt.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'string'],
        ], [
            'invoice_ids.required' => 'Vui lòng chọn ít nhất một hóa đơn cần gửi nhắc nợ.',
            'invoice_ids.min' => 'Vui lòng chọn ít nhất một hóa đơn.',
        ]);

        $staffUserId = (string) $user->id;

        $result = $this->reminderService->queueBulkReminders($validated['invoice_ids'], $staffUserId);

        return response()->json([
            'success' => true,
            'message' => "Đã đưa {$result['queued_count']} email nhắc nợ vào hàng đợi Queue xử lý nền.",
            'data' => $result,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * GET /api/v1/invoices/reminders/history
     * Lịch sử gửi nhắc nợ phân trang
     */
    public function logs(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem nhật ký nhắc nợ.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $filters = $request->only([
            'invoice_id',
            'apartment_id',
            'channel_status',
            'search',
            'per_page',
        ]);

        $logs = $this->reminderService->getReminderLogs($filters);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * GET /api/v1/invoices/reminders/summary
     * Thống kê KPI tổng quan hàng đợi email nhắc nợ
     */
    public function summary(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem thống kê nhắc nợ.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $summary = $this->reminderService->getReminderSummary();

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * POST /api/v1/invoices/reminders/auto-dispatch
     * Kích hoạt quét & xếp hàng nhắc nợ tự động
     */
    public function autoDispatch(Request $request): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, ['INVOICE:UPDATE', 'INVOICE:APPROVE'])) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền kích hoạt quét nhắc nợ tự động.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $overdueOnly = $request->boolean('overdue_only', true);
        $daysBeforeDue = max(1, min(30, (int) $request->input('days_before_due', 3)));

        $count = $this->reminderService->autoDispatchScheduledReminders($overdueOnly, $daysBeforeDue);

        return response()->json([
            'success' => true,
            'message' => "Hệ thống đã quét và đưa thành công {$count} thông báo nhắc nợ vào hàng đợi Queue xử lý nền.",
            'data' => [
                'dispatched_count' => $count,
                'overdue_only' => $overdueOnly,
                'days_before_due' => $daysBeforeDue,
            ],
        ]);
    }

    /**
     * GET /api/v1/invoices/reminders/{id}/preview
     * Xem trước nội dung email mẫu
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, 'INVOICE:VIEW')) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem trước nội dung nhắc nợ.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $preview = $this->reminderService->previewEmail($id);

            return response()->json([
                'success' => true,
                'data' => $preview,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hóa đơn hoặc nhật ký nhắc nợ tương ứng: '.$e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * POST /api/v1/invoices/reminders/{id}/retry
     * Thử lại gửi email nhắc nợ cho bản ghi lỗi
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        if ($authError = $this->checkAuthorization($request, ['INVOICE:UPDATE', 'INVOICE:APPROVE'])) {
            return $authError;
        }

        $user = $request->user();
        if (! $this->isStaffUser($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thử lại gửi nhắc nợ.',
                'error' => 'FORBIDDEN',
            ], Response::HTTP_FORBIDDEN);
        }

        $log = DebtReminderLog::findOrFail($id);
        $this->reminderService->retryLog($log);

        return response()->json([
            'success' => true,
            'message' => "Đã đưa lại email nhắc nợ cho hóa đơn {$log->invoice?->invoice_number} vào hàng đợi Queue.",
            'data' => $log,
        ]);
    }
}
