<?php

namespace Tests\Feature\Vehicles;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\QuocTinRealtimeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleRejectionAndOwnerValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected Block $block;

    protected Apartment $apartmentA;

    protected Apartment $apartmentB;

    protected User $superAdmin;

    protected string $superAdminToken;

    protected User $managerUser;

    protected string $managerToken;

    protected User $residentUser;

    protected string $residentToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $suffix = Str::upper(Str::random(6));

        $this->block = Block::create([
            'block_code' => 'BLK_V_REJ_'.$suffix,
            'block_name' => 'Tòa Thử Nghiệm Từ Chối '.$suffix,
            'total_floors' => 5,
            'total_basements' => 1,
            'total_apartments' => 10,
        ]);

        $floorId = (string) Str::uuid();
        DB::table('floors')->insert([
            'id' => $floorId,
            'block_id' => $this->block->id,
            'floor_number' => 1,
            'floor_code' => 'FL-V-'.$suffix,
            'floor_name' => 'Tầng 1 Thử Nghiệm',
            'floor_type' => 'RESIDENTIAL',
            'total_units' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->apartmentA = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $floorId,
            'apartment_number' => 'V-101-'.$suffix,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);

        $this->apartmentB = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $floorId,
            'apartment_number' => 'V-102-'.$suffix,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);

        [$this->superAdmin, $this->superAdminToken] = $this->createUserWithRole('SUPER_ADMIN', 'sa_rej_');
        [$this->managerUser, $this->managerToken] = $this->createUserWithRole('BUILDING_MANAGER', 'bm_rej_');
        [$this->residentUser, $this->residentToken] = $this->createUserWithRole('RESIDENT_OWNER', 'res_rej_');
    }

    protected function createUserWithRole(string $roleCode, string $prefix, array $attributes = []): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create(array_merge([
            'username' => $prefix.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Test User '.$roleCode.' '.$rand,
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

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Vehicle Rejection Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function createValidResident(Apartment $apartment, array $attributes = []): array
    {
        [$user, $token] = $this->createUserWithRole('RESIDENT_OWNER', 'res_act_');

        $resident = Resident::create(array_merge([
            'user_id' => $user->id,
            'apartment_id' => $apartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ], $attributes));

        return [$user, $resident, $token];
    }

    // =========================================================================
    // NHÓM 1 — OWNER / APARTMENT VALIDATION (BUG-M4-04 PHẦN A)
    // =========================================================================

    /**
     * 1. Chủ xe là cư dân hợp lệ thuộc đúng căn hộ: Đăng ký thành công (201 Created)
     */
    public function test_01_valid_resident_owner_can_register_vehicle_successfully(): void
    {
        [$residentUser] = $this->createValidResident($this->apartmentA);
        $plate = '29A-'.rand(10000, 99999);

        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $residentUser->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'brand' => 'Honda',
            'model' => 'Lead',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.license_plate', $plate);

        $this->assertDatabaseHas('vehicles', [
            'license_plate' => $plate,
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $residentUser->id,
        ]);
    }

    /**
     * 2. Chủ sở hữu trong apartment_owners thuộc đúng căn hộ: Đăng ký thành công (201 Created)
     */
    public function test_02_valid_apartment_owner_can_register_vehicle_successfully(): void
    {
        [$ownerUser] = $this->createUserWithRole('RESIDENT_OWNER', 'apt_own_');

        DB::table('apartment_owners')->insert([
            'id' => (string) Str::uuid(),
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $ownerUser->id,
            'is_current_owner' => 1,
            'ownership_start_date' => now()->subMonths(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plate = '30A-'.rand(10000, 99999);
        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $ownerUser->id,
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
            'brand' => 'VinFast',
            'model' => 'VF9',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    /**
     * 3. Chủ xe là cư dân căn hộ B, cố tình đăng ký cho căn hộ A: Bị từ chối (422)
     */
    public function test_03_cannot_register_vehicle_when_owner_belongs_to_another_apartment(): void
    {
        // User thuộc căn hộ B
        [$userB] = $this->createValidResident($this->apartmentB);
        $plate = '29B-'.rand(10000, 99999);

        // Đăng ký vào căn hộ A
        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $userB->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    /**
     * 4. User không tồn tại trong hệ thống: Bị từ chối (422)
     */
    public function test_04_cannot_register_vehicle_when_user_does_not_exist(): void
    {
        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => (string) Str::uuid(),
            'license_plate' => '29X-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    /**
     * 5. User tồn tại nhưng không phải cư dân hợp lệ của căn hộ: Bị từ chối (422)
     */
    public function test_05_cannot_register_vehicle_when_user_has_no_resident_relationship(): void
    {
        // User tồn tại nhưng không gắn vào bất kỳ căn hộ nào
        [$unlinkedUser] = $this->createUserWithRole('RESIDENT_OWNER', 'unlink_');

        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $unlinkedUser->id,
            'license_plate' => '29U-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    /**
     * 6. Cư dân đã hết hạn lưu trú (stay_end_date trong quá khứ): Bị từ chối (422)
     */
    public function test_06_cannot_register_vehicle_when_resident_stay_end_date_expired(): void
    {
        [$expiredUser] = $this->createValidResident($this->apartmentA, [
            'stay_end_date' => now()->subDay()->toDateString(),
        ]);

        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $expiredUser->id,
            'license_plate' => '29E-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    /**
     * 7. Cư dân bị vô hiệu hóa (is_active = false): Bị từ chối (422)
     */
    public function test_07_cannot_register_vehicle_when_resident_is_inactive(): void
    {
        [$inactiveResident] = $this->createValidResident($this->apartmentA, [
            'is_active' => false,
        ]);

        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $inactiveResident->id,
            'license_plate' => '29I-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    /**
     * 8. Cư dân đã bị xóa mềm (deleted_at khác null): Bị từ chối (422)
     */
    public function test_08_cannot_register_vehicle_when_resident_is_soft_deleted(): void
    {
        [$deletedUser, $residentRecord] = $this->createValidResident($this->apartmentA);
        $residentRecord->delete(); // Soft-delete

        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $deletedUser->id,
            'license_plate' => '29D-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    /**
     * 9. Tài khoản user không ở trạng thái ACTIVE (status = LOCKED): Bị từ chối (422)
     */
    public function test_09_cannot_register_vehicle_when_user_account_is_inactive_or_locked(): void
    {
        [$lockedUser] = $this->createValidResident($this->apartmentA);
        $lockedUser->status = 'LOCKED';
        $lockedUser->save();

        $payload = [
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $lockedUser->id,
            'license_plate' => '29L-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    /**
     * 10. Cập nhật phương tiện với chủ xe không thuộc căn hộ: Bị từ chối (422)
     */
    public function test_10_update_vehicle_with_invalid_owner_fails_validation(): void
    {
        [$validOwner] = $this->createValidResident($this->apartmentA);
        [$otherResident] = $this->createValidResident($this->apartmentB);

        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $validOwner->id,
            'license_plate' => '29UP-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        // Cập nhật owner_user_id sang cư dân thuộc căn hộ B
        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->putJson("/api/v1/vehicles/{$vehicle->id}", [
                'owner_user_id' => $otherResident->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    // =========================================================================
    // NHÓM 2 — REJECT REGISTRATION (BUG-M4-04 PHẦN B & C)
    // =========================================================================

    /**
     * 11. Người có quyền từ chối đăng ký hợp lệ: Thành công 200 OK
     */
    public function test_11_authorized_admin_can_reject_vehicle_with_valid_reason(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29REJ-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => 'Ảnh cà-vẹt xe chụp bị mờ, không nhìn rõ số khung và biển số.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.rejection_reason', 'Ảnh cà-vẹt xe chụp bị mờ, không nhìn rõ số khung và biển số.');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'is_active' => 0,
            'rejected_by' => $this->superAdmin->id,
            'rejection_reason' => 'Ảnh cà-vẹt xe chụp bị mờ, không nhìn rõ số khung và biển số.',
        ]);
    }

    /**
     * 12. Người không có quyền (cư dân) gọi reject: Bị chặn 403 Forbidden
     */
    public function test_12_unauthorized_user_without_permission_cannot_reject_vehicle(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29FORBID-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->residentToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => 'Cư dân tự ý can thiệp từ chối hồ sơ',
            ]);

        $response->assertStatus(403);
    }

    /**
     * 13. Khách chưa đăng nhập gọi reject: Bị chặn 401 Unauthorized
     */
    public function test_13_unauthenticated_guest_cannot_reject_vehicle(): void
    {
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $this->superAdmin->id,
            'license_plate' => '29GUEST-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
            'reason' => 'Không gửi token xác thực',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 14. Không có lý do từ chối: Bị từ chối validation error (422)
     */
    public function test_14_rejection_requires_reason(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29NOREAS-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    /**
     * 15. Lý do từ chối chỉ toàn khoảng trắng: Bị từ chối validation error (422)
     */
    public function test_15_rejection_reason_cannot_be_empty_or_whitespace_only(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29SPACE-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => '     ',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    /**
     * 16. Lý do từ chối dưới 3 ký tự hoặc vượt quá 1000 ký tự: Bị từ chối (422)
     */
    public function test_16_rejection_reason_must_meet_length_constraints(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29LEN-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        // Dưới 3 ký tự
        $resShort = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => 'OK',
            ]);
        $resShort->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        // Vượt quá 1000 ký tự
        $resLong = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => str_repeat('A', 1001),
            ]);
        $resLong->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    /**
     * 17. Phương tiện không tồn tại hoặc đã bị xóa: Trả về 404 Not Found
     */
    public function test_17_cannot_reject_non_existent_or_deleted_vehicle(): void
    {
        $nonExistentId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$nonExistentId}/reject", [
                'reason' => 'Lý do hợp lệ',
            ]);

        $response->assertStatus(404);
    }

    /**
     * 18. Phương tiện đã được phê duyệt trước đó: Không thể từ chối (409 Conflict)
     */
    public function test_18_cannot_reject_already_approved_vehicle(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29APP-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
            'approved_by' => $this->superAdmin->id,
            'approved_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => 'Cố từ chối xe đã duyệt xong',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'CONFLICT');
    }

    /**
     * 19. Phương tiện đã bị từ chối: Không thể được phê duyệt (409 Conflict)
     */
    public function test_19_cannot_approve_already_rejected_vehicle(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29NOAPP-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => false,
            'rejected_by' => $this->superAdmin->id,
            'rejected_at' => now(),
            'rejection_reason' => 'Xe không đủ điều kiện',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/approve");

        $response->assertStatus(409)
            ->assertJsonPath('error', 'CONFLICT');
    }

    /**
     * 20. Idempotency: Không thể gửi yêu cầu từ chối hai lần trên cùng phương tiện (409 Conflict)
     */
    public function test_20_cannot_reject_already_rejected_vehicle_idempotency(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29IDEM-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        // Từ chối lần 1 -> Thành công 200
        $res1 = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => 'Lý do từ chối ban đầu',
            ]);
        $res1->assertStatus(200);

        // Từ chối lần 2 -> Bị chặn 409 Conflict
        $res2 = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => 'Lý do từ chối cố gửi lần 2',
            ]);
        $res2->assertStatus(409)
            ->assertJsonPath('error', 'CONFLICT');
    }

    /**
     * 21. Cập nhật thông thường qua PUT/PATCH không thể giả mạo dữ liệu phê duyệt hoặc từ chối
     */
    public function test_21_normal_update_cannot_tamper_with_rejection_data(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29TAMPER-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
            'rejection_reason' => null,
            'rejected_at' => null,
        ]);

        // Gửi payload cố tình chèn dữ liệu rejection hoặc approval
        $response = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->putJson("/api/v1/vehicles/{$vehicle->id}", [
                'color' => 'Đen Nhám',
                'rejection_reason' => 'Fake reason',
                'rejected_by' => $owner->id,
                'rejected_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(200);

        $freshVehicle = Vehicle::find($vehicle->id);
        $this->assertEquals('Đen Nhám', $freshVehicle->color);
        $this->assertNull($freshVehicle->rejection_reason, 'rejection_reason không bị giả mạo qua update');
        $this->assertNull($freshVehicle->rejected_at, 'rejected_at không bị giả mạo qua update');
    }

    /**
     * 22. Lý do từ chối được lưu chính xác vào database và trả về đúng trong response
     */
    public function test_22_rejection_reason_persisted_in_database_and_returned_in_api(): void
    {
        [$owner] = $this->createValidResident($this->apartmentA);
        $vehicle = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $owner->id,
            'license_plate' => '29PERSIST-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        $reason = 'Biển số xe trên hệ thống không khớp với thông tin thực tế đăng ký tại phường.';

        $res = $this->withHeader('Authorization', "Bearer {$this->superAdminToken}")
            ->postJson("/api/v1/vehicles/{$vehicle->id}/reject", [
                'reason' => $reason,
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.rejection_reason', $reason)
            ->assertJsonPath('data.is_active', false);

        $stored = Vehicle::find($vehicle->id);
        $this->assertNotNull($stored);
        $this->assertEquals($reason, $stored->rejection_reason);
        $this->assertNotNull($stored->rejected_at);
        $this->assertEquals($this->superAdmin->id, $stored->rejected_by);
    }
}
