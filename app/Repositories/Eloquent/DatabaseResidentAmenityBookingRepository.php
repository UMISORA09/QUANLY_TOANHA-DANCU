<?php

namespace App\Repositories\Eloquent;

use App\Services\AmenityBookingPaymentService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DatabaseResidentAmenityBookingRepository
{
    public const HOLDING_STATUSES = ['PENDING', 'APPROVED', 'CONFIRMED', 'CHECKED_IN'];

    public function apartments(string $userId): Collection
    {
        return DB::table('residents')
            ->join('apartments', 'apartments.id', '=', 'residents.apartment_id')
            ->join('blocks', 'blocks.id', '=', 'apartments.block_id')
            ->where('residents.user_id', $userId)
            ->where('residents.is_active', 1)
            ->whereNull('residents.deleted_at')
            ->whereNull('apartments.deleted_at')
            ->whereNull('blocks.deleted_at')
            ->whereDate('residents.stay_start_date', '<=', today())
            ->where(fn (Builder $query) => $query->whereNull('residents.stay_end_date')->orWhereDate('residents.stay_end_date', '>=', today()))
            ->select('apartments.id', 'apartments.apartment_number', 'apartments.block_id', 'blocks.block_name')
            ->orderBy('apartments.apartment_number')->get();
    }

    /** @param array<int, string> $blockIds */
    public function amenities(array $blockIds): Collection
    {
        return DB::table('amenities')
            ->join('amenity_categories', 'amenity_categories.id', '=', 'amenities.category_id')
            ->leftJoin('blocks', 'blocks.id', '=', 'amenities.block_id')
            ->where('amenities.is_active', 1)->whereNull('amenities.deleted_at')
            ->where(fn (Builder $query) => $query->whereNull('amenities.block_id')->orWhereIn('amenities.block_id', $blockIds))
            ->select('amenities.*', 'amenity_categories.category_name', 'blocks.block_name')
            ->orderBy('amenities.amenity_name')->get();
    }

    public function amenity(string $id, bool $lock = false): ?object
    {
        $query = DB::table('amenities')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function slots(string $amenityId, int $dayOfWeek, bool $lock = false): Collection
    {
        $query = DB::table('amenity_time_slots')->where('amenity_id', $amenityId)
            ->where('day_of_week', $dayOfWeek)->orderBy('slot_start_time');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function blackouts(string $amenityId, string $date, bool $lock = false): Collection
    {
        $query = DB::table('amenity_blackouts')->where('amenity_id', $amenityId)->whereDate('blackout_date', $date);

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function holdingBookings(string $amenityId, string $date, bool $lock = false): Collection
    {
        $query = DB::table('amenity_bookings')->where('amenity_id', $amenityId)
            ->whereDate('booking_date', $date)->whereNull('deleted_at')->whereIn('status', self::HOLDING_STATUSES);

        $query = AmenityBookingPaymentService::holdingQuery($query);

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function bookings(string $userId): Builder
    {
        return DB::table('amenity_bookings')
            ->join('amenities', 'amenities.id', '=', 'amenity_bookings.amenity_id')
            ->join('apartments', 'apartments.id', '=', 'amenity_bookings.apartment_id')
            ->where('amenity_bookings.resident_user_id', $userId)->whereNull('amenity_bookings.deleted_at')
            ->select('amenity_bookings.*', 'amenities.amenity_name', 'amenities.location_detail', 'amenities.min_cancel_hours_before', 'apartments.apartment_number');
    }
}
