<?php

namespace Tests\Feature\RfidManagement;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessCardConflictException;
use App\Services\AccessCardService;
use App\Services\QuocTinRealtimeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RfidToggleStatusProtectionTest extends TestCase
{
    use DatabaseTransactions;

    protected ?Apartment $testApartment = null;

    protected ?User $testResident = null;

    protected ?User $managerUser = null;

    protected ?string $managerToken = null;

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
            'username' => 'res_rfid_toggle_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_rfid_toggle_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Toggle Test '.Str::random(4),
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

        [$this->managerUser, $this->managerToken] = $this->createManagerUser();
    }

    protected function createManagerUser(): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create([
            'username' => 'mgr_toggle_'.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'mgr_toggle_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Manager Toggle Tester '.$rand,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'BUILDING_MANAGER')->firstOrFail();

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit RFID Toggle Test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function createCardWithStatus(string $status): array
    {
        $uid = 'UID-TOG-'.Str::upper(Str::random(8));
        $num = 'NUM-TOG-'.Str::upper(Str::random(6));

        $card = $this->service->createCard([
            'card_uid' => $uid,
            'card_number' => $num,
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
            'status' => $status,
            'deposit_fee' => 50000,
        ], $this->managerUser->id);

        return [$card, $uid, $num];
    }

    // ========================================================
    // TEST CASES BUG-M5-03
    // ========================================================

    /**
     * 1. Thẻ đang ACTIVE có thể chuyển đổi sang LOCKED_TEMPORARY thành công (200)
     */
    public function test_01_active_card_can_be_toggled_to_locked_temporary(): void
    {
        [$card] = $this->createCardWithStatus('ACTIVE');

        $res = $this->withHeader('Authorization', "Bearer {$this->managerToken}")
            ->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'LOCKED_TEMPORARY')
            ->assertJsonPath('message', 'Đã tạm khóa thẻ RFID thành công.');

        $this->assertDatabaseHas('access_cards', [
            'id' => $card->id,
            'status' => 'LOCKED_TEMPORARY',
        ]);
    }

    /**
     * 2. Thẻ đang LOCKED_TEMPORARY có thể chuyển đổi ngược lại sang ACTIVE thành công (200)
     */
    public function test_02_locked_temporary_card_can_be_toggled_back_to_active(): void
    {
        [$card] = $this->createCardWithStatus('LOCKED_TEMPORARY');

        $res = $this->withHeader('Authorization', "Bearer {$this->managerToken}")
            ->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('message', 'Đã kích hoạt thẻ RFID thành công.');

        $this->assertDatabaseHas('access_cards', [
            'id' => $card->id,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * 3. Thẻ đã bị thu hồi (REVOKED) KHÔNG thể toggle-status, trả về HTTP 409 Conflict
     */
    public function test_03_revoked_card_cannot_be_toggled_returns_409_conflict(): void
    {
        [$card] = $this->createCardWithStatus('REVOKED');

        $res = $this->withHeader('Authorization', "Bearer {$this->managerToken}")
            ->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");

        $res->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'CONFLICT');

        $this->assertStringContainsString('REVOKED', $res->json('message'));

        // Trạng thái trong DB phải giữ nguyên REVOKED, không bị biến thành ACTIVE
        $this->assertDatabaseHas('access_cards', [
            'id' => $card->id,
            'status' => 'REVOKED',
        ]);
    }

    /**
     * 4. Thẻ đã báo mất (LOST_REPORTED) KHÔNG thể toggle-status, trả về HTTP 409 Conflict
     */
    public function test_04_lost_reported_card_cannot_be_toggled_returns_409_conflict(): void
    {
        [$card] = $this->createCardWithStatus('LOST_REPORTED');

        $res = $this->withHeader('Authorization', "Bearer {$this->managerToken}")
            ->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");

        $res->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'CONFLICT');

        $this->assertStringContainsString('LOST_REPORTED', $res->json('message'));

        // Trạng thái trong DB phải giữ nguyên LOST_REPORTED
        $this->assertDatabaseHas('access_cards', [
            'id' => $card->id,
            'status' => 'LOST_REPORTED',
        ]);
    }

    /**
     * 5. Thẻ đang ở trạng thái khóa cố định (LOCKED) KHÔNG thể toggle-status, trả về HTTP 409 Conflict
     */
    public function test_05_permanently_locked_card_cannot_be_toggled_returns_409_conflict(): void
    {
        [$card] = $this->createCardWithStatus('LOCKED');

        $res = $this->withHeader('Authorization', "Bearer {$this->managerToken}")
            ->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");

        $res->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'CONFLICT');

        $this->assertDatabaseHas('access_cards', [
            'id' => $card->id,
            'status' => 'LOCKED',
        ]);
    }

    /**
     * 6. Gọi trực tiếp tầng Service với thẻ REVOKED ném AccessCardConflictException
     */
    public function test_06_direct_service_call_on_revoked_card_throws_conflict_exception(): void
    {
        [$card] = $this->createCardWithStatus('REVOKED');

        $this->expectException(AccessCardConflictException::class);
        $this->expectExceptionMessage('REVOKED');

        $this->service->toggleStatus($card->id, $this->managerUser->id);
    }

    /**
     * 7. Gọi trực tiếp tầng Service với thẻ LOST_REPORTED ném AccessCardConflictException
     */
    public function test_07_direct_service_call_on_lost_reported_card_throws_conflict_exception(): void
    {
        [$card] = $this->createCardWithStatus('LOST_REPORTED');

        $this->expectException(AccessCardConflictException::class);
        $this->expectExceptionMessage('LOST_REPORTED');

        $this->service->toggleStatus($card->id, $this->managerUser->id);
    }

    /**
     * 8. Thao tác trên thẻ không tồn tại trả về HTTP 404 Not Found
     */
    public function test_08_toggle_status_on_non_existent_card_returns_404(): void
    {
        $fakeId = (string) Str::uuid();

        $res = $this->withHeader('Authorization', "Bearer {$this->managerToken}")
            ->patchJson("/api/v1/rfid-cards/{$fakeId}/toggle-status");

        $res->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'NOT_FOUND');
    }
}
