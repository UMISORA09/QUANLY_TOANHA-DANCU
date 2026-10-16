<?php

namespace App\Http\Controllers;

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
}
