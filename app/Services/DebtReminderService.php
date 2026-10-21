<?php

namespace App\Services;

use App\Jobs\SendDebtReminderEmailJob;
use App\Models\DebtReminderLog;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class DebtReminderService
{
    /**
     * Đưa email nhắc nợ của một hóa đơn vào Queue xử lý nền
     *
     * @throws \DomainException
     */
    public function queueReminderForInvoice(Invoice $invoice, ?string $staffUserId = null): DebtReminderLog
    {
        $invoice->loadMissing(['apartment.block', 'residentUser']);

        if ($invoice->status === 'CANCELLED') {
            throw new \DomainException("Hóa đơn {$invoice->invoice_number} đã bị hủy, không thể gửi nhắc nợ.");
        }

        if ($invoice->status === 'PAID' || $invoice->remaining_balance <= 0) {
            throw new \DomainException("Hóa đơn {$invoice->invoice_number} đã được thanh toán đầy đủ, không có nợ đọng.");
        }

        $recipientEmail = $invoice->residentUser?->email;
        $recipientName = $invoice->residentUser?->full_name ?: "Cư dân Căn hộ {$invoice->apartment?->apartment_number}";

        if (empty($recipientEmail)) {
            // Fallback email cư dân dựa trên mã căn hộ nếu chưa cập nhật email
            $cleanApt = strtolower(str_replace(['-', '/', ' '], '', $invoice->apartment?->apartment_number ?? 'cudan'));
            $recipientEmail = "cudan.{$cleanApt}@cassavas.com";
        }

        $dueDate = Carbon::parse($invoice->due_date);
        $today = Carbon::today();
        $daysOverdue = $today->gt($dueDate) ? (int) $today->diffInDays($dueDate) : 0;

        $log = DebtReminderLog::create([
            'invoice_id' => $invoice->id,
            'apartment_id' => $invoice->apartment_id,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'reminder_type' => 'EMAIL',
            'debt_amount' => $invoice->remaining_balance,
            'due_date' => $invoice->due_date,
            'days_overdue' => $daysOverdue,
            'channel_status' => 'QUEUED',
            'sent_by_user_id' => $staffUserId,
        ]);

        // Đẩy vào Laravel Queue xử lý bất đồng bộ sau khi đã trả response
        SendDebtReminderEmailJob::dispatch($log->id)->afterResponse();

        Log::info("Dispatched debt reminder job for invoice {$invoice->invoice_number} (Log ID: {$log->id})");

        return $log;
    }

    /**
     * Đưa hàng loạt hóa đơn nợ vào Queue
     *
     * @param  array<string>  $invoiceIds
     * @return array{
     *     queued_count: int,
     *     skipped_count: int,
     *     logs: array<DebtReminderLog>,
     *     errors: array<string>
     * }
     */
    public function queueBulkReminders(array $invoiceIds, ?string $staffUserId = null): array
    {
        $invoices = Invoice::with(['apartment.block', 'residentUser'])
            ->whereIn('id', $invoiceIds)
            ->get();

        $queuedLogs = [];
        $errors = [];
        $skipped = 0;

        foreach ($invoices as $invoice) {
            try {
                $log = $this->queueReminderForInvoice($invoice, $staffUserId);
                $queuedLogs[] = $log;
            } catch (\DomainException $e) {
                $skipped++;
                $errors[] = "HĐ {$invoice->invoice_number}: {$e->getMessage()}";
            }
        }

        return [
            'queued_count' => count($queuedLogs),
            'skipped_count' => $skipped,
            'logs' => $queuedLogs,
            'errors' => $errors,
        ];
    }

    /**
     * Tự động quét và đẩy queue nhắc nợ định kỳ (cho Scheduler & Artisan Command)
     */
    public function autoDispatchScheduledReminders(bool $overdueOnly = true, int $daysBeforeDue = 3): int
    {
        $query = Invoice::with(['apartment.block', 'residentUser'])
            ->whereIn('status', ['ISSUED', 'PARTIAL', 'OVERDUE'])
            ->where('remaining_balance', '>', 0);

        if ($overdueOnly) {
            $query->where(function ($q) {
                $q->where('status', 'OVERDUE')
                    ->orWhere('due_date', '<', Carbon::today()->format('Y-m-d'));
            });
        } else {
            $maxDueDate = Carbon::today()->addDays($daysBeforeDue)->format('Y-m-d');
            $query->where('due_date', '<=', $maxDueDate);
        }

        // Bỏ qua các hóa đơn đã được gửi nhắc nợ thành công hoặc đang chờ xử lý trong vòng 24 giờ qua để tránh spam
        $recentRemindedInvoiceIds = DebtReminderLog::where('created_at', '>=', Carbon::now()->subHours(24))
            ->whereIn('channel_status', ['SENT', 'QUEUED'])
            ->pluck('invoice_id')
            ->toArray();

        if (! empty($recentRemindedInvoiceIds)) {
            $query->whereNotIn('id', $recentRemindedInvoiceIds);
        }

        $invoices = $query->limit(200)->get();
        $count = 0;

        foreach ($invoices as $invoice) {
            try {
                $this->queueReminderForInvoice($invoice, null);
                $count++;
            } catch (\Throwable $e) {
                Log::warning("Auto reminder failed for invoice {$invoice->id}: {$e->getMessage()}");
            }
        }

        return $count;
    }

    /**
     * Lịch sử các lần gửi nhắc nợ phân trang
     *
     * @param  array<string, mixed>  $filters
     */
    public function getReminderLogs(array $filters = []): LengthAwarePaginator
    {
        $query = DebtReminderLog::with([
            'invoice.apartment.block',
            'apartment.block',
            'sentByUser',
        ])->orderBy('created_at', 'desc');

        if (! empty($filters['invoice_id'])) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if (! empty($filters['apartment_id'])) {
            $query->where('apartment_id', $filters['apartment_id']);
        }

        if (! empty($filters['channel_status'])) {
            $query->where('channel_status', $filters['channel_status']);
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('recipient_email', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhereHas('invoice', fn ($inv) => $inv->where('invoice_number', 'like', "%{$search}%"))
                    ->orWhereHas('apartment', fn ($apt) => $apt->where('apartment_number', 'like', "%{$search}%"));
            });
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));

        return $query->paginate($perPage);
    }

    /**
     * Lấy thống kê tổng quan KPI cho hệ thống hàng đợi nhắc nợ
     *
     * @return array<string, mixed>
     */
    public function getReminderSummary(): array
    {
        $totalReminders = DebtReminderLog::count();
        $sentCount = DebtReminderLog::where('channel_status', 'SENT')->count();
        $queuedCount = DebtReminderLog::where('channel_status', 'QUEUED')->count();
        $failedCount = DebtReminderLog::where('channel_status', 'FAILED')->count();
        $cancelledCount = DebtReminderLog::where('channel_status', 'CANCELLED')->count();
        $totalDebtReminded = (float) DebtReminderLog::sum('debt_amount');
        $overdueRemindersCount = DebtReminderLog::where('days_overdue', '>', 0)->count();

        // Tính số hóa đơn nợ quá hạn chưa được gửi nhắc nợ thành công trong vòng 24h qua
        $recentRemindedIds = DebtReminderLog::where('created_at', '>=', Carbon::now()->subHours(24))
            ->whereIn('channel_status', ['SENT', 'QUEUED'])
            ->pluck('invoice_id')
            ->toArray();

        $unremindedQuery = Invoice::whereIn('status', ['ISSUED', 'PARTIAL', 'OVERDUE'])
            ->where('remaining_balance', '>', 0)
            ->where(function ($q) {
                $q->where('status', 'OVERDUE')
                    ->orWhere('due_date', '<', Carbon::today()->format('Y-m-d'));
            });

        if (! empty($recentRemindedIds)) {
            $unremindedQuery->whereNotIn('id', $recentRemindedIds);
        }

        $unremindedOverdueCount = $unremindedQuery->count();

        $successRate = $totalReminders > 0 ? round(($sentCount / $totalReminders) * 100, 1) : 100.0;

        return [
            'total_reminders' => $totalReminders,
            'sent_count' => $sentCount,
            'queued_count' => $queuedCount,
            'failed_count' => $failedCount,
            'cancelled_count' => $cancelledCount,
            'total_debt_reminded' => $totalDebtReminded,
            'overdue_reminders_count' => $overdueRemindersCount,
            'unreminded_overdue_count' => $unremindedOverdueCount,
            'success_rate' => $successRate,
        ];
    }

    /**
     * Tạo nội dung xem trước HTML của email nhắc nợ
     *
     * @return array{
     *     subject: string,
     *     html: string,
     *     recipient_name: string,
     *     recipient_email: string,
     *     invoice_number: string,
     *     debt_amount: float
     * }
     */
    public function previewEmail(string $id): array
    {
        $log = DebtReminderLog::with(['invoice.apartment.block', 'invoice.residentUser'])->find($id);

        if ($log && $log->invoice) {
            $invoice = $log->invoice;
            $recipientName = $log->recipient_name;
            $recipientEmail = $log->recipient_email;
            $daysOverdue = $log->days_overdue;
        } else {
            $invoice = Invoice::with(['apartment.block', 'residentUser'])->findOrFail($id);
            $recipientName = $invoice->residentUser?->full_name ?: "Cư dân Căn hộ {$invoice->apartment?->apartment_number}";
            $recipientEmail = $invoice->residentUser?->email ?: 'cudan@cassavas.com';
            $dueDate = Carbon::parse($invoice->due_date);
            $today = Carbon::today();
            $daysOverdue = $today->gt($dueDate) ? (int) $today->diffInDays($dueDate) : 0;
        }

        $paymentService = app(PaymentCollectionService::class);
        $vietQrData = [];
        try {
            $vietQrData = $paymentService->getVietQrPayload($invoice->id);
        } catch (\Throwable) {
        }

        $html = view('emails.debt_reminder', [
            'invoice' => $invoice,
            'recipientName' => $recipientName,
            'daysOverdue' => $daysOverdue,
            'vietQrData' => $vietQrData,
        ])->render();

        $prefix = $daysOverdue > 0 ? '[QUÁ HẠN]' : '[NHẮC NỢ]';
        $subject = "{$prefix} Thông Báo Phí Dịch Vụ Căn Hộ {$invoice->apartment?->apartment_number} - Kỳ {$invoice->billing_period}";

        return [
            'subject' => $subject,
            'html' => $html,
            'recipient_name' => $recipientName,
            'recipient_email' => $recipientEmail,
            'invoice_number' => $invoice->invoice_number,
            'debt_amount' => (float) $invoice->remaining_balance,
        ];
    }

    /**
     * Thử lại hoặc xếp hàng lại gửi email cho một log bị lỗi
     */
    public function retryLog(DebtReminderLog $log): DebtReminderLog
    {
        $log->update([
            'channel_status' => 'QUEUED',
            'error_message' => null,
        ]);

        SendDebtReminderEmailJob::dispatch($log->id);

        return $log;
    }
}
