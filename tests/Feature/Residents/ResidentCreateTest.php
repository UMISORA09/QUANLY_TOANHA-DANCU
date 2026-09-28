<?php

namespace Tests\Feature\Residents;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentCreateTest extends TestCase
{
    /**
     * Tạo tài khoản quản trị viên kèm phiên đăng nhập Bearer token
     */
    protected function createAdminUser(): array
    {
        $user = User::create([
            'username' => 'adm_crt_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_crt_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Test User',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Create Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo tài khoản người dùng bình thường (không có quyền quản trị)
     */
    protected function createNormalUser(): array
    {
        $user = User::create([
            'username' => 'norm_usr_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'norm_usr_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Normal Resident User',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'RESIDENT_MEMBER')->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Normal User Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo cư dân hợp lệ để thêm vào căn hộ
     */
    protected function createCandidateUser(string $name = 'Nguyen Van Candidate'): User
    {
        return User::create([
            'username' => 'cand_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'cand_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => $name,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Tạo căn hộ test độc lập
     */
    protected function createTestApartment(string $code = 'CRTT'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $code.'-'.rand(1000, 9999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * TEST CASE 01: Admin thêm cư dân bình thường
     */
    public function test_case_01_admin_can_create_resident_normally(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC01');
        $u01 = $this->createCandidateUser('Candidate U01');

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'stay_end_date' => null,
            'relationship_to_head' => 'CHILD',
            'occupation' => 'Student',
            'vehicle_count' => 0,
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Thêm cư dân vào căn hộ thành công.',
            ])
            ->assertJsonPath('data.user_id', $u01->id)
            ->assertJsonPath('data.apartment_id', $apt->id)
            ->assertJsonPath('data.resident_type', 'FAMILY_MEMBER')
            ->assertJsonPath('data.is_head_of_household', false)
            ->assertJsonPath('data.relationship_to_head', 'CHILD')
            ->assertJsonPath('data.occupation', 'Student')
            ->assertJsonPath('data.vehicle_count', 0)
            ->assertJsonPath('data.is_active', true);

        // Kiểm tra database row
        $resident = Resident::where('user_id', $u01->id)
            ->where('apartment_id', $apt->id)
            ->first();

        $this->assertNotNull($resident, 'Resident row must exist in database');
        $this->assertNull($resident->deleted_at, 'deleted_at must be NULL');
        $this->assertTrue((bool) $resident->is_active, 'is_active must be true (1)');
        $this->assertEquals(1, Resident::where('user_id', $u01->id)->where('apartment_id', $apt->id)->count());
    }

    /**
     * TEST CASE 02: Invalid user_id (không tồn tại)
     */
    public function test_case_02_invalid_user_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC02');
        $fakeUserId = (string) Str::uuid();

        $payload = [
            'user_id' => $fakeUserId,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseMissing('residents', [
            'apartment_id' => $apt->id,
            'user_id' => $fakeUserId,
        ]);
    }

    /**
     * TEST CASE 03: Invalid apartment_id (không tồn tại)
     */
    public function test_case_03_invalid_apartment_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $u01 = $this->createCandidateUser('Candidate TC03');
        $fakeApartmentId = (string) Str::uuid();

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $fakeApartmentId,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['apartment_id']);

        $this->assertDatabaseMissing('residents', [
            'apartment_id' => $fakeApartmentId,
            'user_id' => $u01->id,
        ]);
    }

    /**
     * TEST CASE 04: Invalid resident_type
     */
    public function test_case_04_invalid_resident_type_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC04');
        $u01 = $this->createCandidateUser('Candidate TC04');

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'INVALID_TYPE',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['resident_type']);

        $this->assertDatabaseMissing('residents', [
            'apartment_id' => $apt->id,
            'user_id' => $u01->id,
        ]);
    }

    /**
     * TEST CASE 05: Invalid relationship_to_head
     */
    public function test_case_05_invalid_relationship_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC05');
        $u01 = $this->createCandidateUser('Candidate TC05');

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'INVALID_RELATIONSHIP',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['relationship_to_head']);

        $this->assertDatabaseMissing('residents', [
            'apartment_id' => $apt->id,
            'user_id' => $u01->id,
        ]);
    }

    /**
     * TEST CASE 06: Invalid date (stay_end_date < stay_start_date)
     */
    public function test_case_06_invalid_date_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC06');
        $u01 = $this->createCandidateUser('Candidate TC06');

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'stay_end_date' => '2026-09-20',
            'relationship_to_head' => 'CHILD',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['stay_end_date']);

        $this->assertDatabaseMissing('residents', [
            'apartment_id' => $apt->id,
            'user_id' => $u01->id,
        ]);
    }

    /**
     * TEST CASE 07: Duplicate resident (same user + same apartment)
     */
    public function test_case_07_duplicate_resident_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC07');
        $u01 = $this->createCandidateUser('Candidate TC07');

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
        ];

