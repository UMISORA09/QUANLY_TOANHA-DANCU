<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAmenityBookingConcurrencyTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('TEST_HTTP_BASE_URL')) {
            $this->markTestSkipped('Set TEST_HTTP_BASE_URL to a multi-worker test server using the same dedicated database.');
        }
        $this->createBookingFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->amenityId)) {
            DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->delete();
            DB::table('amenity_time_slots')->where('amenity_id', $this->amenityId)->delete();
            DB::table('amenities')->where('id', $this->amenityId)->delete();
            DB::table('amenity_categories')->where('id', $this->categoryId)->delete();
            $userIds = [$this->residentUser->id, $this->secondUser->id, $this->adminUser->id];
            DB::table('audit_logs')->whereIn('performed_by_user_id', $userIds)->delete();
            foreach (['residents', 'user_sessions', 'user_roles'] as $table) {
                DB::table($table)->whereIn('user_id', $userIds)->delete();
            }
            DB::table('apartments')->where('block_id', $this->blockId)->delete();
            DB::table('floors')->where('block_id', $this->blockId)->delete();
            DB::table('blocks')->where('id', $this->blockId)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        }
        parent::tearDown();
    }

    /** @param array<int, string> $tokens
     * @param  array<string, mixed>  $payload
     * @return array<int, int>
     */
    private function parallelRequests(array $tokens, array $payload, string $path = '/api/v1/resident/amenity-bookings', ?\Closure $beforeUnlock = null, string $method = 'POST'): array
    {
        $multi = curl_multi_init();
        $handles = [];
        DB::beginTransaction();
        DB::table('amenities')->where('id', $this->amenityId)->lockForUpdate()->first();
        try {
            foreach ($tokens as $token) {
                $handle = curl_init(rtrim(getenv('TEST_HTTP_BASE_URL'), '/').$path);
                curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = $handle;
            }
            curl_multi_exec($multi, $running);
            usleep(200000);
            curl_multi_exec($multi, $running);
            if ($beforeUnlock) {
                $beforeUnlock();
            }
            DB::commit();
            do {
                curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running);
            $statuses = [];
            foreach ($handles as $handle) {
                $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $this->assertContains($status, [200, 201, 409], curl_error($handle).' '.curl_multi_getcontent($handle));
                $statuses[] = $status;
            }
            sort($statuses);

            return $statuses;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }

    public function test_two_residents_compete_for_last_booking_without_overbooking(): void
    {
        DB::table('amenity_time_slots')->where('id', $this->slotId)->update(['max_bookings' => 1]);
        $this->assertSame([201, 409], $this->parallelRequests([$this->residentToken, $this->secondToken], $this->bookingPayload()));
        $this->assertSame(1, DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->count());
    }

    public function test_multiple_bookings_are_allowed_up_to_both_limits(): void
    {
        $this->assertSame([201, 201], $this->parallelRequests([$this->residentToken, $this->secondToken], $this->bookingPayload()));
        $this->assertSame(4, (int) DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->sum('attendee_count'));
    }

    public function test_total_attendees_are_enforced_under_contention(): void
    {
        $this->assertSame([201, 409], $this->parallelRequests([$this->residentToken, $this->secondToken], $this->bookingPayload(['attendee_count' => 3])));
    }

    public function test_current_slot_configuration_is_read_after_waiting_for_lock(): void
    {
        $statuses = $this->parallelRequests([$this->residentToken], $this->bookingPayload(), beforeUnlock: function (): void {
            DB::table('amenity_time_slots')->where('id', $this->slotId)->update(['is_active' => 0]);
        });
        $this->assertSame([409], $statuses);
    }

    public function test_concurrent_cancellation_succeeds_only_once(): void
    {
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->assertSame([200, 409], $this->parallelRequests([$this->residentToken, $this->residentToken], [], '/api/v1/resident/amenity-bookings/'.$id.'/cancel'));
    }

    public function test_concurrent_admin_updates_accept_only_one_timestamp_token(): void
    {
        $timestamp = DB::table('amenities')->where('id', $this->amenityId)->value('updated_at');
        $this->assertSame([200, 409], $this->parallelRequests([$this->adminToken, $this->adminToken], ['amenity_name' => 'Concurrent winner', 'updated_at' => $timestamp], '/api/v1/admin/amenities/'.$this->amenityId, method: 'PUT'));
        $this->assertSame(1, DB::table('audit_logs')->where('record_id', $this->amenityId)->count());
    }
}
