<?php

namespace Tests\Feature\RfidManagement;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AccessCardService;
use App\Services\QuocTinRealtimeService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RfidApartmentRelationshipAndDateValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected Apartment $apartmentA;

    protected Apartment $apartmentB;

    protected User $residentUserA;

    protected Resident $residentRecordA;

    protected User $residentUserB;

    protected Resident $residentRecordB;

    protected Vehicle $vehicleA;

    protected Vehicle $vehicleB;

    protected User $adminUser;

    protected string $adminToken;

    protected AccessCardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->service = app(AccessCardService::class);

        QuocTinRealtimeService::clearModuleCooldown('rfid_cards');

        // Tạo 2 căn hộ khác nhau
        $blockId = DB::table('blocks')->value('id') ?? (string) Str::uuid();
        $floorId = DB::table('floors')->value('id') ?? (string) Str::uuid();

        $this->apartmentA = Apartment::create([
            'apartment_number' => 'APT-A-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 80.0,
            'net_usable_area_sqm' => 75.0,
        ]);

        $this->apartmentB = Apartment::create([
            'apartment_number' => 'APT-B-'.rand(1000, 9999),
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 80.0,
            'net_usable_area_sqm' => 75.0,
        ]);

        // Cư dân thuộc Căn hộ A
        $this->residentUserA = User::create([
            'username' => 'res_a_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_a_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Nguyễn Cư Dân A '.Str::random(4),
            'status' => 'ACTIVE',
        ]);
        $this->residentRecordA = Resident::create([
            'user_id' => $this->residentUserA->id,
            'apartment_id' => $this->apartmentA->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        // Cư dân thuộc Căn hộ B
        $this->residentUserB = User::create([
            'username' => 'res_b_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_b_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Trần Cư Dân B '.Str::random(4),
            'status' => 'ACTIVE',
        ]);
        $this->residentRecordB = Resident::create([
            'user_id' => $this->residentUserB->id,
            'apartment_id' => $this->apartmentB->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => now()->subMonths(6)->toDateString(),
            'stay_end_date' => now()->addYear()->toDateString(),
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        // Phương tiện thuộc Căn hộ A
        $this->vehicleA = Vehicle::create([
            'apartment_id' => $this->apartmentA->id,
            'owner_user_id' => $this->residentUserA->id,
            'license_plate' => '29A-'.rand(10000, 99999),
            'vehicle_category' => 'CAR_4_SEATS',
            'brand' => 'Toyota',
            'model' => 'Camry',
            'monthly_parking_fee' => 1200000,
            'is_active' => true,
        ]);

        // Phương tiện thuộc Căn hộ B
        $this->vehicleB = Vehicle::create([
            'apartment_id' => $this->apartmentB->id,
            'owner_user_id' => $this->residentUserB->id,
            'license_plate' => '29B-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
            'brand' => 'Honda',
            'model' => 'SH 150i',
            'monthly_parking_fee' => 120000,
            'is_active' => true,
        ]);

        // Tạo Admin có quyền RFID:CREATE, RFID:UPDATE
        [$this->adminUser, $this->adminToken] = $this->createAdminUser();
    }

    protected function createAdminUser(): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create([
            'username' => 'admin_rfid_m504_'.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'admin_m504_'.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin RFID M504 Tester '.$rand,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->firstOrFail();

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
            'device_name' => 'PHPUnit RFID M504 Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => 'application/json',
        ];
    }

    // =========================================================================
    // NHÓM 1: KIỂM TRA QUAN HỆ CĂN HỘ VỚI USER & VEHICLE (PHẦN A)
    // =========================================================================

    /**
     * 1. Gán user hợp lệ thuộc đúng căn hộ -> Chấp nhận (201 Created)
     */
    public function test_01_assign_valid_user_belonging_to_apartment_succeeds(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->apartmentA->id,
            'assigned_user_id' => $this->residentUserA->id,
            'issued_date' => now()->toDateString(),
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'assigned_apartment_id' => $this->apartmentA->id,
                    'assigned_user_id' => $this->residentUserA->id,
                ],
            ]);
    }

    /**
     * 2. Gán user thuộc căn hộ khác -> Bị từ chối (422 Unprocessable Entity)
     */
    public function test_02_assign_user_belonging_to_different_apartment_is_rejected(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->apartmentA->id,
            'assigned_user_id' => $this->residentUserB->id, // Cư dân thuộc căn hộ B
            'issued_date' => now()->toDateString(),
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    /**
     * 3. Gán vehicle hợp lệ thuộc đúng căn hộ -> Chấp nhận (201 Created)
     */
    public function test_03_assign_valid_vehicle_belonging_to_apartment_succeeds(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'PARKING_ONLY',
            'assigned_apartment_id' => $this->apartmentA->id,
            'assigned_vehicle_id' => $this->vehicleA->id,
            'issued_date' => now()->toDateString(),
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'assigned_apartment_id' => $this->apartmentA->id,
                    'assigned_vehicle_id' => $this->vehicleA->id,
                ],
            ]);
    }

    /**
     * 4. Gán vehicle thuộc căn hộ khác -> Bị từ chối (422 Unprocessable Entity)
     */
    public function test_04_assign_vehicle_belonging_to_different_apartment_is_rejected(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'PARKING_ONLY',
            'assigned_apartment_id' => $this->apartmentA->id,
            'assigned_vehicle_id' => $this->vehicleB->id, // Phương tiện thuộc căn hộ B
            'issued_date' => now()->toDateString(),
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_vehicle_id']);
    }

    /**
     * 5. Gửi user ID không tồn tại -> Bị từ chối (422 Unprocessable Entity)
     */
    public function test_05_non_existent_user_id_is_rejected(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'assigned_apartment_id' => $this->apartmentA->id,
            'assigned_user_id' => (string) Str::uuid(),
            'issued_date' => now()->toDateString(),
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    /**
     * 6. Gửi vehicle ID không tồn tại -> Bị từ chối (422 Unprocessable Entity)
     */
    public function test_06_non_existent_vehicle_id_is_rejected(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'PARKING_ONLY',
            'assigned_apartment_id' => $this->apartmentA->id,
            'assigned_vehicle_id' => (string) Str::uuid(),
            'issued_date' => now()->toDateString(),
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_vehicle_id']);
    }

    // =========================================================================
    // NHÓM 2: KIỂM TRA NGÀY CẤP & NGÀY HẾT HẠN (PHẦN B)
    // =========================================================================

    /**
     * 7. Ngày hết hạn bằng ngày cấp -> Xử lý đúng, chấp nhận (201 Created)
     */
    public function test_07_expiry_date_equal_to_issued_date_is_accepted(): void
    {
        $today = now()->toDateString();
        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'VISITOR_PASS',
            'issued_date' => $today,
            'expiry_date' => $today,
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'issued_date' => $today,
                    'expiry_date' => $today,
                ],
            ]);
    }

    /**
     * 8. Ngày hết hạn sau ngày cấp -> Chấp nhận (201 Created)
     */
    public function test_08_expiry_date_after_issued_date_is_accepted(): void
    {
        $issued = now()->toDateString();
        $expiry = now()->addYear()->toDateString();

        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'issued_date' => $issued,
            'expiry_date' => $expiry,
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'issued_date' => $issued,
                    'expiry_date' => $expiry,
                ],
            ]);
    }

    /**
     * 9. Ngày hết hạn trước ngày cấp -> Bị từ chối (422 Unprocessable Entity)
     */
    public function test_09_expiry_date_before_issued_date_is_rejected(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-'.Str::random(6),
            'card_number' => 'CARD-M504-'.Str::random(4),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'issued_date' => '2026-11-15',
            'expiry_date' => '2026-11-14', // Hạn trước ngày cấp
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expiry_date']);
    }

    /**
     * 10. Cập nhật ngày cấp/ngày hết hạn không hợp lệ phải bị từ chối (422)
     */
    public function test_10_update_expiry_date_before_issued_date_is_rejected(): void
    {
        // Tạo thẻ ban đầu hợp lệ
        $card = $this->service->createCard([
            'card_uid' => 'UID-UPD-DATE-'.Str::random(6),
            'card_number' => 'CARD-UPD-DATE-'.Str::random(4),
            'issued_date' => '2026-06-01',
            'expiry_date' => '2027-06-01',
            'status' => 'ACTIVE',
        ], $this->adminUser->id);

        // Cập nhật expiry_date trước issued_date có sẵn (không gửi issued_date)
        $updatePayload = [
            'expiry_date' => '2026-05-31',
        ];

        $response = $this->putJson("/api/v1/rfid-cards/{$card->id}", $updatePayload, $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expiry_date']);
    }

    /**
     * 11. Trường hợp null hoặc thiếu ngày tuân thủ đúng nghiệp vụ (201 Created)
     */
    public function test_11_null_or_missing_dates_behave_correctly(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-NODATE-'.Str::random(6),
            'card_number' => 'CARD-M504-NODATE-'.Str::random(4),
            'card_type' => 'RESIDENT_ALL_ACCESS',
            'status' => 'ACTIVE',
            // Không truyền issued_date và expiry_date
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertEquals(now()->toDateString(), $response->json('data.issued_date'));
        $this->assertNull($response->json('data.expiry_date'));
    }

    // =========================================================================
    // NHÓM 3: TOÀN VẸN SERVICE TRỰC TIẾP & CẬP NHẬT CHÉO (PHẦN C)
    // =========================================================================

    /**
     * 12. Gọi trực tiếp Service không thể bỏ qua các ràng buộc quan trọng (throws ValidationException)
     */
    public function test_12_direct_service_calls_enforce_apartment_and_date_constraints(): void
    {
        // A. Service bắt lỗi gán user chéo căn hộ
        try {
            $this->service->createCard([
                'card_uid' => 'UID-SVC-1-'.Str::random(6),
                'card_number' => 'NUM-SVC-1-'.Str::random(4),
                'assigned_apartment_id' => $this->apartmentA->id,
                'assigned_user_id' => $this->residentUserB->id,
            ]);
            $this->fail('Service must throw ValidationException when assigning cross-apartment resident.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assigned_user_id', $e->errors());
        }

        // B. Service bắt lỗi gán xe chéo căn hộ
        try {
            $this->service->createCard([
                'card_uid' => 'UID-SVC-2-'.Str::random(6),
                'card_number' => 'NUM-SVC-2-'.Str::random(4),
                'assigned_apartment_id' => $this->apartmentA->id,
                'assigned_vehicle_id' => $this->vehicleB->id,
            ]);
            $this->fail('Service must throw ValidationException when assigning cross-apartment vehicle.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assigned_vehicle_id', $e->errors());
        }

        // C. Service bắt lỗi ngày hết hạn trước ngày cấp
        try {
            $this->service->createCard([
                'card_uid' => 'UID-SVC-3-'.Str::random(6),
                'card_number' => 'NUM-SVC-3-'.Str::random(4),
                'issued_date' => '2026-12-01',
                'expiry_date' => '2026-11-30',
            ]);
            $this->fail('Service must throw ValidationException when expiry_date < issued_date.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expiry_date', $e->errors());
        }
    }

    /**
     * 13. Cập nhật thẻ: Gán user thuộc căn hộ khác bị từ chối (422)
     */
    public function test_13_update_card_to_cross_apartment_user_is_rejected(): void
    {
        $card = $this->service->createCard([
            'card_uid' => 'UID-UPD-USR-'.Str::random(6),
            'card_number' => 'NUM-UPD-USR-'.Str::random(4),
            'assigned_apartment_id' => $this->apartmentA->id,
            'assigned_user_id' => $this->residentUserA->id,
        ], $this->adminUser->id);

        $response = $this->putJson("/api/v1/rfid-cards/{$card->id}", [
            'assigned_user_id' => $this->residentUserB->id, // Đổi sang cư dân căn hộ B
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_user_id']);
    }

    /**
     * 14. Cập nhật thẻ: Gán vehicle thuộc căn hộ khác bị từ chối (422)
     */
    public function test_14_update_card_to_cross_apartment_vehicle_is_rejected(): void
    {
        $card = $this->service->createCard([
            'card_uid' => 'UID-UPD-VEH-'.Str::random(6),
            'card_number' => 'NUM-UPD-VEH-'.Str::random(4),
            'assigned_apartment_id' => $this->apartmentA->id,
            'assigned_vehicle_id' => $this->vehicleA->id,
        ], $this->adminUser->id);

        $response = $this->putJson("/api/v1/rfid-cards/{$card->id}", [
            'assigned_vehicle_id' => $this->vehicleB->id, // Đổi sang xe căn hộ B
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_vehicle_id']);
    }

    /**
     * 15. Gán vehicle mà không có assigned_apartment_id bị từ chối (422)
     */
    public function test_15_assigning_vehicle_without_apartment_is_rejected(): void
    {
        $payload = [
            'card_uid' => 'UID-M504-NOAPT-'.Str::random(6),
            'card_number' => 'CARD-M504-NOAPT-'.Str::random(4),
            'card_type' => 'PARKING_ONLY',
            'assigned_vehicle_id' => $this->vehicleA->id,
            'issued_date' => now()->toDateString(),
            'status' => 'ACTIVE',
        ];

        $response = $this->postJson('/api/v1/rfid-cards', $payload, $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_apartment_id']);
    }
}
