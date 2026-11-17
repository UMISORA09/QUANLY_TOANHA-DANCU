<?php

namespace Tests\Feature\ReceptionPortal;

use App\Models\Apartment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionPortalOverviewTest extends TestCase
{
    use DatabaseTransactions;

    protected Apartment $apartment;

    protected User $residentUser;

    protected string $residentToken;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo căn hộ mẫu
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
                'floor_number' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->apartment = Apartment::firstOrCreate(
            ['apartment_number' => 'APT-REC-'.Str::random(4)],
            [
                'block_id' => $blockId,
                'floor_id' => $floorId,
                'status' => 'OCCUPIED',
                'room_type' => '2BR',
                'gross_floor_area_sqm' => 75.0,
                'net_usable_area_sqm' => 70.0,
            ]
        );

        // 2. Tạo cư dân mẫu với vai trò RESIDENT
        $this->residentUser = User::create([
            'username' => 'resident_overview_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'resident_overview_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Test Overview',
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

        $this->residentToken = 'token_res_'.Str::random(32);
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
     * Tạo tài khoản nhân viên với vai trò chỉ định
     */
    protected function createStaff(string $roleCode): array
    {
        $user = User::create([
            'username' => strtolower($roleCode).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => strtolower($roleCode).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => "Nhân Viên {$roleCode} Test",
            'status' => 'ACTIVE',
        ]);

        $roleId = DB::table('roles')->where('role_code', $roleCode)->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId,
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
            'role_id' => $roleId,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'staff_token_'.Str::random(32);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'PHPUnit',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Test 01: Yêu cầu không có Bearer token bị chặn HTTP 401
     */
    public function test_01_unauthenticated_request_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reception/overview');

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error' => 'UNAUTHORIZED',
        ]);
    }

    /**
     * Test 02: Token sai, bị thu hồi hoặc hết hạn bị chặn HTTP 401
     */
    public function test_02_request_with_invalid_or_revoked_or_expired_token_returns_401(): void
    {
        // 1. Token sai hoàn toàn
        $this->withHeaders(['Authorization' => 'Bearer completely_invalid_token_12345'])
            ->getJson('/api/v1/reception/overview')
            ->assertStatus(401);

        // 2. Token bị thu hồi (is_revoked = 1)
        $revokedToken = 'revoked_token_'.Str::random(32);
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
            ->getJson('/api/v1/reception/overview')
            ->assertStatus(401)
            ->assertJson([
                'error' => 'TOKEN_REVOKED',
            ]);

        // 3. Token hết hạn
        $expiredToken = 'expired_token_'.Str::random(32);
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
            ->getJson('/api/v1/reception/overview')
            ->assertStatus(401)
            ->assertJson([
                'error' => 'TOKEN_EXPIRED',
            ]);
    }

    /**
     * Test 03: Tài khoản cư dân thông thường bị chặn HTTP 403 Forbidden
     */
    public function test_03_resident_user_cannot_access_reception_overview_and_gets_403(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->residentToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/overview');

        $response->assertStatus(403);
    }

    /**
     * Test 04: Nhân viên Lễ tân (RECEPTIONIST) truy cập thành công 200
     */
    public function test_04_receptionist_can_access_overview_successfully(): void
    {
        [$user, $token] = $this->createStaff('RECEPTIONIST');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/overview');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'kpis' => [
                'activeGuests',
                'activeGuestsNote',
                'pendingParcels',
                'expiringParcels',
                'overdueParcelsNote',
                'pendingVehicles',
                'pendingVehiclesNote',
                'securityIncidents',
                'securityIncidentsNote',
            ],
            'activities',
            'notifications',
        ]);
    }

    /**
     * Test 05: Nhân viên Bảo vệ (SECURITY_GUARD) truy cập thành công 200
     */
    public function test_05_security_guard_can_access_overview_successfully(): void
    {
        [$user, $token] = $this->createStaff('SECURITY_GUARD');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/overview');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'kpis',
            'activities',
            'notifications',
        ]);
    }

    /**
     * Test 06: Quản lý tòa nhà (BUILDING_MANAGER) và SUPER_ADMIN truy cập thành công 200
     */
    public function test_06_building_manager_and_super_admin_can_access_overview_successfully(): void
    {
        [$bmUser, $bmToken] = $this->createStaff('BUILDING_MANAGER');
        $this->withHeaders(['Authorization' => "Bearer {$bmToken}"])
            ->getJson('/api/v1/reception/overview')
            ->assertStatus(200);

        [$adminUser, $adminToken] = $this->createStaff('SUPER_ADMIN');
        $this->withHeaders(['Authorization' => "Bearer {$adminToken}"])
            ->getJson('/api/v1/reception/overview')
            ->assertStatus(200);
    }

    /**
     * Test 07: Khi dữ liệu bảng là 0, API trả về 0 chính xác, KHÔNG ngụy tạo số liệu giả (18, 42, 4, 7, 02)
     */
    public function test_07_zero_counts_are_accurately_returned_as_zero_without_fake_numbers(): void
    {
        // Xóa tạm dữ liệu trong transaction để kiểm tra trường hợp counts = 0
        DB::table('visitor_checkin_logs')->delete();
        DB::table('parcels')->delete();
        DB::table('vehicles')->delete();
        if (DB::getSchemaBuilder()->hasTable('security_incidents')) {
            DB::table('security_incidents')->delete();
        }

        [$user, $token] = $this->createStaff('RECEPTIONIST');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/overview');

        $response->assertStatus(200);

        $kpis = $response->json('kpis');
        $this->assertSame(0, $kpis['activeGuests'], 'activeGuests must be 0, not fake 18');
        $this->assertSame(0, $kpis['pendingParcels'], 'pendingParcels must be 0, not fake 42');
        $this->assertSame('0 bưu phẩm quá hạn', $kpis['overdueParcelsNote'], 'overdueParcelsNote must reflect 0, not fake 4');
        $this->assertSame(0, $kpis['pendingVehicles'], 'pendingVehicles must be 0, not fake 7');
        $this->assertSame('00', $kpis['securityIncidents'], 'securityIncidents must be 00, not fake 02');

        // Activities và Notifications không chứa thông tin giả mạo đã bị loại bỏ
        $activities = $response->json('activities');
        $this->assertIsArray($activities);
        $this->assertEmpty($activities, 'Activities must be empty when no recent events exist');
    }

    /**
     * Test 08: Dữ liệu thực từ database được phản ánh chính xác trong KPIs và Activities
     */
    public function test_08_real_database_records_are_reflected_accurately(): void
    {
        // Làm sạch dữ liệu trong transaction
        DB::table('visitor_checkin_logs')->delete();
        DB::table('parcels')->delete();
        DB::table('vehicles')->delete();
        if (DB::getSchemaBuilder()->hasTable('security_incidents')) {
            DB::table('security_incidents')->delete();
        }

        [$staffUser, $token] = $this->createStaff('RECEPTIONIST');

        // 1. Thêm 1 khách check-in đang trong tòa
        $guestName = 'Khách Thực Tế Thử Nghiệm';
        DB::table('visitor_checkin_logs')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $this->apartment->id,
            'visitor_name' => $guestName,
            'checkin_time' => Carbon::now()->subMinutes(15),
            'checkout_time' => null,
            'receptionist_user_id' => $staffUser->id,
            'created_at' => now(),
        ]);

        // 2. Thêm 1 bưu phẩm chờ nhận
        $trackingNumber = 'PKG-REAL-999';
        DB::table('parcels')->insert([
            'id' => (string) Str::uuid(),
            'tracking_number' => $trackingNumber,
            'courier_company' => 'GHTK Real',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Người Nhận Test',
            'recipient_phone' => '0912345678',
            'parcel_photo_url' => 'https://example.com/parcel.jpg',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'QR-PARCEL-999',
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => Carbon::now()->subMinutes(30),
            'received_by_staff_id' => $staffUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Thêm 1 xe chờ duyệt
        $plate = '51F-'.rand(10000, 99999);
        DB::table('vehicles')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $this->apartment->id,
            'owner_user_id' => $this->residentUser->id,
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
            'is_active' => 0,
            'approved_by' => null,
            'created_at' => Carbon::now()->subMinutes(45),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/overview');

        $response->assertStatus(200);

        $kpis = $response->json('kpis');
        $this->assertSame(1, $kpis['activeGuests']);
        $this->assertSame(1, $kpis['pendingParcels']);
        $this->assertSame(1, $kpis['pendingVehicles']);

        $activities = $response->json('activities');
        $this->assertCount(3, $activities);
        $this->assertStringContainsString($guestName, $activities[0]['title']);
        $this->assertStringContainsString($trackingNumber, $activities[1]['title']);
        $this->assertStringContainsString($plate, $activities[2]['title']);
    }
}
