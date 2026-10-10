<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DebtReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $vietQrData
     */
    public function __construct(
        public Invoice $invoice,
        public string $recipientName,
        public int $daysOverdue = 0,
        public array $vietQrData = []
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->daysOverdue > 0 ? '[QUÁ HẠN]' : '[NHẮC NỢ]';

        return new Envelope(
            subject: "{$prefix} Thông Báo Phí Dịch Vụ Căn Hộ {$this->invoice->apartment?->apartment_number} - Kỳ {$this->invoice->billing_period}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.debt_reminder',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
