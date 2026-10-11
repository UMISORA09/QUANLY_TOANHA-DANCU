<?php

namespace Tests\Feature\ReceptionVisitor;

use App\Models\Apartment;
use App\Models\User;
use App\Models\VisitorRegistration;
use App\Services\QuocTinRealtimeService;
use App\Services\ReceptionVisitorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionVisitorCheckinTest extends TestCase
{
    protected ?User $receptionist = null;

    protected ?string $receptionistToken = null;

    protected ?User $securityGuard = null;

    protected ?string $securityGuardToken = null;

    protected ?User $resident = null;

    protected ?string $residentToken = null;

    protected ?Apartment $apartment = null;

    protected ReceptionVisitorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReceptionVisitorService::class);

        // 1. Tạo người dùng Lễ tân
        [$this->receptionist, $this->receptionistToken] = $this->createStaffUser('RECEPTIONIST');

        // 2. Tạo người dùng Bảo vệ
        [$this->securityGuard, $this->securityGuardToken] = $this->createStaffUser('SECURITY_GUARD');

        // 3. Tạo cư dân thông thường (không có quyền nhân viên)
        [$this->resident, $this->residentToken] = $this->createResidentUser('resident_');

        // 4. Tạo căn hộ mẫu
        $this->apartment = $this->getOrCreateApartment('APT-REC-'.Str::random(4));
    }

    protected function getOrCreateApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_REC_'.Str::random(4),
                'block_name' => 'Tòa Tháp Recept',
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

        return Apartment::create([
            'apartment_number' => $code,
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 85.0,
            'net_usable_area_sqm' => 80.0,
        ]);
    }

    protected function createStaffUser(string $roleCode): array
    {
        $user = User::create([
            'username' => strtolower($roleCode).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => strtolower($roleCode).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => "Nhân Viên {$roleCode} Test",
            'status' => 'ACTIVE',
        ]);

        // Gán Role
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

        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Staff Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function createResidentUser(string $prefix): array
    {
        $user = User::create([
            'username' => $prefix.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Test '.strtoupper(substr($prefix, 0, 10)),
            'status' => 'ACTIVE',
        ]);

        $roleId = DB::table('roles')->where('role_code', 'RESIDENT')->value('id');
        if ($roleId) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $roleId,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Resident Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function createVisitorRegistration(array $overrides = []): VisitorRegistration
    {
        $code = strtoupper(Str::random(8));

        return VisitorRegistration::create(array_merge([
            'registration_code' => 'VR-2026-'.rand(10000, 99999),
            'host_resident_user_id' => $this->resident->id,
            'apartment_id' => $this->apartment->id,
            'visitor_name' => 'Khách Test '.Str::random(4),
            'visitor_phone' => '09'.rand(10000000, 99999999),
            'visitor_national_id' => '001200'.rand(100000, 999999),
            'expected_arrival_time' => Carbon::now()->addHour(),
            'expected_departure_time' => Carbon::now()->addHours(4),
            'visit_purpose' => 'Thăm bạn bè cuối tuần',
            'visitor_count' => 1,
            'vehicle_license_plate' => '29A-'.rand(10000, 99999),
            'qr_access_pass_code' => $code,
            'qr_pass_status' => 'ACTIVE',
            'is_pre_approved_by_resident' => true,
        ], $overrides));
    }

    /**
     * Test 01: Lễ tân có thể xem danh sách khách với KPIs và phân trang
     */
    public function test_01_staff_can_list_visitors_with_kpis_and_pagination(): void
    {
        $reg = $this->createVisitorRegistration();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/visitors');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'kpis' => [
                    'expected_today',
                    'currently_inside',
                    'checked_out_today',
                    'total_registrations',
                ],
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total_items',
                    'total_pages',
                    'has_more',
                ],
            ]);

        $this->assertTrue($response->json('success'));
    }

    /**
     * Test 02: Lễ tân có thể tìm kiếm khách theo tên, SĐT và mã khai báo
     */
    public function test_02_staff_can_search_visitor_by_name_phone_and_code(): void
    {
        $uniqueName = 'Nguyễn Văn Độc Nhất '.Str::random(4);
        $uniquePhone = '0988'.rand(100000, 999999);
        $reg = $this->createVisitorRegistration([
            'visitor_name' => $uniqueName,
            'visitor_phone' => $uniquePhone,
        ]);

        // Tìm theo tên
        $resName = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/reception/visitors?search={$uniqueName}");

        $resName->assertStatus(200);
        $this->assertNotEmpty($resName->json('data'));
        $this->assertEquals($reg->id, $resName->json('data.0.id'));

        // Tìm theo SĐT
        $resPhone = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/reception/visitors?search={$uniquePhone}");

        $resPhone->assertStatus(200);
        $this->assertNotEmpty($resPhone->json('data'));
        $this->assertEquals($reg->id, $resPhone->json('data.0.id'));

        // Tìm theo mã QR Pass
        $resCode = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/reception/visitors?search={$reg->qr_access_pass_code}");

        $resCode->assertStatus(200);
        $this->assertNotEmpty($resCode->json('data'));
        $this->assertEquals($reg->id, $resCode->json('data.0.id'));
    }

    /**
     * Test 03: Bộ lọc theo trạng thái hoạt động chính xác
     */
    public function test_03_staff_can_filter_by_status(): void
    {
        $regExpected = $this->createVisitorRegistration(['qr_pass_status' => 'ACTIVE']);
        $regCancelled = $this->createVisitorRegistration(['qr_pass_status' => 'CANCELLED']);

        // Lọc EXPECTED
        $resExpected = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/visitors?status=EXPECTED');

        $resExpected->assertStatus(200);
        foreach ($resExpected->json('data') as $item) {
            $this->assertEquals('EXPECTED', $item['status']);
        }

        // Lọc CANCELLED
        $resCancelled = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/visitors?status=CANCELLED');

        $resCancelled->assertStatus(200);
        foreach ($resCancelled->json('data') as $item) {
            $this->assertEquals('CANCELLED', $item['status']);
        }
    }

    /**
     * Test 04: Cư dân thông thường và khách vãng lai không có quyền truy cập API Lễ tân
     */
    public function test_04_regular_resident_or_unauthenticated_cannot_access_reception_visitors(): void
    {
        // 1. Không có token -> 401
        $resUnauth = $this->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/v1/reception/visitors');
        $resUnauth->assertStatus(401);

        // 2. Token của Cư dân thông thường (không có role staff) -> 403 Forbidden
        $resForbidden = $this->withHeaders([
            'Authorization' => "Bearer {$this->residentToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/visitors');
        $resForbidden->assertStatus(403);
    }

    /**
     * Test 05: Tra cứu nhanh theo mã QR Access Pass hoặc Mã đăng ký O(1)
     */
    public function test_05_staff_can_lookup_by_qr_code_or_registration_code(): void
    {
        $reg = $this->createVisitorRegistration();

        // 1. Tra cứu theo QR Code
        $resLookup = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/reception/visitors/lookup?code={$reg->qr_access_pass_code}");

        $resLookup->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'registration',
                    'status',
                    'status_label',
                    'can_checkin',
                    'can_checkout',
                ],
            ]);

        $this->assertTrue($resLookup->json('data.can_checkin'));
        $this->assertEquals($reg->id, $resLookup->json('data.registration.id'));

        // 2. Tra cứu mã không tồn tại -> 404
        $resNotFound = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reception/visitors/lookup?code=NONEXISTENT_9999');

        $resNotFound->assertStatus(404);
    }

    /**
     * Test 06: Check-in hợp lệ thành công và ghi nhận Server Time
     */
    public function test_06_staff_can_checkin_valid_expected_visitor_successfully(): void
    {
        $reg = $this->createVisitorRegistration();

        $before = Carbon::now()->subSecond();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
            'national_id_number' => '001200999888',
            'temp_card_id' => 'CARD-TEMP-01',
            'notes' => 'Khách đến đúng hẹn',
        ]);

        $after = Carbon::now()->addSecond();

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'INSIDE',
                ],
            ]);

        // Kiểm tra database visitor_checkin_logs
        $log = DB::table('visitor_checkin_logs')
            ->where('registration_id', $reg->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('CARD-TEMP-01', $log->temp_card_id);
        $this->assertEquals('Khách đến đúng hẹn', $log->notes);
        $this->assertEquals($this->receptionist->id, $log->receptionist_user_id);
        $this->assertNull($log->checkout_time);

        // Checkin time phải nằm trong khoảng server time
        $checkinTime = Carbon::parse($log->checkin_time);
        $this->assertTrue($checkinTime->between($before, $after));

        // Kiểm tra visitor_registrations qr_pass_status đã cập nhật USED
        $updatedReg = DB::table('visitor_registrations')->where('id', $reg->id)->first();
        $this->assertEquals('USED', $updatedReg->qr_pass_status);
    }

    /**
     * Test 07: Check-in lặp lại cho cùng 1 lượt khách bị chặn và trả về 409 Conflict
     */
    public function test_07_repeat_checkin_on_same_visitor_returns_409_conflict(): void
    {
        $reg = $this->createVisitorRegistration();

        // Check-in lần 1 -> Thành công
        $res1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ]);
        $res1->assertStatus(200);

        // Check-in lần 2 ngay sau đó -> 409 Conflict
        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ]);

        $res2->assertStatus(409);

        // Đảm bảo chỉ có duy nhất 1 bản ghi check-in trong database
        $count = DB::table('visitor_checkin_logs')->where('registration_id', $reg->id)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test 08: Không thể check-in lượt khách đã bị hủy hoặc hết hạn
     */
    public function test_08_cannot_checkin_cancelled_or_expired_visitor(): void
    {
        $regCancelled = $this->createVisitorRegistration(['qr_pass_status' => 'CANCELLED']);
        $regExpired = $this->createVisitorRegistration(['qr_pass_status' => 'EXPIRED']);

        // Check-in lượt đã hủy -> 422
        $resCancelled = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $regCancelled->id,
        ]);
        $resCancelled->assertStatus(422);

        // Check-in lượt hết hạn -> 422
        $resExpired = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $regExpired->id,
        ]);
        $resExpired->assertStatus(422);
    }

    /**
     * Test 09: Check-out thành công và ghi nhận Server Time
     */
    public function test_09_staff_can_checkout_visitor_currently_in_building(): void
    {
        $reg = $this->createVisitorRegistration();

        // 1. Check-in trước
        $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ]);

        $beforeCheckout = Carbon::now()->subSecond();

        // 2. Check-out
        $resCheckout = $this->withHeaders([
            'Authorization' => "Bearer {$this->securityGuardToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-out', [
            'registration_id' => $reg->id,
            'notes' => 'Đã thu hồi thẻ thang máy',
        ]);

        $afterCheckout = Carbon::now()->addSecond();

        $resCheckout->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'CHECKED_OUT',
                ],
            ]);

        // Kiểm tra database checkout_time đã được set
        $log = DB::table('visitor_checkin_logs')->where('registration_id', $reg->id)->first();
        $this->assertNotNull($log->checkout_time);

        $checkoutTime = Carbon::parse($log->checkout_time);
        $this->assertTrue($checkoutTime->between($beforeCheckout, $afterCheckout));
        $this->assertStringContainsString('Đã thu hồi thẻ thang máy', (string) $log->notes);
    }

    /**
     * Test 10: Check-out lặp lại trả về 409 Conflict
     */
    public function test_10_repeat_checkout_returns_409_conflict(): void
    {
        $reg = $this->createVisitorRegistration();

        // 1. Check-in
        $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ]);

        // 2. Check-out lần 1 -> 200
        $res1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-out', [
            'registration_id' => $reg->id,
        ]);
        $res1->assertStatus(200);

        // 3. Check-out lần 2 -> 409 Conflict
        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-out', [
            'registration_id' => $reg->id,
        ]);
        $res2->assertStatus(409);
    }

    /**
     * Test 11: Không thể check-out lượt khách chưa từng check-in
     */
    public function test_11_cannot_checkout_visitor_who_has_not_checked_in(): void
    {
        $reg = $this->createVisitorRegistration();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-out', [
            'registration_id' => $reg->id,
        ]);

        $res->assertStatus(404);
    }

    /**
     * Test 12: Realtime events phát ra sau khi Check-in / Check-out và KHÔNG bị cooldown 120s
     */
    public function test_12_realtime_events_emitted_on_checkin_and_checkout_without_120s_cooldown(): void
    {
        $reg = $this->createVisitorRegistration();

        // 1. Thực hiện Check-in
        $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $reg->id,
        ]);

        // Kiểm tra Event CHECKED_IN đã lưu vào realtime_sync_events
        $checkinEvent = DB::table('realtime_sync_events')
            ->where('channel', 'quoc-tin.visitors')
            ->where('action', 'CHECKED_IN')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($checkinEvent);
        $payload = json_decode($checkinEvent->payload, true);
        $this->assertEquals($reg->id, $payload['registration_id']);
        $this->assertEquals('INSIDE', $payload['status']);

        // Xác nhận module 'visitors' KHÔNG bị khóa trong Cooldown 120s
        $this->assertFalse(QuocTinRealtimeService::isModuleInCooldown('visitors'));

        // 2. Thực hiện Check-out
        $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-out', [
            'registration_id' => $reg->id,
        ]);

        // Kiểm tra Event CHECKED_OUT đã lưu vào realtime_sync_events
        $checkoutEvent = DB::table('realtime_sync_events')
            ->where('channel', 'quoc-tin.visitors')
            ->where('action', 'CHECKED_OUT')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($checkoutEvent);
        $checkoutPayload = json_decode($checkoutEvent->payload, true);
        $this->assertEquals('CHECKED_OUT', $checkoutPayload['status']);

        // Module vẫn không bị khóa
        $this->assertFalse(QuocTinRealtimeService::isModuleInCooldown('visitors'));
    }

    /**
     * Test 13: Luồng khai báo khách của Cư dân (Module #7) vẫn hoạt động hoàn hảo và liên thông với Lễ tân
     */
    public function test_13_resident_visitor_flow_remains_intact_and_linked_with_reception(): void
    {
        // 1. Cư dân tạo lượt khai báo qua API Module #7
        $createRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->residentToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Liên Thông',
            'visitor_phone' => '0933333333',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'visit_purpose' => 'Bàn giao hợp đồng',
            'apartment_id' => $this->apartment->id,
        ]);

        $createRes->assertStatus(201);
        $createdData = $createRes->json('data');
        $regId = $createdData['id'];
        $qrPassCode = $createdData['qr_access_pass_code'];

        // 2. Lễ tân tra cứu lượt khai báo này
        $lookupRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/reception/visitors/lookup?code={$qrPassCode}");

        $lookupRes->assertStatus(200);
        $this->assertEquals($regId, $lookupRes->json('data.registration.id'));
        $this->assertTrue($lookupRes->json('data.can_checkin'));

        // 3. Lễ tân check-in cho khách
        $checkinRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $regId,
        ]);
        $checkinRes->assertStatus(200);

        // 4. Cư dân kiểm tra lại chi tiết lượt khách của mình -> qr_pass_status đã là USED
        $residentDetailRes = $this->withHeaders([
            'Authorization' => "Bearer {$this->residentToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/resident/visitors/{$regId}");

        $residentDetailRes->assertStatus(200);
        $this->assertEquals('USED', $residentDetailRes->json('data.qr_pass_status'));
    }
}
