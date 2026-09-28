<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacManagementTest extends TestCase
{
    /**
     * Tạo người dùng test kèm vai trò và token phiên đăng nhập
     */
    protected function createRbacUser(string $roleCode): array
    {
        $user = User::create([
            'username' => 'test_rbac_'.Str::random(8),
            'phone_number' => '08'.rand(10000000, 99999999),
            'email' => 'test_rbac_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'RBAC Tester '.$roleCode,
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
            'device_name' => 'RBAC Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * 1. Guest -> 401 Unauthorized
     */
    public function test_01_guest_access_returns_401(): void
    {
        $response = $this->getJson('/api/v1/rbac/roles');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'UNAUTHORIZED',
            ]);
    }

    /**
     * 2. User không có permission -> 403 Forbidden
     */
    public function test_02_user_without_permission_returns_403(): void
    {
        // RESIDENT_OWNER không có quyền ROLE:VIEW
        [$user, $token] = $this->createRbacUser('RESIDENT_OWNER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/rbac/roles');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);
    }

    /**
     * 3. User có permission -> success (200 OK)
     */
    public function test_03_user_with_permission_returns_200(): void
    {
        // BUILDING_MANAGER có quyền ROLE:VIEW và USER:VIEW
        [$user, $token] = $this->createRbacUser('BUILDING_MANAGER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/rbac/roles');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 4. SUPER_ADMIN -> full RBAC access
     */
    public function test_04_super_admin_has_full_rbac_access(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/rbac/users')
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/rbac/roles')
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/rbac/permissions')
            ->assertStatus(200);
    }

    /**
     * 5. Create role
     */
    public function test_05_can_create_role(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');
        $uniqueCode = 'TEST_ROLE_'.Str::upper(Str::random(6));

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rbac/roles', [
                'role_code' => $uniqueCode,
                'role_name' => 'Vai trò kiểm thử tự động',
                'description' => 'Mô tả vai trò kiểm thử',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('roles', [
            'role_code' => $uniqueCode,
            'is_system_role' => 0,
        ]);
    }

    /**
     * 6. Update role
     */
    public function test_06_can_update_role(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');

        $role = Role::create([
            'role_code' => 'TEST_UPDATE_'.Str::upper(Str::random(6)),
            'role_name' => 'Tên vai trò ban đầu',
            'description' => 'Mô tả ban đầu',
            'is_system_role' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/roles/{$role->id}", [
                'role_name' => 'Tên vai trò đã cập nhật',
                'description' => 'Mô tả đã được cập nhật',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'role_name' => 'Tên vai trò đã cập nhật',
        ]);
    }

    /**
     * 7. Delete role (Vai trò tùy chỉnh không có người dùng)
     */
    public function test_07_can_delete_custom_role(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');

        $role = Role::create([
            'role_code' => 'TEST_DEL_'.Str::upper(Str::random(6)),
            'role_name' => 'Vai trò cần xóa',
            'is_system_role' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/rbac/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
    }

    /**
     * 8. Cannot delete system role
     */
    public function test_08_cannot_delete_system_role(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');
        $systemRole = Role::where('role_code', 'SUPER_ADMIN')->first();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/rbac/roles/{$systemRole->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    /**
     * 9. Create permission
     */
    public function test_09_can_create_permission(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');
        $permCode = 'RBAC_TEST:'.Str::upper(Str::random(6));

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rbac/permissions', [
                'module' => 'RBAC_TEST',
                'permission_code' => $permCode,
                'permission_name' => 'Quyền kiểm thử tạo mới',
                'description' => 'Mô tả quyền kiểm thử',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('permissions', [
            'permission_code' => $permCode,
        ]);
    }

    /**
     * 10. Assign permission to role
     */
    public function test_10_can_assign_permission_to_role(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');

        $role = Role::create([
            'role_code' => 'TEST_ASSIGN_'.Str::upper(Str::random(6)),
            'role_name' => 'Vai trò test gán quyền',
            'is_system_role' => false,
        ]);

        $perm = Permission::first();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
                'permissions' => [$perm->permission_code],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $perm->id,
        ]);
    }

    /**
     * 11. Revoke permission from role
     */
    public function test_11_can_revoke_permission_from_role(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');

        $role = Role::create([
            'role_code' => 'TEST_REVOKE_'.Str::upper(Str::random(6)),
            'role_name' => 'Vai trò test thu hồi quyền',
            'is_system_role' => false,
        ]);

        $perm = Permission::first();
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $role->id,
            'permission_id' => $perm->id,
            'created_at' => now(),
        ]);

        // Gửi mảng rỗng để thu hồi toàn bộ quyền
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
                'permissions' => [],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $perm->id,
        ]);
    }

    /**
     * 12. Assign role to user
     */
    public function test_12_can_assign_role_to_user(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');
        [$targetUser] = $this->createRbacUser('RESIDENT_OWNER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/users/{$targetUser->id}/roles", [
                'roles' => ['ACCOUNTANT', 'RECEPTIONIST'],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $accountantRole = Role::where('role_code', 'ACCOUNTANT')->first();
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $targetUser->id,
            'role_id' => $accountantRole->id,
        ]);
    }

    /**
     * 13. Revoke role from user
     */
    public function test_13_can_revoke_role_from_user(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');
        [$targetUser] = $this->createRbacUser('ACCOUNTANT');

        // Gán sang vai trò RESIDENT_OWNER (gỡ ACCOUNTANT)
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/users/{$targetUser->id}/roles", [
                'roles' => ['RESIDENT_OWNER'],
            ]);

        $response->assertStatus(200);

        $accountantRole = Role::where('role_code', 'ACCOUNTANT')->first();
        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $targetUser->id,
            'role_id' => $accountantRole->id,
        ]);
    }

    /**
     * 14. Self privilege escalation -> Bị chặn (422 / 403)
     */
    public function test_14_self_privilege_escalation_is_prevented(): void
    {
        // BUILDING_MANAGER cố gắng gán vai trò SUPER_ADMIN cho một người dùng
        [$manager, $token] = $this->createRbacUser('BUILDING_MANAGER');
        [$targetUser] = $this->createRbacUser('RESIDENT_OWNER');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/users/{$targetUser->id}/roles", [
                'roles' => ['SUPER_ADMIN'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['roles']);
    }

    /**
     * 15. Duplicate role permission -> Không tạo duplicate
     */
    public function test_15_duplicate_role_permission_does_not_create_duplicates(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');

        $role = Role::create([
            'role_code' => 'TEST_DUP_'.Str::upper(Str::random(6)),
            'role_name' => 'Vai trò test trùng lặp quyền',
            'is_system_role' => false,
        ]);

        $perm = Permission::first();

        // Gửi danh sách có chứa permission trùng lặp
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
                'permissions' => [$perm->permission_code, $perm->permission_code, $perm->id],
            ]);

        $response->assertStatus(200);

        $count = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('permission_id', $perm->id)
            ->count();

        $this->assertEquals(1, $count);
    }
}
