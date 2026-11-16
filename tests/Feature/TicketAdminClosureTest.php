<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\TicketAccessService;
use App\Services\TicketManagementService;
use App\Services\TicketNotificationService;
use Database\Seeders\TicketPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class TicketAdminClosureTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        $category = TicketCategory::create(['category_code' => 'CLOSE-'.Str::random(8), 'category_name' => 'QA Closure', 'default_priority' => 'MEDIUM']);
        $this->ticket = Ticket::create(['ticket_number' => 'TCK-QA-'.Str::random(12), 'category_id' => $category->id, 'creator_user_id' => $this->residentUser->id, 'apartment_id' => $this->apartmentId, 'title' => 'QA đóng yêu cầu', 'description' => 'QA kiểm tra đóng thay cư dân', 'location' => 'QA', 'priority' => 'MEDIUM', 'status' => 'RESOLVED', 'resolved_at' => now(), 'version' => 1]);
    }

    private function url(): string
    {
        return '/api/v1/admin/tickets/'.$this->ticket->id.'/workflow';
    }

    /** @return array{action: string, version: int, reason: string} */
    private function payload(): array
    {
        return ['action' => 'close', 'version' => 1, 'reason' => '  Cư dân đề nghị quản trị đóng thay.  '];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['role_code' => $role], ['role_name' => 'QA '.$role]);
        $user->assignRole($role);
        (new TicketPermissionsSeeder)->run();
        $department = (string) Str::uuid();
        DB::table('departments')->insert(['id' => $department, 'department_code' => 'CLOSE-'.Str::random(8), 'department_name' => 'QA']);
        DB::table('staff_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'department_id' => $department, 'employee_code' => 'CLOSE-'.Str::random(8), 'job_title' => 'QA', 'hire_date' => today(), 'assigned_block_id' => $this->blockId]);

        return $user;
    }

    public function test_admin_closes_with_true_actor_and_notifies_current_recipients(): void
    {
        $manager = $this->staff('BUILDING_MANAGER');
        $technician = $this->staff('TECHNICIAN');
        $this->ticket->update(['coordinator_user_id' => $manager->id, 'current_technician_user_id' => $technician->id]);
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('actions.close', true);
        $this->postJson($this->url(), $this->payload())->assertOk()->assertJsonPath('status', 'CLOSED')->assertJsonPath('version', 2)->assertJsonPath('actions.close', false);
        $this->assertNotNull($this->ticket->fresh()->closed_at);
        $this->assertDatabaseHas('ticket_history', ['ticket_id' => $this->ticket->id, 'performed_by_user_id' => $this->adminUser->id, 'old_status' => 'RESOLVED', 'new_status' => 'CLOSED', 'notes' => 'Admin đóng thay cư dân: Cư dân đề nghị quản trị đóng thay.']);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'tickets', 'record_id' => $this->ticket->id, 'performed_by_user_id' => $this->adminUser->id]);
        $notifications = DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->get();
        $this->assertEqualsCanonicalizing([$this->residentUser->id, $manager->id, $technician->id], $notifications->pluck('recipient_user_id')->all());
        foreach ($notifications as $notification) {
            $this->assertStringStartsWith('Admin đã đóng yêu cầu thay cư dân', $notification->title);
        }
        $this->assertDatabaseMissing('ticket_ratings', ['ticket_id' => $this->ticket->id]);
        $this->postJson($this->url(), $this->payload())->assertConflict();
        $this->assertSame(1, DB::table('ticket_history')->where('ticket_id', $this->ticket->id)->count());
        $this->assertSame(3, DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->count());
    }

    public function test_non_super_admin_roles_and_resident_route_cannot_close(): void
    {
        $manager = $this->staff('BUILDING_MANAGER');
        $technician = $this->staff('TECHNICIAN');
        $this->ticket->update(['current_technician_user_id' => $technician->id]);
        foreach ([$manager, $technician] as $user) {
            $this->withToken($this->sessionToken($user))->getJson('/api/v1/admin/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('actions.close', false);
            $this->postJson($this->url(), $this->payload())->assertForbidden();
        }
        $this->withToken($this->residentToken)->postJson($this->url(), $this->payload())->assertForbidden();
        $this->assertSame('RESOLVED', $this->ticket->fresh()->status);
        $this->assertDatabaseMissing('ticket_history', ['ticket_id' => $this->ticket->id]);
    }

    public function test_invalid_states_versions_and_archived_tickets_cannot_close(): void
    {
        foreach (['NEW', 'RECEIVED', 'IN_PROGRESS', 'CLOSED', 'CANCELLED'] as $state) {
            $this->ticket->update(['status' => $state]);
            $this->withToken($this->adminToken)->postJson($this->url(), $this->payload())->assertConflict();
        }
        $this->ticket->update(['status' => 'RESOLVED', 'version' => 2]);
        $this->postJson($this->url(), $this->payload())->assertConflict();
        $this->ticket->delete();
        $this->postJson($this->url(), $this->payload())->assertNotFound();
    }

    public function test_reason_is_required_and_limited_after_trimming(): void
    {
        foreach ([null, '', '    ', '  abcd  ', str_repeat('a', 501)] as $reason) {
            $payload = $this->payload();
            $payload['reason'] = $reason;
            $this->withToken($this->adminToken)->postJson($this->url(), $payload)->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->assertSame(1, $this->ticket->fresh()->version);
    }

    public function test_sender_is_excluded_and_recipients_are_deduplicated(): void
    {
        $this->ticket->update(['coordinator_user_id' => $this->adminUser->id, 'current_technician_user_id' => $this->adminUser->id]);
        $this->withToken($this->adminToken)->postJson($this->url(), $this->payload())->assertOk();
        $this->assertSame([$this->residentUser->id], DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->pluck('recipient_user_id')->all());
    }

    public function test_audit_failure_rolls_back_status_history_and_notifications(): void
    {
        $real = app(TicketManagementService::class);
        $mock = Mockery::mock(TicketManagementService::class, [app(TicketAccessService::class)])->makePartial();
        $mock->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) use ($real): void {
            $real->record(...$arguments);
            throw new RuntimeException('QA audit failure');
        });
        $this->app->instance(TicketManagementService::class, $mock);
        $this->withToken($this->adminToken)->postJson($this->url(), $this->payload())->assertStatus(500);
        $this->assertSame('RESOLVED', $this->ticket->fresh()->status);
        $this->assertSame(1, $this->ticket->fresh()->version);
        $this->assertDatabaseMissing('ticket_history', ['ticket_id' => $this->ticket->id]);
        $this->assertDatabaseMissing('audit_logs', ['record_id' => $this->ticket->id]);
    }

    public function test_notification_failure_rolls_back_every_write(): void
    {
        $real = app(TicketNotificationService::class);
        $mock = Mockery::mock(TicketNotificationService::class)->makePartial();
        $mock->shouldReceive('changed')->once()->andReturnUsing(function (...$arguments) use ($real): void {
            $real->changed(...$arguments);
            throw new RuntimeException('QA notification failure');
        });
        $this->app->instance(TicketNotificationService::class, $mock);
        $this->withToken($this->adminToken)->postJson($this->url(), $this->payload())->assertStatus(500);
        $this->assertSame('RESOLVED', $this->ticket->fresh()->status);
        $this->assertSame(1, $this->ticket->fresh()->version);
        $this->assertDatabaseMissing('ticket_history', ['ticket_id' => $this->ticket->id]);
        $this->assertDatabaseMissing('audit_logs', ['record_id' => $this->ticket->id]);
        $this->assertSame(0, DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->count());
    }
}
