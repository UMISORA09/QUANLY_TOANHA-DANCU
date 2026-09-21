<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacSecurityTest extends TestCase
{
    /**
     * Tạo user kèm token phiên đăng nhập
     */
    protected function createUserWithRole(string $roleCode): array
    {
        $user = User::create([
            'username' => 'test_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'test_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password'),
            'full_name' => 'Test User '.$roleCode,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', $roleCode)->first();
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
            'device_name' => 'Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * TEST 1: Khách vãng lai (Guest) chưa đăng nhập gọi API được bảo vệ -> 401
     */
    public function test_guest_cannot_access_protected_api_returns_401(): void
    {
        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'UNAUTHORIZED',
            ]);
    }

    /**
     * TEST 2: Người dùng đăng nhập nhưng không có quyền USER:VIEW -> 403 Forbidden
     */
    public function test_user_without_permission_returns_403(): void
    {
        // RESIDENT_OWNER không có quyền USER:VIEW
        [$user, $token] = $this->createUserWithRole('RESIDENT_OWNER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);
    }

    /**
     * TEST 3: Người dùng có quyền USER:VIEW -> 200 OK
     */
    public function test_user_with_user_view_permission_can_list_users(): void
    {
        // BUILDING_MANAGER có quyền USER:VIEW
        [$user, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'username', 'email', 'full_name', 'roles'],
                ],
                'meta',
            ]);
    }

    /**
     * TEST 4: Người dùng không có quyền USER:CREATE khi POST /users -> 403
     */
    public function test_user_without_user_create_cannot_create_user(): void
    {
        [$user, $token] = $this->createUserWithRole('RECEPTIONIST');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'username' => 'attacker_'.Str::random(5),
                'phone_number' => '09'.rand(10000000, 99999999),
                'email' => 'attacker_'.Str::random(5).'@cassavas.vn',
                'full_name' => 'Attacker',
                'password' => 'secret123',
            ]);

        $response->assertStatus(403);
    }

    /**
     * TEST 5: Người dùng có quyền USER:CREATE tạo user thành công -> 201
     */
    public function test_user_with_user_create_can_create_user(): void
    {
        [$user, $token] = $this->createUserWithRole('BUILDING_MANAGER');

        $newUsername = 'new_user_'.Str::random(6);
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/users', [
                'username' => $newUsername,
                'phone_number' => '09'.rand(10000000, 99999999),
                'email' => $newUsername.'@cassavas.vn',
                'full_name' => 'New Resident',
                'password' => 'password123',
                'roles' => ['RESIDENT_OWNER'],
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', ['username' => $newUsername]);
    }

    /**
     * TEST 6: Người dùng có quyền USER:UPDATE cập nhật thông tin thành công -> 200
     */
    public function test_user_with_user_update_can_update_user(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');
        [$targetUser] = $this->createUserWithRole('RESIDENT_OWNER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/users/{$targetUser->id}", [
                'full_name' => 'Updated Full Name',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'full_name' => 'Updated Full Name',
        ]);
    }

    /**
     * TEST 7: Người dùng không có quyền USER:DELETE gọi xóa user -> 403
     */
    public function test_user_without_user_delete_cannot_delete_user(): void
    {
        [$receptionist, $token] = $this->createUserWithRole('RECEPTIONIST');
        [$targetUser] = $this->createUserWithRole('RESIDENT_OWNER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/users/{$targetUser->id}");

        $response->assertStatus(403);
    }

    /**
     * TEST 8: SUPER_ADMIN có toàn quyền bypass trên tất cả API
     */
    public function test_super_admin_has_full_bypass_access(): void
    {
        [$admin, $token] = $this->createUserWithRole('SUPER_ADMIN');

        // Test xem danh sách người dùng
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/users')
            ->assertStatus(200);

        // Test xem danh sách vai trò
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/roles')
            ->assertStatus(200);

        // Test xem danh mục quyền hạn
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/permissions')
            ->assertStatus(200);
    }

    /**
     * TEST 9: Bảo vệ System Role: Không được phép xóa vai trò hệ thống
     */
    public function test_cannot_delete_system_role(): void
    {
        [$admin, $token] = $this->createUserWithRole('SUPER_ADMIN');
        $systemRole = Role::where('role_code', 'BUILDING_MANAGER')->first();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/roles/{$systemRole->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    /**
     * TEST 10: Chống tự nâng quyền (Self-protection): Người không phải SUPER_ADMIN không được gán vai trò SUPER_ADMIN
     */
    public function test_non_super_admin_cannot_assign_super_admin_role(): void
    {
        [$manager, $token] = $this->createUserWithRole('BUILDING_MANAGER');
        [$targetUser] = $this->createUserWithRole('RESIDENT_OWNER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/users/{$targetUser->id}/roles", [
                'roles' => ['SUPER_ADMIN'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['roles']);
    }

    /**
     * TEST 11: Người dùng không thể tự xóa tài khoản của chính mình
     */
    public function test_user_cannot_delete_themselves(): void
    {
        [$admin, $token] = $this->createUserWithRole('SUPER_ADMIN');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/users/{$admin->id}");

        $response->assertStatus(422);
    }

    /**
     * TEST 12: Đăng nhập thành công trả về đầy đủ permissions và role codes
     */
    public function test_login_returns_permissions_and_role_details(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => '123567',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'access_token',
                'token_type',
                'user' => [
                    'id',
                    'username',
                    'email',
                    'roles',
                    'permissions',
                    'is_super_admin',
                ],
            ]);

        $this->assertTrue($response->json('user.is_super_admin'));
    }

    /**
     * TEST 13: Cấu hình gán quyền cho vai trò (Role Permissions Sync)
     */
    public function test_can_sync_role_permissions(): void
    {
        [$admin, $token] = $this->createUserWithRole('SUPER_ADMIN');

        // Tạo role tùy chỉnh
        $role = Role::create([
            'role_code' => 'CUSTOM_TEST_ROLE_'.Str::random(4),
            'role_name' => 'Custom Role Test',
            'is_system_role' => false,
        ]);

        $perms = Permission::take(3)->pluck('id')->toArray();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/roles/{$role->id}/permissions", [
                'permissions' => $perms,
            ]);

        $response->assertStatus(200);

        $this->assertEquals(3, DB::table('role_permissions')->where('role_id', $role->id)->count());
    }
}
