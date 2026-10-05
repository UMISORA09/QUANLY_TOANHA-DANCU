<?php

namespace App\Repositories\Eloquent;

use App\DTOs\AmenityFilterDTO;
use App\Repositories\Contracts\AmenityRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseAmenityRepository implements AmenityRepositoryInterface
{
    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int, total_pages: int}
     */
    public function getPaginated(AmenityFilterDTO $filter): array
    {
        $query = DB::table('amenities')
            ->whereNull('amenities.deleted_at');

        if (! empty($filter->categoryId)) {
            $query->where('amenities.category_id', $filter->categoryId);
        }

        if (! empty($filter->blockId)) {
            $query->where('amenities.block_id', $filter->blockId);
        }

        if ($filter->isActive !== null && $filter->isActive !== '' && $filter->isActive !== 'all') {
            $boolVal = filter_var($filter->isActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($boolVal !== null) {
                $query->where('amenities.is_active', $boolVal ? 1 : 0);
            } elseif ($filter->isActive === 'active') {
                $query->where('amenities.is_active', 1);
            } elseif ($filter->isActive === 'inactive') {
                $query->where('amenities.is_active', 0);
            }
        }

        $total = $query->count();
        $totalPages = $total > 0 ? (int) ceil($total / $filter->limit) : 1;

        match ($filter->sort) {
            'name_asc' => $query->orderBy('amenities.amenity_name', 'asc'),
            'name_desc' => $query->orderBy('amenities.amenity_name', 'desc'),
            'price_asc' => $query->orderBy('amenities.hourly_rate', 'asc'),
            'price_desc' => $query->orderBy('amenities.hourly_rate', 'desc'),
            'created_at_asc' => $query->orderBy('amenities.created_at', 'asc'),
            default => $query->orderBy('amenities.created_at', 'desc'),
        };

        $rows = $query->skip(($filter->page - 1) * $filter->limit)
            ->take($filter->limit)
            ->get();

        $amenityIds = $rows->pluck('id')->toArray();

        $categories = DB::table('amenity_categories')->get()->keyBy('id');
        $blocks = DB::table('blocks')->get()->keyBy('id');

        $slotsCounts = [];
        $activeSlotsCounts = [];
        if (! empty($amenityIds)) {
            $slotsData = DB::table('amenity_time_slots')
                ->whereIn('amenity_id', $amenityIds)
                ->select(
                    'amenity_id',
                    DB::raw('COUNT(*) as total_slots'),
                    DB::raw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_slots')
                )
                ->groupBy('amenity_id')
                ->get();

            foreach ($slotsData as $sd) {
                $slotsCounts[$sd->amenity_id] = (int) $sd->total_slots;
                $activeSlotsCounts[$sd->amenity_id] = (int) $sd->active_slots;
            }
        }

        $activeBookingsCounts = [];
        if (! empty($amenityIds)) {
            $bookingsData = DB::table('amenity_bookings')
                ->whereIn('amenity_id', $amenityIds)
                ->whereIn('status', DatabaseResidentAmenityBookingRepository::HOLDING_STATUSES)
                ->whereNull('deleted_at')
                ->select('amenity_id', DB::raw('COUNT(*) as active_count'))
                ->groupBy('amenity_id')
                ->get();

            foreach ($bookingsData as $bd) {
                $activeBookingsCounts[$bd->amenity_id] = (int) $bd->active_count;
            }
        }

        $formatted = [];
        foreach ($rows as $item) {
            $cat = $categories->get($item->category_id);
            $blk = $item->block_id ? $blocks->get($item->block_id) : null;

            $gallery = [];
            if (! empty($item->gallery_images)) {
                $decoded = json_decode((string) $item->gallery_images, true);
                if (is_array($decoded)) {
                    $gallery = $decoded;
                }
            }

            $formatted[] = [
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
                'requires_admin_approval' => (bool) ($item->requires_admin_approval ?? false),
                'rules_and_regulations' => $item->rules_and_regulations,
                'cover_image_url' => $item->cover_image_url,
                'gallery_images' => $gallery,
                'is_active' => (bool) $item->is_active,
                'version' => (int) ($item->version ?? 1),
                'created_at' => Carbon::parse($item->created_at)->toIso8601String(),
                'updated_at' => Carbon::parse($item->updated_at)->toIso8601String(),
                'category_name' => $cat ? $cat->category_name : null,
                'category_code' => $cat ? $cat->category_code : null,
                'block_name' => $blk ? $blk->block_name : null,
                'block_code' => $blk ? $blk->block_code : null,
                'time_slots_count' => $slotsCounts[$item->id] ?? 0,
                'active_time_slots_count' => $activeSlotsCounts[$item->id] ?? 0,
                'max_bookings_per_slot' => (int) $item->max_capacity_per_slot,
                'active_bookings_count' => $activeBookingsCounts[$item->id] ?? 0,
            ];
        }

        return [
            'items' => $formatted,
            'total' => $total,
            'page' => $filter->page,
            'limit' => $filter->limit,
            'total_pages' => $totalPages,
        ];
    }

    public function findById(string $id, bool $lock = false): ?object
    {
        $query = DB::table('amenities')
            ->where('id', $id)
            ->whereNull('deleted_at');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function findByCode(string $code, ?string $excludeId = null): ?object
    {
        $query = DB::table('amenities')
            ->where('amenity_code', $code)
            ->whereNull('deleted_at');

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $id = (string) Str::uuid();
        $now = now();

        $galleryJson = isset($data['gallery_images']) && is_array($data['gallery_images'])
            ? json_encode($data['gallery_images'])
            : '[]';

        DB::table('amenities')->insert([
            'id' => $id,
            'category_id' => $data['category_id'],
            'block_id' => $data['block_id'] ?? null,
            'amenity_name' => trim($data['amenity_name']),
            'amenity_code' => strtoupper(trim($data['amenity_code'])),
            'location_detail' => trim($data['location_detail']),
            'max_capacity_per_slot' => (int) $data['max_capacity_per_slot'],
            'hourly_rate' => (float) $data['hourly_rate'],
            'security_deposit_required' => (float) ($data['security_deposit_required'] ?? 0),
            'advance_booking_days_limit' => (int) ($data['advance_booking_days_limit'] ?? 7),
            'min_cancel_hours_before' => (int) ($data['min_cancel_hours_before'] ?? 12),
            'requires_admin_approval' => (bool) ($data['requires_admin_approval'] ?? false) ? 1 : 0,
            'rules_and_regulations' => $data['rules_and_regulations'] ?? null,
            'cover_image_url' => $data['cover_image_url'] ?? null,
            'gallery_images' => $galleryJson,
            'is_active' => isset($data['is_active']) ? ((bool) $data['is_active'] ? 1 : 0) : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return array_merge($data, [
            'id' => $id,
            'created_at' => $now->toIso8601String(),
            'updated_at' => $now->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): bool
    {
        $expectedTimestamp = Carbon::parse($data['updated_at'])->setTimezone(config('app.timezone'))->startOfSecond();
        // ponytail: datetime has second precision; advance monotonically, use fractional timestamps if write throughput requires it.
        $data['updated_at'] = now()->max($expectedTimestamp->copy()->addSecond())->format('Y-m-d H:i:s');

        return (bool) DB::table('amenities')->where('id', $id)->whereNull('deleted_at')
            ->where('updated_at', $expectedTimestamp->format('Y-m-d H:i:s'))->update($data);
    }

    public function softDelete(string $id): bool
    {
        return (bool) DB::table('amenities')->where('id', $id)->whereNull('deleted_at')->update([
            'deleted_at' => now(),
            'updated_at' => now(),
            'is_active' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function recordAudit(array $data): void
    {
        DB::table('audit_logs')->insert($data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(): array
    {
        $categories = DB::table('amenity_categories')
            ->orderBy('category_name', 'asc')
            ->get();

        $counts = DB::table('amenities')
            ->whereNull('deleted_at')
            ->select('category_id', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('category_id')
            ->pluck('aggregate_count', 'category_id')
            ->toArray();

        return $categories->map(function ($item) use ($counts) {
            return [
                'id' => $item->id,
                'category_name' => $item->category_name,
                'category_code' => $item->category_code,
                'icon_name' => $item->icon_name,
                'description' => $item->description,
                'created_at' => Carbon::parse($item->created_at)->toIso8601String(),
                'amenities_count' => (int) ($counts[$item->id] ?? 0),
            ];
        })->toArray();
    }

    public function findCategory(string $id): ?object
    {
        return DB::table('amenity_categories')->where('id', $id)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('amenity_categories')->insert([
            'id' => $id,
            'category_name' => trim($data['category_name']),
            'category_code' => strtoupper(trim($data['category_code'])),
            'icon_name' => $data['icon_name'] ?? null,
            'description' => $data['description'] ?? null,
            'created_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(string $id, array $data): bool
    {
        return (bool) DB::table('amenity_categories')->where('id', $id)->update($data);
    }

    public function deleteCategory(string $id): bool
    {
        return (bool) DB::table('amenity_categories')->where('id', $id)->delete();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBlocks(): array
    {
        return DB::table('blocks')
            ->select('id', 'block_name', 'block_code', 'total_floors', 'total_units')
            ->orderBy('block_name', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTimeSlots(string $amenityId): array
    {
        return DB::table('amenity_time_slots')
            ->where('amenity_id', $amenityId)
            ->orderBy('day_of_week', 'asc')
            ->orderBy('slot_start_time', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'amenity_id' => $item->amenity_id,
                    'day_of_week' => (int) $item->day_of_week,
                    'start_time' => mb_substr((string) $item->slot_start_time, 0, 5),
                    'end_time' => mb_substr((string) $item->slot_end_time, 0, 5),
                    'is_active' => (bool) $item->is_active,
                ];
            })
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBlackouts(string $amenityId): array
    {
        return DB::table('amenity_blackouts')
            ->where('amenity_id', $amenityId)
            ->orderBy('blackout_date', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'amenity_id' => $item->amenity_id,
                    'blackout_date' => $item->blackout_date,
                    'start_time' => $item->start_time,
                    'end_time' => $item->end_time,
                    'reason' => $item->reason,
                    'created_at' => Carbon::parse($item->created_at)->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getBookings(string $amenityId, array $filters = []): array
    {
        $query = DB::table('amenity_bookings')
            ->leftJoin('apartments', 'amenity_bookings.apartment_id', '=', 'apartments.id')
            ->leftJoin('users', 'amenity_bookings.resident_user_id', '=', 'users.id')
            ->where('amenity_bookings.amenity_id', $amenityId)
            ->whereNull('amenity_bookings.deleted_at')
            ->select(
                'amenity_bookings.*',
                'apartments.apartment_number',
                'users.full_name as user_name',
                'users.phone_number as user_phone'
            );

        if (! empty($filters['status'])) {
            $query->where('amenity_bookings.status', $filters['status']);
        }
        if (! empty($filters['booking_date'])) {
            $query->where('amenity_bookings.booking_date', $filters['booking_date']);
        }

        return $query->orderBy('amenity_bookings.booking_date', 'desc')
            ->orderBy('amenity_bookings.start_time', 'asc')
            ->get()
            ->toArray();
    }

    public function countActiveBookings(string $amenityId): int
    {
        return (int) DB::table('amenity_bookings')
            ->where('amenity_id', $amenityId)
            ->whereIn('status', DatabaseResidentAmenityBookingRepository::HOLDING_STATUSES)
            ->whereNull('deleted_at')
            ->count();
    }

    public function getPeakBookings(string $amenityId): int
    {
        $activeSlotBookings = DB::table('amenity_bookings')
            ->where('amenity_id', $amenityId)
            ->whereIn('status', DatabaseResidentAmenityBookingRepository::HOLDING_STATUSES)
            ->whereNull('deleted_at')
            ->select('booking_date', 'start_time', 'end_time', 'attendee_count')
            ->get();

        $events = [];
        foreach ($activeSlotBookings as $booking) {
            $people = max(1, (int) $booking->attendee_count);
            $events[$booking->booking_date][] = [$booking->start_time, $people];
            $events[$booking->booking_date][] = [$booking->end_time, -$people];
        }
        $peakBookings = 0;
        foreach ($events as $dayEvents) {
            usort($dayEvents, fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
            $currentPeople = 0;
            foreach ($dayEvents as [, $delta]) {
                $currentPeople += $delta;
                $peakBookings = max($peakBookings, $currentPeople);
            }
        }

        return $peakBookings;
    }

    public function isCodeExists(string $code, ?string $excludeId = null): bool
    {
        $query = DB::table('amenities')
            ->where('amenity_code', $code)
            ->whereNull('deleted_at');

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function getAmenityDetail(string $id): ?object
    {
        return DB::table('amenities')
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
    }

    /**
     * @param  array<int, string>  $amenityIds
     * @return array<string, array{total: int, active: int}>
     */
    public function getSlotCountsForAmenities(array $amenityIds): array
    {
        if (empty($amenityIds)) {
            return [];
        }

        $slots = DB::table('amenity_time_slots')
            ->whereIn('amenity_id', $amenityIds)
            ->select('amenity_id', 'is_active', DB::raw('count(*) as count'))
            ->groupBy('amenity_id', 'is_active')
            ->get();

        $result = [];
        foreach ($slots as $slot) {
            $aid = (string) $slot->amenity_id;
            if (! isset($result[$aid])) {
                $result[$aid] = ['total' => 0, 'active' => 0];
            }
            $result[$aid]['total'] += (int) $slot->count;
            if ($slot->is_active) {
                $result[$aid]['active'] += (int) $slot->count;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $amenityIds
     * @return array<string, int>
     */
    public function getActiveBookingCountsForAmenities(array $amenityIds): array
    {
        if (empty($amenityIds)) {
            return [];
        }

        $bookings = DB::table('amenity_bookings')
            ->whereIn('amenity_id', $amenityIds)
            ->whereIn('status', DatabaseResidentAmenityBookingRepository::HOLDING_STATUSES)
            ->whereNull('deleted_at')
            ->select('amenity_id', DB::raw('count(*) as count'))
            ->groupBy('amenity_id')
            ->get();

        $result = [];
        foreach ($bookings as $b) {
            $result[(string) $b->amenity_id] = (int) $b->count;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAmenityBookingsWithDetails(string $amenityId): array
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
