<?php

namespace App\Mail;

use App\Models\Parcel;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ParcelReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Parcel  $parcel  Kiện hàng bưu phẩm
     * @param  string  $recipientName  Tên cư dân nhận
     * @param  string  $pickupLocation  Địa điểm nhận (Quầy Lễ tân, Tủ Smart Locker...)
     * @param  string|null  $pinCode  Mã PIN lấy hàng (nếu có)
     */
    public function __construct(
        public Parcel $parcel,
        public string $recipientName,
        public string $pickupLocation = 'Quầy Lễ tân Tòa nhà',
        public ?string $pinCode = null
    ) {}

    public function envelope(): Envelope
    {
        $aptNumber = $this->parcel->apartment?->apartment_number ?? 'Căn hộ';

        return new Envelope(
            subject: "[Cassavas Smart] Thông báo kiện hàng mới đã được tiếp nhận - Căn {$aptNumber} (Mã: {$this->parcel->tracking_number})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.parcel_received',
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
