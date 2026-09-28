<?php

namespace Tests\Feature\Residents;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentManagementTest extends TestCase
{
    /**
     * Tạo tài khoản quản trị viên kèm phiên đăng nhập Bearer token
     */
    protected function createAdminUser(): array
    {
        $user = User::create([
            'username' => 'admin_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'admin_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Test User',
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
            'device_name' => 'PHPUnit Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo tài khoản người dùng bình thường (không có quyền quản trị Admin)
     */
    protected function createNormalUser(): array
    {
        $user = User::create([
            'username' => 'member_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'member_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Normal Member User',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'RESIDENT_MEMBER')->first();
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
            'device_name' => 'PHPUnit Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Lấy hoặc tạo căn hộ dùng cho test
     */
    protected function getTestApartment(): Apartment
    {
        $apt = Apartment::first();
        if ($apt) {
            return $apt;
        }

        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => 'TEST-'.rand(100, 999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.5,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * 1. Admin xem danh sách cư dân
     */
    public function test_01_admin_can_view_resident_list(): void
    {
        [, $token] = $this->createAdminUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    /**
     * 2. Search theo tên
     */
    public function test_02_search_by_name(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $searchUser = User::create([
            'username' => 'search_name_'.Str::random(8),
            'phone_number' => '0988'.rand(100000, 999999),
            'email' => 'search_name_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Nguyen Van Dac Biet',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $searchUser->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents?search=Van+Dac+Biet');
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('data'));
    }

    /**
     * 3. Search theo SĐT
     */
    public function test_03_search_by_phone(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $phone = '09'.rand(10000000, 99999999);
        $searchUser = User::create([
            'username' => 'search_phone_'.Str::random(8),
            'phone_number' => $phone,
            'email' => 'search_phone_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Tim Theo Phone',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $searchUser->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents?search={$phone}");
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('data'));
    }

    /**
     * 4. Search theo CCCD nếu có
     */
    public function test_04_search_by_cccd(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $cccd = '079'.rand(100000000, 999999999);
        $searchUser = User::create([
            'username' => 'search_cccd_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'search_cccd_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Tim Theo CCCD',
            'national_id_number' => $cccd,
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $searchUser->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents?search={$cccd}");
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('data'));
    }

    /**
     * 5. Filter apartment
     */
    public function test_05_filter_apartment(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'flt_apt_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'flt_apt_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Loc Can Ho',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents?apartment_id={$apt->id}");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertEquals($apt->id, $item['apartment_id']);
        }
    }

    /**
     * 6. Filter resident_type
     */
    public function test_06_filter_resident_type(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'flt_type_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'flt_type_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Loc Loai Cu Dan',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents?resident_type=TENANT');
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertEquals('TENANT', $item['resident_type']);
        }
    }

    /**
     * 7. Filter active/inactive
     */
    public function test_07_filter_active_inactive(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'flt_act_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'flt_act_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Inactive Test',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2025-01-01',
            'is_active' => false,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents?is_active=0');
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertFalse((bool) $item['is_active']);
        }
    }

    /**
     * 8. Pagination
     */
    public function test_08_pagination(): void
    {
        [, $token] = $this->createAdminUser();

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/residents?limit=5&page=1');
        $res->assertStatus(200)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.current_page', 1);
    }

    /**
     * 9. View resident detail
     */
    public function test_09_view_resident_detail(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'view_det_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'view_det_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Chi Tiet',
            'status' => 'ACTIVE',
        ]);

        $resident = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2025-01-01',
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/residents/{$resident->id}");

        $res->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'resident',
                    'apartment',
                    'head_of_household',
                    'household_members',
                    'total_members',
                ],
            ]);
        $this->assertEquals('User Chi Tiet', $res->json('data.resident.user.full_name'));
    }

    /**
     * 10. Add resident
     */
    public function test_10_add_resident(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'add_res_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'add_res_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Them Vao Can Ho',
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-03-01',
            'relationship_to_head' => 'CHILD',
            'occupation' => 'Hoc sinh',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $res->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Thêm cư dân vào căn hộ thành công.',
            ]);

        $this->assertDatabaseHas('residents', [
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'relationship_to_head' => 'CHILD',
        ]);
    }

    /**
     * 11. Reject invalid apartment
     */
    public function test_11_reject_invalid_apartment(): void
    {
        [, $token] = $this->createAdminUser();
        $fakeAptId = (string) Str::uuid();

        $user = User::create([
            'username' => 'inv_apt_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'inv_apt_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Invalid Apt',
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'apartment_id' => $fakeAptId,
            'user_id' => $user->id,
            'resident_type' => 'OWNER',
            'stay_start_date' => '2026-01-01',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['apartment_id']);
    }

    /**
     * 12. Reject invalid user
     */
    public function test_12_reject_invalid_user(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();
        $fakeUserId = (string) Str::uuid();

        $payload = [
            'apartment_id' => $apt->id,
            'user_id' => $fakeUserId,
            'resident_type' => 'OWNER',
            'stay_start_date' => '2026-01-01',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    /**
     * 13. Reject invalid resident_type
     */
    public function test_13_reject_invalid_resident_type(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'inv_type_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'inv_type_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Invalid Type',
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'INVALID_RESIDENT_TYPE',
            'stay_start_date' => '2026-01-01',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['resident_type']);
    }

    /**
     * 14. Reject invalid relationship_to_head
     */
    public function test_14_reject_invalid_relationship_to_head(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'inv_rel_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'inv_rel_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Invalid Rel',
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'FAMILY_MEMBER',
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'INVALID_RELATIONSHIP',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['relationship_to_head']);
    }

    /**
     * 15. Reject duplicate (user_id, apartment_id)
     */
    public function test_15_reject_duplicate_user_apartment(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'dup_res_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'dup_res_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Check Duplicate',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $payload = [
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'stay_start_date' => '2026-01-01',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    /**
     * 16. Add household head
     */
    public function test_16_add_household_head(): void
    {
        [, $token] = $this->createAdminUser();

        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();
        $newApt = Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => 'NEW-HEAD-'.rand(100, 999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 70.0,
            'net_usable_area_sqm' => 65.0,
            'status' => 'OCCUPIED',
        ]);

        $headUser = User::create([
            'username' => 'head_add_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'head_add_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Nguyen Van Chu Ho Moi',
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'apartment_id' => $newApt->id,
            'user_id' => $headUser->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'SELF',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $res->assertStatus(201);
        $this->assertDatabaseHas('residents', [
            'apartment_id' => $newApt->id,
            'user_id' => $headUser->id,
            'is_head_of_household' => 1,
            'relationship_to_head' => 'SELF',
        ]);
    }

    /**
     * 17. Reject duplicate active household head
     */
    public function test_17_reject_duplicate_active_household_head(): void
    {
        [, $token] = $this->createAdminUser();

        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();
        $apt = Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => 'DUP-HEAD-'.rand(100, 999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 70.0,
            'net_usable_area_sqm' => 65.0,
            'status' => 'OCCUPIED',
        ]);

        $user1 = User::create([
            'username' => 'head1_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'head1_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Chu Ho So 1',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user1->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2025-01-01',
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        $user2 = User::create([
            'username' => 'head2_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'head2_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Chu Ho So 2',
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'apartment_id' => $apt->id,
            'user_id' => $user2->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-02-01',
            'relationship_to_head' => 'SELF',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', $payload);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['is_head_of_household']);
    }

    /**
     * 18. Update resident
     */
    public function test_18_update_resident(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'upd_res_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'upd_res_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Can Cap Nhat',
            'status' => 'ACTIVE',
        ]);

        $resident = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'occupation' => 'Lap trinh vien',
            'is_active' => true,
        ]);

        $updatePayload = [
            'occupation' => 'Kien truc su truong',
            'stay_end_date' => '2027-12-31',
        ];

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/residents/{$resident->id}", $updatePayload);

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Cập nhật thông tin cư dân thành công.',
            ]);

        $this->assertDatabaseHas('residents', [
            'id' => $resident->id,
            'occupation' => 'Kien truc su truong',
        ]);
    }

    /**
     * 19. Soft delete resident
     */
    public function test_19_soft_delete_resident(): void
    {
        [, $token] = $this->createAdminUser();
        $apt = $this->getTestApartment();

        $user = User::create([
            'username' => 'soft_del_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'soft_del_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'User Xoa Mem',
            'status' => 'ACTIVE',
        ]);

        $resident = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/residents/{$resident->id}");

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Đã xóa cư dân khỏi căn hộ thành công.',
            ]);

        $rawRecord = DB::table('residents')->where('id', $resident->id)->first();
        $this->assertNotNull($rawRecord);
        $this->assertNotNull($rawRecord->deleted_at);
        $this->assertEquals(0, $rawRecord->is_active);
        $this->assertNull(Resident::find($resident->id));
        $this->assertNotNull(Resident::withTrashed()->find($resident->id));
    }

    /**
     * 20. Guest → 401
     */
    public function test_20_guest_returns_401(): void
    {
        $res = $this->getJson('/api/v1/residents');
        $res->assertStatus(401);
    }

    /**
     * 21. Unauthorized → 403
     */
    public function test_21_unauthorized_returns_403(): void
    {
        [, $normalToken] = $this->createNormalUser();

        $res = $this->withHeader('Authorization', "Bearer {$normalToken}")
            ->getJson('/api/v1/residents');

        $res->assertStatus(403);
    }
}
