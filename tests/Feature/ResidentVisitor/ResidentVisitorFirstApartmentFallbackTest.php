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

class ResidentVisitorFirstApartmentFallbackTest extends TestCase
{
    use DatabaseTransactions;

    protected Apartment $firstApartmentInDb;

    protected Apartment $secondApartmentInDb;

    protected User $unlinkedUser;

    protected string $unlinkedToken;

    protected User $residentOfSecondApt;

    protected string $residentToken;

    protected User $adminUser;

    protected string $adminToken;

    protected ResidentVisitorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->service = app(ResidentVisitorService::class);

        QuocTinRealtimeService::clearModuleCooldown('visitors');

        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_M602_'.Str::random(4),
                'block_name' => 'Tháp M6-02',
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

        // Tạo căn hộ 1 (sẽ là căn hộ đầu tiên trong test fixture)
        $this->firstApartmentInDb = Apartment::create([
            'apartment_number' => 'APT-M602-01-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 80.0,
            'net_usable_area_sqm' => 75.0,
        ]);

        // Tạo căn hộ 2 (căn hộ thứ hai)
        $this->secondApartmentInDb = Apartment::create([
            'apartment_number' => 'APT-M602-02-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 80.0,
            'net_usable_area_sqm' => 75.0,
        ]);

        // 1. Người dùng chưa liên kết bất kỳ căn hộ nào
        [$this->unlinkedUser, $this->unlinkedToken] = $this->createUserWithSession('unlinked_m602_');

        // 2. Cư dân thuộc căn hộ 2 (không phải căn hộ 1)
        [$this->residentOfSecondApt, $this->residentToken] = $this->createUserWithSession('resident_m602_');
        Resident::create([
            'user_id' => $this->residentOfSecondApt->id,
            'apartment_id' => $this->secondApartmentInDb->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subMonths(6)->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        // 3. Quản trị viên (Admin) không có hồ sơ cư trú
        [$this->adminUser, $this->adminToken] = $this->createUserWithSession('admin_m602_');
        $this->assignRole($this->adminUser->id, 'ADMIN');
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

        $token = 'token_m602_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Test Runner M6-02',
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
     * Test 1: BUG-M6-02 - Người dùng chưa có căn hộ khi không truyền apartment_id phải trả về 422,
     * tuyệt đối KHÔNG fallback sang căn hộ đầu tiên trong DB (firstApartmentInDb).
     */
    public function test_01_user_without_apartment_omitting_apartment_id_aborts_422_and_does_not_assign_first_apartment(): void
    {
        $firstAptInDb = DB::table('apartments')->first();
        $this->assertNotNull($firstAptInDb, 'Phải có ít nhất 1 căn hộ trong database để kiểm tra fallback');

        $visitorsInFirstAptBefore = DB::table('visitor_registrations')
            ->where('apartment_id', $firstAptInDb->id)
            ->count();

        $payload = [
            'visitor_name' => 'Khách Của User Chưa Có Căn Hộ',
            'visitor_phone' => '0912345671',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'visit_purpose' => 'Thăm người quen',
            // Không truyền apartment_id
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->unlinkedToken));

        $response->assertStatus(422);
        $this->assertStringContainsString('Không thể xác định căn hộ tiếp đón', (string) $response->json('message'));

        // Xác minh không có bản ghi nào bị gán nhầm vào căn hộ đầu tiên
        $visitorsInFirstAptAfter = DB::table('visitor_registrations')
            ->where('apartment_id', $firstAptInDb->id)
            ->count();
        $this->assertSame($visitorsInFirstAptBefore, $visitorsInFirstAptAfter);
    }

    /**
     * Test 2: BUG-M6-02 - Người dùng truyền apartment_id là null: Phải trả về 422, không fallback
     */
    public function test_02_user_without_apartment_passing_null_apartment_id_aborts_422(): void
    {
        $payload = [
            'visitor_name' => 'Khách Truyền Null Apartment',
            'visitor_phone' => '0912345672',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => null,
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->unlinkedToken));

        $response->assertStatus(422);
        $this->assertStringContainsString('Không thể xác định căn hộ tiếp đón', (string) $response->json('message'));
    }

