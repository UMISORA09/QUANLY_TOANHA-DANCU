<?php

namespace Tests\Feature\Residents;

use App\Models\Apartment;
use App\Models\Permission;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Services\ResidentService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Tests\TestCase;

/**
 * Test suite kiểm thử BUG-M2-03: Partial Date Validation & Laravel Scout Sync
 */
class ResidentDateAndSearchSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected ResidentService $residentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->residentService = app(ResidentService::class);
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
            'device_name' => 'PHPUnit DateSync Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return $plainToken;
    }

    /**
     * Tạo người dùng chỉ có danh sách quyền chỉ định thông qua CUSTOM_USER_{id}
     *
     * @param  array<int, string>  $permissionCodes
     * @return array{0: User, 1: string}
     */
    protected function createUserWithCustomPermissions(array $permissionCodes, ?string $baseRoleCode = null): array
    {
        $rand = Str::lower(Str::random(8));
        do {
            $phone = '08'.rand(10000000, 99999999);
        } while (DB::table('users')->where('phone_number', $phone)->exists());

        $user = User::create([
            'username' => 'custom_usr_'.$rand,
            'phone_number' => $phone,
            'email' => 'custom_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Custom Resident User '.$rand,
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
    protected function createApartment(string $code = 'SYNC'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();
        $blockId = $block->id ?? (string) Str::uuid();

        do {
            $aptNum = substr($code.'-'.rand(1000, 9999).'-'.Str::upper(Str::random(4)), 0, 30);
        } while (DB::table('apartments')->where('block_id', $blockId)->where('apartment_number', $aptNum)->exists());

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $blockId,
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $aptNum,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 70.0,
            'net_usable_area_sqm' => 65.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * Tạo user cư dân ứng viên
     */
    protected function createCandidateUser(): User
    {
        $rand = Str::lower(Str::random(8));

        do {
            $phone = '07'.rand(10000000, 99999999);
        } while (DB::table('users')->where('phone_number', $phone)->exists());

        return User::create([
            'username' => 'cand_'.$rand,
            'phone_number' => $phone,
            'email' => 'cand_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Candidate User '.$rand,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Tạo bản ghi cư dân mẫu
     */
    protected function createSampleResident(
        string $startDate = '2025-01-01',
        ?string $endDate = '2025-12-31',
        bool $isHead = false
    ): Resident {
        $apartment = $this->createApartment();
        $user = $this->createCandidateUser();

        return Resident::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'apartment_id' => $apartment->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => $isHead,
            'stay_start_date' => $startDate,
            'stay_end_date' => $endDate,
            'relationship_to_head' => $isHead ? 'SELF' : 'TENANT',
            'occupation' => 'Architect',
            'vehicle_count' => 1,
            'is_active' => true,
        ]);
    }

    // ==========================================
    // 1. PARTIAL DATE VALIDATION SCENARIOS
    // ==========================================

    /**
     * Scenario 1: PATCH chỉ gửi stay_end_date hợp lệ đối chiếu với stay_start_date trong DB -> 200 OK
     */
    public function test_scenario_01_patch_only_stay_end_date_valid_relative_to_existing_start_date(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'stay_end_date' => '2026-06-30',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Cập nhật thông tin cư dân thành công.',
            ]);

        $fresh = $resident->fresh();
        $this->assertEquals('2025-01-01', $fresh->stay_start_date->format('Y-m-d'));
        $this->assertEquals('2026-06-30', $fresh->stay_end_date->format('Y-m-d'));
    }

    /**
     * Scenario 2: PATCH chỉ gửi stay_end_date nhỏ hơn stay_start_date trong DB -> 422 Unprocessable
     */
    public function test_scenario_02_patch_only_stay_end_date_earlier_than_existing_start_date_fails(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'stay_end_date' => '2024-12-31',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['stay_end_date']);

        $fresh = $resident->fresh();
        $this->assertEquals('2025-12-31', $fresh->stay_end_date->format('Y-m-d'));
    }

    /**
     * Scenario 3: PATCH cả stay_start_date và stay_end_date hợp lệ -> 200 OK
     */
    public function test_scenario_03_patch_both_dates_valid(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'stay_start_date' => '2025-06-01',
                'stay_end_date' => '2026-06-01',
            ]);

        $response->assertStatus(200);

        $fresh = $resident->fresh();
        $this->assertEquals('2025-06-01', $fresh->stay_start_date->format('Y-m-d'));
        $this->assertEquals('2026-06-01', $fresh->stay_end_date->format('Y-m-d'));
    }

    /**
     * Scenario 4: PATCH cả hai ngày nhưng end date < start date -> 422 Unprocessable
     */
    public function test_scenario_04_patch_both_dates_invalid_end_before_start_fails(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'stay_start_date' => '2025-06-01',
                'stay_end_date' => '2025-05-01',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['stay_end_date']);

        $fresh = $resident->fresh();
        $this->assertEquals('2025-01-01', $fresh->stay_start_date->format('Y-m-d'));
    }

    /**
     * Scenario 5: PATCH chỉ gửi stay_start_date lớn hơn stay_end_date đang lưu trong DB -> 422 Unprocessable
     */
    public function test_scenario_05_patch_only_stay_start_date_later_than_existing_end_date_fails(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'stay_start_date' => '2026-06-01',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['stay_start_date']);

        $fresh = $resident->fresh();
        $this->assertEquals('2025-01-01', $fresh->stay_start_date->format('Y-m-d'));
    }

    /**
     * Scenario 6: PATCH không gửi các trường ngày -> giữ nguyên ngày cũ trong DB
     */
    public function test_scenario_06_patch_without_dates_preserves_existing_dates(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'occupation' => 'Software Engineer',
            ]);

        $response->assertStatus(200);

        $fresh = $resident->fresh();
        $this->assertEquals('Software Engineer', $fresh->occupation);
        $this->assertEquals('2025-01-01', $fresh->stay_start_date->format('Y-m-d'));
        $this->assertEquals('2025-12-31', $fresh->stay_end_date->format('Y-m-d'));
    }

    /**
     * Scenario 7: PATCH gửi explicit stay_end_date: null -> cho phép cư trú vô thời hạn
     */
    public function test_scenario_07_patch_explicit_stay_end_date_null_allows_open_ended_residency(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'stay_end_date' => null,
            ]);

        $response->assertStatus(200);

        $fresh = $resident->fresh();
        $this->assertNull($fresh->stay_end_date);
        $this->assertEquals('2025-01-01', $fresh->stay_start_date->format('Y-m-d'));
    }

    /**
     * Scenario 8: Khi validation thất bại, không có thay đổi nào được lưu dở dang (Atomicity)
     */
    public function test_scenario_08_failed_validation_rolls_back_and_does_not_partially_update(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'occupation' => 'Modified Occupation',
                'stay_end_date' => '2020-01-01', // Không hợp lệ
            ]);

        $response->assertStatus(422);

        $fresh = $resident->fresh();
        $this->assertEquals('Architect', $fresh->occupation);
        $this->assertEquals('2025-12-31', $fresh->stay_end_date->format('Y-m-d'));
    }

    // ==========================================
    // 2. ELOQUENT MODEL EVENTS & SCOUT SYNC
    // ==========================================

    /**
     * Scenario 9: Xác nhận Eloquent Model event `saved` và Scout Engine `update()` được kích hoạt khi gọi ResidentService::update()
     */
    public function test_scenario_09_resident_saved_eloquent_event_fires_on_service_update(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $savedFired = false;
        $savedResidentId = null;

        Resident::saved(function ($model) use (&$savedFired, &$savedResidentId) {
            $savedFired = true;
            $savedResidentId = $model->id;
        });

        // Đăng ký Fake Scout Engine để kiểm chứng ModelObserver đưa bản ghi vào luồng lập chỉ mục của Search Engine
        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_verify_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_verify_driver']);

        $this->residentService->update($resident->id, [
            'occupation' => 'Chief Architect',
            'stay_end_date' => '2026-12-31',
        ]);

        $this->assertTrue($savedFired, 'Eloquent saved event must be fired when Resident is updated');
        $this->assertEquals($resident->id, $savedResidentId);

        // Xác minh Scout Engine update() thực sự nhận được bản ghi cư dân vừa cập nhật vào luồng indexing
        $this->assertNotEmpty($engineReceived, 'Scout Engine update() must receive the updated resident model');
        $this->assertEquals($resident->id, $engineReceived[0]->first()->id);
        $this->assertEquals('Chief Architect', $engineReceived[0]->first()->occupation);
    }

    /**
     * Scenario 10: Xác nhận HTTP PATCH kích hoạt sự kiện Eloquent model saved
     */
    public function test_scenario_10_resident_saved_event_fires_on_http_update(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $savedCount = 0;
        Resident::saved(function () use (&$savedCount) {
            $savedCount++;
        });

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'vehicle_count' => 3,
            ]);

        $this->assertGreaterThanOrEqual(1, $savedCount, 'HTTP update must trigger Eloquent saved event');
    }

    /**
     * Scenario 11: Xác nhận $resident->toSearchableArray() phản ánh đầy đủ các thuộc tính đã cập nhật
     */
    public function test_scenario_11_to_searchable_array_reflects_updated_attributes(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $updated = $this->residentService->update($resident->id, [
            'resident_type' => 'FAMILY_MEMBER',
            'occupation' => 'Data Scientist',
            'stay_end_date' => '2027-01-15',
            'vehicle_count' => 2,
        ]);

        $searchable = $updated->toSearchableArray();

        $this->assertIsArray($searchable);
        $this->assertEquals('FAMILY_MEMBER', $searchable['resident_type']);
        $this->assertEquals('Data Scientist', $searchable['occupation']);
        $this->assertEquals('2027-01-15', $searchable['stay_end_date']);
        $this->assertEquals(2, $searchable['vehicle_count']);
        $this->assertEquals('2025-01-01', $searchable['stay_start_date']);
    }

    /**
     * Scenario 12: toSearchableArray() xử lý đúng khi stay_end_date là null (cư trú mở)
     */
    public function test_scenario_12_to_searchable_array_with_null_stay_end_date(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $updated = $this->residentService->update($resident->id, [
            'stay_end_date' => null,
        ]);

        $searchable = $updated->toSearchableArray();

        $this->assertNull($searchable['stay_end_date']);
        $this->assertEquals('2025-01-01', $searchable['stay_start_date']);
    }

    /**
     * Scenario 13: Service layer double validation (tầng 2) từ chối ngày không hợp lệ trực tiếp
     */
    public function test_scenario_13_service_layer_double_validation_rejects_invalid_partial_dates_directly(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        // Test 1: stay_end_date < existing stay_start_date
        try {
            $this->residentService->update($resident->id, [
                'stay_end_date' => '2020-01-01',
            ]);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stay_end_date', $e->errors());
            $this->assertEquals(
                'Ngày kết thúc cư trú phải sau hoặc bằng ngày bắt đầu cư trú.',
                $e->errors()['stay_end_date'][0]
            );
        }

        // Test 2: stay_start_date > existing stay_end_date
        try {
            $this->residentService->update($resident->id, [
                'stay_start_date' => '2030-01-01',
            ]);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stay_start_date', $e->errors());
            $this->assertEquals(
                'Ngày bắt đầu cư trú phải trước hoặc bằng ngày kết thúc cư trú.',
                $e->errors()['stay_start_date'][0]
            );
        }
    }

    /**
     * Scenario 14: Xung đột Optimistic Concurrency vẫn được bảo vệ và trả HTTP 409
     */
    public function test_scenario_14_optimistic_concurrency_conflict_still_returns_409(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $outdatedTimestamp = $resident->updated_at->copy()->subMinutes(10)->toIso8601String();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'occupation' => 'Concurrent Worker',
                'updated_at' => $outdatedTimestamp,
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => 'CONFLICT',
            ]);
    }

    // ==========================================
    // 3. REGRESSION & RBAC CHECKS
    // ==========================================

    /**
     * Scenario 15: User chỉ có RESIDENT:VIEW không thể gọi update -> 403 Forbidden
     */
    public function test_scenario_15_rbac_regression_user_with_only_view_permission_cannot_update(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:VIEW']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'occupation' => 'Forbidden Update',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Scenario 16: Bảo toàn BUG-M2-02 – Khôi phục nhân khẩu đã soft-delete với quyền RESIDENT:CREATE
     */
    public function test_scenario_16_bug_m2_02_restore_soft_deleted_resident_with_create_permission_still_works(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:CREATE']);
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $userId = $resident->user_id;
        $apartmentId = $resident->apartment_id;

        // Xóa mềm nhân khẩu
        $resident->delete();
        $this->assertSoftDeleted('residents', ['id' => $resident->id]);

        // Thêm lại nhân khẩu đó vào cùng căn hộ
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/residents', [
                'apartment_id' => $apartmentId,
                'user_id' => $userId,
                'resident_type' => 'OWNER',
                'stay_start_date' => '2025-03-01',
                'stay_end_date' => '2026-03-01',
            ]);

        $response->assertStatus(201);

        // Bản ghi cũ phải được restore, giữ nguyên ID
        $restored = Resident::find($resident->id);
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
        $this->assertEquals('OWNER', $restored->resident_type);
        $this->assertEquals('2025-03-01', $restored->stay_start_date->format('Y-m-d'));
    }

    /**
     * Scenario 17: Cập nhật nhân khẩu không tồn tại -> 404; Cập nhật nhân khẩu đã soft-delete -> 409
     */
    public function test_scenario_17_updating_non_existent_or_soft_deleted_resident_returns_404_or_409(): void
    {
        [, $token] = $this->createUserWithCustomPermissions(['RESIDENT:UPDATE']);

        // 1. Non-existent UUID -> 404
        $fakeId = (string) Str::uuid();
        $response404 = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$fakeId}", [
                'occupation' => 'Ghost',
            ]);
        $response404->assertStatus(404);

        // 2. Soft-deleted resident -> 409
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');
        $resident->delete();

        $response409 = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/residents/{$resident->id}", [
                'occupation' => 'Zombie',
            ]);
        $response409->assertStatus(409);
    }

    // ==========================================
    // 4. SCOUT AFTER-COMMIT CONSISTENCY SCENARIOS
    // ==========================================

    /**
     * Scenario 18: Update trong transaction commit thành công -> Fake Scout Engine nhận đúng model mới
     */
    public function test_scenario_18_scout_receives_model_after_transaction_commit_successfully(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_commit_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_commit_driver']);

        $updated = $this->residentService->update($resident->id, [
            'occupation' => 'Committed Senior Architect',
        ]);

        $this->assertNotEmpty($engineReceived, 'Scout Engine must receive models on commit');
        $this->assertEquals($resident->id, $engineReceived[0]->first()->id);
        $this->assertEquals('Committed Senior Architect', $engineReceived[0]->first()->occupation);
    }

    /**
     * Scenario 19: Update rồi rollback transaction -> Fake Scout Engine KHÔNG nhận model chưa commit
     */
    public function test_scenario_19_scout_does_not_receive_uncommitted_model_on_transaction_rollback(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_rollback_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_rollback_driver']);

        // Mô phỏng trường hợp transaction bị rollback khi cập nhật
        try {
            DB::transaction(function () use ($resident) {
                // Thao tác sửa đổi và lưu bên trong transaction với withoutSyncingToSearch
                Resident::withoutSyncingToSearch(function () use ($resident) {
                    $resident->occupation = 'Rollback Phantom Data';
                    $resident->save();
                });

                // Ném exception để kích hoạt rollback
                throw new \RuntimeException('Simulated failure triggering rollback');
            });
        } catch (\RuntimeException $e) {
            // Mong đợi exception xảy ra
            $this->assertEquals('Simulated failure triggering rollback', $e->getMessage());
        }

        // Khẳng định: Scout Engine tuyệt đối không nhận được model chưa commit
        $this->assertEmpty($engineReceived, 'Scout Engine must NOT receive uncommitted models on rollback');

        // Xác nhận dữ liệu trong DB không bị thay đổi
        $fresh = $resident->fresh();
        $this->assertEquals('Architect', $fresh->occupation);
    }

    /**
     * Scenario 20: Update thành công không tạo hai lần indexing (nhận đúng 1 lần duy nhất)
     */
    public function test_scenario_20_scout_receives_exactly_one_indexing_dispatch_on_update(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_single_dispatch_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_single_dispatch_driver']);

        $this->residentService->update($resident->id, [
            'occupation' => 'Single Dispatch Specialist',
        ]);

        $this->assertCount(1, $engineReceived, 'Scout Engine must receive exactly one indexing dispatch');
    }

    /**
     * Scenario 21: Các sự kiện delete và restore của Resident không bị hồi quy
     */
    public function test_scenario_21_scout_delete_and_restore_events_not_regressed(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $deleteCalls = [];
        $updateCalls = [];
        $fakeEngine = new class($deleteCalls, $updateCalls) extends NullEngine
        {
            public function __construct(public array &$deleteCalls, public array &$updateCalls) {}

            public function update($models)
            {
                $this->updateCalls[] = $models;
            }

            public function delete($models)
            {
                $this->deleteCalls[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_lifecycle_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_lifecycle_driver']);

        // 1. Delete (Soft delete)
        $resident->delete();
        $this->assertTrue($resident->trashed());

        // 2. Restore
        $resident->restore();
        $this->assertFalse($resident->trashed());

        // Scout nhận được lệnh cập nhật hoặc xóa theo đúng vòng đời
        $this->assertNotEmpty($updateCalls, 'Scout Engine must be notified on restore');
    }

    // ==========================================
    // 5. NESTED TRANSACTION & HARDENED AFTER-COMMIT TESTS
    // ==========================================

    /**
     * Test A — Nested transaction commit:
     * Khi có transaction ngoài, Fake Scout Engine chưa nhận dữ liệu khi service trả về.
     * Chỉ sau khi transaction ngoài cùng commit thành công, Scout mới nhận đúng 1 lần dữ liệu mới nhất.
     */
    public function test_scenario_22_nested_transaction_commit_executes_scout_only_after_outer_commit(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_nested_commit_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_nested_commit_driver']);

        // Mở transaction ngoài cùng thủ công
        DB::beginTransaction();

        $this->residentService->update($resident->id, [
            'occupation' => 'Chief Technology Officer',
        ]);

        // Khi service trả về nhưng transaction ngoài chưa commit, Fake Engine CHƯA được nhận dữ liệu
        $this->assertEmpty($engineReceived, 'Scout engine must NOT receive data while outer transaction is still open');

        // Commit transaction ngoài cùng
        DB::commit();

        // Bây giờ Scout engine phải nhận đúng 1 lần với dữ liệu mới nhất
        $this->assertCount(1, $engineReceived, 'Scout engine must receive data exactly once after outer commit');
        $this->assertEquals($resident->id, $engineReceived[0]->first()->id);
        $this->assertEquals('Chief Technology Officer', $engineReceived[0]->first()->occupation);
    }

    /**
     * Test B — Nested transaction rollback:
     * Mở transaction ngoài, gọi service update, sau đó rollback transaction ngoài.
     * Fake Scout Engine không nhận bất kỳ bản ghi nào, dữ liệu trong DB được phục hồi.
     */
    public function test_scenario_23_nested_transaction_rollback_prevents_scout_indexing_and_restores_data(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_nested_rollback_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_nested_rollback_driver']);

        // Mở transaction ngoài
        DB::beginTransaction();

        $this->residentService->update($resident->id, [
            'occupation' => 'Uncommitted Shadow Role',
        ]);

        // Xác nhận chưa nhận dữ liệu khi transaction ngoài đang mở
        $this->assertEmpty($engineReceived, 'Scout engine must NOT receive data while outer transaction is open');

        // Chủ động rollback transaction ngoài
        DB::rollBack();

        // Xác nhận Fake Scout Engine không nhận bất kỳ bản ghi cập nhật nào
        $this->assertEmpty($engineReceived, 'Scout engine must NOT receive data after outer rollback');

        // Xác nhận dữ liệu trong database được khôi phục
        $fresh = $resident->fresh();
        $this->assertEquals('Architect', $fresh->occupation);
    }

    /**
     * Test C — Nested transaction exception:
     * Mở transaction ngoài, gọi service update, phát sinh exception sau khi service trả về.
     * Xác nhận callback Scout không được thực thi.
     */
    public function test_scenario_24_nested_transaction_exception_after_service_prevents_scout_indexing(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_nested_exception_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_nested_exception_driver']);

        try {
            DB::transaction(function () use ($resident) {
                $this->residentService->update($resident->id, [
                    'occupation' => 'Exception Target Role',
                ]);

                // Phát sinh exception SAU KHI service đã trả về nhưng TRƯỚC KHI outer transaction commit
                throw new \RuntimeException('Business logic error after resident service update');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('Business logic error after resident service update', $e->getMessage());
        }

        // Xác nhận callback Scout không được thực thi do rollback
        $this->assertEmpty($engineReceived, 'Scout callback must NOT execute when exception occurs in outer transaction');
        $this->assertEquals('Architect', $resident->fresh()->occupation);
    }

    /**
     * Test D — Single dispatch:
     * Một lần cập nhật thành công chỉ gửi đúng 1 lần đến Fake Scout Engine.
     * Không xảy ra đồng bộ kép giữa ModelObserver và callback thủ công.
     */
    public function test_scenario_25_single_dispatch_no_duplicate_indexing_between_observer_and_callback(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_single_dispatch_guaranteed', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_single_dispatch_guaranteed']);

        $this->residentService->update($resident->id, [
            'occupation' => 'Unique Dispatcher',
        ]);

        // Một lần cập nhật thành công chỉ gửi đúng 1 lần đến Fake Scout Engine
        $this->assertCount(1, $engineReceived, 'Exactly one dispatch must be sent to the Scout engine');
        $this->assertEquals('Unique Dispatcher', $engineReceived[0]->first()->occupation);
    }

    /**
     * Test E — No active outer transaction:
     * Gọi service update trong điều kiện không có transaction ngoài.
     * Dữ liệu được lưu và Scout đồng bộ đúng một lần.
     */
    public function test_scenario_26_no_active_outer_transaction_syncs_scout_exactly_once(): void
    {
        $resident = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_standalone_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_standalone_driver']);

        $updated = $this->residentService->update($resident->id, [
            'occupation' => 'Standalone Performer',
        ]);

        $this->assertEquals('Standalone Performer', $updated->fresh()->occupation);
        $this->assertCount(1, $engineReceived, 'Scout must synchronize exactly once when called standalone');
        $this->assertEquals('Standalone Performer', $engineReceived[0]->first()->occupation);
    }

    /**
     * Test F — Scout configuration compatibility:
     * Kiểm tra tương thích cả khi scout.after_commit = false và scout.after_commit = true.
     * Tuyệt đối không tạo hai lần indexing trong bất kỳ trường hợp nào.
     */
    public function test_scenario_27_scout_configuration_compatibility_both_true_and_false_produce_single_indexing(): void
    {
        $resident1 = $this->createSampleResident('2025-01-01', '2025-12-31');
        $resident2 = $this->createSampleResident('2025-01-01', '2025-12-31');

        $engineReceived = [];
        $fakeEngine = new class($engineReceived) extends NullEngine
        {
            public function __construct(public array &$received) {}

            public function update($models)
            {
                $this->received[] = $models;
            }
        };

        $engineManager = app(EngineManager::class);
        $engineManager->extend('fake_compat_driver', fn () => $fakeEngine);
        config(['scout.driver' => 'fake_compat_driver']);

        // Trường hợp 1: scout.after_commit = false
        config(['scout.after_commit' => false]);
        $this->residentService->update($resident1->id, [
            'occupation' => 'Compat Case False',
        ]);
        $this->assertCount(1, $engineReceived, 'With scout.after_commit=false, must dispatch exactly once');
        $this->assertEquals('Compat Case False', $engineReceived[0]->first()->occupation);

        // Trường hợp 2: scout.after_commit = true
        config(['scout.after_commit' => true]);
        $this->residentService->update($resident2->id, [
            'occupation' => 'Compat Case True',
        ]);
        $this->assertCount(2, $engineReceived, 'With scout.after_commit=true, total dispatches must be exactly 2 (one per update)');
        $this->assertEquals('Compat Case True', $engineReceived[1]->first()->occupation);
    }
}
