<?php

namespace App\Listeners;

use App\Events\AmenityCreated;
use App\Events\AmenityDeleted;
use App\Events\AmenityUpdated;
use App\Models\Announcement;
use App\Models\Invoice;
use App\Models\SatisfactionSurvey;
use App\Models\Suggestion;
use App\Models\SurveyResponse;
use App\Models\Ticket;
use App\Services\Concerns\HasPerformanceCache;
use Illuminate\Events\Dispatcher;

class PerformanceCacheInvalidationSubscriber
{
    use HasPerformanceCache;

    /**
     * Xử lý xóa cache khi bảng tin có thay đổi.
     */
    public function onAnnouncementChanged(): void
    {
        $this->flushSearchEntityCache('announcements');
    }

    /**
     * Xử lý xóa cache khi tiện ích có thay đổi.
     */
    public function onAmenityChanged(): void
    {
        $this->flushSearchEntityCache('amenities');
    }

    /**
     * Xử lý xóa cache khi sự cố / ticket có thay đổi.
     */
    public function onTicketChanged(): void
    {
        $this->flushSearchEntityCache('tickets');
    }

    /**
     * Xử lý xóa cache khi góp ý có thay đổi.
     */
    public function onSuggestionChanged(): void
    {
        $this->flushSearchEntityCache('suggestions');
    }

    /**
     * Xử lý xóa cache khi khảo sát hoặc câu trả lời có thay đổi.
     */
    public function onSurveyChanged(): void
    {
        $this->flushSearchEntityCache('surveys');
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
            'eloquent.saved: '.Announcement::class => 'onAnnouncementChanged',
            'eloquent.deleted: '.Announcement::class => 'onAnnouncementChanged',
            'eloquent.saved: '.Ticket::class => 'onTicketChanged',
            'eloquent.deleted: '.Ticket::class => 'onTicketChanged',
            'eloquent.saved: '.Suggestion::class => 'onSuggestionChanged',
            'eloquent.deleted: '.Suggestion::class => 'onSuggestionChanged',
            'eloquent.saved: '.SatisfactionSurvey::class => 'onSurveyChanged',
            'eloquent.deleted: '.SatisfactionSurvey::class => 'onSurveyChanged',
            'eloquent.saved: '.SurveyResponse::class => 'onSurveyChanged',
            'eloquent.saved: '.Invoice::class => 'onInvoiceChanged',
            'eloquent.deleted: '.Invoice::class => 'onInvoiceChanged',
        ];
    }
}
