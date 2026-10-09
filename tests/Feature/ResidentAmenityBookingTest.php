<?php

namespace Tests\Feature;

use App\Models\Resident;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAmenityBookingTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
    }

    public function test_requires_persisted_active_session_and_active_account(): void
    {
        $this->withHeader('Authorization', '')->getJson('/api/v1/resident/amenities')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer smart_token_'.$this->residentUser->id.'_forged')->getJson('/api/v1/resident/amenities')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 1]);
        $this->getJson('/api/v1/resident/amenities')->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 0, 'expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/resident/amenities')->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['expires_at' => now()->addDay()]);
        $this->residentUser->update(['status' => 'INACTIVE']);
        $this->getJson('/api/v1/resident/amenities')->assertForbidden();
    }

    public function test_overview_requires_session_and_only_shows_the_creators_bookings(): void
    {
        $this->withHeader('Authorization', '')->getJson('/api/v1/resident/overview')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->getJson('/api/v1/resident/overview?user_id='.$this->secondUser->id)
            ->assertOk()->assertJsonPath('user.id', $this->residentUser->id)->assertJsonCount(0, 'bookings');
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
        $this->getJson('/api/v1/resident/overview')->assertOk()->assertJsonCount(1, 'bookings')->assertJsonPath('kpis.upcoming_bookings_count', 1);
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['stay_end_date' => today()->subDay()]);
        $this->getJson('/api/v1/resident/overview')->assertForbidden();
    }

    public function test_catalog_filters_buildings_and_inactive_or_deleted_amenities(): void
    {
        $original = (array) DB::table('amenities')->where('id', $this->amenityId)->first();
        $common = (string) Str::uuid();
        DB::table('amenities')->insert(array_merge($original, ['id' => $common, 'amenity_code' => Str::random(12), 'block_id' => null]));
        $otherBlock = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $otherBlock, 'block_code' => Str::random(12), 'block_name' => 'Other Block']);
        $excludedIds = [];
        foreach ([['block_id' => $otherBlock], ['is_active' => 0], ['deleted_at' => now()]] as $change) {
            $excludedIds[] = $id = (string) Str::uuid();
            DB::table('amenities')->insert(array_merge($original, ['id' => $id, 'amenity_code' => Str::random(12)], $change));
        }
        $response = $this->getJson('/api/v1/resident/amenities')->assertOk()->assertJsonCount(1, 'apartments');
        $ids = array_column($response->json('amenities'), 'id');
        $this->assertContains($this->amenityId, $ids);
        $this->assertContains($common, $ids);
        foreach ($excludedIds as $id) {
            $this->assertNotContains($id, $ids);
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_residency_and_apartment_are_validated(): void
    {
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload(['apartment_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('apartment_id');
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['stay_end_date' => today()->subDay()]);
        $this->getJson('/api/v1/resident/amenities')->assertJsonCount(0, 'amenities');
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertForbidden();
    }

    public function test_creation_uses_slot_duration_current_identity_and_price_snapshot(): void
    {
        $response = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload(['user_id' => $this->secondUser->id, 'total_amount' => 1, 'resident_notes' => 'Gia đình tôi']))->assertCreated()
            ->assertJsonPath('booking.resident_user_id', $this->residentUser->id)->assertJsonPath('booking.total_amount', 150000)
            ->assertJsonPath('booking.deposit_amount', 50000)->assertJsonPath('booking.status', 'PENDING')->assertJsonPath('booking.is_paid', false);
        $id = $response->json('booking.id');
        DB::table('amenities')->where('id', $this->amenityId)->update(['hourly_rate' => 200000]);
        $this->getJson('/api/v1/resident/amenity-bookings/'.$id)->assertJsonPath('total_amount', 150000);
        $this->assertDatabaseHas('audit_logs', ['record_id' => $id, 'action' => 'INSERT', 'performed_by_user_id' => $this->residentUser->id]);
    }

    public function test_free_booking_without_approval_is_approved_and_paid_only_without_deposit(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['hourly_rate' => 0, 'requires_admin_approval' => 0]);
        $first = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->assertJsonPath('booking.status', 'APPROVED')->assertJsonPath('booking.is_paid', false);
        $this->postJson('/api/v1/resident/amenity-bookings/'.$first->json('booking.id').'/cancel')->assertOk();
        DB::table('amenities')->where('id', $this->amenityId)->update(['security_deposit_required' => 0]);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->assertJsonPath('booking.is_paid', true);
    }

    public function test_validation_rejects_arbitrary_slots_times_dates_and_missing_rules(): void
    {
        foreach ([['slot_id' => (string) Str::uuid()], ['booking_date' => today()->addDays(8)->toDateString()], ['booking_date' => today()->subDay()->toDateString()], ['accepted_rules' => false], ['attendee_count' => 5], ['resident_notes' => str_repeat('a', 501)]] as $change) {
            $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload($change))->assertUnprocessable();
        }
        $payload = $this->bookingPayload(['start_time' => '10:15', 'end_time' => '11:15']);
        unset($payload['slot_id']);
        $this->postJson('/api/v1/amenities/'.$this->amenityId.'/bookings', $payload)->assertUnprocessable();
        $payload['start_time'] = '10:00';
        $payload['end_time'] = '11:30';
        $this->postJson('/api/v1/amenities/'.$this->amenityId.'/bookings', $payload)->assertCreated();
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
    }

    public function test_blackouts_only_block_overlapping_hours_and_inactive_slots_are_disabled(): void
    {
        $id = (string) Str::uuid();
        DB::table('amenity_blackouts')->insert(['id' => $id, 'amenity_id' => $this->amenityId, 'blackout_date' => $this->bookingDate, 'start_time' => '12:00', 'end_time' => '13:00', 'reason' => 'Maintenance']);
        $this->getJson($this->availabilityUrl())->assertJsonPath('slots.0.available', true);
        DB::table('amenity_blackouts')->where('id', $id)->update(['start_time' => '11:00', 'end_time' => '12:00']);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
        DB::table('amenity_blackouts')->where('id', $id)->update(['start_time' => null, 'end_time' => null]);
        $this->getJson($this->availabilityUrl())->assertJsonPath('slots.0.available', false);
        DB::table('amenity_blackouts')->where('id', $id)->delete();
        DB::table('amenity_time_slots')->where('id', $this->slotId)->update(['is_active' => 0]);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
        DB::table('amenity_time_slots')->where('id', $this->slotId)->update(['is_active' => 1]);
        DB::table('amenities')->where('id', $this->amenityId)->update(['is_active' => 0]);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
    }

    public function test_slot_count_and_total_attendees_are_independent_limits_with_legacy_overlap(): void
    {
        $first = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload(['attendee_count' => 3]))->assertCreated();
        DB::table('amenity_bookings')->where('id', $first->json('booking.id'))->update(['start_time' => '09:30', 'end_time' => '10:30', 'status' => 'CHECKED_IN']);
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $this->getJson($this->availabilityUrl())->assertJsonPath('slots.0.remaining_attendees', 1)->assertJsonPath('slots.0.remaining_bookings', 1);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
        DB::table('amenities')->where('id', $this->amenityId)->update(['max_capacity_per_slot' => 20]);
        DB::table('amenity_time_slots')->where('id', $this->slotId)->update(['max_bookings' => 1]);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload(['attendee_count' => 1]))->assertConflict();
        DB::table('amenity_bookings')->where('id', $first->json('booking.id'))->update(['status' => 'COMPLETED']);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
    }

    public function test_only_creator_can_view_or_cancel_and_cancellation_frees_capacity(): void
    {
        $first = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
        $id = $first->json('booking.id');
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $this->getJson('/api/v1/resident/amenity-bookings')->assertJsonCount(0, 'items');
        $this->getJson('/api/v1/resident/amenity-bookings/'.$id)->assertNotFound();
        $this->getJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings')->assertForbidden();
        $this->postJson('/api/v1/amenities/'.$this->amenityId.'/bookings/'.$id.'/cancel')->assertNotFound();
        $this->patchJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status', ['status' => 'APPROVED'])->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/cancel', ['reason' => 'Đổi kế hoạch'])->assertOk()->assertJsonPath('booking.status', 'CANCELLED');
        $this->getJson($this->availabilityUrl())->assertJsonPath('slots.0.remaining_bookings', 2);
        $this->assertDatabaseHas('audit_logs', ['record_id' => $id, 'action' => 'UPDATE']);
        $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/cancel')->assertConflict();
    }

    public function test_personal_bookings_show_latest_registration_first_regardless_of_usage_date(): void
    {
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        DB::table('amenity_bookings')->where('id', $id)->update(['created_at' => now()->subMinute()]);
        $original = (array) DB::table('amenity_bookings')->where('id', $id)->first();
        $olderId = (string) Str::uuid();
        $newerId = (string) Str::uuid();
        DB::table('amenity_bookings')->insert([
            array_merge($original, ['id' => $olderId, 'booking_code' => 'TEST-'.Str::random(12), 'checkin_qr_code' => 'QR-'.Str::random(32), 'booking_date' => today()->addDays(5)->toDateString(), 'created_at' => now()->subMinutes(2)]),
            array_merge($original, ['id' => $newerId, 'booking_code' => 'TEST-'.Str::random(12), 'checkin_qr_code' => 'QR-'.Str::random(32), 'booking_date' => today()->toDateString(), 'created_at' => now()]),
        ]);

        $response = $this->getJson('/api/v1/resident/amenity-bookings')->assertOk()->assertJsonCount(3, 'items');
        $this->assertSame([$newerId, $id, $olderId], array_column($response->json('items'), 'id'));
    }

    public function test_cancellation_deadline_is_checked_at_boundary_and_paid_amount_is_preserved(): void
    {
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $deadline = Carbon::parse($this->bookingDate.' 10:00')->subHours(12);
        $this->travelTo($deadline->copy()->addSecond());
        $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/cancel')->assertConflict();
        $this->travelTo($deadline);
        $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/cancel')->assertOk()->assertJsonPath('booking.total_amount', 150000);
        $this->travelBack();
    }

    public function test_admin_can_approve_cancel_and_configuration_cannot_change_booking_prices(): void
    {
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status', ['status' => 'APPROVED'])->assertOk();
        $this->putJson('/api/v1/admin/amenities/'.$this->amenityId, ['hourly_rate' => 200000, 'updated_at' => DB::table('amenities')->where('id', $this->amenityId)->value('updated_at')])->assertOk();
        $this->postJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/cancel')->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'total_amount' => 150000, 'status' => 'CANCELLED']);
        $this->patchJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status', ['status' => 'APPROVED'])->assertConflict();
    }

    public function test_past_slots_and_wrong_weekday_are_rejected(): void
    {
        $this->travelTo(Carbon::parse($this->bookingDate.' 10:00'));
        DB::table('user_sessions')->update(['expires_at' => now()->addDay()]);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
        $this->travelBack();
        DB::table('amenity_time_slots')->where('id', $this->slotId)->update(['day_of_week' => (Carbon::parse($this->bookingDate)->dayOfWeek + 1) % 7]);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertUnprocessable();
    }

    public function test_multiple_apartments_require_an_explicit_selection(): void
    {
        $original = (array) DB::table('apartments')->where('id', $this->apartmentId)->first();
        $id = (string) Str::uuid();
        DB::table('apartments')->insert(array_merge($original, ['id' => $id, 'apartment_number' => 'TEST-102']));
        Resident::factory()->create(['user_id' => $this->residentUser->id, 'apartment_id' => $id]);
        $payload = $this->bookingPayload();
        unset($payload['apartment_id']);
        $this->postJson('/api/v1/resident/amenity-bookings', $payload)->assertUnprocessable()->assertJsonValidationErrors('apartment_id');
    }
}
