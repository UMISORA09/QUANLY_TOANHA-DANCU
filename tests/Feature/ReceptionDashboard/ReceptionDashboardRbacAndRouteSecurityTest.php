<?php

namespace Tests\Feature\ReceptionDashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionDashboardRbacAndRouteSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $residentUser;

    protected string $residentToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Tạo tài khoản cư dân mẫu với vai trò RESIDENT
        $this->residentUser = User::create([
            'username' => 'resident_dash_sec_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'resident_dash_sec_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Test Dashboard RBAC',
            'status' => 'ACTIVE',
        ]);

        $residentRoleId = DB::table('roles')->where('role_code', 'RESIDENT')->value('id');
        if (! $residentRoleId) {
            $residentRoleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $residentRoleId,
                'role_code' => 'RESIDENT',
                'role_name' => 'Cư dân',
                'is_system_role' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->residentUser->id,
            'role_id' => $residentRoleId,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $this->residentToken = 'token_dash_res_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->residentUser->id,
            'refresh_token_hash' => hash('sha256', $this->residentToken),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);
    }

    /**
     * Tạo tài khoản nhân viên với vai trò và danh sách permission cụ thể
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

        // 1. Gán vai trò nhân viên gốc (để nhận diện nhóm ALLOWED_STAFF_ROLES)
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
            'device_name' => 'PHPUnit Dashboard Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token, $customRoleId];
    }

    /**
     * Test 01: Yêu cầu không có Bearer Token trên Dashboard và Visitors routes đều bị chặn HTTP 401
     */
    public function test_01_unauthenticated_requests_on_dashboard_and_visitors_routes_return_401(): void
    {
        // 1. Dashboard routes
        $this->getJson('/api/v1/reception/dashboard')->assertStatus(401);
        $this->getJson('/api/reception/dashboard')->assertStatus(401);

        // 2. Visitors routes
        $this->getJson('/api/v1/reception/visitors')->assertStatus(401);
        $this->getJson('/api/v1/reception/visitors/lookup')->assertStatus(401);
        $this->postJson('/api/v1/reception/visitors/check-in', [])->assertStatus(401);
        $this->postJson('/api/v1/reception/visitors/check-out', [])->assertStatus(401);
    }

    /**
     * Test 02: Token không hợp lệ, hết hạn hoặc bị thu hồi trên Dashboard bị chặn HTTP 401
     */
    public function test_02_invalid_revoked_or_expired_tokens_on_dashboard_return_401(): void
    {
        // Token sai hoàn toàn
        $this->withHeaders(['Authorization' => 'Bearer fake_invalid_dashboard_token'])
            ->getJson('/api/v1/reception/dashboard')
            ->assertStatus(401);

        // Token bị thu hồi (is_revoked = 1)
        $revokedToken = 'revoked_dash_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->residentUser->id,
            'refresh_token_hash' => hash('sha256', $revokedToken),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 1,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$revokedToken}"])
            ->getJson('/api/v1/reception/dashboard')
            ->assertStatus(401);

        // Token hết hạn
        $expiredToken = 'expired_dash_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->residentUser->id,
            'refresh_token_hash' => hash('sha256', $expiredToken),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->subDay(),
            'created_at' => now()->subDays(2),
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$expiredToken}"])
            ->getJson('/api/v1/reception/dashboard')
            ->assertStatus(401);
    }

    /**
     * Test 03: Cư dân thông thường (không có role nhân viên) bị chặn HTTP 403 Forbidden
     */
    public function test_03_resident_user_cannot_access_dashboard_and_gets_403(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->residentToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(403);
    }

    /**
     * Test 04: Nhân viên Lễ tân (RECEPTIONIST) có quyền VISITOR:VIEW truy cập Dashboard thành công 200
     */
    public function test_04_receptionist_with_visitor_view_can_access_dashboard_successfully(): void
    {
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['VISITOR:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    /**
     * Test 05: Nhân viên Bảo vệ (SECURITY_GUARD) có quyền VISITOR:VIEW truy cập Dashboard thành công 200
     */
    public function test_05_security_guard_with_visitor_view_can_access_dashboard_successfully(): void
    {
        [$user, $token] = $this->createStaffWithPermissions('SECURITY_GUARD', ['VISITOR:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    /**
     * Test 06: LỖI GỐC BUG-M7-03 — Nhân viên RECEPTIONIST bị thu hồi quyền xem (không có VISITOR:VIEW, PARCEL:VIEW, DASHBOARD:VIEW) BỊ CHẶN 403, không được bypass bằng Role name
     */
    public function test_06_receptionist_without_view_permissions_is_rejected_with_403(): void
    {
        // Tạo nhân viên với role RECEPTIONIST nhưng KHÔNG cấp quyền VISITOR:VIEW hay PARCEL:VIEW
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['TICKET:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        // BẮT BUỘC BỊ CHẶN 403 (không được phép bypass chỉ vì mang role RECEPTIONIST)
        $response->assertStatus(403);
    }

    /**
     * Test 07: Nhân viên SECURITY_GUARD bị thu hồi quyền xem BỊ CHẶN 403
     */
    public function test_07_security_guard_without_view_permissions_is_rejected_with_403(): void
    {
        [$user, $token] = $this->createStaffWithPermissions('SECURITY_GUARD', ['TICKET:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(403);
    }

    /**
     * Test 08: Quản trị viên cấp cao (SUPER_ADMIN) bypass quyền hạn hợp lệ và truy cập thành công 200
     */
    public function test_08_super_admin_bypasses_permissions_successfully(): void
    {
        [$user, $token] = $this->createStaffWithPermissions('SUPER_ADMIN', []);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    /**
     * Tạo dữ liệu mẫu phân hệ Bưu phẩm & Khách viếng thăm với dữ liệu nhận diện riêng biệt
     *
     * @return array{tracking_number: string, recipient_name: string, recipient_phone: string, visitor_name: string, visitor_phone: string, visit_purpose: string}
     */
    protected function seedDistinctSampleData(): array
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_SEC_'.Str::random(4),
                'block_name' => 'Tòa Bảo Mật',
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
                'floor_number' => 5,
                'floor_code' => 'F5',
                'floor_name' => 'Tầng 5',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $aptId = (string) Str::uuid();
        DB::table('apartments')->insert([
            'id' => $aptId,
            'apartment_number' => 'APT-SEC-'.Str::random(4),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 70,
            'net_usable_area_sqm' => 65,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $trackingNumber = 'PKG-SEC-TRK-'.Str::random(6);
        $recipientName = 'Người Nhận Bưu Phẩm Mẫu '.Str::random(4);
        $recipientPhone = '0988'.rand(100000, 999999);

        $staffUserId = DB::table('users')->where('status', 'ACTIVE')->value('id') ?? $this->residentUser->id;

        $parcelId = (string) Str::uuid();
        DB::table('parcels')->insert([
            'id' => $parcelId,
            'tracking_number' => $trackingNumber,
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'apartment_id' => $aptId,
            'courier_company' => 'Shopee Express',
            'status' => 'RECEIVED_AT_RECEPTION',
            'stored_location_type' => 'RECEPTION_SHELF',
            'received_at' => now()->subDays(60),
            'pickup_pin_code' => '654321',
            'pickup_qr_code' => 'QR-'.Str::random(10),
            'parcel_photo_url' => '/images/parcels/sample.jpg',
            'received_by_staff_id' => $staffUserId,
            'created_at' => now()->subDays(60),
            'updated_at' => now(),
        ]);

        $visitorName = 'Khách Viếng Thăm Mẫu '.Str::random(4);
        $visitorPhone = '0912'.rand(100000, 999999);
        $visitPurpose = 'Gặp Gỡ Bảo Mật '.Str::random(4);

        $regId = (string) Str::uuid();
        DB::table('visitor_registrations')->insert([
            'id' => $regId,
            'registration_code' => 'REG-SEC-'.Str::random(5),
            'apartment_id' => $aptId,
            'host_resident_user_id' => $this->residentUser->id,
            'visitor_name' => $visitorName,
            'visitor_phone' => $visitorPhone,
            'expected_arrival_time' => now()->subHour(),
            'visit_purpose' => $visitPurpose,
            'qr_access_pass_code' => 'QR-PASS-'.Str::random(10),
            'qr_pass_status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checkinLogId = (string) Str::uuid();
        DB::table('visitor_checkin_logs')->insert([
            'id' => $checkinLogId,
            'registration_id' => $regId,
            'visitor_name' => $visitorName,
            'visitor_phone' => $visitorPhone,
            'apartment_id' => $aptId,
            'checkin_time' => now()->subMinutes(30),
            'checkout_time' => null,
            'receptionist_user_id' => $staffUserId,
            'created_at' => now(),
        ]);

        return [
            'tracking_number' => $trackingNumber,
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'visitor_name' => $visitorName,
            'visitor_phone' => $visitorPhone,
            'visit_purpose' => $visitPurpose,
        ];
    }

    /**
     * Test 09: Granular Isolation — User chỉ có VISITOR:VIEW xem được dữ liệu Visitor, tuyệt đối không có dữ liệu Parcel
     */
    public function test_09_granular_isolation_staff_with_only_visitor_view_cannot_read_parcel_data(): void
    {
        $sample = $this->seedDistinctSampleData();
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['VISITOR:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.scope.can_view_visitors', true);
        $response->assertJsonPath('data.meta.scope.can_view_parcels', false);

        // 1. Phân hệ Visitor được xem bình thường
        $this->assertArrayHasKey('visitors', $response->json('data.kpis') ?? []);
        $this->assertArrayHasKey('current_visitors_inside', $response->json('data') ?? []);
        $this->assertArrayHasKey('visitor_purposes', $response->json('data.breakdowns') ?? []);
        $this->assertStringContainsString($sample['visitor_name'], $response->getContent());

        // 2. Tuyệt đối KHÔNG có dữ liệu Parcel trong JSON response
        $this->assertArrayNotHasKey('parcels', $response->json('data.kpis') ?? []);
        $this->assertArrayNotHasKey('top_pending_parcels', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('carriers', $response->json('data.breakdowns') ?? []);
        $this->assertArrayNotHasKey('parcels', $response->json('data') ?? []);

        // 3. Không rò rỉ mã vận đơn, tên người nhận hoặc số điện thoại bưu phẩm mẫu
        $this->assertStringNotContainsString($sample['tracking_number'], $response->getContent());
        $this->assertStringNotContainsString($sample['recipient_name'], $response->getContent());
        $this->assertStringNotContainsString($sample['recipient_phone'], $response->getContent());
    }

    /**
     * Test 10: Granular Isolation — User chỉ có PARCEL:VIEW xem được dữ liệu Parcel, tuyệt đối không có dữ liệu Visitor
     */
    public function test_10_granular_isolation_staff_with_only_parcel_view_cannot_read_visitor_data(): void
    {
        $sample = $this->seedDistinctSampleData();
        [$user, $token] = $this->createStaffWithPermissions('STAFF', ['PARCEL:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.scope.can_view_visitors', false);
        $response->assertJsonPath('data.meta.scope.can_view_parcels', true);

        // 1. Phân hệ Parcel được xem bình thường
        $this->assertArrayHasKey('parcels', $response->json('data.kpis') ?? []);
        $this->assertArrayHasKey('top_pending_parcels', $response->json('data') ?? []);
        $this->assertArrayHasKey('carriers', $response->json('data.breakdowns') ?? []);
        $this->assertStringContainsString($sample['tracking_number'], $response->getContent());

        // 2. Tuyệt đối KHÔNG có dữ liệu Visitor trong JSON response
        $this->assertArrayNotHasKey('visitors', $response->json('data.kpis') ?? []);
        $this->assertArrayNotHasKey('current_visitors_inside', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('visitor_purposes', $response->json('data.breakdowns') ?? []);
        $this->assertArrayNotHasKey('visitors', $response->json('data') ?? []);

        // 3. Không rò rỉ họ tên khách, số điện thoại hoặc mục đích thăm mẫu
        $this->assertStringNotContainsString($sample['visitor_name'], $response->getContent());
        $this->assertStringNotContainsString($sample['visitor_phone'], $response->getContent());
        $this->assertStringNotContainsString($sample['visit_purpose'], $response->getContent());
    }

    /**
     * Test 11: Granular Isolation — User chỉ có DASHBOARD:VIEW chỉ xem được tổng quan an toàn, không có PII hoặc danh sách chi tiết
     */
    public function test_11_granular_isolation_staff_with_only_dashboard_view_cannot_read_pii_or_lists(): void
    {
        $sample = $this->seedDistinctSampleData();
        [$user, $token] = $this->createStaffWithPermissions('BUILDING_MANAGER', ['DASHBOARD:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.scope.can_view_visitors', false);
        $response->assertJsonPath('data.meta.scope.can_view_parcels', false);

        // 1. Tuyệt đối KHÔNG trả về danh sách chi tiết bản ghi của 2 phân hệ
        $this->assertArrayNotHasKey('top_pending_parcels', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('current_visitors_inside', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('parcels', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('visitors', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('parcels', $response->json('data.kpis') ?? []);
        $this->assertArrayNotHasKey('visitors', $response->json('data.kpis') ?? []);

        // 2. Không chứa bất kỳ PII, tracking number hoặc dữ liệu định danh nào
        $this->assertStringNotContainsString($sample['tracking_number'], $response->getContent());
        $this->assertStringNotContainsString($sample['recipient_name'], $response->getContent());
        $this->assertStringNotContainsString($sample['recipient_phone'], $response->getContent());
        $this->assertStringNotContainsString($sample['visitor_name'], $response->getContent());
        $this->assertStringNotContainsString($sample['visitor_phone'], $response->getContent());
        $this->assertStringNotContainsString($sample['visit_purpose'], $response->getContent());
    }

    /**
     * Test 12: Granular Isolation — User có cả hai quyền VISITOR:VIEW và PARCEL:VIEW nhận đầy đủ cả hai nhóm widget
     */
    public function test_12_granular_isolation_staff_with_both_views_receives_full_widgets(): void
    {
        $sample = $this->seedDistinctSampleData();
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['VISITOR:VIEW', 'PARCEL:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.scope.can_view_visitors', true);
        $response->assertJsonPath('data.meta.scope.can_view_parcels', true);

        // Đầy đủ cả 2 nhóm widget
        $this->assertArrayHasKey('parcels', $response->json('data.kpis') ?? []);
        $this->assertArrayHasKey('visitors', $response->json('data.kpis') ?? []);
        $this->assertArrayHasKey('top_pending_parcels', $response->json('data') ?? []);
        $this->assertArrayHasKey('current_visitors_inside', $response->json('data') ?? []);
        $this->assertArrayHasKey('carriers', $response->json('data.breakdowns') ?? []);
        $this->assertArrayHasKey('visitor_purposes', $response->json('data.breakdowns') ?? []);

        // Dữ liệu mẫu của cả 2 phân hệ đều xuất hiện
        $this->assertStringContainsString($sample['tracking_number'], $response->getContent());
        $this->assertStringContainsString($sample['visitor_name'], $response->getContent());
    }

    /**
     * Test 13: Granular Isolation — User nhân viên không có quyền xem bị chặn HTTP 403 và không rò rỉ dữ liệu
     */
    public function test_13_granular_isolation_staff_without_permissions_is_forbidden_403_and_leaks_nothing(): void
    {
        $sample = $this->seedDistinctSampleData();
        [$user, $token] = $this->createStaffWithPermissions('RECEPTIONIST', ['TICKET:VIEW']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(403);
        $this->assertStringNotContainsString($sample['tracking_number'], $response->getContent());
        $this->assertStringNotContainsString($sample['recipient_name'], $response->getContent());
        $this->assertStringNotContainsString($sample['visitor_name'], $response->getContent());
    }

    /**
     * Test 14: Granular Isolation — Super Admin xem đầy đủ cả 2 phân hệ
     */
    public function test_14_granular_isolation_super_admin_receives_full_dashboard_data(): void
    {
        $sample = $this->seedDistinctSampleData();
        [$user, $token] = $this->createStaffWithPermissions('SUPER_ADMIN', []);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.scope.is_super_admin', true);
        $response->assertJsonPath('data.meta.scope.can_view_visitors', true);
        $response->assertJsonPath('data.meta.scope.can_view_parcels', true);

        // Đầy đủ cả 2 nhóm widget
        $this->assertArrayHasKey('parcels', $response->json('data.kpis') ?? []);
        $this->assertArrayHasKey('visitors', $response->json('data.kpis') ?? []);
        $this->assertArrayHasKey('top_pending_parcels', $response->json('data') ?? []);
        $this->assertArrayHasKey('current_visitors_inside', $response->json('data') ?? []);
        $this->assertStringContainsString($sample['tracking_number'], $response->getContent());
        $this->assertStringContainsString($sample['visitor_name'], $response->getContent());
    }
}
