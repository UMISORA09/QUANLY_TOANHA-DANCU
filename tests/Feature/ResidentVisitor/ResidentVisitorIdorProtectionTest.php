<?php

namespace Tests\Feature\ResidentVisitor;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\User;
use App\Services\QuocTinRealtimeService;
use App\Services\ResidentVisitorService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ResidentVisitorIdorProtectionTest extends TestCase
{
    use DatabaseTransactions;

    protected Apartment $apartmentA;

    protected Apartment $apartmentB;

    protected User $residentUserA;

    protected string $residentTokenA;

    protected User $residentUserB;

    protected string $residentTokenB;

    protected User $attackerNoApt;

    protected string $attackerToken;

    protected User $adminUser;

    protected string $adminToken;

    protected User $receptionistUser;

    protected string $receptionistToken;

    protected ResidentVisitorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->service = app(ResidentVisitorService::class);

        QuocTinRealtimeService::clearModuleCooldown('visitors');

        // 1. Tạo block & floor
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_IDOR_'.Str::random(4),
                'block_name' => 'Tháp An Ninh IDOR',
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
                'floor_number' => 12,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Tạo 2 căn hộ riêng biệt
        $this->apartmentA = Apartment::create([
            'apartment_number' => 'APT-IDOR-A-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 85.0,
            'net_usable_area_sqm' => 80.0,
        ]);

        $this->apartmentB = Apartment::create([
            'apartment_number' => 'APT-IDOR-B-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 85.0,
            'net_usable_area_sqm' => 80.0,
        ]);

        // 3. Cư dân A (Căn hộ A)
        [$this->residentUserA, $this->residentTokenA] = $this->createUserWithSession('res_a_idor_');
        Resident::create([
            'user_id' => $this->residentUserA->id,
            'apartment_id' => $this->apartmentA->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subMonths(6)->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        // 4. Cư dân B (Căn hộ B - Nạn nhân)
        [$this->residentUserB, $this->residentTokenB] = $this->createUserWithSession('res_b_idor_');
        Resident::create([
            'user_id' => $this->residentUserB->id,
            'apartment_id' => $this->apartmentB->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subMonths(6)->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        // 5. Attacker: tài khoản đã đăng nhập nhưng CHƯA thuộc căn hộ nào
        [$this->attackerNoApt, $this->attackerToken] = $this->createUserWithSession('attacker_idor_');

        // 6. Admin
        [$this->adminUser, $this->adminToken] = $this->createUserWithSession('admin_idor_');
        $this->assignRole($this->adminUser->id, 'ADMIN');

        // 7. Lễ tân (Receptionist)
        [$this->receptionistUser, $this->receptionistToken] = $this->createUserWithSession('rec_idor_');
        $this->assignRole($this->receptionistUser->id, 'RECEPTIONIST');
    }

    protected function createUserWithSession(string $prefix): array
    {
        $user = User::create([
            'username' => $prefix.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'User '.strtoupper(substr($prefix, 0, 8)),
            'status' => 'ACTIVE',
        ]);

        $token = 'token_test_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Test Runner',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function assignRole(string $userId, string $roleCode): void
    {
        $roleId = DB::table('roles')->where('role_code', $roleCode)->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId,
                'role_code' => $roleCode,
                'role_name' => $roleCode,
                'description' => 'Role '.$roleCode,
                'is_system_role' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);
    }

    protected function authHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }

    /**
     * Test 1: BUG-M6-01 IDOR Kịch bản chính:
     * Kẻ tấn công chưa thuộc căn hộ nào gửi POST /api/v1/resident/visitors với apartment_id của nạn nhân (Căn B).
     * Kỳ vọng: PHẢI trả về 403 Forbidden, KHÔNG được cấp QR Pass ACTIVE vào căn hộ nạn nhân.
     */
    public function test_01_attacker_without_apartment_cannot_register_visitor_for_victim_apartment_returns_403(): void
    {
        $countBefore = DB::table('visitor_registrations')->count();
        $eventBefore = DB::table('realtime_sync_events')->count();

        $payload = [
            'visitor_name' => 'Khách Của Attacker Xâm Nhập B',
            'visitor_phone' => '0988001122',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentB->id,
            'visit_purpose' => 'Khai thác IDOR cấp pass căn hộ người khác',
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->attackerToken));

        $response->assertStatus(403);
        $this->assertStringContainsString('không có quyền đăng ký khách', (string) $response->json('message'));

        // Đảm bảo không bản ghi nào được ghi vào cơ sở dữ liệu
        $this->assertSame($countBefore, DB::table('visitor_registrations')->count());
        $this->assertSame($eventBefore, DB::table('realtime_sync_events')->count());

        $exists = DB::table('visitor_registrations')
            ->where('apartment_id', $this->apartmentB->id)
            ->where('host_resident_user_id', $this->attackerNoApt->id)
            ->exists();
        $this->assertFalse($exists);
    }

    /**
     * Test 2: BUG-M6-01 IDOR Giữa Cư dân khác căn hộ:
     * Cư dân A gửi apartment_id của Căn B.
     * Kỳ vọng: PHẢI trả về 403 Forbidden.
     */
    public function test_02_resident_of_apartment_a_cannot_register_visitor_for_apartment_b_returns_403(): void
    {
        $countBefore = DB::table('visitor_registrations')->count();

        $payload = [
            'visitor_name' => 'Khách A Gửi Nhầm Hoặc Cố Tình Vào B',
            'visitor_phone' => '0988001133',
            'expected_arrival_time' => Carbon::now()->addHours(3)->toIso8601String(),
            'apartment_id' => $this->apartmentB->id,
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->residentTokenA));

        $response->assertStatus(403);
        $this->assertStringContainsString('không có quyền đăng ký khách', (string) $response->json('message'));
        $this->assertSame($countBefore, DB::table('visitor_registrations')->count());
    }

    /**
     * Test 3: Cư dân hợp lệ gửi đúng apartment_id của chính mình:
     * Kỳ vọng: HTTP 201 Created, phát hành QR Pass ACTIVE hợp lệ.
     */
    public function test_03_resident_with_valid_apartment_can_register_visitor_for_own_apartment_returns_201(): void
    {
        $payload = [
            'visitor_name' => 'Khách Hợp Lệ Của Cư Dân A',
            'visitor_phone' => '0988001144',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentA->id,
            'visit_purpose' => 'Giao dịch gia đình',
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->residentTokenA));

        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $this->assertEquals($this->apartmentA->id, $response->json('data.apartment_id'));
        $this->assertEquals($this->residentUserA->id, $response->json('data.host_resident_user_id'));
        $this->assertEquals('ACTIVE', $response->json('data.qr_pass_status'));
        $this->assertStringStartsWith('PASS_', (string) $response->json('data.qr_access_pass_code'));
    }

    /**
     * Test 4: Cư dân hợp lệ không truyền apartment_id trong payload:
     * Kỳ vọng: Tự động resolve căn hộ được cấp quyền của cư dân và trả về 201 Created.
     */
    public function test_04_resident_without_apartment_id_in_payload_auto_resolves_own_apartment_returns_201(): void
    {
        $payload = [
            'visitor_name' => 'Khách Tự Động Định Tuyến Căn A',
            'visitor_phone' => '0988001155',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->residentTokenA));

        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $this->assertEquals($this->apartmentA->id, $response->json('data.apartment_id'));
        $this->assertEquals($this->residentUserA->id, $response->json('data.host_resident_user_id'));
    }

    /**
     * Test 5: Kẻ tấn công không thuộc căn hộ nào không gửi apartment_id trong payload:
     * Kỳ vọng: Trả về 422 Unprocessable Entity, tuyệt đối KHÔNG fallback sang căn hộ đầu tiên trong database.
     */
    public function test_05_attacker_without_apartment_omitting_apartment_id_returns_422_without_fallback(): void
    {
        $countBefore = DB::table('visitor_registrations')->count();

        $payload = [
            'visitor_name' => 'Khách Của Kẻ Không Căn Hộ',
            'visitor_phone' => '0988001166',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->attackerToken));

        $response->assertStatus(422);
        $this->assertStringContainsString('Không thể xác định căn hộ tiếp đón', (string) $response->json('message'));
        $this->assertSame($countBefore, DB::table('visitor_registrations')->count());
    }

    /**
     * Test 6: Quản trị viên (Admin) được phép tạo lượt khách cho bất kỳ căn hộ hợp lệ nào:
     * Kỳ vọng: HTTP 201 Created.
     */
    public function test_06_admin_can_register_visitor_for_any_existing_apartment_returns_201(): void
    {
        $payload = [
            'visitor_name' => 'Khách VIP Ban Quản Lý Đăng Ký Hộ Căn A',
            'visitor_phone' => '0988001177',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentA->id,
            'visit_purpose' => 'Kiểm toán ban quản lý',
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->adminToken));

        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $this->assertEquals($this->apartmentA->id, $response->json('data.apartment_id'));
        $this->assertEquals($this->adminUser->id, $response->json('data.host_resident_user_id'));
        $this->assertEquals('ACTIVE', $response->json('data.qr_pass_status'));
    }

    /**
     * Test 7: Lễ tân (Receptionist) được phép tạo lượt khách cho căn hộ hợp lệ:
     * Kỳ vọng: HTTP 201 Created.
     */
    public function test_07_receptionist_can_register_visitor_for_existing_apartment_returns_201(): void
    {
        $payload = [
            'visitor_name' => 'Khách Đăng Ký Tại Quầy Lễ Tân Cho Căn B',
            'visitor_phone' => '0988001188',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentB->id,
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->receptionistToken));

        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $this->assertEquals($this->apartmentB->id, $response->json('data.apartment_id'));
    }

    /**
     * Test 8: Admin gửi apartment_id không tồn tại:
     * Kỳ vọng: HTTP 422 Unprocessable Entity (thay vì 500 server error).
     */
    public function test_08_admin_registering_visitor_for_non_existent_apartment_returns_422(): void
    {
        $fakeAptId = (string) Str::uuid();

        $payload = [
            'visitor_name' => 'Khách Căn Hộ Giả',
            'visitor_phone' => '0988001199',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $fakeAptId,
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->adminToken));

        $response->assertStatus(422);
        $this->assertStringContainsString('không tồn tại hoặc đã ngừng hoạt động', (string) $response->json('message'));
    }

    /**
     * Test 9: Cư dân hết hạn cư trú cố tình truyền apartment_id cũ:
     * Kỳ vọng: PHẢI trả về 403 Forbidden.
     */
    public function test_09_expired_stay_resident_cannot_register_visitor_for_past_apartment_returns_403(): void
    {
        [$expiredUser, $expiredToken] = $this->createUserWithSession('expired_user_');
        Resident::create([
            'user_id' => $expiredUser->id,
            'apartment_id' => $this->apartmentA->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => now()->subYear()->toDateString(),
            'stay_end_date' => now()->subDay()->toDateString(), // Đã hết hạn hôm qua
            'is_active' => true,
        ]);

        $payload = [
            'visitor_name' => 'Khách Của Cư Dân Hết Hạn',
            'visitor_phone' => '0988001200',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentA->id,
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($expiredToken));

        $response->assertStatus(403);
    }

    /**
     * Test 10: Gọi trực tiếp Service tạo khách bằng tài khoản không có quyền:
     * Kỳ vọng: Ném HttpException 403.
     */
    public function test_10_direct_service_invocation_throws_403_on_idor_attempt(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Bạn không có quyền đăng ký khách cho căn hộ này.');

        $this->service->createVisitor($this->attackerNoApt, [
            'visitor_name' => 'Kẻ Tấn Công Service Trực Tiếp',
            'visitor_phone' => '0988001201',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentB->id,
        ]);
    }
}
