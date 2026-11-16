<?php

namespace Tests\Feature\ResidentVisitor;

use App\Models\Apartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ResidentVisitorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentVisitorRbacAndRouteSecurityTest extends TestCase
{
    protected ?User $residentA = null;

    protected ?string $tokenA = null;

    protected ?Apartment $apartmentA = null;

    protected ResidentVisitorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ResidentVisitorService::class);

        [$this->residentA, $this->tokenA] = $this->createResidentUser('res_a_m605_');
        $this->apartmentA = $this->getOrCreateApartment('APT-M605-'.Str::random(4));
        $this->assignResidentToApartment($this->residentA->id, $this->apartmentA->id);
    }

    protected function getOrCreateApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_M605_'.Str::random(4),
                'block_name' => 'Tòa M605 Test',
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
                'floor_number' => 8,
                'floor_code' => 'FL-8',
                'floor_name' => 'Tầng 8',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Apartment::create([
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'apartment_number' => $code,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 80.0,
            'net_usable_area_sqm' => 75.0,
        ]);
    }

    protected function createResidentUser(string $prefix): array
    {
        $user = User::create([
            'username' => $prefix.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân '.strtoupper(substr($prefix, 0, 8)),
            'status' => 'ACTIVE',
        ]);

        $token = 'token_m605_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Runner M6-05',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'is_revoked' => 0,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function assignResidentToApartment(string $userId, string $apartmentId): void
    {
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'apartment_id' => $apartmentId,
            'resident_type' => 'OWNER',
            'is_head_of_household' => 1,
            'stay_start_date' => now()->subYear()->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createUserWithStaffPermissions(array $permissionCodes, string $baseRoleCode = 'STAFF'): array
    {
        [$user, $token] = $this->createResidentUser('staff_m605_');

        $baseRole = Role::firstOrCreate(
            ['role_code' => $baseRoleCode],
            [
                'role_name' => $baseRoleCode,
                'description' => 'Role '.$baseRoleCode,
                'is_system_role' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $baseRole->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $customRole = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => 'CUSTOM_USER_'.$user->id,
            'role_name' => 'Quyền tùy chỉnh người dùng '.$user->username,
            'description' => 'Role tùy chỉnh trong test M6-05',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $customRole->id,
            'is_primary' => 0,
            'assigned_at' => now(),
        ]);

        foreach ($permissionCodes as $code) {
            $perm = Permission::firstOrCreate(
                ['permission_code' => $code],
                ['module' => 'VISITOR', 'permission_name' => $code, 'description' => $code, 'created_at' => now()]
            );

            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $customRole->id,
                'permission_id' => $perm->id,
                'created_at' => now(),
            ]);
        }

        $user->clearPermissionCache();

        return [$user, $token];
    }

    protected function authHeaders(?string $token): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    /**
     * Test 1: Toàn bộ 8 route /api/v1/resident/visitors* từ chối 401 Unauthorized khi không có Bearer token.
     */
    public function test_01_all_resident_visitor_routes_strictly_reject_unauthenticated_requests_with_401(): void
    {
        $fakeId = (string) Str::uuid();

        // 1. GET /api/v1/resident/visitors
        $this->getJson('/api/v1/resident/visitors')->assertStatus(401);

        // 2. POST /api/v1/resident/visitors
        $this->postJson('/api/v1/resident/visitors', ['visitor_name' => 'Khách'])->assertStatus(401);

        // 3. GET /api/v1/resident/visitors/{id}
        $this->getJson("/api/v1/resident/visitors/{$fakeId}")->assertStatus(401);

        // 4. PUT /api/v1/resident/visitors/{id}
        $this->putJson("/api/v1/resident/visitors/{$fakeId}", ['visitor_name' => 'Sửa'])->assertStatus(401);

        // 5. PATCH /api/v1/resident/visitors/{id}
        $this->patchJson("/api/v1/resident/visitors/{$fakeId}", ['visitor_name' => 'Sửa'])->assertStatus(401);

        // 6. POST /api/v1/resident/visitors/{id}/cancel
        $this->postJson("/api/v1/resident/visitors/{$fakeId}/cancel")->assertStatus(401);

        // 7. DELETE /api/v1/resident/visitors/{id}
        $this->deleteJson("/api/v1/resident/visitors/{$fakeId}")->assertStatus(401);

        // 8. POST /api/v1/resident/visitors/{id}/extend
        $this->postJson("/api/v1/resident/visitors/{$fakeId}/extend", [
            'expected_departure_time' => Carbon::now()->addDays(2)->toIso8601String(),
        ])->assertStatus(401);
    }

    /**
     * Test 2: Nhân viên (STAFF/RECEPTIONIST) KHÔNG có permission VISITOR:UPDATE bị chặn 403 khi cố sửa lượt khách của cư dân.
     */
    public function test_02_staff_role_without_visitor_update_permission_cannot_update_resident_visitor(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Của Cư Dân A',
            'visitor_phone' => '0911222333',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        // Staff chỉ có quyền VISITOR:VIEW, không có VISITOR:UPDATE
        [$staffUser, $staffToken] = $this->createUserWithStaffPermissions(['VISITOR:VIEW'], 'RECEPTIONIST');

        $response = $this->putJson("/api/v1/resident/visitors/{$visitorId}", [
            'visitor_name' => 'Nhân Viên Cố Sửa Không Quyền',
        ], $this->authHeaders($staffToken));

        $response->assertStatus(403);
    }

    /**
     * Test 3: Nhân viên có permission VISITOR:UPDATE được phép cập nhật thông tin lượt khách của cư dân (200 OK).
     */
    public function test_03_staff_role_with_visitor_update_permission_can_update_resident_visitor(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Chuẩn Bị Sửa',
            'visitor_phone' => '0911222444',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        // Staff có quyền VISITOR:UPDATE
        [$staffUser, $staffToken] = $this->createUserWithStaffPermissions(['VISITOR:VIEW', 'VISITOR:UPDATE'], 'STAFF');

        $response = $this->putJson("/api/v1/resident/visitors/{$visitorId}", [
            'visitor_name' => 'Nhân Viên Đã Sửa Hợp Lệ',
            'visitor_count' => 4,
        ], $this->authHeaders($staffToken));

        $response->assertStatus(200);
        $this->assertEquals('Nhân Viên Đã Sửa Hợp Lệ', $response->json('data.visitor_name'));
        $this->assertEquals(4, $response->json('data.visitor_count'));
    }

    /**
     * Test 4: Nhân viên KHÔNG có permission VISITOR:DELETE bị chặn 403 khi cố hủy lượt khách của cư dân.
     */
    public function test_04_staff_role_without_visitor_delete_permission_cannot_cancel_resident_visitor(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Không Cho Staff Hủy',
            'visitor_phone' => '0911222555',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        // Staff chỉ có VISITOR:VIEW, thiếu VISITOR:DELETE
        [$staffUser, $staffToken] = $this->createUserWithStaffPermissions(['VISITOR:VIEW'], 'RECEPTIONIST');

        $response = $this->postJson("/api/v1/resident/visitors/{$visitorId}/cancel", [
            'reason' => 'Staff cố hủy không quyền',
        ], $this->authHeaders($staffToken));

        $response->assertStatus(403);
    }

    /**
     * Test 5: Nhân viên có permission VISITOR:DELETE được phép hủy lượt khách của cư dân (200 OK).
     */
    public function test_05_staff_role_with_visitor_delete_permission_can_cancel_resident_visitor(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Cho Staff Hủy',
            'visitor_phone' => '0911222666',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        // Staff có quyền VISITOR:DELETE
        [$staffUser, $staffToken] = $this->createUserWithStaffPermissions(['VISITOR:VIEW', 'VISITOR:DELETE'], 'BUILDING_MANAGER');

        $response = $this->postJson("/api/v1/resident/visitors/{$visitorId}/cancel", [
            'reason' => 'BQL hủy theo yêu cầu tổng đài',
        ], $this->authHeaders($staffToken));

        $response->assertStatus(200);
        $this->assertEquals('CANCELLED', $response->json('data.qr_pass_status'));
    }

    /**
     * Test 6: Nhân viên KHÔNG có permission VISITOR:VIEW bị chặn 403 khi cố xem chi tiết lượt khách của cư dân.
     */
    public function test_06_staff_role_without_visitor_view_permission_cannot_view_resident_visitor_detail(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Bảo Mật A',
            'visitor_phone' => '0911222777',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        // Staff không có bất kỳ quyền VISITOR nào
        [$staffUser, $staffToken] = $this->createUserWithStaffPermissions([], 'STAFF');

        $response = $this->getJson("/api/v1/resident/visitors/{$visitorId}", $this->authHeaders($staffToken));
        $response->assertStatus(403);
    }

    /**
     * Test 7: Chính cư dân tạo lượt khách (Owner) luôn được quyền xem, cập nhật, gia hạn và hủy lượt của mình.
     */
    public function test_07_resident_owner_can_always_view_update_cancel_and_extend_their_own_visitor(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Của Cư Dân Chính Chủ',
            'visitor_phone' => '0911222888',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        // 1. Xem chi tiết
        $viewRes = $this->getJson("/api/v1/resident/visitors/{$visitorId}", $this->authHeaders($this->tokenA));
        $viewRes->assertStatus(200);
        $this->assertEquals($visitorId, $viewRes->json('data.id'));

        // 2. Cập nhật thông tin
        $updateRes = $this->putJson("/api/v1/resident/visitors/{$visitorId}", [
            'visitor_name' => 'Khách Đã Được Cư Dân Sửa',
        ], $this->authHeaders($this->tokenA));
        $updateRes->assertStatus(200);
        $this->assertEquals('Khách Đã Được Cư Dân Sửa', $updateRes->json('data.visitor_name'));

        // 3. Gia hạn thời gian rời đi
        $newDeparture = Carbon::now()->addHours(6)->toIso8601String();
        $extendRes = $this->postJson("/api/v1/resident/visitors/{$visitorId}/extend", [
            'expected_departure_time' => $newDeparture,
        ], $this->authHeaders($this->tokenA));
        $extendRes->assertStatus(200);
        $this->assertTrue($extendRes->json('success'));

        // 4. Hủy lượt khách
        $cancelRes = $this->postJson("/api/v1/resident/visitors/{$visitorId}/cancel", [
            'reason' => 'Cư dân tự hủy',
        ], $this->authHeaders($this->tokenA));
        $cancelRes->assertStatus(200);
        $this->assertEquals('CANCELLED', $cancelRes->json('data.qr_pass_status'));
    }

    /**
     * Test 8: Chặn tạo hoặc cập nhật expected_arrival_time về quá khứ (422 Unprocessable Entity).
     */
    public function test_08_cannot_update_or_create_visitor_with_past_arrival_time(): void
    {
        $pastTime = Carbon::now()->subHours(2)->toIso8601String();

        // 1. Chặn tạo mới với ngày đến trong quá khứ
        $createRes = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Quá Khứ',
            'visitor_phone' => '0911222999',
            'expected_arrival_time' => $pastTime,
            'apartment_id' => $this->apartmentA->id,
        ], $this->authHeaders($this->tokenA));

        $createRes->assertStatus(422);

        // 2. Chặn cập nhật ngày đến về quá khứ
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Sắp Bị Sửa Về Quá Khứ',
            'visitor_phone' => '0911333000',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        $updateRes = $this->putJson("/api/v1/resident/visitors/{$visitorId}", [
            'expected_arrival_time' => $pastTime,
        ], $this->authHeaders($this->tokenA));

        $updateRes->assertStatus(422);
    }

    /**
     * Test 9: Cư dân gia hạn giờ rời đi thành công sau khi khách đã check-in qua API riêng (/extend).
     */
    public function test_09_resident_can_extend_departure_time_after_checkin(): void
    {
        $initialArrival = Carbon::now()->addHours(1);
        $initialDeparture = Carbon::now()->addHours(3);

        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Đã Vào Tòa Nhà',
            'visitor_phone' => '0911333111',
            'expected_arrival_time' => $initialArrival->toIso8601String(),
            'expected_departure_time' => $initialDeparture->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        // Giả lập khách đã check-in tại quầy lễ tân
        DB::table('visitor_checkin_logs')->insert([
            'id' => (string) Str::uuid(),
            'registration_id' => $visitorId,
            'apartment_id' => $this->apartmentA->id,
            'visitor_name' => 'Khách Đã Vào Tòa Nhà',
            'visitor_phone' => '0911333111',
            'checkin_time' => Carbon::now()->toDateTimeString(),
            'checkout_time' => null,
            'created_at' => now(),
        ]);

        // Cố sửa thông tin qua PUT /visitors/{id} -> Bị chặn 422
        $blockedUpdate = $this->putJson("/api/v1/resident/visitors/{$visitorId}", [
            'visitor_name' => 'Cố Sửa Khi Đã Checkin',
        ], $this->authHeaders($this->tokenA));
        $blockedUpdate->assertStatus(422);

        // Gọi API chuyên biệt gia hạn giờ rời đi POST /visitors/{id}/extend -> Thành công 200
        $extendedDeparture = Carbon::now()->addHours(8)->toIso8601String();
        $extendRes = $this->postJson("/api/v1/resident/visitors/{$visitorId}/extend", [
            'expected_departure_time' => $extendedDeparture,
            'reason' => 'Khách ở lại ăn tối cùng gia đình',
        ], $this->authHeaders($this->tokenA));

        $extendRes->assertStatus(200);
        $this->assertTrue($extendRes->json('success'));

        // Kiểm tra trong database thời gian departure đã được cập nhật
        $record = DB::table('visitor_registrations')->where('id', $visitorId)->first();
        $this->assertEquals(
            Carbon::parse($extendedDeparture)->toDateTimeString(),
            Carbon::parse($record->expected_departure_time)->toDateTimeString()
        );
    }

    /**
     * Test 10: API gia hạn giờ rời đi kiểm tra chặt chẽ các điều kiện thời gian.
     */
    public function test_10_extend_departure_validates_new_time_is_after_arrival_and_current_time(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Test Validation Gia Hạn',
            'visitor_phone' => '0911333222',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'expected_departure_time' => Carbon::now()->addHours(4)->toIso8601String(),
        ]);
        $visitorId = $created['data']->id;

        // 1. Gia hạn với thời gian ở quá khứ -> 422
        $pastRes = $this->postJson("/api/v1/resident/visitors/{$visitorId}/extend", [
            'expected_departure_time' => Carbon::now()->subHours(1)->toIso8601String(),
        ], $this->authHeaders($this->tokenA));
        $pastRes->assertStatus(422);

        // 2. Gia hạn với thời gian trước hoặc bằng giờ đến (trước +2h) -> 422
        $beforeArrivalRes = $this->postJson("/api/v1/resident/visitors/{$visitorId}/extend", [
            'expected_departure_time' => Carbon::now()->addHours(1)->toIso8601String(),
        ], $this->authHeaders($this->tokenA));
        $beforeArrivalRes->assertStatus(422);

        // 3. Gia hạn với thời gian trước hoặc bằng giờ rời đã có (trước +4h) -> 422
        $beforeOldDepartureRes = $this->postJson("/api/v1/resident/visitors/{$visitorId}/extend", [
            'expected_departure_time' => Carbon::now()->addHours(3)->toIso8601String(),
        ], $this->authHeaders($this->tokenA));
        $beforeOldDepartureRes->assertStatus(422);
    }
}
