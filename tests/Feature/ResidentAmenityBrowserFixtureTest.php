<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAmenityBrowserFixtureTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('RESIDENT_BROWSER_FIXTURE')) {
            $this->markTestSkipped('Set RESIDENT_BROWSER_FIXTURE for the live resident browser test.');
        }
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
    }

    public function test_prepare_browser_fixture(): void
    {
        $path = getenv('RESIDENT_BROWSER_FIXTURE');
        $this->assertFileDoesNotExist($path);
        $this->createBookingFixture();
        DB::table('amenities')->where('id', $this->amenityId)->update(['amenity_name' => 'Browser QA BBQ', 'min_cancel_hours_before' => 0]);
        $fixture = [
            'token' => $this->residentToken, 'second_token' => $this->secondToken, 'admin_token' => $this->adminToken,
            'user_ids' => [$this->residentUser->id, $this->secondUser->id, $this->adminUser->id],
            'amenity_id' => $this->amenityId, 'block_id' => $this->blockId,
            'category_id' => $this->categoryId, 'apartment_id' => $this->apartmentId, 'slot_id' => $this->slotId, 'date' => $this->bookingDate,
        ];
        $this->assertNotFalse(file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR)));
    }

    public function test_verify_and_cleanup_browser_fixture(): void
    {
        $path = getenv('RESIDENT_BROWSER_FIXTURE');
        $this->assertFileExists($path);
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertDatabaseHas('amenities', ['id' => $fixture['amenity_id'], 'amenity_name' => 'Browser QA BBQ']);
        try {
            $bookings = DB::table('amenity_bookings')->where('amenity_id', $fixture['amenity_id'])->get();
            $this->assertCount(1, $bookings);
            $booking = $bookings->first();
            $this->assertSame('CANCELLED', $booking->status);
            $this->assertSame($fixture['user_ids'][0], $booking->resident_user_id);
            $this->assertSame(150000.0, (float) $booking->total_amount);
            $this->assertSame(50000.0, (float) $booking->deposit_amount);
            $this->assertSame(2, (int) $booking->attendee_count);
            $this->assertSame(1, (int) $booking->is_paid);
            $this->assertDatabaseHas('amenity_booking_payments', [
                'booking_id' => $booking->id, 'status' => 'REVIEW', 'amount' => 200000,
                'received_amount' => 200000, 'refund_required' => 1,
                'confirmed_by_user_id' => $fixture['user_ids'][2], 'bank_transaction_id' => 'QA-'.strtoupper($booking->id),
            ]);
            $this->assertDatabaseHas('audit_logs', ['record_id' => $booking->id, 'action' => 'INSERT']);
            $this->assertDatabaseHas('audit_logs', ['record_id' => $booking->id, 'action' => 'UPDATE']);
        } finally {
            DB::table('user_in_app_notifications')->where('category', 'AMENITY_BOOKING')->where('deep_link_url', 'like', '%'.$fixture['amenity_id'].'%')->delete();
            DB::table('amenity_booking_payments')->whereIn('booking_id', DB::table('amenity_bookings')->where('amenity_id', $fixture['amenity_id'])->select('id'))->delete();
            DB::table('amenity_bookings')->where('amenity_id', $fixture['amenity_id'])->delete();
            DB::table('amenity_time_slots')->where('amenity_id', $fixture['amenity_id'])->delete();
            DB::table('amenities')->where('id', $fixture['amenity_id'])->delete();
            DB::table('amenity_categories')->where('id', $fixture['category_id'])->delete();
            DB::table('audit_logs')->whereIn('performed_by_user_id', $fixture['user_ids'])->delete();
            DB::table('user_in_app_notifications')->whereIn('recipient_user_id', $fixture['user_ids'])->delete();
            foreach (['residents', 'user_sessions', 'user_roles'] as $table) {
                DB::table($table)->whereIn('user_id', $fixture['user_ids'])->delete();
            }
            DB::table('apartments')->where('block_id', $fixture['block_id'])->delete();
            DB::table('floors')->where('block_id', $fixture['block_id'])->delete();
            DB::table('blocks')->where('id', $fixture['block_id'])->delete();
            DB::table('users')->whereIn('id', $fixture['user_ids'])->delete();
            unlink($path);
        }
    }
}
