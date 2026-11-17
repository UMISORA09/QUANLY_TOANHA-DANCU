<?php

namespace Tests\Feature\ResidentVisitor;

use App\Models\Apartment;
use App\Models\Role;
use App\Models\User;
use App\Services\QuocTinRealtimeService;
use App\Services\ResidentVisitorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ResidentVisitorTest extends TestCase
{
    protected ?User $residentA = null;

    protected ?string $tokenA = null;

    protected ?User $residentB = null;

    protected ?string $tokenB = null;

    protected ?Apartment $apartmentA = null;

    protected ?Apartment $apartmentB = null;

    protected ResidentVisitorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ResidentVisitorService::class);

        // 1. Tạo 2 cư dân độc lập A và B
        [$this->residentA, $this->tokenA] = $this->createResidentUser('resident_a_');
        [$this->residentB, $this->tokenB] = $this->createResidentUser('resident_b_');

        // 2. Tạo 2 căn hộ riêng biệt
        $this->apartmentA = $this->getOrCreateApartment('APT-A-'.Str::random(4));
        $this->apartmentB = $this->getOrCreateApartment('APT-B-'.Str::random(4));

        // 3. Gán cư dân vào căn hộ
        $this->assignResidentToApartment($this->residentA->id, $this->apartmentA->id);
        $this->assignResidentToApartment($this->residentB->id, $this->apartmentB->id);
    }

    protected function getOrCreateApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_TEST_'.Str::random(4),
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
                'floor_number' => 8,
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
            'full_name' => 'Cư Dân Test '.strtoupper(substr($prefix, 0, 10)),
            'status' => 'ACTIVE',
        ]);

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

    protected function assignResidentToApartment(string $userId, string $apartmentId): void
    {
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'apartment_id' => $apartmentId,
            'resident_type' => 'OWNER',
            'is_head_of_household' => 1,
            'stay_start_date' => now()->subMonths(6)->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
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
     * 1. Cư dân tạo lượt khai báo thành công
     */
    public function test_01_resident_can_create_visitor_registration_successfully(): void
    {
        $payload = [
            'visitor_name' => 'Nguyễn Khách A',
            'visitor_phone' => '0988776655',
            'visitor_national_id' => '001099123456',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'expected_departure_time' => Carbon::now()->addHours(5)->toIso8601String(),
            'visit_purpose' => 'Thăm bạn bè cuối tuần',
            'visitor_count' => 2,
            'vehicle_license_plate' => '29A-123.45',
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($this->tokenA));

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertNotNull($response->json('data.id'));
        $this->assertEquals('Nguyễn Khách A', $response->json('data.visitor_name'));
        $this->assertEquals('ACTIVE', $response->json('data.qr_pass_status'));
        $this->assertStringStartsWith('PASS_', $response->json('data.qr_access_pass_code'));
        $this->assertStringStartsWith('VIS-', $response->json('data.registration_code'));
        $this->assertEquals($this->residentA->id, $response->json('data.host_resident_user_id'));
    }

    /**
     * 2. Dữ liệu thiếu hoặc không hợp lệ bị từ chối (422)
     */
    public function test_02_validation_fails_on_missing_or_invalid_data(): void
    {
        // Thiếu visitor_name và visitor_phone
        $response = $this->postJson('/api/v1/resident/visitors', [
            'expected_arrival_time' => Carbon::now()->addHours(1)->toIso8601String(),
        ], $this->authHeaders($this->tokenA));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['visitor_name', 'visitor_phone']);

        // Departure time trước arrival time
        $response2 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Lê Khách',
            'visitor_phone' => '0912345678',
            'expected_arrival_time' => Carbon::now()->addHours(4)->toIso8601String(),
            'expected_departure_time' => Carbon::now()->addHours(1)->toIso8601String(),
        ], $this->authHeaders($this->tokenA));

        $response2->assertStatus(422);
    }

    /**
     * 3. Cư dân xem được các lượt khai báo thuộc quyền của mình
     */
    public function test_03_resident_can_list_their_own_visitor_registrations(): void
    {
        $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Của A',
            'visitor_phone' => '0933111222',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);

        $response = $this->getJson('/api/v1/resident/visitors', $this->authHeaders($this->tokenA));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(1, count($data));
        $this->assertEquals('Khách Của A', $data[0]['visitor_name']);
        $this->assertNotNull($response->json('kpis'));
    }

    /**
     * 4. Cư dân không thể xem dữ liệu của cư dân khác (cô lập dữ liệu khách)
     */
    public function test_04_resident_cannot_see_other_residents_visitors(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Bí Mật Của A',
            'visitor_phone' => '0933999888',
            'expected_arrival_time' => Carbon::now()->addHours(3)->toIso8601String(),
        ]);

        // Cư dân B lấy danh sách của mình -> không được có khách của A
        $listResponse = $this->getJson('/api/v1/resident/visitors', $this->authHeaders($this->tokenB));
        $listResponse->assertStatus(200);
        $names = collect($listResponse->json('data'))->pluck('visitor_name')->toArray();
        $this->assertNotContains('Khách Bí Mật Của A', $names);

        // Cư dân B cố truy cập trực tiếp ID của khách A -> 403 Forbidden
        $detailResponse = $this->getJson("/api/v1/resident/visitors/{$created['data']->id}", $this->authHeaders($this->tokenB));
        $detailResponse->assertStatus(403);
    }

    /**
     * 5. Cư dân sửa được lượt khai báo được phép sửa
     */
    public function test_05_resident_can_update_active_visitor_registration(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Cần Sửa Tên',
            'visitor_phone' => '0911222333',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $id = $created['data']->id;

        $updatePayload = [
            'visitor_name' => 'Khách Đã Đổi Tên',
            'visit_purpose' => 'Sửa chữa điện thoại',
            'visitor_count' => 3,
        ];

        $response = $this->putJson("/api/v1/resident/visitors/{$id}", $updatePayload, $this->authHeaders($this->tokenA));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertEquals('Khách Đã Đổi Tên', $response->json('data.visitor_name'));
        $this->assertEquals(3, $response->json('data.visitor_count'));
    }

    /**
     * 6. Cư dân không được sửa lượt không thuộc quyền sở hữu (403)
     */
    public function test_06_resident_cannot_update_other_residents_visitor(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Của A',
            'visitor_phone' => '0922333444',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $id = $created['data']->id;

        // Cư dân B cố cập nhật lượt của cư dân A
        $response = $this->putJson("/api/v1/resident/visitors/{$id}", [
            'visitor_name' => 'Hack Tên',
        ], $this->authHeaders($this->tokenB));

        $response->assertStatus(403);
    }

    /**
     * 7. Cư dân hủy được lượt khai báo hợp lệ (soft cancel, giữ lịch sử)
     */
    public function test_07_resident_can_cancel_valid_active_visitor_registration(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Sắp Hủy',
            'visitor_phone' => '0944555666',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $id = $created['data']->id;

        $response = $this->postJson("/api/v1/resident/visitors/{$id}/cancel", [
            'reason' => 'Khách bận việc đột xuất',
        ], $this->authHeaders($this->tokenA));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertEquals('CANCELLED', $response->json('data.qr_pass_status'));

        // Kiểm tra trong DB vẫn còn bản ghi với trạng thái CANCELLED
        $record = DB::table('visitor_registrations')->where('id', $id)->first();
        $this->assertNotNull($record);
        $this->assertEquals('CANCELLED', $record->qr_pass_status);
    }

    /**
     * 8. Không thể hủy lượt ở trạng thái bị nghiệp vụ cấm
     */
    public function test_08_cannot_cancel_already_cancelled_or_used_visitor(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Test Hủy 2 Lần',
            'visitor_phone' => '0955666777',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $id = $created['data']->id;

        // Lần 1: hủy thành công
        $this->service->cancelVisitor($this->residentA, $id);

        // Lần 2: hủy lại lượt đã CANCELLED -> từ chối 422
        $response = $this->postJson("/api/v1/resident/visitors/{$id}/cancel", [], $this->authHeaders($this->tokenA));
        $response->assertStatus(422);
    }

    /**
     * 9. Record không tồn tại được xử lý đúng (404)
     */
    public function test_09_not_found_visitor_returns_404(): void
    {
        $fakeId = (string) Str::uuid();

        $response = $this->getJson("/api/v1/resident/visitors/{$fakeId}", $this->authHeaders($this->tokenA));
        $response->assertStatus(404);

        $responseUpdate = $this->putJson("/api/v1/resident/visitors/{$fakeId}", ['visitor_name' => 'Test'], $this->authHeaders($this->tokenA));
        $responseUpdate->assertStatus(404);
    }

    /**
     * 10. Unauthorized user bị từ chối 401
     */
    public function test_10_unauthorized_user_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/resident/visitors');
        $response->assertStatus(401);

        $responseCreate = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Vô Danh',
            'visitor_phone' => '0912345678',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $responseCreate->assertStatus(401);
    }

    /**
     * 11. Realtime: A tạo lượt khai báo -> event CREATED được phát với metadata an toàn
     */
    public function test_11_realtime_event_emitted_on_create_with_safe_payload(): void
    {
        $lastEventIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Realtime Check',
            'visitor_phone' => '0977888999',
            'visitor_national_id' => '001095999888',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);

        $event = DB::table('realtime_sync_events')
            ->where('id', '>', $lastEventIdBefore)
            ->where('entity', 'visitor_registration')
            ->where('action', 'CREATED')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($event, 'Phải phát sự kiện CREATED sau khi tạo lượt khách');
        $this->assertEquals('quoc-tin.visitors', $event->channel);
        $this->assertEquals($created['data']->id, $event->entity_id);

        $payload = json_decode($event->payload, true);
        $this->assertEquals($this->residentA->id, $payload['host_resident_user_id'] ?? null);
        // Đảm bảo không phát tán thông tin nhạy cảm (CCCD, SĐT) trên payload broadcast
        $this->assertArrayNotHasKey('visitor_phone', $payload);
        $this->assertArrayNotHasKey('visitor_national_id', $payload);
    }

    /**
     * 12. Realtime: A sửa và hủy -> event UPDATED và CANCELLED được phát
     */
    public function test_12_realtime_event_emitted_on_update_and_cancel(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Sửa Hủy Realtime',
            'visitor_phone' => '0911555999',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $id = $created['data']->id;

        // Cập nhật
        $lastIdBeforeUpdate = DB::table('realtime_sync_events')->max('id') ?? 0;
        $this->service->updateVisitor($this->residentA, $id, ['visitor_name' => 'Khách Tên Mới']);

        $updateEvent = DB::table('realtime_sync_events')
            ->where('id', '>', $lastIdBeforeUpdate)
            ->where('action', 'UPDATED')
            ->where('entity_id', $id)
            ->first();
        $this->assertNotNull($updateEvent, 'Phải phát sự kiện UPDATED');

        // Hủy
        $lastIdBeforeCancel = DB::table('realtime_sync_events')->max('id') ?? 0;
        $this->service->cancelVisitor($this->residentA, $id);

        $cancelEvent = DB::table('realtime_sync_events')
            ->where('id', '>', $lastIdBeforeCancel)
            ->where('action', 'CANCELLED')
            ->where('entity_id', $id)
            ->first();
        $this->assertNotNull($cancelEvent, 'Phải phát sự kiện CANCELLED');
    }

    /**
     * 13. Cooldown theo bản ghi: bảo vệ bản ghi khỏi concurrent mutation nhưng KHÔNG khóa cư dân khác
     */
    public function test_13_record_level_cooldown_protects_record_without_locking_other_residents(): void
    {
        $createdA = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Của A',
            'visitor_phone' => '0988111222',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $idA = $createdA['data']->id;

        // Cư dân A cập nhật -> kích hoạt cooldown 30s cho bản ghi idA
        $this->service->updateVisitor($this->residentA, $idA, ['visitor_name' => 'Khách A Vừa Sửa']);

        // Phiên khác cố sửa bản ghi idA trong thời gian cooldown -> bị từ chối 409
        $diffUser = User::create([
            'username' => 'other_user_'.Str::random(6),
            'full_name' => 'Other Admin User',
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'other_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'status' => 'ACTIVE',
        ]);
        // Gán quyền admin để vượt qua bước check ownership
        $adminRole = DB::table('roles')->where('role_code', 'SUPER_ADMIN')->value('id');
        if ($adminRole) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $diffUser->id,
                'role_id' => $adminRole,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $cooldownConflict = false;
        try {
            $this->service->updateVisitor($diffUser, $idA, ['visitor_name' => 'Ghi Đè']);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 409) {
                $cooldownConflict = true;
            }
        }
        $this->assertTrue($cooldownConflict, 'Phiên khác phải nhận mã 409 khi cố sửa bản ghi đang trong cooldown');

        // QUAN TRỌNG: Cư dân B vẫn tạo lượt khách hoàn toàn bình thường, KHÔNG bị khóa
        $createdB = $this->service->createVisitor($this->residentB, [
            'visitor_name' => 'Khách Độc Lập Của B',
            'visitor_phone' => '0977222333',
            'expected_arrival_time' => Carbon::now()->addHours(3)->toIso8601String(),
        ]);
        $this->assertNotNull($createdB['data']->id);
        $this->assertEquals('Khách Độc Lập Của B', $createdB['data']->visitor_name);

        // Xóa cooldown bản ghi để dọn dẹp
        QuocTinRealtimeService::clearVisitorRecordCooldown($idA);
    }

    /**
     * 14. Thao tác GET / list / detail vẫn hoạt động bình thường trong cooldown
     */
    public function test_14_read_operations_allowed_during_cooldown(): void
    {
        $created = $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Khách Test Đọc Khi Cooldown',
            'visitor_phone' => '0933444555',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);
        $id = $created['data']->id;

        // Giả lập bản ghi đang trong cooldown
        QuocTinRealtimeService::setVisitorRecordCooldown($id, $this->residentA->id, 60);

        // Thao tác xem danh sách vẫn trả về 200
        $listResponse = $this->getJson('/api/v1/resident/visitors', $this->authHeaders($this->tokenA));
        $listResponse->assertStatus(200);

        // Thao tác xem chi tiết vẫn trả về 200
        $detailResponse = $this->getJson("/api/v1/resident/visitors/{$id}", $this->authHeaders($this->tokenA));
        $detailResponse->assertStatus(200);
        $this->assertNotNull($detailResponse->json('cooldown'));

        QuocTinRealtimeService::clearVisitorRecordCooldown($id);
    }

    /**
     * 15. Server-side pagination và tìm kiếm lọc trạng thái
     */
    public function test_15_server_side_pagination_and_search_filter(): void
    {
        // Tạo 3 khách với tên đặc thù
        $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Alpha Visitor Unique',
            'visitor_phone' => '0911000001',
            'expected_arrival_time' => Carbon::now()->addHours(1)->toIso8601String(),
        ]);
        $this->service->createVisitor($this->residentA, [
            'visitor_name' => 'Beta Visitor Unique',
            'visitor_phone' => '0911000002',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ]);

        // Tìm kiếm theo từ khóa
        $searchResponse = $this->getJson('/api/v1/resident/visitors?search=Alpha', $this->authHeaders($this->tokenA));
        $searchResponse->assertStatus(200);
        $searchData = $searchResponse->json('data');
        $this->assertCount(1, $searchData);
        $this->assertEquals('Alpha Visitor Unique', $searchData[0]['visitor_name']);

        // Phân trang với limit = 1
        $pageResponse = $this->getJson('/api/v1/resident/visitors?limit=1&page=1', $this->authHeaders($this->tokenA));
        $pageResponse->assertStatus(200);
        $this->assertCount(1, $pageResponse->json('data'));
        $this->assertEquals(1, $pageResponse->json('pagination.per_page'));
        $this->assertGreaterThanOrEqual(2, $pageResponse->json('pagination.total'));
    }

    /**
     * 16. Cư dân là chủ hộ trong apartment_owners (chưa có trong bảng residents) vẫn tạo khai báo thành công
     */
    public function test_16_resident_who_is_apartment_owner_can_create_visitor_without_residents_record(): void
    {
        [$ownerUser, $ownerToken] = $this->createResidentUser('owner_only_');
        $ownerApt = $this->getOrCreateApartment('APT-OWNER-'.Str::random(4));

        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $ownerApt->id,
            'owner_user_id' => $ownerUser->id,
            'ownership_percentage' => 100.00,
            'ownership_start_date' => now()->toDateString(),
            'is_current_owner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'visitor_name' => 'Chị Nguyễn Thị Khách',
            'visitor_phone' => '0933888999',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'visit_purpose' => 'Thăm chủ hộ',
        ];

        $response = $this->postJson('/api/v1/resident/visitors', $payload, $this->authHeaders($ownerToken));

        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $this->assertEquals($ownerApt->id, $response->json('data.apartment_id'));
    }

    protected function createUserWithRoleForVisitorTest(string $roleCode, string $prefix): array
    {
        [$user, $token] = $this->createResidentUser($prefix);

        $role = Role::firstOrCreate(
            ['role_code' => $roleCode],
            [
                'role_name' => $roleCode,
                'description' => 'Test role '.$roleCode,
                'is_system_role' => in_array($roleCode, ['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST', 'RESIDENT'], true),
            ]
        );

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * 17. BUG-M6-01 (Req 1): Cư dân truyền rõ apartment_id hợp lệ của chính mình (cư trú hoặc sở hữu) tạo QR thành công
     */
    public function test_17_resident_can_create_visitor_for_explicit_authorized_apartment(): void
    {
        $secondOwnedApt = $this->getOrCreateApartment('APT-OWNED-2-'.Str::random(4));
        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $secondOwnedApt->id,
            'owner_user_id' => $this->residentA->id,
            'ownership_percentage' => 100.00,
            'ownership_start_date' => now()->subDays(10)->toDateString(),
            'is_current_owner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tạo cho căn hộ cư trú A
        $res1 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Căn Hộ A Hợp Lệ',
            'visitor_phone' => '0966111001',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentA->id,
        ], $this->authHeaders($this->tokenA));

        $res1->assertStatus(201);
        $this->assertEquals($this->apartmentA->id, $res1->json('data.apartment_id'));
        $this->assertEquals('ACTIVE', $res1->json('data.qr_pass_status'));
        $this->assertStringStartsWith('PASS_', (string) $res1->json('data.qr_access_pass_code'));

        // Tạo cho căn hộ sở hữu thứ 2
        $res2 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Căn Hộ Sở Hữu 2',
            'visitor_phone' => '0966111002',
            'expected_arrival_time' => Carbon::now()->addHours(3)->toIso8601String(),
            'apartment_id' => $secondOwnedApt->id,
        ], $this->authHeaders($this->tokenA));

        $res2->assertStatus(201);
        $this->assertEquals($secondOwnedApt->id, $res2->json('data.apartment_id'));
        $this->assertEquals('ACTIVE', $res2->json('data.qr_pass_status'));
    }

    /**
     * 18. BUG-M6-01 (Req 2, 5, 6): Cư dân gửi apartment_id của căn hộ người khác bị từ chối 403, không tạo QR hay event
     */
    public function test_18_resident_cannot_create_visitor_for_another_residents_apartment(): void
    {
        $visitorCountBefore = DB::table('visitor_registrations')->count();
        $eventCountBefore = DB::table('realtime_sync_events')->count();

        $response = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Xâm Nhập Căn B',
            'visitor_phone' => '0966222001',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentB->id,
        ], $this->authHeaders($this->tokenA));

        $response->assertStatus(403);

        $this->assertSame($visitorCountBefore, DB::table('visitor_registrations')->count());
        $this->assertSame($eventCountBefore, DB::table('realtime_sync_events')->count());
        $this->assertFalse(
            DB::table('visitor_registrations')
                ->where('host_resident_user_id', $this->residentA->id)
                ->where('apartment_id', $this->apartmentB->id)
                ->where('qr_pass_status', 'ACTIVE')
                ->exists()
        );
    }

    /**
     * 19. BUG-M6-01 (Req 3, 5, 6): Cư dân / tài khoản không có căn hộ cố truyền apartment_id bất kỳ bị từ chối 403, không sinh QR ACTIVE
     */
    public function test_19_user_without_apartment_cannot_bypass_by_passing_target_apartment_id(): void
    {
        [$unlinkedResident, $unlinkedToken] = $this->createUserWithRoleForVisitorTest('RESIDENT_OWNER', 'unlinked_res_');
        [$pointerOnlyUser, $pointerToken] = $this->createResidentUser('pointer_only_');

        // Tạo căn hộ chỉ trỏ current_resident_user_id nhưng không có bản ghi residents / apartment_owners hợp lệ
        $pointerApt = $this->getOrCreateApartment('APT-PTR-'.Str::random(4));
        DB::table('apartments')->where('id', $pointerApt->id)->update([
            'current_resident_user_id' => $pointerOnlyUser->id,
        ]);

        $visitorCountBefore = DB::table('visitor_registrations')->count();
        $eventCountBefore = DB::table('realtime_sync_events')->count();

        // User không có căn hộ cố gắng gửi apartment_id của cư dân B
        $res1 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Kẻ Lạ Xâm Nhập B',
            'visitor_phone' => '0966333001',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentB->id,
        ], $this->authHeaders($unlinkedToken));

        $res1->assertStatus(403);

        // User truyền UUID căn hộ không tồn tại
        $res2 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Kẻ Lạ UUID Giả',
            'visitor_phone' => '0966333002',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => (string) Str::uuid(),
        ], $this->authHeaders($unlinkedToken));

        $res2->assertStatus(403);

        // User chỉ có pointer current_resident_user_id không được phép bypass
        $res3 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Pointer Only Bypass',
            'visitor_phone' => '0966333003',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $pointerApt->id,
        ], $this->authHeaders($pointerToken));

        $res3->assertStatus(403);

        $this->assertSame($visitorCountBefore, DB::table('visitor_registrations')->count());
        $this->assertSame($eventCountBefore, DB::table('realtime_sync_events')->count());
        $this->assertSame(0, DB::table('visitor_registrations')->whereIn('host_resident_user_id', [$unlinkedResident->id, $pointerOnlyUser->id])->count());
    }

    /**
     * 20. BUG-M6-01 (Req 4, 5, 6): Cư dân không có căn hộ hợp lệ và không truyền apartment_id bị trả lỗi, tuyệt đối không lấy căn hộ đầu tiên
     */
    public function test_20_user_without_valid_apartment_and_no_apartment_id_does_not_fallback_to_first_apartment(): void
    {
        [$unlinkedUser, $unlinkedToken] = $this->createResidentUser('no_apt_');
        [$expiredResident, $expiredToken] = $this->createResidentUser('expired_res_');

        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $expiredResident->id,
            'apartment_id' => $this->apartmentA->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => 0,
            'stay_start_date' => now()->subMonths(6)->toDateString(),
            'stay_end_date' => now()->subDay()->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Đảm bảo DB đang có căn hộ tồn tại
        $this->assertNotNull(DB::table('apartments')->first());

        $visitorCountBefore = DB::table('visitor_registrations')->count();
        $eventCountBefore = DB::table('realtime_sync_events')->count();

        // Không có căn hộ và không truyền apartment_id -> 422
        $res1 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Không Căn Hộ',
            'visitor_phone' => '0966444001',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ], $this->authHeaders($unlinkedToken));

        $res1->assertStatus(422);

        // Cư dân hết hạn cư trú không truyền apartment_id -> 422, cố truyền căn hộ cũ -> 403
        $res2 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Cư Dân Hết Hạn',
            'visitor_phone' => '0966444002',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
        ], $this->authHeaders($expiredToken));

        $res2->assertStatus(422);

        $res3 = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Cư Dân Hết Hạn Cố Truyền',
            'visitor_phone' => '0966444003',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentA->id,
        ], $this->authHeaders($expiredToken));

        $res3->assertStatus(403);

        $this->assertSame($visitorCountBefore, DB::table('visitor_registrations')->count());
        $this->assertSame($eventCountBefore, DB::table('realtime_sync_events')->count());
    }

    /**
     * 21. BUG-M6-01 (Req 7): Admin, Quản lý tòa nhà và Lễ tân vẫn tạo lượt khách cho căn hộ hợp lệ bình thường
     */
    public function test_21_admin_and_valid_staff_roles_can_create_visitor_for_valid_apartment(): void
    {
        $roles = ['SUPER_ADMIN', 'ADMIN', 'BUILDING_MANAGER', 'RECEPTIONIST'];

        foreach ($roles as $idx => $roleCode) {
            [$staffUser, $staffToken] = $this->createUserWithRoleForVisitorTest($roleCode, strtolower($roleCode).'_vis_');

            $response = $this->postJson('/api/v1/resident/visitors', [
                'visitor_name' => 'Khách Hỗ Trợ Bởi '.$roleCode,
                'visitor_phone' => '096655500'.$idx,
                'expected_arrival_time' => Carbon::now()->addHours(2 + $idx)->toIso8601String(),
                'apartment_id' => $this->apartmentA->id,
            ], $this->authHeaders($staffToken));

            $response->assertStatus(201);
            $this->assertTrue($response->json('success'));
            $this->assertEquals($this->apartmentA->id, $response->json('data.apartment_id'));
            $this->assertEquals('ACTIVE', $response->json('data.qr_pass_status'));
            $this->assertEquals($staffUser->id, $response->json('data.host_resident_user_id'));
        }

        // Admin truyền apartment_id không tồn tại phải trả 422 sạch sẽ thay vì lỗi 500
        [, $adminToken] = $this->createUserWithRoleForVisitorTest('ADMIN', 'admin_invalid_apt_');
        $visitorCountBefore = DB::table('visitor_registrations')->count();

        $invalidRes = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Căn Hộ Không Tồn Tại',
            'visitor_phone' => '0966555999',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => (string) Str::uuid(),
        ], $this->authHeaders($adminToken));

        $invalidRes->assertStatus(422);
        $this->assertSame($visitorCountBefore, DB::table('visitor_registrations')->count());
    }

    /**
     * 22. BUG-M6-01 Integration: Cư dân hợp lệ tạo QR và liên thông lễ tân (lookup/check-in/check-out), cư dân ngoài căn hộ bị chặn 403
     */
    public function test_22_integration_valid_resident_qr_flows_through_reception_checkin_while_unassigned_resident_is_blocked(): void
    {
        // 1. Cư dân A được gán hợp lệ vào apartmentA -> tạo visitor QR thành công (201)
        $createRes = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Tích Hợp Lễ Tân',
            'visitor_phone' => '0966888111',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'visit_purpose' => 'Giao dịch cư dân hợp lệ',
            'apartment_id' => $this->apartmentA->id,
        ], $this->authHeaders($this->tokenA));

        $createRes->assertStatus(201);
        $regId = (string) $createRes->json('data.id');
        $qrPassCode = (string) $createRes->json('data.qr_access_pass_code');
        $this->assertSame($this->apartmentA->id, $createRes->json('data.apartment_id'));
        $this->assertSame('ACTIVE', $createRes->json('data.qr_pass_status'));
        $this->assertStringStartsWith('PASS_', $qrPassCode);

        // 2. Cư dân khác (residentB) và cư dân chưa được gán căn hộ đều không thể tạo QR cho apartmentA (403)
        [$unassignedResident, $unassignedToken] = $this->createUserWithRoleForVisitorTest('RESIDENT', 'unassigned_int_');
        $countBefore = DB::table('visitor_registrations')->count();

        $blockedResB = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Của B Xâm Nhập A',
            'visitor_phone' => '0966888222',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentA->id,
        ], $this->authHeaders($this->tokenB));
        $blockedResB->assertStatus(403);

        $blockedUnassigned = $this->postJson('/api/v1/resident/visitors', [
            'visitor_name' => 'Khách Của User Chưa Gán',
            'visitor_phone' => '0966888333',
            'expected_arrival_time' => Carbon::now()->addHours(2)->toIso8601String(),
            'apartment_id' => $this->apartmentA->id,
        ], $this->authHeaders($unassignedToken));
        $blockedUnassigned->assertStatus(403);

        $this->assertSame($countBefore, DB::table('visitor_registrations')->count());
        $this->assertSame(0, DB::table('visitor_registrations')->where('host_resident_user_id', $unassignedResident->id)->count());

        // 3. Luồng lễ tân tiếp nhận visitor hợp lệ (lookup -> check-in -> cập nhật USED -> check-out) vẫn hoạt động
        [, $receptionistToken] = $this->createUserWithRoleForVisitorTest('RECEPTIONIST', 'rec_int_');

        $lookupRes = $this->getJson(
            "/api/v1/reception/visitors/lookup?code={$qrPassCode}",
            $this->authHeaders($receptionistToken)
        );
        $lookupRes->assertStatus(200);
        $this->assertSame($regId, $lookupRes->json('data.registration.id'));
        $this->assertTrue((bool) $lookupRes->json('data.can_checkin'));

        $checkinRes = $this->postJson('/api/v1/reception/visitors/check-in', [
            'registration_id' => $regId,
        ], $this->authHeaders($receptionistToken));
        $checkinRes->assertStatus(200);

        $detailAfterCheckin = $this->getJson(
            "/api/v1/resident/visitors/{$regId}",
            $this->authHeaders($this->tokenA)
        );
        $detailAfterCheckin->assertStatus(200);
        $this->assertSame('USED', $detailAfterCheckin->json('data.qr_pass_status'));

        $checkoutRes = $this->postJson('/api/v1/reception/visitors/check-out', [
            'registration_id' => $regId,
        ], $this->authHeaders($receptionistToken));
        $checkoutRes->assertStatus(200);
    }
}
