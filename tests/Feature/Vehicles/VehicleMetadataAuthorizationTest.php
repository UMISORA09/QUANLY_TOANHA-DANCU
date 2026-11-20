<?php

namespace Tests\Feature\Vehicles;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Permission;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VehicleMetadataAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected Block $blockA;

    protected Block $blockB;

    protected Apartment $apartmentA;

    protected Apartment $apartmentB;

    protected User $residentUserA;

    protected string $residentTokenA;

    protected User $residentUserB;

    protected string $departmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $suffix = Str::upper(Str::random(6));

        $this->blockA = Block::create([
            'block_code' => 'BLK_META_A_'.$suffix,
            'block_name' => 'Tòa A Kiểm Thử '.$suffix,
            'total_floors' => 5,
            'total_basements' => 1,
            'total_apartments' => 10,
        ]);

        $this->blockB = Block::create([
            'block_code' => 'BLK_META_B_'.$suffix,
            'block_name' => 'Tòa B Kiểm Thử '.$suffix,
            'total_floors' => 5,
            'total_basements' => 1,
            'total_apartments' => 10,
        ]);

        $floorAId = (string) Str::uuid();
        DB::table('floors')->insert([
            'id' => $floorAId,
            'block_id' => $this->blockA->id,
            'floor_number' => 1,
            'floor_code' => 'FLA-'.$suffix,
            'floor_name' => 'Tầng 1 A',
            'floor_type' => 'RESIDENTIAL',
            'total_units' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $floorBId = (string) Str::uuid();
        DB::table('floors')->insert([
            'id' => $floorBId,
            'block_id' => $this->blockB->id,
            'floor_number' => 1,
            'floor_code' => 'FLB-'.$suffix,
            'floor_name' => 'Tầng 1 B',
            'floor_type' => 'RESIDENTIAL',
            'total_units' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->apartmentA = Apartment::create([
            'block_id' => $this->blockA->id,
            'floor_id' => $floorAId,
            'apartment_number' => 'MA-101-'.$suffix,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);

        $this->apartmentB = Apartment::create([
            'block_id' => $this->blockB->id,
            'floor_id' => $floorBId,
            'apartment_number' => 'MB-202-'.$suffix,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 82.0,
            'net_usable_area_sqm' => 75.0,
            'status' => 'OCCUPIED',
        ]);

        $this->departmentId = (string) Str::uuid();
        DB::table('departments')->insert([
            'id' => $this->departmentId,
            'department_code' => 'DEPT_META_'.$suffix,
            'department_name' => 'Bộ phận Vận hành '.$suffix,
            'created_at' => now(),
        ]);

        [$this->residentUserA, $this->residentTokenA] = $this->createUserWithRole('RESIDENT_OWNER', 'res_a_', [
            'full_name' => 'Cư Dân Tòa A '.$suffix,
            'email' => 'secret_a_'.strtolower($suffix).'@private-resident.vn',
            'phone_number' => '0911'.rand(100000, 999999),
            'national_id_number' => '079099'.rand(100000, 999999),
            'date_of_birth' => '1992-05-15',
            'gender' => 'MALE',
        ]);

        Resident::create([
            'user_id' => $this->residentUserA->id,
            'apartment_id' => $this->apartmentA->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'relationship_to_head' => 'SELF',
            'occupation' => 'Kỹ sư phần mềm mật',
            'is_active' => true,
        ]);

        [$this->residentUserB] = $this->createUserWithRole('RESIDENT_OWNER', 'res_b_', [
            'full_name' => 'Cư Dân Tòa B Bí Mật '.$suffix,
            'email' => 'secret_b_'.strtolower($suffix).'@private-resident.vn',
            'phone_number' => '0988'.rand(100000, 999999),
            'national_id_number' => '001088'.rand(100000, 999999),
            'date_of_birth' => '1988-11-20',
            'gender' => 'FEMALE',
        ]);

        Resident::create([
            'user_id' => $this->residentUserB->id,
            'apartment_id' => $this->apartmentB->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'relationship_to_head' => 'SELF',
            'occupation' => 'Bác sĩ nội khoa mật',
            'is_active' => true,
        ]);
    }

    /**
     * Tạo người dùng kèm vai trò hệ thống và phiên Bearer token hợp lệ.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: string}
     */
    protected function createUserWithRole(string $roleCode, string $prefix, array $attributes = []): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create(array_merge([
            'username' => $prefix.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Tester '.$roleCode.' '.$rand,
            'status' => 'ACTIVE',
        ], $attributes));

        $role = Role::where('role_code', $roleCode)->firstOrFail();

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = $this->issueTestBearerToken($user);

        return [$user, $token];
    }

    /**
     * Gắn hồ sơ nhân viên giới hạn phạm vi một block cụ thể.
     */
    protected function assignStaffBlockScope(User $user, ?string $blockId): void
    {
        DB::table('staff_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'employee_code' => 'EMP-META-'.Str::upper(Str::random(8)),
            'department_id' => $this->departmentId,
            'job_title' => 'Nhân viên Kiểm thử Phạm vi',
            'hire_date' => today(),
            'contract_type' => 'FULL_TIME',
            'assigned_block_id' => $blockId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Gắn vai trò tùy biến theo danh sách mã quyền cụ thể cho người dùng.
     *
     * @param  array<int, string>  $permissionCodes
     * @return array{0: User, 1: string}
     */
    protected function createUserWithCustomPermissions(array $permissionCodes, string $rolePrefix = 'CUSTOM_META_'): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create([
            'username' => 'custom_meta_'.$rand,
            'phone_number' => '08'.rand(10000000, 99999999),
            'email' => 'custom_meta_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Custom Scope User '.$rand,
            'status' => 'ACTIVE',
        ]);

        $role = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => $rolePrefix.Str::upper(Str::random(6)),
            'role_name' => 'Custom Vehicle Meta Role',
            'is_system_role' => false,
        ]);

        $permIds = Permission::whereIn('permission_code', $permissionCodes)->pluck('id');
        foreach ($permIds as $permId) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $permId,
                'created_at' => now(),
            ]);
        }

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = $this->issueTestBearerToken($user);

        return [$user, $token];
    }

    /**
     * Header Bearer JSON cho request kiểm thử.
     *
     * @return array<string, string>
     */
    protected function bearerHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }

    /**
     * Xác nhận phản hồi 401/403/error hoàn toàn không rò rỉ dữ liệu nhạy cảm hay chi tiết hệ thống.
     */
    protected function assertNoSensitiveLeakInErrorResponse(TestResponse $response): void
    {
        $raw = (string) $response->getContent();
        $json = $response->json();

        $this->assertIsArray($json);
        $this->assertFalse((bool) ($json['success'] ?? true));
        $this->assertArrayNotHasKey('data', $json);

        $forbiddenLeaks = [
            $this->residentUserA->full_name,
            $this->residentUserA->email,
            $this->residentUserA->phone_number,
            $this->residentUserA->national_id_number,
            $this->residentUserB->full_name,
            $this->residentUserB->email,
            $this->residentUserB->phone_number,
            $this->residentUserB->national_id_number,
            $this->apartmentA->apartment_number,
            $this->apartmentB->apartment_number,
            'SQLSTATE',
            'Stack trace',
            'VehicleController.php',
            'select * from',
        ];

        foreach ($forbiddenLeaks as $secret) {
            if (! empty($secret)) {
                $this->assertStringNotContainsString((string) $secret, $raw);
            }
        }
    }

    /**
     * 1. Người không có VEHICLE:VIEW (cư dân, kế toán, kỹ thuật viên, user thiếu quyền) không thể truy cập metadata.
     */
    public function test_01_users_without_vehicle_view_permission_cannot_access_vehicle_metadata(): void
    {
        [, $memberToken] = $this->createUserWithRole('RESIDENT_MEMBER', 'res_m_');
        [, $accountantToken] = $this->createUserWithRole('ACCOUNTANT', 'acc_');
        [, $technicianToken] = $this->createUserWithRole('TECHNICIAN', 'tech_');
        [, $createOnlyToken] = $this->createUserWithCustomPermissions(['VEHICLE:CREATE', 'USER:VIEW']);

        $tokens = [
            'resident_owner' => $this->residentTokenA,
            'resident_member' => $memberToken,
            'accountant' => $accountantToken,
            'technician' => $technicianToken,
            'vehicle_create_without_view' => $createOnlyToken,
        ];

        $endpoints = [
            '/api/v1/vehicles/meta/apartments',
            "/api/v1/vehicles/meta/apartments/{$this->apartmentA->id}/residents",
            "/api/v1/vehicles/meta/apartments/{$this->apartmentB->id}/residents",
            '/api/v1/vehicles/meta/residents',
            "/api/v1/vehicles/meta/residents?apartment_id={$this->apartmentA->id}",
            "/api/v1/vehicles/meta/residents?building_id={$this->blockB->id}",
        ];

        foreach ($tokens as $label => $token) {
            foreach ($endpoints as $endpoint) {
                $response = $this->withHeaders($this->bearerHeaders($token))->getJson($endpoint);
                $response->assertStatus(403, "Vai trò '{$label}' không có VEHICLE:VIEW phải bị chặn 403 tại {$endpoint}");
                $this->assertNoSensitiveLeakInErrorResponse($response);
            }
        }
    }

    /**
     * 1b. Nhân viên có role RECEPTIONIST/BUILDING_MANAGER nhưng bị thu hồi VEHICLE:VIEW qua CUSTOM_USER_% cũng bị chặn 403.
     */
    public function test_01b_staff_role_with_revoked_vehicle_view_permission_is_rejected(): void
    {
        [$revokedReceptionist, $revokedToken] = $this->createUserWithRole('RECEPTIONIST', 'revoked_rec_');

        $customRole = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => 'CUSTOM_USER_'.Str::upper(Str::random(10)),
            'role_name' => 'Custom Override Without Vehicle View',
            'is_system_role' => false,
        ]);

        $visitorViewPerm = Permission::where('permission_code', 'VISITOR:VIEW')->firstOrFail();
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $customRole->id,
            'permission_id' => $visitorViewPerm->id,
            'created_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $revokedReceptionist->id,
            'role_id' => $customRole->id,
            'is_primary' => 0,
            'assigned_at' => now(),
        ]);

        $endpoints = [
            '/api/v1/vehicles/meta/apartments',
            "/api/v1/vehicles/meta/apartments/{$this->apartmentA->id}/residents",
            '/api/v1/vehicles/meta/residents',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->withHeaders($this->bearerHeaders($revokedToken))->getJson($endpoint);
            $response->assertStatus(403);
            $this->assertNoSensitiveLeakInErrorResponse($response);
        }
    }

    /**
     * 2. Người có VEHICLE:VIEW được truy cập đúng phạm vi tòa nhà được phân công.
     */
    public function test_02_building_manager_with_vehicle_view_only_accesses_assigned_building_scope(): void
    {
        [$managerA, $managerTokenA] = $this->createUserWithRole('BUILDING_MANAGER', 'bm_a_');
        $this->assignStaffBlockScope($managerA, $this->blockA->id);

        // 1) Chỉ thấy căn hộ thuộc Block A
        $aptRes = $this->withHeaders($this->bearerHeaders($managerTokenA))
            ->getJson('/api/v1/vehicles/meta/apartments');
        $aptRes->assertStatus(200)->assertJson(['success' => true]);

        $aptIds = collect($aptRes->json('data'))->pluck('id')->all();
        $this->assertContains($this->apartmentA->id, $aptIds);
        $this->assertNotContains($this->apartmentB->id, $aptIds);

        // 2) Lấy được cư dân của căn hộ trong Block A
        $aptResidentsRes = $this->withHeaders($this->bearerHeaders($managerTokenA))
            ->getJson("/api/v1/vehicles/meta/apartments/{$this->apartmentA->id}/residents");
        $aptResidentsRes->assertStatus(200)->assertJson(['success' => true]);
        $aptResidentUserIds = collect($aptResidentsRes->json('data'))->pluck('user_id')->all();
        $this->assertContains($this->residentUserA->id, $aptResidentUserIds);
        $this->assertNotContains($this->residentUserB->id, $aptResidentUserIds);

        // 3) Tra cứu allResidents chỉ trả cư dân thuộc Block A ngay cả khi limit lớn hoặc search tên cư dân Block B
        $allRes = $this->withHeaders($this->bearerHeaders($managerTokenA))
            ->getJson('/api/v1/vehicles/meta/residents?limit=500');
        $allRes->assertStatus(200)->assertJson(['success' => true]);
        $this->assertLessThanOrEqual(50, count($allRes->json('data')));
        $allUserIds = collect($allRes->json('data'))->pluck('user_id')->all();
        $this->assertContains($this->residentUserA->id, $allUserIds);
        $this->assertNotContains($this->residentUserB->id, $allUserIds);

        $searchBlockB = $this->withHeaders($this->bearerHeaders($managerTokenA))
            ->getJson('/api/v1/vehicles/meta/residents?search='.urlencode($this->residentUserB->full_name));
        $searchBlockB->assertStatus(200)->assertJson([
            'success' => true,
            'data' => [],
        ]);
    }

    /**
     * 2b. Quản lý được gán trực tiếp qua blocks.building_manager_user_id cũng bị giới hạn đúng block mình quản lý.
     */
    public function test_02b_block_building_manager_user_id_scopes_metadata_when_no_staff_profile(): void
    {
        [$managerA, $managerTokenA] = $this->createUserWithRole('BUILDING_MANAGER', 'bm_owner_');
        $this->blockA->update(['building_manager_user_id' => $managerA->id]);

        $aptRes = $this->withHeaders($this->bearerHeaders($managerTokenA))
            ->getJson('/api/v1/vehicles/meta/apartments');
        $aptRes->assertStatus(200);

        $aptIds = collect($aptRes->json('data'))->pluck('id')->all();
        $this->assertContains($this->apartmentA->id, $aptIds);
        $this->assertNotContains($this->apartmentB->id, $aptIds);

        $forbiddenCrossApt = $this->withHeaders($this->bearerHeaders($managerTokenA))
            ->getJson("/api/v1/vehicles/meta/apartments/{$this->apartmentB->id}/residents");
        $forbiddenCrossApt->assertStatus(403);
        $this->assertNoSensitiveLeakInErrorResponse($forbiddenCrossApt);
    }

    /**
     * 3. RECEPTIONIST có quyền hiện hành vẫn lấy được metadata cần thiết.
     */
    public function test_03_receptionist_with_current_permissions_can_access_required_metadata(): void
    {
        [, $receptionToken] = $this->createUserWithRole('RECEPTIONIST', 'rec_ok_');

        $aptsRes = $this->withHeaders($this->bearerHeaders($receptionToken))
            ->getJson('/api/v1/vehicles/meta/apartments');
        $aptsRes->assertStatus(200)->assertJson(['success' => true]);
        $this->assertContains($this->apartmentA->id, collect($aptsRes->json('data'))->pluck('id')->all());

        $aptResidentsRes = $this->withHeaders($this->bearerHeaders($receptionToken))
            ->getJson("/api/v1/vehicles/meta/apartments/{$this->apartmentA->id}/residents");
        $aptResidentsRes->assertStatus(200)->assertJson(['success' => true]);
        $this->assertContains($this->residentUserA->id, collect($aptResidentsRes->json('data'))->pluck('user_id')->all());

        $searchRes = $this->withHeaders($this->bearerHeaders($receptionToken))
            ->getJson('/api/v1/vehicles/meta/residents?search='.urlencode($this->residentUserA->full_name));
        $searchRes->assertStatus(200)->assertJson(['success' => true]);
        $this->assertContains($this->residentUserA->id, collect($searchRes->json('data'))->pluck('user_id')->all());
    }

    /**
     * 4. SECURITY_GUARD có quyền hiện hành vẫn lấy được metadata cần thiết.
     */
    public function test_04_security_guard_with_current_permissions_can_access_required_metadata(): void
    {
        [, $securityToken] = $this->createUserWithRole('SECURITY_GUARD', 'sec_ok_');

        $aptsRes = $this->withHeaders($this->bearerHeaders($securityToken))
            ->getJson('/api/v1/vehicles/meta/apartments');
        $aptsRes->assertStatus(200)->assertJson(['success' => true]);
        $this->assertContains($this->apartmentB->id, collect($aptsRes->json('data'))->pluck('id')->all());

        $aptResidentsRes = $this->withHeaders($this->bearerHeaders($securityToken))
            ->getJson("/api/v1/vehicles/meta/apartments/{$this->apartmentB->id}/residents");
        $aptResidentsRes->assertStatus(200)->assertJson(['success' => true]);
        $this->assertContains($this->residentUserB->id, collect($aptResidentsRes->json('data'))->pluck('user_id')->all());

        $searchRes = $this->withHeaders($this->bearerHeaders($securityToken))
            ->getJson('/api/v1/vehicles/meta/residents?apartment_id='.$this->apartmentB->id);
        $searchRes->assertStatus(200)->assertJson(['success' => true]);
        $this->assertContains($this->residentUserB->id, collect($searchRes->json('data'))->pluck('user_id')->all());
    }

    /**
     * 5. Người dùng không thể truyền apartment_id của căn hộ khác ngoài phạm vi để lấy danh sách cư dân.
     */
    public function test_05_user_cannot_pass_other_apartment_id_to_retrieve_residents(): void
    {
        [$receptionA, $receptionTokenA] = $this->createUserWithRole('RECEPTIONIST', 'rec_scope_');
        $this->assignStaffBlockScope($receptionA, $this->blockA->id);

        // Chặn cả route param apartmentId lẫn query param apartment_id
        $crossRoute = $this->withHeaders($this->bearerHeaders($receptionTokenA))
            ->getJson("/api/v1/vehicles/meta/apartments/{$this->apartmentB->id}/residents");
        $crossRoute->assertStatus(403);
        $this->assertNoSensitiveLeakInErrorResponse($crossRoute);

        $crossQueryResidents = $this->withHeaders($this->bearerHeaders($receptionTokenA))
            ->getJson("/api/v1/vehicles/meta/residents?apartment_id={$this->apartmentB->id}");
        $crossQueryResidents->assertStatus(403);
        $this->assertNoSensitiveLeakInErrorResponse($crossQueryResidents);

        // Tấn công đính kèm query apartment_id căn hộ B vào URL căn hộ A
        $overrideAttempt = $this->withHeaders($this->bearerHeaders($receptionTokenA))
            ->getJson("/api/v1/vehicles/meta/apartments/{$this->apartmentA->id}/residents?apartment_id={$this->apartmentB->id}");
        $overrideAttempt->assertStatus(403);
        $this->assertNoSensitiveLeakInErrorResponse($overrideAttempt);

        $crossApartmentsQuery = $this->withHeaders($this->bearerHeaders($receptionTokenA))
            ->getJson("/api/v1/vehicles/meta/apartments?apartment_id={$this->apartmentB->id}");
        $crossApartmentsQuery->assertStatus(403);
        $this->assertNoSensitiveLeakInErrorResponse($crossApartmentsQuery);
    }

    /**
     * 6. Người dùng không thể dùng building_id, block_id hoặc filter khác để vượt phạm vi.
     */
    public function test_06_user_cannot_use_building_id_or_filter_tampering_to_bypass_scope(): void
    {
        [$guardA, $guardTokenA] = $this->createUserWithRole('SECURITY_GUARD', 'sec_scope_');
        $this->assignStaffBlockScope($guardA, $this->blockA->id);

        $tamperRequests = [
            "/api/v1/vehicles/meta/apartments?building_id={$this->blockB->id}",
            "/api/v1/vehicles/meta/apartments?block_id={$this->blockB->id}",
            "/api/v1/vehicles/meta/residents?building_id={$this->blockB->id}",
            "/api/v1/vehicles/meta/residents?block_id={$this->blockB->id}",
            "/api/v1/vehicles/meta/apartments/{$this->apartmentA->id}/residents?building_id={$this->blockB->id}",
            "/api/v1/vehicles/meta/residents?apartment_id={$this->apartmentA->id}&building_id={$this->blockB->id}",
            "/api/v1/vehicles/meta/apartments?building_id={$this->blockA->id}&block_id={$this->blockB->id}",
        ];

        foreach ($tamperRequests as $url) {
            $res = $this->withHeaders($this->bearerHeaders($guardTokenA))->getJson($url);
            $res->assertStatus(403, "Request giả mạo phạm vi {$url} phải bị từ chối 403.");
            $this->assertNoSensitiveLeakInErrorResponse($res);
        }

        // Thử bypass bằng LIKE wildcard '%' trên search khi đã giới hạn block A -> vẫn chỉ thấy Block A
        $wildcardRes = $this->withHeaders($this->bearerHeaders($guardTokenA))
            ->getJson('/api/v1/vehicles/meta/residents?search=%25');
        $wildcardRes->assertStatus(200);
        $this->assertNotContains(
            $this->residentUserB->id,
            collect($wildcardRes->json('data'))->pluck('user_id')->all()
        );
    }

    /**
     * 7 & 8. Response không chứa thông tin PII dư thừa (email, CCCD, ngày sinh, username...), đồng thời giữ đủ trường frontend cần.
     */
    public function test_07_and_08_responses_exclude_unnecessary_pii_and_keep_required_frontend_fields(): void
    {
        [, $receptionToken] = $this->createUserWithRole('RECEPTIONIST', 'rec_fields_');

        // A. Kiểm tra /vehicles/meta/apartments
        $aptsRes = $this->withHeaders($this->bearerHeaders($receptionToken))
            ->getJson('/api/v1/vehicles/meta/apartments');
        $aptsRes->assertStatus(200);
        $aptRow = collect($aptsRes->json('data'))->firstWhere('id', $this->apartmentA->id);
        $this->assertNotNull($aptRow);
        $this->assertEqualsCanonicalizing(['id', 'apartment_number'], array_keys($aptRow));

        // B. Kiểm tra /vehicles/meta/apartments/{id}/residents
        $aptResidentsRes = $this->withHeaders($this->bearerHeaders($receptionToken))
            ->getJson("/api/v1/vehicles/meta/apartments/{$this->apartmentA->id}/residents");
        $aptResidentsRes->assertStatus(200);

        $rawAptResidents = (string) $aptResidentsRes->getContent();
        $resRow = collect($aptResidentsRes->json('data'))->firstWhere('user_id', $this->residentUserA->id);
        $this->assertNotNull($resRow);
        $this->assertEqualsCanonicalizing([
            'user_id',
            'full_name',
            'phone_number',
            'resident_type',
            'is_head_of_household',
        ], array_keys($resRow));
        $this->assertSame($this->residentUserA->full_name, $resRow['full_name']);
        $this->assertSame($this->residentUserA->phone_number, $resRow['phone_number']);
        $this->assertSame('OWNER', $resRow['resident_type']);

        // C. Kiểm tra /vehicles/meta/residents
        $allRes = $this->withHeaders($this->bearerHeaders($receptionToken))
            ->getJson('/api/v1/vehicles/meta/residents?apartment_id='.$this->apartmentA->id);
        $allRes->assertStatus(200);

        $rawAllResidents = (string) $allRes->getContent();
        $allRow = collect($allRes->json('data'))->firstWhere('user_id', $this->residentUserA->id);
        $this->assertNotNull($allRow);
        $this->assertEqualsCanonicalizing([
            'user_id',
            'full_name',
            'phone_number',
            'resident_type',
            'is_head_of_household',
            'apartment_id',
            'apartment_number',
        ], array_keys($allRow));
        $this->assertSame($this->apartmentA->id, $allRow['apartment_id']);
        $this->assertSame($this->apartmentA->apartment_number, $allRow['apartment_number']);

        // Xác nhận email, CCCD, ngày sinh, giới tính, username, occupation tuyệt đối không xuất hiện
        $disallowedKeys = [
            'email',
            'national_id_number',
            'date_of_birth',
            'gender',
            'username',
            'password_hash',
            'mfa_secret',
            'avatar_url',
            'occupation',
            'relationship_to_head',
            'stay_start_date',
            'stay_end_date',
            'primary_owner',
        ];

        foreach ($disallowedKeys as $key) {
            $this->assertArrayNotHasKey($key, $resRow);
            $this->assertArrayNotHasKey($key, $allRow);
        }

        foreach ([$rawAptResidents, $rawAllResidents] as $payload) {
            $this->assertStringNotContainsString($this->residentUserA->email, $payload);
            $this->assertStringNotContainsString($this->residentUserA->national_id_number, $payload);
            $this->assertStringNotContainsString('1992-05-15', $payload);
            $this->assertStringNotContainsString($this->residentUserA->username, $payload);
            $this->assertStringNotContainsString('Kỹ sư phần mềm mật', $payload);
        }

        // Không cho phép dò tìm cư dân bằng email qua ?search=
        $searchByEmail = $this->withHeaders($this->bearerHeaders($receptionToken))
            ->getJson('/api/v1/vehicles/meta/residents?search='.urlencode($this->residentUserA->email));
        $searchByEmail->assertStatus(200)->assertJson([
            'success' => true,
            'data' => [],
        ]);
    }

    /**
     * 9. Không có dữ liệu nhạy cảm bị trả về trong các trường hợp 401/403 (kể cả tài khoản bị khóa hoặc chưa đăng nhập).
     */
    public function test_09_401_and_403_responses_never_leak_sensitive_data(): void
    {
        $unauthEndpoints = [
            '/api/v1/vehicles/meta/apartments',
            "/api/v1/vehicles/meta/apartments/{$this->apartmentA->id}/residents",
            '/api/v1/vehicles/meta/residents',
        ];

        foreach ($unauthEndpoints as $endpoint) {
            auth()->forgetGuards();
            $this->flushHeaders();
            $res401 = $this->getJson($endpoint);
            $res401->assertStatus(401)->assertJson(['error' => 'UNAUTHORIZED']);
            $this->assertNoSensitiveLeakInErrorResponse($res401);

            $resInvalidToken401 = $this->withHeaders($this->bearerHeaders('invalid_token_should_not_pass'))
                ->getJson($endpoint);
            $resInvalidToken401->assertStatus(401)->assertJson(['error' => 'UNAUTHORIZED']);
            $this->assertNoSensitiveLeakInErrorResponse($resInvalidToken401);

            $resResident403 = $this->withHeaders($this->bearerHeaders($this->residentTokenA))->getJson($endpoint);
            $resResident403->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
            $this->assertNoSensitiveLeakInErrorResponse($resResident403);
        }

        // Tài khoản bị khóa nhưng vẫn gắn role RECEPTIONIST
        [$lockedStaff, $lockedToken] = $this->createUserWithRole('RECEPTIONIST', 'locked_rec_', [
            'status' => 'LOCKED',
        ]);
        $this->assertSame('LOCKED', $lockedStaff->status);

        $lockedRes = $this->withHeaders($this->bearerHeaders($lockedToken))
            ->getJson('/api/v1/vehicles/meta/residents');
        $lockedRes->assertStatus(403);
        $this->assertNoSensitiveLeakInErrorResponse($lockedRes);
    }

    /**
     * 10. Lỗi validation, ID không hợp lệ hoặc căn hộ không tồn tại không làm rò rỉ thông tin qua error response.
     */
    public function test_10_error_responses_never_leak_internal_errors_or_database_details(): void
    {
        [$receptionA, $receptionTokenA] = $this->createUserWithRole('RECEPTIONIST', 'rec_err_');
        $this->assignStaffBlockScope($receptionA, $this->blockA->id);

        // 1) Tham số mảng / kiểu sai không được gây 500 Array to string conversion
        $badArrayQueries = [
            '/api/v1/vehicles/meta/apartments?building_id[]=abc',
            '/api/v1/vehicles/meta/residents?apartment_id[]=abc',
            '/api/v1/vehicles/meta/residents?search[]=abc',
            '/api/v1/vehicles/meta/residents?limit[]=10',
        ];

        foreach ($badArrayQueries as $url) {
            $res = $this->withHeaders($this->bearerHeaders($receptionTokenA))->getJson($url);
            $res->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
            $this->assertNoSensitiveLeakInErrorResponse($res);
        }

        // 2) UUID căn hộ không tồn tại -> 404 gọn, không rò rỉ thông tin
        $missingUuid = (string) Str::uuid();
        $missingRes = $this->withHeaders($this->bearerHeaders($receptionTokenA))
            ->getJson("/api/v1/vehicles/meta/apartments/{$missingUuid}/residents");
        $missingRes->assertStatus(404)->assertJson(['error' => 'NOT_FOUND']);
        $this->assertNoSensitiveLeakInErrorResponse($missingRes);

        // 3) Chuỗi ID không phải UUID cho user chưa có quyền -> vẫn chặn 403 trước khi chạm DB
        $malformedUnauth = $this->withHeaders($this->bearerHeaders($this->residentTokenA))
            ->getJson('/api/v1/vehicles/meta/apartments/not-a-valid-uuid/residents');
        $malformedUnauth->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        $this->assertNoSensitiveLeakInErrorResponse($malformedUnauth);
    }
}
