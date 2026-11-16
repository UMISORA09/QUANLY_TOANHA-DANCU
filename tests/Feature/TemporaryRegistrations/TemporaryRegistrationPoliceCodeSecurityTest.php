<?php

namespace Tests\Feature\TemporaryRegistrations;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use App\Services\TemporaryRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TemporaryRegistrationPoliceCodeSecurityTest extends TestCase
{
    /**
     * Tạo tài khoản quản trị viên (SUPER_ADMIN hoặc BUILDING_MANAGER) kèm Bearer token
     */
    protected function createAdminOrManagerUser(string $roleCode = 'SUPER_ADMIN'): array
    {
        $user = User::create([
            'username' => 'adm_sec_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_sec_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => $roleCode === 'SUPER_ADMIN' ? 'Super Admin Sec' : 'Building Manager Sec',
            'status' => 'ACTIVE',
        ]);

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

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Admin Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo căn hộ test với số căn hộ ngẫu nhiên
     */
    protected function createApartment(string $code = 'SEC'): Apartment
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
            'username' => 'res_sec_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_sec_'.Str::random(8).'@cassavas.vn',
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
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú ban đầu',
            'police_status' => 'PENDING',
            'police_reference_code' => null,
            'notes' => 'Ghi chú ban đầu',
        ], $overrides));
    }

    /**
     * Test 01: Cư dân không thể tự đặt police_reference_code khi cập nhật hồ sơ PENDING
     */
    public function test_01_resident_cannot_set_police_reference_code_on_pending_registration(): void
    {
        $apt = $this->createApartment();
        [$residentUser, $resident, $token] = $this->createResidentUser($apt);
        $record = $this->createRegistration($apt, $resident, [
            'police_status' => 'PENDING',
            'police_reference_code' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cư dân cập nhật lý do mới',
                'police_reference_code' => 'CA-2026-FAKE-FORGED-001',
                'updated_at' => $record->updated_at->toISOString(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.reason', 'Cư dân cập nhật lý do mới');
        $response->assertJsonPath('data.police_reference_code', null);

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $record->id,
            'reason' => 'Cư dân cập nhật lý do mới',
            'police_reference_code' => null,
        ]);
    }

    /**
     * Test 02: Cư dân không thể ghi đè police_reference_code đã tồn tại khi ở PENDING_POLICE_SUBMISSION
     */
    public function test_02_resident_cannot_overwrite_existing_police_reference_code(): void
    {
        $apt = $this->createApartment();
        [$residentUser, $resident, $token] = $this->createResidentUser($apt);
        $record = $this->createRegistration($apt, $resident, [
            'police_status' => 'PENDING_POLICE_SUBMISSION',
            'police_reference_code' => 'CA-OFFICIAL-2026-LEGIT',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'notes' => 'Cư dân bổ sung ghi chú hồ sơ',
                'police_reference_code' => 'CA-HACKER-OVERWRITE-999',
                'updated_at' => $record->updated_at->toISOString(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.notes', 'Cư dân bổ sung ghi chú hồ sơ');
        $response->assertJsonPath('data.police_reference_code', 'CA-OFFICIAL-2026-LEGIT');

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $record->id,
            'notes' => 'Cư dân bổ sung ghi chú hồ sơ',
            'police_reference_code' => 'CA-OFFICIAL-2026-LEGIT',
        ]);
    }

    /**
     * Test 03: Quản trị viên (SUPER_ADMIN) có thể cập nhật police_reference_code qua update API
     */
    public function test_03_admin_can_update_police_reference_code(): void
    {
        $apt = $this->createApartment();
        [$residentUser, $resident] = $this->createResidentUser($apt);
        [$adminUser, $adminToken] = $this->createAdminOrManagerUser('SUPER_ADMIN');

        $record = $this->createRegistration($apt, $resident, [
            'police_status' => 'PENDING_POLICE_SUBMISSION',
            'police_reference_code' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'police_reference_code' => 'CA-ADMIN-OFFICIAL-2026',
                'notes' => 'Admin cập nhật mã hồ sơ gửi công an',
                'updated_at' => $record->updated_at->toISOString(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.police_reference_code', 'CA-ADMIN-OFFICIAL-2026');

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $record->id,
            'police_reference_code' => 'CA-ADMIN-OFFICIAL-2026',
        ]);
    }

    /**
     * Test 04: Trưởng ban quản lý (BUILDING_MANAGER) có thể cập nhật police_reference_code
     */
    public function test_04_building_manager_can_update_police_reference_code(): void
    {
        $apt = $this->createApartment();
        [$residentUser, $resident] = $this->createResidentUser($apt);
        [$bmUser, $bmToken] = $this->createAdminOrManagerUser('BUILDING_MANAGER');

        $record = $this->createRegistration($apt, $resident, [
            'police_status' => 'PENDING_POLICE_SUBMISSION',
            'police_reference_code' => 'OLD-CODE-001',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bmToken)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'police_reference_code' => 'CA-BM-NEW-CODE-2026',
                'updated_at' => $record->updated_at->toISOString(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.police_reference_code', 'CA-BM-NEW-CODE-2026');

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $record->id,
            'police_reference_code' => 'CA-BM-NEW-CODE-2026',
        ]);
    }

    /**
     * Test 05: TemporaryRegistrationService::update mặc định bỏ qua police_reference_code trừ khi được cấp phép
     */
    public function test_05_service_update_defaults_to_stripping_police_reference_code(): void
    {
        $apt = $this->createApartment();
        [$residentUser, $resident] = $this->createResidentUser($apt);
        $record = $this->createRegistration($apt, $resident, [
            'police_status' => 'PENDING',
            'police_reference_code' => null,
        ]);

        $service = app(TemporaryRegistrationService::class);

        // Gọi service update mặc định không cho phép police_reference_code
        $updated1 = $service->update($record->id, [
            'reason' => 'Cập nhật lý do qua service',
            'police_reference_code' => 'FORGED-VIA-SERVICE-DIRECT-CALL',
        ]);

        $this->assertEquals('Cập nhật lý do qua service', $updated1->reason);
        $this->assertNull($updated1->police_reference_code);
        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $record->id,
            'police_reference_code' => null,
        ]);

        // Gọi service update với $allowPoliceRef = true
        $updated2 = $service->update($record->id, [
            'police_reference_code' => 'ALLOWED-POLICE-CODE-12345',
        ], true);

        $this->assertEquals('ALLOWED-POLICE-CODE-12345', $updated2->police_reference_code);
        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $record->id,
            'police_reference_code' => 'ALLOWED-POLICE-CODE-12345',
        ]);
    }

    /**
     * Test 06: Cư dân không thể ghi đè các trường hệ thống khác (reviewed_by, reviewed_at, police_status, resident_id, apartment_id)
     */
    public function test_06_system_fields_remain_protected_against_resident_tampering(): void
    {
        $apt = $this->createApartment();
        $otherApt = $this->createApartment('OTHER');
        [$residentUser, $resident, $token] = $this->createResidentUser($apt);
        [$otherUser, $otherResident] = $this->createResidentUser($otherApt);

        $record = $this->createRegistration($apt, $resident, [
            'police_status' => 'PENDING',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'police_status' => 'APPROVED',
                'reviewed_by' => $residentUser->id,
                'reviewed_at' => now()->toIso8601String(),
                'resident_id' => $otherResident->id,
                'apartment_id' => $otherApt->id,
                'police_reference_code' => 'HACKED-REF-CODE',
                'reason' => 'Lý do hợp lệ',
                'updated_at' => $record->updated_at->toISOString(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.police_status', 'PENDING');
        $response->assertJsonPath('data.police_reference_code', null);
        $response->assertJsonPath('data.resident_id', $resident->id);
        $response->assertJsonPath('data.apartment_id', $apt->id);

        $this->assertDatabaseHas('temporary_registrations', [
            'id' => $record->id,
            'police_status' => 'PENDING',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'police_reference_code' => null,
        ]);
    }
}
