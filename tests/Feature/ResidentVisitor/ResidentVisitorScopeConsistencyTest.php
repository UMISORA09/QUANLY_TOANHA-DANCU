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
use Tests\TestCase;

class ResidentVisitorScopeConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    protected Apartment $sharedApartment;

    protected User $residentB;

    protected string $tokenB;

    protected User $residentC;

    protected string $tokenC;

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
                'block_code' => 'BLK_M603_'.Str::random(4),
                'block_name' => 'Tháp M6-03 Scope',
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
                'floor_number' => 7,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Tạo căn hộ dùng chung cho cả Cư dân B và Cư dân C
        $this->sharedApartment = Apartment::create([
            'apartment_number' => 'APT-M603-SHARED-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '3BR',
            'gross_floor_area_sqm' => 100.0,
            'net_usable_area_sqm' => 95.0,
        ]);

        // Cư dân C (Chủ hộ)
        [$this->residentC, $this->tokenC] = $this->createUserWithSession('res_c_m603_');
        Resident::create([
            'user_id' => $this->residentC->id,
            'apartment_id' => $this->sharedApartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        // Cư dân B (Thành viên cùng căn hộ với C)
        [$this->residentB, $this->tokenB] = $this->createUserWithSession('res_b_m603_');
        Resident::create([
            'user_id' => $this->residentB->id,
            'apartment_id' => $this->sharedApartment->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => now()->subMonths(6)->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'OTHER',
            'is_active' => true,
        ]);

        // Quản trị viên (Admin)
        [$this->adminUser, $this->adminToken] = $this->createUserWithSession('admin_m603_');
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

        $token = 'token_m603_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Test Runner M6-03',
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
     * Test 1: BUG-M6-03 Kịch bản cốt lõi:
     * Cư dân C đã tạo khách riêng của mình.
     * Cư dân B cùng căn hộ nhưng CHƯA tạo lượt khách nào ($hasOwn = false).
     * Kỳ vọng: B lấy danh sách của mình PHẢI nhận danh sách rỗng (count = 0),
     * TUYỆT ĐỐI KHÔNG được rò rỉ danh sách khách riêng của C sang cho B.
     */
    public function test_01_co_resident_without_own_visitors_cannot_see_roommate_visitors_in_list(): void
    {
        // Cư dân C tạo khách riêng cho căn hộ chung
        $createdC = $this->service->createVisitor($this->residentC, [
            'visitor_name' => 'Khách Riêng Của C',
            'visitor_phone' => '0933111222',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'visit_purpose' => 'Việc cá nhân của chủ hộ C',
        ]);
        $this->assertTrue($createdC['success']);

        // Cư dân B (chưa từng tạo khách nào) gọi GET /api/v1/resident/visitors
        $response = $this->getJson('/api/v1/resident/visitors', $this->authHeaders($this->tokenB));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $items = $response->json('data');
        $this->assertIsArray($items);
        $this->assertCount(0, $items, 'Cư dân B chưa tạo khách không được nhìn thấy khách của C');

        $names = collect($items)->pluck('visitor_name')->toArray();
        $this->assertNotContains('Khách Riêng Của C', $names);

        // KPIs của B cũng phải bằng 0
        $this->assertEquals(0, $response->json('kpis.total'));
        $this->assertEquals(0, $response->json('kpis.active'));
    }

    /**
     * Test 2: BUG-M6-03 Tính nhất quán:
     * Khi Cư dân B tạo lượt khách đầu tiên của mình, B chỉ thấy khách của B,
     * không xảy ra hiện tượng chuyển đổi scope đột ngột hay mất mát dữ liệu không nhất quán.
     */
    public function test_02_co_resident_creating_first_visitor_only_sees_their_own_visitor(): void
    {
        // Cư dân C tạo khách của C
        $this->service->createVisitor($this->residentC, [
            'visitor_name' => 'Khách Của C',
            'visitor_phone' => '0933111333',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);

        // Cư dân B tạo khách đầu tiên của B
        $this->service->createVisitor($this->residentB, [
            'visitor_name' => 'Khách Của B',
            'visitor_phone' => '0933111444',
            'expected_arrival_time' => Carbon::now()->addHours(3)->toIso8601String(),
        ]);

        // B lấy danh sách
        $responseB = $this->getJson('/api/v1/resident/visitors', $this->authHeaders($this->tokenB));
        $responseB->assertStatus(200);
        $itemsB = $responseB->json('data');
        $this->assertCount(1, $itemsB);
        $this->assertEquals('Khách Của B', $itemsB[0]['visitor_name']);
        $this->assertEquals($this->residentB->id, $itemsB[0]['host_resident_user_id']);

        // C lấy danh sách
        $responseC = $this->getJson('/api/v1/resident/visitors', $this->authHeaders($this->tokenC));
        $responseC->assertStatus(200);
        $itemsC = $responseC->json('data');
        $this->assertCount(1, $itemsC);
        $this->assertEquals('Khách Của C', $itemsC[0]['visitor_name']);
        $this->assertEquals($this->residentC->id, $itemsC[0]['host_resident_user_id']);
    }

    /**
     * Test 3: Đồng nhất giữa listVisitors và getVisitorDetail:
     * Cư dân B cố truy cập trực tiếp ID chi tiết khách của C $\rightarrow$ Bị chặn 403 Forbidden.
     * Vì B không thấy khách của C trong list, nên không xảy ra tình trạng "thấy ở list nhưng bấm vào xem thì 403".
     */
    public function test_03_co_resident_cannot_access_roommate_visitor_detail_returns_403(): void
    {
        $createdC = $this->service->createVisitor($this->residentC, [
            'visitor_name' => 'Khách Bảo Mật C',
            'visitor_phone' => '0933111555',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $visitorId = $createdC['data']->id;

        // B cố xem chi tiết khách của C
        $response = $this->getJson("/api/v1/resident/visitors/{$visitorId}", $this->authHeaders($this->tokenB));
        $response->assertStatus(403);
        $this->assertStringContainsString('không có quyền truy cập', (string) $response->json('message'));
    }

    /**
     * Test 4: Quản trị viên (Admin) xem được toàn bộ khách của tất cả các cư dân trong tòa nhà
     */
    public function test_04_admin_can_see_all_visitors_across_all_residents(): void
    {
        $this->service->createVisitor($this->residentC, [
            'visitor_name' => 'Khách Của C Cho Admin Thấy',
            'visitor_phone' => '0933111666',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);

        $this->service->createVisitor($this->residentB, [
            'visitor_name' => 'Khách Của B Cho Admin Thấy',
            'visitor_phone' => '0933111777',
            'expected_arrival_time' => Carbon::now()->addHours(3)->toIso8601String(),
        ]);

        $response = $this->getJson('/api/v1/resident/visitors?search=Cho Admin Thấy', $this->authHeaders($this->adminToken));
        $response->assertStatus(200);

        $items = $response->json('data');
        $this->assertCount(2, $items);
        $this->assertGreaterThanOrEqual(2, $response->json('kpis.total'));

        $names = collect($items)->pluck('visitor_name')->toArray();
        $this->assertContains('Khách Của C Cho Admin Thấy', $names);
        $this->assertContains('Khách Của B Cho Admin Thấy', $names);
    }

    /**
     * Test 5: Tính toán KPIs tách biệt hoàn toàn giữa các cư dân
     */
    public function test_05_kpis_calculation_is_strictly_scoped_to_each_resident(): void
    {
        // C có 2 khách ACTIVE
        $this->service->createVisitor($this->residentC, [
            'visitor_name' => 'Khách C 1',
            'visitor_phone' => '0933111888',
            'expected_arrival_time' => Carbon::now()->addHours(1)->toIso8601String(),
        ]);
        $this->service->createVisitor($this->residentC, [
            'visitor_name' => 'Khách C 2',
            'visitor_phone' => '0933111889',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);

        // B chỉ có 1 khách và sau đó bị CANCEL
        $createdB = $this->service->createVisitor($this->residentB, [
            'visitor_name' => 'Khách B Duy Nhất',
            'visitor_phone' => '0933111890',
            'expected_arrival_time' => Carbon::now()->addHours(3)->toIso8601String(),
        ]);
        $this->service->cancelVisitor($this->residentB, $createdB['data']->id, 'B bận');

        // Kiểm tra KPIs của B: total = 1, active = 0, cancelled = 1
        $resB = $this->getJson('/api/v1/resident/visitors', $this->authHeaders($this->tokenB));
        $resB->assertStatus(200);
        $this->assertEquals(1, $resB->json('kpis.total'));
        $this->assertEquals(0, $resB->json('kpis.active'));
        $this->assertEquals(1, $resB->json('kpis.cancelled'));

        // Kiểm tra KPIs của C: total = 2, active = 2, cancelled = 0
        $resC = $this->getJson('/api/v1/resident/visitors', $this->authHeaders($this->tokenC));
        $resC->assertStatus(200);
        $this->assertEquals(2, $resC->json('kpis.total'));
        $this->assertEquals(2, $resC->json('kpis.active'));
        $this->assertEquals(0, $resC->json('kpis.cancelled'));
    }

    /**
     * Test 6: Gọi trực tiếp ResidentVisitorService::listVisitors
     */
    public function test_06_direct_service_call_list_visitors_is_properly_scoped(): void
    {
        $this->service->createVisitor($this->residentC, [
            'visitor_name' => 'Khách Service C',
            'visitor_phone' => '0933111999',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);

        $listB = $this->service->listVisitors($this->residentB);
        $this->assertTrue($listB['success']);
        $this->assertCount(0, $listB['data']);
        $this->assertEquals(0, $listB['kpis']['total']);
    }
}
