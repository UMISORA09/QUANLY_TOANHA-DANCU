<?php

namespace App\Modules\ResidentService\Services;

use App\Modules\ResidentService\Models\Amenity;
use App\Modules\ResidentService\Models\Announcement;
use App\Modules\ResidentService\Models\Ticket;
use Illuminate\Database\Eloquent\Collection;

/**
 * ResidentServiceQueryService — Public API cho module khác truy vấn data dịch vụ cư dân.
 *
 * Các module khác KHÔNG được query trực tiếp Model của ResidentService.
 * Thay vào đó, inject service này qua Dependency Injection.
 */
class ResidentServiceQueryService
{
    /**
     * Lấy danh sách ticket của cư dân.
     */
    public function getTicketsByResident(int $residentId): Collection
    {
        return Ticket::where('resident_id', $residentId)
            ->with('attachments')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Lấy danh sách tiện ích đang hoạt động.
     */
    public function getActiveAmenities(): Collection
    {
        return Amenity::where('status', Amenity::STATUS_ACTIVE)->get();
    }

    /**
     * Lấy thông báo đang hiển thị.
     */
    public function getActiveAnnouncements(): Collection
    {
        return Announcement::active()
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->get();
    }
}
