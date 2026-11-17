<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class RbacManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

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

    /**
     * 16. Cấp, lưu và thu hồi quyền người đặc biệt (special) trong ma trận RBAC
     */
    public function test_16_can_assign_and_revoke_special_permission_in_matrix(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');

        $role = Role::create([
            'role_code' => 'TEST_SPECIAL_'.Str::upper(Str::random(6)),
            'role_name' => 'Vai trò kiểm thử người đặc biệt',
            'is_system_role' => false,
        ]);

        $specialPerm = Permission::firstOrCreate(
            ['permission_code' => 'SYSTEM:SPECIAL'],
            [
                'id' => (string) Str::uuid(),
                'module' => 'SYSTEM',
                'permission_name' => 'Người đặc biệt (special)',
                'description' => 'Quyền người đặc biệt với thẩm quyền quản trị đặc thù',
                'created_at' => now(),
            ]
        );

        // 1. Cấp quyền special cho vai trò
        $assignResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
                'permissions' => [$specialPerm->permission_code],
            ]);

        $assignResponse->assertStatus(200);

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $specialPerm->id,
        ]);

        // 2. Kiểm tra đọc lại quyền qua getRolePermissions
        $getResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/rbac/roles/{$role->id}/permissions");

        $getResponse->assertStatus(200);
        $permissions = collect($getResponse->json('data.permissions'));
        $this->assertTrue($permissions->contains('permission_code', 'SYSTEM:SPECIAL'));

        // 3. Kiểm tra User gắn vai trò có quyền special
        [$testUser] = $this->createRbacUser($role->role_code);
        $this->assertTrue($testUser->hasPermission('special'));
        $this->assertTrue($testUser->hasPermission('SYSTEM:SPECIAL'));

        // 4. Thu hồi quyền special
        $revokeResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
                'permissions' => [],
            ]);

        $revokeResponse->assertStatus(200);
        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $specialPerm->id,
        ]);

        // Reset cache quyền của test user
        $testUser = User::find($testUser->id);
        $this->assertFalse($testUser->hasPermission('special'));
    }

    /**
     * 17. Ma trận phân quyền hỗ trợ đầy đủ 7 quyền (create, delete, edit, download, upload, view, special)
     */
    public function test_17_matrix_preserves_six_core_permissions_and_supports_special(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');

        $role = Role::create([
            'role_code' => 'TEST_7PERMS_'.Str::upper(Str::random(6)),
            'role_name' => 'Vai trò kiểm thử 7 quyền',
            'is_system_role' => false,
        ]);

        $coreCodes = [
            'USER:CREATE',    // 1. Thêm (create)
            'USER:DELETE',    // 2. Xóa (delete)
            'USER:UPDATE',    // 3. Sửa (edit)
            'REPORT:EXPORT',  // 4. Tải về (download)
            'USER:UPLOAD',    // 5. Tải lên (upload)
            'USER:VIEW',      // 6. Xem (view)
            'SYSTEM:SPECIAL', // 7. Người đặc biệt (special)
        ];

        // Đảm bảo các quyền tồn tại
        foreach ($coreCodes as $code) {
            $parts = explode(':', $code);
            Permission::firstOrCreate(
                ['permission_code' => $code],
                [
                    'id' => (string) Str::uuid(),
                    'module' => $parts[0],
                    'permission_name' => 'Quyền '.$code,
                    'created_at' => now(),
                ]
            );
        }

        // Cấp đồng thời cả 7 quyền
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/roles/{$role->id}/permissions", [
                'permissions' => $coreCodes,
            ]);

        $response->assertStatus(200);

        // Xác nhận cả 7 quyền đều được lưu chính xác trong database
        foreach ($coreCodes as $code) {
            $perm = Permission::where('permission_code', $code)->first();
            $this->assertDatabaseHas('role_permissions', [
                'role_id' => $role->id,
                'permission_id' => $perm->id,
            ]);
        }
    }

    /**
     * Test 18: Phân quyền riêng cho từng tài khoản người dùng theo ma trận 7 quyền
     */
    public function test_18_can_assign_and_retrieve_user_specific_permissions_in_matrix(): void
    {
        [$admin, $token] = $this->createRbacUser('SUPER_ADMIN');
        [$targetUser] = $this->createRbacUser('RESIDENT');

        // 1. Kiểm tra lấy quyền ban đầu của người dùng (chưa tùy chỉnh)
        $initialRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/rbac/users/{$targetUser->id}/permissions");

        $initialRes->assertStatus(200);
        $this->assertFalse($initialRes->json('data.is_customized'));

        // 2. Cấu hình phân quyền riêng cho tài khoản theo ma trận 7 quyền
        $customPerms = [
            'USER:CREATE',
            'USER:DELETE',
            'USER:UPDATE',
            'REPORT:EXPORT',
            'USER:UPLOAD',
            'USER:VIEW',
            'SYSTEM:SPECIAL',
        ];

        foreach ($customPerms as $code) {
            $parts = explode(':', $code);
            Permission::firstOrCreate(
                ['permission_code' => $code],
                [
                    'id' => (string) Str::uuid(),
                    'module' => $parts[0],
                    'permission_name' => 'Quyền '.$code,
                    'created_at' => now(),
                ]
            );
        }

        $syncRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/rbac/users/{$targetUser->id}/permissions", [
                'permissions' => $customPerms,
            ]);

        $syncRes->assertStatus(200);
        $this->assertTrue($syncRes->json('data.is_customized'));

        // 3. Tải lại quyền hạn của tài khoản
        $getRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/rbac/users/{$targetUser->id}/permissions");

        $getRes->assertStatus(200);
        $this->assertTrue($getRes->json('data.is_customized'));
        $retrievedPerms = $getRes->json('data.permissions');

        foreach ($customPerms as $code) {
            $this->assertContains($code, $retrievedPerms);
        }

        // Kiểm tra thực tế trên User model
        $freshUser = User::find($targetUser->id);
        $this->assertTrue($freshUser->hasPermission('SYSTEM:SPECIAL'));
        $this->assertTrue($freshUser->hasPermission('special'));
        $this->assertTrue($freshUser->hasPermission('USER:CREATE'));

        // 4. Khôi phục quyền mặc định theo vai trò cho tài khoản
        $resetRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/rbac/users/{$targetUser->id}/permissions");

        $resetRes->assertStatus(200);
        $this->assertFalse($resetRes->json('data.is_customized'));

        $freshUserAfterReset = User::find($targetUser->id);
        $this->assertFalse($freshUserAfterReset->hasPermission('SYSTEM:SPECIAL'));
    }

    /**
     * Helper: tạo người dùng ACTIVE với tập permission cụ thể qua một custom role sạch.
     *
     * @param  array<int, string>  $permissionCodes
     * @return array{0: User, 1: string, 2: Role}
     */
    protected function createUserWithCustomPermissions(array $permissionCodes, ?string $rolePrefix = 'TEST_M102_'): array
    {
        $role = Role::create([
            'role_code' => $rolePrefix.Str::upper(Str::random(6)),
            'role_name' => 'Custom Test Role '.$rolePrefix,
            'is_system_role' => false,
        ]);

        foreach ($permissionCodes as $code) {
            $parts = explode(':', $code);
            $perm = Permission::firstOrCreate(
                ['permission_code' => $code],
                [
                    'module' => $parts[0] ?: 'SYSTEM',
                    'permission_name' => 'Quyền '.$code,
                    'created_at' => now(),
                ]
            );

            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $role->id,
                'permission_id' => $perm->id,
                'created_at' => now(),
            ]);
        }

        $user = User::create([
            'username' => 'rbac_m102_'.Str::random(8),
            'phone_number' => '08'.random_int(10000000, 99999999),
            'email' => 'rbac_m102_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'RBAC M1-02 Tester',
            'status' => 'ACTIVE',
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'RBAC Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token, $role];
    }

    /**
     * Test 19 (BUG-M1-02): Người có USER:UPDATE nhưng KHÔNG có ROLE:ASSIGN_PERMISSION không thể gán hoặc tự gán permission.
     */
    public function test_19_user_with_user_update_only_cannot_assign_or_self_assign_permissions(): void
    {
        [$manager, $managerToken] = $this->createRbacUser('BUILDING_MANAGER');
        [$updateOnlyUser, $updateOnlyToken] = $this->createUserWithCustomPermissions(['USER:VIEW', 'USER:UPDATE']);
        [$targetUser] = $this->createRbacUser('RESIDENT_OWNER');

        $beforeCustomRoles = Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count();
        $beforeUserRoles = DB::table('user_roles')->count();
        $beforeRolePerms = DB::table('role_permissions')->count();

        foreach ([
            ['user' => $manager, 'token' => $managerToken],
            ['user' => $updateOnlyUser, 'token' => $updateOnlyToken],
        ] as $actorCase) {
            $actor = $actorCase['user'];
            $headers = ['Authorization' => 'Bearer '.$actorCase['token']];

            // Tự cấp quyền cho chính mình trên cả /users, /admin/users, /rbac/users -> 403
            foreach ([
                "/api/v1/users/{$actor->id}/permissions",
                "/api/v1/admin/users/{$actor->id}/permissions",
                "/api/v1/rbac/users/{$actor->id}/permissions",
            ] as $endpoint) {
                $this->withHeaders($headers)
                    ->putJson($endpoint, ['permissions' => ['SYSTEM:SPECIAL', 'ROLE:ASSIGN_PERMISSION', 'USER:DELETE']])
                    ->assertStatus(403)
                    ->assertJsonPath('error', 'FORBIDDEN');

                $this->withHeaders($headers)
                    ->putJson($endpoint, ['permissions' => ['USER:VIEW']])
                    ->assertStatus(403)
                    ->assertJsonPath('error', 'FORBIDDEN');

                $this->withHeaders($headers)
                    ->deleteJson($endpoint)
                    ->assertStatus(403)
                    ->assertJsonPath('error', 'FORBIDDEN');
            }

            // Cấp quyền cho user khác khi không có ROLE:ASSIGN_PERMISSION -> 403
            $this->withHeaders($headers)
                ->putJson("/api/v1/users/{$targetUser->id}/permissions", ['permissions' => ['USER:VIEW']])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($headers)
                ->deleteJson("/api/v1/users/{$targetUser->id}/permissions")
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');
        }

        // Người chỉ có USER:UPDATE (không có USER:ASSIGN_ROLE) cũng không được gán/đổi role qua assignRoles hoặc update
        $this->withHeader('Authorization', 'Bearer '.$updateOnlyToken)
            ->putJson("/api/v1/users/{$updateOnlyUser->id}/roles", ['roles' => ['BUILDING_MANAGER']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeader('Authorization', 'Bearer '.$updateOnlyToken)
            ->putJson("/api/v1/users/{$targetUser->id}/roles", ['roles' => ['RECEPTIONIST']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeader('Authorization', 'Bearer '.$updateOnlyToken)
            ->putJson("/api/v1/users/{$updateOnlyUser->id}", ['roles' => ['BUILDING_MANAGER']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->assertSame($beforeCustomRoles, Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count());
        $this->assertSame($beforeUserRoles, DB::table('user_roles')->count());
        $this->assertSame($beforeRolePerms, DB::table('role_permissions')->count());
        $this->assertFalse(User::findOrFail($manager->id)->hasPermission('SYSTEM:SPECIAL'));
        $this->assertFalse(User::findOrFail($updateOnlyUser->id)->hasPermission('ROLE:ASSIGN_PERMISSION'));
    }

    /**
     * Test 20 (BUG-M1-02): Người dùng thông thường không có ROLE:ASSIGN_PERMISSION không thể gọi các endpoint phân quyền.
     */
    public function test_20_regular_user_without_role_assign_permission_cannot_assign_permissions(): void
    {
        [$resident, $residentToken] = $this->createRbacUser('RESIDENT_OWNER');
        [$receptionist, $receptionistToken] = $this->createRbacUser('RECEPTIONIST');
        [$targetUser] = $this->createRbacUser('RESIDENT_MEMBER');

        $beforeCustomRoles = Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count();

        foreach ([$residentToken, $receptionistToken] as $token) {
            $headers = ['Authorization' => 'Bearer '.$token];

            $this->withHeaders($headers)
                ->putJson("/api/v1/users/{$targetUser->id}/permissions", ['permissions' => ['USER:VIEW']])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($headers)
                ->patchJson("/api/v1/rbac/users/{$resident->id}/permissions", ['permissions' => ['SYSTEM:SPECIAL']])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeaders($headers)
                ->deleteJson("/api/v1/users/{$targetUser->id}/permissions")
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');
        }

        $this->assertSame($beforeCustomRoles, Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count());
    }

    /**
     * Test 21 (BUG-M1-02): Ngay cả người có ROLE:ASSIGN_PERMISSION cũng không thể tự gán quyền hoặc cấp quyền vượt quá phạm vi bản thân / SPECIAL.
     */
    public function test_21_delegated_assigner_cannot_self_assign_or_grant_unowned_or_special_permissions(): void
    {
        Permission::firstOrCreate(
            ['permission_code' => 'SYSTEM:SPECIAL'],
            ['module' => 'SYSTEM', 'permission_name' => 'SYSTEM:SPECIAL', 'created_at' => now()]
        );
        Permission::firstOrCreate(
            ['permission_code' => 'SPECIAL'],
            ['module' => 'SYSTEM', 'permission_name' => 'SPECIAL', 'created_at' => now()]
        );

        $systemSpecialPerm = Permission::where('permission_code', 'SYSTEM:SPECIAL')->firstOrFail();
        $specialAliasPerm = Permission::where('permission_code', 'SPECIAL')->firstOrFail();
        $unownedApprovePerm = Permission::where('permission_code', 'INVOICE:APPROVE')->firstOrFail();

        [$delegated, $delegatedToken] = $this->createUserWithCustomPermissions([
            'ROLE:ASSIGN_PERMISSION',
            'USER:VIEW',
            'USER:UPDATE',
            'TICKET:VIEW',
            'TICKET:UPDATE',
            // Thử tình huống non-SA có sẵn USER:SPECIAL vẫn bị cấm cấp SPECIAL
            'USER:SPECIAL',
        ]);
        [$targetUser] = $this->createRbacUser('RESIDENT_OWNER');
        $headers = ['Authorization' => 'Bearer '.$delegatedToken];

        $beforeCustomRoles = Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count();
        $beforeUserRoles = DB::table('user_roles')->count();
        $beforeRolePerms = DB::table('role_permissions')->count();

        // 1. Không được tự cấp quyền cho chính mình (kể cả quyền thường bản thân đang có)
        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$delegated->id}/permissions", ['permissions' => ['USER:VIEW', 'TICKET:VIEW']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/users/{$delegated->id}/permissions")
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        // 2. Không được cấp SPECIAL / SYSTEM:SPECIAL / *:SPECIAL / SYSTEM:* / ROLE:* / PERMISSION:* / USER:DELETE (kể cả bằng UUID hoặc lowercase)
        foreach ([
            ['SPECIAL'],
            ['special'],
            ['SYSTEM:SPECIAL'],
            ['system:special'],
            ['USER:SPECIAL'],
            ['SYSTEM:CREATE'],
            ['ROLE:ASSIGN_PERMISSION'],
            ['PERMISSION:CREATE'],
            ['USER:DELETE'],
            [$systemSpecialPerm->id],
            [$specialAliasPerm->id],
        ] as $privilegedList) {
            $this->withHeaders($headers)
                ->putJson("/api/v1/users/{$targetUser->id}/permissions", ['permissions' => $privilegedList])
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');
        }

        // 3. Không được cấp permission mà bản thân không sở hữu (code hoặc UUID)
        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", ['permissions' => ['USER:VIEW', 'INVOICE:APPROVE']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", ['permissions' => [$unownedApprovePerm->id]])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        // Xác nhận 0 thay đổi DB sau các request bị từ chối
        $this->assertSame($beforeCustomRoles, Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count());
        $this->assertSame($beforeUserRoles, DB::table('user_roles')->count());
        $this->assertSame($beforeRolePerms, DB::table('role_permissions')->count());
        $this->assertFalse(User::findOrFail($targetUser->id)->hasPermission('SYSTEM:SPECIAL'));
        $this->assertFalse(User::findOrFail($targetUser->id)->hasPermission('INVOICE:APPROVE'));

        // 4. Nghiệp vụ hợp lệ: cấp tập quyền thường bản thân đang sở hữu cho tài khoản khác vẫn thành công
        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", ['permissions' => ['USER:VIEW', 'TICKET:VIEW']])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $freshTarget = User::findOrFail($targetUser->id);
        $this->assertTrue($freshTarget->hasPermission('USER:VIEW'));
        $this->assertTrue($freshTarget->hasPermission('TICKET:VIEW'));
        $this->assertFalse($freshTarget->hasPermission('SYSTEM:SPECIAL'));
    }

    /**
     * Test 22 (BUG-M1-02): Người bị giới hạn bởi CUSTOM_USER_* không thể lợi dụng reset hoặc tự nâng quyền, và non-SA không thể reset để mở khóa quyền cao hơn.
     */
    public function test_22_non_super_admin_cannot_bypass_custom_user_jail_or_reset_higher_base_roles(): void
    {
        [$delegated, $delegatedToken] = $this->createUserWithCustomPermissions([
            'ROLE:ASSIGN_PERMISSION',
            'USER:VIEW',
        ]);
        [$managerTarget] = $this->createRbacUser('BUILDING_MANAGER');
        [$superAdmin] = $this->createRbacUser('SUPER_ADMIN');

        // SUPER_ADMIN giới hạn $managerTarget chỉ còn USER:VIEW qua CUSTOM_USER_*
        $superToken = $this->issueTestBearerToken($superAdmin);
        $this->withHeader('Authorization', 'Bearer '.$superToken)
            ->putJson("/api/v1/users/{$managerTarget->id}/permissions", ['permissions' => ['USER:VIEW']])
            ->assertStatus(200);

        $beforeCustomCount = Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count();

        // $delegated chỉ có ROLE:ASSIGN_PERMISSION + USER:VIEW, không có USER:CREATE của BUILDING_MANAGER -> không thể DELETE reset mở lại quyền BUILDING_MANAGER cho $managerTarget
        $this->withHeader('Authorization', 'Bearer '.$delegatedToken)
            ->deleteJson("/api/v1/users/{$managerTarget->id}/permissions")
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->assertSame($beforeCustomCount, Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count());
        $this->assertFalse(User::findOrFail($managerTarget->id)->hasPermission('USER:CREATE'));
    }

    /**
     * Test 23 (BUG-M1-02): Người không phải SUPER_ADMIN không thể sửa permission, role, thông tin hay xóa tài khoản SUPER_ADMIN.
     */
    public function test_23_non_super_admin_cannot_modify_permissions_roles_or_profile_of_super_admin(): void
    {
        [$superAdmin] = $this->createRbacUser('SUPER_ADMIN');
        [$privilegedStaff, $staffToken] = $this->createUserWithCustomPermissions([
            'ROLE:ASSIGN_PERMISSION',
            'USER:VIEW',
            'USER:UPDATE',
            'USER:ASSIGN_ROLE',
            'USER:DELETE',
            'TICKET:VIEW',
        ]);

        $headers = ['Authorization' => 'Bearer '.$staffToken];
        $beforeUserRoles = DB::table('user_roles')->where('user_id', $superAdmin->id)->pluck('role_id')->all();

        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$superAdmin->id}/permissions", ['permissions' => ['USER:VIEW']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeaders($headers)
            ->putJson("/api/v1/rbac/users/{$superAdmin->id}/permissions", ['permissions' => ['TICKET:VIEW']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/users/{$superAdmin->id}/permissions")
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$superAdmin->id}/roles", ['roles' => ['RESIDENT_OWNER']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$superAdmin->id}", [
                'full_name' => 'Tampered SA',
                'password' => 'HackPass123',
                'status' => 'LOCKED',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/users/{$superAdmin->id}")
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $freshSa = User::findOrFail($superAdmin->id);
        $this->assertEquals('ACTIVE', $freshSa->status);
        $this->assertTrue($freshSa->isSuperAdmin());
        $this->assertSame($beforeUserRoles, DB::table('user_roles')->where('user_id', $superAdmin->id)->pluck('role_id')->all());
    }

    /**
     * Test 24 (BUG-M1-02): Chặn leo thang qua store/update/assignRoles và payload giả mạo permission/role.
     */
    public function test_24_spoofed_privilege_fields_and_escalated_role_assignments_are_rejected(): void
    {
        [$superAdmin, $superToken] = $this->createRbacUser('SUPER_ADMIN');
        [$manager, $managerToken] = $this->createRbacUser('BUILDING_MANAGER');
        [$delegated, $delegatedToken] = $this->createUserWithCustomPermissions([
            'ROLE:ASSIGN_PERMISSION',
            'USER:VIEW',
            'USER:CREATE',
            'USER:UPDATE',
            'USER:ASSIGN_ROLE',
        ]);
        [$targetUser] = $this->createRbacUser('RESIDENT_OWNER');
        [,,, $specialRole] = (function () {
            [$u, $t, $r] = $this->createUserWithCustomPermissions(['SYSTEM:SPECIAL'], 'SPEC_ROLE_');

            return [$u, $t, $r, $r];
        })();

        $beforeUsersCount = User::withTrashed()->count();
        $beforeCustomCount = Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count();

        // 1. Giả mạo field đặc quyền vào /permissions, /roles, store, update, reset đều bị 403
        foreach ([
            ['roles' => ['SUPER_ADMIN']],
            ['role' => 'SUPER_ADMIN'],
            ['role_code' => 'SUPER_ADMIN'],
            ['is_super_admin' => true],
            ['custom_role' => 'CUSTOM_USER_HACK'],
            ['bypass_rbac' => true],
        ] as $spoof) {
            $this->withHeader('Authorization', 'Bearer '.$delegatedToken)
                ->putJson("/api/v1/users/{$targetUser->id}/permissions", array_merge(['permissions' => ['USER:VIEW']], $spoof))
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeader('Authorization', 'Bearer '.$superToken)
                ->putJson("/api/v1/users/{$targetUser->id}/permissions", array_merge(['permissions' => ['USER:VIEW']], $spoof))
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');

            $this->withHeader('Authorization', 'Bearer '.$delegatedToken)
                ->deleteJson("/api/v1/users/{$targetUser->id}/permissions", $spoof)
                ->assertStatus(403)
                ->assertJsonPath('error', 'FORBIDDEN');
        }

        // 2. Giả mạo permissions trong store / update / assignRoles -> 403
        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/users', [
                'username' => 'spoof_store_'.Str::random(6),
                'phone_number' => '08'.random_int(10000000, 99999999),
                'email' => 'spoof_'.Str::random(6).'@cassavas.vn',
                'full_name' => 'Spoof Store',
                'password' => 'Secret@123',
                'permissions' => ['SYSTEM:SPECIAL'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->putJson("/api/v1/users/{$targetUser->id}", [
                'full_name' => 'Spoof Update',
                'permissions' => ['SYSTEM:SPECIAL'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        // 3. Không được gán role chứa SYSTEM:SPECIAL hoặc tự gán role qua update/assignRoles
        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->putJson("/api/v1/users/{$manager->id}/roles", ['roles' => ['SUPER_ADMIN']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->putJson("/api/v1/users/{$targetUser->id}/roles", ['roles' => [$specialRole->role_code]])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->putJson("/api/v1/users/{$targetUser->id}", ['roles' => [$specialRole->role_code]])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/users', [
                'username' => 'esc_role_'.Str::random(6),
                'phone_number' => '08'.random_int(10000000, 99999999),
                'email' => 'esc_'.Str::random(6).'@cassavas.vn',
                'full_name' => 'Escalated Role User',
                'password' => 'Secret@123',
                'roles' => [$specialRole->role_code],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        // 4. Permission giả mạo không tồn tại trong hệ thống bị từ chối
        $this->withHeader('Authorization', 'Bearer '.$delegatedToken)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", ['permissions' => ['NON_EXISTENT:PERM']])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeader('Authorization', 'Bearer '.$superToken)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", ['permissions' => ['NON_EXISTENT:PERM']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['permissions']);

        $this->assertSame($beforeUsersCount, User::withTrashed()->count());
        $this->assertSame($beforeCustomCount, Role::where('role_code', 'LIKE', 'CUSTOM_USER_%')->count());
        $this->assertFalse(User::findOrFail($targetUser->id)->hasPermission('SYSTEM:SPECIAL'));
    }

    /**
     * Test 25 (BUG-M1-02): SUPER_ADMIN vẫn thực hiện đầy đủ thao tác quản trị permission/role hợp lệ trên hệ thống.
     */
    public function test_25_super_admin_retains_full_permission_and_role_management_capabilities(): void
    {
        [$superAdmin, $superToken] = $this->createRbacUser('SUPER_ADMIN');
        [$targetUser] = $this->createRbacUser('RESIDENT_OWNER');
        $headers = ['Authorization' => 'Bearer '.$superToken];

        Permission::firstOrCreate(
            ['permission_code' => 'SYSTEM:SPECIAL'],
            ['module' => 'SYSTEM', 'permission_name' => 'SYSTEM:SPECIAL', 'created_at' => now()]
        );

        // SUPER_ADMIN có thể gán quyền đặc biệt & xóa qua DELETE /permissions
        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", [
                'permissions' => ['USER:VIEW', 'SYSTEM:SPECIAL'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_customized', true);

        $fresh = User::findOrFail($targetUser->id);
        $this->assertTrue($fresh->hasPermission('SYSTEM:SPECIAL'));
        $this->assertTrue($fresh->hasPermission('USER:VIEW'));

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/users/{$targetUser->id}/permissions")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_customized', false);

        $this->assertFalse(User::findOrFail($targetUser->id)->hasPermission('SYSTEM:SPECIAL'));

        // SUPER_ADMIN vẫn gán được vai trò cho người dùng khác qua /users/{id}/roles
        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$targetUser->id}/roles", ['roles' => ['BUILDING_MANAGER']])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * Test 26 (BUG-M1-03): Reset quyền (soft-delete CUSTOM_USER_*) rồi cấp lại quyền nhiều lần phải restore đúng bản ghi cũ và không gây lỗi Duplicate Entry.
     */
    public function test_26_reset_and_resync_user_permissions_restores_soft_deleted_custom_role_without_duplicate_entry(): void
    {
        [, $superToken] = $this->createRbacUser('SUPER_ADMIN');
        [$targetUser] = $this->createRbacUser('RESIDENT_OWNER');
        $headers = ['Authorization' => 'Bearer '.$superToken];
        $customRoleCode = 'CUSTOM_USER_'.substr(str_replace('-', '', $targetUser->id), 0, 12);
        $targetPermissions = ['USER:VIEW', 'VEHICLE:VIEW'];

        // 1. Cấp phân quyền riêng lần đầu
        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", [
                'permissions' => $targetPermissions,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_customized', true);

        $firstRole = Role::withTrashed()->where('role_code', $customRoleCode)->first();
        $this->assertNotNull($firstRole);
        $this->assertFalse($firstRole->trashed());
        $originalRoleId = $firstRole->id;
        $this->assertSame(1, Role::withTrashed()->where('role_code', $customRoleCode)->count());

        $permsAfterFirstAssign = User::findOrFail($targetUser->id)->getAllPermissions();
        $this->assertContains('USER:VIEW', $permsAfterFirstAssign);
        $this->assertContains('VEHICLE:VIEW', $permsAfterFirstAssign);

        // 2. Reset quyền lần 1 -> custom role bị soft-delete
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/users/{$targetUser->id}/permissions")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_customized', false);

        $this->assertNull(Role::where('role_code', $customRoleCode)->first());
        $trashedRole = Role::withTrashed()->where('role_code', $customRoleCode)->first();
        $this->assertNotNull($trashedRole);
        $this->assertTrue($trashedRole->trashed());
        $this->assertNotNull($trashedRole->deleted_at);

        // 3. Cấp lại cùng tập quyền -> restore đúng bản ghi cũ, không crash 500 Duplicate entry
        $resyncOne = $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", [
                'permissions' => $targetPermissions,
            ]);

        $resyncOne->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_customized', true);

        $this->assertSame(1, Role::withTrashed()->where('role_code', $customRoleCode)->count());
        $restoredRole = Role::withTrashed()->where('role_code', $customRoleCode)->first();
        $this->assertNotNull($restoredRole);
        $this->assertSame($originalRoleId, $restoredRole->id);
        $this->assertFalse($restoredRole->trashed());
        $this->assertNull($restoredRole->deleted_at);

        $permsAfterResyncOne = User::findOrFail($targetUser->id)->getAllPermissions();
        $this->assertContains('USER:VIEW', $permsAfterResyncOne);
        $this->assertContains('VEHICLE:VIEW', $permsAfterResyncOne);

        // 4. Lặp lại chu kỳ reset lần 2 và cấp lại lần 2 để bảo đảm ổn định
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/users/{$targetUser->id}/permissions")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_customized', false);

        $secondTrashed = Role::withTrashed()->where('role_code', $customRoleCode)->first();
        $this->assertNotNull($secondTrashed);
        $this->assertTrue($secondTrashed->trashed());

        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", [
                'permissions' => ['USER:VIEW', 'VEHICLE:VIEW'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_customized', true);

        $this->assertSame(1, Role::withTrashed()->where('role_code', $customRoleCode)->count());
        $restoredSecond = Role::withTrashed()->where('role_code', $customRoleCode)->first();
        $this->assertNotNull($restoredSecond);
        $this->assertSame($originalRoleId, $restoredSecond->id);
        $this->assertNull($restoredSecond->deleted_at);

        $permsAfterResyncTwo = User::findOrFail($targetUser->id)->getAllPermissions();
        $this->assertContains('USER:VIEW', $permsAfterResyncTwo);
        $this->assertContains('VEHICLE:VIEW', $permsAfterResyncTwo);
    }

    /**
     * Test 27 (BUG-M1-03 + BUG-M1-02 regression): Reset/restore custom role không ảnh hưởng user khác và vẫn giữ nguyên rào chắn phân quyền của BUG-M1-02.
     */
    public function test_27_custom_role_restore_isolates_other_users_and_preserves_m1_02_privilege_guards(): void
    {
        [$delegated, $delegatedToken] = $this->createUserWithCustomPermissions([
            'ROLE:ASSIGN_PERMISSION',
            'USER:VIEW',
            'VEHICLE:VIEW',
        ]);
        [$targetUser] = $this->createRbacUser('NO_ROLE');
        [$bystanderUser] = $this->createUserWithCustomPermissions(['TICKET:VIEW'], 'BYSTANDER_');
        $delegatedHeaders = ['Authorization' => 'Bearer '.$delegatedToken];

        // 1. $delegated cấp custom permissions cho $targetUser, reset, rồi cấp lại hợp lệ
        $this->withHeaders($delegatedHeaders)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", [
                'permissions' => ['USER:VIEW'],
            ])
            ->assertStatus(200);

        $this->withHeaders($delegatedHeaders)
            ->deleteJson("/api/v1/users/{$targetUser->id}/permissions")
            ->assertStatus(200);

        // Không thể lợi dụng sau khi target đang soft-delete custom role để cấp SYSTEM:SPECIAL vượt phạm vi hoặc tự cấp cho bản thân
        $this->withHeaders($delegatedHeaders)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", [
                'permissions' => ['SYSTEM:SPECIAL'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->withHeaders($delegatedHeaders)
            ->putJson("/api/v1/users/{$delegated->id}/permissions", [
                'permissions' => ['USER:VIEW', 'VEHICLE:VIEW'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'FORBIDDEN');

        // Cấp lại quyền hợp lệ trong phạm vi cho $targetUser -> thành công và restore custom role
        $this->withHeaders($delegatedHeaders)
            ->putJson("/api/v1/users/{$targetUser->id}/permissions", [
                'permissions' => ['USER:VIEW', 'VEHICLE:VIEW'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_customized', true);

        $customRoleCode = 'CUSTOM_USER_'.substr(str_replace('-', '', $targetUser->id), 0, 12);
        $this->assertSame(1, Role::withTrashed()->where('role_code', $customRoleCode)->count());
        $this->assertTrue(User::findOrFail($targetUser->id)->hasPermission('VEHICLE:VIEW'));

        // Quyền của user khác ($bystanderUser) không bị thay đổi
        $bystanderPerms = User::findOrFail($bystanderUser->id)->getAllPermissions();
        $this->assertSame(['TICKET:VIEW'], $bystanderPerms);
    }

    /**
     * Test 28 (BUG-M1-05): Role đã soft-delete không được rò rỉ quyền qua User::getAllPermissions()/hasPermission()/hasRole() và cache được làm mới đúng cách.
     */
    public function test_28_active_and_soft_deleted_role_permissions_and_cache_invalidation(): void
    {
        [$targetUser] = $this->createRbacUser('NO_ROLE');

        $activePerm = Permission::firstOrCreate(
            ['permission_code' => 'VEHICLE:DELETE'],
            ['module' => 'VEHICLE', 'permission_name' => 'VEHICLE:DELETE', 'created_at' => now()]
        );
        $deletedRoleOnlyPerm = Permission::firstOrCreate(
            ['permission_code' => 'INVOICE:APPROVE'],
            ['module' => 'INVOICE', 'permission_name' => 'INVOICE:APPROVE', 'created_at' => now()]
        );

        $activeRoleCode = 'M105_ACTIVE_'.Str::upper(Str::random(6));
        $activeRole = Role::create([
            'role_code' => $activeRoleCode,
            'role_name' => 'M105 Active Role',
            'is_system_role' => false,
        ]);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $activeRole->id,
            'permission_id' => $activePerm->id,
            'created_at' => now(),
        ]);

        $deletedRoleCode = 'M105_TRASH_'.Str::upper(Str::random(6));
        $deletedRole = Role::create([
            'role_code' => $deletedRoleCode,
            'role_name' => 'M105 Soft-Deleted Role',
            'is_system_role' => false,
        ]);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $deletedRole->id,
            'permission_id' => $deletedRoleOnlyPerm->id,
            'created_at' => now(),
        ]);

        // Gán cả 2 role cho cùng user
        foreach ([$activeRole, $deletedRole] as $idx => $role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $targetUser->id,
                'role_id' => $role->id,
                'is_primary' => $idx === 0 ? 1 : 0,
                'assigned_at' => now(),
            ]);
        }

        // 1. Khi cả 2 role đang hoạt động, user nhận đủ cả 2 quyền và nạp cache in-memory
        $permsBeforeDelete = $targetUser->getAllPermissions();
        $this->assertContains('VEHICLE:DELETE', $permsBeforeDelete);
        $this->assertContains('INVOICE:APPROVE', $permsBeforeDelete);
        $this->assertTrue($targetUser->hasPermission('VEHICLE:DELETE'));
        $this->assertTrue($targetUser->hasPermission('INVOICE:APPROVE'));
        $this->assertTrue($targetUser->hasRole($deletedRoleCode));

        // 2. Soft-delete role thứ hai nhưng vẫn giữ nguyên liên kết user_roles & role_permissions trong DB
        $deletedRole->delete();
        $this->assertSoftDeleted('roles', ['id' => $deletedRole->id]);

        // Khi chưa clear cache, bản ghi cache in-memory vẫn giữ snapshot cũ
        $this->assertContains('INVOICE:APPROVE', $targetUser->getAllPermissions());

        // Sau khi clearPermissionCache() và làm mới quan hệ roles, quyền từ role đã soft-delete phải biến mất
        $targetUser->clearPermissionCache();
        $targetUser->unsetRelation('roles');

        $permsAfterClear = $targetUser->getAllPermissions();
        $this->assertContains('VEHICLE:DELETE', $permsAfterClear);
        $this->assertNotContains('INVOICE:APPROVE', $permsAfterClear);
        $this->assertTrue($targetUser->hasPermission('VEHICLE:DELETE'));
        $this->assertFalse($targetUser->hasPermission('INVOICE:APPROVE'));
        $this->assertTrue($targetUser->hasRole($activeRoleCode));
        $this->assertFalse($targetUser->hasRole($deletedRoleCode));

        // Kiểm tra thêm với instance User mới truy vấn từ CSDL
        $freshUser = User::findOrFail($targetUser->id);
        $this->assertSame(['VEHICLE:DELETE'], $freshUser->getAllPermissions());
        $this->assertFalse($freshUser->hasPermission('INVOICE:APPROVE'));
        $this->assertFalse($freshUser->hasRole($deletedRoleCode));
    }

    /**
     * Test 29 (BUG-M1-05): RbacService::hasPermission() fallback stdClass đồng bộ với User về lọc soft-deleted role, precedence CUSTOM_USER_* và quy tắc SPECIAL.
     */
    public function test_29_rbac_service_stdclass_fallback_respects_soft_deleted_roles_custom_user_precedence_and_special_rules(): void
    {
        foreach (['SPECIAL', 'SYSTEM:SPECIAL', 'USER:SPECIAL', 'VEHICLE:SPECIAL', 'TICKET:UPDATE', 'INVOICE:APPROVE', 'VEHICLE:APPROVE'] as $code) {
            Permission::firstOrCreate(
                ['permission_code' => $code],
                ['module' => explode(':', $code)[0], 'permission_name' => $code, 'created_at' => now()]
            );
        }

        // A. Fallback stdClass: role active cấp quyền, soft-delete role lập tức mất quyền (kể cả khi user_roles vẫn còn)
        [$fallbackUser] = $this->createRbacUser('NO_ROLE');
        $tempRole = Role::create([
            'role_code' => 'M105_STD_'.Str::upper(Str::random(6)),
            'role_name' => 'M105 Fallback Role',
            'is_system_role' => false,
        ]);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $tempRole->id,
            'permission_id' => Permission::where('permission_code', 'TICKET:UPDATE')->firstOrFail()->id,
            'created_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $fallbackUser->id,
            'role_id' => $tempRole->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $stdFallbackUser = (object) ['id' => $fallbackUser->id, 'status' => 'ACTIVE'];
        $this->assertTrue(RbacService::hasPermission($stdFallbackUser, 'TICKET:UPDATE'));
        $this->assertTrue(RbacService::hasPermission($stdFallbackUser, ' ticket:update '));

        $tempRole->delete();
        $this->assertFalse(RbacService::hasPermission($stdFallbackUser, 'TICKET:UPDATE'));
        $this->assertFalse(User::findOrFail($fallbackUser->id)->hasPermission('TICKET:UPDATE'));

        // B. Precedence của CUSTOM_USER_%: khi CUSTOM_USER_* còn hiệu lực, nó override base role trên cả User và stdClass; khi soft-delete, quyền riêng bị loại bỏ
        [$customTarget] = $this->createRbacUser('NO_ROLE');
        $baseRole = Role::create([
            'role_code' => 'M105_BASE_'.Str::upper(Str::random(6)),
            'role_name' => 'M105 Base Role',
            'is_system_role' => false,
        ]);
        foreach (['INVOICE:APPROVE', 'TICKET:UPDATE'] as $basePermCode) {
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $baseRole->id,
                'permission_id' => Permission::where('permission_code', $basePermCode)->firstOrFail()->id,
                'created_at' => now(),
            ]);
        }
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $customTarget->id,
            'role_id' => $baseRole->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $customCode = 'CUSTOM_USER_'.substr(str_replace('-', '', $customTarget->id), 0, 12);
        $customRole = Role::create([
            'role_code' => $customCode,
            'role_name' => 'M105 Custom Role',
            'is_system_role' => false,
        ]);
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid(),
            'role_id' => $customRole->id,
            'permission_id' => Permission::where('permission_code', 'VEHICLE:APPROVE')->firstOrFail()->id,
            'created_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $customTarget->id,
            'role_id' => $customRole->id,
            'is_primary' => 0,
            'assigned_at' => now(),
        ]);

        $stdCustomTarget = (object) ['id' => $customTarget->id, 'status' => 'ACTIVE'];
        $freshCustomUser = User::findOrFail($customTarget->id);

        // Khi CUSTOM_USER_* active: chỉ có VEHICLE:APPROVE, không gộp INVOICE:APPROVE/TICKET:UPDATE từ base role
        $this->assertTrue($freshCustomUser->hasPermission('VEHICLE:APPROVE'));
        $this->assertFalse($freshCustomUser->hasPermission('INVOICE:APPROVE'));
        $this->assertTrue(RbacService::hasPermission($stdCustomTarget, 'VEHICLE:APPROVE'));
        $this->assertFalse(RbacService::hasPermission($stdCustomTarget, 'INVOICE:APPROVE'));

        // Khi CUSTOM_USER_* bị soft-delete (kể cả chưa detach user_roles): VEHICLE:APPROVE không rò rỉ
        $customRole->delete();
        $freshAfterCustomDelete = User::findOrFail($customTarget->id);
        $this->assertFalse($freshAfterCustomDelete->hasPermission('VEHICLE:APPROVE'));
        $this->assertFalse(RbacService::hasPermission($stdCustomTarget, 'VEHICLE:APPROVE'));
        $this->assertTrue($freshAfterCustomDelete->hasPermission('INVOICE:APPROVE'));
        $this->assertTrue(RbacService::hasPermission($stdCustomTarget, 'INVOICE:APPROVE'));

        // C. Quy tắc SPECIAL: chỉ SPECIAL và SYSTEM:SPECIAL thỏa mãn SPECIAL; USER:SPECIAL & VEHICLE:SPECIAL không được suy diễn thành SPECIAL
        [$moduleSpecialUser] = $this->createUserWithCustomPermissions(['USER:SPECIAL', 'VEHICLE:SPECIAL'], 'M105_MODSPEC_');
        $stdModuleSpecial = (object) ['id' => $moduleSpecialUser->id, 'status' => 'ACTIVE'];

        $this->assertFalse($moduleSpecialUser->hasPermission('SPECIAL'));
        $this->assertFalse($moduleSpecialUser->hasPermission('special'));
        $this->assertFalse(RbacService::hasPermission($stdModuleSpecial, 'SPECIAL'));
        $this->assertFalse(RbacService::hasPermission($stdModuleSpecial, 'special'));
        $this->assertTrue($moduleSpecialUser->hasPermission('USER:SPECIAL'));
        $this->assertTrue(RbacService::hasPermission($stdModuleSpecial, 'USER:SPECIAL'));

        [$sysSpecialUser, , $sysSpecialRole] = $this->createUserWithCustomPermissions(['SYSTEM:SPECIAL'], 'M105_SYSSPEC_');
        $stdSysSpecial = (object) ['id' => $sysSpecialUser->id, 'status' => 'ACTIVE'];

        $this->assertTrue($sysSpecialUser->hasPermission('SPECIAL'));
        $this->assertTrue(RbacService::hasPermission($stdSysSpecial, 'SPECIAL'));

        // Soft-delete role chứa SYSTEM:SPECIAL -> mất quyền SPECIAL ở cả User và stdClass
        $sysSpecialRole->delete();
        $this->assertFalse(User::findOrFail($sysSpecialUser->id)->hasPermission('SPECIAL'));
        $this->assertFalse(RbacService::hasPermission($stdSysSpecial, 'SPECIAL'));

        [$exactSpecialUser] = $this->createUserWithCustomPermissions(['SPECIAL'], 'M105_EXACTSPEC_');
        $stdExactSpecial = (object) ['id' => $exactSpecialUser->id, 'status' => 'ACTIVE'];
        $this->assertTrue($exactSpecialUser->hasPermission('SPECIAL'));
        $this->assertTrue(RbacService::hasPermission($stdExactSpecial, 'SPECIAL'));
    }
}
