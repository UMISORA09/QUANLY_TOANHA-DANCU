<?php

namespace Tests\Feature;

use App\Repositories\Contracts\AmenityRepositoryInterface;
use App\Repositories\Eloquent\DatabaseAmenityRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityConcurrencyTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
    }

    private function url(): string
    {
        return '/api/v1/admin/amenities/'.$this->amenityId;
    }

    public function test_two_tabs_cannot_overwrite_the_first_update_even_in_the_same_second(): void
    {
        $this->freezeTime();
        $tabA = $this->getJson($this->url())->assertOk()->json();
        $tabB = $this->getJson($this->url())->assertOk()->json();
        $first = $this->putJson($this->url(), ['amenity_name' => 'Admin A', 'updated_at' => $tabA['updated_at']])->assertOk();
        $this->assertNotSame($tabA['updated_at'], $first->json('updated_at'));
        $this->putJson($this->url(), ['amenity_name' => 'Admin B', 'updated_at' => $tabB['updated_at']])
            ->assertConflict()->assertExactJson(['message' => 'Dữ liệu đã được thay đổi bởi người dùng khác.', 'code' => 'AMENITY_CONFLICT']);
        $this->assertDatabaseHas('amenities', ['id' => $this->amenityId, 'amenity_name' => 'Admin A']);
        $this->assertSame(1, DB::table('audit_logs')->where('record_id', $this->amenityId)->count());
        $this->putJson($this->url(), ['amenity_name' => 'Latest token', 'updated_at' => $first->json('updated_at')])->assertOk();
    }

    public function test_status_changes_use_the_same_occ_and_audit_path(): void
    {
        $timestamp = $this->getJson($this->url())->json('updated_at');
        $this->patchJson($this->url().'/status', ['is_active' => false, 'updated_at' => $timestamp])->assertOk();
        $this->patchJson($this->url().'/status', ['is_active' => true, 'updated_at' => $timestamp])->assertConflict();
        $this->assertDatabaseHas('amenities', ['id' => $this->amenityId, 'is_active' => 0]);
        $this->assertDatabaseHas('audit_logs', ['record_id' => $this->amenityId, 'performed_by_user_id' => $this->adminUser->id, 'action' => 'UPDATE']);
    }

    public function test_update_of_deleted_or_missing_amenity_returns_404_without_audit(): void
    {
        $timestamp = $this->getJson($this->url())->json('updated_at');
        $this->deleteJson($this->url())->assertOk();
        $this->putJson($this->url(), ['amenity_name' => 'Resurrected', 'updated_at' => $timestamp])->assertNotFound();
        $this->putJson('/api/v1/admin/amenities/'.Str::uuid(), ['amenity_name' => 'Missing', 'updated_at' => $timestamp])->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('record_id', $this->amenityId)->count());
        $this->assertDatabaseHas('audit_logs', ['record_id' => $this->amenityId, 'action' => 'DELETE']);
    }

    public function test_update_requires_a_real_timestamp_token(): void
    {
        $this->putJson($this->url(), ['amenity_name' => 'Unsafe', 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('updated_at');
        $this->putJson($this->url(), ['updated_at' => 'invalid'])->assertUnprocessable();
    }

    public function test_create_records_audit_in_the_same_transaction(): void
    {
        $created = $this->postJson('/api/v1/admin/amenities', [
            'category_id' => $this->categoryId, 'amenity_name' => 'Audit create', 'amenity_code' => 'AUDIT-'.Str::upper(Str::random(10)),
            'location_detail' => 'Test', 'max_capacity_per_slot' => 4, 'hourly_rate' => 0,
        ])->assertCreated();
        $audit = DB::table('audit_logs')->where('record_id', $created->json('id'))->first();
        $this->assertSame('INSERT', $audit->action);
        $this->assertSame($this->adminUser->id, $audit->performed_by_user_id);
        $this->assertNull($audit->old_data);
        $this->assertSame('Audit create', json_decode($audit->new_data, true)['amenity_name']);
    }

    public function test_audit_failure_rolls_back_update_and_cache_invalidation(): void
    {
        $existing = DB::table('amenities')->where('id', $this->amenityId)->first();
        $cacheVersion = cache()->get('amenities_data_version');
        $repository = Mockery::mock(DatabaseAmenityRepository::class)->makePartial();
        $repository->shouldReceive('recordAudit')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(AmenityRepositoryInterface::class, $repository);
        $this->withoutExceptionHandling();
        try {
            $this->putJson($this->url(), ['amenity_name' => 'Must rollback', 'updated_at' => $existing->updated_at]);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseHas('amenities', ['id' => $this->amenityId, 'amenity_name' => $existing->amenity_name, 'updated_at' => $existing->updated_at]);
        $this->assertSame(0, DB::table('audit_logs')->where('record_id', $this->amenityId)->count());
        $this->assertSame($cacheVersion, cache()->get('amenities_data_version'));
    }

    public function test_repository_uses_actual_slot_blackout_and_booking_columns(): void
    {
        $repository = app(AmenityRepositoryInterface::class);
        $this->assertSame('10:00', $repository->getTimeSlots($this->amenityId)[0]['start_time']);
        DB::table('amenity_blackouts')->insert(['id' => (string) Str::uuid(), 'amenity_id' => $this->amenityId, 'blackout_date' => $this->bookingDate, 'reason' => 'Maintenance']);
        $this->assertSame($this->bookingDate, $repository->getBlackouts($this->amenityId)[0]['blackout_date']);
        $this->assertSame([], $repository->getBookings($this->amenityId));
    }

    public function test_capacity_reduction_counts_partially_overlapping_legacy_bookings(): void
    {
        foreach ([['10:00', '11:00', 2], ['10:30', '11:30', 2]] as [$start, $end, $people]) {
            DB::table('amenity_bookings')->insert([
                'id' => (string) Str::uuid(), 'booking_code' => 'TEST-'.Str::random(12), 'amenity_id' => $this->amenityId,
                'apartment_id' => $this->apartmentId, 'resident_user_id' => $this->residentUser->id,
                'booking_date' => $this->bookingDate, 'start_time' => $start, 'end_time' => $end,
                'attendee_count' => $people, 'status' => 'CHECKED_IN', 'checkin_qr_code' => Str::random(32),
            ]);
        }
        $timestamp = $this->getJson($this->url())->json('updated_at');
        $this->putJson($this->url(), ['max_capacity_per_slot' => 3, 'updated_at' => $timestamp])->assertUnprocessable();
        $this->assertSame(4, app(AmenityRepositoryInterface::class)->getPeakBookings($this->amenityId));
    }
}
