<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SatisfactionSurvey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\SurveyResponseDetail;
use App\Models\User;
use App\Services\SatisfactionSurveyService;
use App\Services\SurveyAnalyticsService;
use Database\Seeders\SatisfactionSurveySeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SatisfactionSurveyTest extends TestCase
{
    use DatabaseTransactions;

    protected User $residentUser;

    protected User $otherBlockResidentUser;

    protected User $managerUser;

    protected User $otherBlockManagerUser;

    protected string $residentToken;

    protected string $otherBlockResidentToken;

    protected string $managerToken;

    protected string $otherBlockManagerToken;

    protected Block $blockA;

    protected Block $blockB;

    protected Apartment $aptA;

    protected Apartment $aptB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Blocks & Floors & Apartments
        $this->blockA = Block::firstOrCreate(['block_code' => 'TEST_SURVEY_BLK_A'], [
            'id' => (string) Str::uuid(),
            'block_name' => 'Tòa Khảo Sát A',
            'total_floors' => 10,
        ]);

        $this->blockB = Block::firstOrCreate(['block_code' => 'TEST_SURVEY_BLK_B'], [
            'id' => (string) Str::uuid(),
            'block_name' => 'Tòa Khảo Sát B',
            'total_floors' => 10,
        ]);

        $floorA = Floor::firstOrCreate(['block_id' => $this->blockA->id, 'floor_number' => 2], [
            'id' => (string) Str::uuid(),
            'floor_code' => 'FL-SA02',
            'floor_name' => 'Tầng 2 Block A',
        ]);

        $floorB = Floor::firstOrCreate(['block_id' => $this->blockB->id, 'floor_number' => 2], [
            'id' => (string) Str::uuid(),
            'floor_code' => 'FL-SB02',
            'floor_name' => 'Tầng 2 Block B',
        ]);

        $this->aptA = Apartment::firstOrCreate(['apartment_number' => 'TEST-SA201'], [
            'id' => (string) Str::uuid(),
            'block_id' => $this->blockA->id,
            'floor_id' => $floorA->id,
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 70.0,
            'status' => 'OCCUPIED',
        ]);

        $this->aptB = Apartment::firstOrCreate(['apartment_number' => 'TEST-SB201'], [
            'id' => (string) Str::uuid(),
            'block_id' => $this->blockB->id,
            'floor_id' => $floorB->id,
            'gross_floor_area_sqm' => 85.0,
            'net_usable_area_sqm' => 80.0,
            'status' => 'OCCUPIED',
        ]);

        // 2. Create Users
        $this->residentUser = User::factory()->create();
        $this->otherBlockResidentUser = User::factory()->create();
        $this->managerUser = User::factory()->create();
        $this->otherBlockManagerUser = User::factory()->create();

        // 3. Link Residents to Apartments
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
            'user_id' => $this->otherBlockResidentUser->id,
            'apartment_id' => $this->aptB->id,
            'resident_type' => 'OWNER',
            'is_active' => 1,
            'stay_start_date' => today()->subMonth()->toDateString(),
            'created_at' => now(),
        ]);

        // 4. Roles and Permissions for Managers
        $managerRole = Role::firstOrCreate(['role_code' => 'BUILDING_MANAGER'], ['role_name' => 'Quản lý tòa nhà']);
        DB::table('user_roles')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $this->managerUser->id, 'role_id' => $managerRole->id],
            ['id' => (string) Str::uuid(), 'user_id' => $this->otherBlockManagerUser->id, 'role_id' => $managerRole->id],
        ]);

        foreach (['VIEW', 'CREATE', 'UPDATE', 'ANALYTICS', 'MANAGE'] as $act) {
            $perm = Permission::firstOrCreate(['permission_code' => 'SURVEY:'.$act], [
                'module' => 'SURVEY',
                'permission_name' => 'Survey '.$act,
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
                'department_code' => 'DEPT-SURVEY',
                'department_name' => 'Ban Khảo Sát',
                'created_at' => now(),
            ]);
        }

        // Staff profile scopes: managerUser -> Block A, otherBlockManagerUser -> Block B
        DB::table('staff_profiles')->insert([
            [
                'id' => (string) Str::uuid(),
                'user_id' => $this->managerUser->id,
                'employee_code' => 'MGR-SA-'.Str::random(5),
                'department_id' => $deptId,
                'job_title' => 'Trưởng ban Block A',
                'hire_date' => '2025-01-01',
                'assigned_block_id' => $this->blockA->id,
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $this->otherBlockManagerUser->id,
                'employee_code' => 'MGR-SB-'.Str::random(5),
                'department_id' => $deptId,
                'job_title' => 'Trưởng ban Block B',
                'hire_date' => '2025-01-01',
                'assigned_block_id' => $this->blockB->id,
                'created_at' => now(),
            ],
        ]);

        // 5. Auth Tokens
        $this->residentToken = $this->createSessionToken($this->residentUser);
        $this->otherBlockResidentToken = $this->createSessionToken($this->otherBlockResidentUser);
        $this->managerToken = $this->createSessionToken($this->managerUser);
        $this->otherBlockManagerToken = $this->createSessionToken($this->otherBlockManagerUser);
    }

    protected function createSessionToken(User $user): string
    {
        $token = 'test_survey_token_'.$user->id.'_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
            'is_revoked' => 0,
        ]);

        return $token;
    }

    // 1. Manager tạo khảo sát thành công với cả 4 loại câu hỏi
    public function test_01_manager_can_create_survey_with_all_question_types(): void
    {
        $payload = [
            'survey_code' => 'TEST-SURVEY-Q4-'.Str::random(4),
            'title' => 'Khảo sát chất lượng dịch vụ Block A',
            'description' => 'Mô tả chi tiết khảo sát',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(15)->toDateString(),
            'is_active' => true,
            'questions' => [
                [
                    'question_text' => 'Đánh giá mức độ hài lòng về an ninh (1-5 sao):',
                    'question_type' => 'RATING_1_TO_5',
                    'is_required' => true,
                    'question_order' => 1,
                ],
                [
                    'question_text' => 'Bạn có hài lòng với dịch vụ vệ sinh hành lang?',
                    'question_type' => 'YES_NO',
                    'options_json' => ['Có', 'Không'],
                    'is_required' => true,
                    'question_order' => 2,
                ],
                [
                    'question_text' => 'Khung giờ bạn thường xuyên sử dụng phòng Gym:',
                    'question_type' => 'MULTIPLE_CHOICE',
                    'options_json' => ['Sáng sớm (06:00 - 08:00)', 'Chiều tối (17:00 - 20:00)', 'Khác'],
                    'is_required' => true,
                    'question_order' => 3,
                ],
                [
                    'question_text' => 'Ý kiến đóng góp khác:',
                    'question_type' => 'FREE_TEXT',
                    'is_required' => false,
                    'question_order' => 4,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->postJson('/api/v1/management/surveys', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.title', 'Khảo sát chất lượng dịch vụ Block A');
        $this->assertDatabaseHas('satisfaction_surveys', ['survey_code' => $payload['survey_code']]);
        $createdId = $response->json('data.id');
        $this->assertEquals(4, SurveyQuestion::where('survey_id', $createdId)->count());
    }

    // 2. Validation: Mã khảo sát trùng lặp bị từ chối (422)
    public function test_02_survey_code_uniqueness_validation(): void
    {
        $code = 'UNIQUE-SURVEY-CODE-'.Str::random(4);
        SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => $code,
            'title' => 'Khảo sát hiện có',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        $payload = [
            'survey_code' => $code,
            'title' => 'Khảo sát trùng mã',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'questions' => [
                [
                    'question_text' => 'Câu hỏi 1',
                    'question_type' => 'RATING_1_TO_5',
                    'is_required' => true,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->postJson('/api/v1/management/surveys', $payload);

        $response->assertStatus(422);
    }

    // 3. Validation: Ngày kết thúc trước ngày bắt đầu bị từ chối (422)
    public function test_03_invalid_dates_are_rejected(): void
    {
        $payload = [
            'title' => 'Khảo sát ngày sai',
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->toDateString(), // trước start_date
            'questions' => [
                [
                    'question_text' => 'Câu hỏi 1',
                    'question_type' => 'RATING_1_TO_5',
                    'is_required' => true,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->postJson('/api/v1/management/surveys', $payload);

        $response->assertStatus(422);
    }

    // 4. Cư dân đúng block nhìn thấy khảo sát active
    public function test_04_resident_in_target_block_sees_active_survey(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'TARGET-A-'.Str::random(4),
            'title' => 'Khảo sát riêng Tòa A',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Đánh giá tòa A',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        // Cư dân A (ở block A) gọi API
        $resA = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->getJson('/api/v1/resident/surveys/active');

        $resA->assertStatus(200);
        $idsA = collect($resA->json('data'))->pluck('id')->all();
        $this->assertContains($survey->id, $idsA);

        // Cư dân B (ở block B) gọi API không được thấy khảo sát này
        $resB = $this->withHeader('Authorization', 'Bearer '.$this->otherBlockResidentToken)
            ->getJson('/api/v1/resident/surveys/active');

        $resB->assertStatus(200);
        $idsB = collect($resB->json('data'))->pluck('id')->all();
        $this->assertNotContains($survey->id, $idsB);
    }

    // 5. Cư dân đúng block nộp phiếu thành công
    public function test_05_resident_can_submit_valid_survey_response(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'SUBMIT-A-'.Str::random(4),
            'title' => 'Khảo sát nộp phiếu',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        $q1 = SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Chất lượng thang máy',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        $q2 = SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 2,
            'question_text' => 'Góp ý thêm',
            'question_type' => 'FREE_TEXT',
            'is_required' => false,
        ]);

        $payload = [
            'answers' => [
                $q1->id => 5,
                $q2->id => 'Thang máy chạy êm ái, sạch sẽ.',
            ],
            'idempotency_key' => 'IDEMP-'.Str::random(10),
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/surveys/{$survey->id}/submit", $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('survey_responses', [
            'survey_id' => $survey->id,
            'apartment_id' => $this->aptA->id,
            'resident_user_id' => $this->residentUser->id,
        ]);
        $this->assertDatabaseHas('survey_response_details', [
            'question_id' => $q1->id,
            'numeric_score' => 5,
        ]);
    }

    // 6. Anti-duplicate: Căn hộ không thể nộp phiếu lần 2 (HTTP 409 Conflict)
    public function test_06_duplicate_submission_by_same_apartment_returns_409(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'ANTI-DUP-'.Str::random(4),
            'title' => 'Khảo sát chống trùng',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        $q1 = SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Điểm số chung',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        // Lần 1: Thành công
        $payload = ['answers' => [$q1->id => 4]];
        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/surveys/{$survey->id}/submit", $payload);
        $res1->assertStatus(201);

        // Lần 2: Cùng căn hộ nộp lại -> phải bị chặn HTTP 409
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/surveys/{$survey->id}/submit", $payload);
        $res2->assertStatus(409);
    }

    // 7. Validation: Thiếu câu hỏi bắt buộc bị từ chối (422)
    public function test_07_missing_required_question_is_rejected(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'REQ-TEST-'.Str::random(4),
            'title' => 'Khảo sát câu hỏi bắt buộc',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(5)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        $qRequired = SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Bắt buộc phải trả lời',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        // Không gửi câu trả lời cho câu bắt buộc
        $payload = ['answers' => []];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/surveys/{$survey->id}/submit", $payload);

        $response->assertStatus(422);
    }

    // 8. Validation: Rating ngoài phạm vi 1-5 bị từ chối (422)
    public function test_08_invalid_rating_value_is_rejected(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'RATING-RANGE-'.Str::random(4),
            'title' => 'Khảo sát thang điểm',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(5)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        $q = SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Chấm điểm',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        // Gửi điểm 6 (ngoài 1-5)
        $payload = ['answers' => [$q->id => 6]];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/surveys/{$survey->id}/submit", $payload);

        $response->assertStatus(422);
    }

    // 9. Khảo sát đã hết hạn không cho phép nộp phiếu (422)
    public function test_09_expired_survey_cannot_accept_submissions(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'EXPIRED-'.Str::random(4),
            'title' => 'Khảo sát quá khứ',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->subDays(20)->toDateString(),
            'end_date' => today()->subDays(5)->toDateString(), // Hết hạn 5 ngày trước
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        $q = SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Chấm điểm',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        $payload = ['answers' => [$q->id => 5]];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->postJson("/api/v1/resident/surveys/{$survey->id}/submit", $payload);

        $response->assertStatus(422);
    }

    // 10. OCC: Quản lý cập nhật với version cũ bị 409 Conflict
    public function test_10_optimistic_concurrency_control_prevents_silent_overwrite(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'OCC-SURVEY-'.Str::random(4),
            'title' => 'Tiêu đề gốc',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
            'version' => 2,
        ]);

        // Client A gửi version cũ (version = 1)
        $payload = [
            'version' => 1,
            'title' => 'Tiêu đề sửa từ bản cũ',
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->putJson("/api/v1/management/surveys/{$survey->id}", $payload);

        $response->assertStatus(409);
    }

    // 11. Khóa câu hỏi: Không thể sửa câu hỏi khi khảo sát đã có phiếu trả lời (422)
    public function test_11_cannot_modify_questions_after_receiving_responses(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'LOCK-Q-'.Str::random(4),
            'title' => 'Khảo sát đã có phản hồi',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->subDay()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
            'version' => 1,
        ]);

        $q = SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Câu hỏi ban đầu',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        // Tạo 1 response
        $resp = SurveyResponse::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'apartment_id' => $this->aptA->id,
            'resident_user_id' => $this->residentUser->id,
            'submitted_at' => now(),
        ]);
        SurveyResponseDetail::create([
            'id' => (string) Str::uuid(),
            'response_id' => $resp->id,
            'question_id' => $q->id,
            'numeric_score' => 4,
        ]);

        // Manager cố tình gửi mảng questions mới
        $payload = [
            'version' => 1,
            'title' => 'Đổi tiêu đề',
            'questions' => [
                [
                    'question_text' => 'Câu hỏi thay thế nguy hiểm',
                    'question_type' => 'RATING_1_TO_5',
                    'is_required' => true,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->putJson("/api/v1/management/surveys/{$survey->id}", $payload);

        $response->assertStatus(422);
    }

    // 12. Kiểm tra chính xác công thức CSAT và Average Rating theo mục XVII:
    // Input ratings: 5, 4, 4, 3, 2
    // Satisfied (4 hoặc 5): 3
    // Total: 5
    // CSAT = 3/5 * 100 = 60.0%
    // Average Rating = (5+4+4+3+2)/5 = 18/5 = 3.6
    public function test_12_exact_csat_and_average_rating_formula_calculation(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'CSAT-FORMULA-'.Str::random(4),
            'title' => 'Khảo sát test công thức CSAT',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->subDays(2)->toDateString(),
            'end_date' => today()->addDays(5)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        $q = SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Đánh giá CSAT 1-5 sao',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        $scores = [5, 4, 4, 3, 2];

        foreach ($scores as $index => $score) {
            $u = User::factory()->create();
            $apt = Apartment::create([
                'id' => (string) Str::uuid(),
                'block_id' => $this->blockA->id,
                'floor_id' => $this->aptA->floor_id,
                'apartment_number' => 'CSAT-APT-'.$index.'-'.Str::random(4),
                'gross_floor_area_sqm' => 70.0,
                'net_usable_area_sqm' => 65.0,
            ]);

            $resp = SurveyResponse::create([
                'id' => (string) Str::uuid(),
                'survey_id' => $survey->id,
                'apartment_id' => $apt->id,
                'resident_user_id' => $u->id,
                'submitted_at' => now(),
            ]);

            SurveyResponseDetail::create([
                'id' => (string) Str::uuid(),
                'response_id' => $resp->id,
                'question_id' => $q->id,
                'numeric_score' => $score,
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->getJson("/api/v1/management/surveys/{$survey->id}/analytics");

        $response->assertStatus(200);

        // Kiểm tra KPI survey
        $kpis = $response->json('data.kpis');
        $this->assertEquals(5, $kpis['total_valid_ratings']);
        $this->assertEquals(3, $kpis['satisfied_ratings_count']);
        $this->assertEquals(60.0, $kpis['overall_csat_percentage']);
        $this->assertEquals(3.6, $kpis['overall_average_rating']);

        // Kiểm tra Question stat
        $qStat = $response->json('data.question_stats.0');
        $this->assertEquals(3.6, $qStat['average_rating']);
        $this->assertEquals(60.0, $qStat['csat_percentage']);
        $this->assertEquals(3, $qStat['satisfied_count']);
        $this->assertEquals(1, $qStat['neutral_count']); // Điểm 3
        $this->assertEquals(1, $qStat['dissatisfied_count']); // Điểm 2 (1 lượt đánh giá)
    }

    // 13. An toàn chia cho 0: Khảo sát chưa có phản hồi trả về 0.0% và thông báo rõ ràng
    public function test_13_zero_division_safety_when_survey_has_no_responses(): void
    {
        $survey = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'ZERO-RESP-'.Str::random(4),
            'title' => 'Khảo sát chưa có phản hồi',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(5)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        SurveyQuestion::create([
            'id' => (string) Str::uuid(),
            'survey_id' => $survey->id,
            'question_order' => 1,
            'question_text' => 'Chưa có ai chấm điểm',
            'question_type' => 'RATING_1_TO_5',
            'is_required' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->managerToken)
            ->getJson("/api/v1/management/surveys/{$survey->id}/analytics");

        $response->assertStatus(200);
        $this->assertEquals(0.0, $response->json('data.kpis.overall_csat_percentage'));
        $this->assertEquals(0.0, $response->json('data.kpis.overall_average_rating'));
        $this->assertEquals(0, $response->json('data.kpis.total_responses'));
    }

    public function test_analytics_loads_all_question_details_in_one_query(): void
    {
        $survey = $this->createRegressionSurvey();
        foreach (['RATING_1_TO_5', 'YES_NO', 'MULTIPLE_CHOICE', 'FREE_TEXT'] as $order => $type) {
            SurveyQuestion::create([
                'survey_id' => $survey->id, 'question_order' => $order + 1,
                'question_text' => 'Câu hỏi '.$order, 'question_type' => $type, 'is_required' => false,
            ]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(SurveyAnalyticsService::class)->getSurveyAnalytics($survey, $this->managerUser);
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'survey_response_details'));
            $this->assertCount(1, $queries);
            $this->assertCount(4, $result['question_stats']);
            $this->assertSame(0.0, $result['kpis']['overall_csat_percentage']);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_overview_loads_rating_details_once_across_surveys(): void
    {
        foreach (range(1, 3) as $order) {
            $survey = $this->createRegressionSurvey();
            SurveyQuestion::create([
                'survey_id' => $survey->id, 'question_order' => 1,
                'question_text' => 'Đánh giá', 'question_type' => 'RATING_1_TO_5', 'is_required' => true,
            ]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(SurveyAnalyticsService::class)->getManagementOverviewKpis($this->managerUser);
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'survey_response_details'));
            $this->assertCount(1, $queries);
            $this->assertGreaterThanOrEqual(3, $result['total_surveys']);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_update_rechecks_version_against_locked_row_instead_of_stale_model(): void
    {
        $survey = $this->createRegressionSurvey();
        $stale = SatisfactionSurvey::findOrFail($survey->id);
        $service = app(SatisfactionSurveyService::class);
        $service->updateSurvey($survey, ['version' => 1, 'title' => 'Bản cập nhật đầu'], $this->managerUser);
        try {
            $service->updateSurvey($stale, ['version' => 1, 'title' => 'Bản cũ ghi đè'], $this->managerUser);
            $this->fail('Stale model must not overwrite a newer survey.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('Bản cập nhật đầu', $survey->fresh()->title);
        $this->assertSame(2, $survey->fresh()->version);
    }

    public function test_toggle_advances_current_version_even_when_input_model_is_stale(): void
    {
        $survey = $this->createRegressionSurvey();
        $stale = SatisfactionSurvey::findOrFail($survey->id);
        $service = app(SatisfactionSurveyService::class);
        $service->updateSurvey($survey, ['version' => 1, 'title' => 'Nội dung mới'], $this->managerUser);
        $result = $service->toggleActive($stale, false, $this->managerUser);
        $this->assertSame(3, $result->version);
        $this->assertSame('Nội dung mới', $result->title);
        $this->assertFalse($result->is_active);
    }

    private function createRegressionSurvey(): SatisfactionSurvey
    {
        return SatisfactionSurvey::create([
            'survey_code' => 'REGRESSION-'.Str::random(10), 'title' => 'Khảo sát hồi quy',
            'target_block_id' => $this->blockA->id, 'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(), 'is_active' => true,
            'created_by' => $this->managerUser->id, 'version' => 1,
        ]);
    }

    public function test_audit_failure_rolls_back_survey_edits_and_status_changes(): void
    {
        $survey = $this->createRegressionSurvey();
        $service = app(SatisfactionSurveyService::class);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_logs')) {
                throw new \RuntimeException('QA audit failure');
            }
        });
        foreach (['update', 'toggle'] as $action) {
            try {
                if ($action === 'update') {
                    $service->updateSurvey($survey, ['version' => 1, 'title' => 'Không được lưu'], $this->managerUser);
                } else {
                    $service->toggleActive($survey, false, $this->managerUser);
                }
                $this->fail('Audit failure must abort the transaction.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('QA audit failure', $exception->getMessage());
            }
            $this->assertSame(1, $survey->fresh()->version);
            $this->assertTrue($survey->fresh()->is_active);
            $this->assertSame('Khảo sát hồi quy', $survey->fresh()->title);
            $this->assertSame(0, DB::table('audit_logs')->where('record_id', $survey->id)->count());
        }
    }

    public function test_production_seeder_only_grants_permissions_without_creating_demo_surveys(): void
    {
        $this->assertSame(0, SatisfactionSurvey::count());
        $this->app->instance('env', 'production');

        try {
            $this->artisan('db:seed', ['--class' => SatisfactionSurveySeeder::class, '--force' => true, '--no-interaction' => true])->assertSuccessful();
            $permissionCount = DB::table('permissions')->count();
            $grantCount = DB::table('role_permissions')->count();
            $this->artisan('db:seed', ['--class' => SatisfactionSurveySeeder::class, '--force' => true, '--no-interaction' => true])->assertSuccessful();

            $this->assertSame(0, SatisfactionSurvey::count());
            $this->assertSame(0, SurveyQuestion::count());
            $this->assertSame(5, Permission::where('module', 'SURVEY')->count());
            $this->assertSame($permissionCount, DB::table('permissions')->count());
            $this->assertSame($grantCount, DB::table('role_permissions')->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    // 14. Phân quyền Block: Quản lý Block B không thể xem khảo sát Block A (403)
    public function test_14_manager_assigned_to_block_b_cannot_view_block_a_survey(): void
    {
        $surveyA = SatisfactionSurvey::create([
            'id' => (string) Str::uuid(),
            'survey_code' => 'BLOCK-A-PRIVATE-'.Str::random(4),
            'title' => 'Khảo sát nội bộ Block A',
            'target_block_id' => $this->blockA->id,
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(10)->toDateString(),
            'is_active' => true,
            'created_by' => $this->managerUser->id,
        ]);

        // Manager B cố tình xem chi tiết
        $response = $this->withHeader('Authorization', 'Bearer '.$this->otherBlockManagerToken)
            ->getJson("/api/v1/management/surveys/{$surveyA->id}");

        $response->assertStatus(403);
    }
}
