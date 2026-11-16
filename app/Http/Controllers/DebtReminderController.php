<?php

namespace App\Http\Controllers;

use App\Models\DebtReminderLog;
use App\Models\Invoice;
use App\Services\DebtReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebtReminderController extends Controller
{
    public function __construct(
        protected DebtReminderService $reminderService
    ) {}

    /**
     * POST /api/v1/invoices/{id}/send-reminder
     * Gửi email nhắc nợ cho một hóa đơn
     */
    public function sendSingle(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $staffUserId = $request->user()?->id ? (string) $request->user()->id : null;

        try {
            $log = $this->reminderService->queueReminderForInvoice($invoice, $staffUserId);

            return response()->json([
                'success' => true,
                'message' => "Đã đưa email nhắc nợ cho hóa đơn {$invoice->invoice_number} vào hàng đợi xử lý nền.",
                'data' => $log,
            ], 202);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/v1/invoices/bulk-send-reminders
     * Gửi email nhắc nợ hàng loạt qua Queue
     */
    public function sendBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'string'],
        ], [
            'invoice_ids.required' => 'Vui lòng chọn ít nhất một hóa đơn cần gửi nhắc nợ.',
            'invoice_ids.min' => 'Vui lòng chọn ít nhất một hóa đơn.',
        ]);

        $staffUserId = $request->user()?->id ? (string) $request->user()->id : null;

        $result = $this->reminderService->queueBulkReminders($validated['invoice_ids'], $staffUserId);

        return response()->json([
            'success' => true,
            'message' => "Đã đưa {$result['queued_count']} email nhắc nợ vào hàng đợi Queue xử lý nền.",
            'data' => $result,
        ], 202);
    }

    /**
     * GET /api/v1/invoices/reminders/history
     * Lịch sử gửi nhắc nợ phân trang
     */
    public function logs(Request $request): JsonResponse
    {
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
    public function summary(): JsonResponse
    {
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
        $overdueOnly = $request->boolean('overdue_only', true);
        $daysBeforeDue = max(1, min(30, (int) $request->input('days_before_due', 3)));
        $minDaysOverdue = max(0, min(365, (int) $request->input('min_days_overdue', 0)));

        $count = $this->reminderService->autoDispatchScheduledReminders($overdueOnly, $daysBeforeDue, $minDaysOverdue);

        return response()->json([
            'success' => true,
            'message' => "Hệ thống đã quét và đưa thành công {$count} thông báo nhắc nợ vào hàng đợi Queue xử lý nền.",
            'data' => [
                'dispatched_count' => $count,
                'overdue_only' => $overdueOnly,
                'days_before_due' => $daysBeforeDue,
                'min_days_overdue' => $minDaysOverdue,
            ],
        ]);
    }

    /**
     * GET /api/v1/invoices/reminders/{id}/preview
     * Xem trước nội dung email mẫu
     */
    public function preview(string $id): JsonResponse
    {
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
            ], 404);
        }
    }

    /**
     * POST /api/v1/invoices/reminders/{id}/retry
     * Thử lại gửi email nhắc nợ cho bản ghi lỗi
     */
    public function retry(string $id): JsonResponse
    {
        $log = DebtReminderLog::findOrFail($id);
        $this->reminderService->retryLog($log);

        return response()->json([
            'success' => true,
            'message' => "Đã đưa lại email nhắc nợ cho hóa đơn {$log->invoice?->invoice_number} vào hàng đợi Queue.",
            'data' => $log,
        ]);
    }
}
