<?php

namespace App\Events;

use App\Models\Parcel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParcelReceivedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * Khởi tạo sự kiện tiếp nhận bưu phẩm thành công
     */
    public function __construct(
        public Parcel $parcel
    ) {}
}
