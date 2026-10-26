<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Suggestion;
use App\Models\SuggestionBox;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuggestionManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected User $residentUser;

    protected User $otherResidentUser;

    protected User $managerUser;

    protected User $otherBlockManagerUser;

    protected string $residentToken;

    protected string $otherResidentToken;

    protected string $managerToken;

    protected string $otherBlockManagerToken;

    protected Block $blockA;

    protected Block $blockB;

    protected Apartment $aptA;

    protected Apartment $aptB;

    protected SuggestionBox $generalBox;

    protected SuggestionBox $blockABox;

    protected SuggestionBox $blockBBox;

    protected SuggestionBox $closedBox;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Create Blocks
        $this->blockA = Block::firstOrCreate(['block_code' => 'TEST_BLOCK_A'], [
            'id' => (string) Str::uuid(),
            'block_name' => 'Tòa Tháp Test A',
            'total_floors' => 10,
        ]);
        $this->blockB = Block::firstOrCreate(['block_code' => 'TEST_BLOCK_B'], [
            'id' => (string) Str::uuid(),
            'block_name' => 'Tòa Tháp Test B',
            'total_floors' => 10,
        ]);

        $floorA = Floor::firstOrCreate(['block_id' => $this->blockA->id, 'floor_number' => 1], [
            'id' => (string) Str::uuid(),
            'floor_code' => 'FL-A01',
            'floor_name' => 'Tầng 1 - Block A',
        ]);
        $floorB = Floor::firstOrCreate(['block_id' => $this->blockB->id, 'floor_number' => 1], [
            'id' => (string) Str::uuid(),
            'floor_code' => 'FL-B01',
            'floor_name' => 'Tầng 1 - Block B',
        ]);

        $this->aptA = Apartment::firstOrCreate(['apartment_number' => 'TEST-A101'], [
            'id' => (string) Str::uuid(),
            'block_id' => $this->blockA->id,
            'floor_id' => $floorA->id,
            'gross_floor_area_sqm' => 75.5,
            'net_usable_area_sqm' => 70.0,
            'status' => 'OCCUPIED',
        ]);
        $this->aptB = Apartment::firstOrCreate(['apartment_number' => 'TEST-B101'], [
            'id' => (string) Str::uuid(),
            'block_id' => $this->blockB->id,
            'floor_id' => $floorB->id,
            'gross_floor_area_sqm' => 85.0,
            'net_usable_area_sqm' => 80.0,
            'status' => 'OCCUPIED',
        ]);

        // Create Users
        $this->residentUser = User::factory()->create();
        $this->otherResidentUser = User::factory()->create();
        $this->managerUser = User::factory()->create();
        $this->otherBlockManagerUser = User::factory()->create();

        // Assign Residents to Apartments
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->residentUser->id,
            'apartment_id' => $this->aptA->id,
            'resident_type' => 'OWNER',
            'is_active' => 1,
            'stay_start_date' => today()->subMonth()->toDateString(),
            'created_at' => now(),
        ]);
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->otherResidentUser->id,
            'apartment_id' => $this->aptB->id,
            'resident_type' => 'OWNER',
            'is_active' => 1,
            'stay_start_date' => today()->subMonth()->toDateString(),
            'created_at' => now(),
        ]);

        // Assign Manager roles and permissions
        $managerRole = Role::firstOrCreate(['role_code' => 'BUILDING_MANAGER'], ['role_name' => 'Building Manager']);
        DB::table('user_roles')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $this->managerUser->id, 'role_id' => $managerRole->id],
            ['id' => (string) Str::uuid(), 'user_id' => $this->otherBlockManagerUser->id, 'role_id' => $managerRole->id],
        ]);

        foreach (['VIEW', 'REPLY', 'MANAGE'] as $act) {
            $perm = Permission::firstOrCreate(['permission_code' => 'SUGGESTION:'.$act], [
                'module' => 'SUGGESTION',
                'permission_name' => 'Suggestion '.$act,
            ]);
            if (! DB::table('role_permissions')->where('role_id', $managerRole->id)->where('permission_id', $perm->id)->exists()) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $managerRole->id,
                    'permission_id' => $perm->id,
                    'created_at' => now(),
                ]);
            }
        }

        $dept = DB::table('departments')->first();
        $deptId = $dept?->id ?? (string) Str::uuid();
        if (! $dept) {
            DB::table('departments')->insert([
                'id' => $deptId,
                'department_code' => 'DEPT-TEST',
                'department_name' => 'Ban Quản Lý',
                'created_at' => now(),
            ]);
        }

        // Staff profile scopes: managerUser -> Block A, otherBlockManagerUser -> Block B
        DB::table('staff_profiles')->insert([
            [
                'id' => (string) Str::uuid(),
                'user_id' => $this->managerUser->id,
                'employee_code' => 'EMP-A-'.Str::random(6),
                'department_id' => $deptId,
                'job_title' => 'Manager A',
                'hire_date' => '2025-01-01',
                'assigned_block_id' => $this->blockA->id,
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $this->otherBlockManagerUser->id,
                'employee_code' => 'EMP-B-'.Str::random(6),
                'department_id' => $deptId,
                'job_title' => 'Manager B',
                'hire_date' => '2025-01-01',
                'assigned_block_id' => $this->blockB->id,
                'created_at' => now(),
            ],
        ]);

        // Tokens
        $this->residentToken = $this->createToken($this->residentUser);
        $this->otherResidentToken = $this->createToken($this->otherResidentUser);
        $this->managerToken = $this->createToken($this->managerUser);
        $this->otherBlockManagerToken = $this->createToken($this->otherBlockManagerUser);

        // Boxes
        $this->generalBox = SuggestionBox::create([
            'id' => (string) Str::uuid(),
            'box_name' => 'Hòm thư Chung',
            'block_id' => null,
            'is_open' => true,
        ]);
        $this->blockABox = SuggestionBox::create([
            'id' => (string) Str::uuid(),
            'box_name' => 'Hòm thư Block A',
            'block_id' => $this->blockA->id,
            'is_open' => true,
        ]);
        $this->blockBBox = SuggestionBox::create([
            'id' => (string) Str::uuid(),
            'box_name' => 'Hòm thư Block B',
            'block_id' => $this->blockB->id,
            'is_open' => true,
        ]);
        $this->closedBox = SuggestionBox::create([
            'id' => (string) Str::uuid(),
            'box_name' => 'Hòm thư Tạm Đóng',
            'block_id' => null,
            'is_open' => false,
        ]);
    }

    protected function createToken(User $user): string
    {
        $token = 'test_token_'.$user->id.'_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
            'is_revoked' => 0,
        ]);

        return $token;
    }

    // 1. Gửi góp ý hợp lệ (cư dân có danh tính)
    public function test_01_resident_submits_valid_suggestion_with_identity(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/suggestions', [
                'box_id' => $this->generalBox->id,
                'topic_category' => 'VE_SINH',
                'title' => 'Đề nghị vệ sinh sảnh tầng 1',
                'content_text' => 'Khu vực thang máy sảnh tầng 1 cần được lau dọn thường xuyên hơn trong mùa mưa.',
                'is_anonymous' => false,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('suggestions', [
            'box_id' => $this->generalBox->id,
            'resident_user_id' => $this->residentUser->id,
            'apartment_id' => $this->aptA->id,
            'is_anonymous' => 0,
            'title' => 'Đề nghị vệ sinh sảnh tầng 1',
            'status' => 'RECEIVED',
            'version' => 1,
        ]);
    }

    // 2. Gửi ẩn danh không lưu danh tính & trả mã tra cứu bí mật
    public function test_02_anonymous_submission_does_not_store_identity_and_returns_tracking_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/suggestions', [
                'box_id' => $this->generalBox->id,
                'topic_category' => 'AN_NINH',
                'title' => 'Góp ý ẩn danh về chốt bảo vệ cổng phụ',
                'content_text' => 'Buổi tối cổng phụ hay mở cửa mà không thấy có nhân viên túc trực.',
                'is_anonymous' => true,
            ]);

        $response->assertCreated();
        $data = $response->json();

        $this->assertNotEmpty($data['anonymous_token']);
        $rawToken = $data['anonymous_token'];
        $tokenHash = hash('sha256', $rawToken);

        // Verify database strictly has NULL identity
        $this->assertDatabaseHas('suggestions', [
            'id' => $data['data']['id'],
            'resident_user_id' => null,
            'apartment_id' => null,
            'is_anonymous' => 1,
            'anonymous_access_token_hash' => $tokenHash,
        ]);

        // Verify audit log has NULL performed_by_user_id
        $audit = DB::table('audit_logs')->where('record_id', $data['data']['id'])->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->performed_by_user_id);

        // Lookup via token works
        $lookupRes = $this->getJson('/api/v1/resident/suggestions/anonymous-lookup?token='.$rawToken);
        $lookupRes->assertOk();
        $lookupData = $lookupRes->json('data');
        $this->assertSame('Góp ý ẩn danh về chốt bảo vệ cổng phụ', $lookupData['title']);
        $this->assertArrayNotHasKey('resident_user_id', $lookupData);
        $this->assertArrayNotHasKey('apartment_id', $lookupData);
    }

    // 3. Không gửi vào hòm thư đóng
    public function test_03_cannot_submit_to_closed_suggestion_box(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/suggestions', [
                'box_id' => $this->closedBox->id,
                'topic_category' => 'TIEN_ICH',
                'title' => 'Góp ý vào hòm thư đóng',
                'content_text' => 'Nội dung kiểm tra hòm thư đã đóng không được phép tiếp nhận.',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['box_id']);
    }

    // 4. Không gửi vào tòa nhà ngoài quyền
    public function test_04_resident_cannot_submit_to_unauthorized_building_box(): void
    {
        // Resident A belongs to Block A, cannot submit to Block B box
        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/suggestions', [
                'box_id' => $this->blockBBox->id,
                'topic_category' => 'BAI_XE',
                'title' => 'Góp ý gửi nhầm hòm thư tòa khác',
                'content_text' => 'Cư dân Block A cố gắng gửi vào hòm thư của Block B.',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['box_id']);
    }

    // 5. Tải ảnh hợp lệ
    public function test_05_upload_valid_image_attachments(): void
    {
        $fakeImage = UploadedFile::fake()->image('evidence.jpg', 600, 400);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->post('/api/v1/resident/suggestions', [
                'box_id' => $this->blockABox->id,
                'topic_category' => 'VE_SINH',
                'title' => 'Kèm ảnh chụp rác tồn đọng',
                'content_text' => 'Gửi kèm hình ảnh minh chứng khu tập kết rác cần được thu gom.',
                'attached_files' => [$fakeImage],
            ]);

        $response->assertCreated();
        $suggestion = Suggestion::find($response->json('data.id'));
        $this->assertNotNull($suggestion);
        $this->assertCount(1, $suggestion->attached_files);
        $this->assertSame('image/jpeg', $suggestion->attached_files[0]['mime_type']);

        // Download attachment
        $downloadRes = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->get($suggestion->attached_files[0]['url']);
        $downloadRes->assertOk();
    }

    // 6. Chặn file không hợp lệ (không phải ảnh hoặc quá 5MB)
    public function test_06_reject_invalid_attachment_files(): void
    {
        $fakeScript = UploadedFile::fake()->create('malicious.php', 100, 'text/x-php');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->post('/api/v1/resident/suggestions', [
                'box_id' => $this->generalBox->id,
                'topic_category' => 'KHAC',
                'title' => 'Gửi file không phải định dạng ảnh',
                'content_text' => 'Cố gắng đính kèm file script nguy hiểm vào góp ý.',
                'attached_files' => [$fakeScript],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['attached_files.0']);
    }

    // 7. Không xem góp ý của người khác
    public function test_07_resident_cannot_view_other_resident_suggestions_in_history(): void
    {
        $sugA = Suggestion::create([
            'id' => (string) Str::uuid(),
            'box_id' => $this->generalBox->id,
            'resident_user_id' => $this->residentUser->id,
            'apartment_id' => $this->aptA->id,
            'is_anonymous' => false,
            'topic_category' => 'TIEN_ICH',
            'title' => 'Góp ý riêng tư của cư dân A',
            'content_text' => 'Nội dung góp ý của cư dân A.',
            'status' => 'RECEIVED',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->otherResidentToken)
            ->getJson('/api/v1/resident/suggestions/my-suggestions');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($sugA->id));
    }

    // 8. Quản lý chỉ xem đúng phạm vi tòa nhà
    public function test_08_manager_only_sees_scoped_building_suggestions(): void
    {
        // Suggestion in Block B
        $sugB = Suggestion::create([
            'id' => (string) Str::uuid(),
            'box_id' => $this->blockBBox->id,
            'resident_user_id' => $this->otherResidentUser->id,
            'apartment_id' => $this->aptB->id,
            'is_anonymous' => false,
            'topic_category' => 'BAI_XE',
            'title' => 'Góp ý hòm thư Block B',
            'content_text' => 'Nội dung góp ý riêng cho tòa tháp B.',
            'status' => 'RECEIVED',
        ]);

        // Manager A (assigned to Block A) should NOT see sugB in list
        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->getJson('/api/v1/management/suggestions');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($sugB->id));

        // Manager A cannot view detail of Block B suggestion (scoped 404)
        $detailRes = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->getJson('/api/v1/management/suggestions/'.$sugB->id);
        $detailRes->assertNotFound();

        // Manager B (assigned to Block B) CAN see sugB
        $responseB = $this->withHeader('Authorization', 'Bearer '.$this->otherBlockManagerToken)
            ->getJson('/api/v1/management/suggestions');
        $responseB->assertOk();
        $idsB = collect($responseB->json('data'))->pluck('id');
        $this->assertTrue($idsB->contains($sugB->id));
    }

    // 9. Phản hồi chính thức của Ban Quản lý
    public function test_09_manager_official_reply(): void
    {
        $sug = Suggestion::create([
            'id' => (string) Str::uuid(),
            'box_id' => $this->generalBox->id,
            'resident_user_id' => $this->residentUser->id,
            'apartment_id' => $this->aptA->id,
            'is_anonymous' => false,
            'topic_category' => 'VE_SINH',
            'title' => 'Cần xử lý rác tầng 3',
            'content_text' => 'Rác tầng 3 chưa được gom.',
            'status' => 'REVIEWING',
            'version' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->putJson('/api/v1/management/suggestions/'.$sug->id.'/reply', [
                'official_response_text' => 'Ban Quản lý đã cử đội vệ sinh xử lý ngay trong ca sáng.',
                'version' => 1,
                'status' => 'RESOLVED',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('suggestions', [
            'id' => $sug->id,
            'status' => 'RESOLVED',
            'official_response_text' => 'Ban Quản lý đã cử đội vệ sinh xử lý ngay trong ca sáng.',
            'responded_by_staff_id' => $this->managerUser->id,
            'version' => 2,
        ]);
        $this->assertDatabaseHas('user_in_app_notifications', [
            'recipient_user_id' => $this->residentUser->id,
            'category' => 'SUGGESTION',
        ]);
    }

    // 10. Trạng thái không hợp lệ bị chặn bởi State Machine
    public function test_10_invalid_state_machine_transition_is_rejected(): void
    {
        $sug = Suggestion::create([
            'id' => (string) Str::uuid(),
            'box_id' => $this->generalBox->id,
            'is_anonymous' => true,
            'topic_category' => 'KHAC',
            'title' => 'Góp ý đã hoàn thành',
            'content_text' => 'Nội dung góp ý kiểm tra state machine.',
            'status' => 'RESOLVED',
            'version' => 2,
        ]);

        // Attempt invalid transition: RESOLVED -> REVIEWING
        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->patchJson('/api/v1/management/suggestions/'.$sug->id.'/status', [
                'status' => 'REVIEWING',
                'version' => 2,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    // 11. Tranh chấp version trả HTTP 409 (Optimistic Concurrency Control)
    public function test_11_occ_version_mismatch_returns_409_conflict(): void
    {
        $sug = Suggestion::create([
            'id' => (string) Str::uuid(),
            'box_id' => $this->generalBox->id,
            'is_anonymous' => true,
            'topic_category' => 'AN_NINH',
            'title' => 'Kiểm tra tranh chấp OCC',
            'content_text' => 'Góp ý để test tranh chấp version.',
            'status' => 'RECEIVED',
            'version' => 3, // Current DB version is 3
        ]);

        // Client passes stale version = 2
        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->patchJson('/api/v1/management/suggestions/'.$sug->id.'/status', [
                'status' => 'REVIEWING',
                'version' => 2,
            ]);

        $response->assertStatus(409);
    }

    // 12. Hai quản lý cùng phản hồi một góp ý
    public function test_12_concurrent_replies_handle_conflict_cleanly(): void
    {
        $sug = Suggestion::create([
            'id' => (string) Str::uuid(),
            'box_id' => $this->generalBox->id,
            'is_anonymous' => false,
            'resident_user_id' => $this->residentUser->id,
            'apartment_id' => $this->aptA->id,
            'topic_category' => 'TIEN_ICH',
            'title' => 'Cần nâng cấp phòng gym',
            'content_text' => 'Đề xuất thay máy chạy bộ số 2.',
            'status' => 'REVIEWING',
            'version' => 1,
        ]);

        // Manager 1 replies with version 1 -> succeeds, increments version to 2
        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->putJson('/api/v1/management/suggestions/'.$sug->id.'/reply', [
                'official_response_text' => 'Phản hồi từ Quản lý 1.',
                'version' => 1,
                'status' => 'ACCEPTED',
            ]);
        $res1->assertOk();

        // Manager 2 simultaneously sends reply with stale version 1 -> 409 Conflict
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->putJson('/api/v1/management/suggestions/'.$sug->id.'/reply', [
                'official_response_text' => 'Phản hồi từ Quản lý 2.',
                'version' => 1,
            ]);
        $res2->assertStatus(409);
    }

    // 13. Notification không tiết lộ danh tính người gửi ẩn danh
    public function test_13_notification_does_not_reveal_anonymous_identity(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/suggestions', [
                'box_id' => $this->blockABox->id,
                'topic_category' => 'THAI_DO_NHAN_VIEN',
                'title' => 'Phản ánh nhân viên bảo vệ',
                'content_text' => 'Thái độ của nhân viên ca trực đêm chưa chuẩn mực.',
                'is_anonymous' => true,
            ]);

        $notification = DB::table('user_in_app_notifications')
            ->where('recipient_user_id', $this->managerUser->id)
            ->where('category', 'SUGGESTION')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Góp ý ẩn danh', $notification->title);
        $this->assertStringNotContainsString($this->residentUser->full_name ?? 'User', $notification->body_message);
        $this->assertStringNotContainsString($this->aptA->apartment_number, $notification->body_message);
    }

    // 14. AI sentiment: có provider và xử lý lỗi provider
    public function test_14_ai_sentiment_with_provider_mock_and_error_handling(): void
    {
        config(['services.gemini.key' => 'fake-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode(['sentiment' => 'VERY_ANGRY', 'score' => -0.85])],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $sug = Suggestion::create([
            'id' => (string) Str::uuid(),
            'box_id' => $this->generalBox->id,
            'is_anonymous' => true,
            'topic_category' => 'AN_NINH',
            'title' => 'Mất trộm đồ tại bãi xe',
            'content_text' => 'Xe tôi bị bẻ khóa mất mũ bảo hiểm cao cấp, bảo vệ vô trách nhiệm!',
            'status' => 'RECEIVED',
            'ai_sentiment' => 'NOT_ANALYZED',
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->postJson('/api/v1/suggestions/ai-sentiment-analyze', [
                'suggestion_id' => $sug->id,
            ]);

        $res->assertOk();
        $this->assertDatabaseHas('suggestions', [
            'id' => $sug->id,
            'ai_sentiment' => 'VERY_ANGRY',
            'ai_sentiment_score' => -0.85,
        ]);
    }

    // 15. Không có provider thì không tạo kết quả giả (trả NOT_ANALYZED / BLOCKED)
    public function test_15_no_ai_provider_does_not_fake_results(): void
    {
        config(['services.gemini.key' => null, 'services.openai.key' => null]);
        putenv('GEMINI_API_KEY=');
        putenv('OPENAI_API_KEY=');

        $sug = Suggestion::create([
            'id' => (string) Str::uuid(),
            'box_id' => $this->generalBox->id,
            'is_anonymous' => true,
            'topic_category' => 'TIEN_ICH',
            'title' => 'Hồ bơi nước hơi lạnh',
            'content_text' => 'Nhiệt độ nước hồ bơi cần được điều chỉnh ấm hơn.',
            'status' => 'RECEIVED',
            'ai_sentiment' => 'NOT_ANALYZED',
        ]);

        $res = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->postJson('/api/v1/suggestions/ai-sentiment-analyze', [
                'suggestion_id' => $sug->id,
            ]);

        $res->assertStatus(503);
        $this->assertSame('BLOCKED', $res->json('status'));
        $this->assertDatabaseHas('suggestions', [
            'id' => $sug->id,
            'ai_sentiment' => 'NOT_ANALYZED',
            'ai_sentiment_score' => null,
        ]);
    }

    // 16. Không tạo dữ liệu trùng do retry (Idempotency key)
    public function test_16_idempotency_prevents_duplicate_submission(): void
    {
        $idempotencyKey = 'idem-'.Str::random(16);

        $payload = [
            'box_id' => $this->generalBox->id,
            'topic_category' => 'BAI_XE',
            'title' => 'Đề xuất sơn lại vạch đỗ xe',
            'content_text' => 'Vạch sơn bãi đỗ xe đã mờ gây khó khăn khi dừng xe.',
            'idempotency_key' => $idempotencyKey,
        ];

        // First submit
        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/suggestions', $payload);
        $res1->assertCreated();
        $id1 = $res1->json('data.id');

        // Immediate retry with identical idempotency key
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson('/api/v1/resident/suggestions', $payload);
        $res2->assertCreated();
        $id2 = $res2->json('data.id');

        $this->assertSame($id1, $id2);
        $this->assertSame(1, Suggestion::where('idempotency_key', $idempotencyKey)->count());
    }
}
