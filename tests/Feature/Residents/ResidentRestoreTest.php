<?php

namespace Tests\Feature\Residents;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Services\ResidentService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentRestoreTest extends TestCase
{
    /**
     * Tạo tài khoản quản trị viên kèm phiên đăng nhập Bearer token
     */
    protected function createAdminUser(): array
    {
        do {
            $phone = '09'.rand(10000000, 99999999);
        } while (DB::table('users')->where('phone_number', $phone)->exists());

        $user = User::create([
            'username' => 'adm_rst_'.Str::random(8),
            'phone_number' => $phone,
            'email' => 'adm_rst_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Restore Tester',
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
            'device_name' => 'PHPUnit Restore Test Agent',
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
        do {
            $phone = '09'.rand(10000000, 99999999);
        } while (DB::table('users')->where('phone_number', $phone)->exists());

        return User::create([
            'username' => 'cand_'.Str::random(8),
            'phone_number' => $phone,
            'email' => 'cand_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => $name,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Tạo căn hộ test độc lập
     */
    protected function createTestApartment(string $code = 'RST'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();
        $blockId = $block->id ?? (string) Str::uuid();

        do {
            $aptNum = substr($code.'-'.rand(1000, 9999).'-'.Str::upper(Str::random(4)), 0, 30);
        } while (DB::table('apartments')->where('block_id', $blockId)->where('apartment_number', $aptNum)->exists());

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $blockId,
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $aptNum,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * Kịch bản 1: Tạo nhân khẩu lần đầu thành công
     */
    public function test_01_create_resident_initially_success(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC01');
        $u01 = $this->createCandidateUser('Candidate TC01');

        $payload = [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('residents', [
            'user_id' => $u01->id,
            'apartment_id' => $apt->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * Kịch bản 2: Soft delete nhân khẩu
     */
    public function test_02_soft_delete_resident_success(): void
    {
        $apt = $this->createTestApartment('TC02');
        $u02 = $this->createCandidateUser('Candidate TC02');

        $resident = Resident::create([
            'user_id' => $u02->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);

        $resident->delete();

        $this->assertSoftDeleted('residents', [
            'id' => $resident->id,
        ]);
        $this->assertNull(Resident::find($resident->id));
        $this->assertNotNull(Resident::withTrashed()->find($resident->id));
    }

    /**
     * Kịch bản 3: Gửi lại request tạo cùng user_id và apartment_id trả về 201 Created
     */
    public function test_03_re_adding_soft_deleted_resident_returns_201_created(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC03');
        $u03 = $this->createCandidateUser('Candidate TC03');

        $resident = Resident::create([
            'user_id' => $u03->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);
        $resident->delete();

        $payload = [
            'user_id' => $u03->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-06-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Thêm cư dân vào căn hộ thành công.',
        ]);
    }

    /**
     * Kịch bản 4: Xác nhận bản ghi cũ được restore (deleted_at is null, cùng ID)
     */
    public function test_04_restored_record_has_null_deleted_at_and_same_id(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC04');
        $u04 = $this->createCandidateUser('Candidate TC04');

        $originalResident = Resident::create([
            'user_id' => $u04->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);
        $originalId = $originalResident->id;
        $originalResident->delete();

        $payload = [
            'user_id' => $u04->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-07-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201);
        $responseData = $response->json('data');

        $this->assertEquals($originalId, $responseData['id'], 'ID của bản ghi được restore phải trùng với bản ghi cũ');

        $restoredInDb = Resident::find($originalId);
        $this->assertNotNull($restoredInDb, 'Bản ghi phải truy vấn được bình thường qua Eloquent find()');
        $this->assertNull($restoredInDb->deleted_at, 'Trường deleted_at phải là null');
    }

    /**
     * Kịch bản 5: Xác nhận không INSERT bản ghi thứ hai (tổng số record bao gồm trashed vẫn là 1)
     */
    public function test_05_re_adding_soft_deleted_resident_does_not_insert_duplicate_row(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC05');
        $u05 = $this->createCandidateUser('Candidate TC05');

        $resident = Resident::create([
            'user_id' => $u05->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);
        $resident->delete();

        $payload = [
            'user_id' => $u05->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-08-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload)
            ->assertStatus(201);

        $totalRecords = Resident::withTrashed()
            ->where('user_id', $u05->id)
            ->where('apartment_id', $apt->id)
            ->count();

        $this->assertSame(1, $totalRecords, 'Do UNIQUE(user_id, apartment_id), tổng số bản ghi trong database chỉ được đúng là 1');
    }

    /**
     * Kịch bản 6: Xác nhận dữ liệu nghiệp vụ được cập nhật đúng sau khi restore
     */
    public function test_06_re_adding_soft_deleted_resident_updates_business_fields(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC06');
        $u06 = $this->createCandidateUser('Candidate TC06');

        $resident = Resident::create([
            'user_id' => $u06->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'stay_end_date' => '2026-05-01',
            'relationship_to_head' => 'CHILD',
            'occupation' => 'Học sinh',
            'vehicle_count' => 0,
            'is_active' => true,
        ]);
        $resident->delete();

        $payload = [
            'user_id' => $u06->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-01',
            'stay_end_date' => '2027-09-01',
            'relationship_to_head' => 'TENANT',
            'occupation' => 'Kỹ sư phần mềm',
            'vehicle_count' => 2,
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201);

        $refreshed = Resident::find($resident->id);
        $this->assertSame('TENANT', $refreshed->resident_type);
        $this->assertStringStartsWith('2026-09-01', (string) $refreshed->stay_start_date);
        $this->assertStringStartsWith('2027-09-01', (string) $refreshed->stay_end_date);
        $this->assertSame('TENANT', $refreshed->relationship_to_head);
        $this->assertSame('Kỹ sư phần mềm', $refreshed->occupation);
        $this->assertSame(2, $refreshed->vehicle_count);
        $this->assertTrue((bool) $refreshed->is_active);
    }

    /**
     * Kịch bản 7: Xác nhận nhân khẩu active bị thêm trùng vẫn bị từ chối 422
     */
    public function test_07_adding_already_active_resident_is_rejected_with_422(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC07');
        $u07 = $this->createCandidateUser('Candidate TC07');

        Resident::create([
            'user_id' => $u07->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);

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

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    /**
     * Kịch bản 8: Xác nhận không khôi phục chủ hộ gây trùng chủ hộ (trả về 422)
     */
    public function test_08_restoring_as_household_head_when_another_active_head_exists_is_rejected_with_422(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC08');
        $uHead = $this->createCandidateUser('Candidate UHead');
        $uMember = $this->createCandidateUser('Candidate UMember');

        // Chủ hộ hiện tại đang active
        Resident::create([
            'user_id' => $uHead->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        // Cư dân cũ đã soft-delete
        $oldMember = Resident::create([
            'user_id' => $uMember->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);
        $oldMember->delete();

        // Cố gắng khôi phục thành chủ hộ
        $payload = [
            'user_id' => $uMember->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-09-01',
            'relationship_to_head' => 'SELF',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_head_of_household']);

        // Bản ghi cũ vẫn phải là soft-deleted, không bị restore nhầm
        $this->assertSoftDeleted('residents', ['id' => $oldMember->id]);
    }

    /**
     * Kịch bản 9: Xác nhận các apartment khác nhau không ảnh hưởng lẫn nhau
     */
    public function test_09_different_apartments_for_same_user_do_not_interfere(): void
    {
        [, $token] = $this->createAdminUser();
        $apt1 = $this->createTestApartment('APT1');
        $apt2 = $this->createTestApartment('APT2');
        $user = $this->createCandidateUser('Candidate MultiApt');

        // User đang active ở Apt1
        Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apt1->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        // User đã soft-deleted ở Apt2
        $resApt2 = Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apt2->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);
        $resApt2->delete();

        // Thêm lại vào Apt2
        $payload = [
            'user_id' => $user->id,
            'apartment_id' => $apt2->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-10-01',
            'relationship_to_head' => 'TENANT',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201);

        // Cả 2 apartment đều có bản ghi active riêng biệt
        $this->assertDatabaseHas('residents', [
            'apartment_id' => $apt1->id,
            'user_id' => $user->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('residents', [
            'apartment_id' => $apt2->id,
            'user_id' => $user->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * Kịch bản 10: Xác nhận transaction rollback khi bước khôi phục gặp lỗi
     */
    public function test_10_transaction_rollback_when_restore_encounters_error(): void
    {
        $apt = $this->createTestApartment('TC10');
        $u10 = $this->createCandidateUser('Candidate TC10');

        $resident = Resident::create([
            'user_id' => $u10->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);
        $resident->delete();
        $this->assertSoftDeleted('residents', ['id' => $resident->id]);

        $service = app(ResidentService::class);

        // Cố tình gửi payload gây lỗi DB bên trong transaction (ví dụ resident_type vượt giới hạn hoặc trigger exception)
        try {
            DB::transaction(function () use ($service, $u10, $apt) {
                $service->create([
                    'user_id' => $u10->id,
                    'apartment_id' => $apt->id,
                    'resident_type' => 'FAMILY_MEMBER',
                    'is_head_of_household' => false,
                    'stay_start_date' => '2026-10-01',
                    'relationship_to_head' => 'CHILD',
                ]);

                throw new Exception('Simulated crash after restore inside outer transaction');
            });
        } catch (Exception $e) {
            $this->assertSame('Simulated crash after restore inside outer transaction', $e->getMessage());
        }

        // Sau khi transaction rollback, bản ghi vẫn phải ở trạng thái soft-deleted
        $this->assertSoftDeleted('residents', ['id' => $resident->id]);
    }

    /**
     * Kịch bản 11: Kiểm tra hành vi khôi phục tuần tự và bảo vệ độc quyền
     */
    public function test_11_sequential_restore_idempotency_and_protection(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->createTestApartment('TC11');
        $u11 = $this->createCandidateUser('Candidate TC11');

        $resident = Resident::create([
            'user_id' => $u11->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);
        $resident->delete();

        $payload = [
            'user_id' => $u11->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-11-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ];

        // Lần 1: Khôi phục thành công -> 201
        $res1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);
        $res1->assertStatus(201);

        // Lần 2: Vì bản ghi đã active, yêu cầu tiếp theo phải bị từ chối 422 (không được phép duplicate)
        $res2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);
        $res2->assertStatus(422);

        // Kiểm tra tổng số bản ghi vẫn chính xác là 1
        $totalRecords = Resident::withTrashed()
            ->where('user_id', $u11->id)
            ->where('apartment_id', $apt->id)
            ->count();
        $this->assertSame(1, $totalRecords);
    }
}
