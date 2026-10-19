<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityBookingNotificationTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->createBookingFixture();
    }

    public function test_creation_notifies_authorized_active_managers_with_exact_booking_link(): void
    {
        $manager = User::factory()->create();
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $roleId, 'role_code' => 'TEST_'.Str::random(8), 'role_name' => 'Amenity manager']);
        $permissionId = DB::table('permissions')->where('permission_code', 'AMENITY:UPDATE')->value('id');
        DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'permission_id' => $permissionId]);
        foreach ([$manager, $this->secondUser] as $user) {
            DB::table('user_roles')->insert(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'user_id' => $user->id]);
        }
        $this->secondUser->update(['status' => 'INACTIVE']);
        $booking = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');
        $rows = DB::table('user_in_app_notifications')->where('category', 'AMENITY_BOOKING')->where('deep_link_url', 'like', '%'.$booking['booking_code'].'%')->get();
        $this->assertContains($manager->id, $rows->pluck('recipient_user_id')->all());
        $this->assertContains($this->adminUser->id, $rows->pluck('recipient_user_id')->all());
        $this->assertNotContains($this->residentUser->id, $rows->pluck('recipient_user_id')->all());
        $this->assertNotContains($this->secondUser->id, $rows->pluck('recipient_user_id')->all());
        $row = $rows->firstWhere('recipient_user_id', $manager->id);
        $this->assertStringContainsString($this->amenityId, $row->deep_link_url);
        $this->assertStringContainsString('Test BBQ', $row->title);
        $this->assertStringContainsString('TEST-101', $row->body_message);
        $count = $rows->count();
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
        $this->assertSame($count, DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$booking['booking_code'].'%')->count());
    }

    public function test_feed_is_private_read_is_idempotent_and_authentication_is_required(): void
    {
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
        $url = '/api/v1/admin/amenity-booking-notifications';
        $this->getJson($url)->assertForbidden();
        $this->withHeader('Authorization', '');
        $this->getJson($url)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $response = $this->getJson($url)->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'items');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $id = $response->json('items.0.id');
        $this->postJson($url.'/'.$id.'/read')->assertOk();
        $this->postJson($url.'/'.$id.'/read')->assertOk();
        $this->getJson($url)->assertJsonPath('unread_count', 0)->assertJsonPath('items.0.isRead', true);
        DB::table('user_in_app_notifications')->where('id', $id)->update(['recipient_user_id' => $this->residentUser->id]);
        $this->postJson($url.'/'.$id.'/read')->assertNotFound();
        $this->getJson($url)->assertJsonCount(0, 'items');
    }

    public function test_payment_report_notifies_managers_once_without_marking_paid(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $url = '/api/v1/resident/amenity-bookings/'.$id.'/payment/report';
        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertOk();
        $this->assertSame(1, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->adminUser->id)->where('title', 'like', 'Cư dân báo chuyển khoản%')->count());
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0]);
    }

    public function test_old_unread_notifications_are_accessible_by_pagination_and_filter(): void
    {
        $oldest = null;
        for ($i = 0; $i < 21; $i++) {
            $id = (string) Str::uuid();
            $oldest ??= $id;
            DB::table('user_in_app_notifications')->insert(['id' => $id, 'recipient_user_id' => $this->adminUser->id,
                'title' => 'QA '.$i, 'body_message' => 'QA', 'category' => 'AMENITY_BOOKING', 'is_read' => $i === 0 ? 0 : 1, 'created_at' => now()->subMinutes(21 - $i)]);
        }
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $url = '/api/v1/admin/amenity-booking-notifications';
        $this->getJson($url)->assertJsonCount(20, 'items')->assertJsonPath('total_pages', 2)->assertJsonPath('latest_unread.id', $oldest);
        $this->getJson($url.'?page=2')->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $oldest);
        $this->getJson($url.'?unread=1')->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $oldest);
        $this->getJson($url.'?page=0')->assertUnprocessable();
    }

    public function test_free_approval_and_cancellation_notify_resident_with_owned_booking_link(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['hourly_rate' => 0, 'security_deposit_required' => 0]);
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $url = '/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status';
        $this->patchJson($url, ['status' => 'APPROVED'])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $feed = '/api/v1/resident/amenity-notifications';
        $row = $this->getJson($feed)->assertJsonCount(1, 'items')->assertJsonPath('unread_count', 1)->json('items.0');
        $this->assertStringContainsString('booking_id='.$id, $row['deepLink']);
        $this->postJson($feed.'/'.$row['id'].'/read')->assertOk();
        $this->getJson($feed)->assertJsonPath('unread_count', 0);
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $this->getJson($feed)->assertJsonCount(0, 'items');
        $this->postJson($feed.'/'.$row['id'].'/read')->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson($url, ['status' => 'CANCELLED', 'admin_notes' => 'Lịch thay đổi'])->assertOk();
        $this->assertSame(2, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->count());
    }

    public function test_worklist_groups_all_amenities_and_requires_management_permission(): void
    {
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $url = '/api/v1/admin/amenity-booking-worklist';
        $counts = [
            'PENDING' => DB::table('amenity_bookings')->whereNull('deleted_at')->where('status', 'PENDING')->count(),
            'REPORTED' => DB::table('amenity_booking_payments')->where('status', 'REPORTED')->count(),
            'REVIEW' => DB::table('amenity_booking_payments')->where('status', 'REVIEW')->count(),
        ];
        $this->getJson($url)->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $pending = $this->getJson($url)->assertJsonPath('counts.PENDING', $counts['PENDING'])->json('items');
        $this->assertContains($id, array_column($pending, 'id'));
        $this->patchJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status', ['status' => 'APPROVED'])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/payment/report')->assertOk();
        $this->travel(31)->minutes();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $reported = $this->getJson($url.'?bucket=REPORTED')->assertJsonPath('counts.REPORTED', $counts['REPORTED'] + 1)->json('items');
        $this->assertTrue(collect($reported)->firstWhere('id', $id)['review_overdue']);
        $this->postJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/payment/reject', ['reason' => 'Không nhận được tiền'])->assertOk();
        $review = $this->getJson($url.'?bucket=REVIEW')->assertJsonPath('counts.REVIEW', $counts['REVIEW'] + 1)->json('items');
        $this->assertContains($id, array_column($review, 'id'));
    }
}
