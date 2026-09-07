<?php

namespace App\Modules\Reception\Events;

use App\Modules\Reception\Models\Parcel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Bưu phẩm được tiếp nhận tại quầy lễ tân.
 * Cross-module: ResidentService sẽ lắng nghe event này để gửi thông báo cho cư dân.
 */
class ParcelReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Parcel $parcel
    ) {}
}
