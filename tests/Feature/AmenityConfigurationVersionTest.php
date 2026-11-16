<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityConfigurationVersionTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        $this->withToken($this->adminToken);
    }

    private function base(): string
    {
        return '/api/v1/admin/amenities/'.$this->amenityId;
    }

    /** @return array<string, mixed> */
    private function slotPayload(int $version = 1): array
    {
        return ['version' => $version, 'day_of_week' => 1, 'slot_start_time' => '09:00', 'slot_end_time' => '10:00', 'max_bookings' => 2, 'slot_label' => 'Bản nhập'];
    }

    public function test_category_update_and_delete_require_the_reviewed_version(): void
    {
        $payload = ['category_name' => 'QA '.Str::random(10), 'category_code' => 'QA_'.Str::upper(Str::random(10))];
        $created = $this->postJson('/api/v1/admin/amenity-categories', $payload)->assertCreated()->assertJsonPath('version', 1)->json();
        $url = '/api/v1/admin/amenity-categories/'.$created['id'];
        $this->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson($url, [...$payload, 'version' => 1, 'description' => 'Người sửa đầu tiên'])->assertOk()->assertJsonPath('version', 2);
        $this->putJson($url, [...$payload, 'version' => 1, 'description' => 'Bản cũ ghi đè'])->assertConflict();
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $this->assertDatabaseHas('amenity_categories', ['id' => $created['id'], 'description' => 'Người sửa đầu tiên', 'version' => 2]);
        $latest = collect($this->getJson('/api/v1/admin/amenity-categories')->assertOk()->json())->firstWhere('id', $created['id']);
        $this->assertSame(2, $latest['version']);
        $this->deleteJson($url, ['version' => 2])->assertOk();
        $this->assertDatabaseMissing('amenity_categories', ['id' => $created['id']]);
    }

    public function test_slot_edit_toggle_and_delete_share_one_version(): void
    {
        $url = $this->base().'/time-slots/'.$this->slotId;
        $this->assertSame(1, $this->getJson($this->base().'/time-slots')->assertOk()->json('0.version'));
        $this->patchJson($url.'/status', ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->patchJson($url.'/status', ['is_active' => false, 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->putJson($url, $this->slotPayload())->assertConflict();
        $this->patchJson($url.'/status', ['is_active' => true, 'version' => 1])->assertConflict();
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $this->assertDatabaseHas('amenity_time_slots', ['id' => $this->slotId, 'is_active' => 0, 'version' => 2]);
        $this->putJson($url, $this->slotPayload(2))->assertOk()->assertJsonPath('version', 3);
        $this->getJson($this->base().'/time-slots')->assertOk()->assertJsonPath('0.version', 3);
        $this->deleteJson($url, ['version' => 3])->assertOk();
    }

    public function test_stale_blackout_edit_or_delete_does_not_cancel_an_existing_booking(): void
    {
        $created = $this->postJson($this->base().'/blackouts', ['blackout_date' => today()->addDays(5)->toDateString(), 'reason' => 'Bảo trì QA'])->assertCreated()->assertJsonPath('version', 1)->json();
        $url = $this->base().'/blackouts/'.$created['id'];
        $this->putJson($url, ['version' => 1, 'blackout_date' => today()->addDays(6)->toDateString(), 'reason' => 'Lịch mới'])->assertOk()->assertJsonPath('version', 2);
        $this->withToken($this->residentToken);
        $booking = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->withToken($this->adminToken);
        $this->putJson($url, ['version' => 1, 'blackout_date' => $this->bookingDate, 'reason' => 'Bản cũ'])->assertConflict();
        $this->deleteJson($url, ['version' => 1])->assertConflict();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $booking, 'status' => 'PENDING']);
        $this->assertDatabaseHas('amenity_blackouts', ['id' => $created['id'], 'reason' => 'Lịch mới', 'version' => 2]);
        $this->getJson($this->base().'/blackouts')->assertOk()->assertJsonPath('0.version', 2);
        $this->deleteJson($url, ['version' => 2])->assertOk();
    }

    public function test_versions_are_required_and_must_be_positive_integers(): void
    {
        $url = $this->base().'/time-slots/'.$this->slotId;
        foreach ([null, 0, -1, 'invalid'] as $version) {
            $this->putJson($url, [...$this->slotPayload(), 'version' => $version])->assertUnprocessable()->assertJsonValidationErrors('version');
            $this->deleteJson($url, ['version' => $version])->assertUnprocessable()->assertJsonValidationErrors('version');
        }
        $this->assertDatabaseHas('amenity_time_slots', ['id' => $this->slotId, 'version' => 1]);
    }

    public function test_version_checks_do_not_bypass_access_to_configuration(): void
    {
        $this->withToken($this->residentToken)->putJson('/api/v1/admin/amenity-categories/'.$this->categoryId, ['version' => 1, 'category_name' => 'Không được sửa', 'category_code' => 'FORBIDDEN'])->assertForbidden();
        $this->withToken($this->adminToken)->deleteJson($this->base().'/time-slots/'.Str::uuid(), ['version' => 1])->assertNotFound();
    }
}
