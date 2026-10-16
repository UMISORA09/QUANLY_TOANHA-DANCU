<?php

namespace App\Jobs;

use App\Mail\DebtReminderMail;
use App\Models\DebtReminderLog;
use App\Services\PaymentCollectionService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDebtReminderEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Số lần thử lại tối đa
     */
    public int $tries = 3;

    /**
     * Thời gian chờ giữa các lần thử lại (giây)
     */
    public int $backoff = 30;

    public function __construct(
        public string $reminderLogId
    ) {}

    public function handle(PaymentCollectionService $paymentService): void
    {
        $log = DebtReminderLog::with(['invoice.apartment.block', 'invoice.residentUser'])->find($this->reminderLogId);

        if (! $log || ! $log->invoice) {
            Log::warning("Debt reminder log {$this->reminderLogId} not found or invoice missing.");

            return;
        }

        $invoice = $log->invoice;

        // Nếu hóa đơn đã được thanh toán xong trước đó, hủy gửi nhắc nợ
        if ($invoice->status === 'PAID' || $invoice->remaining_balance <= 0) {
            $log->update([
                'channel_status' => 'CANCELLED',
                'error_message' => 'Hóa đơn đã được thanh toán xong trước khi gửi email.',
            ]);

            return;
        }

        try {
            // Lấy dữ liệu VietQR cho hóa đơn
            $vietQrData = [];
            try {
                $vietQrData = $paymentService->getVietQrPayload($invoice->id);
            } catch (\Throwable $e) {
                Log::notice("Cannot generate VietQR payload for reminder email: {$e->getMessage()}");
            }

            // Gửi email qua Mail facade
            Mail::to($log->recipient_email)->send(
                new DebtReminderMail(
                    invoice: $invoice,
                    recipientName: $log->recipient_name,
                    daysOverdue: $log->days_overdue,
                    vietQrData: $vietQrData
                )
            );

            $log->update([
                'channel_status' => 'SENT',
                'sent_at' => Carbon::now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to send debt reminder email for log {$log->id}: {$e->getMessage()}");

            $log->update([
                'channel_status' => 'FAILED',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
