<?php

namespace Tests\Feature\Vehicles;

use App\Models\Apartment;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionSecurityVehicleTest extends TestCase
{
    protected ?User $receptionistUser = null;

    protected ?string $receptionistToken = null;

    protected ?User $securityUser = null;

    protected ?string $securityToken = null;

    protected ?User $residentUser = null;

    protected ?string $residentToken = null;

    protected ?Apartment $testApartment = null;

    protected ?User $testOwner = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Tạo tài khoản Lễ Tân (RECEPTIONIST) kèm token
        [$this->receptionistUser, $this->receptionistToken] = $this->createUserWithRole('RECEPTIONIST', 'letan_test_');

        // 2. Tạo tài khoản An Ninh / Bảo Vệ (SECURITY_GUARD) kèm token
        [$this->securityUser, $this->securityToken] = $this->createUserWithRole('SECURITY_GUARD', 'security_test_');

        // 3. Tạo tài khoản Cư Dân không có quyền quản trị xe (RESIDENT_OWNER) kèm token
        [$this->residentUser, $this->residentToken] = $this->createUserWithRole('RESIDENT_OWNER', 'resident_test_');

        // 4. Tìm hoặc tạo căn hộ kiểm thử
        $this->testApartment = Apartment::first();
        if (! $this->testApartment) {
            $blockId = DB::table('blocks')->value('id') ?? (string) Str::uuid();
            $floorId = DB::table('floors')->value('id') ?? (string) Str::uuid();
            $this->testApartment = Apartment::create([
                'apartment_number' => 'REC-TEST-'.Str::random(4),
                'block_id' => $blockId,
                'floor_id' => $floorId,
                'status' => 'OCCUPIED',
                'room_type' => '2BR',
                'gross_floor_area_sqm' => 70.0,
                'net_usable_area_sqm' => 65.0,
            ]);
        }

        // 5. Tạo chủ xe kiểm thử
        $this->testOwner = User::create([
            'username' => 'owner_rec_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'owner_rec_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Đăng Ký Tại Quầy '.Str::random(4),
            'status' => 'ACTIVE',
        ]);
    }

    protected function createUserWithRole(string $roleCode, string $prefix): array
    {
        $user = User::create([
            'username' => $prefix.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Nhân Viên '.$roleCode,
            'status' => 'ACTIVE',
        ]);

        $role = Role::firstOrCreate(
            ['role_code' => $roleCode],
            [
                'role_name' => $roleCode,
                'description' => 'Test role '.$roleCode,
                'is_system_role' => true,
            ]
        );

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(30);
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
     * 1. Cổng Lễ Tân: Lễ tân có thể xem danh sách phương tiện
     */
    public function test_receptionist_can_view_vehicle_list(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/vehicles');

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
     * 2. Cổng Lễ Tân: Lễ tân đăng ký phương tiện mới và hệ thống tự động đẩy phí sang hóa đơn căn hộ
     */
    public function test_receptionist_can_register_vehicle_and_auto_push_parking_fee_to_invoice(): void
    {
        $plate = '29L1-'.rand(10000, 99999);
        $payload = [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'brand' => 'Honda',
            'model' => 'SH Mode',
            'color' => 'Trắng Sữa',
            'registration_certificate_number' => 'CV-REC-'.rand(100000, 999999),
        ];

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/vehicles', $payload);

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

        // Kiểm tra phương tiện đã được lưu vào database
        $vehicle = Vehicle::where('license_plate', $plate)->first();
        $this->assertNotNull($vehicle);

        // Kiểm tra Hóa đơn tự động phát sinh đúng kỳ hiện tại
        $currentPeriod = Carbon::now()->format('Y-m');
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->testApartment->id)
            ->where('billing_period', $currentPeriod)
            ->first();

        $this->assertNotNull($invoice, 'Hóa đơn kỳ hiện tại phải được tự động khởi tạo khi lễ tân đăng ký xe.');

        // Kiểm tra dòng mục phí gửi xe trong hóa đơn
        $invoiceItem = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->first();

        $this->assertNotNull($invoiceItem, 'Mục phí trông giữ phương tiện phải được tự động ghi nhận vào chi tiết hóa đơn.');
        $this->assertEquals(120000.00, (float) $invoiceItem->amount_before_tax);
        $this->assertEquals(132000.00, (float) $invoiceItem->total_line_amount); // 120k + 10% VAT
    }

    /**
     * 3. Cổng Lễ Tân & An Ninh: Phê duyệt phương tiện và kích hoạt đẩy phí vào kỳ hóa đơn
     */
    public function test_receptionist_can_approve_pending_vehicle_and_sync_invoice(): void
    {
        $plate = '30H-'.rand(10000, 99999);
        $service = app(VehicleService::class);

        // Giả lập phương tiện cư dân đăng ký qua cổng cư dân đang chờ duyệt (approved_by null, is_active false)
        $vehicle = Vehicle::create([
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'CAR',
            'monthly_parking_fee' => 1500000.00,
            'is_active' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        $this->assertFalse((bool) $vehicle->is_active);

        // Lễ tân hoặc An ninh duyệt phương tiện tại quầy
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->receptionistToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/vehicles/{$vehicle->id}/approve");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $vehicle->id,
                    'is_active' => true,
                    'approved_by' => $this->receptionistUser->id,
                ],
            ]);

        // Xác nhận phí ô tô 1,500,000 đã tự động đẩy vào hóa đơn
        $currentPeriod = Carbon::now()->format('Y-m');
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->testApartment->id)
            ->where('billing_period', $currentPeriod)
            ->first();

        $this->assertNotNull($invoice);

        $item = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals(1500000.00, (float) $item->amount_before_tax);
        $this->assertEquals(1650000.00, (float) $item->total_line_amount);
    }

    /**
     * 4. Cổng An Ninh: Nhân viên Bảo vệ / An ninh (SECURITY_GUARD) có quyền xem và đăng ký xe
     */
    public function test_security_guard_can_view_and_manage_vehicles(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->securityToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/vehicles');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $plate = '59S-'.rand(10000, 99999);
        $createResponse = $this->withHeaders([
            'Authorization' => "Bearer {$this->securityToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/vehicles', [
            'apartment_id' => $this->testApartment->id,
            'owner_user_id' => $this->testOwner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
        ]);

        $createResponse->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 5. Cư Dân không có quyền quản lý xe (RESIDENT_OWNER) bị từ chối 403 Forbidden
     */
    public function test_unauthorized_resident_cannot_access_vehicle_management_apis(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->residentToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/vehicles');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => 'FORBIDDEN',
            ]);
    }

    /**
     * 6. Demo token fallback smart_token_reception_demo hoạt động chính xác
     */
    public function test_reception_demo_token_fallback(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer smart_token_reception_demo',
            'Accept' => 'application/json',
        ])->getJson('/api/v1/vehicles');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }
}
