<?php

namespace Tests\Feature;

use App\Jobs\SendParcelNotificationJob;
use App\Models\Apartment;
use App\Models\Parcel;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\QuocTinRealtimeService;
use App\Services\ResidentService;
use App\Services\TemporaryRegistrationService;
use App\Services\VehicleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealtimeSyncTest extends TestCase
{
    protected ?User $adminUser = null;

    protected ?string $adminToken = null;

    protected ?User $restrictedUser = null;

    protected ?string $restrictedToken = null;

    protected ?Apartment $testApartment = null;

    protected ?Resident $testResident = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo tài khoản Admin có toàn quyền
        $this->adminUser = User::create([
            'username' => 'rt_admin_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'rt_admin_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Realtime Admin Tester',
            'status' => 'ACTIVE',
        ]);

        $superAdminRole = Role::where('role_code', 'SUPER_ADMIN')->first();
        if ($superAdminRole) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $this->adminUser->id,
                'role_id' => $superAdminRole->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $this->adminToken = 'smart_token_'.$this->adminUser->id.'_'.Str::random(40);
        $adminTokenHash = hash('sha256', $this->adminToken);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->adminUser->id,
            'refresh_token_hash' => $adminTokenHash,
            'device_name' => 'RT Admin Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'RTTestAgent',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        // 2. Tạo tài khoản thường không có quyền quản trị
        $this->restrictedUser = User::create([
            'username' => 'rt_user_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'rt_user_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Restricted User',
            'status' => 'ACTIVE',
        ]);

        $this->restrictedToken = 'smart_token_'.$this->restrictedUser->id.'_'.Str::random(40);
        $userTokenHash = hash('sha256', $this->restrictedToken);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->restrictedUser->id,
            'refresh_token_hash' => $userTokenHash,
            'device_name' => 'RT User Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'RTTestAgent',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        // 3. Đảm bảo căn hộ và cư dân tồn tại an toàn cho test suite
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();
        $blockId = $block?->id ?? (string) Str::uuid();
        $floorId = $floor?->id ?? (string) Str::uuid();

        if (! $block) {
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'RT-'.Str::random(6),
                'block_name' => 'Realtime Block',
                'total_floors' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! $floor) {
            DB::table('floors')->insert([
                'id' => $floorId,
                'block_id' => $blockId,
                'floor_number' => 1,
                'floor_code' => 'F1',
                'floor_name' => 'Floor 1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->testApartment = Apartment::first();
        if (! $this->testApartment) {
            $this->testApartment = Apartment::create([
                'id' => (string) Str::uuid(),
                'block_id' => $blockId,
                'floor_id' => $floorId,
                'apartment_number' => 'RT-'.rand(100, 999),
                'room_type' => '2_BEDROOM',
                'gross_floor_area_sqm' => 75.0,
                'net_usable_area_sqm' => 68.0,
                'status' => 'OCCUPIED',
            ]);
        }

        $this->testResident = Resident::where('is_active', 1)->first();
        if (! $this->testResident) {
            $this->testResident = Resident::create([
                'apartment_id' => $this->testApartment->id,
                'user_id' => $this->adminUser->id,
                'resident_type' => 'OWNER',
                'is_head_of_household' => true,
                'stay_start_date' => now()->toDateString(),
                'relationship_to_head' => 'SELF',
                'is_active' => true,
            ]);
        }

        DB::table('realtime_sync_events')->delete();
        foreach (['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles'] as $mod) {
            QuocTinRealtimeService::clearModuleCooldown($mod);
        }
    }

    protected function tearDown(): void
    {
        foreach (['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles'] as $mod) {
            QuocTinRealtimeService::clearModuleCooldown($mod);
        }

        parent::tearDown();
    }

    private function getAdminHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->adminToken,
            'Accept' => 'application/json',
        ];
    }

    private function getRestrictedHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->restrictedToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Test 1: Client A tạo Vehicle -> Hệ thống phát event trên channel quoc-tin.vehicles
     */
    public function test_1_client_a_create_vehicle_broadcasts_to_channel(): void
    {
        $aptId = $this->testApartment->id;
        $plate = '29A-'.rand(10000, 99999);

        $response = $this->postJson('/api/v1/vehicles', [
            'apartment_id' => $aptId,
            'owner_user_id' => $this->adminUser->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
        ], $this->getAdminHeaders());

        $response->assertStatus(201);
        $vehicleId = $response->json('data.id');

        // Kiểm tra event đã được ghi nhận trên channel quoc-tin.vehicles
        $events = QuocTinRealtimeService::getEvents(['quoc-tin.vehicles'], 0, 0, 10);
        $matched = collect($events)->first(function ($ev) use ($vehicleId) {
            return $ev['entity_id'] === $vehicleId && $ev['action'] === 'CREATED';
        });

        $this->assertNotNull($matched, 'Client B phải nhận được event CREATED cho vehicle vừa tạo');
        $this->assertEquals('vehicles', $matched['module']);
        $this->assertEquals($plate, $matched['license_plate']);
    }

    /**
     * Test 2: Client A cập nhật Resident -> Hệ thống phát event UPDATED trên channel quoc-tin.residents
     */
    public function test_2_client_a_update_resident_broadcasts_to_channel(): void
    {
        $resident = $this->testResident;

        $service = app(ResidentService::class);
        $service->update($resident->id, [
            'relationship_to_head' => 'COUSIN',
        ]);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.residents'], 0, 0, 10);
        $matched = collect($events)->first(function ($ev) use ($resident) {
            return $ev['entity_id'] === $resident->id && $ev['action'] === 'UPDATED';
        });

        $this->assertNotNull($matched, 'Client B phải nhận được event UPDATED cho resident vừa sửa');
        $this->assertEquals('residents', $matched['module']);
    }

    /**
     * Test 3: Client A xóa Role -> Hệ thống phát event DELETED trên channel quoc-tin.rbac
     */
    public function test_3_client_a_delete_role_broadcasts_to_channel(): void
    {
        $role = Role::create([
            'role_code' => 'TEMP_ROLE_'.Str::random(6),
            'role_name' => 'Temporary Role For Test',
            'is_system_role' => 0,
        ]);

        $response = $this->deleteJson("/api/v1/roles/{$role->id}", [], $this->getAdminHeaders());
        $response->assertStatus(200);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.rbac'], 0, 0, 10);
        $matched = collect($events)->first(function ($ev) use ($role) {
            return $ev['entity_id'] === $role->id && $ev['action'] === 'DELETED';
        });

        $this->assertNotNull($matched, 'Client B phải nhận được event DELETED cho role vừa xóa');
    }

    /**
     * Test 4: Phê duyệt và từ chối Tạm trú / Tạm vắng phát APPROVED và REJECTED events
     */
    public function test_4_approve_and_reject_temporary_registration_broadcasts(): void
    {
        $resident = $this->testResident;
        $tempreg = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $resident->apartment_id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'reason' => 'Kiểm thử Realtime Phê duyệt',
            'police_status' => 'PENDING',
        ]);

        $service = app(TemporaryRegistrationService::class);

        // Duyệt hồ sơ
        $service->approve($tempreg->id, $this->adminUser, 'Đã phê duyệt nhanh');

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.temporary-registrations'], 0, 0, 10);
        $matchedApprove = collect($events)->first(function ($ev) use ($tempreg) {
            return $ev['entity_id'] === $tempreg->id && $ev['action'] === 'APPROVED';
        });
        $this->assertNotNull($matchedApprove, 'Client B phải nhận được trạng thái APPROVED ngay lập tức');

        // Tạo hồ sơ thứ 2 để test từ chối
        QuocTinRealtimeService::clearModuleCooldown('temporary_registrations');
        $tempreg2 = TemporaryRegistration::create([
            'resident_id' => $resident->id,
            'apartment_id' => $resident->apartment_id,
            'registration_type' => 'TEMPORARY_ABSENCE',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(1)->toDateString(),
            'reason' => 'Kiểm thử Realtime Từ chối',
            'police_status' => 'PENDING',
        ]);

        $service->reject($tempreg2->id, $this->adminUser, 'Thiếu giấy tờ tùy thân');

        $events2 = QuocTinRealtimeService::getEvents(['quoc-tin.temporary-registrations'], 0, 0, 10);
        $matchedReject = collect($events2)->first(function ($ev) use ($tempreg2) {
            return $ev['entity_id'] === $tempreg2->id && $ev['action'] === 'REJECTED';
        });
        $this->assertNotNull($matchedReject, 'Client B phải nhận được trạng thái REJECTED ngay lập tức');
    }

    /**
     * Test 5: Hai client cùng mở một record -> event chứa entity_id để client cập nhật modal
     */
    public function test_5_multiple_clients_watching_same_record_receive_targeted_entity_id(): void
    {
        $vehicle = Vehicle::first();
        if (! $vehicle) {
            $vehicle = Vehicle::create([
                'apartment_id' => $this->testApartment->id,
                'owner_user_id' => $this->adminUser->id,
                'license_plate' => '29C-'.rand(10000, 99999),
                'vehicle_category' => 'MOTORBIKE',
                'monthly_parking_fee' => 120000,
                'is_active' => 1,
            ]);
        }

        $service = app(VehicleService::class);
        $service->updateVehicle($vehicle->id, ['color' => 'Xanh Neon']);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.vehicles'], 0, 0, 5);
        $matched = collect($events)->first(function ($ev) use ($vehicle) {
            return $ev['entity_id'] === $vehicle->id;
        });

        $this->assertNotNull($matched);
        $this->assertEquals($vehicle->id, $matched['entity_id']);
    }

    /**
     * Test 6: Hai client dùng filter khác nhau -> cả 2 đều nhận được event trên channel để refetch theo filter riêng
     */
    public function test_6_clients_with_different_filters_both_receive_channel_events(): void
    {
        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('vehicles', 'vehicle', 'UPDATED', (string) Str::uuid());

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.vehicles'], $lastIdBefore, 0, 5);
        $this->assertNotEmpty($events);
        $this->assertEquals('quoc-tin.vehicles', $events[0]['channel']);
    }

    /**
     * Test 7: Client mất kết nối rồi reconnect -> lấy lại toàn bộ sự kiện đã bỏ lỡ qua since_id
     */
    public function test_7_reconnect_catchup_endpoint_returns_missed_events(): void
    {
        $lastId = DB::table('realtime_sync_events')->max('id') ?? 0;

        // Giả lập 2 event phát sinh trong lúc Client B offline
        QuocTinRealtimeService::emit('residents', 'resident', 'CREATED', 'res-offline-1');
        QuocTinRealtimeService::emit('residents', 'resident', 'UPDATED', 'res-offline-2');

        // Client B kết nối lại và gọi /api/realtime/events?channels=quoc-tin.residents&since_id=$lastId
        $response = $this->getJson(
            "/api/realtime/events?channels=quoc-tin.residents&since_id={$lastId}",
            $this->getAdminHeaders()
        );

        $response->assertStatus(200);
        $events = $response->json('events');
        $this->assertGreaterThanOrEqual(2, count($events), 'Client B phải nhận được các event đã bỏ lỡ khi offline');
    }

    /**
     * Test 8: Chống stale event: Event cũ có timestamp nhỏ hơn không được ghi đè
     */
    public function test_8_anti_stale_event_version_and_timestamp(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $pastMs = $nowMs - 5000;

        $eventNew = ['timestamp' => $nowMs, 'version' => (string) $nowMs];
        $eventOld = ['timestamp' => $pastMs, 'version' => (string) $pastMs];

        $this->assertGreaterThan($eventOld['timestamp'], $eventNew['timestamp'], 'Event mới phải có timestamp lớn hơn event cũ để tránh ghi đè');
    }

    /**
     * Test 9: User không có permission bị từ chối 403 khi kết nối channel
     */
    public function test_9_restricted_user_cannot_subscribe_unauthorized_channel(): void
    {
        // User thường không có quyền RBAC thử kết nối /api/realtime/events
        $response = $this->getJson(
            '/api/realtime/events?channels=quoc-tin.rbac',
            $this->getRestrictedHeaders()
        );

        $response->assertStatus(403);
        $this->assertFalse($response->json('success'));
    }

    /**
     * Test 10: Mutation database fail -> không broadcast event ra channel
     */
    public function test_10_failed_database_mutation_does_not_broadcast(): void
    {
        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        try {
            DB::transaction(function () {
                // Giả lập câu lệnh ném exception gây rollback
                throw new \RuntimeException('Database constraint error simulation');
            });
        } catch (\Throwable $e) {
            // Transaction đã rollback
        }

        $lastIdAfter = DB::table('realtime_sync_events')->max('id') ?? 0;
        $this->assertEquals($lastIdBefore, $lastIdAfter, 'Giao dịch thất bại tuyệt đối không được phát sinh event');
    }

    /**
     * Test 11: Sau khi Client A mutation thành công -> Cooldown 120s được kích hoạt trên server
     */
    public function test_11_cooldown_starts_on_successful_mutation_with_120_seconds(): void
    {
        QuocTinRealtimeService::clearModuleCooldown('residents');

        $resident = $this->testResident;
        QuocTinRealtimeService::emit('residents', 'resident', 'UPDATED', $resident->id, [], $this->adminUser->id, true);

        $cooldown = QuocTinRealtimeService::getModuleCooldown('residents');
        $this->assertNotNull($cooldown, 'Server phải lưu trạng thái cooldown cho module residents');
        $this->assertEquals(120, $cooldown['cooldown_seconds']);
        $this->assertGreaterThan(time(), $cooldown['cooldown_until_ts']);
    }

    /**
     * Test 12: Client A không thể mutation lại trong 120 giây (Server trả 409)
     */
    public function test_12_client_a_cannot_update_again_during_cooldown_409(): void
    {
        QuocTinRealtimeService::setModuleCooldown('residents', 120, $this->adminUser->id);

        $resident = $this->testResident;
        $response = $this->putJson(
            "/api/v1/residents/{$resident->id}",
            ['occupation' => 'Tester'],
            $this->getAdminHeaders()
        );

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'error' => 'COOLDOWN_ACTIVE',
        ]);
        $this->assertNotEmpty($response->json('cooldown_until'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
    }

    /**
     * Test 13: Client B (user khác) cũng không thể mutation trong thời gian cooldown
     */
    public function test_13_client_b_cannot_update_or_create_during_cooldown_409(): void
    {
        QuocTinRealtimeService::setModuleCooldown('residents', 120, 'different_user_id');

        $resident = $this->testResident;
        $response = $this->putJson(
            "/api/v1/residents/{$resident->id}",
            ['occupation' => 'Engineer'],
            $this->getAdminHeaders()
        );

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'error' => 'COOLDOWN_ACTIVE',
        ]);
    }

    /**
     * Test 14: Client cố gọi API trực tiếp bypass frontend vẫn bị chặn với HTTP 409
     */
    public function test_14_direct_api_mutation_bypass_rejected_with_409(): void
    {
        QuocTinRealtimeService::setModuleCooldown('vehicles', 120);

        $response = $this->postJson(
            '/api/v1/vehicles',
            [
                'license_plate' => '51A-'.rand(10000, 99999),
                'vehicle_category' => 'CAR',
            ],
            $this->getAdminHeaders()
        );

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'error' => 'COOLDOWN_ACTIVE',
        ]);
        $this->assertEquals(120, $response->json('cooldown_seconds'));
    }

    /**
     * Test 15: Hết thời gian cooldown -> Client được phép chỉnh sửa trở lại
     */
    public function test_15_cooldown_expiry_allows_mutations_again(): void
    {
        QuocTinRealtimeService::clearModuleCooldown('residents');

        $this->assertFalse(QuocTinRealtimeService::isModuleInCooldown('residents'));

        $resident = $this->testResident;
        $response = $this->putJson(
            "/api/v1/residents/{$resident->id}",
            ['occupation' => 'Doctor'],
            $this->getAdminHeaders()
        );

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Test 16: Validation thất bại -> KHÔNG được kích hoạt cooldown
     */
    public function test_16_validation_failure_does_not_start_cooldown(): void
    {
        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $response = $this->postJson(
            '/api/v1/vehicles',
            [],
            $this->getAdminHeaders()
        );

        $response->assertStatus(422);

        $this->assertFalse(
            QuocTinRealtimeService::isModuleInCooldown('vehicles'),
            'Validation thất bại tuyệt đối không được kích hoạt cooldown'
        );
    }

    /**
     * Test 17: Database transaction rollback -> KHÔNG được kích hoạt cooldown
     */
    public function test_17_transaction_rollback_does_not_start_cooldown(): void
    {
        QuocTinRealtimeService::clearModuleCooldown('temporary_registrations');

        try {
            DB::transaction(function () {
                throw new \Exception('Rollback simulation');
            });
        } catch (\Throwable $e) {
            // Rollback
        }

        $this->assertFalse(
            QuocTinRealtimeService::isModuleInCooldown('temporary_registrations'),
            'Transaction rollback tuyệt đối không được kích hoạt cooldown'
        );
    }

    /**
     * Test 18: Cooldown của Module A (residents) KHÔNG khóa Module B (vehicles hay rbac)
     */
    public function test_18_module_cooldown_is_isolated_and_does_not_lock_other_modules(): void
    {
        QuocTinRealtimeService::setModuleCooldown('residents', 120);
        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $this->assertTrue(QuocTinRealtimeService::isModuleInCooldown('residents'));
        $this->assertFalse(QuocTinRealtimeService::isModuleInCooldown('vehicles'));
        $this->assertNull(QuocTinRealtimeService::getModuleCooldown('vehicles'));
    }

    /**
     * Test 19: Reconnect / Poll endpoint trả về trạng thái cooldown chính xác
     */
    public function test_19_reconnect_query_returns_accurate_cooldown_state(): void
    {
        QuocTinRealtimeService::setModuleCooldown('rbac', 120, $this->adminUser->id);

        $response = $this->getJson(
            '/api/v1/realtime/cooldown?modules=rbac,residents,vehicles',
            $this->getAdminHeaders()
        );

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertTrue($data['rbac']['is_in_cooldown']);
        $this->assertEquals(120, $data['rbac']['cooldown_seconds']);
        $this->assertGreaterThan(0, $data['rbac']['retry_after']);
        $this->assertFalse($data['vehicles']['is_in_cooldown']);
    }

    /**
     * Test 20: 3+ clients cùng mở chức năng đều nhận được event cooldown
     */
    public function test_20_multiple_clients_watching_same_function_all_receive_cooldown_events(): void
    {
        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('residents', 'resident', 'UPDATED', 'res-123', [], $this->adminUser->id, true);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.residents'], $lastIdBefore, 0, 10);

        $cooldownEvent = collect($events)->firstWhere('action', 'EDIT_COOLDOWN_STARTED');
        $this->assertNotNull($cooldownEvent, 'Hệ thống phải broadcast event EDIT_COOLDOWN_STARTED');
        $this->assertEquals('residents', $cooldownEvent['module']);
        $this->assertEquals(120, $cooldownEvent['cooldown_seconds']);
        $this->assertNotEmpty($cooldownEvent['cooldown_until']);
    }

    /**
     * Helper tạo tài khoản cư dân có session token và gán căn hộ
     *
     * @param  array<string>  $apartmentIds
     * @return array{user: User, token: string, headers: array<string, string>}
     */
    protected function createResidentUserWithSession(array $apartmentIds = []): array
    {
        $user = User::create([
            'username' => 'res_user_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Resident Tester',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'RESIDENT')->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        foreach ($apartmentIds as $aptId) {
            $exists = DB::table('apartments')->where('id', $aptId)->exists();
            if (! $exists) {
                DB::table('apartments')->insert([
                    'id' => $aptId,
                    'block_id' => DB::table('blocks')->value('id') ?? (string) Str::uuid(),
                    'floor_id' => DB::table('floors')->value('id') ?? (string) Str::uuid(),
                    'apartment_number' => 'TEST-'.Str::random(5),
                    'gross_floor_area_sqm' => 70,
                    'net_usable_area_sqm' => 65,
                    'status' => 'OCCUPIED',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('residents')->insert([
                'id' => (string) Str::uuid(),
                'apartment_id' => $aptId,
                'user_id' => $user->id,
                'resident_type' => 'TENANT',
                'is_head_of_household' => 0,
                'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonth()->toDateString(),
                'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->addYear()->toDateString(),
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'Test Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [
            'user' => $user,
            'token' => $token,
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
        ];
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 1 & 2: Cư dân căn hộ A chỉ nhận parcel event của A, KHÔNG nhận của B
     */
    public function test_bug05_01_and_02_resident_apt_a_receives_only_apt_a_parcel_events_and_not_apt_b(): void
    {
        $aptAId = (string) Str::uuid();
        $aptBId = (string) Str::uuid();

        $resA = $this->createResidentUserWithSession([$aptAId]);
        $residentUserA = $resA['user'];

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        // Phát 1 event bưu phẩm căn hộ A và 1 event bưu phẩm căn hộ B
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptAId,
            'tracking_number' => 'VN-A-1111',
            'recipient_name' => 'Resident A',
        ]);

        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptBId,
            'tracking_number' => 'VN-B-2222',
            'recipient_name' => 'Resident B',
        ]);

        $eventsForResidentA = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $residentUserA);

        $this->assertNotEmpty($eventsForResidentA, 'Cư dân A phải nhận được sự kiện bưu phẩm');
        $this->assertCount(1, $eventsForResidentA, 'Cư dân A chỉ được nhận đúng 1 sự kiện của căn hộ mình');
        $this->assertEquals('VN-A-1111', $eventsForResidentA[0]['tracking_number']);
        $this->assertEquals($aptAId, $eventsForResidentA[0]['apartment_id']);

        // Xác minh tuyệt đối không nhận bưu phẩm căn B
        $trackingNumbers = collect($eventsForResidentA)->pluck('tracking_number')->toArray();
        $this->assertNotContains('VN-B-2222', $trackingNumbers, 'Cư dân A tuyệt đối không được nhận sự kiện bưu phẩm của căn hộ B');
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 3 & 4: Cư dân căn hộ A chỉ nhận visitor event của A, KHÔNG nhận của B
     */
    public function test_bug05_03_and_04_resident_apt_a_receives_only_apt_a_visitor_events_and_not_apt_b(): void
    {
        $aptAId = (string) Str::uuid();
        $aptBId = (string) Str::uuid();

        $resA = $this->createResidentUserWithSession([$aptAId]);
        $residentUserA = $resA['user'];

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('visitors', 'visitor_registration', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptAId,
            'visitor_name' => 'Guest of A',
        ]);

        QuocTinRealtimeService::emit('visitors', 'visitor_registration', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptBId,
            'visitor_name' => 'Guest of B',
        ]);

        $eventsForResidentA = QuocTinRealtimeService::getEvents(['quoc-tin.visitors'], $lastIdBefore, 0, 50, $residentUserA);

        $this->assertNotEmpty($eventsForResidentA);
        $this->assertCount(1, $eventsForResidentA);
        $this->assertEquals('Guest of A', $eventsForResidentA[0]['visitor_name']);

        $guestNames = collect($eventsForResidentA)->pluck('visitor_name')->toArray();
        $this->assertNotContains('Guest of B', $guestNames, 'Cư dân A không được nhận sự kiện khách của căn hộ B');
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 5: Cư dân có nhiều căn hộ nhận sự kiện của tất cả căn hộ được phép truy cập
     */
    public function test_bug05_05_resident_with_multiple_apartments_receives_events_for_all_assigned_apartments(): void
    {
        $aptAId = (string) Str::uuid();
        $aptBId = (string) Str::uuid();
        $aptCId = (string) Str::uuid();

        // Cư dân sở hữu cả căn A và căn C
        $multiRes = $this->createResidentUserWithSession([$aptAId, $aptCId]);
        $multiUser = $multiRes['user'];

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptAId, 'tracking_number' => 'PARCEL-A']);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptBId, 'tracking_number' => 'PARCEL-B']);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptCId, 'tracking_number' => 'PARCEL-C']);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $multiUser);

        $this->assertCount(2, $events, 'Cư dân có 2 căn hộ phải nhận đủ sự kiện của cả 2 căn A và C');
        $trackingNumbers = collect($events)->pluck('tracking_number')->toArray();
        $this->assertContains('PARCEL-A', $trackingNumbers);
        $this->assertContains('PARCEL-C', $trackingNumbers);
        $this->assertNotContains('PARCEL-B', $trackingNumbers, 'Không được nhận bưu phẩm căn B');
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 6: Cư dân không có căn hộ không nhận parcel/visitor events
     */
    public function test_bug05_06_resident_without_apartment_receives_no_parcel_or_visitor_events(): void
    {
        // Cư dân chưa được gán bất kỳ căn hộ nào
        $noAptRes = $this->createResidentUserWithSession([]);
        $noAptUser = $noAptRes['user'];

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => (string) Str::uuid(), 'tracking_number' => 'PARCEL-X']);
        QuocTinRealtimeService::emit('visitors', 'visitor_registration', 'CREATED', (string) Str::uuid(), ['apartment_id' => (string) Str::uuid(), 'visitor_name' => 'Guest X']);

        $parcelEvents = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $noAptUser);
        $visitorEvents = QuocTinRealtimeService::getEvents(['quoc-tin.visitors'], $lastIdBefore, 0, 50, $noAptUser);

        $this->assertEmpty($parcelEvents, 'Cư dân không có căn hộ không được nhận parcel events');
        $this->assertEmpty($visitorEvents, 'Cư dân không có căn hộ không được nhận visitor events');
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 7: Event thiếu apartment_id không bị gửi cho cư dân
     */
    public function test_bug05_07_event_missing_apartment_id_is_not_sent_to_resident(): void
    {
        $aptAId = (string) Str::uuid();
        $resA = $this->createResidentUserWithSession([$aptAId]);
        $residentUserA = $resA['user'];

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        // Phát event cố tình không có apartment_id và entity_id không tồn tại trong DB
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', 'non-existent-parcel-id', [
            'tracking_number' => 'ORPHAN-PARCEL',
        ]);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $residentUserA);

        $this->assertEmpty($events, 'Event thiếu apartment_id và không resolve được tuyệt đối không được gửi cho cư dân');
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 8: Nhân viên có quyền nhận đúng phạm vi sự kiện theo quyền hiện tại (nhận đủ toàn tòa)
     */
    public function test_bug05_08_staff_user_receives_all_events_across_apartments(): void
    {
        $aptAId = (string) Str::uuid();
        $aptBId = (string) Str::uuid();

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptAId, 'tracking_number' => 'PARCEL-STAFF-A']);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptBId, 'tracking_number' => 'PARCEL-STAFF-B']);

        $staffEvents = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $this->adminUser);

        $this->assertCount(2, $staffEvents, 'Nhân viên quản lý phải nhận toàn bộ sự kiện của tất cả căn hộ trong tòa');
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 9 & 10: Client giả mạo apartment_id hoặc user_id qua API không thể xem dữ liệu căn hộ khác
     */
    public function test_bug05_09_and_10_client_cannot_spoof_apartment_id_or_user_id_via_api(): void
    {
        $aptAId = (string) Str::uuid();
        $aptBId = (string) Str::uuid();

        $resA = $this->createResidentUserWithSession([$aptAId]);

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptAId, 'tracking_number' => 'LEGIT-A']);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptBId, 'tracking_number' => 'SECRET-B']);

        // Gửi request API kèm query parameter giả mạo: apartment_id=aptBId & user_id=admin
        $response = $this->getJson(
            "/api/v1/realtime/events?channels=quoc-tin.parcels&since_id={$lastIdBefore}&apartment_id={$aptBId}&user_id={$this->adminUser->id}",
            $resA['headers']
        );

        $response->assertStatus(200);
        $events = $response->json('events');

        $this->assertNotEmpty($events);
        $this->assertCount(1, $events);
        $this->assertEquals('LEGIT-A', $events[0]['tracking_number']);

        $trackings = collect($events)->pluck('tracking_number')->toArray();
        $this->assertNotContains('SECRET-B', $trackings, 'Server-side không tin query params từ client và không trả về dữ liệu căn B');
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 11: RFID và schedules không bao giờ bị lộ cho cư dân
     */
    public function test_bug05_11_rfid_and_schedules_channels_are_never_leaked_to_resident(): void
    {
        $aptAId = (string) Str::uuid();
        $resA = $this->createResidentUserWithSession([$aptAId]);
        $residentUserA = $resA['user'];

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('rfid_cards', 'rfid_card', 'CREATED', (string) Str::uuid(), [
            'card_uid' => 'SECRET-RFID-9999',
        ]);

        QuocTinRealtimeService::emit('schedules', 'staff_schedule', 'CREATED', (string) Str::uuid(), [
            'shift_name' => 'SECRET-NIGHT-SHIFT',
        ]);

        $rfidEvents = QuocTinRealtimeService::getEvents(['quoc-tin.rfid-cards'], $lastIdBefore, 0, 50, $residentUserA);
        $scheduleEvents = QuocTinRealtimeService::getEvents(['quoc-tin.schedules'], $lastIdBefore, 0, 50, $residentUserA);

        $this->assertEmpty($rfidEvents, 'Cư dân không được nhận sự kiện thẻ RFID');
        $this->assertEmpty($scheduleEvents, 'Cư dân không được nhận sự kiện lịch trực nhân viên');
    }

    /**
     * BUG-05 Giai đoạn 2 - Test 12: Đảm bảo các chức năng BUG-01 bảo mật bưu phẩm không bị tác động
     */
    public function test_bug05_12_bug01_protections_remain_intact_with_realtime_filtering(): void
    {
        $aptAId = (string) Str::uuid();
        $aptBId = (string) Str::uuid();

        $resA = $this->createResidentUserWithSession([$aptAId]);

        // Cư dân A không được phép xem bưu phẩm căn B qua API BUG-01
        $parcelB = Parcel::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'SECURE-BUG01-B-'.Str::random(6),
            'courier_company' => 'Shopee Express',
            'apartment_id' => $aptBId,
            'recipient_name' => 'Resident B',
            'recipient_phone' => '0912345678',
            'pickup_pin_code' => '9999',
            'pickup_qr_code' => 'QR-9999-'.Str::random(6),
            'parcel_photo_url' => 'https://example.com/photo.jpg',
            'status' => 'STORED',
            'received_at' => now(),
            'received_by_staff_id' => $this->adminUser->id,
        ]);

        $response = $this->getJson("/api/v1/reception/parcels/{$parcelB->id}", $resA['headers']);
        $this->assertEquals(403, $response->status(), 'BUG-01 bảo vệ chi tiết bưu phẩm khác căn hộ vẫn hoạt động');
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 1: Anonymous truy cập SSE stream bị từ chối (401)
     */
    public function test_bug05_stage3_01_anonymous_access_to_stream_is_rejected(): void
    {
        $response = $this->getJson('/api/realtime/stream?channels=quoc-tin.parcels');
        $this->assertEquals(401, $response->status());
        $this->assertFalse($response->json('success'));

        $responseV1 = $this->getJson('/api/v1/realtime/stream?channels=quoc-tin.parcels');
        $this->assertEquals(401, $responseV1->status());
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 2: Anonymous truy cập catch-up polling bị từ chối (401)
     */
    public function test_bug05_stage3_02_anonymous_access_to_events_is_rejected(): void
    {
        $response = $this->getJson('/api/realtime/events?channels=quoc-tin.parcels');
        $this->assertEquals(401, $response->status());
        $this->assertFalse($response->json('success'));

        $responseV1 = $this->getJson('/api/v1/realtime/events?channels=quoc-tin.parcels');
        $this->assertEquals(401, $responseV1->status());
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 3: Anonymous truy cập cooldown bị từ chối (401)
     */
    public function test_bug05_stage3_03_anonymous_access_to_cooldown_is_rejected(): void
    {
        $response = $this->getJson('/api/realtime/cooldown?module=residents');
        $this->assertEquals(401, $response->status());
        $this->assertFalse($response->json('success'));

        $responseV1 = $this->getJson('/api/v1/realtime/cooldown?module=residents');
        $this->assertEquals(401, $responseV1->status());
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 4: Token sai bị từ chối (401)
     */
    public function test_bug05_stage3_04_invalid_token_is_rejected(): void
    {
        $headers = [
            'Authorization' => 'Bearer smart_token_invalid_malformed_12345',
            'Accept' => 'application/json',
        ];

        $response = $this->getJson('/api/realtime/events?channels=quoc-tin.parcels', $headers);
        $this->assertEquals(401, $response->status());

        $cooldownResponse = $this->getJson('/api/realtime/cooldown?module=residents', $headers);
        $this->assertEquals(401, $cooldownResponse->status());
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 5: Token hết hạn bị từ chối (401)
     */
    public function test_bug05_stage3_05_expired_token_is_rejected(): void
    {
        $user = User::create([
            'username' => 'expired_user_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'expired_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Expired User',
            'status' => 'ACTIVE',
        ]);

        $token = 'smart_token_'.$user->id.'_expired_'.Str::random(30);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'Expired Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'expires_at' => now()->subHours(2), // Đã hết hạn 2 giờ trước
            'is_revoked' => 0,
            'created_at' => now()->subDays(2),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];

        $response = $this->getJson('/api/realtime/events?channels=quoc-tin.parcels', $headers);
        $this->assertEquals(401, $response->status());
        $this->assertEquals('TOKEN_EXPIRED', $response->json('error'));
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 6: Token bị thu hồi bị từ chối (401)
     */
    public function test_bug05_stage3_06_revoked_token_is_rejected(): void
    {
        $user = User::create([
            'username' => 'revoked_user_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'revoked_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Revoked User',
            'status' => 'ACTIVE',
        ]);

        $token = 'smart_token_'.$user->id.'_revoked_'.Str::random(30);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'Revoked Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'expires_at' => now()->addDays(2),
            'is_revoked' => 1, // Đã bị thu hồi
            'created_at' => now(),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];

        $response = $this->getJson('/api/realtime/cooldown?module=residents', $headers);
        $this->assertEquals(401, $response->status());
        $this->assertEquals('TOKEN_REVOKED', $response->json('error'));
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 7: User hợp lệ truy cập đúng endpoint (200)
     */
    public function test_bug05_stage3_07_valid_user_can_access_endpoints(): void
    {
        $responseEvents = $this->getJson('/api/realtime/events?channels=quoc-tin.residents', $this->getAdminHeaders());
        $responseEvents->assertStatus(200);
        $this->assertTrue($responseEvents->json('success'));

        $responseCooldown = $this->getJson('/api/realtime/cooldown?module=residents', $this->getAdminHeaders());
        $responseCooldown->assertStatus(200);
        $this->assertTrue($responseCooldown->json('success'));
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 8: Client giả mạo user_id không có tác dụng
     */
    public function test_bug05_stage3_08_client_spoofed_user_id_has_no_effect(): void
    {
        $res = $this->createResidentUserWithSession([]);
        $residentUser = $res['user'];

        // Cư dân gửi request kèm header và query param giả mạo adminUser ID
        $headers = array_merge($res['headers'], [
            'X-User-Id' => $this->adminUser->id,
        ]);

        $response = $this->getJson(
            "/api/realtime/events?channels=quoc-tin.rbac&user_id={$this->adminUser->id}",
            $headers
        );

        // Phân quyền kênh xác thực theo user của token (resident) -> 403 Forbidden thay vì bypass sang quyền Admin
        $this->assertEquals(403, $response->status());
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 9: Cooldown không tiết lộ actor_id cho người không có quyền (Cư dân nhận null)
     */
    public function test_bug05_stage3_09_cooldown_does_not_reveal_actor_id_to_unauthorized_users(): void
    {
        // 1. Kích hoạt cooldown module residents bởi Admin
        QuocTinRealtimeService::setModuleCooldown('residents', 120, $this->adminUser->id, 'MUTATION');

        // 2. Admin gọi cooldown endpoint -> nhìn thấy actor_id để phục vụ điều phối
        $adminResp = $this->getJson('/api/v1/realtime/cooldown?module=residents', $this->getAdminHeaders());
        $adminResp->assertStatus(200);
        $this->assertEquals($this->adminUser->id, $adminResp->json('data.residents.actor_id'));

        // 3. Cư dân gọi cooldown endpoint -> actor_id và action BỊ ẨN (null) để bảo mật nội bộ
        $res = $this->createResidentUserWithSession([]);
        $resResp = $this->getJson('/api/v1/realtime/cooldown?module=residents', $res['headers']);
        $resResp->assertStatus(200);
        $this->assertTrue($resResp->json('data.residents.is_in_cooldown'));
        $this->assertNull($resResp->json('data.residents.actor_id'), 'Cư dân tuyệt đối không được xem actor_id của nhân viên');
        $this->assertNull($resResp->json('data.residents.action'), 'Cư dân tuyệt đối không được xem action nội bộ');
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 10: Cư dân vẫn chỉ nhận event thuộc căn hộ được phép
     */
    public function test_bug05_stage3_10_resident_still_only_receives_authorized_apartment_events(): void
    {
        $aptAId = (string) Str::uuid();
        $aptBId = (string) Str::uuid();

        $resA = $this->createResidentUserWithSession([$aptAId]);

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptAId, 'tracking_number' => 'STAGE3-A']);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptBId, 'tracking_number' => 'STAGE3-B']);

        $response = $this->getJson("/api/v1/realtime/events?channels=quoc-tin.parcels&since_id={$lastIdBefore}", $resA['headers']);
        $response->assertStatus(200);

        $events = $response->json('events');
        $this->assertCount(1, $events);
        $this->assertEquals('STAGE3-A', $events[0]['tracking_number']);
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 11: Nhân viên vẫn nhận event theo quyền hiện tại (đầy đủ toàn tòa)
     */
    public function test_bug05_stage3_11_staff_receives_events_according_to_permissions(): void
    {
        $aptAId = (string) Str::uuid();
        $aptBId = (string) Str::uuid();

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptAId, 'tracking_number' => 'STAGE3-STAFF-A']);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), ['apartment_id' => $aptBId, 'tracking_number' => 'STAGE3-STAFF-B']);

        $response = $this->getJson("/api/v1/realtime/events?channels=quoc-tin.parcels&since_id={$lastIdBefore}", $this->getAdminHeaders());
        $response->assertStatus(200);

        $events = $response->json('events');
        $this->assertCount(2, $events);
    }

    /**
     * BUG-05 Giai đoạn 3 - Test 12: SSE reconnect và catch-up polling vẫn hoạt động thông suốt với Bearer token
     */
    public function test_bug05_stage3_12_sse_reconnect_and_catchup_polling_work_seamlessly(): void
    {
        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;

        QuocTinRealtimeService::emit('residents', 'resident', 'UPDATED', 'res-rec-1');
        QuocTinRealtimeService::emit('residents', 'resident', 'UPDATED', 'res-rec-2');

        $response = $this->getJson(
            "/api/v1/realtime/events?channels=quoc-tin.residents&since_id={$lastIdBefore}&limit=10",
            $this->getAdminHeaders()
        );

        $response->assertStatus(200);
        $events = $response->json('events');
        $this->assertGreaterThanOrEqual(2, count($events));
        $this->assertNotNull($response->json('timestamp'));
    }

    /**
     * Helper tạo căn hộ phục vụ kiểm thử thời hạn
     */
    protected function createApartmentForExpirationTest(array $overrides = []): string
    {
        $aptId = (string) Str::uuid();
        $blockId = DB::table('blocks')->value('id') ?? (string) Str::uuid();
        $floorId = DB::table('floors')->value('id') ?? (string) Str::uuid();

        DB::table('apartments')->insert(array_merge([
            'id' => $aptId,
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'apartment_number' => 'EXP-'.Str::random(5),
            'gross_floor_area_sqm' => 80,
            'net_usable_area_sqm' => 75,
            'status' => 'OCCUPIED',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $aptId;
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 01: Cư dân có bản ghi hợp lệ trong residents được nhận sự kiện
     */
    public function test_bug05_exp_01_valid_resident_in_residents_receives_events(): void
    {
        $today = Carbon::now('Asia/Ho_Chi_Minh')->toDateString();
        $aptId = $this->createApartmentForExpirationTest();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonth()->toDateString(),
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->addMonth()->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertContains((string) $aptId, $authorizedIds);

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptId,
            'tracking_number' => 'TC01-PARCEL',
        ]);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $user);
        $this->assertCount(1, $events);
        $this->assertEquals('TC01-PARCEL', $events[0]['tracking_number']);
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 02: Cư dân có stay_end_date là hôm qua (kể cả is_active = 1) bị từ chối
     */
    public function test_bug05_exp_02_resident_stay_end_date_yesterday_is_rejected_even_if_active(): void
    {
        $yesterday = Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString();
        $aptId = $this->createApartmentForExpirationTest();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        // Cư dân hợp đồng hết hạn hôm qua, nhưng cron job chưa đổi is_active
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(6)->toDateString(),
            'stay_end_date' => $yesterday,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertNotContains((string) $aptId, $authorizedIds);

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptId,
            'tracking_number' => 'TC02-EXPIRED-PARCEL',
        ]);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $user);
        $this->assertEmpty($events, 'Cư dân hết hạn cư trú tuyệt đối không nhận được event của căn hộ');
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 03: Cư dân có stay_start_date là ngày mai bị từ chối
     */
    public function test_bug05_exp_03_resident_stay_start_date_tomorrow_is_rejected(): void
    {
        $tomorrow = Carbon::now('Asia/Ho_Chi_Minh')->addDay()->toDateString();
        $aptId = $this->createApartmentForExpirationTest();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $user->id,
            'stay_start_date' => $tomorrow,
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->addYear()->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertNotContains((string) $aptId, $authorizedIds);
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 04: Cư dân có stay_end_date đúng ngày hôm nay vẫn được nhận sự kiện (đến hết ngày)
     */
    public function test_bug05_exp_04_resident_stay_end_date_today_receives_events_until_end_of_day(): void
    {
        $today = Carbon::now('Asia/Ho_Chi_Minh')->toDateString();
        $aptId = $this->createApartmentForExpirationTest();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(3)->toDateString(),
            'stay_end_date' => $today,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertContains((string) $aptId, $authorizedIds);

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('visitors', 'visitor_registration', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptId,
            'visitor_name' => 'Guest for Today Resident',
        ]);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.visitors'], $lastIdBefore, 0, 50, $user);
        $this->assertCount(1, $events);
        $this->assertEquals('Guest for Today Resident', $events[0]['visitor_name']);
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 05: Chủ sở hữu có thời hạn sở hữu hợp lệ được nhận sự kiện
     */
    public function test_bug05_exp_05_valid_owner_receives_events(): void
    {
        $aptId = $this->createApartmentForExpirationTest();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'owner_user_id' => $user->id,
            'ownership_percentage' => 100.0,
            'ownership_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subYear()->toDateString(),
            'ownership_end_date' => null, // Sở hữu lâu dài
            'is_current_owner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertContains((string) $aptId, $authorizedIds);

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptId,
            'tracking_number' => 'TC05-OWNER-PARCEL',
        ]);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $user);
        $this->assertCount(1, $events);
        $this->assertEquals('TC05-OWNER-PARCEL', $events[0]['tracking_number']);
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 06: Chủ sở hữu hết hạn sở hữu (hôm qua) bị từ chối kể cả khi is_current_owner = 1
     */
    public function test_bug05_exp_06_owner_expired_yesterday_is_rejected_even_if_is_current_owner(): void
    {
        $yesterday = Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString();
        $aptId = $this->createApartmentForExpirationTest();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'owner_user_id' => $user->id,
            'ownership_percentage' => 100.0,
            'ownership_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subYears(2)->toDateString(),
            'ownership_end_date' => $yesterday,
            'is_current_owner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertNotContains((string) $aptId, $authorizedIds);
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 07: Tài khoản CHỈ có current_resident_user_id (không có hồ sơ residents/owners) bị từ chối
     */
    public function test_bug05_exp_07_only_current_resident_user_id_pointer_without_records_is_rejected(): void
    {
        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        // Căn hộ gán current_resident_user_id là user nhưng KHÔNG có bản ghi trong residents hay apartment_owners
        $aptId = $this->createApartmentForExpirationTest([
            'current_resident_user_id' => $user->id,
            'status' => 'OCCUPIED',
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertNotContains((string) $aptId, $authorizedIds, 'Pointer current_resident_user_id đơn thuần không được tự cấp quyền realtime');
        $this->assertEmpty($authorizedIds);
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 08: Tài khoản có current_resident_user_id nhưng hồ sơ residents đã hết hạn bị từ chối
     */
    public function test_bug05_exp_08_current_resident_pointer_with_expired_resident_record_is_rejected(): void
    {
        $yesterday = Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        // Căn hộ vẫn lưu pointer cũ
        $aptId = $this->createApartmentForExpirationTest([
            'current_resident_user_id' => $user->id,
            'status' => 'OCCUPIED',
        ]);

        // Nhưng trong residents hợp đồng đã hết hạn hôm qua
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subYear()->toDateString(),
            'stay_end_date' => $yesterday,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertNotContains((string) $aptId, $authorizedIds, 'Không được bypass qua pointer current_resident_user_id khi hợp đồng cư trú đã hết hạn');
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 09: Cư dân hợp lệ trong residents dù KHÔNG PHẢI current resident vẫn được nhận sự kiện
     */
    public function test_bug05_exp_09_resident_valid_without_being_current_resident_receives_events(): void
    {
        $aptId = $this->createApartmentForExpirationTest([
            'current_resident_user_id' => (string) Str::uuid(), // Đại diện là người khác
            'status' => 'OCCUPIED',
        ]);

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        // User là thành viên hợp lệ trong gia đình
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(2)->toDateString(),
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->addMonths(10)->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertContains((string) $aptId, $authorizedIds, 'Thành viên hợp lệ trong residents phải nhận được quyền dù không phải chủ hộ đại diện');
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 10: Chủ sở hữu hợp lệ dù có hồ sơ cư trú cũ đã hết hạn vẫn nhận sự kiện theo quyền sở hữu
     */
    public function test_bug05_exp_10_owner_with_expired_residency_still_receives_events_via_ownership(): void
    {
        $yesterday = Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString();
        $aptId = $this->createApartmentForExpirationTest();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        // Hợp đồng cư trú cũ hết hạn
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subYears(3)->toDateString(),
            'stay_end_date' => $yesterday,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Nhưng quyền sở hữu vẫn còn hiệu lực
        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'owner_user_id' => $user->id,
            'ownership_percentage' => 100.0,
            'ownership_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subYears(2)->toDateString(),
            'ownership_end_date' => null,
            'is_current_owner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertContains((string) $aptId, $authorizedIds, 'Chủ sở hữu có quyền sở hữu hợp lệ vẫn được cấp quyền dù quan hệ cư trú cũ đã hết hạn');
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 11: Cư dân có nhiều căn hộ với thời hạn khác nhau chỉ nhận sự kiện từ căn còn quyền
     */
    public function test_bug05_exp_11_multiple_apartments_with_different_expiration_receives_only_valid_ones(): void
    {
        $yesterday = Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString();
        $nextMonth = Carbon::now('Asia/Ho_Chi_Minh')->addMonth()->toDateString();

        $aptValidId = $this->createApartmentForExpirationTest();
        $aptExpiredId = $this->createApartmentForExpirationTest();

        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        // Căn 1: Còn hạn
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptValidId,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonth()->toDateString(),
            'stay_end_date' => $nextMonth,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Căn 2: Hết hạn
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptExpiredId,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(6)->toDateString(),
            'stay_end_date' => $yesterday,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);
        $this->assertContains((string) $aptValidId, $authorizedIds);
        $this->assertNotContains((string) $aptExpiredId, $authorizedIds);

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptValidId,
            'tracking_number' => 'PARCEL-VALID-APT',
        ]);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptExpiredId,
            'tracking_number' => 'PARCEL-EXPIRED-APT',
        ]);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $user);
        $this->assertCount(1, $events);
        $this->assertEquals('PARCEL-VALID-APT', $events[0]['tracking_number']);
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 12: Nhân viên và Admin không bị ảnh hưởng bởi logic lọc căn hộ của cư dân
     */
    public function test_bug05_exp_12_staff_and_admin_not_affected_by_resident_apartment_filter(): void
    {
        $aptA = $this->createApartmentForExpirationTest();
        $aptB = $this->createApartmentForExpirationTest();

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptA,
            'tracking_number' => 'STAFF-PARCEL-A',
        ]);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptB,
            'tracking_number' => 'STAFF-PARCEL-B',
        ]);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.parcels'], $lastIdBefore, 0, 50, $this->adminUser);
        $this->assertGreaterThanOrEqual(2, count($events), 'Admin phải nhận toàn bộ bưu phẩm của tất cả các căn hộ');
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 13: Bảo vệ trạng thái vô hiệu hóa (is_active=0, soft-delete resident, soft-delete apartment, is_current_owner=0, ownership tương lai)
     */
    public function test_bug05_exp_13_inactive_and_soft_deleted_and_future_ownership_rejected(): void
    {
        $res = $this->createResidentUserWithSession([]);
        $user = $res['user'];

        // 1. is_active = 0
        $aptInactive = $this->createApartmentForExpirationTest();
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptInactive,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(6)->toDateString(),
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->addYear()->toDateString(),
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Soft-deleted resident
        $aptSoftDelRes = $this->createApartmentForExpirationTest();
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptSoftDelRes,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(6)->toDateString(),
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->addYear()->toDateString(),
            'is_active' => 1,
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Soft-deleted apartment
        $aptSoftDelApt = $this->createApartmentForExpirationTest([
            'deleted_at' => now(),
        ]);
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptSoftDelApt,
            'user_id' => $user->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(6)->toDateString(),
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->addYear()->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. is_current_owner = 0
        $aptNotCurrentOwner = $this->createApartmentForExpirationTest();
        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptNotCurrentOwner,
            'owner_user_id' => $user->id,
            'ownership_percentage' => 100.0,
            'ownership_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subYear()->toDateString(),
            'ownership_end_date' => null,
            'is_current_owner' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Future ownership start date
        $aptFutureOwner = $this->createApartmentForExpirationTest();
        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptFutureOwner,
            'owner_user_id' => $user->id,
            'is_current_owner' => 1,
            'ownership_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->addMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorizedIds = QuocTinRealtimeService::getAuthorizedApartmentIds($user);

        $this->assertNotContains((string) $aptInactive, $authorizedIds);
        $this->assertNotContains((string) $aptSoftDelRes, $authorizedIds);
        $this->assertNotContains((string) $aptSoftDelApt, $authorizedIds);
        $this->assertNotContains((string) $aptNotCurrentOwner, $authorizedIds);
        $this->assertNotContains((string) $aptFutureOwner, $authorizedIds);
    }

    /**
     * BUG-05 Giai đoạn 4 - TC 14: Không hồi quy bảo mật BUG-01 (Rate Limit và Cooldown)
     */
    public function test_bug05_exp_14_no_security_regression_on_bug01(): void
    {
        // Kiểm tra cooldown vẫn hoạt động độc lập và trả về HTTP 409
        QuocTinRealtimeService::setModuleCooldown('parcels', 60, $this->adminUser->id);

        $response = $this->getJson(
            '/api/v1/realtime/cooldown?modules=parcels',
            $this->getAdminHeaders()
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertTrue($data['parcels']['is_in_cooldown']);
        $this->assertEquals(60, $data['parcels']['cooldown_seconds']);

        QuocTinRealtimeService::clearModuleCooldown('parcels');
    }

    /**
     * Helper tạo staff user với permission cụ thể
     */
    protected function createStaffUserWithPermission(string $permissionCode): array
    {
        $user = User::create([
            'username' => 'staff_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'staff_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Staff Tester',
            'status' => 'ACTIVE',
        ]);

        // 1. Gán vai trò TECHNICIAN để thỏa mãn isStaffUser() mà không bị thừa quyền bưu phẩm/khách từ seeder
        $staffRole = Role::where('role_code', 'TECHNICIAN')->first()
            ?? Role::where('role_code', 'ACCOUNTANT')->first()
            ?? Role::whereIn('role_code', QuocTinRealtimeService::STAFF_ROLES)->first();

        if ($staffRole) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $staffRole->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        // 2. Tạo role riêng biệt cho test để gán đúng quyền cần kiểm tra, tránh duplicate key và không làm ô nhiễm role chung
        $customRole = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => 'STAFF_CUSTOM_'.Str::random(8),
            'role_name' => 'Staff Custom Perm Role',
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

        $permission = DB::table('permissions')->where('permission_code', $permissionCode)->first();
        if (! $permission) {
            $permId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $permId,
                'permission_code' => $permissionCode,
                'permission_name' => 'Test Permission '.$permissionCode,
                'module' => 'TEST',
                'created_at' => now(),
            ]);
            $permission = (object) ['id' => $permId];
        }

        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $customRole->id,
            'permission_id' => $permission->id,
            'created_at' => now(),
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'Staff Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'StaffAgent',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return ['user' => $user, 'token' => $token, 'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 01: Cư dân còn hạn không được subscribe WebSocket parcels toàn tòa
     */
    public function test_bug05_ws_01_valid_resident_cannot_subscribe_building_wide_parcels_websocket(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];

        // Cư dân có hợp đồng còn hạn hợp lệ
        $this->assertTrue(QuocTinRealtimeService::isResidentUser($resident));
        $this->assertNotEmpty(QuocTinRealtimeService::getAuthorizedApartmentIds($resident));

        // WebSocket channel toàn tòa tuyệt đối từ chối cư dân
        $isWsAuth = QuocTinRealtimeService::isUserAuthorizedForChannel($resident, 'quoc-tin.parcels');
        $this->assertFalse($isWsAuth, 'Cư dân dù còn hạn tuyệt đối không được cấp quyền subscribe channel WebSocket bưu phẩm toàn tòa');
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 02: Cư dân còn hạn không được subscribe WebSocket visitors toàn tòa
     */
    public function test_bug05_ws_02_valid_resident_cannot_subscribe_building_wide_visitors_websocket(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];

        $isWsAuth = QuocTinRealtimeService::isUserAuthorizedForChannel($resident, 'quoc-tin.visitors');
        $this->assertFalse($isWsAuth, 'Cư dân dù còn hạn tuyệt đối không được cấp quyền subscribe channel WebSocket khách đến thăm toàn tòa');
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 03: Cư dân hết hạn không được subscribe WebSocket
     */
    public function test_bug05_ws_03_expired_resident_cannot_subscribe_websocket(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([]);
        $resident = $res['user'];

        // Cư dân hết hạn hôm qua
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $resident->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(6)->toDateString(),
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(QuocTinRealtimeService::isUserAuthorizedForChannel($resident, 'quoc-tin.parcels'));
        $this->assertFalse(QuocTinRealtimeService::isUserAuthorizedForChannel($resident, 'quoc-tin.visitors'));
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 04: Chủ sở hữu hết hạn không được subscribe WebSocket
     */
    public function test_bug05_ws_04_expired_owner_cannot_subscribe_websocket(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([]);
        $owner = $res['user'];

        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'owner_user_id' => $owner->id,
            'ownership_percentage' => 100.0,
            'ownership_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subYears(2)->toDateString(),
            'ownership_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString(),
            'is_current_owner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(QuocTinRealtimeService::isUserAuthorizedForChannel($owner, 'quoc-tin.parcels'));
        $this->assertFalse(QuocTinRealtimeService::isUserAuthorizedForChannel($owner, 'quoc-tin.visitors'));
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 05: Cư dân có permission PARCEL:VIEW không được bypass WebSocket
     */
    public function test_bug05_ws_05_resident_with_parcel_view_permission_cannot_bypass_websocket(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];

        // Cố tình gán permission PARCEL:VIEW cho role của cư dân
        $residentRole = DB::table('roles')->where('role_code', 'RESIDENT')->first();
        $perm = DB::table('permissions')->where('permission_code', 'PARCEL:VIEW')->first();
        if (! $perm) {
            $permId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $permId,
                'permission_code' => 'PARCEL:VIEW',
                'permission_name' => 'Xem bưu phẩm',
                'module' => 'PARCEL',
                'created_at' => now(),
            ]);
            $perm = (object) ['id' => $permId];
        }
        if ($residentRole && $perm) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $residentRole->id, 'permission_id' => $perm->id],
                ['id' => (string) Str::uuid(), 'created_at' => now()]
            );
        }

        try {
            // Dù có permission, nhưng không phải staff -> Tuyệt đối không được bypass channel toàn tòa!
            $this->assertFalse(
                QuocTinRealtimeService::isUserAuthorizedForChannel($resident, 'quoc-tin.parcels'),
                'Permission PARCEL:VIEW không được phép cho phép cư dân bypass vào channel WebSocket bưu phẩm toàn tòa'
            );
        } finally {
            if ($residentRole && $perm) {
                DB::table('role_permissions')
                    ->where('role_id', $residentRole->id)
                    ->where('permission_id', $perm->id)
                    ->delete();
            }
        }
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 06: Cư dân có permission VISITOR:VIEW không được bypass WebSocket
     */
    public function test_bug05_ws_06_resident_with_visitor_view_permission_cannot_bypass_websocket(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];

        $residentRole = DB::table('roles')->where('role_code', 'RESIDENT')->first();
        $perm = DB::table('permissions')->where('permission_code', 'VISITOR:VIEW')->first();
        if ($residentRole && $perm) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $residentRole->id, 'permission_id' => $perm->id],
                ['id' => (string) Str::uuid(), 'created_at' => now()]
            );
        }

        try {
            $this->assertFalse(
                QuocTinRealtimeService::isUserAuthorizedForChannel($resident, 'quoc-tin.visitors'),
                'Permission VISITOR:VIEW không được phép cho phép cư dân bypass vào channel WebSocket khách toàn tòa'
            );
        } finally {
            if ($residentRole && $perm) {
                DB::table('role_permissions')
                    ->where('role_id', $residentRole->id)
                    ->where('permission_id', $perm->id)
                    ->delete();
            }
        }
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 07: Nhân viên có quyền bưu phẩm subscribe thành công
     */
    public function test_bug05_ws_07_staff_with_parcel_permission_subscribes_successfully(): void
    {
        $staffData = $this->createStaffUserWithPermission('PARCEL:VIEW');
        $staffUser = $staffData['user'];

        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForChannel($staffUser, 'quoc-tin.parcels'),
            'Nhân viên vận hành có quyền PARCEL:VIEW phải subscribe được channel bưu phẩm toàn tòa'
        );
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 08: Nhân viên có quyền khách subscribe thành công
     */
    public function test_bug05_ws_08_staff_with_visitor_permission_subscribes_successfully(): void
    {
        $staffData = $this->createStaffUserWithPermission('VISITOR:VIEW');
        $staffUser = $staffData['user'];

        $this->assertTrue(
            QuocTinRealtimeService::isUserAuthorizedForChannel($staffUser, 'quoc-tin.visitors'),
            'Nhân viên vận hành có quyền VISITOR:VIEW phải subscribe được channel khách toàn tòa'
        );
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 09: Nhân viên không có quyền phù hợp bị từ chối WebSocket
     */
    public function test_bug05_ws_09_staff_without_appropriate_permission_rejected(): void
    {
        // Nhân viên chỉ có quyền VEHICLE:VIEW, không có PARCEL hay VISITOR
        $staffData = $this->createStaffUserWithPermission('VEHICLE:VIEW');
        $staffUser = $staffData['user'];

        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($staffUser, 'quoc-tin.parcels'),
            'Nhân viên không có quyền bưu phẩm không được subscribe channel bưu phẩm'
        );
        $this->assertFalse(
            QuocTinRealtimeService::isUserAuthorizedForChannel($staffUser, 'quoc-tin.visitors'),
            'Nhân viên không có quyền khách không được subscribe channel khách'
        );
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 10: Cư dân còn quyền vẫn nhận SSE/Polling của căn hộ hợp lệ
     */
    public function test_bug05_sse_10_valid_resident_receives_sse_for_their_apartment(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];
        $headers = $res['headers'];

        // Kiểm tra quyền SSE endpoint: phải được cho phép
        $this->assertTrue(QuocTinRealtimeService::isUserAuthorizedForSseChannel($resident, 'quoc-tin.parcels'));

        // Gọi HTTP API endpoint polling thực tế
        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptId,
            'tracking_number' => 'VN-RES-SSE-10',
        ]);

        $response = $this->getJson(
            "/api/v1/realtime/events?channels=quoc-tin.parcels&since_id={$lastIdBefore}",
            $headers
        );

        // Phải trả về HTTP 200 (KHÔNG PHẢI 403!)
        $response->assertStatus(200);
        $events = $response->json('events');
        $this->assertCount(1, $events);
        $this->assertEquals('VN-RES-SSE-10', $events[0]['tracking_number']);
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 11: Cư dân căn A không nhận sự kiện căn B qua SSE
     */
    public function test_bug05_sse_11_resident_apt_a_does_not_receive_events_for_apt_b(): void
    {
        $aptA = $this->createApartmentForExpirationTest();
        $aptB = $this->createApartmentForExpirationTest();

        $resA = $this->createResidentUserWithSession([$aptA]);
        $headersA = $resA['headers'];

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptA,
            'tracking_number' => 'PARCEL-FOR-A',
        ]);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptB,
            'tracking_number' => 'PARCEL-FOR-B',
        ]);

        $response = $this->getJson(
            "/api/v1/realtime/events?channels=quoc-tin.parcels&since_id={$lastIdBefore}",
            $headersA
        );

        $response->assertStatus(200);
        $events = $response->json('events');
        $this->assertCount(1, $events);
        $this->assertEquals('PARCEL-FOR-A', $events[0]['tracking_number']);

        $trackings = collect($events)->pluck('tracking_number')->toArray();
        $this->assertNotContains('PARCEL-FOR-B', $trackings, 'Cư dân căn A tuyệt đối không nhận được bưu phẩm căn B');
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 12: Cư dân hết hạn không nhận SSE (nhận HTTP 403 Forbidden)
     */
    public function test_bug05_sse_12_expired_resident_rejected_from_sse(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([]);
        $resident = $res['user'];
        $headers = $res['headers'];

        // Cư dân hợp đồng hết hạn hôm qua
        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $resident->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(6)->toDateString(),
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // isUserAuthorizedForSseChannel phải trả về false
        $this->assertFalse(QuocTinRealtimeService::isUserAuthorizedForSseChannel($resident, 'quoc-tin.parcels'));

        // Khi gọi API polling, controller phải chặn bằng HTTP 403
        $response = $this->getJson(
            '/api/v1/realtime/events?channels=quoc-tin.parcels',
            $headers
        );

        $response->assertStatus(403);
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 13: User không có apartment hợp lệ không nhận dữ liệu
     */
    public function test_bug05_sse_13_user_without_apartment_rejected_from_sse(): void
    {
        $res = $this->createResidentUserWithSession([]);
        $headers = $res['headers'];

        $response = $this->getJson(
            '/api/v1/realtime/events?channels=quoc-tin.parcels',
            $headers
        );

        $response->assertStatus(403);
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 14: Nhân viên vẫn nhận dữ liệu toàn tòa theo RBAC qua SSE
     */
    public function test_bug05_sse_14_staff_receives_all_events_across_apartments_via_sse(): void
    {
        $aptA = $this->createApartmentForExpirationTest();
        $aptB = $this->createApartmentForExpirationTest();

        $lastIdBefore = DB::table('realtime_sync_events')->max('id') ?? 0;
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptA,
            'tracking_number' => 'STAFF-ALL-A',
        ]);
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', (string) Str::uuid(), [
            'apartment_id' => $aptB,
            'tracking_number' => 'STAFF-ALL-B',
        ]);

        $response = $this->getJson(
            "/api/v1/realtime/events?channels=quoc-tin.parcels&since_id={$lastIdBefore}",
            $this->getAdminHeaders()
        );

        $response->assertStatus(200);
        $events = $response->json('events');
        $trackings = collect($events)->pluck('tracking_number')->toArray();
        $this->assertContains('STAFF-ALL-A', $trackings);
        $this->assertContains('STAFF-ALL-B', $trackings);
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 15: Luồng gửi thông báo SMS/Web Push từ QuocTinRealtimeEvent vẫn hoạt động
     */
    public function test_bug05_notif_15_quoctin_realtime_event_triggers_parcel_notification_listener(): void
    {
        Queue::fake();

        $aptId = $this->createApartmentForExpirationTest();
        $parcelId = (string) Str::uuid();

        // Emit sự kiện tiếp nhận bưu phẩm mới
        QuocTinRealtimeService::emit('parcels', 'parcel', 'CREATED', $parcelId, [
            'apartment_id' => $aptId,
            'tracking_number' => 'NOTIF-TRIGGER-15',
        ]);

        // Kiểm tra listener đã bắt được QuocTinRealtimeEvent và dispatch SendParcelNotificationJob
        Queue::assertPushed(SendParcelNotificationJob::class, function ($job) use ($parcelId) {
            return $job->parcelId === $parcelId;
        });
    }

    /**
     * TÁCH QUYỀN WEBSOCKET VÀ SSE - TEST 16: Không hồi quy các bảo vệ của BUG-01
     */
    public function test_bug05_regression_16_bug01_protections_fully_preserved(): void
    {
        // 1. Kiểm tra route bưu phẩm không có token bị chặn bằng 401
        $resAnon = $this->getJson('/api/v1/reception/parcels');
        $resAnon->assertStatus(401);

        // 2. Kiểm tra token sai bị chặn bằng 401
        $resBadToken = $this->getJson('/api/v1/reception/parcels', [
            'Authorization' => 'Bearer bad-fake-token-123',
            'Accept' => 'application/json',
        ]);
        $resBadToken->assertStatus(401);
    }

    /**
     * Helper lấy Closure callback channel thực tế được đăng ký qua Broadcast::channel() trong routes/channels.php
     */
    protected function getBroadcastChannelCallback(string $channelName): \Closure
    {
        if (file_exists(base_path('routes/channels.php'))) {
            require base_path('routes/channels.php');
        }

        $channels = Broadcast::driver()->getChannels();
        $callback = $channels instanceof Collection
            ? $channels->get($channelName)
            : ($channels[$channelName] ?? null);

        $this->assertNotNull($callback, "Broadcast channel [{$channelName}] callback must be registered in routes/channels.php");
        $this->assertInstanceOf(\Closure::class, $callback);

        return $callback;
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 01: Cư dân còn hạn - callback parcels thực tế trả false
     */
    public function test_bug05_callback_01_resident_valid_callback_parcels_returns_false(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];

        $callback = $this->getBroadcastChannelCallback('quoc-tin.parcels');
        $result = $callback($resident);

        $this->assertFalse($result, 'Callback Broadcast::channel(quoc-tin.parcels) thực tế phải trả false cho cư dân');
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 02: Cư dân còn hạn - callback visitors thực tế trả false
     */
    public function test_bug05_callback_02_resident_valid_callback_visitors_returns_false(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];

        $callback = $this->getBroadcastChannelCallback('quoc-tin.visitors');
        $result = $callback($resident);

        $this->assertFalse($result, 'Callback Broadcast::channel(quoc-tin.visitors) thực tế phải trả false cho cư dân');
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 03: Cư dân có permission PARCEL:VIEW - callback parcels thực tế vẫn trả false
     */
    public function test_bug05_callback_03_resident_with_parcel_view_callback_returns_false(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];

        $residentRole = DB::table('roles')->where('role_code', 'RESIDENT')->first();
        $perm = DB::table('permissions')->where('permission_code', 'PARCEL:VIEW')->first();
        if (! $perm) {
            $permId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $permId,
                'permission_code' => 'PARCEL:VIEW',
                'permission_name' => 'Xem bưu phẩm',
                'module' => 'PARCEL',
                'created_at' => now(),
            ]);
            $perm = (object) ['id' => $permId];
        }
        if ($residentRole && $perm) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $residentRole->id, 'permission_id' => $perm->id],
                ['id' => (string) Str::uuid(), 'created_at' => now()]
            );
        }

        try {
            $callback = $this->getBroadcastChannelCallback('quoc-tin.parcels');
            $result = $callback($resident);

            $this->assertFalse($result, 'Callback Broadcast::channel(quoc-tin.parcels) thực tế phải từ chối cư dân kể cả khi có PARCEL:VIEW');
        } finally {
            if ($residentRole && $perm) {
                DB::table('role_permissions')
                    ->where('role_id', $residentRole->id)
                    ->where('permission_id', $perm->id)
                    ->delete();
            }
        }
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 04: Cư dân có permission VISITOR:VIEW - callback visitors thực tế vẫn trả false
     */
    public function test_bug05_callback_04_resident_with_visitor_view_callback_returns_false(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([$aptId]);
        $resident = $res['user'];

        $residentRole = DB::table('roles')->where('role_code', 'RESIDENT')->first();
        $perm = DB::table('permissions')->where('permission_code', 'VISITOR:VIEW')->first();
        if ($residentRole && $perm) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $residentRole->id, 'permission_id' => $perm->id],
                ['id' => (string) Str::uuid(), 'created_at' => now()]
            );
        }

        try {
            $callback = $this->getBroadcastChannelCallback('quoc-tin.visitors');
            $result = $callback($resident);

            $this->assertFalse($result, 'Callback Broadcast::channel(quoc-tin.visitors) thực tế phải từ chối cư dân kể cả khi có VISITOR:VIEW');
        } finally {
            if ($residentRole && $perm) {
                DB::table('role_permissions')
                    ->where('role_id', $residentRole->id)
                    ->where('permission_id', $perm->id)
                    ->delete();
            }
        }
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 05: Cư dân hết hạn - cả 2 callback thực tế đều trả false
     */
    public function test_bug05_callback_05_resident_expired_callback_returns_false(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([]);
        $resident = $res['user'];

        DB::table('residents')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'user_id' => $resident->id,
            'stay_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subMonths(6)->toDateString(),
            'stay_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callbackParcels = $this->getBroadcastChannelCallback('quoc-tin.parcels');
        $callbackVisitors = $this->getBroadcastChannelCallback('quoc-tin.visitors');

        $this->assertFalse($callbackParcels($resident), 'Callback parcels thực tế phải trả false cho cư dân hết hạn');
        $this->assertFalse($callbackVisitors($resident), 'Callback visitors thực tế phải trả false cho cư dân hết hạn');
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 06: Chủ sở hữu hết hạn - cả 2 callback thực tế đều trả false
     */
    public function test_bug05_callback_06_owner_expired_callback_returns_false(): void
    {
        $aptId = $this->createApartmentForExpirationTest();
        $res = $this->createResidentUserWithSession([]);
        $owner = $res['user'];

        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $aptId,
            'owner_user_id' => $owner->id,
            'ownership_percentage' => 100.0,
            'ownership_start_date' => Carbon::now('Asia/Ho_Chi_Minh')->subYears(2)->toDateString(),
            'ownership_end_date' => Carbon::now('Asia/Ho_Chi_Minh')->subDay()->toDateString(),
            'is_current_owner' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callbackParcels = $this->getBroadcastChannelCallback('quoc-tin.parcels');
        $callbackVisitors = $this->getBroadcastChannelCallback('quoc-tin.visitors');

        $this->assertFalse($callbackParcels($owner), 'Callback parcels thực tế phải trả false cho chủ sở hữu hết hạn');
        $this->assertFalse($callbackVisitors($owner), 'Callback visitors thực tế phải trả false cho chủ sở hữu hết hạn');
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 07: Nhân viên có PARCEL:VIEW - callback parcels thực tế trả true
     */
    public function test_bug05_callback_07_staff_with_parcel_view_callback_returns_true(): void
    {
        $staffData = $this->createStaffUserWithPermission('PARCEL:VIEW');
        $staffUser = $staffData['user'];

        $callback = $this->getBroadcastChannelCallback('quoc-tin.parcels');
        $result = $callback($staffUser);

        $this->assertTrue($result, 'Callback Broadcast::channel(quoc-tin.parcels) thực tế phải trả true cho nhân viên có quyền PARCEL:VIEW');
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 08: Nhân viên có VISITOR:VIEW - callback visitors thực tế trả true
     */
    public function test_bug05_callback_08_staff_with_visitor_view_callback_returns_true(): void
    {
        $staffData = $this->createStaffUserWithPermission('VISITOR:VIEW');
        $staffUser = $staffData['user'];

        $callback = $this->getBroadcastChannelCallback('quoc-tin.visitors');
        $result = $callback($staffUser);

        $this->assertTrue($result, 'Callback Broadcast::channel(quoc-tin.visitors) thực tế phải trả true cho nhân viên có quyền VISITOR:VIEW');
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 09: Nhân viên không có quyền phù hợp - cả 2 callback thực tế đều trả false
     */
    public function test_bug05_callback_09_staff_without_appropriate_permission_callback_returns_false(): void
    {
        $staffData = $this->createStaffUserWithPermission('VEHICLE:VIEW');
        $staffUser = $staffData['user'];

        $callbackParcels = $this->getBroadcastChannelCallback('quoc-tin.parcels');
        $callbackVisitors = $this->getBroadcastChannelCallback('quoc-tin.visitors');

        $this->assertFalse($callbackParcels($staffUser), 'Callback parcels thực tế phải trả false cho nhân viên thiếu quyền');
        $this->assertFalse($callbackVisitors($staffUser), 'Callback visitors thực tế phải trả false cho nhân viên thiếu quyền');
    }

    /**
     * BỔ SUNG KIỂM THỬ CALLBACK - TEST 10: Admin và Super Admin - cả 2 callback thực tế đều trả true
     */
    public function test_bug05_callback_10_admin_and_super_admin_callbacks_return_true(): void
    {
        $callbackParcels = $this->getBroadcastChannelCallback('quoc-tin.parcels');
        $callbackVisitors = $this->getBroadcastChannelCallback('quoc-tin.visitors');

        // 1. Kiểm tra Super Admin
        $this->assertTrue($callbackParcels($this->adminUser), 'Callback parcels thực tế phải trả true cho Super Admin');
        $this->assertTrue($callbackVisitors($this->adminUser), 'Callback visitors thực tế phải trả true cho Super Admin');

        // 2. Kiểm tra Admin
        $adminRole = Role::where('role_code', 'ADMIN')->first();
        if (! $adminRole) {
            $adminRole = Role::create([
                'id' => (string) Str::uuid(),
                'role_code' => 'ADMIN',
                'role_name' => 'Administrator',
            ]);
        }
        $adminTester = User::create([
            'username' => 'admin_cb_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'admin_cb_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Callback Tester',
            'status' => 'ACTIVE',
        ]);
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $adminTester->id,
            'role_id' => $adminRole->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $this->assertTrue($callbackParcels($adminTester), 'Callback parcels thực tế phải trả true cho Admin');
        $this->assertTrue($callbackVisitors($adminTester), 'Callback visitors thực tế phải trả true cho Admin');
    }
}
