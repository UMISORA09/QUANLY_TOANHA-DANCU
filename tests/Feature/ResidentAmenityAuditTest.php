<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAmenityAuditTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
    }

    public function test_anonymous_caller_cannot_create_a_ticket_for_another_resident(): void
    {
        $categoryId = (string) Str::uuid();
        DB::table('ticket_categories')->insert(['id' => $categoryId, 'category_code' => Str::random(12), 'category_name' => 'QA category']);
        $this->withHeader('Authorization', '')->postJson('/api/v1/resident/tickets', [
            'title' => 'QA identity check', 'description' => 'Test only',
            'category_id' => $categoryId, 'user_id' => $this->secondUser->id, 'apartment_id' => $this->apartmentId,
        ])->assertUnauthorized();
    }

    public function test_personal_history_paginates_filters_and_never_includes_another_creator(): void
    {
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $original = (array) DB::table('amenity_bookings')->where('id', $id)->first();
        for ($index = 0; $index < 11; $index++) {
            DB::table('amenity_bookings')->insert(array_merge($original, [
                'id' => (string) Str::uuid(), 'booking_code' => 'QA-'.Str::random(12),
                'checkin_qr_code' => 'QA-'.Str::random(24), 'status' => 'CANCELLED',
            ]));
        }
        DB::table('amenity_bookings')->insert(array_merge($original, [
            'id' => (string) Str::uuid(), 'booking_code' => 'QA-'.Str::random(12),
            'checkin_qr_code' => 'QA-'.Str::random(24), 'resident_user_id' => $this->secondUser->id,
        ]));
        $this->getJson('/api/v1/resident/amenity-bookings?page=1')->assertOk()->assertJsonPath('total', 12)->assertJsonCount(10, 'items');
        $this->getJson('/api/v1/resident/amenity-bookings?page=2')->assertOk()->assertJsonCount(2, 'items');
        $this->getJson('/api/v1/resident/amenity-bookings?status=PENDING')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $id);
        $this->getJson('/api/v1/resident/amenity-bookings?status=INVALID')->assertUnprocessable();
        $this->getJson('/api/v1/resident/amenity-bookings?page=0')->assertUnprocessable();
    }

    public function test_ticket_uses_session_identity_and_checks_residency_and_category(): void
    {
        $categoryId = (string) Str::uuid();
        DB::table('ticket_categories')->insert(['id' => $categoryId, 'category_code' => Str::random(12), 'category_name' => 'QA category']);
        $payload = ['title' => 'QA ticket', 'description' => 'Test only', 'category_id' => $categoryId, 'user_id' => $this->secondUser->id, 'apartment_id' => $this->apartmentId];
        $this->postJson('/api/v1/resident/tickets', $payload)->assertCreated()->assertJsonPath('ticket.creator_user_id', $this->residentUser->id);
        $this->postJson('/api/v1/resident/tickets', array_merge($payload, ['apartment_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('apartment_id');
        $this->postJson('/api/v1/resident/tickets', array_merge($payload, ['category_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['stay_end_date' => today()->subDay()]);
        $this->postJson('/api/v1/resident/tickets', $payload)->assertForbidden();
    }

    public function test_slot_creation_and_blackouts_validate_times_and_missing_children(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $base = '/api/v1/admin/amenities/'.$this->amenityId;
        $this->postJson($base.'/time-slots', ['day_of_week' => 1, 'slot_start_time' => '25:00', 'slot_end_time' => '26:00'])->assertUnprocessable();
        foreach ([['start_time' => '12:00'], ['start_time' => '12:00', 'end_time' => '10:00']] as $times) {
            $this->postJson($base.'/blackouts', array_merge(['blackout_date' => $this->bookingDate, 'reason' => 'QA maintenance'], $times))->assertUnprocessable();
        }
        $this->postJson($base.'/blackouts', ['blackout_date' => $this->bookingDate, 'reason' => 'Full day'])->assertCreated();
        $this->patchJson($base.'/time-slots/'.Str::uuid().'/status', ['is_active' => false])->assertNotFound();
        $this->deleteJson($base.'/time-slots/'.Str::uuid())->assertNotFound();
        $this->putJson($base.'/blackouts/'.Str::uuid(), ['blackout_date' => $this->bookingDate, 'reason' => 'QA'])->assertNotFound();
        $this->deleteJson($base.'/blackouts/'.Str::uuid())->assertNotFound();
    }

    public function test_availability_rejects_foreign_buildings_and_malformed_dates(): void
    {
        $this->getJson(str_replace($this->bookingDate, '2026-02-30', $this->availabilityUrl()))->assertUnprocessable();
        $blockId = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $blockId, 'block_code' => Str::random(12), 'block_name' => 'Other QA block']);
        DB::table('amenities')->where('id', $this->amenityId)->update(['block_id' => $blockId]);
        $this->getJson($this->availabilityUrl())->assertNotFound();
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertNotFound();
    }

    public function test_admin_cannot_save_a_slot_with_end_before_start(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->putJson('/api/v1/admin/amenities/'.$this->amenityId.'/time-slots/'.$this->slotId, [
                'day_of_week' => today()->addDay()->dayOfWeek, 'slot_start_time' => '12:00', 'slot_end_time' => '10:00', 'max_bookings' => 2,
            ])->assertUnprocessable();
    }

    public function test_admin_cannot_reduce_slot_limit_below_existing_holding_bookings(): void
    {
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken)
            ->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->putJson('/api/v1/admin/amenities/'.$this->amenityId.'/time-slots/'.$this->slotId, [
                'day_of_week' => today()->addDay()->dayOfWeek, 'slot_start_time' => '10:00', 'slot_end_time' => '11:30', 'max_bookings' => 1,
            ])->assertConflict();
    }

    public function test_slot_identifier_must_belong_to_the_amenity_in_the_url(): void
    {
        $otherAmenityId = (string) Str::uuid();
        $original = (array) DB::table('amenities')->where('id', $this->amenityId)->first();
        DB::table('amenities')->insert(array_merge($original, ['id' => $otherAmenityId, 'amenity_code' => Str::random(12)]));
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->putJson('/api/v1/admin/amenities/'.$otherAmenityId.'/time-slots/'.$this->slotId, [
                'day_of_week' => today()->addDay()->dayOfWeek, 'slot_start_time' => '10:00', 'slot_end_time' => '11:30', 'max_bookings' => 2,
            ])->assertNotFound();
    }

    public function test_rejection_reason_is_visible_to_the_booking_creator(): void
    {
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->patchJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status', ['status' => 'REJECTED'])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->patchJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status', ['status' => 'REJECTED', 'rejection_reason' => 'Maintenance conflict'])->assertOk();
        $this->patchJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status', ['status' => 'REJECTED', 'rejection_reason' => 'Second rejection'])->assertConflict();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->getJson('/api/v1/resident/amenity-bookings/'.$id)->assertJsonPath('status', 'REJECTED')->assertJsonPath('rejection_reason', 'Maintenance conflict');
    }
}
