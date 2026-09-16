<?php

namespace App\Services;

use App\Services\Search\SearchManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Service xử lý nghiệp vụ Quản lý Tiện ích & Slot theo Kiến trúc Tinh gọn (Lightweight Architecture):
 * Tách biệt nghiệp vụ (Business Rules), Chuẩn hóa dữ liệu (Sanitization),
 * Ràng buộc Sức chứa / Đặt chỗ (Capacity & Conflict Enforcement)
 * và Tối ưu truy vấn (Batch Aggregation / No N+1).
 */
class AmenityService
{
    /**
     * Chuẩn hóa giá trị chuỗi rỗng thành NULL
     */
    public function nullifyEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Chuẩn hóa và format DTO Tiện ích trả về cho Frontend
     *
     * @return array<string, mixed>
     */
    public function formatAmenityDto(
        object $item,
        int $timeSlotsCount = 0,
        int $activeSlotsCount = 0,
        int $activeBookingsCount = 0
    ): array {
        $gallery = [];
        if (! empty($item->gallery_images)) {
            $decoded = json_decode((string) $item->gallery_images, true);
            if (is_array($decoded)) {
                $gallery = $decoded;
            }
        }

        return [
            'id' => $item->id,
            'category_id' => $item->category_id,
            'block_id' => $item->block_id,
            'amenity_name' => $item->amenity_name,
            'amenity_code' => $item->amenity_code,
            'location_detail' => $item->location_detail,
            'max_capacity_per_slot' => (int) $item->max_capacity_per_slot,
            'hourly_rate' => (float) $item->hourly_rate,
            'security_deposit_required' => (float) ($item->security_deposit_required ?? 0),
            'advance_booking_days_limit' => (int) ($item->advance_booking_days_limit ?? 7),
            'min_cancel_hours_before' => (int) ($item->min_cancel_hours_before ?? 12),
            'requires_admin_approval' => (bool) $item->requires_admin_approval,
            'rules_and_regulations' => $item->rules_and_regulations,
            'cover_image_url' => $item->cover_image_url,
            'gallery_images' => $gallery,
            'is_active' => (bool) $item->is_active,
            'version' => 1,
            'created_at' => Carbon::parse($item->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($item->updated_at)->toIso8601String(),
            'category_name' => $item->category_name ?? null,
            'category_code' => $item->category_code ?? null,
            'block_name' => $item->block_name ?? null,
            'block_code' => $item->block_code ?? null,
            'time_slots_count' => $timeSlotsCount,
            'active_time_slots_count' => $activeSlotsCount,
            'max_bookings_per_slot' => (int) $item->max_capacity_per_slot,
            'active_bookings_count' => $activeBookingsCount,
        ];
    }

    /**
     * Khởi tạo AmenityService với SearchManager
     */
    public function __construct(protected ?SearchManager $searchManager = null)
    {
        $this->searchManager = $searchManager ?: new SearchManager;
    }

    /**
     * Gợi ý autocomplete siêu tốc cho thanh tìm kiếm
     *
     * @return array<int, array{id: string, label: string, code?: string, extra?: mixed}>
     */
    public function getSuggestions(string $query, int $limit = 5): array
    {
        return $this->searchManager->suggest('amenities', $query, $limit);
    }

    /**
     * Lấy danh sách tiện ích có phân trang và bộ lọc tìm kiếm đa chiều
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int, total_pages: int, search_time_ms?: float, is_fuzzy?: bool}
     */
    public function getPaginatedAmenities(array $filters): array
    {
        $search = $filters['search'] ?? null;
        $categoryId = $filters['category_id'] ?? null;
        $blockId = $filters['block_id'] ?? null;
        $isActive = $filters['is_active'] ?? null;
        $sort = $filters['sort'] ?? 'created_at_desc';
        $page = max((int) ($filters['page'] ?? 1), 1);
        $limit = max(min((int) ($filters['limit'] ?? 10), 100), 1);

        $searchTimeMs = 0.0;
        $isFuzzy = false;
        $correctedQuery = null;

        if (! empty($search) && trim((string) $search) !== '') {
            // Sử dụng Smart Search Engine (MySQL Full-Text / Meilisearch / Elasticsearch)
            $searchResult = $this->searchManager->search('amenities', (string) $search, [
                'category_id' => $categoryId,
                'block_id' => $blockId,
                'is_active' => $isActive,
            ], [
                'page' => $page,
                'per_page' => $limit,
                'sort' => $sort,
            ]);

            $total = $searchResult->total;
            $totalPages = $searchResult->getTotalPages();
            $items = collect($searchResult->items);
            $searchTimeMs = $searchResult->searchTimeMs;
            $isFuzzy = $searchResult->isFuzzy;
            $correctedQuery = $searchResult->metadata['corrected_query'] ?? null;
        } else {
            $query = DB::table('amenities')
                ->leftJoin('amenity_categories', 'amenities.category_id', '=', 'amenity_categories.id')
                ->leftJoin('blocks', 'amenities.block_id', '=', 'blocks.id')
                ->whereNull('amenities.deleted_at')
                ->select(
                    'amenities.*',
                    'amenity_categories.category_name',
                    'amenity_categories.category_code',
                    'blocks.block_name',
                    'blocks.block_code'
                );

            if (! empty($categoryId)) {
                $query->where('amenities.category_id', $categoryId);
            }

            if (! empty($blockId)) {
                $query->where('amenities.block_id', $blockId);
            }

            if ($isActive !== null && $isActive !== '' && $isActive !== 'all') {
                $boolVal = filter_var($isActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolVal !== null) {
                    $query->where('amenities.is_active', $boolVal ? 1 : 0);
                } elseif ($isActive === 'active') {
                    $query->where('amenities.is_active', 1);
                } elseif ($isActive === 'inactive') {
                    $query->where('amenities.is_active', 0);
                }
            }

            // Sắp xếp
            match ($sort) {
                'name_asc' => $query->orderBy('amenities.amenity_name', 'asc'),
                'name_desc' => $query->orderBy('amenities.amenity_name', 'desc'),
                'price_asc' => $query->orderBy('amenities.hourly_rate', 'asc'),
                'price_desc' => $query->orderBy('amenities.hourly_rate', 'desc'),
                'created_at_asc' => $query->orderBy('amenities.created_at', 'asc'),
                default => $query->orderBy('amenities.created_at', 'desc'),
            };

            $total = $query->count();
            $totalPages = $total > 0 ? (int) ceil($total / $limit) : 1;
            $items = $query->skip(($page - 1) * $limit)->take($limit)->get();
        }

        // Batch aggregate slot counts & booking counts để tránh N+1 queries
        $amenityIds = $items->pluck('id')->toArray();
        $slotCounts = [];
        $activeSlotCounts = [];
        $activeBookingCounts = [];

        if (! empty($amenityIds)) {
            // 1. Khung giờ
            $slots = DB::table('amenity_time_slots')
                ->whereIn('amenity_id', $amenityIds)
                ->select('amenity_id', 'is_active', DB::raw('count(*) as count'))
                ->groupBy('amenity_id', 'is_active')
                ->get();

            foreach ($slots as $slot) {
                $aid = $slot->amenity_id;
                $slotCounts[$aid] = ($slotCounts[$aid] ?? 0) + $slot->count;
                if ($slot->is_active) {
                    $activeSlotCounts[$aid] = ($activeSlotCounts[$aid] ?? 0) + $slot->count;
                }
            }

            // 2. Lượt đặt chỗ thực tế đang hoạt động
            $bookings = DB::table('amenity_bookings')
                ->whereIn('amenity_id', $amenityIds)
                ->whereIn('status', ['PENDING', 'APPROVED', 'CONFIRMED'])
                ->whereNull('deleted_at')
                ->select('amenity_id', DB::raw('count(*) as count'))
                ->groupBy('amenity_id')
                ->get();

            foreach ($bookings as $b) {
                $activeBookingCounts[$b->amenity_id] = (int) $b->count;
            }
        }

        $formatted = $items->map(function ($rawItem) use ($slotCounts, $activeSlotCounts, $activeBookingCounts) {
            $item = is_array($rawItem) ? (object) $rawItem : $rawItem;

            return $this->formatAmenityDto(
                $item,
                (int) ($slotCounts[$item->id] ?? 0),
                (int) ($activeSlotCounts[$item->id] ?? 0),
                (int) ($activeBookingCounts[$item->id] ?? 0)
            );
        })->toArray();

        return [
            'items' => $formatted,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
            'search_time_ms' => round($searchTimeMs, 2),
            'corrected_query' => $correctedQuery,
            'is_fuzzy' => $isFuzzy,
        ];
    }

    /**
     * Tạo mới tiện ích và đảm bảo dữ liệu chuẩn hóa (Data Sanitization)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createAmenity(array $data): array
    {
        $amenityCode = strtoupper(trim((string) $data['amenity_code']));

        // Kiểm tra trùng mã tiện ích trong hệ thống
        $exists = DB::table('amenities')
            ->where('amenity_code', $amenityCode)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("Mã tiện ích '{$amenityCode}' đã tồn tại trong hệ thống.");
        }

        $id = (string) Str::uuid();
        $now = Carbon::now();

        $blockId = $this->nullifyEmpty($data['block_id'] ?? null);
        $rules = $this->nullifyEmpty($data['rules_and_regulations'] ?? null);
        $cover = $this->nullifyEmpty($data['cover_image_url'] ?? null);

        DB::table('amenities')->insert([
            'id' => $id,
            'category_id' => $data['category_id'],
            'block_id' => $blockId,
            'amenity_name' => trim((string) $data['amenity_name']),
            'amenity_code' => $amenityCode,
            'location_detail' => trim((string) $data['location_detail']),
            'max_capacity_per_slot' => (int) $data['max_capacity_per_slot'],
            'hourly_rate' => $data['hourly_rate'],
            'security_deposit_required' => $data['security_deposit_required'] ?? 0,
            'advance_booking_days_limit' => $data['advance_booking_days_limit'] ?? 7,
            'min_cancel_hours_before' => $data['min_cancel_hours_before'] ?? 12,
            'requires_admin_approval' => ! empty($data['requires_admin_approval']) ? 1 : 0,
            'rules_and_regulations' => $rules,
            'cover_image_url' => $cover,
            'gallery_images' => isset($data['gallery_images']) ? json_encode($data['gallery_images']) : '[]',
            'is_active' => isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (array) $this->getAmenityById($id);
    }

    /**
     * Cập nhật tiện ích với kiểm tra Capacity Conflict & Sanitization
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateAmenity(string $id, array $data): array
    {
        $existing = DB::table('amenities')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $existing) {
            throw new InvalidArgumentException('Không tìm thấy tiện ích hoặc đã bị xóa.');
        }

        // 1. Kiểm tra mã tiện ích nếu có cập nhật
        if (isset($data['amenity_code'])) {
            $newCode = strtoupper(trim((string) $data['amenity_code']));
            $dup = DB::table('amenities')
                ->where('amenity_code', $newCode)
                ->where('id', '!=', $id)
                ->whereNull('deleted_at')
                ->exists();

            if ($dup) {
                throw new InvalidArgumentException("Mã tiện ích '{$newCode}' đã tồn tại ở một tiện ích khác.");
            }
        }

        // 2. Nghiệp vụ Slot Capacity & Booking Conflict (Section 13 & 14):
        // Khi Admin giảm Maximum Slot (ví dụ: 50 -> 30), backend phải kiểm tra dữ liệu booking hiện tại.
        // Không được cho phép cấu hình gây mâu thuẫn với booking hiện tại.
        if (isset($data['max_capacity_per_slot'])) {
            $newCapacity = (int) $data['max_capacity_per_slot'];

            // Lấy số lượng đặt chỗ hoặc người tham gia cao nhất trên bất kỳ slot/ngày nào đang hoạt động
            $activeSlotBookings = DB::table('amenity_bookings')
                ->where('amenity_id', $id)
                ->whereIn('status', ['PENDING', 'APPROVED', 'CONFIRMED'])
                ->whereNull('deleted_at')
                ->groupBy('booking_date', 'start_time', 'end_time')
                ->selectRaw('COUNT(*) as total_bookings, COALESCE(SUM(attendee_count), 0) as total_attendees')
                ->get();

            $peakBookings = 0;
            foreach ($activeSlotBookings as $slotStat) {
                $peak = max((int) $slotStat->total_bookings, (int) $slotStat->total_attendees);
                if ($peak > $peakBookings) {
                    $peakBookings = $peak;
                }
            }

            if ($peakBookings > 0 && $newCapacity < $peakBookings) {
                throw new InvalidArgumentException(
                    "Không thể giảm Maximum Slot xuống {$newCapacity} vì hiện tại đã có {$peakBookings} lượt đăng ký."
                );
            }
        }

        $update = ['updated_at' => Carbon::now()];

        if (isset($data['category_id'])) {
            $update['category_id'] = $data['category_id'];
        }
        if (array_key_exists('block_id', $data)) {
            $update['block_id'] = $this->nullifyEmpty($data['block_id']);
        }
        if (isset($data['amenity_name'])) {
            $update['amenity_name'] = trim((string) $data['amenity_name']);
        }
        if (isset($data['amenity_code'])) {
            $update['amenity_code'] = strtoupper(trim((string) $data['amenity_code']));
        }
        if (isset($data['location_detail'])) {
            $update['location_detail'] = trim((string) $data['location_detail']);
        }
        if (isset($data['max_capacity_per_slot'])) {
            $update['max_capacity_per_slot'] = (int) $data['max_capacity_per_slot'];
        }
        if (isset($data['hourly_rate'])) {
            $update['hourly_rate'] = $data['hourly_rate'];
        }
        if (isset($data['security_deposit_required'])) {
            $update['security_deposit_required'] = $data['security_deposit_required'];
        }
        if (isset($data['advance_booking_days_limit'])) {
            $update['advance_booking_days_limit'] = (int) $data['advance_booking_days_limit'];
        }
        if (isset($data['min_cancel_hours_before'])) {
            $update['min_cancel_hours_before'] = (int) $data['min_cancel_hours_before'];
        }
        if (isset($data['requires_admin_approval'])) {
            $update['requires_admin_approval'] = $data['requires_admin_approval'] ? 1 : 0;
        }
        if (array_key_exists('rules_and_regulations', $data)) {
            $update['rules_and_regulations'] = $this->nullifyEmpty($data['rules_and_regulations']);
        }
        if (array_key_exists('cover_image_url', $data)) {
            $update['cover_image_url'] = $this->nullifyEmpty($data['cover_image_url']);
        }
        if (isset($data['gallery_images'])) {
            $update['gallery_images'] = json_encode($data['gallery_images']);
        }
        if (isset($data['is_active'])) {
            $update['is_active'] = $data['is_active'] ? 1 : 0;
        }

        DB::table('amenities')->where('id', $id)->update($update);

        return (array) $this->getAmenityById($id);
    }

    /**
     * Lấy chi tiết một tiện ích theo ID
     *
     * @return array<string, mixed>|null
     */
    public function getAmenityById(string $id): ?array
    {
        $item = DB::table('amenities')
            ->leftJoin('amenity_categories', 'amenities.category_id', '=', 'amenity_categories.id')
            ->leftJoin('blocks', 'amenities.block_id', '=', 'blocks.id')
            ->where('amenities.id', $id)
            ->whereNull('amenities.deleted_at')
            ->select(
                'amenities.*',
                'amenity_categories.category_name',
                'amenity_categories.category_code',
                'blocks.block_name',
                'blocks.block_code'
            )
            ->first();

        if (! $item) {
            return null;
        }

        $slotCount = DB::table('amenity_time_slots')->where('amenity_id', $id)->count();
        $activeSlotCount = DB::table('amenity_time_slots')->where('amenity_id', $id)->where('is_active', 1)->count();
        $activeBookingsCount = DB::table('amenity_bookings')
            ->where('amenity_id', $id)
            ->whereIn('status', ['PENDING', 'APPROVED', 'CONFIRMED'])
            ->whereNull('deleted_at')
            ->count();

        return $this->formatAmenityDto($item, $slotCount, $activeSlotCount, $activeBookingsCount);
    }

    /**
     * Lấy danh sách đặt chỗ thực tế của tiện ích (Amenity Bookings)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAmenityBookings(string $amenityId): array
    {
        $bookings = DB::table('amenity_bookings')
            ->leftJoin('users', 'amenity_bookings.resident_user_id', '=', 'users.id')
            ->leftJoin('apartments', 'amenity_bookings.apartment_id', '=', 'apartments.id')
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->where('amenity_bookings.amenity_id', $amenityId)
            ->whereNull('amenity_bookings.deleted_at')
            ->select(
                'amenity_bookings.*',
                'users.full_name as resident_name',
                'users.phone_number as resident_phone',
                'apartments.apartment_number',
                'blocks.block_name'
            )
            ->orderBy('amenity_bookings.booking_date', 'desc')
            ->orderBy('amenity_bookings.start_time', 'desc')
            ->get();

        return $bookings->map(function ($b) {
            return [
                'id' => $b->id,
                'booking_code' => $b->booking_code,
                'amenity_id' => $b->amenity_id,
                'apartment_id' => $b->apartment_id,
                'resident_user_id' => $b->resident_user_id,
                'resident_name' => $b->resident_name ?? 'Cư dân',
                'resident_phone' => $b->resident_phone ?? '',
                'apartment_number' => $b->apartment_number ?? '',
                'block_name' => $b->block_name ?? '',
                'booking_date' => $b->booking_date,
                'start_time' => substr((string) $b->start_time, 0, 5),
                'end_time' => substr((string) $b->end_time, 0, 5),
                'attendee_count' => (int) $b->attendee_count,
                'total_amount' => (float) $b->total_amount,
                'deposit_amount' => (float) $b->deposit_amount,
                'is_paid' => (bool) $b->is_paid,
                'status' => $b->status,
                'checkin_qr_code' => $b->checkin_qr_code,
                'checked_in_at' => $b->checked_in_at ? Carbon::parse($b->checked_in_at)->toIso8601String() : null,
                'resident_notes' => $b->resident_notes,
                'admin_notes' => $b->admin_notes,
                'created_at' => Carbon::parse($b->created_at)->toIso8601String(),
            ];
        })->toArray();
    }
}
