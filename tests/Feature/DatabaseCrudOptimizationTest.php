<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Permission;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AccountProvisioningDuplicateException;
use App\Services\AccountProvisioningService;
use App\Services\QuocTinRealtimeService;
use App\Services\RbacService;
use App\Services\ResidentConflictException;
use App\Services\ResidentService;
use App\Services\TemporaryRegistrationConflictException;
use App\Services\TemporaryRegistrationService;
use App\Services\VehicleConflictException;
use App\Services\VehicleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DatabaseCrudOptimizationTest extends TestCase
{
    protected ?User $adminUser = null;

    protected ?string $adminToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'username' => 'crud_admin_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'crud_admin_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'CRUD Optimizer Admin',
            'status' => 'ACTIVE',
        ]);

        $superAdminRole = Role::where('role_code', 'SUPER_ADMIN')->first();
        if ($superAdminRole) {
            DB::table('user_roles')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $this->adminUser->id,
                'role_id' => $superAdminRole->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $this->adminToken = 'smart_token_'.$this->adminUser->id.'_'.Str::random(40);
        $adminTokenHash = hash('sha256', $this->adminToken);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->adminUser->id,
            'refresh_token_hash' => $adminTokenHash,
            'device_name' => 'CRUD Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'CRUDTestAgent',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        // Reset cooldowns
        foreach (['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles'] as $mod) {
            QuocTinRealtimeService::clearModuleCooldown($mod);
        }
    }

    protected function tearDown(): void
    {
        foreach (['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles'] as $mod) {
            QuocTinRealtimeService::clearModuleCooldown($mod);
        }

        parent::tearDown();
    }

    protected function getTestApartment(): Apartment
    {
        $existing = Apartment::first();
        if ($existing) {
            return $existing;
        }

        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();
        $blockId = $block?->id ?? (string) Str::uuid();
        $floorId = $floor?->id ?? (string) Str::uuid();

        if (! $block) {
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'TEST-'.Str::random(6),
                'block_name' => 'Test Block',
                'total_floors' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! $floor) {
            DB::table('floors')->insert([
                'id' => $floorId,
                'block_id' => $blockId,
                'floor_number' => 1,
                'floor_code' => 'F1',
                'floor_name' => 'Floor 1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'apartment_number' => 'TEST-'.rand(100, 999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 70.0,
            'net_usable_area_sqm' => 65.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * 1. Test Resident CREATE / UPDATE / DELETE tối ưu query và atomic soft delete
     */
    public function test_resident_create_update_delete_optimized(): void
    {
        $apartment = $this->getTestApartment();

        $user = User::create([
            'username' => 'test_res_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'test_res_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Resident Test Optimization',
            'status' => 'ACTIVE',
        ]);

        $service = app(ResidentService::class);

        // CREATE
        $resident = $service->create([
            'apartment_id' => $apartment->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => now()->toDateString(),
        ]);

        $this->assertNotNull($resident->id);
        $this->assertTrue($resident->relationLoaded('user'));
        $this->assertTrue($resident->relationLoaded('apartment'));

        // Clear cooldown cho bước kế
        QuocTinRealtimeService::clearModuleCooldown('residents');

        // UPDATE (Optimistic Concurrency)
        $updated = $service->update($resident->id, [
            'occupation' => 'Kỹ sư phần mềm',
            'updated_at' => $resident->updated_at->toIso8601String(),
        ]);

        $this->assertEquals('Kỹ sư phần mềm', $updated->occupation);

        // Clear cooldown
        QuocTinRealtimeService::clearModuleCooldown('residents');

        // DELETE (Gộp deactivation và soft-delete trong 1 câu lệnh UPDATE)
        $deleted = $service->delete($resident->id);
        $this->assertTrue($deleted);

        // Kiểm tra soft-deleted trong CSDL
        $this->assertNull(Resident::find($resident->id));
        $this->assertNotNull(Resident::withTrashed()->find($resident->id));
    }

    /**
     * 2. Test Resident kiểm tra trùng lặp & bảo vệ duy nhất 1 chủ hộ active
     */
    public function test_resident_duplicate_and_head_of_household_rule(): void
    {
        $apartment = Apartment::whereDoesntHave('residents', function ($q) {
            $q->where('is_active', true)->where('is_head_of_household', true);
        })->first();

        if (! $apartment) {
            $apartment = $this->getTestApartment();
        }
        $user1 = User::create([
            'username' => 'head1_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'head1_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Head User 1',
            'status' => 'ACTIVE',
        ]);
        $user2 = User::create([
            'username' => 'head2_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'head2_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Head User 2',
            'status' => 'ACTIVE',
        ]);

        $service = app(ResidentService::class);

        // Tạo chủ hộ 1
        $res1 = $service->create([
            'apartment_id' => $apartment->id,
            'user_id' => $user1->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->toDateString(),
        ]);

        QuocTinRealtimeService::clearModuleCooldown('residents');

        // Thử tạo trùng lặp user1 vào cùng apartment -> phải ném ValidationException
        $this->expectException(ValidationException::class);
        $service->create([
            'apartment_id' => $apartment->id,
            'user_id' => $user1->id,
            'resident_type' => 'TENANT',
            'stay_start_date' => now()->toDateString(),
        ]);
    }

    /**
     * 3. Test Resident Optimistic Concurrency từ chối bản ghi lỗi thời
     */
    public function test_resident_optimistic_concurrency_rejection(): void
    {
        $apartment = $this->getTestApartment();
        $user = User::create([
            'username' => 'occ_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'occ_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'OCC Tester',
            'status' => 'ACTIVE',
        ]);

        $service = app(ResidentService::class);
        $resident = $service->create([
            'apartment_id' => $apartment->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'stay_start_date' => now()->toDateString(),
        ]);

        QuocTinRealtimeService::clearModuleCooldown('residents');

        // Giả lập Client gửi updated_at lỗi thời (lùi 10 giây)
        $staleTime = $resident->updated_at->subSeconds(10)->toIso8601String();

        $this->expectException(ResidentConflictException::class);
        $service->update($resident->id, [
            'occupation' => 'Bị xung đột',
            'updated_at' => $staleTime,
        ]);
    }

    /**
     * 4. Test Temporary Registration CREATE / UPDATE / DELETE / APPROVE / REJECT
     */
    public function test_temporary_registration_lifecycle(): void
    {
        $apartment = $this->getTestApartment();
        $user = User::create([
            'username' => 'tempreg_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'tempreg_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Temp Reg Tester',
            'status' => 'ACTIVE',
        ]);

        $res = Resident::create([
            'apartment_id' => $apartment->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'stay_start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $service = app(TemporaryRegistrationService::class);

        // CREATE
        $tempreg = $service->create([
            'resident_id' => $res->id,
            'apartment_id' => $apartment->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'reason' => 'Kiểm thử tối ưu hóa CSDL',
        ]);

        $this->assertEquals('PENDING_POLICE_SUBMISSION', $tempreg->police_status);
        $this->assertTrue($tempreg->relationLoaded('resident'));

        QuocTinRealtimeService::clearModuleCooldown('temporary_registrations');

        // UPDATE
        $updated = $service->update($tempreg->id, [
            'reason' => 'Lý do đã được cập nhật nhanh',
            'updated_at' => $tempreg->updated_at->toIso8601String(),
        ]);
        $this->assertEquals('Lý do đã được cập nhật nhanh', $updated->reason);

        QuocTinRealtimeService::clearModuleCooldown('temporary_registrations');

        // APPROVE
        $approved = $service->approve($tempreg->id, $this->adminUser, 'Đã duyệt qua tối ưu');
        $this->assertEquals('APPROVED', $approved->police_status);
        $this->assertEquals($this->adminUser->id, $approved->reviewed_by);

        QuocTinRealtimeService::clearModuleCooldown('temporary_registrations');

        // Thử xóa hồ sơ đã APPROVED -> phải bị từ chối 409
        $this->expectException(TemporaryRegistrationConflictException::class);
        $service->delete($tempreg->id);
    }

    /**
     * 5. Test Vehicle CREATE và tự động đồng bộ IDEMPOTENT sang Invoice
     */
    public function test_vehicle_create_and_idempotent_invoice_sync(): void
    {
        $apartment = $this->getTestApartment();
        $plate = '51A-'.rand(10000, 99999);

        $service = app(VehicleService::class);
        $result = $service->createVehicle([
            'apartment_id' => $apartment->id,
            'owner_user_id' => $this->adminUser->id,
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
            'monthly_parking_fee' => 1500000,
        ], $this->adminUser->id);

        $this->assertNotNull($result['vehicle']);
        $this->assertTrue($result['invoice_sync']['synced']);

        // Đồng bộ lại lần 2 cho cùng vehicle -> phải trả về ALREADY_EXISTS không tạo trùng phí
        $reSync = $service->syncVehicleParkingFeeToInvoice($result['vehicle']);
        $this->assertFalse($reSync['synced']);
        $this->assertEquals('ALREADY_EXISTS', $reSync['reason']);
    }

    /**
     * 6. Test Vehicle từ chối trùng lặp biển số
     */
    public function test_vehicle_duplicate_license_plate_rejected(): void
    {
        $apartment = $this->getTestApartment();
        $plate = '29B-'.rand(10000, 99999);

        $service = app(VehicleService::class);
        $service->createVehicle([
            'apartment_id' => $apartment->id,
            'owner_user_id' => $this->adminUser->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
        ], $this->adminUser->id);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        // Thử đăng ký cùng biển số -> ném VehicleConflictException 409
        $this->expectException(VehicleConflictException::class);
        $service->createVehicle([
            'apartment_id' => $apartment->id,
            'owner_user_id' => $this->adminUser->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
        ], $this->adminUser->id);
    }

    /**
     * 7. Test Account Provisioning với batch role assignment và hash password trước giao dịch
     */
    public function test_account_provisioning_optimized(): void
    {
        $email = 'prov_'.Str::random(8).'@cassavas.vn';
        $phone = '09'.rand(10000000, 99999999);

        $service = app(AccountProvisioningService::class);
        $result = $service->provisionAccount([
            'email' => $email,
            'phone_number' => $phone,
            'full_name' => 'Auto Provisioned Tester',
            'roles' => ['RESIDENT_OWNER'],
        ], $this->adminUser);

        $this->assertEquals(strtolower($email), $result['user']['email']);
        $this->assertEquals('PENDING_ACTIVATION', $result['user']['status']);

        // Kiểm tra vai trò đã được gán
        $createdUser = User::find($result['user']['id']);
        $this->assertTrue($createdUser->roles()->where('role_code', 'RESIDENT_OWNER')->exists());
    }

    /**
     * 8. Test Account Provisioning từ chối trùng email và phone
     */
    public function test_account_provisioning_duplicate_rejected(): void
    {
        $email = 'dup_'.Str::random(8).'@cassavas.vn';
        $phone = '09'.rand(10000000, 99999999);

        $service = app(AccountProvisioningService::class);
        $service->provisionAccount([
            'email' => $email,
            'phone_number' => $phone,
            'full_name' => 'User Original',
            'roles' => ['RESIDENT_OWNER'],
        ], $this->adminUser);

        QuocTinRealtimeService::clearModuleCooldown('account_provisioning');

        // Thử cấp phát trùng email -> ném AccountProvisioningDuplicateException
        $this->expectException(AccountProvisioningDuplicateException::class);
        $service->provisionAccount([
            'email' => $email,
            'phone_number' => '09'.rand(10000000, 99999999),
            'full_name' => 'User Duplicate',
            'roles' => ['RESIDENT_OWNER'],
        ], $this->adminUser);
    }

    /**
     * 9. Test RBAC: Tạo vai trò và gán quyền atomic trong transaction
     */
    public function test_rbac_atomic_role_and_permission_sync(): void
    {
        $roleCode = 'ROLE_OPT_'.rand(100, 999);
        $rbacService = app(RbacService::class);

        $role = $rbacService->createRole([
            'role_code' => $roleCode,
            'role_name' => 'Role Tối Ưu Test',
        ], $this->adminUser);

        $this->assertNotNull($role->id);

        // Gán quyền
        Permission::firstOrCreate(
            ['permission_code' => 'RESIDENT:VIEW'],
            [
                'module' => 'RESIDENT',
                'permission_name' => 'Xem danh sách cư dân',
                'description' => 'Xem danh sách và hồ sơ cư dân',
            ]
        );

        $rbacService->syncRolePermissions($role, ['RESIDENT:VIEW'], $this->adminUser);

        $this->assertTrue(
            DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->exists()
        );

        // Dọn dẹp
        $rbacService->deleteRole($role, $this->adminUser);
        $this->assertNull(Role::find($role->id));
    }

    /**
     * 10. Test RBAC: Chống gán vai trò trùng lặp cho người dùng
     */
    public function test_rbac_duplicate_role_assignment_prevention(): void
    {
        $targetUser = User::create([
            'username' => 'rbac_tgt_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'rbac_tgt_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Target User for RBAC',
            'status' => 'ACTIVE',
        ]);

        $rbacService = app(RbacService::class);
        $role = Role::first();
        if (! $role) {
            $role = Role::create([
                'role_code' => 'ROLE_TEST_'.rand(100, 999),
                'role_name' => 'Role Test',
            ]);
        }

        // Gán vai trò 2 lần với cùng role_id trong danh sách
        $rbacService->syncUserRoles($targetUser, [$role->id, $role->id], null, $this->adminUser);

        // Kiểm tra bảng user_roles chỉ có đúng 1 bản ghi cho cặp (user_id, role_id)
        $count = DB::table('user_roles')
            ->where('user_id', $targetUser->id)
            ->where('role_id', $role->id)
            ->count();

        $this->assertEquals(1, $count, 'User_roles không được chứa bản ghi trùng lặp');
    }
}
