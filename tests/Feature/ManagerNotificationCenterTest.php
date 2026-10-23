<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\ResidentAmenityBookingService;
use Database\Seeders\TicketPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ManagerNotificationCenterTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
    }

    private function staff(string $role, ?string $block, bool $profile = true): User
    {
        Role::firstOrCreate(['role_code' => $role], ['role_name' => $role]);
        (new TicketPermissionsSeeder)->run();
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $user->assignRole($role);
        if ($profile) {
            $department = (string) Str::uuid();
            DB::table('departments')->insert(['id' => $department, 'department_code' => Str::random(12), 'department_name' => 'Notification QA']);
            DB::table('staff_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'department_id' => $department, 'employee_code' => Str::random(12), 'job_title' => $role, 'hire_date' => today(), 'assigned_block_id' => $block]);
        }

        return $user;
    }

    private function ticket(): string
    {
        $category = TicketCategory::create(['category_code' => Str::random(12), 'category_name' => 'Notification QA', 'default_priority' => 'HIGH', 'sla_response_time_minutes' => 30, 'sla_resolution_time_hours' => 24]);

        return $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->postJson('/api/v1/resident/tickets', ['title' => 'Kiểm thử thông báo', 'description' => 'Yêu cầu QA riêng biệt.', 'location' => 'Bếp', 'apartment_id' => $this->apartmentId, 'category_id' => $category->id])->assertCreated()->json('data.id');
    }

    public function test_real_sources_have_global_pagination_and_unread_count(): void
    {
        $this->ticket();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated();
        $url = '/api/v1/manager/notifications';
        $rows = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->getJson($url)->assertOk()->assertJsonPath('unread_count', 2)->assertJsonCount(2, 'items')->json('items');
        $this->assertEqualsCanonicalizing(['TICKET', 'AMENITY_BOOKING'], array_column($rows, 'category'));
        $template = (array) DB::table('user_in_app_notifications')->where('id', $rows[0]['id'])->first();
        for ($i = 0; $i < 16; $i++) {
            DB::table('user_in_app_notifications')->insert(array_merge($template, ['id' => (string) Str::uuid(), 'created_at' => now()->addSeconds($i + 1)]));
        }
        $first = $this->getJson($url)->assertJsonCount(15, 'items')->assertJsonPath('unread_count', 18)->assertJsonPath('total_pages', 2)->json('items');
        $second = $this->getJson($url.'?page=2')->assertJsonCount(3, 'items')->assertJsonPath('unread_count', 18)->json('items');
        $this->assertCount(18, array_unique(array_column(array_merge($first, $second), 'id')));
        $this->assertGreaterThanOrEqual($second[0]['created_at'], $first[0]['created_at']);
        $this->getJson($url.'?page=0')->assertUnprocessable();
        $this->getJson($url.'?category=invalid')->assertUnprocessable();
        $this->getJson($url.'?category=TICKET')->assertJsonPath('unread_count', 18);
    }

    public function test_read_is_private_idempotent_and_requires_staff_authentication(): void
    {
        $this->ticket();
        $url = '/api/v1/manager/notifications';
        $this->withHeader('Authorization', '')->getJson($url)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->getJson($url)->assertForbidden();
        $response = $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->getJson($url)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $id = $response->json('items.0.id');
        $this->postJson($url.'/'.$id.'/read', ['recipient_user_id' => $this->residentUser->id])->assertJsonPath('unread_count', 0);
        $readAt = DB::table('user_in_app_notifications')->where('id', $id)->value('read_at');
        $this->postJson($url.'/'.$id.'/read')->assertJsonPath('unread_count', 0);
        $this->assertEquals($readAt, DB::table('user_in_app_notifications')->where('id', $id)->value('read_at'));
        $other = $this->staff('SUPER_ADMIN', null, false);
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($other))->postJson($url.'/'.$id.'/read')->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->postJson('/api/v1/ticket-notifications/'.$id.'/read')->assertNotFound();
        DB::table('user_sessions')->where('refresh_token_hash', hash('sha256', $this->adminToken))->update(['is_revoked' => 1]);
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->getJson($url)->assertUnauthorized();
    }

    public function test_current_building_and_assignment_are_rechecked_for_old_notifications(): void
    {
        $manager = $this->staff('BUILDING_MANAGER', $this->blockId);
        $missing = $this->staff('BUILDING_MANAGER', null, false);
        $technician = $this->staff('TECHNICIAN', $this->blockId);
        $ticket = $this->ticket();
        $url = '/api/v1/manager/notifications';
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($missing))->getJson($url)->assertForbidden();
        $id = $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($manager))->getJson($url)->assertJsonCount(1, 'items')->json('items.0.id');
        $otherBlock = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $otherBlock, 'block_code' => Str::random(12), 'block_name' => 'Other QA']);
        DB::table('staff_profiles')->where('user_id', $manager->id)->update(['assigned_block_id' => $otherBlock]);
        $this->getJson($url)->assertJsonCount(0, 'items')->assertJsonPath('unread_count', 0);
        $this->postJson($url.'/'.$id.'/read')->assertNotFound();
        $this->getJson('/api/v1/ticket-notifications')->assertJsonCount(0, 'items');
        $this->postJson('/api/v1/ticket-notifications/'.$id.'/read')->assertNotFound();
        $row = (array) DB::table('user_in_app_notifications')->where('id', $id)->first();
        $row['id'] = (string) Str::uuid();
        $row['recipient_user_id'] = $technician->id;
        DB::table('user_in_app_notifications')->insert($row);
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($technician))->getJson($url)->assertJsonCount(0, 'items');
        DB::table('tickets')->where('id', $ticket)->update(['current_technician_user_id' => $technician->id]);
        $this->getJson($url)->assertJsonCount(1, 'items');
        DB::table('tickets')->where('id', $ticket)->update(['deleted_at' => now()]);
        $this->getJson($url)->assertJsonCount(0, 'items');
    }

    public function test_reopen_notifies_scoped_manager_and_transaction_rollback_removes_notifications(): void
    {
        $manager = $this->staff('BUILDING_MANAGER', $this->blockId);
        $technician = $this->staff('TECHNICIAN', $this->blockId);
        $backup = $this->staff('BUILDING_MANAGER', $this->blockId);
        $id = $this->ticket();
        DB::table('tickets')->where('id', $id)->update(['status' => 'RESOLVED', 'version' => 1, 'current_technician_user_id' => $technician->id, 'coordinator_user_id' => null]);
        $url = '/api/v1/resident/tickets/'.$id.'/reopen';
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->postJson($url, ['version' => 1, 'reason' => 'Vẫn chưa được khắc phục.'])->assertOk();
        $this->postJson($url, ['version' => 1, 'reason' => 'Vẫn chưa được khắc phục.'])->assertConflict();
        foreach ([$manager, $technician] as $user) {
            $this->assertSame(1, DB::table('user_in_app_notifications')->where('recipient_user_id', $user->id)->where('title', 'like', 'Cư dân phản hồi chưa đạt%')->count());
        }
        DB::table('tickets')->where('id', $id)->update(['status' => 'RESOLVED', 'version' => 3, 'coordinator_user_id' => $manager->id]);
        $this->postJson($url, ['version' => 3, 'reason' => 'Vẫn chưa được khắc phục.'])->assertOk();
        $this->assertSame(1, DB::table('user_in_app_notifications')->where('recipient_user_id', $backup->id)->where('title', 'like', 'Cư dân phản hồi chưa đạt%')->count());
        $manager->update(['status' => 'INACTIVE']);
        DB::table('tickets')->where('id', $id)->update(['status' => 'RESOLVED', 'version' => 5]);
        $this->postJson($url, ['version' => 5, 'reason' => 'Vẫn chưa được khắc phục.'])->assertOk();
        $this->assertSame(2, DB::table('user_in_app_notifications')->where('recipient_user_id', $backup->id)->where('title', 'like', 'Cư dân phản hồi chưa đạt%')->count());
        $before = DB::table('user_in_app_notifications')->count();
        DB::beginTransaction();
        app(ResidentAmenityBookingService::class)->notifyManagers($this->amenityId, 'QA-ROLLBACK', 'Rollback QA', 'Must not persist');
        DB::rollBack();
        $this->assertSame($before, DB::table('user_in_app_notifications')->count());
    }

    public function test_shared_amenity_notifications_follow_booking_apartment_scope(): void
    {
        $manager = $this->staff('BUILDING_MANAGER', $this->blockId);
        $role = Role::where('role_code', 'BUILDING_MANAGER')->firstOrFail();
        $permission = DB::table('permissions')->where('permission_code', 'AMENITY:UPDATE')->value('id');
        DB::table('role_permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_id' => $permission]);
        $otherBlock = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $otherBlock, 'block_code' => Str::random(12), 'block_name' => 'Other QA']);
        $other = $this->staff('BUILDING_MANAGER', $otherBlock);
        DB::table('amenities')->where('id', $this->amenityId)->update(['block_id' => null]);
        $booking = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');
        $query = DB::table('user_in_app_notifications')->where('category', 'AMENITY_BOOKING')->where('deep_link_url', 'like', '%'.$booking['booking_code'].'%');
        $this->assertContains($manager->id, (clone $query)->pluck('recipient_user_id')->all());
        $this->assertNotContains($other->id, (clone $query)->pluck('recipient_user_id')->all());
        $url = '/api/v1/manager/notifications';
        $id = $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($manager))->getJson($url)->assertJsonCount(1, 'items')->json('items.0.id');
        DB::table('staff_profiles')->where('user_id', $manager->id)->update(['assigned_block_id' => $otherBlock]);
        $this->getJson($url)->assertJsonCount(0, 'items');
        $this->postJson($url.'/'.$id.'/read')->assertNotFound();
        $this->getJson('/api/v1/admin/amenity-booking-notifications')->assertJsonCount(0, 'items');
        $this->postJson('/api/v1/admin/amenity-booking-notifications/'.$id.'/read')->assertNotFound();
    }
}
