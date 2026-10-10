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

        // Đẩy vào Laravel Queue xử lý bất đồng bộ
        SendDebtReminderEmailJob::dispatch($log->id);

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

        // Bỏ qua các hóa đơn đã được gửi nhắc nợ trong vòng 24 giờ qua để tránh spam
        $recentRemindedInvoiceIds = DebtReminderLog::where('created_at', '>=', Carbon::now()->subHours(24))
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
}