        // Lần 1: Thêm thành công
        $firstResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);
        $firstResponse->assertStatus(201);

        // Lần 2: Thêm lại cùng user và apartment -> phải reject
        $secondResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $secondResponse->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);

        $count = Resident::where('user_id', $u01->id)
            ->where('apartment_id', $apt->id)
            ->count();
        $this->assertEquals(1, $count, 'COUNT(user_id=U01 AND apartment_id=A01) must remain exactly 1');
    }

    /**
     * TEST CASE 11: Thêm chủ hộ sau khi đã có chủ hộ
     */
    public function test_case_11_create_household_head_when_already_exists_rejected(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC11');
        $uHead1 = $this->createCandidateUser('Candidate Head 1');
        $uHead2 = $this->createCandidateUser('Candidate Head 2');

        // Tạo R1 làm chủ hộ
        $payload1 = [
            'user_id' => $uHead1->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ];
        $res1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload1);
        $res1->assertStatus(201);

        // Admin cố thêm R2 làm chủ hộ cho cùng căn hộ
        $payload2 = [
            'user_id' => $uHead2->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-02-01',
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ];
        $res2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload2);

        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['is_head_of_household']);

        $activeHeadCount = Resident::where('apartment_id', $apt->id)
            ->where('is_head_of_household', 1)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();

        $this->assertEquals(1, $activeHeadCount, 'Căn hộ chỉ được có tối đa 1 chủ hộ active');
    }

    /**
     * TEST CASE 12: Non-admin truy cập -> 403 Forbidden
     */
    public function test_case_12_non_admin_forbidden(): void
    {
        [, $token] = $this->createNormalUser();
        $apt = $this->createTestApartment('TC12');
        $u01 = $this->createCandidateUser('Candidate TC12');

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);

        $this->assertDatabaseMissing('residents', [
            'apartment_id' => $apt->id,
            'user_id' => $u01->id,
        ]);
    }

    /**
     * TEST CASE 13: Guest (không token) -> 401 Unauthorized
     */
    public function test_case_13_guest_unauthorized(): void
    {
        $apt = $this->createTestApartment('TC13');
        $u01 = $this->createCandidateUser('Candidate TC13');

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
        ];

        $response = $this->postJson('/api/v1/residents', $payload);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'UNAUTHORIZED',
            ]);

        $this->assertDatabaseMissing('residents', [
            'apartment_id' => $apt->id,
            'user_id' => $u01->id,
        ]);
    }

    /**
     * TEST CASE 14: Soft-deleted resident behavior
     */
    public function test_case_14_soft_deleted_resident_behavior(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC14');
        $u07 = $this->createCandidateUser('Candidate U07');

        // Tạo resident ban đầu
        $resident = Resident::create([
            'user_id' => $u07->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);

        // Xóa mềm resident
        $resident->delete();
        $this->assertSoftDeleted('residents', ['id' => $resident->id]);

        // Thử thêm lại U07 + A07 qua API
        $payload = [
            'user_id' => $u07->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        // Ghi lại behavior thực tế để báo cáo chi tiết
        $status = $response->status();
        $body = $response->json();

        echo PHP_EOL.'============================================================'.PHP_EOL;
        echo 'TEST CASE 14: SOFT DELETED RESIDENT'.PHP_EOL;
        echo "HTTP status: {$status}".PHP_EOL;
        echo 'Response body: '.json_encode($body, JSON_UNESCAPED_UNICODE).PHP_EOL;
        echo '============================================================'.PHP_EOL;

        // Kiểm tra xem database constraint uq_resident_apt có chặn duplicate không
        // Nếu MySQL chặn: response sẽ là 500 do QueryException Duplicate Entry (hoặc 409/422 nếu có catch)
        $this->assertTrue(in_array($status, [409, 422, 500], true), "Actual HTTP status: {$status}");

        // Tổng số record (bao gồm trashed) không được vượt quá 1 nếu unique constraint chặn, hoặc báo cáo chính xác
        $totalRecordsIncludingTrashed = Resident::withTrashed()
            ->where('user_id', $u07->id)
            ->where('apartment_id', $apt->id)
            ->count();

        $this->assertEquals(1, $totalRecordsIncludingTrashed, 'Do UNIQUE(user_id, apartment_id) trên DB, không thể tạo record thứ hai');
    }

    /**
     * KIỂM TRA BẢO VỆ MASS ASSIGNMENT
     */
    public function test_case_15_mass_assignment_protection(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC15');
        $u01 = $this->createCandidateUser('Candidate TC15');

        $fakeUuid = '00000000-0000-0000-0000-000000000001';
        $fakeCreatedAt = '2019-01-01 00:00:00';
        $fakeUpdatedAt = '2019-01-01 00:00:00';
        $fakeDeletedAt = '2019-01-01 00:00:00';

        $payload = [
            'id' => $fakeUuid,
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
            'created_at' => $fakeCreatedAt,
            'updated_at' => $fakeUpdatedAt,
            'deleted_at' => $fakeDeletedAt,
            'unauthorized_field' => 'malicious_input',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201);

        $createdResident = Resident::where('user_id', $u01->id)
            ->where('apartment_id', $apt->id)
            ->first();

        $this->assertNotNull($createdResident);
        $this->assertNotEquals($fakeUuid, $createdResident->id, 'Client ID must NOT override generated UUID');
        $this->assertNotEquals($fakeCreatedAt, (string) $createdResident->created_at, 'created_at must not be overwritten');
        $this->assertNull($createdResident->deleted_at, 'deleted_at must remain NULL');
    }
}
