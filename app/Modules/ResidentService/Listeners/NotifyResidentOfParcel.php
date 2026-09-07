<?php

namespace App\Modules\ResidentService\Listeners;

use App\Modules\Reception\Events\ParcelReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener: Gửi thông báo cho cư dân khi có bưu phẩm mới.
 *
 * Đây là ví dụ Cross-Module Communication qua Event/Listener:
 * - Module Reception dispatch event ParcelReceived
 * - Module ResidentService lắng nghe và xử lý (gửi thông báo)
 *
 * Implement ShouldQueue để xử lý bất đồng bộ (background job).
 */
class NotifyResidentOfParcel implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(ParcelReceived $event): void
    {
        $parcel = $event->parcel;
        $resident = $parcel->resident;

        // TODO: Implement gửi thông báo thực tế (email, push notification, ...)
        Log::info("Thông báo bưu phẩm cho cư dân", [
            'resident_id' => $resident->id ?? null,
            'parcel_id' => $parcel->id,
            'tracking' => $parcel->tracking_number,
        ]);
    }
}
