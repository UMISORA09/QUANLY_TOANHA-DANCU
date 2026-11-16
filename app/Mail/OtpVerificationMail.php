<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $otp,
        public string $purpose = 'đăng ký tài khoản',
        public ?string $userName = null,
        public int $expiryMinutes = 15,
        public ?string $ipAddress = null
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[CSVSMART] {$this->otp} là mã xác thực OTP {$this->purpose}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $appUrl = config('app.url', 'http://localhost:8000');
        $formattedOtp = strlen($this->otp) === 6
            ? substr($this->otp, 0, 3).' '.substr($this->otp, 3, 3)
            : $this->otp;

        return new Content(
            view: 'emails.otp-verification',
            with: [
                'otp' => $this->otp,
                'formattedOtp' => $formattedOtp,
                'purpose' => $this->purpose,
                'userName' => $this->userName,
                'expiryMinutes' => $this->expiryMinutes,
                'appUrl' => $appUrl,
                'requestedAt' => now()->setTimezone('Asia/Ho_Chi_Minh')->format('H:i:s - d/m/Y'),
                'ipAddress' => $this->ipAddress,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
