<?php

namespace Tests\Feature\RfidManagement;

use App\Models\Apartment;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessCardService;
use App\Services\QuocTinRealtimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RfidManagementTest extends TestCase
{
    protected ?User $adminUser = null;

    protected ?string $adminToken = null;

    protected ?Apartment $testApartment = null;

    protected ?User $testResident = null;

    protected AccessCardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AccessCardService::class);

        // 1. Tạo tài khoản admin và token
        [$this->adminUser, $this->adminToken] = $this->createAdminUser();

        // 2. Căn hộ kiểm thử
        $this->testApartment = Apartment::first();
        if (! $this->testApartment) {
            $blockId = DB::table('blocks')->value('id') ?? (string) Str::uuid();
            $floorId = DB::table('floors')->value('id') ?? (string) Str::uuid();
            $this->testApartment = Apartment::create([
                'apartment_number' => 'TEST-APT-'.Str::random(4),
                'block_id' => $blockId,
                'floor_id' => $floorId,
                'status' => 'OCCUPIED',
                'room_type' => '2BR',
                'gross_floor_area_sqm' => 75.5,
                'net_usable_area_sqm' => 70.0,
            ]);
        }

        // 3. Cư dân kiểm thử
        $this->testResident = User::create([
            'username' => 'resident_rfid_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_rfid_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Nguyễn Cư Dân Thẻ '.Str::random(4),
            'status' => 'ACTIVE',
        ]);

        // Đảm bảo không còn cooldown rfid_cards từ test trước
        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');
    }

    protected function createAdminUser(): array
    {
        $user = User::create([
            'username' => 'adm_rfid_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_rfid_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin RFID Tester',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit RFID Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ];
    }

    // ==========================================
    // 1. CRUD TESTS
    // ==========================================

    /**
     * Test 01: Tạo thẻ RFID thành công
     */
    public function test_01_create_rfid_card_successfully(): void
    {
        $payload = [
            'card_uid' => 'UID-TEST-'.Str::random(6),
            'card_number' => 'CARD-'.Str::random(5),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
            'status' => 'ACTIVE',
            'deposit_fee' => 50000,
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $cardId = $response->json('data.id');
        $this->assertNotEmpty($cardId);
        $this->assertDatabaseHas('access_cards', [
            'id' => $cardId,
            'card_uid' => strtoupper($payload['card_uid']),
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Test 02: Không cho tạo mã UID trùng lặp
     */
    public function test_02_prevent_duplicate_card_uid(): void
    {
        $sharedUid = 'DUPLICATE-UID-'.Str::random(6);

        // Tạo thẻ đầu tiên
        $this->service->createCard([
            'card_uid' => $sharedUid,
            'card_number' => 'NUM-A-'.Str::random(4),
            'card_type' => 'ELEVATOR_ONLY',
            'status' => 'ACTIVE',
        ], $this->adminUser->id);

        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

        // Tạo thẻ thứ hai cùng UID
        $response = $this->postJson('/api/v1/rfid-cards', [
            'card_uid' => $sharedUid,
            'card_number' => 'NUM-B-'.Str::random(4),
            'card_type' => 'ELEVATOR_ONLY',
            'status' => 'ACTIVE',
        ], $this->authHeaders());

        $this->assertContains($response->status(), [409, 422]);
        $response->assertJson(['success' => false]);
    }

    /**
     * Test 03: Sửa thông tin thẻ thành công
     */
    public function test_03_update_rfid_card_successfully(): void
    {
        $card = $this->service->createCard([
            'card_uid' => 'UID-UPD-'.Str::random(6),
            'card_number' => 'CARD-UPD-'.Str::random(4),
            'card_type' => 'PARKING_ONLY',
            'status' => 'ACTIVE',
        ], $this->adminUser->id);

        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

        $updatePayload = [
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'deposit_fee' => 100000,
        ];

        $response = $this->putJson("/api/v1/rfid-cards/{$card->id}", $updatePayload, $this->authHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'card_type' => 'RESIDENT_ALL_ACCESS',
                    'deposit_fee' => 100000,
                ],
            ]);
    }

    /**
     * Test 04: Chuyển ACTIVE sang LOCKED_TEMPORARY
     */
    public function test_04_toggle_status_from_active_to_locked(): void
    {
        $card = $this->service->createCard([
            'card_uid' => 'UID-LOCK-'.Str::random(6),
            'card_number' => 'CARD-LOCK-'.Str::random(4),
            'status' => 'ACTIVE',
        ], $this->adminUser->id);

        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

        $response = $this->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'LOCKED_TEMPORARY',
                ],
            ]);

        $this->assertDatabaseHas('access_cards', [
            'id' => $card->id,
            'status' => 'LOCKED_TEMPORARY',
        ]);
    }

    /**
     * Test 05: Chuyển LOCKED sang ACTIVE
     */
    public function test_05_toggle_status_from_locked_to_active(): void
    {
        $card = $this->service->createCard([
            'card_uid' => 'UID-UNLOCK-'.Str::random(6),
            'card_number' => 'CARD-UNLOCK-'.Str::random(4),
            'status' => 'LOCKED_TEMPORARY',
        ], $this->adminUser->id);

        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

        $response = $this->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'ACTIVE',
                ],
            ]);

        $this->assertDatabaseHas('access_cards', [
            'id' => $card->id,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Test 06: Xóa thẻ chưa từng quẹt (Xóa vật lý an toàn)
     */
    public function test_06_delete_card_without_logs(): void
    {
        $card = $this->service->createCard([
            'card_uid' => 'UID-DEL-'.Str::random(6),
            'card_number' => 'CARD-DEL-'.Str::random(4),
            'status' => 'ACTIVE',
        ], $this->adminUser->id);

        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

        $response = $this->deleteJson("/api/v1/rfid-cards/{$card->id}", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'action' => 'DELETED',
            ]);

        $this->assertDatabaseMissing('access_cards', ['id' => $card->id]);
    }

    /**
     * Test 07: Thẻ đã từng có nhật ký quét thì chuyển sang REVOKED thay vì xóa cứng
     */
    public function test_07_revoke_card_with_access_logs(): void
    {
        $card = $this->service->createCard([
            'card_uid' => 'UID-LOG-'.Str::random(6),
            'card_number' => 'CARD-LOG-'.Str::random(4),
            'status' => 'ACTIVE',
        ], $this->adminUser->id);

        // Tạo log quét vào bãi xe
        DB::table('parking_access_logs')->insert([
            'id' => (string) Str::uuid(),
            'card_id' => $card->id,
            'access_direction' => 'IN',
            'gate_name' => 'Cổng Chính Tòa Nhà',
            'detected_license_plate' => '29A-99999',
            'captured_plate_image_url' => '/images/sample_plate.jpg',
            'log_timestamp' => now(),
        ]);

        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

        $response = $this->deleteJson("/api/v1/rfid-cards/{$card->id}", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'action' => 'REVOKED',
            ]);

        $this->assertDatabaseHas('access_cards', [
            'id' => $card->id,
            'status' => 'REVOKED',
        ]);
    }

    /**
     * Test 08: Xử lý đúng khi thẻ không tồn tại (404)
     */
    public function test_08_not_found_card_returns_404(): void
    {
        $fakeId = (string) Str::uuid();

        $response = $this->getJson("/api/v1/rfid-cards/{$fakeId}", $this->authHeaders());

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => 'NOT_FOUND',
            ]);
    }

    /**
     * Test 09: User chưa xác thực bị từ chối (401)
     */
    public function test_09_unauthorized_user_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/rfid-cards', [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(401);
    }

    // ==========================================
    // 2. COOLDOWN 120-SECOND TESTS
    // ==========================================

    /**
     * Test 10: Mutation thành công kích hoạt Cooldown 1 phút (60 giây) cho RFID
     */
    public function test_10_mutation_triggers_60s_cooldown(): void
    {
        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

        $this->assertFalse(QuocTinRealtimeService::isModuleInCooldown('rfid_cards'));

        $this->service->createCard([
            'card_uid' => 'UID-CD-'.Str::random(6),
            'card_number' => 'CARD-CD-'.Str::random(4),
            'status' => 'ACTIVE',
        ], $this->adminUser->id);

        $this->assertTrue(QuocTinRealtimeService::isModuleInCooldown('rfid_cards'));

        $cdData = QuocTinRealtimeService::getModuleCooldown('rfid_cards');
        $this->assertNotNull($cdData);
        $this->assertEquals(60, $cdData['cooldown_seconds']);
        $this->assertGreaterThan(0, $cdData['retry_after']);
    }

    /**
     * Test 11: Mutation tiếp theo trong thời gian Cooldown bị chặn (409)
     */
    public function test_11_subsequent_mutation_blocked_during_cooldown(): void
    {
        // 1. Thực hiện mutation đầu tiên
        $first = $this->postJson('/api/v1/rfid-cards', [
            'card_uid' => 'UID-BLOCK-1-'.Str::random(5),
            'card_number' => 'CARD-B1-'.Str::random(4),
            'status' => 'ACTIVE',
        ], $this->authHeaders());
        $first->assertStatus(201);

        // 2. Ngay lập tức thực hiện mutation thứ hai -> phải bị chặn HTTP 409
        $second = $this->postJson('/api/v1/rfid-cards', [
            'card_uid' => 'UID-BLOCK-2-'.Str::random(5),
            'card_number' => 'CARD-B2-'.Str::random(4),
            'status' => 'ACTIVE',
        ], $this->authHeaders());

        $second->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => 'COOLDOWN_ACTIVE',
            ]);

        $this->assertGreaterThan(0, $second->json('retry_after'));
    }

    /**
     * Test 12: GET danh sách và chi tiết vẫn hoạt động bình thường trong thời gian Cooldown
     */
    public function test_12_read_operations_allowed_during_cooldown(): void
    {
        // Kích hoạt cooldown cho rfid_cards (1 phút)
        QuocTinRealtimeService::setModuleCooldown('rfid_cards', 60, $this->adminUser->id, 'TEST');

        // GET danh sách
        $listRes = $this->getJson('/api/v1/rfid-cards', $this->authHeaders());
        $listRes->assertStatus(200)->assertJson(['success' => true]);

        // GET dropdown
        $aptRes = $this->getJson('/api/v1/rfid-cards/meta/apartments', $this->authHeaders());
        $aptRes->assertStatus(200)->assertJson(['success' => true]);
    }

    /**
     * Test 13: Cooldown của RFID không gây ảnh hưởng hay khóa module khác (ví dụ vehicles)
     */
    public function test_13_rfid_cooldown_does_not_lock_other_modules(): void
    {
        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        // Khóa rfid_cards 60 giây
        QuocTinRealtimeService::setModuleCooldown('rfid_cards', 60, $this->adminUser->id, 'MUTATION');

        $this->assertTrue(QuocTinRealtimeService::isModuleInCooldown('rfid_cards'));
        $this->assertFalse(QuocTinRealtimeService::isModuleInCooldown('vehicles'));
    }

    // ==========================================
    // 3. REALTIME EVENT TESTS
    // ==========================================

    /**
     * Test 14: Mutation phát Realtime Event vào bảng realtime_sync_events trên channel quoc-tin.rfid-cards
     */
    public function test_14_realtime_event_emitted_after_card_created(): void
    {
        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        $card = $this->service->createCard([
            'card_uid' => 'UID-RT-'.Str::random(6),
            'card_number' => 'CARD-RT-'.Str::random(4),
            'status' => 'ACTIVE',
        ], $this->adminUser->id);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.rfid-cards'], $lastIdBefore);

        $this->assertNotEmpty($events);
        $createEvent = collect($events)->firstWhere('action', 'CREATED');
        $this->assertNotNull($createEvent, 'Hệ thống phải broadcast event CREATED cho RFID Card');
        $this->assertEquals('rfid_cards', $createEvent['module']);
        $this->assertEquals($card->id, $createEvent['entity_id']);
    }

    /**
     * Test 15: Phân trang danh sách tối ưu, không tải toàn bảng
     */
    public function test_15_list_pagination_limits_data_transfer(): void
    {
        $paginated = $this->service->listCards(['per_page' => 5]);

        $this->assertLessThanOrEqual(5, count($paginated->items()));
        $this->assertEquals(5, $paginated->perPage());
    }
}
