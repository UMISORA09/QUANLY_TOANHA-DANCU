<?php

namespace Tests;

use App\Models\Resident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ResidentAmenityBookingFixtures
{
    protected User $residentUser;

    protected User $secondUser;

    protected User $adminUser;

    protected string $residentToken;

    protected string $secondToken;

    protected string $adminToken;

    protected string $blockId;

    protected string $apartmentId;

    protected string $amenityId;

    protected string $categoryId;

    protected string $slotId;

    protected string $bookingDate;

    protected function createBookingFixture(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName(), 'Booking tests require a dedicated *_test database.');
        $this->residentUser = User::factory()->create();
        $this->secondUser = User::factory()->create();
        $this->adminUser = User::factory()->create();
        $this->residentToken = $this->sessionToken($this->residentUser);
        $this->secondToken = $this->sessionToken($this->secondUser);
        $this->adminToken = $this->sessionToken($this->adminUser);
        $role = DB::table('roles')->where('role_code', 'SUPER_ADMIN')->first();
        $roleId = $role?->id ?? (string) Str::uuid();
        if (! $role) {
            DB::table('roles')->insert(['id' => $roleId, 'role_code' => 'SUPER_ADMIN', 'role_name' => 'Test administrator']);
        }
        DB::table('user_roles')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->adminUser->id, 'role_id' => $roleId]);
        $this->blockId = (string) Str::uuid();
        $floorId = (string) Str::uuid();
        $this->apartmentId = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $this->blockId, 'block_code' => 'TEST-'.Str::random(8), 'block_name' => 'Booking Test Block']);
        DB::table('floors')->insert(['id' => $floorId, 'block_id' => $this->blockId, 'floor_number' => 1, 'floor_code' => 'F1', 'floor_name' => 'Floor 1']);
        DB::table('apartments')->insert(['id' => $this->apartmentId, 'block_id' => $this->blockId, 'floor_id' => $floorId, 'apartment_number' => 'TEST-101', 'gross_floor_area_sqm' => 80, 'net_usable_area_sqm' => 70]);
        foreach ([$this->residentUser, $this->secondUser] as $user) {
            Resident::factory()->create(['user_id' => $user->id, 'apartment_id' => $this->apartmentId]);
        }
        $this->categoryId = (string) Str::uuid();
        DB::table('amenity_categories')->insert(['id' => $this->categoryId, 'category_name' => 'Test '.Str::random(8), 'category_code' => Str::upper(Str::random(8))]);
        $this->amenityId = (string) Str::uuid();
        DB::table('amenities')->insert([
            'id' => $this->amenityId, 'category_id' => $this->categoryId, 'block_id' => $this->blockId,
            'amenity_name' => 'Test BBQ', 'amenity_code' => 'TEST-'.Str::upper(Str::random(8)), 'location_detail' => 'Garden',
            'max_capacity_per_slot' => 4, 'hourly_rate' => 100000, 'security_deposit_required' => 50000,
            'advance_booking_days_limit' => 7, 'min_cancel_hours_before' => 12, 'requires_admin_approval' => 1,
        ]);
        $this->bookingDate = today()->addDays(2)->toDateString();
        $this->slotId = (string) Str::uuid();
        DB::table('amenity_time_slots')->insert([
            'id' => $this->slotId, 'amenity_id' => $this->amenityId, 'day_of_week' => today()->addDays(2)->dayOfWeek,
            'slot_start_time' => '10:00', 'slot_end_time' => '11:30', 'slot_label' => 'Buổi sáng', 'max_bookings' => 2,
        ]);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
    }

    protected function sessionToken(User $user): string
    {
        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        DB::table('user_sessions')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'refresh_token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30), 'is_revoked' => 0]);

        return $token;
    }

    /** @return array<string, mixed> */
    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge(['amenity_id' => $this->amenityId, 'slot_id' => $this->slotId, 'apartment_id' => $this->apartmentId, 'booking_date' => $this->bookingDate, 'attendee_count' => 2, 'accepted_rules' => true], $overrides);
    }

    protected function availabilityUrl(): string
    {
        return '/api/v1/resident/amenities/'.$this->amenityId.'/availability?date='.$this->bookingDate.'&apartment_id='.$this->apartmentId;
    }
}
