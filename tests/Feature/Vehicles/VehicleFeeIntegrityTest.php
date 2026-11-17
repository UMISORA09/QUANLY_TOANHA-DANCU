<?php

namespace Tests\Feature\Vehicles;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\QuocTinRealtimeService;
use App\Services\VehicleService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class VehicleFeeIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected Block $block;

    protected Apartment $apartment;

    protected User $owner;

    protected User $receptionist;

    protected string $receptionistToken;

    protected User $superAdmin;

    protected string $superAdminToken;

    protected float $serverMotorbikeFee;

    protected float $serverMotorbikeVat;

    protected float $serverCarFee;

    protected float $serverCarVat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        QuocTinRealtimeService::clearModuleCooldown('vehicles');
        Cache::forget('pricing_config_PARKING_MOTORBIKE');
        Cache::forget('pricing_config_PARKING_CAR');
        Cache::forget('parking_pricing_configs_all');

        $suffix = Str::upper(Str::random(6));

        $this->block = Block::create([
            'block_code' => 'BLK_FEE_'.$suffix,
            'block_name' => 'Tòa Kiểm Thử Phí '.$suffix,
            'total_floors' => 5,
            'total_basements' => 1,
            'total_apartments' => 10,
        ]);

        $floorId = (string) Str::uuid();
        DB::table('floors')->insert([
            'id' => $floorId,
            'block_id' => $this->block->id,
            'floor_number' => 1,
            'floor_code' => 'FL-FEE-'.$suffix,
            'floor_name' => 'Tầng 1 Kiểm Thử Phí',
            'floor_type' => 'RESIDENTIAL',
            'total_units' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->apartment = Apartment::create([
            'block_id' => $this->block->id,
            'floor_id' => $floorId,
            'apartment_number' => 'VF-101-'.$suffix,
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);

        [$this->owner] = $this->createUserWithRole('RESIDENT_OWNER', 'fee_owner_');
        [$this->receptionist, $this->receptionistToken] = $this->createUserWithRole('RECEPTIONIST', 'fee_rec_');
        [$this->superAdmin, $this->superAdminToken] = $this->createUserWithRole('SUPER_ADMIN', 'fee_sa_');

        Resident::create([
            'user_id' => $this->owner->id,
            'apartment_id' => $this->apartment->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => now()->subYear()->toDateString(),
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ]);

        $service = app(VehicleService::class);
        $motoPricing = $service->getPricingForCategory('MOTORBIKE');
        $carPricing = $service->getPricingForCategory('CAR');

        $this->serverMotorbikeFee = (float) $motoPricing['monthly_parking_fee'];
        $this->serverMotorbikeVat = (float) $motoPricing['vat_percentage'];
        $this->serverCarFee = (float) $carPricing['monthly_parking_fee'];
        $this->serverCarVat = (float) $carPricing['vat_percentage'];
    }

    /**
     * Tạo người dùng kèm vai trò hệ thống và phiên Bearer token hợp lệ.
     *
     * @return array{0: User, 1: string}
     */
    protected function createUserWithRole(string $roleCode, string $prefix): array
    {
        $rand = Str::lower(Str::random(8));
        $user = User::create([
            'username' => $prefix.$rand,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => $prefix.$rand.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Fee Test '.$roleCode.' '.$rand,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', $roleCode)->firstOrFail();

        DB::table('user_roles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_primary' => 1,
            'assigned_at' => now(),
        ]);

        $token = 'smart_token_'.$user->id.'_'.Str::random(32);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'device_name' => 'PHPUnit Vehicle Fee Integrity',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDay(),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Header JSON kèm Bearer token.
     *
     * @return array<string, string>
     */
    protected function bearerHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }

    /**
     * Tính tổng tiền dự kiến sau thuế VAT.
     */
    protected function expectedLineTotal(float $fee, float $vatPct): float
    {
        return round($fee + round($fee * ($vatPct / 100), 2), 2);
    }

    /**
     * 1. Client gửi phí 1 đồng khi tạo xe: DB vehicles và invoice_items phải dùng đúng biểu phí server.
     */
    public function test_01_client_sending_1_vnd_on_create_uses_server_pricing(): void
    {
        $plate = '29F1-'.rand(10000, 99999);

        $response = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $plate,
                'vehicle_category' => 'MOTORBIKE',
                'monthly_parking_fee' => 1,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'license_plate' => $plate,
                    'monthly_parking_fee' => $this->serverMotorbikeFee,
                ],
            ]);

        $vehicle = Vehicle::where('license_plate', $plate)->firstOrFail();
        $this->assertEquals($this->serverMotorbikeFee, (float) $vehicle->monthly_parking_fee);

        $currentPeriod = Carbon::now()->format('Y-m');
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->apartment->id)
            ->where('billing_period', $currentPeriod)
            ->first();
        $this->assertNotNull($invoice);

        $item = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->first();
        $this->assertNotNull($item);
        $this->assertEquals($this->serverMotorbikeFee, (float) $item->unit_price);
        $this->assertEquals($this->serverMotorbikeFee, (float) $item->amount_before_tax);
        $this->assertEquals(
            $this->expectedLineTotal($this->serverMotorbikeFee, $this->serverMotorbikeVat),
            (float) $item->total_line_amount
        );
    }

    /**
     * 2. Client gửi phí 0.01 đồng khi tạo ô tô (kể cả SUPER_ADMIN qua HTTP): không thể thao túng phí.
     */
    public function test_02_client_sending_fractional_penny_fee_on_create_cannot_manipulate_fee(): void
    {
        $plate = '30F2-'.rand(10000, 99999);

        $response = $this->withHeaders($this->bearerHeaders($this->superAdminToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $plate,
                'vehicle_category' => 'CAR',
                'monthly_parking_fee' => 0.01,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'license_plate' => $plate,
                    'monthly_parking_fee' => $this->serverCarFee,
                ],
            ]);

        $vehicle = Vehicle::where('license_plate', $plate)->firstOrFail();
        $this->assertEquals($this->serverCarFee, (float) $vehicle->monthly_parking_fee);

        $item = DB::table('invoice_items')
            ->where('item_description', 'like', "%{$plate}%")
            ->first();
        $this->assertNotNull($item);
        $this->assertEquals($this->serverCarFee, (float) $item->unit_price);
        $this->assertEquals(
            $this->expectedLineTotal($this->serverCarFee, $this->serverCarVat),
            (float) $item->total_line_amount
        );
    }

    /**
     * 3. Client gửi phí quá cao khi tạo và cập nhật: không thể ghi đè mức phí server.
     */
    public function test_03_client_sending_excessive_fee_on_create_and_update_is_ignored(): void
    {
        $plate = '29F3-'.rand(10000, 99999);

        $createRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $plate,
                'vehicle_category' => 'MOTORBIKE',
                'monthly_parking_fee' => 99999999.00,
            ]);

        $createRes->assertStatus(201);
        $vehicleId = (string) $createRes->json('data.id');
        $this->assertEquals($this->serverMotorbikeFee, (float) $createRes->json('data.monthly_parking_fee'));

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $updateRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->putJson("/api/v1/vehicles/{$vehicleId}", [
                'color' => 'Bạc Ánh Kim',
                'monthly_parking_fee' => 50000000.00,
            ]);

        $updateRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $vehicleId,
                    'color' => 'Bạc Ánh Kim',
                    'monthly_parking_fee' => $this->serverMotorbikeFee,
                ],
            ]);

        $vehicle = Vehicle::findOrFail($vehicleId);
        $this->assertEquals($this->serverMotorbikeFee, (float) $vehicle->monthly_parking_fee);

        $item = DB::table('invoice_items')
            ->where('item_description', 'like', "%{$plate}%")
            ->first();
        $this->assertNotNull($item);
        $this->assertEquals($this->serverMotorbikeFee, (float) $item->unit_price);
    }

    /**
     * 4. Client gửi phí thấp hơn biểu phí khi cập nhật (PUT & PATCH): không được thay đổi phí theo payload.
     */
    public function test_04_client_sending_lower_fee_on_update_cannot_override_fee(): void
    {
        $plate = '30F4-'.rand(10000, 99999);
        $createRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $plate,
                'vehicle_category' => 'CAR',
            ]);
        $createRes->assertStatus(201);
        $vehicleId = (string) $createRes->json('data.id');

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $putRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->putJson("/api/v1/vehicles/{$vehicleId}", [
                'color' => 'Xám',
                'monthly_parking_fee' => 1.00,
            ]);
        $putRes->assertStatus(200);
        $this->assertEquals($this->serverCarFee, (float) $putRes->json('data.monthly_parking_fee'));

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $patchRes = $this->withHeaders($this->bearerHeaders($this->superAdminToken))
            ->patchJson("/api/v1/vehicles/{$vehicleId}", [
                'brand' => 'Mazda',
                'monthly_parking_fee' => 0.01,
            ]);
        $patchRes->assertStatus(200);
        $this->assertEquals($this->serverCarFee, (float) $patchRes->json('data.monthly_parking_fee'));

        $vehicle = Vehicle::findOrFail($vehicleId);
        $this->assertEquals($this->serverCarFee, (float) $vehicle->monthly_parking_fee);

        $item = DB::table('invoice_items')
            ->where('item_description', 'like', "%{$plate}%")
            ->first();
        $this->assertNotNull($item);
        $this->assertEquals($this->serverCarFee, (float) $item->unit_price);
        $this->assertEquals(
            $this->expectedLineTotal($this->serverCarFee, $this->serverCarVat),
            (float) $item->total_line_amount
        );
    }

    /**
     * 5 & 6. Thay đổi loại phương tiện qua API cập nhật tự động tính lại phí theo loại mới và đồng bộ hóa đơn.
     */
    public function test_05_changing_vehicle_category_recalculates_server_fee_and_syncs_invoice(): void
    {
        $plate = '29F5-'.rand(10000, 99999);
        $createRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $plate,
                'vehicle_category' => 'MOTORBIKE',
            ]);
        $createRes->assertStatus(201);
        $vehicleId = (string) $createRes->json('data.id');

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        // Đổi sang CAR đồng thời cố tình gửi kèm phí giả mạo 1 đồng và chữ thường 'car'
        $toCarRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->putJson("/api/v1/vehicles/{$vehicleId}", [
                'vehicle_category' => 'car',
                'monthly_parking_fee' => 1,
            ]);

        $toCarRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $vehicleId,
                    'vehicle_category' => 'CAR',
                    'monthly_parking_fee' => $this->serverCarFee,
                ],
            ]);

        $currentPeriod = Carbon::now()->format('Y-m');
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->apartment->id)
            ->where('billing_period', $currentPeriod)
            ->first();
        $this->assertNotNull($invoice);

        $carItem = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->first();
        $this->assertNotNull($carItem);
        $this->assertSame('PARKING_CAR', $carItem->service_code);
        $this->assertEquals($this->serverCarFee, (float) $carItem->unit_price);
        $this->assertEquals(
            $this->expectedLineTotal($this->serverCarFee, $this->serverCarVat),
            (float) $carItem->total_line_amount
        );
        $this->assertEquals(
            $this->expectedLineTotal($this->serverCarFee, $this->serverCarVat),
            (float) $invoice->total_amount
        );

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        // Đổi ngược từ CAR về MOTORBIKE và gửi kèm phí giả mạo cao hơn
        $toMotoRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->putJson("/api/v1/vehicles/{$vehicleId}", [
                'vehicle_category' => 'MOTORBIKE',
                'monthly_parking_fee' => 9999999,
            ]);

        $toMotoRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'vehicle_category' => 'MOTORBIKE',
                    'monthly_parking_fee' => $this->serverMotorbikeFee,
                ],
            ]);

        $motoItem = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->first();
        $this->assertNotNull($motoItem);
        $this->assertSame('PARKING_MOTORBIKE', $motoItem->service_code);
        $this->assertEquals($this->serverMotorbikeFee, (float) $motoItem->unit_price);
        $this->assertEquals(
            $this->expectedLineTotal($this->serverMotorbikeFee, $this->serverMotorbikeVat),
            (float) $motoItem->total_line_amount
        );

        $updatedInvoice = DB::table('invoices')->where('id', $invoice->id)->first();
        $this->assertEquals(
            $this->expectedLineTotal($this->serverMotorbikeFee, $this->serverMotorbikeVat),
            (float) $updatedInvoice->total_amount
        );
        $this->assertEquals((float) $updatedInvoice->total_amount, (float) $updatedInvoice->remaining_balance);
    }

    /**
     * 6 & 7. Đồng bộ hóa đơn dùng đúng mức phí server và không tạo bản ghi invoice_items trùng lặp.
     */
    public function test_06_invoice_sync_uses_server_fee_and_prevents_duplicate_invoice_items(): void
    {
        $plate = '29F6-'.rand(10000, 99999);
        $createRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $plate,
                'vehicle_category' => 'MOTORBIKE',
                'monthly_parking_fee' => 1,
            ]);
        $createRes->assertStatus(201);
        $vehicleId = (string) $createRes->json('data.id');

        $nextPeriod = Carbon::now()->addMonth()->format('Y-m');

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $firstSync = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson("/api/v1/vehicles/{$vehicleId}/sync-invoice", [
                'billing_period' => $nextPeriod,
            ]);
        $firstSync->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'synced' => true,
                    'amount_before_tax' => $this->serverMotorbikeFee,
                    'total_line_amount' => $this->expectedLineTotal($this->serverMotorbikeFee, $this->serverMotorbikeVat),
                ],
            ]);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $secondSync = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson("/api/v1/vehicles/{$vehicleId}/sync-invoice", [
                'billing_period' => $nextPeriod,
            ]);
        $secondSync->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'synced' => false,
                    'reason' => 'ALREADY_EXISTS',
                ],
            ]);

        $nextInvoice = DB::table('invoices')
            ->where('apartment_id', $this->apartment->id)
            ->where('billing_period', $nextPeriod)
            ->first();
        $this->assertNotNull($nextInvoice);

        $itemsCount = DB::table('invoice_items')
            ->where('invoice_id', $nextInvoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->count();
        $this->assertSame(1, $itemsCount);
    }

    /**
     * 8. Khi client không gửi monthly_parking_fee (hoặc gửi đúng payload kiểu UI hiện tại), tạo và cập nhật vẫn hoạt động bình thường.
     */
    public function test_07_create_and_update_without_fee_field_or_with_ui_payload_work_normally(): void
    {
        $plate = '29F7-'.rand(10000, 99999);
        $createRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $plate,
                'vehicle_category' => 'MOTORBIKE',
                'brand' => 'Honda',
                'model' => 'SH Mode',
                'color' => 'Trắng',
            ]);

        $createRes->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'license_plate' => $plate,
                    'monthly_parking_fee' => $this->serverMotorbikeFee,
                ],
            ]);

        $vehicleId = (string) $createRes->json('data.id');
        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        $updateWithoutFeeRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->putJson("/api/v1/vehicles/{$vehicleId}", [
                'color' => 'Đen Nhám',
            ]);

        $updateWithoutFeeRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'color' => 'Đen Nhám',
                    'monthly_parking_fee' => $this->serverMotorbikeFee,
                ],
            ]);

        // Tương thích payload UI (VehicleManagement.tsx luôn gửi kèm monthly_parking_fee)
        QuocTinRealtimeService::clearModuleCooldown('vehicles');
        $uiPlate = '29F8-'.rand(10000, 99999);
        $uiCreateRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $uiPlate,
                'vehicle_category' => 'CAR',
                'monthly_parking_fee' => $this->serverCarFee,
            ]);
        $uiCreateRes->assertStatus(201);
        $this->assertEquals($this->serverCarFee, (float) $uiCreateRes->json('data.monthly_parking_fee'));
    }

    /**
     * 9. Transaction phải rollback toàn bộ bản ghi phương tiện nếu bước đồng bộ hóa đơn thất bại.
     */
    public function test_08_transaction_rolls_back_vehicle_creation_when_invoice_sync_fails(): void
    {
        $plate = '29RB-'.rand(10000, 99999);
        $initialCount = Vehicle::count();

        $this->partialMock(VehicleService::class, function (MockInterface $mock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('syncVehicleParkingFeeToInvoice')
                ->once()
                ->andThrow(new \RuntimeException('Simulated invoice sync failure'));
        });

        $response = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->postJson('/api/v1/vehicles', [
                'apartment_id' => $this->apartment->id,
                'owner_user_id' => $this->owner->id,
                'license_plate' => $plate,
                'vehicle_category' => 'MOTORBIKE',
            ]);

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'error' => 'SERVER_ERROR',
            ]);

        $this->assertSame($initialCount, Vehicle::count());
        $this->assertNull(Vehicle::withTrashed()->where('license_plate', $plate)->first());
    }

    /**
     * 10. Phân biệt ranh giới HTTP (allowTrustedFee = false) và luồng nghiệp vụ nội bộ của server (allowTrustedFee = true).
     */
    public function test_09_internal_service_fee_updates_remain_supported_while_untrusted_mode_blocks_override(): void
    {
        $service = app(VehicleService::class);
        $internalPlate = '29IN-'.rand(10000, 99999);

        // Luồng nội bộ server thiết lập phí tùy chỉnh hợp lệ
        $created = $service->createVehicle([
            'apartment_id' => $this->apartment->id,
            'owner_user_id' => $this->owner->id,
            'license_plate' => $internalPlate,
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 150000.00,
        ], $this->superAdmin->id);

        /** @var Vehicle $vehicle */
        $vehicle = $created['vehicle'];
        $this->assertEquals(150000.00, (float) $vehicle->monthly_parking_fee);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        // Cập nhật thông tin khác qua HTTP API vẫn không bị client hạ phí về 1 đồng, và giữ nguyên phí nội bộ nếu không đổi loại xe
        $httpUpdate = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->putJson("/api/v1/vehicles/{$vehicle->id}", [
                'color' => 'Xanh Dương',
                'monthly_parking_fee' => 1,
            ]);
        $httpUpdate->assertStatus(200);
        $this->assertEquals(150000.00, (float) $httpUpdate->json('data.monthly_parking_fee'));

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        // Gọi trực tiếp service ở chế độ untrusted (allowTrustedFee = false) cũng ép dùng biểu phí server
        $untrustedPlate = '29UT-'.rand(10000, 99999);
        $untrusted = $service->createVehicle([
            'apartment_id' => $this->apartment->id,
            'owner_user_id' => $this->owner->id,
            'license_plate' => $untrustedPlate,
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 1,
        ], $this->superAdmin->id, false);

        $this->assertEquals($this->serverMotorbikeFee, (float) $untrusted['vehicle']->monthly_parking_fee);
    }

    /**
     * 11. Luồng nội bộ server gọi VehicleService::updateVehicle() với allowTrustedFee = true cập nhật phí, đồng bộ hóa đơn không trùng và vẫn chặn HTTP giả mạo phí.
     */
    public function test_10_internal_service_update_vehicle_with_allow_trusted_fee_updates_fee_and_syncs_invoice(): void
    {
        $service = app(VehicleService::class);
        $plate = '29UP-'.rand(10000, 99999);

        $created = $service->createVehicle([
            'apartment_id' => $this->apartment->id,
            'owner_user_id' => $this->owner->id,
            'license_plate' => $plate,
            'vehicle_category' => 'MOTORBIKE',
            'monthly_parking_fee' => 120000.00,
        ], $this->superAdmin->id);

        /** @var Vehicle $vehicle */
        $vehicle = $created['vehicle'];
        $this->assertEquals(120000.00, (float) $vehicle->monthly_parking_fee);

        $currentPeriod = Carbon::now()->format('Y-m');
        $invoice = DB::table('invoices')
            ->where('apartment_id', $this->apartment->id)
            ->where('billing_period', $currentPeriod)
            ->where('status', '!=', 'PAID')
            ->whereNull('deleted_at')
            ->first();
        $this->assertNotNull($invoice);

        $initialItems = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->get();
        $this->assertCount(1, $initialItems);
        $this->assertEquals(120000.00, (float) $initialItems->first()->unit_price);

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        // Luồng nội bộ server cập nhật phí từ 120.000 lên 150.000 với allowTrustedFee = true
        $updatedVehicle = $service->updateVehicle($vehicle->id, [
            'monthly_parking_fee' => 150000.00,
        ], true);

        $this->assertEquals(150000.00, (float) $updatedVehicle->monthly_parking_fee);

        $dbVehicle = DB::table('vehicles')->where('id', $vehicle->id)->first();
        $this->assertNotNull($dbVehicle);
        $this->assertEquals(150000.00, (float) $dbVehicle->monthly_parking_fee);

        // Xác nhận invoice_items được đồng bộ theo mức phí mới và không tạo bản ghi trùng
        $syncedItems = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->get();
        $this->assertCount(1, $syncedItems);

        $syncedItem = $syncedItems->first();
        $this->assertEquals(150000.00, (float) $syncedItem->unit_price);
        $this->assertEquals(150000.00, (float) $syncedItem->amount_before_tax);
        $this->assertEquals(
            $this->expectedLineTotal(150000.00, $this->serverMotorbikeVat),
            (float) $syncedItem->total_line_amount
        );

        QuocTinRealtimeService::clearModuleCooldown('vehicles');

        // Gọi lại luồng HTTP với payload giả mạo phí -> không được ghi đè phí 150.000
        $httpRes = $this->withHeaders($this->bearerHeaders($this->receptionistToken))
            ->putJson("/api/v1/vehicles/{$vehicle->id}", [
                'color' => 'Đỏ Đô',
                'monthly_parking_fee' => 1.00,
            ]);

        $httpRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $vehicle->id,
                    'color' => 'Đỏ Đô',
                    'monthly_parking_fee' => 150000.00,
                ],
            ]);

        $afterHttpVehicle = DB::table('vehicles')->where('id', $vehicle->id)->first();
        $this->assertNotNull($afterHttpVehicle);
        $this->assertEquals(150000.00, (float) $afterHttpVehicle->monthly_parking_fee);

        $itemsAfterHttp = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->where('item_description', 'like', "%{$plate}%")
            ->get();
        $this->assertCount(1, $itemsAfterHttp);
        $this->assertEquals(150000.00, (float) $itemsAfterHttp->first()->unit_price);
        $this->assertEquals(
            $this->expectedLineTotal(150000.00, $this->serverMotorbikeVat),
            (float) $itemsAfterHttp->first()->total_line_amount
        );
    }
}
