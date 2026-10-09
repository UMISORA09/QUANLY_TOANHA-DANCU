<?php

namespace Tests\Feature\Vehicles;

use App\Models\Apartment;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleConflictException;
use App\Services\VehicleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleManagementTest extends TestCase
{
    protected ?User $adminUser = null;

    protected ?string $adminToken = null;

    protected ?Apartment $testApartment = null;

    protected ?User $testOwner = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo tài khoản admin và token
        [$this->adminUser, $this->adminToken] = $this->createAdminUser();

        // 2. Tìm hoặc tạo căn hộ kiểm thử
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

        // 3. Tạo chủ sở hữu (cư dân)
        $this->testOwner = User::create([
            'username' => 'owner_v_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'owner_v_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Nguyễn Chủ Xe '.Str::random(4),
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Tạo tài khoản quản trị viên kèm phiên đăng nhập Bearer token
     */
    protected function createAdminUser(): array
    {
        $user = User::create([
            'username' => 'adm_v_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_v_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Vehicle Tester',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->first();
        if (! $role) {
            $role = Role::create([
                'id' => (string) Str::uuid(),
                'role_code' => 'SUPER_ADMIN',
                'role_name' => 'Quản trị viên cấp cao',
                'status' => 'ACTIVE',
            ]);
        }
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
            'device_name' => 'PHPUnit Vehicle Agent',
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

    /**
     * 1. Admin xem danh sách phương tiện thành công
     */
    public function test_01_admin_can_view_vehicle_list(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/vehicles');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    /**
     * 2. Tạo xe máy thành công với mức phí tự động từ cấu hình (120,000 VNĐ)
     */
    public function test_02_create_motorbike_successfully(): void
    {
        $plate = '29M1-'.rand(10000, 99999);
        $payload = [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'brand' => 'Honda',
            'model' => 'Air Blade 160',
            'color' => 'Xanh Đen',
            'registration_certificate_number' => 'CV-MOTO-'.rand(100000, 999999),
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'license_plate' => $plate,
                    'vehicle_category' => 'MOTORBIKE',
                    'monthly_parking_fee' => 120000.00,
                    'is_active' => true,
                ],
            ]);

        $this->assertDatabaseHas('vehicles', [
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
        ]);
    }

    /**
     * 3. Tạo ô tô thành công với mức phí tự động từ cấu hình (1,500,000 VNĐ)
     */
    public function test_03_create_car_successfully(): void
    {
        $plate = '30A-'.rand(10000, 99999);
        $payload = [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
            'brand' => 'VinFast',
            'model' => 'VF8',
            'color' => 'Trắng Ngọc Trai',
            'registration_certificate_number' => 'CV-CAR-'.rand(100000, 999999),
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'license_plate' => $plate,
                    'vehicle_category' => 'CAR',
                    'monthly_parking_fee' => 1500000.00,
                    'is_active' => true,
                ],
            ]);

        $this->assertDatabaseHas('vehicles', [
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
        ]);
    }

    /**
     * 4. Không cho tạo khi apartment không tồn tại
     */
    public function test_04_cannot_create_vehicle_with_nonexistent_apartment(): void
    {
        $payload = [
            'apartment_id' => (string) Str::uuid(),
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => '29A-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['apartment_id']);
    }

    /**
     * 5. Không cho tạo khi owner_user_id không hợp lệ
     */
    public function test_05_cannot_create_vehicle_with_invalid_owner(): void
    {
        $payload = [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => (string) Str::uuid(),
            'license_plate' => '29A-'.rand(10000, 99999),
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    /**
     * 6. Không cho biển số trống
     */
    public function test_06_cannot_create_vehicle_with_empty_license_plate(): void
    {
        $payload = [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => '',
            'vehicle_category' => 'MOTORBIKE',
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['license_plate']);
    }

    /**
     * 7. Không cho biển số trùng lặp
     */
    public function test_07_cannot_create_vehicle_with_duplicate_license_plate(): void
    {
        $plate = '51F-'.rand(10000, 99999);
        $payload = [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
        ];

        // Tạo lần 1 thành công
        $firstRes = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);
        $firstRes->assertStatus(201);

        // Tạo lần 2 cùng biển số -> Bị chặn
        $secondRes = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);

        $secondRes->assertStatus(422)
            ->assertJsonValidationErrors(['license_plate']);
    }

    /**
     * 8. Validation vehicle_category không hợp lệ
     */
    public function test_08_validation_vehicle_category_must_be_valid(): void
    {
        $payload = [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => '29B-'.rand(10000, 99999),
            'vehicle_category' => 'AIRPLANE', // Không hợp lệ
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_category']);
    }

    /**
     * 9. Lấy đúng cấu hình phí xe máy (PARKING_MOTORBIKE = 120,000)
     */
    public function test_09_get_correct_pricing_for_motorbike(): void
    {
        $service = app(VehicleService::class);
        $pricing = $service->getPricingForCategory('MOTORBIKE');

        $this->assertEquals('PARKING_MOTORBIKE', $pricing['service_code']);
        $this->assertEquals(120000.00, $pricing['monthly_parking_fee']);
        $this->assertEquals(10.00, $pricing['vat_percentage']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/vehicles/pricing-config?category=MOTORBIKE');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'service_code' => 'PARKING_MOTORBIKE',
                    'monthly_parking_fee' => 120000.00,
                ],
            ]);
    }

    /**
     * 10. Lấy đúng cấu hình phí ô tô (PARKING_CAR = 1,500,000)
     */
    public function test_10_get_correct_pricing_for_car(): void
    {
        $service = app(VehicleService::class);
        $pricing = $service->getPricingForCategory('CAR');

        $this->assertEquals('PARKING_CAR', $pricing['service_code']);
        $this->assertEquals(1500000.00, $pricing['monthly_parking_fee']);
        $this->assertEquals(10.00, $pricing['vat_percentage']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/vehicles/pricing-config?category=CAR');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'service_code' => 'PARKING_CAR',
                    'monthly_parking_fee' => 1500000.00,
                ],
            ]);
    }

    /**
     * 11. Đẩy phí gửi xe vào invoice flow hiện tại khi đăng ký phương tiện
     */
    public function test_11_parking_fee_is_automatically_pushed_to_invoice(): void
    {
        $plate = '43C-'.rand(10000, 99999);
        $payload = [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000.00,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/vehicles', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'invoice_sync' => [
                    'synced' => true,
                ],
            ]);

        $currentPeriod = Carbon::now()->format('Y-m');

        // Kiểm tra bảng invoices có hóa đơn cho căn hộ
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->testApartment->id)
            ->where('billing_period', $currentPeriod)
            ->first();

        $this->assertNotNull($invoice);

        // Kiểm tra bảng invoice_items có mục phí gửi xe chứa biển số
        $item = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals('PARKING_MOTORBIKE', $item->service_code);
        $this->assertEquals(120000.00, (float) $item->unit_price);
        $this->assertEquals(132000.00, (float) $item->total_line_amount); // 120k + 10% VAT
    }

    /**
     * 12. Không tạo duplicate parking fee trong cùng billing period
     */
    public function test_12_does_not_create_duplicate_parking_fee_in_same_billing_period(): void
    {
        $plate = '60B-'.rand(10000, 99999);
        $service = app(VehicleService::class);

        $res = $service->createVehicle([
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
            'monthly_parking_fee' => 1500000.00,
        ]);

        $vehicle = $res['vehicle'];
        $currentPeriod = Carbon::now()->format('Y-m');

        // Gọi đồng bộ lại lần 2 cho cùng kỳ thanh toán
        $syncAgain = $service->syncVehicleParkingFeeToInvoice($vehicle, $currentPeriod);

        $this->assertFalse($syncAgain['synced']);
        $this->assertEquals('ALREADY_EXISTS', $syncAgain['reason']);

        // Xác nhận trong invoice_items chỉ có DUY NHẤT 1 bản ghi cho biển số này
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->testApartment->id)
            ->where('billing_period', $currentPeriod)
            ->first();

        $count = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->count();

        $this->assertEquals(1, $count);
    }

    /**
     * 13. Update vehicle thành công
     */
    public function test_13_update_vehicle_successfully(): void
    {
        $plate = '75F-'.rand(10000, 99999);
        $service = app(VehicleService::class);
        $createRes = $service->createVehicle([
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'brand' => 'Yamaha',
            'color' => 'Đỏ',
        ]);
        $vehicleId = $createRes['vehicle']->id;

        $updatePayload = [
            'color' => 'Xám Bạc Kim Loại',
            'brand' => 'Yamaha Grande Hybrid',
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/vehicles/{$vehicleId}", $updatePayload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $vehicleId,
                    'color' => 'Xám Bạc Kim Loại',
                    'brand' => 'Yamaha Grande Hybrid',
                ],
            ]);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicleId,
            'color' => 'Xám Bạc Kim Loại',
        ]);
    }

    /**
     * 14. Soft delete vehicle bảo toàn dữ liệu tài chính
     */
    public function test_14_soft_delete_vehicle(): void
    {
        $plate = '88A-'.rand(10000, 99999);
        $service = app(VehicleService::class);
        $createRes = $service->createVehicle([
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
        ]);
        $vehicleId = $createRes['vehicle']->id;

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/v1/vehicles/{$vehicleId}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Kiểm tra deleted_at được cập nhật (xóa mềm), bản ghi vẫn tồn tại trong DB
        $deletedVehicle = Vehicle::withTrashed()->find($vehicleId);
        $this->assertNotNull($deletedVehicle);
        $this->assertNotNull($deletedVehicle->deleted_at);

        // Truy vấn bình thường không thấy
        $this->assertNull(Vehicle::find($vehicleId));
    }

    /**
     * 15. Concurrent registration cùng biển số không sinh 500 lỗi hệ thống
     */
    public function test_15_concurrent_registration_with_same_license_plate(): void
    {
        $plate = '99H-'.rand(10000, 99999);
        $service = app(VehicleService::class);

        // Mô phỏng 2 tiến trình tạo đồng thời cùng biển số
        $res1 = $service->createVehicle([
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
        ]);
        $this->assertNotNull($res1['vehicle']);

        // Tiến trình 2 gọi trực tiếp service với cùng biển số
        $this->expectException(VehicleConflictException::class);
        $service->createVehicle([
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
        ]);
    }

    /**
     * 16. Invoice retry/generation không làm tăng trùng số tiền
     */
    public function test_16_invoice_retry_does_not_duplicate_parking_fee(): void
    {
        $plate = '65A-'.rand(10000, 99999);
        $service = app(VehicleService::class);

        $res = $service->createVehicle([
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
            'monthly_parking_fee' => 1500000.00,
        ]);
        $vehicle = $res['vehicle'];

        $currentPeriod = Carbon::now()->format('Y-m');

        // Gọi API đồng bộ hóa đơn thử lại (retry)
        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/vehicles/{$vehicle->id}/sync-invoice", [
                'billing_period' => $currentPeriod,
            ]);

        $response->assertStatus(200);

        // Kiểm tra tổng tiền hóa đơn không bị nhân đôi
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->testApartment->id)
            ->where('billing_period', $currentPeriod)
            ->first();

        // Đảm bảo chỉ có đúng 1 item cho phương tiện này
        $items = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->get();

        $this->assertCount(1, $items);
        $this->assertEquals(1650000.00, (float) $items->first()->total_line_amount); // 1.5M + 10% VAT
    }

    /**
     * 17. Cập nhật biển số hoặc phí phương tiện tự động đồng bộ hóa đơn hiện hành chưa thanh toán
     */
    public function test_17_updating_vehicle_fee_or_plate_synchronizes_current_unpaid_invoice(): void
    {
        $oldPlate = '29A-'.rand(10000, 99999);
        $service = app(VehicleService::class);
        $createRes = $service->createVehicle([
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $oldPlate,
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000.00,
        ]);
        $vehicleId = $createRes['vehicle']->id;

        $newPlate = '29A-'.rand(10000, 99999);
        $updatePayload = [
            'license_plate' => $newPlate,
            'monthly_parking_fee' => 150000.00,
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/vehicles/{$vehicleId}", $updatePayload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $vehicleId,
                    'license_plate' => $newPlate,
                    'monthly_parking_fee' => 150000.00,
                ],
            ]);

        $currentPeriod = Carbon::now()->format('Y-m');
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->testApartment->id)
            ->where('billing_period', $currentPeriod)
            ->first();

        $this->assertNotNull($invoice);

        $item = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$newPlate}%")
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals(150000.00, (float) $item->unit_price);
        $this->assertEquals(165000.00, (float) $item->total_line_amount); // 150k + 10% VAT
    }
}
