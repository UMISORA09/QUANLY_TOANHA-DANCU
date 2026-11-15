<?php

namespace App\Listeners;

use App\Events\AmenityCreated;
use App\Events\AmenityDeleted;
use App\Events\AmenityUpdated;
use App\Models\Invoice;
use App\Models\SatisfactionSurvey;
use App\Models\SurveyResponse;
use App\Services\Concerns\HasPerformanceCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Events\Dispatcher;

class PerformanceCacheInvalidationSubscriber implements ShouldHandleEventsAfterCommit
{
    use HasPerformanceCache;

    /**
     * Xử lý xóa cache khi tiện ích có thay đổi.
     */
    public function onAmenityChanged(): void
    {
        $this->flushSearchEntityCache('amenities');
    }

    /**
     * Xử lý xóa cache khi khảo sát hoặc câu trả lời có thay đổi.
     */
    public function onSurveyChanged(): void
    {
        $this->flushAnalyticsCache('surveys');
    }

    /**
     * Xử lý xóa cache báo cáo khi hóa đơn có thay đổi.
     */
    public function onInvoiceChanged(): void
    {
        $this->flushAnalyticsCache('invoices');
    }

    /**
     * Đăng ký các bộ lắng nghe sự kiện với Dispatcher.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            AmenityCreated::class => 'onAmenityChanged',
            AmenityUpdated::class => 'onAmenityChanged',
            AmenityDeleted::class => 'onAmenityChanged',
            'eloquent.saved: '.SatisfactionSurvey::class => 'onSurveyChanged',
            'eloquent.deleted: '.SatisfactionSurvey::class => 'onSurveyChanged',
            'eloquent.saved: '.SurveyResponse::class => 'onSurveyChanged',
            'eloquent.saved: '.Invoice::class => 'onInvoiceChanged',
            'eloquent.deleted: '.Invoice::class => 'onInvoiceChanged',
        ];
    }
}