    /**
     * Test 3: BUG-M6-02 - Người dùng truyền apartment_id là chuỗi rỗng hoặc khoảng trắng: Phải trả về 422
     */
    public function test_03_user_without_apartment_passing_empty_string_or_whitespace_aborts_422(): void
    {
        // Chuỗi rỗng
        $res1 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Chuỗi Rỗng',
            'visitor_phone' => '0912345673',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => '',
        ], $this->authHeaders($this->unlinkedToken));
        $res1->assertStatus(422);

        // Khoảng trắng
        $res2 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Khoảng Trắng',
            'visitor_phone' => '0912345674',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => '   ',
        ], $this->authHeaders($this->unlinkedToken));
        $res2->assertStatus(422);
    }

    /**
     * Test 4: BUG-M6-02 - Cư dân có hồ sơ nhưng đã hết hạn lưu trú (expired) bỏ trống apartment_id:
     * Phải trả về 422, không được fallback sang căn hộ đầu tiên trong DB.
     */
    public function test_04_expired_stay_resident_omitting_apartment_id_aborts_422(): void
    {
        [$expiredUser, $expiredToken] = $this->createUserWithSession('expired_m602_');
        Resident::create([
            'user_id' => $expiredUser->id,
            'apartment_id' => $this->firstApartmentInDb->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => now()->subYear()->toDateString(),
            'stay_end_date' => now()->subDay()->toDateString(), // Hết hạn ngày hôm qua
            'is_active' => true,
        ]);

        $payload = [
            'visitor_name' => 'Khách Của Cư Dân Hết Hạn Không Điền Căn Hộ',
            'visitor_phone' => '0912345675',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($expiredToken));

        $response->assertStatus(422);
        $this->assertStringContainsString('Không thể xác định căn hộ tiếp đón', (string) $response->json('message'));
    }

    /**
     * Test 5: BUG-M6-02 - Cư dân có hồ sơ nhưng is_active = 0 bỏ trống apartment_id: Phải trả về 422
     */
    public function test_05_inactive_resident_omitting_apartment_id_aborts_422(): void
    {
        [$inactiveUser, $inactiveToken] = $this->createUserWithSession('inactive_m602_');
        Resident::create([
            'user_id' => $inactiveUser->id,
            'apartment_id' => $this->secondApartmentInDb->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => now()->subMonth()->toDateString(),
            'stay_end_date' => now()->addYear()->toDateString(),
            'is_active' => false, // Bị vô hiệu hóa
        ]);

        $payload = [
            'visitor_name' => 'Khách Của Cư Dân Inactive',
            'visitor_phone' => '0912345676',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($inactiveToken));

        $response->assertStatus(422);
    }

    /**
     * Test 6: BUG-M6-02 - Cư dân có hồ sơ bị xóa mềm (deleted_at) bỏ trống apartment_id: Phải trả về 422
     */
    public function test_06_soft_deleted_resident_omitting_apartment_id_aborts_422(): void
    {
        [$deletedUser, $deletedToken] = $this->createUserWithSession('deleted_m602_');
        $resident = Resident::create([
            'user_id' => $deletedUser->id,
            'apartment_id' => $this->secondApartmentInDb->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => now()->subMonth()->toDateString(),
            'stay_end_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);
        $resident->delete();

        $payload = [
            'visitor_name' => 'Khách Của Cư Dân Đã Xóa',
            'visitor_phone' => '0912345677',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($deletedToken));

        $response->assertStatus(422);
    }

    /**
     * Test 7: Đối chiếu hành vi hợp lệ: Cư dân hợp lệ của Căn hộ 2 khi không truyền apartment_id
     * phải tự động gán đúng Căn hộ 2, TUYỆT ĐỐI KHÔNG gán vào Căn hộ 1 (căn hộ đầu tiên trong DB).
     */
    public function test_07_valid_resident_omitting_apartment_id_auto_resolves_own_apartment_not_db_first(): void
    {
        $payload = [
            'visitor_name' => 'Khách Hợp Lệ Căn 2 Tự Động Định Tuyến',
            'visitor_phone' => '0912345678',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->residentToken));

        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));

        // Phải gán đúng Căn hộ 2 của cư dân
        $this->assertEquals($this->secondApartmentInDb->id, $response->json('data.apartment_id'));
        $this->assertNotEquals($this->firstApartmentInDb->id, $response->json('data.apartment_id'));
    }

    /**
     * Test 8: Đối chiếu hành vi hợp lệ với Chủ hộ (apartment_owners):
     * Chủ hộ của Căn hộ 2 khi không truyền apartment_id tự động gán Căn hộ 2, không phải căn hộ đầu DB.
     */
    public function test_08_valid_owner_omitting_apartment_id_auto_resolves_own_apartment_not_db_first(): void
    {
        [$ownerUser, $ownerToken] = $this->createUserWithSession('owner_m602_');
        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $this->secondApartmentInDb->id,
            'owner_user_id' => $ownerUser->id,
            'ownership_percentage' => 100.00,
            'ownership_start_date' => now()->toDateString(),
            'is_current_owner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'visitor_name' => 'Khách Của Chủ Hộ Căn 2',
            'visitor_phone' => '0912345679',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($ownerToken));

        $response->assertStatus(201);
        $this->assertEquals($this->secondApartmentInDb->id, $response->json('data.apartment_id'));
    }

    /**
     * Test 9: Quản trị viên (Admin) không có hồ sơ cư trú khi bỏ trống apartment_id:
     * Phải trả về 422, buộc Admin phải chỉ định rõ căn hộ cần đăng ký khách, không tự ý gán bừa.
     */
    public function test_09_admin_omitting_apartment_id_aborts_422_when_admin_has_no_resident_apartment(): void
    {
        $payload = [
            'visitor_name' => 'Khách Admin Quên Điền Căn Hộ',
            'visitor_phone' => '0912345680',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->adminToken));

        $response->assertStatus(422);
        $this->assertStringContainsString('Không thể xác định căn hộ tiếp đón', (string) $response->json('message'));
    }

    /**
     * Test 10: Khả năng bypass Controller: Gọi trực tiếp ResidentVisitorService::createVisitor
     * với người dùng chưa có căn hộ và không truyền apartment_id:
     * Bắt buộc phải ném HttpException 422, không fallback sang apartments->first().
     */
    public function test_10_direct_service_call_throws_422_when_user_has_no_apartment_and_no_apartment_id(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Không thể xác định căn hộ tiếp đón cho tài khoản cư dân này.');

        $this->service->createVisitor($this->unlinkedUser, [
            'visitor_name' => 'Gọi Trực Tiếp Service Không Căn Hộ',
            'visitor_phone' => '0912345681',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
    }

    /**
     * Test 11: Kiểm tra tính toàn vẹn: Căn hộ đầu tiên trong DB không bao giờ bị phát sinh
     * lượt khách nào từ các yêu cầu không hợp lệ.
     */
    public function test_11_first_apartment_in_db_remains_untampered_after_unrelated_failed_requests(): void
    {
        $firstApt = DB::table('apartments')->first();
        $countInitial = DB::table('visitor_registrations')->where('apartment_id', $firstApt->id)->count();

        // 3 yêu cầu vi phạm khác nhau
        $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Vi Phạm 1',
            'visitor_phone' => '0912345682',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ], $this->authHeaders($this->unlinkedToken));

        $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Vi Phạm 2',
            'visitor_phone' => '0912345683',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => null,
        ], $this->authHeaders($this->unlinkedToken));

        $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Vi Phạm 3',
            'visitor_phone' => '0912345684',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => '',
        ], $this->authHeaders($this->unlinkedToken));

        $countFinal = DB::table('visitor_registrations')->where('apartment_id', $firstApt->id)->count();
        $this->assertSame($countInitial, $countFinal);
    }
}
