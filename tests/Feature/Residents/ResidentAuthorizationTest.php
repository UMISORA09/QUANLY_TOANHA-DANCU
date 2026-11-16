<?php

namespace Tests\Feature\Residents;

use App\Models\Apartment;
use App\Models\Permission;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /**
     * Tạo Bearer token cho người dùng trong bảng user_sessions
     */
    protected function issueTestBearerToken(User $user): string
    {
        $plainToken = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $plainToken);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Resident Auth Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return $plainToken;
    }

    /**
     * Tạo người dùng với vai trò hệ thống
     *
     * @return array{0: User, 1: string}
     */
    protected function createUserWithRole(string $roleCode, array $attributes = []): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create(array_merge([
            'username' => 'usr_'.strtolower($roleCode).'_'.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'usr_'.strtolower($roleCode).'_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'User '.$roleCode.' '.$rand,
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

        $token = $this->issueTestBearerToken($user);

        return [$user, $token];
    }

    /**
     * Tạo người dùng chỉ có danh sách quyền chỉ định thông qua CUSTOM_USER_{id}
     *
     * @param  array<int, string>  $permissionCodes
     * @return array{0: User, 1: string}
     */
    protected function createUserWithCustomPermissions(array $permissionCodes, ?string $baseRoleCode = null, array $userAttributes = []): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create(array_merge([
            'username' => 'custom_usr_'.$rand,
            'phone_number' => '08'.rand(10000000, 99999999),
            'email' => 'custom_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Custom Resident User '.$rand,
            'status' => 'ACTIVE',
        ], $userAttributes));

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

        // Tạo vai trò CUSTOM_USER_{id}
        $customRole = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => 'CUSTOM_USER_'.$user->id,
            'role_name' => 'Custom Permissions for '.$user->username,
            'is_system_role' => false,
            'status' => 'ACTIVE',
        ]);

        if ($permissionCodes !== []) {
            $permIds = Permission::whereIn('permission_code', $permissionCodes)->pluck('id');
            foreach ($permIds as $permId) {
                DB::table('role_permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'role_id' => $customRole->id,
                    'permission_id' => $permId,
                    'created_at' => now(),
                ]);
            }
        }

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $customRole->id,
            'is_primary' => 0,
            'assigned_at' => now(),
        ]);

        $token = $this->issueTestBearerToken($user);

        return [$user, $token];
    }

    /**
     * Tạo căn hộ test độc lập
     */
    protected function createApartment(string $code = 'AUTH'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $code.'-'.rand(1000, 9999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 70.0,
            'net_usable_area_sqm' => 65.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * Tạo ứng viên cư dân để gán vào căn hộ
     */
    protected function createCandidateUser(): User
    {
        $rand = Str::lower(Str::random(8));

        return User::create([
            'username' => 'cand_'.$rand,
            'phone_number' => '07'.rand(10000000, 99999999),
            'email' => 'cand_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Candidate User '.$rand,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * 1. User chỉ có RESIDENT:VIEW được xem danh sách (GET /api/v1/residents -> 200)
     */
    public function test_01_user_with_only_view_permission_can_access_index(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:VIEW']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents');

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data', 'meta']);
    }

    /**
     * 2. User chỉ có RESIDENT:VIEW không tạo được nhân khẩu (POST /api/v1/residents -> 403)
     */
    public function test_02_user_with_only_view_permission_cannot_create_resident(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:VIEW']);
        $apt = $this->createApartment('TC02');
        $cand = $this->createCandidateUser();

        $payload = [
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => 'FORBIDDEN',
        ]);
    }

    /**
     * 3. User chỉ có RESIDENT:VIEW không sửa được nhân khẩu (PUT /api/v1/residents/{id} -> 403)
     */
    public function test_03_user_with_only_view_permission_cannot_update_resident(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:VIEW']);
        $apt = $this->createApartment('TC03');
        $cand = $this->createCandidateUser();

        $resident = Resident::create([
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);

        $payload = [
            'resident_type' => 'TENANT',
            'relationship_to_head' => 'TENANT',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/residents/'.$resident->id, $payload);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => 'FORBIDDEN',
        ]);
    }

    /**
     * 4. User chỉ có RESIDENT:VIEW không xóa được nhân khẩu (DELETE /api/v1/residents/{id} -> 403)
     */
    public function test_04_user_with_only_view_permission_cannot_delete_resident(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:VIEW']);
        $apt = $this->createApartment('TC04');
        $cand = $this->createCandidateUser();

        $resident = Resident::create([
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/residents/'.$resident->id);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => 'FORBIDDEN',
        ]);
    }

    /**
     * 5. User có RESIDENT:CREATE tạo được nhân khẩu (POST /api/v1/residents -> 201)
     */
    public function test_05_user_with_create_permission_can_create_resident(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:CREATE']);
        $apt = $this->createApartment('TC05');
        $cand = $this->createCandidateUser();

        $payload = [
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Thêm cư dân vào căn hộ thành công.',
        ]);
    }

    /**
     * 6. User có RESIDENT:UPDATE sửa được nhân khẩu (PUT /api/v1/residents/{id} -> 200)
     */
    public function test_06_user_with_update_permission_can_update_resident(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $apt = $this->createApartment('TC06');
        $cand = $this->createCandidateUser();

        $resident = Resident::create([
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);

        $payload = [
            'resident_type' => 'TENANT',
            'relationship_to_head' => 'TENANT',
            'occupation' => 'Kỹ sư cầu nối',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/residents/'.$resident->id, $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Cập nhật thông tin cư dân thành công.',
        ]);
        $this->assertSame('TENANT', Resident::find($resident->id)->resident_type);
    }

    /**
     * 7. User có RESIDENT:DELETE xóa được nhân khẩu (DELETE /api/v1/residents/{id} -> 200)
     */
    public function test_07_user_with_delete_permission_can_delete_resident(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:DELETE']);
        $apt = $this->createApartment('TC07');
        $cand = $this->createCandidateUser();

        $resident = Resident::create([
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/residents/'.$resident->id);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Đã xóa cư dân khỏi căn hộ thành công.',
        ]);
        $this->assertSoftDeleted('residents', ['id' => $resident->id]);
    }

    /**
     * 8. BUILDING_MANAGER bị thu hồi permission không thể tiếp tục thao tác tương ứng (403)
     */
    public function test_08_building_manager_with_revoked_permission_cannot_perform_action(): void
    {
        // BUILDING_MANAGER bị thu hồi RESIDENT:CREATE (chỉ còn RESIDENT:VIEW và RESIDENT:UPDATE)
        [, $tokenWithoutCreate] = $this->createUserWithCustomPermissions(
            ['RESIDENT:VIEW', 'RESIDENT:UPDATE', 'RESIDENT:DELETE'],
            'BUILDING_MANAGER'
        );

        $apt = $this->createApartment('TC08');
        $cand = $this->createCandidateUser();

        $payload = [
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
        ];

        // Thao tác POST phải bị chặn 403 vì permission CREATE đã bị thu hồi
        $resCreate = $this->withHeader('Authorization', "Bearer {$tokenWithoutCreate}")
            ->postJson('/api/v1/residents', $payload);
        $resCreate->assertStatus(403);

        // BUILDING_MANAGER bị thu hồi RESIDENT:DELETE (chỉ còn RESIDENT:VIEW và RESIDENT:CREATE)
        [, $tokenWithoutDelete] = $this->createUserWithCustomPermissions(
            ['RESIDENT:VIEW', 'RESIDENT:CREATE'],
            'BUILDING_MANAGER'
        );

        $resident = Resident::create([
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);

        // Thao tác DELETE phải bị chặn 403 vì permission DELETE đã bị thu hồi
        $resDelete = $this->withHeader('Authorization', "Bearer {$tokenWithoutDelete}")
            ->deleteJson('/api/v1/residents/'.$resident->id);
        $resDelete->assertStatus(403);
    }

    /**
     * 9. Role tùy chỉnh có permission hợp lệ được hoạt động bình thường
     */
    public function test_09_custom_role_with_valid_permission_can_operate(): void
    {
        // Tạo vai trò tùy chỉnh (ví dụ: TỔ TRƯỞNG DÂN PHỐ)
        $customRole = Role::create([
            'id' => (string) Str::uuid(),
            'role_code' => 'WARD_OFFICER_'.Str::upper(Str::random(6)),
            'role_name' => 'Cán bộ Tổ Dân Phố',
            'is_system_role' => false,
            'status' => 'ACTIVE',
        ]);

        $createPermId = Permission::where('permission_code', 'RESIDENT:CREATE')->value('id');
        $viewPermId = Permission::where('permission_code', 'RESIDENT:VIEW')->value('id');

        DB::table('role_permissions')->insert([
            ['id' => (string) Str::uuid(), 'role_id' => $customRole->id, 'permission_id' => $createPermId, 'created_at' => now()],
            ['id' => (string) Str::uuid(), 'role_id' => $customRole->id, 'permission_id' => $viewPermId, 'created_at' => now()],
        ]);

        $user = User::create([
            'username' => 'ward_user_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'ward_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Can Bo To Dan Pho',
            'status' => 'ACTIVE',
        ]);

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $customRole->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = $this->issueTestBearerToken($user);

        // Xem danh sách thành công
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents')
            ->assertStatus(200);

        // Tạo nhân khẩu thành công
        $apt = $this->createApartment('TC09');
        $cand = $this->createCandidateUser();

        $payload = [
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
        ];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload)
            ->assertStatus(201);
    }

    /**
     * 10. SUPER_ADMIN vẫn hoạt động đúng chính sách (bypass permission)
     */
    public function test_10_super_admin_bypasses_all_permission_checks(): void
    {
        [, $token] = $this->createUserWithRole('SUPER_ADMIN');
        $apt = $this->createApartment('TC10');
        $cand = $this->createCandidateUser();

        // 1. GET danh sách -> 200
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents')
            ->assertStatus(200);

        // 2. POST tạo cư dân -> 201
        $payload = [
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
        ];

        $resCreate = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);
        $resCreate->assertStatus(201);
        $residentId = $resCreate->json('data.id');

        // 3. PUT cập nhật -> 200
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/residents/'.$residentId, [
                'resident_type' => 'TENANT',
                'relationship_to_head' => 'TENANT',
            ])
            ->assertStatus(200);

        // 4. DELETE xóa -> 200
        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/residents/'.$residentId)
            ->assertStatus(200);
    }

    /**
     * 11. Tài khoản không ACTIVE không được vượt qua authorization (403)
     */
    public function test_11_inactive_or_locked_account_is_blocked_with_403(): void
    {
        // User có full permissions nhưng trạng thái tài khoản là LOCKED
        [, $tokenLocked] = $this->createUserWithCustomPermissions(
            ['RESIDENT:VIEW', 'RESIDENT:CREATE', 'RESIDENT:UPDATE', 'RESIDENT:DELETE'],
            null,
            ['status' => 'LOCKED']
        );

        $response = $this->withHeader('Authorization', "Bearer {$tokenLocked}")
            ->getJson('/api/v1/residents');

        $response->assertStatus(403);
        $this->assertFalse($response->json('success'));
        $this->assertTrue(in_array($response->json('error'), ['FORBIDDEN', 'ACCOUNT_LOCKED'], true));
    }

    /**
     * 12. Không hồi quy BUG-M2-02: khôi phục nhân khẩu đã soft-delete vẫn hoạt động bình thường
     */
    public function test_12_restoring_soft_deleted_resident_works_with_create_permission(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:CREATE']);
        $apt = $this->createApartment('TC12');
        $cand = $this->createCandidateUser();

        // Tạo sẵn nhân khẩu rồi soft-delete
        $resident = Resident::create([
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ]);
        $originalId = $resident->id;
        $resident->delete();

        $payload = [
            'user_id' => $cand->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-08-01',
            'relationship_to_head' => 'TENANT',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Thêm cư dân vào căn hộ thành công.',
        ]);

        // Xác nhận bản ghi cũ được khôi phục, deleted_at = null, ID không đổi, count = 1
        $refreshed = Resident::find($originalId);
        $this->assertNotNull($refreshed);
        $this->assertNull($refreshed->deleted_at);
        $this->assertSame($originalId, $response->json('data.id'));

        $totalCount = Resident::withTrashed()
            ->where('user_id', $cand->id)
            ->where('apartment_id', $apt->id)
            ->count();
        $this->assertSame(1, $totalCount);
    }
}
