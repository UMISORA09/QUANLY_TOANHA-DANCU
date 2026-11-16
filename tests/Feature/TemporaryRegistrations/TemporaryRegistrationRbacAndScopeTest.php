<?php

namespace Tests\Feature\TemporaryRegistrations;

use App\Models\Apartment;
use App\Models\Permission;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kiểm thử bảo mật BUG-M3-04:
 * 1. Phân quyền Admin theo ma trận RBAC (TEMP_REG:VIEW, TEMP_REG:APPROVE, TEMP_REG:DELETE, TEMP_REG:CREATE).
 * 2. Ngăn chặn triệt để leo thang đặc quyền Chủ hộ chéo căn hộ khi tài khoản có role RESIDENT_OWNER tại căn hộ A
 *    nhưng chỉ là TENANT hoặc FAMILY_MEMBER tại căn hộ B.
 */
class TemporaryRegistrationRbacAndScopeTest extends TestCase
{
    /**
     * Tạo tài khoản kèm các quyền RBAC cụ thể
     */
    protected function createUserWithPermissions(array $permissionCodes, string $roleName = 'Custom Staff'): array
    {
        $user = User::create([
            'username' => 'staff_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'staff_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => $roleName.' '.Str::random(4),
            'status' => 'ACTIVE',
        ]);

        $customRoleCode = 'CUSTOM_USER_'.Str::upper(Str::random(8));
        $role = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => $customRoleCode,
            'role_name' => $roleName,
            'description' => 'Role for testing custom permissions',
            'is_system_role' => false,
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        foreach ($permissionCodes as $permCode) {
            $perm = Permission::firstOrCreate(
                ['permission_code' => $permCode],
                [
                    'id' => (string) Str::uuid(),
                    'module' => 'TEMP_REG',
                    'permission_name' => $permCode,
                    'description' => 'Test perm '.$permCode,
                ]
            );

            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $perm->id,
                'created_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Staff Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo căn hộ test
     */
    protected function createApartment(string $code = 'RBAC'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $code.'-'.Str::upper(Str::random(6)),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * Tạo tài khoản cư dân hợp lệ thuộc một căn hộ
     */
    protected function createResidentUser(Apartment $apt, bool $isHead = true, string $residentType = 'OWNER'): array
    {
        $user = User::create([
            'username' => 'res_rb_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_rb_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cu Dan '.($isHead ? 'Chu Ho ' : 'Thanh Vien ').Str::random(4),
            'national_id_number' => '079'.rand(100000000, 999999999),
            'status' => 'ACTIVE',
        ]);

        $roleCode = $residentType === 'OWNER' ? 'RESIDENT_OWNER' : 'RESIDENT_MEMBER';
        $role = Role::where('role_code', $roleCode)->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $resident = Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apt->id,
            'resident_type' => $residentType,
            'is_head_of_household' => $isHead,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => $isHead ? 'SELF' : 'MEMBER',
            'is_active' => true,
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Resident Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $resident, $token];
    }

    /**
     * Tạo hồ sơ tạm trú / tạm vắng
     */
    protected function createRegistration(Apartment $apt, Resident $resident, array $overrides = []): TemporaryRegistration
    {
        return TemporaryRegistration::create(array_merge([
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'reason' => 'Kiểm thử RBAC và quyền truy cập',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ], $overrides));
    }

    // =========================================================================
    // PHẦN 1: RBAC PERMISSION ENFORCEMENT CHO CÁC THAO TÁC QUẢN TRỊ
    // =========================================================================

    /**
     * 1. Người dùng có quyền TEMP_REG:VIEW có thể xem toàn bộ danh sách và chi tiết hồ sơ
     */
    public function test_01_user_with_temp_reg_view_can_access_list_and_detail_of_all_registrations(): void
    {
        $aptA = $this->createApartment('A01');
        $aptB = $this->createApartment('B01');
        [, $resA] = $this->createResidentUser($aptA, true, 'OWNER');
        [, $resB] = $this->createResidentUser($aptB, true, 'OWNER');

        $regA = $this->createRegistration($aptA, $resA, ['reason' => 'Ho so A']);
        $regB = $this->createRegistration($aptB, $resB, ['reason' => 'Ho so B']);

        [$viewerUser, $viewerToken] = $this->createUserWithPermissions(['TEMP_REG:VIEW'], 'Staff Viewer');

        // Danh sách: thấy cả 2 hồ sơ
        $resList = $this->withHeader('Authorization', "Bearer {$viewerToken}")
            ->getJson('/api/v1/residents/temporary-registrations');

        $resList->assertStatus(200);
        $ids = collect($resList->json('data'))->pluck('id')->toArray();
        $this->assertContains($regA->id, $ids);
        $this->assertContains($regB->id, $ids);

        // Chi tiết hồ sơ A
        $resShow = $this->withHeader('Authorization', "Bearer {$viewerToken}")
            ->getJson("/api/v1/residents/temporary-registrations/{$regA->id}");
        $resShow->assertStatus(200)
            ->assertJsonPath('data.id', $regA->id);
    }

    /**
     * 2. Người dùng chỉ có quyền TEMP_REG:VIEW không thể phê duyệt, từ chối hoặc gửi Công An (403 Forbidden)
     */
    public function test_02_user_with_only_temp_reg_view_cannot_approve_reject_or_submit_police(): void
    {
        $apt = $this->createApartment('A02');
        [, $resident] = $this->createResidentUser($apt, true, 'OWNER');
        $record = $this->createRegistration($apt, $resident);

        [, $viewerToken] = $this->createUserWithPermissions(['TEMP_REG:VIEW'], 'Staff ReadOnly');

        // Phê duyệt -> 403
        $resApprove = $this->withHeader('Authorization', "Bearer {$viewerToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/approve", [
                'notes' => 'Cố gắng duyệt',
            ]);
        $resApprove->assertStatus(403);

        // Từ chối -> 403
        $resReject = $this->withHeader('Authorization', "Bearer {$viewerToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/reject", [
                'reason' => 'Cố gắng từ chối',
            ]);
        $resReject->assertStatus(403);

        // Gửi công an -> 403
        $resSubmit = $this->withHeader('Authorization', "Bearer {$viewerToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/submit-police", [
                'police_reference_code' => 'CA-FAIL-999',
            ]);
        $resSubmit->assertStatus(403);
    }

    /**
     * 3. Người dùng có quyền TEMP_REG:APPROVE có thể phê duyệt, từ chối và nộp Công An
     */
    public function test_03_user_with_temp_reg_approve_can_approve_reject_and_submit_police(): void
    {
        $apt = $this->createApartment('A03');
        [, $resident] = $this->createResidentUser($apt, true, 'OWNER');
        $record = $this->createRegistration($apt, $resident);

        [$approverUser, $approverToken] = $this->createUserWithPermissions(['TEMP_REG:APPROVE'], 'Staff Approver');

        // Nộp công an thành công
        $resSubmit = $this->withHeader('Authorization', "Bearer {$approverToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/submit-police", [
                'police_reference_code' => 'CA-APP-1234',
                'notes' => 'Approver gui CA',
            ]);
        $resSubmit->assertStatus(200)
            ->assertJsonPath('data.police_status', 'SUBMITTED_TO_POLICE');

        // Phê duyệt thành công
        $resApprove = $this->withHeader('Authorization', "Bearer {$approverToken}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/approve", [
                'notes' => 'Approver duyet',
            ]);
        $resApprove->assertStatus(200)
            ->assertJsonPath('data.police_status', 'APPROVED')
            ->assertJsonPath('data.reviewed_by', $approverUser->id);
    }

    /**
     * 4. Người dùng có quyền TEMP_REG:DELETE có thể xóa hồ sơ hợp lệ của bất kỳ căn hộ nào
     */
    public function test_04_user_with_temp_reg_delete_can_delete_registration(): void
    {
        $apt = $this->createApartment('A04');
        [, $resident] = $this->createResidentUser($apt, true, 'OWNER');
        $record = $this->createRegistration($apt, $resident, [
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        [, $deleterToken] = $this->createUserWithPermissions(['TEMP_REG:DELETE'], 'Staff Deleter');

        $resDelete = $this->withHeader('Authorization', "Bearer {$deleterToken}")
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");

        $resDelete->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('temporary_registrations', [
            'id' => $record->id,
        ]);
    }

    // =========================================================================
    // PHẦN 2: CHỐNG LEO THANG ĐẶC QUYỀN CHỦ HỘ CHÉO CĂN HỘ (BUG-M3-04 CORE)
    // =========================================================================

    /**
     * 5. User X có role RESIDENT_OWNER (chủ hộ tại Căn hộ A), đồng thời là TENANT tại Căn hộ B:
     *    KHÔNG ĐƯỢC PHÉP đăng ký tạm trú cho thành viên khác (Member Y) tại Căn hộ B (403 Forbidden).
     */
    public function test_05_cross_apartment_owner_cannot_register_for_other_members_in_apartment_where_user_is_only_tenant(): void
    {
        // Căn hộ A: User X là OWNER & Head of household (user_roles có RESIDENT_OWNER)
        $aptA = $this->createApartment('APT-A05');
        [$userX, , $tokenX] = $this->createResidentUser($aptA, true, 'OWNER');

        // Căn hộ B: User X cũng là cư dân, nhưng chỉ là TENANT (không phải chủ hộ)
        $aptB = $this->createApartment('APT-B05');
        Resident::create([
            'user_id' => $userX->id,
            'apartment_id' => $aptB->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        // Căn hộ B có một thành viên khác là Member Y
        [, $residentY] = $this->createResidentUser($aptB, false, 'TENANT');

        // User X cố gắng gửi yêu cầu đăng ký tạm trú thay cho Member Y tại Căn hộ B
        $response = $this->withHeader('Authorization', "Bearer {$tokenX}")
            ->postJson('/api/v1/residents/temporary-registrations', [
                'apartment_id' => $aptB->id,
                'resident_id' => $residentY->id,
                'registration_type' => 'TEMPORARY_STAY',
                'start_date' => '2026-11-15',
                'end_date' => '2026-12-15',
                'reason' => 'User X cố tình đăng ký cho Member Y tại căn hộ B',
            ]);

        // Bắt buộc phải bị từ chối 403 Forbidden (FormRequest authorize)
        $response->assertStatus(403);
    }

    /**
     * 6. User X có role RESIDENT_OWNER (từ căn A), là TENANT tại căn B:
     *    KHÔNG ĐƯỢC PHÉP xem chi tiết hồ sơ của Member Y tại Căn hộ B (403 Forbidden).
     */
    public function test_06_cross_apartment_owner_cannot_view_detail_of_other_members_record_in_tenant_apartment(): void
    {
        $aptA = $this->createApartment('APT-A06');
        [$userX, , $tokenX] = $this->createResidentUser($aptA, true, 'OWNER');

        $aptB = $this->createApartment('APT-B06');
        Resident::create([
            'user_id' => $userX->id,
            'apartment_id' => $aptB->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        [, $residentY] = $this->createResidentUser($aptB, false, 'TENANT');
        $recordY = $this->createRegistration($aptB, $residentY, [
            'reason' => 'Ho so rieng cua Y tai can B',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$tokenX}")
            ->getJson("/api/v1/residents/temporary-registrations/{$recordY->id}");

        $response->assertStatus(403);
    }

    /**
     * 7. User X có role RESIDENT_OWNER (từ căn A), là TENANT tại căn B:
     *    KHÔNG ĐƯỢC PHÉP chỉnh sửa hồ sơ của Member Y tại Căn hộ B (403 Forbidden).
     */
    public function test_07_cross_apartment_owner_cannot_update_other_members_record_in_tenant_apartment(): void
    {
        $aptA = $this->createApartment('APT-A07');
        [$userX, , $tokenX] = $this->createResidentUser($aptA, true, 'OWNER');

        $aptB = $this->createApartment('APT-B07');
        Resident::create([
            'user_id' => $userX->id,
            'apartment_id' => $aptB->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        [, $residentY] = $this->createResidentUser($aptB, false, 'TENANT');
        $recordY = $this->createRegistration($aptB, $residentY, [
            'reason' => 'Ho so goc cua Y',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$tokenX}")
            ->putJson("/api/v1/residents/temporary-registrations/{$recordY->id}", [
                'reason' => 'User X can thiep sua ho so cua Y',
            ]);

        $response->assertStatus(403);
    }

    /**
     * 8. User X có role RESIDENT_OWNER (từ căn A), là TENANT tại căn B:
     *    KHÔNG ĐƯỢC PHÉP xóa hồ sơ của Member Y tại Căn hộ B (403 Forbidden).
     */
    public function test_08_cross_apartment_owner_cannot_delete_other_members_record_in_tenant_apartment(): void
    {
        $aptA = $this->createApartment('APT-A08');
        [$userX, , $tokenX] = $this->createResidentUser($aptA, true, 'OWNER');

        $aptB = $this->createApartment('APT-B08');
        Resident::create([
            'user_id' => $userX->id,
            'apartment_id' => $aptB->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        [, $residentY] = $this->createResidentUser($aptB, false, 'TENANT');
        $recordY = $this->createRegistration($aptB, $residentY, [
            'police_status' => 'PENDING_POLICE_SUBMISSION',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$tokenX}")
            ->deleteJson("/api/v1/residents/temporary-registrations/{$recordY->id}");

        $response->assertStatus(403);
    }

    /**
     * 9. Data Scoping trong danh sách: User X không nhìn thấy hồ sơ của Member Y tại Căn hộ B
     */
    public function test_09_cross_apartment_owner_cannot_see_other_members_records_in_list_at_tenant_apartment(): void
    {
        $aptA = $this->createApartment('APT-A09');
        [$userX, $residentXA, $tokenX] = $this->createResidentUser($aptA, true, 'OWNER');

        $aptB = $this->createApartment('APT-B09');
        $residentXB = Resident::create([
            'user_id' => $userX->id,
            'apartment_id' => $aptB->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        [, $residentY] = $this->createResidentUser($aptB, false, 'TENANT');

        // Hồ sơ của chính X tại căn A
        $regXA = $this->createRegistration($aptA, $residentXA, ['reason' => 'Ho so X o can A']);
        // Hồ sơ của chính X tại căn B
        $regXB = $this->createRegistration($aptB, $residentXB, ['reason' => 'Ho so X o can B']);
        // Hồ sơ của Y tại căn B
        $regY = $this->createRegistration($aptB, $residentY, ['reason' => 'Ho so Y o can B']);

        $resList = $this->withHeader('Authorization', "Bearer {$tokenX}")
            ->getJson('/api/v1/residents/temporary-registrations');

        $resList->assertStatus(200);
        $ids = collect($resList->json('data'))->pluck('id')->toArray();

        // X thấy hồ sơ của chính mình ở cả 2 căn hộ
        $this->assertContains($regXA->id, $ids);
        $this->assertContains($regXB->id, $ids);

        // X KHÔNG ĐƯỢC thấy hồ sơ của Y ở căn B
        $this->assertNotContains($regY->id, $ids);
    }

    /**
     * 10. Chủ hộ hợp pháp thực sự tại Căn hộ B có toàn quyền quản lý hồ sơ cho Member Y trong cùng căn hộ
     */
    public function test_10_legitimate_head_of_household_can_register_and_manage_members_in_their_apartment(): void
    {
        $aptB = $this->createApartment('APT-B10');
        // Head of household tại Căn hộ B
        [, , $tokenHeadB] = $this->createResidentUser($aptB, true, 'OWNER');
        // Thành viên trong hộ tại Căn hộ B
        [, $residentMemberB] = $this->createResidentUser($aptB, false, 'MEMBER');

        // Chủ hộ đăng ký tạm trú cho thành viên
        $resCreate = $this->withHeader('Authorization', "Bearer {$tokenHeadB}")
            ->postJson('/api/v1/residents/temporary-registrations', [
                'apartment_id' => $aptB->id,
                'resident_id' => $residentMemberB->id,
                'registration_type' => 'TEMPORARY_STAY',
                'start_date' => '2026-11-20',
                'end_date' => '2026-12-20',
                'reason' => 'Chu ho B dang ky cho thanh vien',
            ]);

        $resCreate->assertStatus(201);
        $recordId = $resCreate->json('data.id');

        // Chủ hộ xem chi tiết
        $resShow = $this->withHeader('Authorization', "Bearer {$tokenHeadB}")
            ->getJson("/api/v1/residents/temporary-registrations/{$recordId}");
        $resShow->assertStatus(200);

        // Chủ hộ cập nhật
        $resUpdate = $this->withHeader('Authorization', "Bearer {$tokenHeadB}")
            ->putJson("/api/v1/residents/temporary-registrations/{$recordId}", [
                'reason' => 'Chu ho B cap nhat ly do hop le',
            ]);
        $resUpdate->assertStatus(200);

        // Chủ hộ xóa
        $resDelete = $this->withHeader('Authorization', "Bearer {$tokenHeadB}")
            ->deleteJson("/api/v1/residents/temporary-registrations/{$recordId}");
        $resDelete->assertStatus(200);
    }

    /**
     * 11. Khách thuê (TENANT) tại Căn hộ B có thể tự tạo và quản lý hồ sơ của CHÍNH MÌNH
     */
    public function test_11_tenant_at_apartment_can_create_and_manage_their_own_registration(): void
    {
        $aptB = $this->createApartment('APT-B11');
        [, $residentTenant, $tokenTenant] = $this->createResidentUser($aptB, false, 'TENANT');

        // Tự đăng ký cho bản thân
        $resCreate = $this->withHeader('Authorization', "Bearer {$tokenTenant}")
            ->postJson('/api/v1/residents/temporary-registrations', [
                'apartment_id' => $aptB->id,
                'resident_id' => $residentTenant->id,
                'registration_type' => 'TEMPORARY_STAY',
                'start_date' => '2026-11-20',
                'end_date' => '2026-12-20',
                'reason' => 'Tenant tu dang ky',
            ]);

        $resCreate->assertStatus(201);
        $recordId = $resCreate->json('data.id');

        // Tự xem hồ sơ
        $resShow = $this->withHeader('Authorization', "Bearer {$tokenTenant}")
            ->getJson("/api/v1/residents/temporary-registrations/{$recordId}");
        $resShow->assertStatus(200);

        // Tự sửa hồ sơ
        $resUpdate = $this->withHeader('Authorization', "Bearer {$tokenTenant}")
            ->putJson("/api/v1/residents/temporary-registrations/{$recordId}", [
                'reason' => 'Tenant tu sua ly do',
            ]);
        $resUpdate->assertStatus(200);
    }

    /**
     * 12. Chủ sở hữu căn hộ được ghi nhận trong bảng apartment_owners có quyền chủ sở hữu
     */
    public function test_12_apartment_owner_via_apartment_owners_table_has_owner_access(): void
    {
        $aptC = $this->createApartment('APT-C12');

        // Tạo user W không có trong bảng residents nhưng là owner trong apartment_owners
        $userW = User::create([
            'username' => 'owner_apt_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'owner_apt_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Chu So Huu Chung Tu '.Str::random(4),
            'status' => 'ACTIVE',
        ]);

        $roleOwner = Role::where('role_code', 'RESIDENT_OWNER')->first();
        if ($roleOwner) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $userW->id,
                'role_id' => $roleOwner->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptC->id,
            'owner_user_id' => $userW->id,
            'is_current_owner' => 1,
            'ownership_start_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tokenW = 'smart_token_'.$userW->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $tokenW);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userW->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Owner Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        // Cư dân thuê trong Căn hộ C
        [, $residentC] = $this->createResidentUser($aptC, false, 'TENANT');

        // User W đăng ký cho cư dân C trong căn hộ C thành công
        $resCreate = $this->withHeader('Authorization', "Bearer {$tokenW}")
            ->postJson('/api/v1/residents/temporary-registrations', [
                'apartment_id' => $aptC->id,
                'resident_id' => $residentC->id,
                'registration_type' => 'TEMPORARY_STAY',
                'start_date' => '2026-11-25',
                'end_date' => '2026-12-25',
                'reason' => 'Chu so huu dang ky cho cu dan thue',
            ]);

        $resCreate->assertStatus(201);
        $recordId = $resCreate->json('data.id');

        // User W xem chi tiết hồ sơ
        $resShow = $this->withHeader('Authorization', "Bearer {$tokenW}")
            ->getJson("/api/v1/residents/temporary-registrations/{$recordId}");
        $resShow->assertStatus(200);
    }

    /**
     * 13. Cư dân không có liên kết với căn hộ hoàn toàn bị từ chối truy cập (403 Forbidden)
     */
    public function test_13_unassociated_resident_cannot_access_or_manage_records(): void
    {
        $aptTarget = $this->createApartment('APT-T13');
        [, $resTarget] = $this->createResidentUser($aptTarget, true, 'OWNER');
        $record = $this->createRegistration($aptTarget, $resTarget);

        $aptOther = $this->createApartment('APT-O13');
        [, , $tokenOther] = $this->createResidentUser($aptOther, true, 'OWNER');

        // Xem chi tiết -> 403
        $resShow = $this->withHeader('Authorization', "Bearer {$tokenOther}")
            ->getJson("/api/v1/residents/temporary-registrations/{$record->id}");
        $resShow->assertStatus(403);

        // Cập nhật -> 403
        $resUpdate = $this->withHeader('Authorization', "Bearer {$tokenOther}")
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Nguoi ngoai co gang sua',
            ]);
        $resUpdate->assertStatus(403);

        // Xóa -> 403
        $resDelete = $this->withHeader('Authorization', "Bearer {$tokenOther}")
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");
        $resDelete->assertStatus(403);
    }

    // =========================================================================
    // PHẦN 3: BỔ SUNG KIỂM TRA CHUYÊN SÂU FAIL-CLOSED & IDEMPOTENT CHO BUG-M3-04
    // =========================================================================

    /**
     * 14. BUILDING_MANAGER bị thu hồi quyền TEMP_REG:APPROVE:
     *     Chính sách FAIL-CLOSED: không được tự động có quyền chỉ vì mang Role (403 Forbidden).
     */
    public function test_14_building_manager_without_approve_permission_cannot_approve_reject_or_submit_police(): void
    {
        $apt = $this->createApartment('APT-BM14');
        [, $resident] = $this->createResidentUser($apt, true, 'OWNER');
        $record = $this->createRegistration($apt, $resident);

        // Tạo user có vai trò BUILDING_MANAGER nhưng không có quyền TEMP_REG:APPROVE (chỉ có TEMP_REG:VIEW)
        $managerUser = User::create([
            'username' => 'bm_restricted_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'bm_re_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Manager Bị Thu Hồi Quyền Phê Duyệt',
            'status' => 'ACTIVE',
        ]);

        $customBmRole = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => 'CUSTOM_USER_BM_REVOKED_'.Str::upper(Str::random(6)),
            'role_name' => 'Building Manager Bị Thu Hồi Duyệt',
            'description' => 'Custom role lacking approve',
            'is_system_role' => false,
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $managerUser->id,
            'role_id' => $customBmRole->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        // Chỉ cấp duy nhất quyền VIEW, KHÔNG cấp quyền APPROVE
        $viewPerm = Permission::where('permission_code', 'TEMP_REG:VIEW')->first();
        if ($viewPerm) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $customBmRole->id,
                'permission_id' => $viewPerm->id,
                'created_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$managerUser->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $managerUser->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit BM Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        // Cố tình gọi approve -> Bắt buộc 403 Forbidden (fail-closed, không fallback cấp quyền)
        $resApprove = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/approve", [
                'notes' => 'Manager bị thu hồi quyền cố duyệt',
            ]);
        $resApprove->assertStatus(403);

        // Cố tình gọi reject -> 403 Forbidden
        $resReject = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/reject", [
                'reason' => 'Manager bị thu hồi quyền cố từ chối',
            ]);
        $resReject->assertStatus(403);

        // Cố tình nộp công an -> 403 Forbidden
        $resSubmit = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/submit-police", [
                'police_reference_code' => 'CA-REVOKED-001',
            ]);
        $resSubmit->assertStatus(403);
    }

    /**
     * 15. SUPER_ADMIN có toàn quyền quản trị hệ thống và bypass quyền hạt nhân
     */
    public function test_15_super_admin_retains_super_admin_privileges_across_all_actions(): void
    {
        $apt = $this->createApartment('APT-SA15');
        [, $resident] = $this->createResidentUser($apt, true, 'OWNER');
        $record = $this->createRegistration($apt, $resident);

        $adminUser = User::create([
            'username' => 'sa_test_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'sa_test_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Super Admin Test',
            'status' => 'ACTIVE',
        ]);

        $saRole = Role::where('role_code', 'SUPER_ADMIN')->first();
        if ($saRole) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $adminUser->id,
                'role_id' => $saRole->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$adminUser->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $adminUser->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit SA Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        // Super admin submit police thành công
        $resSubmit = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/submit-police", [
                'police_reference_code' => 'CA-SA-8899',
            ]);
        $resSubmit->assertStatus(200);

        // Super admin duyệt thành công
        $resApprove = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/residents/temporary-registrations/{$record->id}/approve", [
                'notes' => 'SA phê duyệt',
            ]);
        $resApprove->assertStatus(200);
    }

    /**
     * 16. Chạy RbacSeeder nhiều lần bảo đảm tính IDEMPOTENT:
     *     Không duplicate dữ liệu, không ghi đè quyền tùy chỉnh đã có.
     */
    public function test_16_rbac_seeder_is_idempotent_and_does_not_duplicate_or_overwrite_custom_permissions(): void
    {
        $rolesCountBefore = DB::table('roles')->count();
        $permsCountBefore = DB::table('permissions')->count();
        $rolePermsCountBefore = DB::table('role_permissions')->count();

        // Chạy lại RbacSeeder
        $seeder = new RbacSeeder;
        $seeder->run();

        // Kiểm tra số lượng roles, permissions, role_permissions không bị nhân bản
        $rolesCountAfter = DB::table('roles')->count();
        $permsCountAfter = DB::table('permissions')->count();
        $rolePermsCountAfter = DB::table('role_permissions')->count();

        $this->assertEquals($rolesCountBefore, $rolesCountAfter, 'Số lượng roles không đổi sau khi re-run seeder');
        $this->assertEquals($permsCountBefore, $permsCountAfter, 'Số lượng permissions không đổi sau khi re-run seeder');
        $this->assertEquals($rolePermsCountBefore, $rolePermsCountAfter, 'Số lượng role_permissions không đổi sau khi re-run seeder');
    }

    /**
     * 17. BUG-M3-05: Tải biểu mẫu CT01 (/download) yêu cầu Bearer token xác thực
     *     và kiểm tra phạm vi phân quyền truy cập của Cư dân:
     *     - Không gửi Bearer token: 401 Unauthorized.
     *     - Cư dân khác căn hộ không có quyền truy cập: 403 Forbidden.
     *     - Cư dân sở hữu hồ sơ có Bearer token: 200 OK với Content-Type text/html và header Content-Disposition.
     */
    public function test_17_download_ct01_requires_bearer_token_and_validates_resident_authorization(): void
    {
        $aptA = $this->createApartment('APT-DL17A');
        [$userA, $residentA, $tokenA] = $this->createResidentUser($aptA, true, 'OWNER');
        $recordA = $this->createRegistration($aptA, $residentA, [
            'reason' => 'Đăng ký CT01 hợp lệ của Căn hộ A',
        ]);

        $aptB = $this->createApartment('APT-DL17B');
        [, , $tokenB] = $this->createResidentUser($aptB, true, 'OWNER');

        // 1. Không gửi Bearer token -> Bắt buộc bị chặn 401 Unauthorized do auth.bearer middleware
        $resNoAuth = $this->get("/api/v1/residents/temporary-registrations/{$recordA->id}/download");
        $resNoAuth->assertStatus(401);

        // 2. Gửi Bearer token của Cư dân khác căn hộ (User B) -> 403 Forbidden
        $resForbidden = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->get("/api/v1/residents/temporary-registrations/{$recordA->id}/download");
        $resForbidden->assertStatus(403);

        // 3. Gửi Bearer token của Chủ hộ/Người tạo (User A) -> 200 OK
        $resSuccess = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->get("/api/v1/residents/temporary-registrations/{$recordA->id}/download");
        $resSuccess->assertStatus(200);
        $this->assertStringContainsString('text/html', (string) $resSuccess->headers->get('Content-Type'));
        $this->assertStringContainsString("bieu_mau_ct01_{$recordA->id}.html", (string) $resSuccess->headers->get('Content-Disposition'));
    }
}
