<?php

namespace Tests\Feature\ResidentVisitor;

use App\Models\Apartment;
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
}
