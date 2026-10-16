<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Floor;
use App\Models\Resident;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\TicketNotificationService;
use Database\Seeders\TicketPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentTicketTest extends TestCase
{
    use DatabaseTransactions;

    protected User $residentUser;

    protected User $otherResidentUser;

    protected string $residentToken;

    protected string $otherToken;

    protected string $apartmentId;

    protected string $otherApartmentId;

    protected string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['tickets.attachment_disk' => 'local']);

        // Setup users
        $this->residentUser = User::factory()->create(['status' => 'ACTIVE']);
        $this->otherResidentUser = User::factory()->create(['status' => 'ACTIVE']);

        $this->residentToken = $this->createSessionToken($this->residentUser);
        $this->otherToken = $this->createSessionToken($this->otherResidentUser);

        // Setup block, floor, apartments
        $blockId = (string) Str::uuid();
        DB::table('blocks')->insert([
            'id' => $blockId,
            'block_code' => 'BLK-'.Str::random(6),
            'block_name' => 'Tòa Tháp Ruby',
        ]);

        $floorId = (string) Str::uuid();
        DB::table('floors')->insert([
            'id' => $floorId,
            'block_id' => $blockId,
            'floor_number' => 8,
            'floor_code' => 'F08',
            'floor_name' => 'Tầng 08',
        ]);

        $this->apartmentId = (string) Str::uuid();
        DB::table('apartments')->insert([
            'id' => $this->apartmentId,
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'apartment_number' => 'RB-0801',
            'gross_floor_area_sqm' => 85,
            'net_usable_area_sqm' => 80,
            'status' => 'OCCUPIED',
        ]);

        $this->otherApartmentId = (string) Str::uuid();
        DB::table('apartments')->insert([
            'id' => $this->otherApartmentId,
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'apartment_number' => 'RB-0802',
            'gross_floor_area_sqm' => 85,
            'net_usable_area_sqm' => 80,
            'status' => 'OCCUPIED',
        ]);

        // Resident relations
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->residentUser->id,
            'apartment_id' => $this->apartmentId,
            'resident_type' => 'OWNER',
            'is_active' => 1,
            'stay_start_date' => today()->subMonths(6),
            'stay_end_date' => null,
            'created_at' => now(),
        ]);

        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->otherResidentUser->id,
            'apartment_id' => $this->otherApartmentId,
            'resident_type' => 'TENANT',
            'is_active' => 1,
            'stay_start_date' => today()->subMonths(3),
            'stay_end_date' => null,
            'created_at' => now(),
        ]);

        // Category
        $cat = TicketCategory::first();
        if (! $cat) {
            $cat = TicketCategory::create([
                'category_code' => 'PLUMBING',
                'category_name' => 'Sự cố Điện nước',
                'default_priority' => 'HIGH',
                'sla_response_time_minutes' => 30,
                'sla_resolution_time_hours' => 24,
            ]);
        }
        $this->categoryId = $cat->id;
    }

    private function createSessionToken(User $user): string
    {
        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'is_revoked' => 0,
        ]);

        return $token;
    }

    private function ticketStaff(string $role, ?string $blockId = null, bool $profile = true): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE']);
        Role::firstOrCreate(['role_code' => $role], ['role_name' => $role]);
        $user->assignRole($role);
        (new TicketPermissionsSeeder)->run();
        if ($profile) {
            $departmentId = (string) Str::uuid();
            DB::table('departments')->insert(['id' => $departmentId, 'department_code' => 'QA-'.Str::random(8), 'department_name' => 'QA Technical']);
            DB::table('staff_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'department_id' => $departmentId, 'employee_code' => 'QA-'.Str::random(8), 'job_title' => $role, 'hire_date' => today(), 'assigned_block_id' => $blockId]);
        }

        return $user;
    }

    public function test_ticket_management_roles_scope_creation_version_and_archive(): void
    {
        $admin = $this->ticketStaff('SUPER_ADMIN', profile: false);
        $blockId = DB::table('apartments')->where('id', $this->apartmentId)->value('block_id');
        $manager = $this->ticketStaff('BUILDING_MANAGER', $blockId);
        $missing = $this->ticketStaff('BUILDING_MANAGER', profile: false);
        $path = '/api/v1/admin/tickets';
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->getJson($path)->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($missing))->getJson($path)->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($manager));
        $created = $this->postJson($path, ['reporter_user_id' => $this->residentUser->id, 'apartment_id' => $this->apartmentId, 'title' => 'Tạo hộ cư dân', 'description' => 'Kiểm tra vòi nước tại phòng bếp.', 'location' => 'Phòng bếp', 'category_id' => $this->categoryId, 'priority' => 'HIGH', 'idempotency_key' => Str::uuid()->toString()])->assertCreated();
        $id = $created->json('id');
        $created->assertJsonPath('creator_user_id', $this->residentUser->id)->assertJsonPath('actions.edit', true);
        $this->assertDatabaseHas('ticket_history', ['ticket_id' => $id, 'action_type' => 'CREATED', 'performed_by_user_id' => $manager->id]);
        $this->getJson($path)->assertOk()->assertJsonPath('per_page', 15)->assertJsonPath('total', 1);
        $this->patchJson($path.'/'.$id, ['version' => 1, 'priority' => 'LOW'])->assertOk()->assertJsonPath('version', 2);
        $this->patchJson($path.'/'.$id, ['version' => 1, 'priority' => 'HIGH'])->assertConflict();
        $this->patchJson($path.'/'.$id, ['version' => 2, 'status' => 'CLOSED'])->assertUnprocessable();
        $this->deleteJson($path.'/'.$id, ['version' => 2, 'reason' => 'Lưu trữ yêu cầu'])->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($admin));
        $this->deleteJson($path.'/'.$id, ['version' => 2, 'reason' => 'Lưu trữ yêu cầu'])->assertConflict();
        Ticket::whereKey($id)->update(['status' => 'CANCELLED']);
        $this->deleteJson($path.'/'.$id, ['version' => 2, 'reason' => 'Lưu trữ yêu cầu'])->assertOk();
        $this->assertSoftDeleted('tickets', ['id' => $id]);
        $this->getJson($path.'/'.$id)->assertNotFound();
    }

    public function test_ticket_management_denies_cross_building_access_including_resident_alias(): void
    {
        $otherBlock = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $otherBlock, 'block_code' => 'OUT-'.Str::random(8), 'block_name' => 'Other block']);
        $manager = $this->ticketStaff('BUILDING_MANAGER', $otherBlock);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $id = $this->postJson('/api/v1/resident/tickets', ['title' => 'Sự cố riêng tư', 'description' => 'Sự cố tại căn hộ của tôi.', 'location' => 'Bếp', 'category_id' => $this->categoryId])->assertCreated()->json('data.id');
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($manager));
        $this->getJson('/api/v1/admin/tickets')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/admin/tickets/'.$id)->assertNotFound();
        $this->getJson('/api/v1/resident/tickets/'.$id)->assertNotFound();
        $this->patchJson('/api/v1/admin/tickets/'.$id, ['version' => 1, 'priority' => 'LOW'])->assertNotFound();
    }

    public function test_ticket_workflow_notifies_the_right_people_and_preserves_resident_acceptance(): void
    {
        $block = DB::table('apartments')->where('id', $this->apartmentId)->value('block_id');
        $manager = $this->ticketStaff('BUILDING_MANAGER', $block);
        $technician = $this->ticketStaff('TECHNICIAN', $block);
        $otherTech = $this->ticketStaff('TECHNICIAN', $block);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $id = $this->postJson('/api/v1/resident/tickets', ['title' => 'Kiểm tra luồng sửa chữa', 'description' => 'Vòi nước bị rò rỉ cần sửa.', 'location' => 'Phòng bếp', 'category_id' => $this->categoryId])->assertCreated()->json('data.id');
        $notification = DB::table('user_in_app_notifications')->where('recipient_user_id', $manager->id)->where('category', 'TICKET')->first();
        $this->assertNotNull($notification);
        $this->postJson('/api/v1/ticket-notifications/'.$notification->id.'/read')->assertNotFound();
        $path = '/api/v1/admin/tickets/'.$id;
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($manager));
        $this->postJson($path.'/workflow', ['action' => 'receive', 'version' => 1])->assertOk()->assertJsonPath('status', 'RECEIVED');
        $this->postJson($path.'/workflow', ['action' => 'assign', 'version' => 2, 'technician_user_id' => $technician->id])->assertOk()->assertJsonPath('version', 3);
        $this->postJson($path.'/workflow', ['action' => 'resolve', 'version' => 3, 'note' => 'Đã kiểm tra và sửa'])->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($otherTech));
        $this->postJson($path.'/workflow', ['action' => 'start', 'version' => 3])->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($technician));
        $this->patchJson($path, ['version' => 3, 'priority' => 'LOW'])->assertForbidden();
        $this->postJson($path.'/workflow', ['action' => 'start', 'version' => 3])->assertOk()->assertJsonPath('version', 4);
        $this->postJson($path.'/workflow', ['action' => 'resolve', 'version' => 3, 'note' => 'Đã kiểm tra và sửa'])->assertConflict();
        $this->post('/api/v1/resident/tickets/'.$id.'/attachments', ['version' => 4, 'attachments' => [UploadedFile::fake()->image('proof.png')]])->assertOk()->assertJsonPath('data.attachments.0.is_resolution_proof', true);
        $this->postJson($path.'/workflow', ['action' => 'resolve', 'version' => 5, 'note' => 'Đã thay van nước'])->assertOk()->assertJsonPath('version', 6);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $residentPath = '/api/v1/resident/tickets/'.$id;
        $this->postJson($residentPath.'/reopen', ['version' => 6, 'reason' => 'Vẫn bị rò nước'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($technician));
        $this->postJson($path.'/workflow', ['action' => 'resolve', 'version' => 7, 'note' => 'Đã xử lý dứt điểm'])->assertOk()->assertJsonPath('version', 8);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->postJson($residentPath.'/confirm', ['version' => 8])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->postJson($residentPath.'/confirm', ['version' => 8])->assertConflict();
        $this->getJson('/api/v1/ticket-notifications')->assertOk()->assertJsonPath('unread_count', 4);
        $this->assertSame(1, DB::table('ticket_history')->where('ticket_id', $id)->where('new_status', 'CLOSED')->count());
        $this->assertDatabaseHas('user_in_app_notifications', ['recipient_user_id' => $technician->id, 'category' => 'TICKET']);
    }

    public function test_workflow_transition_matrix_rejects_invalid_states_without_side_effects(): void
    {
        $block = DB::table('apartments')->where('id', $this->apartmentId)->value('block_id');
        $manager = $this->ticketStaff('BUILDING_MANAGER', $block);
        $technician = $this->ticketStaff('TECHNICIAN', $block);
        $managerToken = $this->createSessionToken($manager);
        $technicianToken = $this->createSessionToken($technician);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $id = $this->postJson('/api/v1/resident/tickets', ['title' => 'Kiểm tra ma trận trạng thái', 'description' => 'Sự cố kiểm thử chuyển trạng thái.', 'location' => 'Bếp', 'category_id' => $this->categoryId])->assertCreated()->json('data.id');
        $transitions = ['receive' => ['NEW'], 'assign' => ['NEW', 'RECEIVED', 'IN_PROGRESS'], 'start' => ['RECEIVED'], 'resolve' => ['IN_PROGRESS'], 'confirm' => ['RESOLVED'], 'reopen' => ['RESOLVED'], 'cancel' => ['NEW', 'RECEIVED']];
        foreach (['NEW', 'RECEIVED', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'CANCELLED'] as $status) {
            foreach ($transitions as $action => $allowed) {
                Ticket::whereKey($id)->update(['status' => $status, 'version' => 1, 'current_technician_user_id' => $technician->id]);
                $history = DB::table('ticket_history')->where('ticket_id', $id)->count();
                $notifications = DB::table('user_in_app_notifications')->where('category', 'TICKET')->count();
                $resident = in_array($action, ['confirm', 'reopen', 'cancel'], true);
                $token = $resident ? $this->residentToken : (in_array($action, ['start', 'resolve'], true) ? $technicianToken : $managerToken);
                $path = '/api/v1/'.($resident ? 'resident' : 'admin').'/tickets/'.$id.'/'.($resident ? $action : 'workflow');
                $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson($path, ['version' => 1, 'action' => $action, 'technician_user_id' => $technician->id, 'reason' => 'Lý do kiểm thử trạng thái', 'note' => 'Ghi chú kiểm thử trạng thái']);
                $valid = in_array($status, $allowed, true);
                $response->assertStatus($valid ? 200 : 409);
                $this->assertSame($valid ? 2 : 1, Ticket::findOrFail($id)->version);
                $this->assertSame($history + (int) $valid, DB::table('ticket_history')->where('ticket_id', $id)->count());
                if (! $valid) {
                    $this->assertSame($notifications, DB::table('user_in_app_notifications')->where('category', 'TICKET')->count());
                }
            }
        }
    }

    public function test_ticket_notification_failure_rolls_back_transition_history_and_audit(): void
    {
        $admin = $this->ticketStaff('SUPER_ADMIN', profile: false);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $id = $this->postJson('/api/v1/resident/tickets', ['title' => 'Kiểm tra rollback workflow', 'description' => 'Sự cố kiểm thử rollback.', 'location' => 'Bếp', 'category_id' => $this->categoryId])->assertCreated()->json('data.id');
        $notifications = \Mockery::mock(TicketNotificationService::class);
        $notifications->shouldReceive('changed')->once()->andThrow(new \RuntimeException('QA injected notification failure'));
        $this->app->instance(TicketNotificationService::class, $notifications);
        $this->withHeader('Authorization', 'Bearer '.$this->createSessionToken($admin))
            ->postJson('/api/v1/admin/tickets/'.$id.'/workflow', ['version' => 1, 'action' => 'receive'])->assertServerError();
        $this->assertDatabaseHas('tickets', ['id' => $id, 'status' => 'NEW', 'version' => 1]);
        $this->assertSame(0, DB::table('ticket_history')->where('ticket_id', $id)->where('action_type', 'STATUS_CHANGED')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('record_id', $id)->count());
    }

    public function test_ticket_creation_failure_removes_only_newly_written_private_files(): void
    {
        Storage::disk('local')->put('tickets/existing.png', 'keep-existing');
        $notifications = \Mockery::mock(TicketNotificationService::class);
        $notifications->shouldReceive('changed')->once()->andThrow(new \RuntimeException('QA injected notification failure'));
        $this->app->instance(TicketNotificationService::class, $notifications);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->post('/api/v1/resident/tickets', [
            'title' => 'Kiểm tra rollback file', 'description' => 'Ảnh kiểm thử rollback transaction.', 'location' => 'Bếp', 'category_id' => $this->categoryId,
            'attachments' => [UploadedFile::fake()->image('new.png')],
        ], ['Accept' => 'application/json'])->assertServerError();
        $this->assertSame(0, Ticket::where('creator_user_id', $this->residentUser->id)->count());
        $this->assertSame(['tickets/existing.png'], Storage::disk('local')->allFiles());
    }

    public function test_resident_can_create_ticket_successfully(): void
    {
        $payload = [
            'title' => 'Rò rỉ vòi nước bồn rửa chén',
            'description' => 'Vòi nước bị rỉ nước liên tục dưới gầm tủ bếp, cần kỹ thuật hỗ trợ kiểm tra van.',
            'category_id' => $this->categoryId,
            'location' => 'Khu vực bếp nấu',
            'priority' => 'HIGH',
            'apartment_id' => $this->apartmentId,
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/tickets', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Rò rỉ vòi nước bồn rửa chén')
            ->assertJsonPath('data.status', 'NEW')
            ->assertJsonPath('data.priority', 'HIGH')
            ->assertJsonPath('data.location', 'Khu vực bếp nấu')
            ->assertJsonPath('data.version', 1);

        $ticketId = $response->json('data.id');

        // Kiểm tra database có bản ghi ticket và history ban đầu
        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'title' => 'Rò rỉ vòi nước bồn rửa chén',
            'status' => 'NEW',
            'creator_user_id' => $this->residentUser->id,
        ]);

        $this->assertDatabaseHas('ticket_history', [
            'ticket_id' => $ticketId,
            'action_type' => 'CREATED',
            'new_status' => 'NEW',
            'performed_by_user_id' => $this->residentUser->id,
        ]);
    }

    public function test_resident_can_create_ticket_with_photo_attachments(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5ZkAAAAASUVORK5CYII=');
        $file1 = UploadedFile::fake()->createWithContent('leak1.jpg', $png);
        $file2 = UploadedFile::fake()->createWithContent('leak2.png', $png);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->post('/api/v1/resident/tickets', [
                'title' => 'Nứt gạch ban công',
                'description' => 'Có viên gạch bị nứt góc nguy hiểm khi bước ra ban công.',
                'category_id' => $this->categoryId,
                'location' => 'Ban công phòng khách',
                'priority' => 'MEDIUM',
                'apartment_id' => $this->apartmentId,
                'attachments' => [$file1, $file2],
            ]);

        $response->assertStatus(201);
        $ticketId = $response->json('data.id');

        $this->assertDatabaseCount('ticket_attachments', 2);
        $this->assertDatabaseHas('ticket_attachments', [
            'ticket_id' => $ticketId,
            'file_name' => 'leak1.jpg',
            'file_type' => 'IMAGE',
        ]);
    }

    public function test_create_ticket_validation_errors(): void
    {
        // 1. Thiếu title
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/tickets', [
                'description' => 'Chi tiết lỗi nhưng không có tiêu đề',
                'category_id' => $this->categoryId,
                'location' => 'Phòng tắm',
            ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        // 2. Category không tồn tại
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/tickets', [
                'title' => 'Lỗi hỏng hóc',
                'description' => 'Mô tả chi tiết',
                'category_id' => (string) Str::uuid(),
                'location' => 'Phòng tắm',
            ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // 3. Priority không hợp lệ
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/tickets', [
                'title' => 'Lỗi hỏng hóc',
                'description' => 'Mô tả chi tiết',
                'category_id' => $this->categoryId,
                'location' => 'Phòng tắm',
                'priority' => 'INVALID_PRIORITY',
            ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_upload_invalid_file_format_fails(): void
    {
        $badFile = UploadedFile::fake()->create('exploit.php', 100, 'application/x-php');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->post('/api/v1/resident/tickets', [
                'title' => 'Sự cố chập điện',
                'description' => 'Mô tả sự cố',
                'category_id' => $this->categoryId,
                'location' => 'Hành lang',
                'apartment_id' => $this->apartmentId,
                'attachments' => [$badFile],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);
    }

    public function test_resident_cannot_access_other_residents_ticket(): void
    {
        // Tạo ticket của residentUser
        $ticket = Ticket::create([
            'ticket_number' => 'TCK-PRIVATE-001',
            'category_id' => $this->categoryId,
            'apartment_id' => $this->apartmentId,
            'creator_user_id' => $this->residentUser->id,
            'title' => 'Sự cố riêng của căn RB-0801',
            'description' => 'Mô tả bí mật',
            'location' => 'Phòng ngủ',
            'priority' => 'LOW',
            'status' => 'NEW',
            'version' => 1,
        ]);

        // otherResidentUser cố truy cập ticket này
        $response = $this->withHeader('Authorization', 'Bearer '.$this->otherToken)
            ->getJson("/api/v1/resident/tickets/{$ticket->id}");

        $response->assertStatus(403);
    }

    public function test_resident_can_update_ticket_when_in_new_status(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TCK-UPDATE-001',
            'category_id' => $this->categoryId,
            'apartment_id' => $this->apartmentId,
            'creator_user_id' => $this->residentUser->id,
            'title' => 'Tiêu đề ban đầu',
            'description' => 'Mô tả ban đầu',
            'location' => 'Phòng khách',
            'priority' => 'LOW',
            'status' => 'NEW',
            'version' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->patchJson("/api/v1/resident/tickets/{$ticket->id}", [
                'title' => 'Tiêu đề đã được bổ sung',
                'description' => 'Mô tả bổ sung chi tiết hơn',
                'location' => 'Phòng khách cạnh cửa sổ',
                'priority' => 'HIGH',
                'version' => 1,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Tiêu đề đã được bổ sung')
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('ticket_history', [
            'ticket_id' => $ticket->id,
            'action_type' => 'UPDATED',
        ]);
    }

    public function test_cannot_update_ticket_when_in_progress(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TCK-INPROGRESS-001',
            'category_id' => $this->categoryId,
            'apartment_id' => $this->apartmentId,
            'creator_user_id' => $this->residentUser->id,
            'title' => 'Sự cố đang sửa',
            'description' => 'Kỹ thuật viên đang xử lý',
            'location' => 'Phòng khách',
            'status' => 'IN_PROGRESS',
            'version' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->patchJson("/api/v1/resident/tickets/{$ticket->id}", [
                'title' => 'Thay đổi tiêu đề',
                'description' => 'Thay đổi mô tả',
                'location' => 'Phòng khách',
                'version' => 1,
            ]);

        $response->assertStatus(409);
    }

    public function test_optimistic_concurrency_control_returns_409_when_stale_version_used(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TCK-OCC-001',
            'category_id' => $this->categoryId,
            'apartment_id' => $this->apartmentId,
            'creator_user_id' => $this->residentUser->id,
            'title' => 'Kiểm thử OCC',
            'description' => 'Mô tả',
            'location' => 'Phòng khách',
            'status' => 'NEW',
            'version' => 3, // Version hiện tại trong DB là 3
        ]);

        // Client gửi version cũ là 2
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->patchJson("/api/v1/resident/tickets/{$ticket->id}", [
                'title' => 'Cập nhật từ tab cũ',
                'description' => 'Mô tả mới',
                'location' => 'Phòng khách',
                'version' => 2,
            ]);

        $response->assertStatus(409);
    }

    public function test_resident_can_cancel_ticket(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TCK-CANCEL-001',
            'category_id' => $this->categoryId,
            'apartment_id' => $this->apartmentId,
            'creator_user_id' => $this->residentUser->id,
            'title' => 'Tôi đã tự sửa được',
            'description' => 'Không cần kỹ thuật đến nữa',
            'location' => 'Phòng tắm',
            'status' => 'NEW',
            'version' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/tickets/{$ticket->id}/cancel", [
                'reason' => 'Đã tự vặn chặt van nước, hoạt động bình thường.',
                'version' => 1,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertDatabaseHas('ticket_history', [
            'ticket_id' => $ticket->id,
            'new_status' => 'CANCELLED',
        ]);
    }

    public function test_resident_can_confirm_resolved_ticket_to_closed(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TCK-RESOLVED-001',
            'category_id' => $this->categoryId,
            'apartment_id' => $this->apartmentId,
            'creator_user_id' => $this->residentUser->id,
            'title' => 'Sửa bóng đèn hành lang',
            'description' => 'Đã thay bóng LED mới',
            'location' => 'Cửa chính',
            'status' => 'RESOLVED',
            'version' => 2,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/tickets/{$ticket->id}/confirm", [
                'note' => 'Đèn sáng tốt, kỹ thuật viên rất nhiệt tình.',
                'version' => 2,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'CLOSED');

        $this->assertNotNull($ticket->fresh()->closed_at);
    }

    public function test_resident_can_reopen_resolved_ticket(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TCK-REOPEN-001',
            'category_id' => $this->categoryId,
            'apartment_id' => $this->apartmentId,
            'creator_user_id' => $this->residentUser->id,
            'title' => 'Sửa điều hòa phòng ngủ',
            'description' => 'Bật không mát',
            'location' => 'Phòng ngủ lớn',
            'status' => 'RESOLVED',
            'version' => 2,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/tickets/{$ticket->id}/reopen", [
                'reason' => 'Điều hòa vẫn bị chảy nước và phát ra tiếng kêu cọc cọc.',
                'version' => 2,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');

        $this->assertDatabaseHas('ticket_history', [
            'ticket_id' => $ticket->id,
            'new_status' => 'IN_PROGRESS',
        ]);
    }

    public function test_duplicate_submission_with_idempotency_key_returns_existing_ticket(): void
    {
        $key = 'IDEMP-TEST-KEY-'.Str::random(10);
        $payload = [
            'title' => 'Mất tín hiệu mạng Internet',
            'description' => 'Router nhấp nháy đèn đỏ từ sáng sớm.',
            'category_id' => $this->categoryId,
            'location' => 'Phòng làm việc',
            'apartment_id' => $this->apartmentId,
        ];

        // Lần 1
        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/v1/resident/tickets', $payload);
        $res1->assertStatus(201);
        $ticketId1 = $res1->json('data.id');

        // Lần 2 gửi y hệt cùng key
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson('/api/v1/resident/tickets', $payload);
        $res2->assertStatus(201);
        $ticketId2 = $res2->json('data.id');

        $this->assertSame($ticketId1, $ticketId2);
        // Kiểm tra trong DB chỉ có duy nhất 1 ticket được tạo
        $this->assertEquals(1, Ticket::where('title', 'Mất tín hiệu mạng Internet')->count());
    }

    public function test_alias_routes_work_for_standard_resident_tickets_path(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->getJson('/api/resident/tickets');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'pagination', 'counters']);
    }

    public function test_audit_alias_rejects_a_fabricated_token_without_a_session(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $token = 'smart_token_'.$this->residentUser->id.'_fabricated';

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/resident/tickets')->assertUnauthorized();
    }

    public function test_private_photos_require_ticket_access_and_preserve_storage_privacy(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->post('/api/v1/resident/tickets', [
            'title' => 'Kiểm tra ảnh riêng tư', 'description' => 'Ảnh hiện trường sự cố trong căn hộ.',
            'category_id' => $this->categoryId, 'location' => 'Phòng bếp',
            'attachments' => [UploadedFile::fake()->image('photo.png')],
        ])->assertCreated();
        $attachment = TicketAttachment::findOrFail($response->json('data.attachments.0.id'));
        Storage::disk('local')->assertExists($attachment->storage_path);
        Storage::disk('public')->assertMissing($attachment->storage_path);
        $response->assertJsonMissingPath('data.attachments.0.storage_path');
        $url = $response->json('data.attachments.0.file_url');
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withHeader('Authorization', 'Bearer '.$this->otherToken)->get($url)->assertForbidden();
        $this->withHeader('Authorization', '')->get($url)->assertUnauthorized();
    }

    public function test_private_photos_reject_corrupt_content_and_roll_back_created_files(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->post('/api/v1/resident/tickets', [
            'title' => 'Kiểm tra ảnh bị hỏng', 'description' => 'Ảnh hiện trường sự cố trong căn hộ.',
            'category_id' => $this->categoryId, 'location' => 'Phòng bếp',
            'attachments' => [UploadedFile::fake()->image('good.png'), UploadedFile::fake()->create('broken.png', 1, 'image/png')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, Ticket::where('creator_user_id', $this->residentUser->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_private_photo_addition_checks_total_limit_and_version(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $id = $this->postJson('/api/v1/resident/tickets', [
            'title' => 'Kiểm tra bổ sung ảnh', 'description' => 'Ảnh hiện trường sự cố trong căn hộ.',
            'category_id' => $this->categoryId, 'location' => 'Phòng bếp',
        ])->assertCreated()->json('data.id');
        $path = '/api/v1/resident/tickets/'.$id.'/attachments';
        $first = $this->post($path, ['version' => '1', 'attachments' => [UploadedFile::fake()->image('first.png')]])->assertOk();
        $first->assertJsonPath('data.version', 2);
        $this->post($path, ['version' => 1, 'attachments' => [UploadedFile::fake()->image('stale.png')]])->assertConflict();
        $this->post($path, ['version' => 2, 'attachments' => array_map(fn (int $i) => UploadedFile::fake()->image($i.'.png'), range(1, 5))], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson($path.'/'.$first->json('data.attachments.0.id'), ['version' => 2])->assertOk()->assertJsonCount(0, 'data.attachments');
    }

    public function test_legacy_photo_migration_is_dry_run_until_apply_and_verifies_private_copy(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $id = $this->postJson('/api/v1/resident/tickets', [
            'title' => 'Kiểm tra chuyển kho ảnh', 'description' => 'Ảnh hiện trường sự cố trong căn hộ.',
            'category_id' => $this->categoryId, 'location' => 'Phòng bếp',
        ])->assertCreated()->json('data.id');
        $path = 'tickets/'.$id.'/legacy.png';
        Storage::disk('public')->put($path, 'legacy-bytes');
        $attachment = TicketAttachment::create(['ticket_id' => $id, 'file_name' => 'legacy.png', 'file_url' => '/storage/'.$path, 'file_type' => 'IMAGE', 'storage_path' => $path, 'uploaded_by_user_id' => $this->residentUser->id]);
        $this->artisan('tickets:migrate-attachments')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $this->artisan('tickets:migrate-attachments', ['--apply' => true, '--remove-public' => true])->assertSuccessful();
        $this->assertSame('local', $attachment->fresh()->storage_disk);
        $this->assertSame('legacy-bytes', Storage::disk('local')->get($path));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_ticket_retry_survives_cache_loss_and_the_old_ten_minute_window(): void
    {
        $payload = [
            'title' => 'Kiểm tra gửi lại sau mất mạng', 'description' => 'Vòi nước cần được kiểm tra.',
            'category_id' => $this->categoryId, 'location' => 'Phòng bếp', 'apartment_id' => $this->apartmentId,
        ];
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->withHeader('X-Idempotency-Key', 'retry-after-cache-loss');
        $first = $this->postJson('/api/v1/resident/tickets', $payload)->assertCreated()->json('data.id');
        Cache::flush();
        $this->travel(11)->minutes();
        try {
            $this->postJson('/api/v1/resident/tickets', $payload)->assertCreated()->assertJsonPath('data.id', $first);
            $this->assertSame(1, Ticket::where('creator_user_id', $this->residentUser->id)->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_ticket_rejects_an_oversized_idempotency_header(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->withHeader('X-Idempotency-Key', str_repeat('a', 101))
            ->postJson('/api/v1/resident/tickets', [
                'title' => 'Yêu cầu kiểm tra vòi nước', 'description' => 'Vòi nước cần được kiểm tra.',
                'category_id' => $this->categoryId, 'location' => 'Phòng bếp',
            ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_ticket_mutations_lock_the_row_and_do_not_repeat_the_transition(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $ticket = $this->postJson('/api/v1/resident/tickets', [
            'title' => 'Yêu cầu kiểm tra vòi nước', 'description' => 'Vòi nước cần được kiểm tra.',
            'category_id' => $this->categoryId, 'location' => 'Phòng bếp',
        ])->assertCreated()->json('data.id');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->postJson('/api/v1/resident/tickets/'.$ticket.'/cancel', ['version' => 1, 'reason' => 'Đã tự khắc phục'])->assertOk();
            $this->assertTrue(collect(DB::getQueryLog())->contains(
                fn (array $query): bool => str_contains($query['query'], 'from `tickets`') && str_contains($query['query'], 'for update')
            ));
            $this->postJson('/api/v1/resident/tickets/'.$ticket.'/cancel', ['version' => 1, 'reason' => 'Đã tự khắc phục'])->assertConflict();
            $this->assertSame(1, DB::table('ticket_history')->where('ticket_id', $ticket)->where('new_status', 'CANCELLED')->count());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_audit_strict_route_rejects_a_revoked_cached_session(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->getJson('/api/v1/resident/tickets')->assertOk();

        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 1]);

        $this->getJson('/api/v1/resident/tickets')->assertUnauthorized();
    }

    public function test_audit_strict_route_rejects_an_expired_cached_session(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)
            ->update(['expires_at' => now()->addSecond()]);

        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->getJson('/api/v1/resident/tickets')->assertOk();

        $this->travel(2)->seconds();
        try {
            $this->getJson('/api/v1/resident/tickets')->assertUnauthorized();
        } finally {
            $this->travelBack();
            Cache::forget('auth_bearer_user_id:'.hash('sha256', $this->residentToken));
        }
    }
}
