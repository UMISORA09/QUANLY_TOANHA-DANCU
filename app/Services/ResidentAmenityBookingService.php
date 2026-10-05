<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Eloquent\DatabaseResidentAmenityBookingRepository;
use App\Services\Search\SearchCacheService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResidentAmenityBookingService
{
    public function __construct(public DatabaseResidentAmenityBookingRepository $repository) {}

    /** @return array{apartments: Collection, amenities: Collection, categories: Collection, today: string, timezone: string} */
    public function catalog(User $user): array
    {
        $apartments = $this->repository->apartments($user->id);
        $amenities = $apartments->isEmpty() ? collect() : $this->repository->amenities($apartments->pluck('block_id')->all());

        return [
            'apartments' => $apartments,
            'amenities' => $amenities,
            'categories' => $amenities->map(fn (object $amenity): array => ['id' => $amenity->category_id, 'category_name' => $amenity->category_name])->unique('id')->values(),
            'today' => today()->toDateString(),
            'timezone' => config('app.timezone'),
        ];
    }

    private function apartment(User $user, ?string $id): object
    {
        $apartments = $this->repository->apartments($user->id);
        if ($apartments->isEmpty()) {
            abort(403, 'Bạn chưa có hồ sơ cư trú còn hiệu lực.');
        }
        if ($id === null && $apartments->count() === 1) {
            return $apartments->first();
        }
        $apartment = $apartments->firstWhere('id', $id);
        if (! $apartment) {
            throw ValidationException::withMessages(['apartment_id' => 'Vui lòng chọn căn hộ bạn đang cư trú.']);
        }

        return $apartment;
    }

    private function accessibleAmenity(string $id, object $apartment, bool $lock = false): object
    {
        $amenity = $this->repository->amenity($id, $lock);
        abort_unless($amenity && ! $amenity->deleted_at && (! $amenity->block_id || $amenity->block_id === $apartment->block_id), 404, 'Không tìm thấy tiện ích được phép đăng ký.');

        return $amenity;
    }

    private function validateDate(object $amenity, string $date): Carbon
    {
        $day = Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        if ($day->lt(today()) || $day->gt(today()->addDays((int) $amenity->advance_booking_days_limit))) {
            throw ValidationException::withMessages(['booking_date' => 'Ngày đăng ký nằm ngoài giới hạn đặt trước của tiện ích.']);
        }

        return $day;
    }

    /** @return array{slots: Collection, date: string, amenity_id: string} */
    public function availability(User $user, string $amenityId, string $date, ?string $apartmentId): array
    {
        $amenity = $this->accessibleAmenity($amenityId, $this->apartment($user, $apartmentId));
        $day = $this->validateDate($amenity, $date);
        $bookings = $this->repository->holdingBookings($amenityId, $date);
        $blackouts = $this->repository->blackouts($amenityId, $date);

        return [
            'slots' => $this->repository->slots($amenityId, $day->dayOfWeek)->map(fn (object $slot): array => $this->slotAvailability($user, $amenity, $slot, $date, $bookings, $blackouts)),
            'date' => $date,
            'amenity_id' => $amenityId,
        ];
    }

    /** @return array{slot_id: string, start_time: string, end_time: string, slot_label: ?string, remaining_bookings: int, remaining_attendees: int, available: bool, reason: ?string, total_amount: float, deposit_amount: float} */
    private function slotAvailability(User $user, object $amenity, object $slot, string $date, Collection $bookings, Collection $blackouts): array
    {
        $start = substr($slot->slot_start_time, 0, 5);
        $end = substr($slot->slot_end_time, 0, 5);
        $overlapping = $bookings->filter(fn (object $booking): bool => substr($booking->start_time, 0, 5) < $end && substr($booking->end_time, 0, 5) > $start);
        $remainingBookings = max(0, (int) $slot->max_bookings - $overlapping->count());
        $remainingAttendees = max(0, (int) $amenity->max_capacity_per_slot - (int) $overlapping->sum('attendee_count'));
        $blackout = $blackouts->first(fn (object $blackout): bool => ! $blackout->start_time || (substr($blackout->start_time, 0, 5) < $end && substr($blackout->end_time, 0, 5) > $start));
        $reason = null;
        if (! $amenity->is_active) {
            $reason = 'Tiện ích hiện đang tạm ngưng hoạt động.';
        } elseif (! $slot->is_active || $start >= $end) {
            $reason = 'Khung giờ hiện không hoạt động.';
        } elseif (Carbon::parse($date.' '.$start, config('app.timezone'))->lte(now())) {
            $reason = 'Khung giờ đã qua.';
        } elseif ($blackout) {
            $reason = 'Bảo trì: '.$blackout->reason;
        } elseif ($overlapping->contains(fn (object $booking): bool => $booking->resident_user_id === $user->id && substr($booking->start_time, 0, 5) === $start && substr($booking->end_time, 0, 5) === $end)) {
            $reason = 'Bạn đã đăng ký khung giờ này.';
        } elseif ($remainingBookings === 0 || $remainingAttendees === 0) {
            $reason = 'Khung giờ đã hết chỗ.';
        }
        $minutes = Carbon::parse($start)->diffInMinutes(Carbon::parse($end));

        return [
            'slot_id' => $slot->id, 'start_time' => $start, 'end_time' => $end,
            'slot_label' => $slot->slot_label, 'remaining_bookings' => $remainingBookings,
            'remaining_attendees' => $remainingAttendees, 'available' => $reason === null, 'reason' => $reason,
            'total_amount' => round((float) $amenity->hourly_rate * $minutes / 60, 2),
            'deposit_amount' => (float) $amenity->security_deposit_required,
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(User $user, array $data, Request $request): array
    {
        return DB::transaction(function () use ($user, $data, $request): array {
            $apartment = $this->apartment($user, $data['apartment_id'] ?? null);
            $amenity = $this->accessibleAmenity($data['amenity_id'], $apartment, true);
            $day = $this->validateDate($amenity, $data['booking_date']);
            $slots = $this->repository->slots($amenity->id, $day->dayOfWeek, true);
            $slot = ! empty($data['slot_id']) ? $slots->firstWhere('id', $data['slot_id']) : $slots->first(fn (object $slot): bool => substr($slot->slot_start_time, 0, 5) === $data['start_time'] && substr($slot->slot_end_time, 0, 5) === $data['end_time']);
            if (! $slot) {
                throw ValidationException::withMessages(['slot_id' => 'Khung giờ không khớp cấu hình của ngày đã chọn.']);
            }
            $availability = $this->slotAvailability($user, $amenity, $slot, $data['booking_date'], $this->repository->holdingBookings($amenity->id, $data['booking_date'], true), $this->repository->blackouts($amenity->id, $data['booking_date'], true));
            abort_unless($availability['available'], 409, $availability['reason'] ?? 'Khung giờ không còn khả dụng.');
            if ((int) $data['attendee_count'] > (int) $amenity->max_capacity_per_slot) {
                throw ValidationException::withMessages(['attendee_count' => 'Số người vượt sức chứa của tiện ích.']);
            }
            abort_if((int) $data['attendee_count'] > $availability['remaining_attendees'], 409, 'Khung giờ không còn đủ chỗ cho số người đã chọn.');
            $id = (string) Str::uuid();
            DB::table('amenity_bookings')->insert([
                'id' => $id, 'booking_code' => 'BK-'.Str::upper(Str::random(12)),
                'amenity_id' => $amenity->id, 'apartment_id' => $apartment->id, 'resident_user_id' => $user->id,
                'booking_date' => $data['booking_date'], 'start_time' => $availability['start_time'], 'end_time' => $availability['end_time'],
                'attendee_count' => $data['attendee_count'], 'total_amount' => $availability['total_amount'], 'deposit_amount' => $availability['deposit_amount'],
                'is_paid' => $availability['total_amount'] === 0.0 && $availability['deposit_amount'] === 0.0 ? 1 : 0,
                'status' => $amenity->requires_admin_approval ? 'PENDING' : 'APPROVED',
                'checkin_qr_code' => 'QR-'.Str::upper(Str::random(32)),
                'resident_notes' => $data['resident_notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $booking = $this->detail($user, $id, true);
            $this->audit($user, $id, 'INSERT', null, $booking, $request);
            $this->invalidateAfterCommit();

            return $booking;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function list(User $user, ?string $status, int $page): array
    {
        $query = $this->repository->bookings($user->id);
        if ($status) {
            $query->where('amenity_bookings.status', $status);
        }
        $total = (clone $query)->count();
        $items = $query->orderByDesc('booking_date')->orderByDesc('start_time')->orderByDesc('amenity_bookings.created_at')->orderBy('amenity_bookings.id')->forPage($page, 10)->get()->map(fn (object $booking): array => $this->formatBooking($booking));

        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => max(1, (int) ceil($total / 10))];
    }

    /** @return array<string, mixed> */
    public function detail(User $user, string $id, bool $lock = false): array
    {
        $query = $this->repository->bookings($user->id)->where('amenity_bookings.id', $id);
        $booking = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($booking, 404, 'Không tìm thấy lượt đăng ký.');

        return $this->formatBooking($booking);
    }

    /** @return array<string, mixed> */
    private function formatBooking(object $booking): array
    {
        $start = Carbon::parse($booking->booking_date.' '.$booking->start_time, config('app.timezone'));
        $deadline = $start->copy()->subHours((int) $booking->min_cancel_hours_before);
        $canCancel = in_array($booking->status, ['PENDING', 'APPROVED', 'CONFIRMED'], true) && now()->lt($start) && now()->lte($deadline);

        return array_merge((array) $booking, [
            'start_time' => substr($booking->start_time, 0, 5), 'end_time' => substr($booking->end_time, 0, 5),
            'total_amount' => (float) $booking->total_amount, 'deposit_amount' => (float) $booking->deposit_amount,
            'is_paid' => (bool) $booking->is_paid, 'can_cancel' => $canCancel,
            'cancel_deadline' => $deadline->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    public function cancel(User $user, string $id, ?string $reason, Request $request): array
    {
        $existing = $this->detail($user, $id);

        return DB::transaction(function () use ($user, $id, $reason, $request, $existing): array {
            $this->repository->amenity($existing['amenity_id'], true);
            DB::table('amenity_bookings')->where('id', $id)->lockForUpdate()->first();
            $booking = $this->detail($user, $id, true);
            abort_unless($booking['can_cancel'], 409, 'Lượt đăng ký đã qua thời hạn hủy hoặc không còn được phép hủy.');
            $notes = trim(($booking['resident_notes'] ?? '').($reason ? "\nLý do hủy: ".$reason : ''));
            DB::table('amenity_bookings')->where('id', $id)->update(['status' => 'CANCELLED', 'resident_notes' => $notes ?: null, 'updated_at' => now()]);
            $updated = $this->detail($user, $id, true);
            $this->audit($user, $id, 'UPDATE', $booking, $updated, $request);
            $this->invalidateAfterCommit();

            return $updated;
        }, 3);
    }

    public function invalidateAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            Cache::add('amenities_data_version', 1);
            Cache::increment('amenities_data_version');
            Cache::forget('portal:amenities:available');
            SearchCacheService::invalidate();
        });
    }

    /** @param array<string, mixed>|null $oldData
     * @param  array<string, mixed>  $newData
     */
    private function audit(User $user, string $id, string $action, ?array $oldData, array $newData, Request $request): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'table_name' => 'amenity_bookings', 'record_id' => $id, 'action' => $action,
            'performed_by_user_id' => $user->id, 'client_ip_address' => $request->ip(), 'user_agent' => Str::limit($request->userAgent() ?? '', 500, ''),
            'old_data' => $oldData ? json_encode($oldData, JSON_THROW_ON_ERROR) : null,
            'new_data' => json_encode($newData, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
