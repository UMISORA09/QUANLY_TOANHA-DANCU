<?php

namespace App\Listeners;

use App\Events\ParcelReceivedEvent;
use App\Events\QuocTinRealtimeEvent;
use App\Jobs\SendParcelNotificationJob;
use Illuminate\Support\Facades\Log;

class SendParcelNotificationListener
{
    /**
     * Lắng nghe sự kiện tiếp nhận bưu phẩm để kích hoạt tiến trình gửi thông báo bất đồng bộ
     */
    public function handle(object $event): void
    {
        $parcelId = null;

        if ($event instanceof ParcelReceivedEvent) {
            $parcelId = $event->parcel->id;
        } elseif ($event instanceof QuocTinRealtimeEvent) {
            $data = $event->eventData ?? [];
            $module = $data['module'] ?? '';
            $entity = $data['entity'] ?? '';
            $action = strtoupper($data['action'] ?? '');

            // Chỉ xử lý sự kiện tiếp nhận mới thành công (CREATED)
            if ($module !== 'parcels' || $entity !== 'parcel' || $action !== 'CREATED') {
                return;
            }

            $parcelId = $data['entity_id'] ?? null;
        }

        if (! $parcelId) {
            return;
        }

        try {
            SendParcelNotificationJob::dispatch($parcelId);
        } catch (\Throwable $e) {
            Log::error("SendParcelNotificationListener: Không thể dispatch SendParcelNotificationJob cho bưu phẩm {$parcelId}: {$e->getMessage()}");
        }
    }
}
