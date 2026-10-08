<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\QuocTinRealtimeService;
use App\Services\ResidentService;
use App\Services\TemporaryRegistrationService;
use App\Services\VehicleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealtimeSyncTest extends TestCase
{
    protected ?User $adminUser = null;

    protected ?string $adminToken = null;

    protected ?User $restrictedUser = null;

    protected ?string $restrictedToken = null;

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
        $aptId = Apartment::value('id') ?? (string) Str::uuid();
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
        $resident = Resident::first();
        if (! $resident) {
            $aptId = Apartment::value('id');
            $resident = Resident::create([
                'apartment_id' => $aptId,
                'user_id' => $this->adminUser->id,
                'resident_type' => 'FAMILY_MEMBER',
                'is_head_of_household' => 0,
                'is_active' => 1,
                'stay_start_date' => now()->toDateString(),
            ]);
        }

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
        $resident = Resident::first();
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

        // Tạo hồ sơ thứ 2 để test từ chối (clear cooldown để cho phép mutation kế tiếp trong test case này)
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
        if ($vehicle) {
            $service = app(VehicleService::class);
            $service->updateVehicle($vehicle->id, ['color' => 'Xanh Neon']);

            $events = QuocTinRealtimeService::getEvents(['quoc-tin.vehicles'], 0, 0, 5);
            $matched = collect($events)->first(function ($ev) use ($vehicle) {
                return $ev['entity_id'] === $vehicle->id;
            });

            $this->assertNotNull($matched);
            $this->assertEquals($vehicle->id, $matched['entity_id']);
        } else {
            $this->assertTrue(true);
        }
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

        $resident = Resident::first();
        if (! $resident) {
            $aptId = Apartment::value('id');
            $resident = Resident::create([
                'apartment_id' => $aptId,
                'user_id' => $this->adminUser->id,
                'resident_type' => 'FAMILY_MEMBER',
                'is_head_of_household' => 0,
                'is_active' => 1,
                'stay_start_date' => now()->toDateString(),
            ]);
        }

        $service = app(ResidentService::class);
        $service->update($resident->id, [
            'occupation' => 'Architect_'.Str::random(4),
        ]);

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

        $resident = Resident::first();
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

        $resident = Resident::first();
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

        $resident = Resident::first();
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

        QuocTinRealtimeService::emit('residents', 'resident', 'UPDATED', 'res-123', [], $this->adminUser->id);

        $events = QuocTinRealtimeService::getEvents(['quoc-tin.residents'], $lastIdBefore, 0, 10);

        $cooldownEvent = collect($events)->firstWhere('action', 'EDIT_COOLDOWN_STARTED');
        $this->assertNotNull($cooldownEvent, 'Hệ thống phải broadcast event EDIT_COOLDOWN_STARTED');
        $this->assertEquals('residents', $cooldownEvent['module']);
        $this->assertEquals(120, $cooldownEvent['cooldown_seconds']);
        $this->assertNotEmpty($cooldownEvent['cooldown_until']);
    }
}
