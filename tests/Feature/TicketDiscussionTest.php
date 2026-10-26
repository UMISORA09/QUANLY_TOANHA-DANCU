<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\TicketNotificationService;
use Database\Seeders\TicketPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class TicketDiscussionTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        $category = TicketCategory::create(['category_code' => 'CHAT-'.Str::random(8), 'category_name' => 'QA Trao đổi', 'default_priority' => 'MEDIUM']);
        $this->ticket = Ticket::create(['ticket_number' => 'TCK-QA-'.Str::random(10), 'category_id' => $category->id, 'creator_user_id' => $this->residentUser->id, 'apartment_id' => $this->apartmentId, 'title' => 'QA sửa vòi nước', 'description' => 'QA cần kiểm tra vòi nước', 'location' => 'Phòng bếp', 'priority' => 'MEDIUM', 'status' => 'NEW', 'version' => 1]);
    }

    private function url(string $suffix = 'comments'): string
    {
        return '/api/v1/tickets/'.$this->ticket->id.'/'.$suffix;
    }

    /** @return array{id: string, comment_text: string, is_internal_staff_note: bool} */
    private function payload(bool $internal = false): array
    {
        return ['id' => (string) Str::uuid(), 'comment_text' => "Cần kiểm tra đường nước.\nCảm ơn.", 'is_internal_staff_note' => $internal];
    }

    private function employee(string $role, ?string $block = null, bool $profile = true): User
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['role_code' => $role], ['role_name' => 'QA '.$role]);
        $user->assignRole($role);
        (new TicketPermissionsSeeder)->run();
        if ($profile) {
            $department = (string) Str::uuid();
            DB::table('departments')->insert(['id' => $department, 'department_code' => 'CHAT-'.Str::random(8), 'department_name' => 'QA Technical']);
            DB::table('staff_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'department_id' => $department, 'employee_code' => 'CHAT-'.Str::random(8), 'job_title' => 'QA', 'hire_date' => today(), 'assigned_block_id' => $block]);
        }

        return $user;
    }

    public function test_strict_auth_revalidates_missing_expired_revoked_demo_and_locked_sessions(): void
    {
        $this->withHeader('Authorization', '')->getJson($this->url())->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer smart_token_admin_demo')->postJson($this->url(), $this->payload())->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['expires_at' => now()->subMinute()]);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->getJson($this->url())->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['expires_at' => now()->addDay(), 'is_revoked' => 1]);
        $this->getJson($this->url())->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 0]);
        $this->residentUser->update(['status' => 'LOCKED']);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_post_retries_are_idempotent_and_preserve_workflow_version(): void
    {
        $payload = $this->payload();
        $this->postJson($this->url(), [...$payload, 'sender_user_id' => $this->adminUser->id, 'created_at' => '2000-01-01'])->assertCreated()->assertJsonPath('data.sender.id', $this->residentUser->id)->assertJsonMissingPath('data.sender.email');
        $this->postJson($this->url(), $payload)->assertOk()->assertJsonPath('data.id', $payload['id']);
        $this->assertSame(1, DB::table('ticket_comments')->where('ticket_id', $this->ticket->id)->count());
        $this->assertSame(1, $this->ticket->fresh()->version);
        $this->assertSame(0, DB::table('ticket_history')->where('ticket_id', $this->ticket->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('record_id', $payload['id'])->count());
        $this->assertSame(1, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->adminUser->id)->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->count());
        $this->postJson($this->url(), [...$payload, 'comment_text' => 'Nội dung đã thay đổi'])->assertConflict();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken)->postJson($this->url(), $payload)->assertConflict();
    }

    public function test_validation_and_plain_text_storage(): void
    {
        $this->postJson($this->url(), ['id' => 'invalid', 'comment_text' => '   '])->assertUnprocessable()->assertJsonValidationErrors(['id', 'comment_text']);
        $this->postJson($this->url(), [...$this->payload(), 'comment_text' => str_repeat('Đ', 2001)])->assertUnprocessable()->assertJsonValidationErrors('comment_text');
        $text = "<script>alert('qa')</script>\nNội dung thuần văn bản.";
        $this->postJson($this->url(), [...$this->payload(), 'comment_text' => $text])->assertCreated()->assertJsonPath('data.comment_text', $text);
        $this->getJson($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'items');
    }

    public function test_internal_notes_never_leak_to_resident_items_cursors_metadata_or_notifications(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $before = $this->getJson($this->url())->assertOk()->json();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->postJson($this->url(), [...$this->payload(true), 'comment_text' => 'QA thông tin nội bộ riêng tư'])->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('meta.can_internal_note', true);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->getJson($this->url())->assertOk()->assertExactJson($before);
        $this->postJson($this->url(), $this->payload(true))->assertForbidden();
        $this->assertSame(0, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->count());
        $this->getJson($this->url('history'))->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_only_current_residents_and_in_scope_staff_may_post(): void
    {
        $outsider = User::factory()->create();
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($outsider))->getJson($this->url())->assertForbidden();
        $this->getJson($this->url('history'))->assertForbidden();
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        Resident::where('user_id', $this->residentUser->id)->update(['stay_end_date' => today()->subDay()]);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->getJson($this->url())->assertOk()->assertJsonPath('meta.can_comment', false);
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $manager = $this->employee('BUILDING_MANAGER', $this->blockId, false);
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($manager))->getJson($this->url())->assertForbidden();
        $manager = $this->employee('BUILDING_MANAGER', $this->blockId);
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($manager))->postJson($this->url(), $this->payload())->assertCreated();
        $otherBlock = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $otherBlock, 'block_code' => 'CHAT-'.Str::random(8), 'block_name' => 'QA ngoài phạm vi']);
        DB::table('staff_profiles')->where('user_id', $manager->id)->update(['assigned_block_id' => $otherBlock]);
        $this->getJson($this->url())->assertNotFound();
        $this->postJson($this->url(), $this->payload())->assertNotFound();
    }

    public function test_technician_must_be_currently_assigned(): void
    {
        $tech = $this->employee('TECHNICIAN', $this->blockId);
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($tech))->getJson($this->url())->assertNotFound();
        $this->ticket->update(['current_technician_user_id' => $tech->id]);
        $this->postJson($this->url(), $this->payload(true))->assertCreated();
        $this->ticket->update(['current_technician_user_id' => null]);
        $this->getJson($this->url())->assertNotFound();
        $this->postJson($this->url(), $this->payload())->assertNotFound();
    }

    public function test_closed_cancelled_and_archived_tickets_reject_new_comments(): void
    {
        $payload = $this->payload();
        $this->postJson($this->url(), $payload)->assertCreated();
        foreach (['CLOSED', 'CANCELLED'] as $status) {
            $this->ticket->update(['status' => $status]);
            $this->getJson($this->url())->assertOk()->assertJsonPath('meta.can_comment', false)->assertJsonCount(1, 'items');
            $this->postJson($this->url(), $this->payload())->assertConflict();
            $this->postJson($this->url(), $payload)->assertOk();
        }
        $this->ticket->delete();
        $this->getJson($this->url())->assertNotFound();
        $this->getJson($this->url('history'))->assertNotFound();
        $this->postJson($this->url(), $this->payload())->assertNotFound();
    }

    public function test_stable_cursor_pagination_and_history_without_account_data(): void
    {
        $ids = [];
        for ($i = 0; $i < 35; $i++) {
            $ids[] = $id = (string) Str::uuid();
            DB::table('ticket_comments')->insert(['id' => $id, 'ticket_id' => $this->ticket->id, 'sender_user_id' => $this->residentUser->id, 'comment_text' => 'QA '.$i, 'created_at' => now()]);
            DB::table('ticket_history')->insert(['id' => $id, 'ticket_id' => $this->ticket->id, 'action_type' => 'UPDATED', 'performed_by_user_id' => $this->adminUser->id, 'created_at' => now()]);
        }
        foreach (['comments', 'history'] as $suffix) {
            $seen = [];
            $cursor = null;
            do {
                $page = $this->getJson($this->url($suffix).($cursor ? '?cursor='.urlencode($cursor) : ''))->assertOk()->json();
                $this->assertLessThanOrEqual(15, count($page['items']));
                $seen = [...$seen, ...array_column($page['items'], 'id')];
                $cursor = $page['next_cursor'];
                $this->assertStringNotContainsString('email', json_encode($page));
            } while ($cursor);
            $this->assertCount(35, array_unique($seen));
            $this->assertEqualsCanonicalizing($ids, $seen);
        }
        $this->getJson($this->url().'?cursor=invalid')->assertUnprocessable();
        $this->getJson($this->url().'?cursor='.base64_encode('{"_pointsToNextItems":true}'))->assertUnprocessable();
    }

    public function test_database_limiter_does_not_block_successful_retries(): void
    {
        $payload = $this->payload();
        $this->postJson($this->url(), $payload)->assertCreated();
        for ($i = 0; $i < 29; $i++) {
            DB::table('ticket_comments')->insert(['id' => (string) Str::uuid(), 'ticket_id' => $this->ticket->id, 'sender_user_id' => $this->residentUser->id, 'comment_text' => 'QA '.$i, 'created_at' => now()]);
        }
        $this->postJson($this->url(), $this->payload())->assertStatus(429);
        $other = $this->ticket->replicate();
        $other->ticket_number .= '-TWO';
        $other->save();
        $this->postJson('/api/v1/tickets/'.$other->id.'/comments', $this->payload())->assertStatus(429);
        $this->postJson($this->url(), $payload)->assertOk();
        $this->assertSame(30, DB::table('ticket_comments')->where('ticket_id', $this->ticket->id)->count());
    }

    public function test_inclusive_delta_finds_more_than_one_page_of_same_second_comments(): void
    {
        $this->travelTo(now()->startOfSecond());
        $ids = [];
        for ($i = 0; $i < 32; $i++) {
            $ids[] = $id = (string) Str::uuid();
            DB::table('ticket_comments')->insert(['id' => $id, 'ticket_id' => $this->ticket->id, 'sender_user_id' => $this->residentUser->id, 'comment_text' => 'Cùng giây '.$i, 'created_at' => now()]);
        }
        $seen = [];
        $cursor = null;
        do {
            $query = http_build_query(['since' => now()->toIso8601String(), 'cursor' => $cursor]);
            $page = $this->getJson($this->url().'?'.$query)->assertOk()->json();
            $seen = [...$seen, ...array_column($page['items'], 'id')];
            $cursor = $page['next_cursor'];
        } while ($cursor);
        $this->assertEqualsCanonicalizing($ids, $seen);
        $this->getJson($this->url().'?since='.urlencode(now()->addSecond()->toIso8601String()))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->url().'?since=invalid')->assertUnprocessable();
    }

    public function test_global_manager_and_read_only_technician_permissions_are_enforced(): void
    {
        $manager = $this->employee('BUILDING_MANAGER');
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($manager))->postJson($this->url(), $this->payload(true))->assertCreated();
        $tech = $this->employee('TECHNICIAN', $this->blockId);
        $this->ticket->update(['current_technician_user_id' => $tech->id]);
        $role = Role::where('role_code', 'TECHNICIAN')->firstOrFail();
        $permission = DB::table('permissions')->where('permission_code', 'TICKET:UPDATE')->value('id');
        DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permission)->delete();
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($tech))->getJson($this->url())->assertOk()->assertJsonPath('meta.can_comment', false)->assertJsonPath('meta.can_view_internal', true);
        $this->postJson($this->url(), $this->payload())->assertForbidden();
    }

    public function test_audit_failure_rolls_back_comment_before_notifications(): void
    {
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into `audit_logs`')) {
                throw new \RuntimeException('Injected QA audit failure');
            }
        });
        $payload = $this->payload();
        $this->postJson($this->url(), $payload)->assertServerError();
        $this->assertDatabaseMissing('ticket_comments', ['id' => $payload['id']]);
        $this->assertDatabaseMissing('audit_logs', ['record_id' => $payload['id']]);
        $this->assertSame(0, DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->count());
    }

    public function test_notification_failure_rolls_back_comment_audit_and_notification(): void
    {
        $mock = Mockery::mock(TicketNotificationService::class);
        $mock->shouldReceive('commented')->once()->andReturnUsing(function (): void {
            DB::table('user_in_app_notifications')->insert(['id' => (string) Str::uuid(), 'recipient_user_id' => $this->adminUser->id, 'title' => 'QA rollback', 'body_message' => 'QA', 'category' => 'TICKET', 'is_read' => 0, 'created_at' => now()]);
            throw new \RuntimeException('Injected QA notification failure');
        });
        $this->app->instance(TicketNotificationService::class, $mock);
        $payload = $this->payload();
        $this->postJson($this->url(), $payload)->assertServerError();
        $this->assertDatabaseMissing('ticket_comments', ['id' => $payload['id']]);
        $this->assertDatabaseMissing('audit_logs', ['record_id' => $payload['id']]);
        $this->assertDatabaseMissing('user_in_app_notifications', ['title' => 'QA rollback']);
    }

    public function test_notifications_target_current_participants_without_internal_text(): void
    {
        $tech = $this->employee('TECHNICIAN', $this->blockId);
        $this->ticket->update(['coordinator_user_id' => $this->adminUser->id, 'current_technician_user_id' => $tech->id]);
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $notices = DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->get();
        $this->assertEqualsCanonicalizing([$tech->id, $this->adminUser->id], $notices->pluck('recipient_user_id')->all());
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($tech))->postJson($this->url(), [...$this->payload(true), 'comment_text' => 'Nội dung bí mật QA'])->assertCreated();
        $this->assertSame(0, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->count());
        $this->assertSame(0, DB::table('user_in_app_notifications')->where('body_message', 'like', '%bí mật%')->count());
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->assertDatabaseHas('user_in_app_notifications', ['recipient_user_id' => $this->residentUser->id, 'deep_link_url' => '/cu-dan?tab=tickets&ticket_id='.$this->ticket->id]);
    }
}
