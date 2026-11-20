<?php

namespace Tests\Feature\RfidManagement;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessCardService;
use App\Services\QuocTinRealtimeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RfidRealtimeActorTest extends TestCase
{
    use DatabaseTransactions;

    protected ?Apartment $testApartment = null;

    protected ?User $testResident = null;

    protected AccessCardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->service = app(AccessCardService::class);

        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

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

        $this->testResident = User::create([
            'username' => 'res_rfid_actor_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_rfid_actor_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Actor '.Str::random(4),
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'user_id' => $this->testResident->id,
            'apartment_id' => $this->testApartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'is_active' => true,
        ]);
    }

    /**
     * Helper tạo Bearer token cho user
     */
    protected function issueToken(User $user): string
    {
        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit RFID Actor Test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * Helper tạo User kèm Role
     */
    protected function createUserWithRole(string $roleCode, string $prefix = 'user_'): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create([
            'username' => $prefix.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'RFID User '.$roleCode.' '.$rand,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', $roleCode)->firstOrFail();

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = $this->issueToken($user);

        return [$user, $token];
    }

    /**
     * Helper lấy sự kiện realtime gần nhất được ghi vào DB
     */
    protected function getLatestRealtimeEvent(string $entityId, ?string $action = null): ?array
    {
        $query = DB::table('realtime_sync_events')
            ->where('module', 'rfid_cards')
            ->where('entity_id', $entityId);

        if ($action !== null) {
            $query->where('action', strtoupper($action));
        }

        $row = $query->orderByDesc('id')->first();
        if (! $row) {
            return null;
        }

        return json_decode($row->payload, true);
    }

    // ========================================================
    // TEST CASES BUG-M5-02
    // ========================================================

    /**
     * 1. Sự kiện CREATED phát đúng actor_id là chuỗi UUID chuẩn của người tạo, không bị ép kiểu (int)
     */
    public function test_01_create_rfid_card_emits_valid_uuid_actor_id(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER', 'mgr_');

        $uid = 'UID-ACTOR-'.Str::upper(Str::random(6));
        $num = 'NUM-ACTOR-'.Str::upper(Str::random(5));

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/rfid-cards', [
                'card_uid' => $uid,
                'card_number' => $num,
                'card_type' => 'RESIDENT_ALL_ACCESS',
                'assigned_apartment_id' => $this->testApartment->id,
                'assigned_user_id' => $this->testResident->id,
                'issued_date' => date('Y-m-d'),
                'status' => 'ACTIVE',
                'deposit_fee' => 50000,
            ]);

        $res->assertStatus(201);
        $cardId = $res->json('data.id');

        $eventPayload = $this->getLatestRealtimeEvent($cardId, 'CREATED');
        $this->assertNotNull($eventPayload, 'Sự kiện realtime CREATED phải được ghi nhận');

        // Kiểm tra actor_id phải là UUID của manager
        $actorId = $eventPayload['actor_id'];
        $this->assertIsString($actorId, 'actor_id phải là kiểu chuỗi');
        $this->assertTrue(Str::isUuid($actorId), "actor_id '{$actorId}' phải là UUID hợp lệ, không phải số nguyên");
        $this->assertEquals($manager->id, $actorId, 'actor_id phải khớp chính xác UUID của người thực hiện thao tác');
        $this->assertNotEquals(0, $actorId);
        $this->assertNotEquals(1, $actorId);
    }

    /**
     * 2. Sự kiện UPDATED phát đúng actor_id là chuỗi UUID của người cập nhật
     */
    public function test_02_update_rfid_card_emits_valid_uuid_actor_id(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER', 'mgr_');

        // Tạo thẻ ban đầu
        $card = $this->service->createCard([
            'card_uid' => 'UID-UPD-'.Str::upper(Str::random(6)),
            'card_number' => 'NUM-UPD-'.Str::upper(Str::random(5)),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
        ], null);

        // Cập nhật thẻ bằng tài khoản manager
        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/rfid-cards/{$card->id}", [
                'deposit_fee' => 85000,
            ]);

        $res->assertStatus(200);

        $eventPayload = $this->getLatestRealtimeEvent($card->id, 'UPDATED');
        $this->assertNotNull($eventPayload, 'Sự kiện realtime UPDATED phải được ghi nhận');

        $actorId = $eventPayload['actor_id'];
        $this->assertIsString($actorId);
        $this->assertTrue(Str::isUuid($actorId));
        $this->assertEquals($manager->id, $actorId);
        $this->assertNotEquals(0, $actorId);
        $this->assertNotEquals(1, $actorId);
    }

    /**
     * 3. Sự kiện STATUS_CHANGED phát đúng actor_id là chuỗi UUID của người đổi trạng thái
     */
    public function test_03_toggle_status_rfid_card_emits_valid_uuid_actor_id(): void
    {
        [$guard, $token] = $this->createUserWithRole('SECURITY_GUARD', 'sec_');

        $card = $this->service->createCard([
            'card_uid' => 'UID-TOG-'.Str::upper(Str::random(6)),
            'card_number' => 'NUM-TOG-'.Str::upper(Str::random(5)),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
            'status' => 'ACTIVE',
        ], null);

        // Bảo vệ tạm khóa thẻ
        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");

        $res->assertStatus(200);

        $eventPayload = $this->getLatestRealtimeEvent($card->id, 'STATUS_CHANGED');
        $this->assertNotNull($eventPayload, 'Sự kiện realtime STATUS_CHANGED phải được ghi nhận');

        $actorId = $eventPayload['actor_id'];
        $this->assertIsString($actorId);
        $this->assertTrue(Str::isUuid($actorId));
        $this->assertEquals($guard->id, $actorId);
        $this->assertNotEquals(0, $actorId);
        $this->assertNotEquals(1, $actorId);
    }

    /**
     * 4. Sự kiện DELETED phát đúng actor_id là chuỗi UUID của người xóa thẻ
     */
    public function test_04_delete_rfid_card_emits_valid_uuid_actor_id(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER', 'mgr_');

        $card = $this->service->createCard([
            'card_uid' => 'UID-DEL-'.Str::upper(Str::random(6)),
            'card_number' => 'NUM-DEL-'.Str::upper(Str::random(5)),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
        ], null);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/rfid-cards/{$card->id}");

        $res->assertStatus(200);

        $eventPayload = $this->getLatestRealtimeEvent($card->id, 'DELETED');
        $this->assertNotNull($eventPayload, 'Sự kiện realtime DELETED phải được ghi nhận');

        $actorId = $eventPayload['actor_id'];
        $this->assertIsString($actorId);
        $this->assertTrue(Str::isUuid($actorId));
        $this->assertEquals($manager->id, $actorId);
        $this->assertNotEquals(0, $actorId);
        $this->assertNotEquals(1, $actorId);
    }

    /**
     * 5. Khi gọi trực tiếp qua service với actorId = null: actor_id được lưu là null an toàn
     */
    public function test_05_direct_service_call_with_null_actor_emits_null_actor_id(): void
    {
        $card = $this->service->createCard([
            'card_uid' => 'UID-NULL-'.Str::upper(Str::random(6)),
            'card_number' => 'NUM-NULL-'.Str::upper(Str::random(5)),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
        ], null);

        $eventPayload = $this->getLatestRealtimeEvent($card->id, 'CREATED');
        $this->assertNotNull($eventPayload);
        $this->assertNull($eventPayload['actor_id'], 'actor_id phải là null khi không có người thao tác');
        $this->assertNotSame(0, $eventPayload['actor_id']);
    }

    /**
     * 6. Hai nhân viên khác nhau thao tác phát sinh đúng actor_id tương ứng của từng người
     */
    public function test_06_different_actors_emit_their_own_respective_uuid(): void
    {
        [$receptionist, $recToken] = $this->createUserWithRole('RECEPTIONIST', 'rec_');
        [$manager, $mgrToken] = $this->createUserWithRole('BUILDING_MANAGER', 'mgr_');

        // Lễ tân tạo thẻ
        $resCreate = $this->withHeader('Authorization', "Bearer {$recToken}")
            ->postJson('/api/v1/rfid-cards', [
                'card_uid' => 'UID-DIFF-'.Str::upper(Str::random(6)),
                'card_number' => 'NUM-DIFF-'.Str::upper(Str::random(5)),
                'card_type' => 'RESIDENT_ALL_ACCESS',
                'assigned_apartment_id' => $this->testApartment->id,
                'assigned_user_id' => $this->testResident->id,
                'issued_date' => date('Y-m-d'),
            ]);

        $resCreate->assertStatus(201);
        $cardId = $resCreate->json('data.id');

        $createEvent = $this->getLatestRealtimeEvent($cardId, 'CREATED');
        $this->assertEquals($receptionist->id, $createEvent['actor_id']);

        // Ban quản lý cập nhật thẻ
        $resUpdate = $this->withHeader('Authorization', "Bearer {$mgrToken}")
            ->putJson("/api/v1/rfid-cards/{$cardId}", [
                'deposit_fee' => 70000,
            ]);

        $resUpdate->assertStatus(200);

        $updateEvent = $this->getLatestRealtimeEvent($cardId, 'UPDATED');
        $this->assertEquals($manager->id, $updateEvent['actor_id']);
        $this->assertNotEquals($createEvent['actor_id'], $updateEvent['actor_id']);
    }

    /**
     * 7. Yêu cầu chưa xác thực không thể thực hiện thao tác hay phát sự kiện giả mạo
     */
    public function test_07_unauthenticated_request_cannot_spoof_realtime_events(): void
    {
        $initialMaxId = DB::table('realtime_sync_events')->where('module', 'rfid_cards')->max('id') ?? 0;

        $res = $this->postJson('/api/v1/rfid-cards', [
            'card_uid' => 'UID-SPOOF-'.Str::random(6),
            'card_number' => 'NUM-SPOOF-'.Str::random(5),
        ]);

        $res->assertStatus(401);

        $newMaxId = DB::table('realtime_sync_events')->where('module', 'rfid_cards')->max('id') ?? 0;
        $this->assertEquals($initialMaxId, $newMaxId, 'Không có sự kiện realtime nào được phát sinh khi request 401');
    }
}
