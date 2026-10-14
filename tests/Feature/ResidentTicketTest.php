<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Floor;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
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
        $file1 = UploadedFile::fake()->create('leak1.jpg', 150, 'image/jpeg');
        $file2 = UploadedFile::fake()->create('leak2.png', 200, 'image/png');

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
}
