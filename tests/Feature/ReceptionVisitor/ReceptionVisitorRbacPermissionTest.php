<?php

namespace Tests\Feature\ReceptionVisitor;

use App\Models\Apartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VisitorRegistration;
use App\Services\ReceptionVisitorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReceptionVisitorRbacPermissionTest extends TestCase
{
    protected ReceptionVisitorService $service;

    protected ?Apartment $apartment = null;

    protected ?User $resident = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReceptionVisitorService::class);

        // 1. Tạo căn hộ mẫu
        $this->apartment = $this->createApartment('APT-RBAC-'.Str::random(4));

        // 2. Tạo cư dân mẫu
        $this->resident = $this->createResidentUser();
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->resident->id,
            'apartment_id' => $this->apartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => 1,
            'stay_start_date' => now()->subMonths(3)->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_'.Str::random(4),
                'block_name' => 'Tòa Tháp Test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $floorId = DB::table('floors')->value('id');
        if (! $floorId) {
            $floorId = (string) Str::uuid();
            DB::table('floors')->insert([
                'id' => $floorId,
                'block_id' => $blockId,
                'floor_number' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Apartment::create([
            'apartment_number' => $code,
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 70.0,
        ]);
    }

    protected function createResidentUser(): User
    {
        return User::create([
            'username' => 'resident_rbac_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'resident_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân RBAC Test',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Tạo tài khoản nhân viên với vai trò và danh sách permissions cụ thể
     */
    protected function createStaffWithPermissions(string $roleCode, array $permissions = []): array
    {
        $user = User::create([
            'username' => strtolower($roleCode).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => strtolower($roleCode).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => "Nhân Viên {$roleCode} Test",
            'status' => 'ACTIVE',
        ]);

        // 1. Gán thêm vai trò nhân viên gốc (để nhận diện thuộc nhóm ALLOWED_STAFF_ROLES)
        $origRoleId = DB::table('roles')->where('role_code', $roleCode)->value('id');
        if (! $origRoleId) {
            $origRoleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $origRoleId,
                'role_code' => $roleCode,
                'role_name' => $roleCode,
                'is_system_role' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $origRoleId,
            'is_primary' => 0,
            'assigned_at' => now(),
        ]);

        // 2. Tạo vai trò CUSTOM_USER_... để override quyền hạn chính xác của user này
        // (RbacService & User model ưu tiên CUSTOM_USER_ role trên mọi role khác)
        $customRoleCode = 'CUSTOM_USER_'.strtoupper(Str::random(8));
        $customRoleId = (string) Str::uuid();
        DB::table('roles')->insert([
            'id' => $customRoleId,
            'role_code' => $customRoleCode,
            'role_name' => "Custom Role {$customRoleCode}",
            'is_system_role' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $customRoleId,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        // Gán permissions vào role riêng
        foreach ($permissions as $permCode) {
            $permId = DB::table('permissions')->where('permission_code', $permCode)->value('id');
            if (! $permId) {
                $permId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $permId,
                    'module' => 'VISITOR',
                    'permission_code' => $permCode,
                    'permission_name' => $permCode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('role_permissions')->insert([
                'role_id' => $customRoleId,
                'permission_id' => $permId,
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit RBAC Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token, $customRoleId];
    }

    protected function createRegistration(array $overrides = []): VisitorRegistration
    {
        $code = strtoupper(Str::random(8));

        return VisitorRegistration::create(array_merge([
            'registration_code' => 'VR-RBAC-'.rand(10000, 99999),
            'host_resident_user_id' => $this->resident->id,
            'apartment_id' => $this->apartment->id,
            'visitor_name' => 'Khách Kiểm Tra RBAC',
            'visitor_phone' => '09'.rand(10000000, 99999999),
            'visitor_national_id' => '001200'.rand(100000, 999999),
            'expected_arrival_time' => Carbon::now()->addHour(),
            'expected_departure_time' => Carbon::now()->addHours(3),
            'visit_purpose' => 'Kiểm tra phân quyền',
            'visitor_count' => 1,
            'qr_access_pass_code' => $code,
            'qr_pass_status' => 'ACTIVE',
            'is_pre_approved_by_resident' => true,
        ], $overrides));
    }

    /**
     * Test 01: Yêu cầu không xác thực (unauthenticated) bị từ chối 401 trên mọi endpoint Lễ tân
     */
    public function test_01_unauthenticated_requests_are_rejected_with_401(): void
    {
        $reg = $this->createRegistration();

        $this->getJson('/api/v1/reception/visitors')->assertStatus(401);
        $this->getJson("/api/v1/reception/visitors/lookup?code={$reg->qr_access_pass_code}")->assertStatus(401);
        $this->postJson('/api/v1/reception/visitors/check-in', ['registration_id' => $reg->id])->assertStatus(401);
        $this->postJson('/api/v1/reception/visitors/check-out', ['registration_id' => $reg->id])->assertStatus(401);
    }

    /**
     * Test 02: Cư dân thông thường (không có vai trò nhân viên) bị từ chối 403 Forbidden
     */
    public function test_02_resident_user_cannot_access_reception_endpoints(): void
    {
        $token = 'resident_token_'.$this->resident->id.'_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->resident->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $reg = $this->createRegistration();

        $this->withHeaders($headers)->getJson('/api/v1/reception/visitors')->assertStatus(403);
        $this->withHeaders($headers)->getJson("/api/v1/reception/visitors/lookup?code={$reg->qr_access_pass_code}")->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-in', ['registration_id' => $reg->id])->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-out', ['registration_id' => $reg->id])->assertStatus(403);
    }

    /**
     * Test 03: Nhân viên chỉ có VISITOR:VIEW được phép tra cứu/xem danh sách nhưng BỊ CHẶN 403 khi Check-in / Check-out (BUG-M7-01 Core)
     */
    public function test_03_staff_with_only_visitor_view_can_view_and_lookup_but_cannot_checkin_or_checkout(): void
    {
        // 1. Tạo nhân viên lễ tân với chỉ quyền VISITOR:VIEW (bị thu hồi hoặc không có VISITOR:CHECKIN)
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['VISITOR:VIEW']);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $reg = $this->createRegistration();

        // 2. Thao tác Xem (View & Lookup) -> THÀNH CÔNG (200 OK)
        $listRes = $this->withHeaders($headers)->getJson('/api/v1/reception/visitors');
        $listRes->assertStatus(200);

        $lookupRes = $this->withHeaders($headers)->getJson("/api/v1/reception/visitors/lookup?code={$reg->qr_access_pass_code}");
        $lookupRes->assertStatus(200);

        // 3. Thao tác Check-in -> BỊ CHẶN (403 Forbidden)
        $checkinRes = $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ]);
        $checkinRes->assertStatus(403);
        $this->assertStringContainsString('không có quyền', $checkinRes->json('message'));

        // 4. Thao tác Check-out -> BỊ CHẶN (403 Forbidden)
        $checkoutRes = $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-out', [
            'registration_id' => $reg->id,
        ]);
        $checkoutRes->assertStatus(403);
        $this->assertStringContainsString('không có quyền', $checkoutRes->json('message'));

        // 5. Kiểm tra trực tiếp qua Service: phải ném HttpException với statusCode 403
        try {
            $this->service->checkIn($user, ['registration_id' => $reg->id]);
            $this->fail('Expected HttpException 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * Test 04: Tên vai trò (Role Name) đơn thuần không được phép bypass permission khi thiếu quyền
     */
    public function test_04_staff_role_name_alone_does_not_bypass_missing_permissions(): void
    {
        // Tạo nhân viên với role SECURITY_GUARD nhưng KHÔNG CÓ bất kỳ permission nào
        [$guardUser, $guardToken, $customRoleId] = $this->createStaffWithPermissions('SECURITY_GUARD', []);

        $headers = ['Authorization' => "Bearer {$guardToken}", 'Accept' => 'application/json'];
        $reg = $this->createRegistration();

        // Khi không có VISITOR:VIEW -> Xem bị 403
        $this->withHeaders($headers)->getJson('/api/v1/reception/visitors')->assertStatus(403);
        $this->withHeaders($headers)->getJson("/api/v1/reception/visitors/lookup?code={$reg->qr_access_pass_code}")->assertStatus(403);

        // Khi không có VISITOR:CHECKIN -> Check-in bị 403
        $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ])->assertStatus(403);
    }

    /**
     * Test 05: Nhân viên có đầy đủ quyền VISITOR:VIEW và VISITOR:CHECKIN có thể thực hiện mọi thao tác
     */
    public function test_05_staff_with_both_permissions_can_perform_all_operations(): void
    {
        [$staffUser, $staffToken] = $this->createStaffWithPermissions('RECEPTIONIST', [
            'VISITOR:VIEW',
            'VISITOR:CHECKIN',
        ]);

        $headers = ['Authorization' => "Bearer {$staffToken}", 'Accept' => 'application/json'];
        $reg = $this->createRegistration();

        // 1. List
        $this->withHeaders($headers)->getJson('/api/v1/reception/visitors')->assertStatus(200);

        // 2. Lookup
        $this->withHeaders($headers)->getJson("/api/v1/reception/visitors/lookup?code={$reg->qr_access_pass_code}")->assertStatus(200);

        // 3. Check-in
        $checkinRes = $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ]);
        $checkinRes->assertStatus(200);
        $checkinLogId = $checkinRes->json('data.checkin_log.id');

        // 4. Check-out
        $checkoutRes = $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-out', [
            'checkin_log_id' => $checkinLogId,
        ]);
        $checkoutRes->assertStatus(200);
    }

    /**
     * Test 06: Thu hồi quyền có hiệu lực ngay trong lần request tiếp theo (không bị stale session)
     */
    public function test_06_permission_revocation_takes_effect_immediately(): void
    {
        [$staffUser, $staffToken, $customRoleId] = $this->createStaffWithPermissions('RECEPTIONIST', [
            'VISITOR:VIEW',
            'VISITOR:CHECKIN',
        ]);

        $headers = ['Authorization' => "Bearer {$staffToken}", 'Accept' => 'application/json'];
        $reg1 = $this->createRegistration();

        // Lần 1: Có quyền -> Check-in thành công
        $res1 = $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg1->id,
        ]);
        $res1->assertStatus(200);

        // Thu hồi quyền VISITOR:CHECKIN khỏi custom role của user này
        $checkinPermId = DB::table('permissions')->where('permission_code', 'VISITOR:CHECKIN')->value('id');
        DB::table('role_permissions')
            ->where('role_id', $customRoleId)
            ->where('permission_id', $checkinPermId)
            ->delete();

        // Lần 2: Check-in bản ghi mới ngay lập tức bị 403 Forbidden mà không cần logout/login lại
        $reg2 = $this->createRegistration();
        $res2 = $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg2->id,
        ]);
        $res2->assertStatus(403);
    }

    /**
     * Test 07: Quản trị viên cấp cao (SUPER_ADMIN) bypass toàn bộ permission hợp lệ
     */
    public function test_07_super_admin_bypasses_all_permission_checks(): void
    {
        $adminUser = User::create([
            'username' => 'superadmin_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'admin_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Quản Trị Viên Tối Cao',
            'status' => 'ACTIVE',
        ]);

        $roleId = DB::table('roles')->where('role_code', 'SUPER_ADMIN')->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId,
                'role_code' => 'SUPER_ADMIN',
                'role_name' => 'SUPER_ADMIN',
                'is_system_role' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $adminUser->id,
            'role_id' => $roleId,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'smart_token_'.$adminUser->id.'_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $adminUser->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'PHPUnit Admin',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $reg = $this->createRegistration();

        // SUPER_ADMIN có thể list, lookup, check-in, check-out không gặp trở ngại
        $this->withHeaders($headers)->getJson('/api/v1/reception/visitors')->assertStatus(200);
        $this->withHeaders($headers)->getJson("/api/v1/reception/visitors/lookup?code={$reg->qr_access_pass_code}")->assertStatus(200);

        $checkinRes = $this->withHeaders($headers)->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ]);
        $checkinRes->assertStatus(200);
    }

    /**
     * Test 08: Phương thức assertStaffAuthorized() kiểm tra chính xác từng nhánh và ném đúng HTTP Exception
     */
    public function test_08_assert_staff_authorized_direct_method_verification(): void
    {
        // 1. null user -> 401
        try {
            $this->service->assertStaffAuthorized(null, 'view');
            $this->fail('Expected HttpException 401');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        // 2. Cư dân thông thường -> 403
        try {
            $this->service->assertStaffAuthorized($this->resident, 'view');
            $this->fail('Expected HttpException 403 for resident');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // 3. Nhân viên có VISITOR:VIEW gọi 'view' -> OK (không throw)
        [$viewStaff] = $this->createStaffWithPermissions('RECEPTIONIST', ['VISITOR:VIEW']);
        $this->service->assertStaffAuthorized($viewStaff, 'view');
        $this->assertTrue(true);

        // 4. Nhân viên có VISITOR:VIEW gọi 'checkin' -> 403
        try {
            $this->service->assertStaffAuthorized($viewStaff, 'checkin');
            $this->fail('Expected HttpException 403 for checkin with view-only staff');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // 5. Nhân viên có VISITOR:CHECKIN gọi 'checkin' -> OK (không throw)
        [$checkinStaff] = $this->createStaffWithPermissions('RECEPTIONIST', ['VISITOR:CHECKIN']);
        $this->service->assertStaffAuthorized($checkinStaff, 'checkin');
        $this->assertTrue(true);
    }
}
