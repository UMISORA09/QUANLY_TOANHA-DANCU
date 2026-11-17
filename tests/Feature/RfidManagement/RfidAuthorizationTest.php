<?php

namespace Tests\Feature\RfidManagement;

use App\Models\Apartment;
use App\Models\Permission;
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

class RfidAuthorizationTest extends TestCase
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
            'username' => 'res_rfid_auth_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_rfid_auth_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân RFID Auth '.Str::random(4),
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
            'device_name' => 'PHPUnit RFID Auth Test',
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
    protected function createUserWithRole(string $roleCode, string $prefix = 'user_', array $attributes = []): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create(array_merge([
            'username' => $prefix.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'RFID Role '.$roleCode.' '.$rand,
            'status' => 'ACTIVE',
        ], $attributes));

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
     * Helper tạo User có danh sách Permission chỉ định (qua CUSTOM_USER_{id} role)
     */
    protected function createUserWithCustomPermissions(array $permissionCodes, ?string $baseRoleCode = null): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create([
            'username' => 'custom_rfid_'.$rand,
            'phone_number' => '08'.rand(10000000, 99999999),
            'email' => 'custom_rfid_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Custom RFID Tester '.$rand,
            'status' => 'ACTIVE',
        ]);

        if ($baseRoleCode !== null) {
            $baseRole = Role::where('role_code', $baseRoleCode)->firstOrFail();
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $baseRole->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $customRole = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => 'CUSTOM_USER_'.$user->id,
            'role_name' => 'Quyền tùy chỉnh người dùng '.$user->username,
            'description' => 'Role tùy chỉnh trong test',
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
                ['module' => 'RFID', 'permission_name' => $code, 'description' => $code, 'created_at' => now()]
            );

            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $customRole->id,
                'permission_id' => $perm->id,
                'created_at' => now(),
            ]);
        }

        $user->clearPermissionCache();
        $token = $this->issueToken($user);

        return [$user, $token];
    }

    /**
     * Helper tạo nhanh 1 thẻ RFID trong CSDL
     */
    protected function createTestCard(array $overrides = []): array
    {
        $cardUid = 'UID-TEST-'.Str::upper(Str::random(8));
        $cardNumber = 'CARD-'.Str::upper(Str::random(6));

        $payload = array_merge([
            'card_uid' => $cardUid,
            'card_number' => $cardNumber,
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
            'status' => 'ACTIVE',
            'deposit_fee' => 50000,
        ], $overrides);

        $card = $this->service->createCard($payload, null);

        return [$card, $payload];
    }

    // ========================================================
    // TEST CASES
    // ========================================================

    /**
     * 1. Yêu cầu không xác thực bị chặn 401 Unauthorized
     */
    public function test_01_unauthenticated_request_is_blocked_with_401(): void
    {
        $response = $this->getJson('/api/v1/rfid-cards');
        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'UNAUTHORIZED');
    }

    /**
     * 2. Người dùng bị khóa / không ở trạng thái ACTIVE bị chặn 403 Forbidden
     */
    public function test_02_inactive_user_is_blocked_with_403(): void
    {
        [$inactiveUser, $token] = $this->createUserWithRole('BUILDING_MANAGER', 'inact_', ['status' => 'SUSPENDED']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/rfid-cards');

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 3. Người dùng chỉ có RFID:VIEW được phép truy cập index, show, apartments, residents
     */
    public function test_03_user_with_rfid_view_can_access_view_endpoints(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW']);
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$token}"];

        // Index
        $resIndex = $this->withHeaders($headers)->getJson('/api/v1/rfid-cards');
        $resIndex->assertStatus(200)->assertJsonPath('success', true);

        // Show
        $resShow = $this->withHeaders($headers)->getJson("/api/v1/rfid-cards/{$card->id}");
        $resShow->assertStatus(200)->assertJsonPath('success', true);

        // Apartments dropdown
        $resApts = $this->withHeaders($headers)->getJson('/api/v1/rfid-cards/meta/apartments');
        $resApts->assertStatus(200)->assertJsonPath('success', true);

        // Residents dropdown
        $resRes = $this->withHeaders($headers)->getJson("/api/v1/rfid-cards/meta/residents?apartment_id={$this->testApartment->id}");
        $resRes->assertStatus(200)->assertJsonPath('success', true);
    }

    /**
     * 4. Người dùng chỉ có RFID:VIEW KHÔNG thể tạo, sửa, đổi trạng thái hoặc xóa thẻ (403)
     */
    public function test_04_user_with_only_rfid_view_cannot_create_update_toggle_or_delete_card(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW']);
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$token}"];

        // Cố tình tạo thẻ (POST)
        $resStore = $this->withHeaders($headers)->postJson('/api/v1/rfid-cards', [
            'card_uid' => 'UID-FORBIDDEN-'.Str::random(6),
            'card_number' => 'NUM-FORBIDDEN-'.Str::random(5),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
        ]);
        $resStore->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN');

        // Cố tình cập nhật thẻ (PUT)
        $resUpdate = $this->withHeaders($headers)->putJson("/api/v1/rfid-cards/{$card->id}", [
            'deposit_fee' => 99000,
        ]);
        $resUpdate->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN');

        // Cố tình đổi trạng thái (PATCH toggle-status)
        $resToggle = $this->withHeaders($headers)->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");
        $resToggle->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN');

        // Cố tình xóa thẻ (DELETE)
        $resDelete = $this->withHeaders($headers)->deleteJson("/api/v1/rfid-cards/{$card->id}");
        $resDelete->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    /**
     * 5. Người dùng thiếu RFID:CREATE không thể tạo thẻ (403)
     */
    public function test_05_user_without_rfid_create_cannot_create_card(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW', 'RFID:UPDATE']);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/rfid-cards', [
                'card_uid' => 'UID-NO-CREATE-'.Str::random(6),
                'card_number' => 'NUM-NO-CREATE-'.Str::random(5),
                'card_type' => 'RESIDENT_ALL_ACCESS',
                'assigned_apartment_id' => $this->testApartment->id,
                'assigned_user_id' => $this->testResident->id,
            ]);

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    /**
     * 6. Người dùng có RFID:CREATE tạo thẻ thành công (201)
     */
    public function test_06_user_with_rfid_create_can_create_card(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW', 'RFID:CREATE']);

        $uid = 'UID-CAN-CREATE-'.Str::upper(Str::random(6));
        $num = 'NUM-CAN-CREATE-'.Str::upper(Str::random(5));

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/rfid-cards', [
                'card_uid' => $uid,
                'card_number' => $num,
                'card_type' => 'RESIDENT_ALL_ACCESS',
                'assigned_apartment_id' => $this->testApartment->id,
                'assigned_user_id' => $this->testResident->id,
                'issued_date' => date('Y-m-d'),
                'status' => 'ACTIVE',
                'deposit_fee' => 60000,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('access_cards', [
            'card_uid' => $uid,
            'card_number' => $num,
        ]);
    }

    /**
     * 7. Người dùng thiếu RFID:UPDATE không thể sửa thẻ hoặc đổi trạng thái (403)
     */
    public function test_07_user_without_rfid_update_cannot_update_or_toggle_status(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW', 'RFID:CREATE']);
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$token}"];

        $resUpdate = $this->withHeaders($headers)->putJson("/api/v1/rfid-cards/{$card->id}", [
            'deposit_fee' => 88000,
        ]);
        $resUpdate->assertStatus(403)->assertJsonPath('success', false);

        $resToggle = $this->withHeaders($headers)->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");
        $resToggle->assertStatus(403)->assertJsonPath('success', false);
    }

    /**
     * 8. Người dùng có RFID:UPDATE cập nhật và đổi trạng thái thành công (200)
     */
    public function test_08_user_with_rfid_update_can_update_and_toggle_status(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW', 'RFID:UPDATE']);
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$token}"];

        // Update deposit fee
        $resUpdate = $this->withHeaders($headers)->putJson("/api/v1/rfid-cards/{$card->id}", [
            'deposit_fee' => 77000,
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deposit_fee', 77000);

        // Toggle status ACTIVE -> LOCKED_TEMPORARY
        $resToggle = $this->withHeaders($headers)->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status");
        $resToggle->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'LOCKED_TEMPORARY');
    }

    /**
     * 9. Người dùng thiếu RFID:DELETE không thể xóa thẻ (403)
     */
    public function test_09_user_without_rfid_delete_cannot_delete_card(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW', 'RFID:CREATE', 'RFID:UPDATE']);
        [$card] = $this->createTestCard();

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/rfid-cards/{$card->id}");

        $res->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'FORBIDDEN');
    }

    /**
     * 10. Người dùng có RFID:DELETE xóa thẻ thành công (200)
     */
    public function test_10_user_with_rfid_delete_can_delete_card(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW', 'RFID:DELETE']);
        [$card] = $this->createTestCard();

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/rfid-cards/{$card->id}");

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        // Thẻ chưa từng có log quẹt -> xóa vật lý
        $this->assertDatabaseMissing('access_cards', [
            'id' => $card->id,
        ]);

        // Tạo thẻ thứ 2 và giả lập đã có nhật ký khách thăm dùng thẻ này
        [$card2] = $this->createTestCard();
        DB::table('visitor_checkin_logs')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $this->testApartment->id,
            'visitor_name' => 'Khách kiểm thử RFID',
            'temp_card_id' => $card2->id,
            'checkin_time' => now(),
            'created_at' => now(),
        ]);

        $res2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/rfid-cards/{$card2->id}");

        $res2->assertStatus(200)
            ->assertJsonPath('success', true);

        // Thẻ đã có log quẹt -> chuyển trạng thái REVOKED để bảo toàn quan hệ
        $this->assertDatabaseHas('access_cards', [
            'id' => $card2->id,
            'status' => 'REVOKED',
        ]);
    }

    /**
     * 11. Role có tên hợp lệ nhưng không có quyền RFID (vd ACCOUNTANT, RESIDENT_OWNER) không thể bypass (403)
     */
    public function test_11_role_with_valid_name_but_missing_permissions_cannot_bypass(): void
    {
        [$accountant, $accToken] = $this->createUserWithRole('ACCOUNTANT', 'acc_');
        [$owner, $ownerToken] = $this->createUserWithRole('RESIDENT_OWNER', 'own_');

        // Kế toán không thể xem danh sách thẻ
        $resAcc = $this->withHeader('Authorization', "Bearer {$accToken}")
            ->getJson('/api/v1/rfid-cards');
        $resAcc->assertStatus(403)->assertJsonPath('success', false);

        // Cư dân chủ hộ không thể xem danh sách thẻ
        $resOwner = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->getJson('/api/v1/rfid-cards');
        $resOwner->assertStatus(403)->assertJsonPath('success', false);
    }

    /**
     * 12. Vai trò LỄ TÂN (RECEPTIONIST) có VIEW, CREATE, UPDATE nhưng KHÔNG thể DELETE (403)
     */
    public function test_12_role_receptionist_has_view_create_update_but_cannot_delete(): void
    {
        [$receptionist, $recToken] = $this->createUserWithRole('RECEPTIONIST', 'rec_');
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$recToken}"];

        // Có thể VIEW
        $this->withHeaders($headers)->getJson('/api/v1/rfid-cards')->assertStatus(200);

        // Có thể CREATE
        $uid = 'UID-REC-'.Str::upper(Str::random(6));
        $this->withHeaders($headers)->postJson('/api/v1/rfid-cards', [
            'card_uid' => $uid,
            'card_number' => 'NUM-REC-'.Str::random(5),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
        ])->assertStatus(201);

        // Có thể UPDATE
        $this->withHeaders($headers)->putJson("/api/v1/rfid-cards/{$card->id}", [
            'deposit_fee' => 55000,
        ])->assertStatus(200);

        // KHÔNG thể DELETE (403)
        $this->withHeaders($headers)->deleteJson("/api/v1/rfid-cards/{$card->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * 13. Vai trò BẢO VỆ (SECURITY_GUARD) có VIEW, UPDATE nhưng KHÔNG thể CREATE hoặc DELETE (403)
     */
    public function test_13_role_security_guard_has_view_update_but_cannot_create_or_delete(): void
    {
        [$guard, $guardToken] = $this->createUserWithRole('SECURITY_GUARD', 'sec_');
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$guardToken}"];

        // Có thể VIEW
        $this->withHeaders($headers)->getJson('/api/v1/rfid-cards')->assertStatus(200);

        // Có thể UPDATE (toggle-status)
        $this->withHeaders($headers)->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status")->assertStatus(200);

        // KHÔNG thể CREATE (403)
        $this->withHeaders($headers)->postJson('/api/v1/rfid-cards', [
            'card_uid' => 'UID-SEC-'.Str::upper(Str::random(6)),
            'card_number' => 'NUM-SEC-'.Str::random(5),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
        ])->assertStatus(403);

        // KHÔNG thể DELETE (403)
        $this->withHeaders($headers)->deleteJson("/api/v1/rfid-cards/{$card->id}")->assertStatus(403);
    }

    /**
     * 14. Vai trò BAN QUẢN LÝ (BUILDING_MANAGER) có đầy đủ toàn quyền RFID (VIEW, CREATE, UPDATE, DELETE)
     */
    public function test_14_role_building_manager_has_full_rfid_permissions(): void
    {
        [$manager, $mgrToken] = $this->createUserWithRole('BUILDING_MANAGER', 'mgr_');
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$mgrToken}"];

        // VIEW
        $this->withHeaders($headers)->getJson('/api/v1/rfid-cards')->assertStatus(200);

        // CREATE
        $uid = 'UID-MGR-'.Str::upper(Str::random(6));
        $this->withHeaders($headers)->postJson('/api/v1/rfid-cards', [
            'card_uid' => $uid,
            'card_number' => 'NUM-MGR-'.Str::random(5),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->testApartment->id,
            'assigned_user_id' => $this->testResident->id,
            'issued_date' => date('Y-m-d'),
        ])->assertStatus(201);

        // UPDATE
        $this->withHeaders($headers)->putJson("/api/v1/rfid-cards/{$card->id}", [
            'deposit_fee' => 95000,
        ])->assertStatus(200);

        // DELETE
        $this->withHeaders($headers)->deleteJson("/api/v1/rfid-cards/{$card->id}")->assertStatus(200);
    }

    /**
     * 15. SUPER_ADMIN bypass toàn bộ các kiểm tra permission
     */
    public function test_15_super_admin_bypasses_all_permission_checks(): void
    {
        [$superAdmin, $adminToken] = $this->createUserWithRole('SUPER_ADMIN', 'super_');
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$adminToken}"];

        $this->withHeaders($headers)->getJson('/api/v1/rfid-cards')->assertStatus(200);
        $this->withHeaders($headers)->getJson("/api/v1/rfid-cards/{$card->id}")->assertStatus(200);
        $this->withHeaders($headers)->putJson("/api/v1/rfid-cards/{$card->id}", ['deposit_fee' => 100000])->assertStatus(200);
        $this->withHeaders($headers)->patchJson("/api/v1/rfid-cards/{$card->id}/toggle-status")->assertStatus(200);
        $this->withHeaders($headers)->deleteJson("/api/v1/rfid-cards/{$card->id}")->assertStatus(200);
    }

    /**
     * 16. Thu hồi quyền hạn và xóa cache: Hiệu lực tức thì, chặn ngay lập tức
     */
    public function test_16_permission_revocation_and_cache_clearing_is_enforced_immediately(): void
    {
        [$user, $token] = $this->createUserWithCustomPermissions(['RFID:VIEW', 'RFID:DELETE']);
        [$card] = $this->createTestCard();

        $headers = ['Authorization' => "Bearer {$token}"];

        // Ban đầu có quyền: Xóa thành công
        $this->withHeaders($headers)->deleteJson("/api/v1/rfid-cards/{$card->id}")->assertStatus(200);

        // Tạo thẻ thứ 2
        [$card2] = $this->createTestCard();

        // Thu hồi quyền RFID:DELETE khỏi CUSTOM role của user
        $customRole = Role::where('role_code', 'CUSTOM_USER_'.$user->id)->firstOrFail();
        $permDelete = Permission::where('permission_code', 'RFID:DELETE')->firstOrFail();

        DB::table('role_permissions')
            ->where('role_id', $customRole->id)
            ->where('permission_id', $permDelete->id)
            ->delete();

        // Xóa cache permission
        $user->clearPermissionCache();

        // Cố tình xóa tiếp: Bị chặn ngay lập tức (403)
        $resBlocked = $this->withHeaders($headers)->deleteJson("/api/v1/rfid-cards/{$card2->id}");
        $resBlocked->assertStatus(403)->assertJsonPath('success', false);
    }
}
