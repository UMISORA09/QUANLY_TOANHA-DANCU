<?php

namespace Tests\Feature\ServicePricing;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\PricingTier;
use App\Models\Role;
use App\Models\ServicePricingConfig;
use App\Models\User;
use App\Services\InvoiceGenerationService;
use Database\Seeders\ServicePricingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServicePricingManagementTest extends TestCase
{
    protected string $adminToken = 'smart_token_admin_test_pricing';

    protected function setUp(): void
    {
        parent::setUp();

        $adminUser = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'phone_number' => '0900000001',
                'email' => 'admin@cassavas.vn',
                'password_hash' => bcrypt('password123'),
                'full_name' => 'System Administrator',
                'status' => 'ACTIVE',
            ]
        );
        $role = Role::firstOrCreate(
            ['role_code' => 'SUPER_ADMIN'],
            ['role_name' => 'Quản trị viên cấp cao', 'status' => 'ACTIVE']
        );
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $adminUser->id, 'role_id' => $role->id],
            ['id' => (string) Str::uuid(), 'is_primary' => 1, 'assigned_at' => now()]
        );
        $this->adminToken = $this->issueTestBearerToken($adminUser);

        // Chạy seeder nạp dữ liệu mẫu biểu giá nếu chưa có
        if (ServicePricingConfig::count() === 0) {
            $this->seed(ServicePricingSeeder::class);
        }
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->adminToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Test 01: Lấy danh mục cấu hình đơn giá kèm các bậc thang
     */
    public function test_01_can_list_pricing_configs_with_tiers(): void
    {
        $response = $this->withHeaders($this->authHeaders())->getJson('/api/v1/pricing-configs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'service_code',
                        'service_name',
                        'billing_type',
                        'unit_name',
                        'vat_percentage',
                        'is_active',
                        'tiers',
                    ],
                ],
                'total',
            ]);
    }

    /**
     * Test 02: Tạo mới cấu hình đơn giá cố định (vd: Phí vệ sinh môi trường bổ sung)
     */
    public function test_02_can_create_new_fixed_pricing_config(): void
    {
        $payload = [
            'service_code' => 'TEST_CLEANING_FEE_'.Str::upper(Str::random(4)),
            'service_name' => 'Phí Vệ Sinh Đặc Biệt Thử Nghiệm',
            'billing_type' => 'FIXED_MONTHLY',
            'unit_name' => 'tháng',
            'fixed_unit_price' => 85000,
            'vat_percentage' => 10,
            'environmental_protection_fee_pct' => 0,
            'effective_from_date' => '2026-01-01',
            'is_active' => true,
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/pricing-configs', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.service_code', $payload['service_code'])
            ->assertJsonPath('data.fixed_unit_price', 85000);

        $this->assertDatabaseHas('service_pricing_configs', [
            'service_code' => $payload['service_code'],
            'fixed_unit_price' => 85000,
        ]);
    }

    /**
     * Test 03: Tạo mới cấu hình biểu giá lũy tiến kèm các bậc thang
     */
    public function test_03_can_create_new_tiered_pricing_config_with_tiers(): void
    {
        $code = 'TEST_WATER_TIERED_'.Str::upper(Str::random(4));
        $payload = [
            'service_code' => $code,
            'service_name' => 'Nước Kinh Doanh Bậc Thang',
            'meter_type' => 'COLD_WATER',
            'billing_type' => 'TIERED_USAGE',
            'unit_name' => 'm3',
            'vat_percentage' => 5,
            'environmental_protection_fee_pct' => 10,
            'effective_from_date' => '2026-01-01',
            'is_active' => true,
            'tiers' => [
                [
                    'tier_order' => 1,
                    'tier_name' => 'Bậc 1: Dưới 15m3',
                    'min_usage_threshold' => 0,
                    'max_usage_threshold' => 15,
                    'unit_price' => 11000,
                ],
                [
                    'tier_order' => 2,
                    'tier_name' => 'Bậc 2: Trên 15m3',
                    'min_usage_threshold' => 15,
                    'max_usage_threshold' => null,
                    'unit_price' => 16000,
                ],
            ],
        ];

        $response = $this->withHeaders($this->authHeaders())->postJson('/api/v1/pricing-configs', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.service_code', $code);

        $created = ServicePricingConfig::where('service_code', $code)->first();
        $this->assertNotNull($created);
        $this->assertCount(2, $created->tiers);
    }

    /**
     * Test 04: Cập nhật thông tin cấu hình đơn giá
     */
    public function test_04_can_update_pricing_config(): void
    {
        $config = ServicePricingConfig::firstOrCreate(
            ['service_code' => 'MANAGEMENT_FEE_TEST'],
            [
                'service_name' => 'Phí Quản Lý Thử Nghiệm',
                'billing_type' => 'UNIT_PRICE_USAGE',
                'unit_name' => 'm2/tháng',
                'fixed_unit_price' => 14000,
                'vat_percentage' => 10,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        $response = $this->withHeaders($this->authHeaders())->putJson("/api/v1/pricing-configs/{$config->id}", [
            'fixed_unit_price' => 15500,
            'service_name' => 'Phí Quản Lý Điều Chỉnh 2026',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.fixed_unit_price', 15500);

        $this->assertDatabaseHas('service_pricing_configs', [
            'id' => $config->id,
            'fixed_unit_price' => 15500,
        ]);
    }

    /**
     * Test 05: Lấy danh sách bậc thang định mức
     */
    public function test_05_can_get_tiers_of_pricing_config(): void
    {
        $electricity = ServicePricingConfig::where('service_code', 'ELECTRICITY_RESIDENTIAL')->first();
        if (! $electricity) {
            $this->seed(ServicePricingSeeder::class);
            $electricity = ServicePricingConfig::where('service_code', 'ELECTRICITY_RESIDENTIAL')->firstOrFail();
        }

        $response = $this->withHeaders($this->authHeaders())->getJson("/api/v1/pricing-configs/{$electricity->id}/tiers");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'tier_order',
                        'tier_name',
                        'min_usage_threshold',
                        'max_usage_threshold',
                        'unit_price',
                    ],
                ],
            ]);
    }

    /**
     * Test 06: Cập nhật định mức bậc thang giá hợp lệ
     */
    public function test_06_can_sync_and_update_pricing_tiers_successfully(): void
    {
        $config = ServicePricingConfig::create([
            'id' => (string) Str::uuid(),
            'service_code' => 'TIER_SYNC_TEST_'.Str::upper(Str::random(4)),
            'service_name' => 'Kiểm Thử Sync Bậc Thang',
            'meter_type' => 'COLD_WATER',
            'billing_type' => 'TIERED_USAGE',
            'unit_name' => 'm3',
            'vat_percentage' => 5,
            'effective_from_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $newTiers = [
            [
                'tier_order' => 1,
                'tier_name' => 'Bậc 1 (0 - 10 m3)',
                'min_usage_threshold' => 0,
                'max_usage_threshold' => 10,
                'unit_price' => 8000,
            ],
            [
                'tier_order' => 2,
                'tier_name' => 'Bậc 2 (10 - 20 m3)',
                'min_usage_threshold' => 10,
                'max_usage_threshold' => 20,
                'unit_price' => 10500,
            ],
            [
                'tier_order' => 3,
                'tier_name' => 'Bậc 3 (Trên 20 m3)',
                'min_usage_threshold' => 20,
                'max_usage_threshold' => null,
                'unit_price' => 14000,
            ],
        ];

        $response = $this->withHeaders($this->authHeaders())->putJson("/api/v1/pricing-configs/{$config->id}/tiers", [
            'tiers' => $newTiers,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertEquals(3, PricingTier::where('pricing_config_id', $config->id)->count());
    }

    /**
     * Test 07: Validate báo lỗi khi bậc 1 không bắt đầu từ 0
     */
    public function test_07_fails_when_pricing_tiers_range_not_starting_at_zero(): void
    {
        $config = ServicePricingConfig::create([
            'id' => (string) Str::uuid(),
            'service_code' => 'TIER_FAIL_TEST_'.Str::upper(Str::random(4)),
            'service_name' => 'Kiểm Thử Lỗi Bậc 1',
            'meter_type' => 'ELECTRICITY',
            'billing_type' => 'TIERED_USAGE',
            'unit_name' => 'kWh',
            'vat_percentage' => 10,
            'effective_from_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $invalidTiers = [
            [
                'tier_order' => 1,
                'tier_name' => 'Bậc 1 sai min',
                'min_usage_threshold' => 10, // Bắt buộc phải là 0
                'max_usage_threshold' => 50,
                'unit_price' => 1800,
            ],
        ];

        $response = $this->withHeaders($this->authHeaders())->putJson("/api/v1/pricing-configs/{$config->id}/tiers", [
            'tiers' => $invalidTiers,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'INVALID_TIER_RANGE',
            ]);
    }

    /**
     * Test 08: Validate báo lỗi khi có khoảng hở giữa các bậc (vd Bậc 1 max=50, Bậc 2 min=60)
     */
    public function test_08_fails_when_pricing_tiers_have_gap_between_max_and_min(): void
    {
        $config = ServicePricingConfig::create([
            'id' => (string) Str::uuid(),
            'service_code' => 'TIER_GAP_TEST_'.Str::upper(Str::random(4)),
            'service_name' => 'Kiểm Thử Gap Bậc',
            'meter_type' => 'ELECTRICITY',
            'billing_type' => 'TIERED_USAGE',
            'unit_name' => 'kWh',
            'vat_percentage' => 10,
            'effective_from_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $gapTiers = [
            [
                'tier_order' => 1,
                'tier_name' => 'Bậc 1',
                'min_usage_threshold' => 0,
                'max_usage_threshold' => 50,
                'unit_price' => 1800,
            ],
            [
                'tier_order' => 2,
                'tier_name' => 'Bậc 2 hở khoảng',
                'min_usage_threshold' => 55, // Phải là 50
                'max_usage_threshold' => null,
                'unit_price' => 2000,
            ],
        ];

        $response = $this->withHeaders($this->authHeaders())->putJson("/api/v1/pricing-configs/{$config->id}/tiers", [
            'tiers' => $gapTiers,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'INVALID_TIER_RANGE',
            ]);
    }

    /**
     * Test 09: Mô phỏng tính tiền điện lũy tiến chính xác theo từng bậc
     */
    public function test_09_can_simulate_tiered_electricity_calculation_accurately(): void
    {
        $electricity = ServicePricingConfig::where('service_code', 'ELECTRICITY_RESIDENTIAL')->first();
        if (! $electricity) {
            $this->seed(ServicePricingSeeder::class);
            $electricity = ServicePricingConfig::where('service_code', 'ELECTRICITY_RESIDENTIAL')->firstOrFail();
        }

        // Kiểm tra với lượng dùng 80 kWh:
        // Bậc 1 (0 - 50 kWh): 50 * 1806 = 90,300
        // Bậc 2 (51 - 100 kWh): 30 * 1866 = 55,980
        // Subtotal = 146,280. VAT 10% = 14,628. Total = 160,908
        $response = $this->withHeaders($this->authHeaders())->postJson("/api/v1/pricing-configs/{$electricity->id}/simulate", [
            'usage' => 80,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.usage', 80)
            ->assertJsonPath('data.subtotal', 146280)
            ->assertJsonPath('data.vat_amount', 14628)
            ->assertJsonPath('data.total_amount', 160908);
    }

    /**
     * Test 10: Bật / tắt áp dụng và xóa mềm cấu hình đơn giá
     */
    public function test_10_can_toggle_active_and_soft_delete_pricing_config(): void
    {
        $config = ServicePricingConfig::create([
            'id' => (string) Str::uuid(),
            'service_code' => 'TOGGLE_DEL_TEST_'.Str::upper(Str::random(4)),
            'service_name' => 'Kiểm Thử Toggle Và Xóa Mềm',
            'billing_type' => 'FIXED_MONTHLY',
            'unit_name' => 'tháng',
            'fixed_unit_price' => 50000,
            'vat_percentage' => 10,
            'effective_from_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // Toggle sang tắt
        $toggleRes = $this->withHeaders($this->authHeaders())->patchJson("/api/v1/pricing-configs/{$config->id}/toggle-active");
        $toggleRes->assertStatus(200)->assertJsonPath('data.is_active', false);

        // Xóa mềm
        $delRes = $this->withHeaders($this->authHeaders())->deleteJson("/api/v1/pricing-configs/{$config->id}");
        $delRes->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSoftDeleted('service_pricing_configs', [
            'id' => $config->id,
        ]);
    }

    /**
     * Test 11: Cư dân không có quyền thì không thể tạo hoặc xem danh mục cấu hình đơn giá
     */
    public function test_11_resident_without_permission_cannot_create_or_view_pricing_configs(): void
    {
        $residentUser = User::firstOrCreate(
            ['username' => 'resident_test_pricing'],
            [
                'phone_number' => '0908888888',
                'email' => 'resident_pricing@cassavas.vn',
                'password_hash' => bcrypt('password123'),
                'full_name' => 'Resident Test',
                'status' => 'ACTIVE',
            ]
        );
        $residentRole = Role::firstOrCreate(
            ['role_code' => 'RESIDENT_MEMBER'],
            ['role_name' => 'Cư dân', 'status' => 'ACTIVE']
        );
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $residentUser->id, 'role_id' => $residentRole->id],
            ['id' => (string) Str::uuid(), 'is_primary' => 1, 'assigned_at' => now()]
        );
        $residentToken = $this->issueTestBearerToken($residentUser);
        $residentHeaders = [
            'Authorization' => 'Bearer '.$residentToken,
            'Accept' => 'application/json',
        ];

        // 1. Cư dân gọi POST /api/v1/pricing-configs nhận 403
        $postRes = $this->withHeaders($residentHeaders)->postJson('/api/v1/pricing-configs', [
            'service_code' => 'TEST_RESIDENT_POST',
            'service_name' => 'Test Resident Create',
            'billing_type' => 'FIXED_MONTHLY',
            'unit_name' => 'tháng',
        ]);
        $postRes->assertStatus(403)
            ->assertJson(['success' => false, 'error' => 'FORBIDDEN']);

        // 2. Cư dân gọi GET /api/v1/pricing-configs nhận 403
        $getRes = $this->withHeaders($residentHeaders)->getJson('/api/v1/pricing-configs');
        $getRes->assertStatus(403)
            ->assertJson(['success' => false, 'error' => 'FORBIDDEN']);
    }

    /**
     * Test 12: Route vehicles/pricing-config được bảo vệ xác thực (401 khi không token, 403 khi cư dân không có quyền)
     */
    public function test_12_unauthenticated_request_to_vehicles_pricing_config_returns_401(): void
    {
        // 1. Không kèm token nhận 401
        $guestRes = $this->getJson('/vehicles/pricing-config');
        $guestRes->assertStatus(401);

        // 2. Cư dân không có quyền nhận 403
        $residentUser = User::where('username', 'resident_test_pricing')->first();
        if ($residentUser) {
            $residentToken = $this->issueTestBearerToken($residentUser);
            $resRes = $this->withHeaders([
                'Authorization' => 'Bearer '.$residentToken,
                'Accept' => 'application/json',
            ])->getJson('/vehicles/pricing-config');
            $resRes->assertStatus(403);
        }

        // 3. Admin nhận 200
        $adminRes = $this->withHeaders($this->authHeaders())->getJson('/vehicles/pricing-config');
        $adminRes->assertStatus(200)->assertJson(['success' => true]);
    }

    /**
     * Test 13: Cấu hình nước (meter_type = WATER) được nạp và tính tiền chính xác khi sinh hóa đơn căn hộ
     */
    public function test_13_water_pricing_config_is_used_when_generating_invoice_for_apartment_with_water_reading(): void
    {
        $this->seed(ServicePricingSeeder::class);

        $waterConfig = ServicePricingConfig::where('service_code', 'WATER_RESIDENTIAL')->first();
        if ($waterConfig) {
            $waterConfig->update([
                'meter_type' => 'WATER',
                'effective_from_date' => '2026-11-01',
            ]);
        }

        $block = Block::firstOrCreate(
            ['block_code' => 'TEST_BLOCK_W'],
            ['block_name' => 'Tòa Thử Nghiệm Nước', 'status' => 'ACTIVE', 'total_floors' => 10]
        );
        $floor = Floor::firstOrCreate(
            ['block_id' => $block->id, 'floor_number' => 1],
            ['floor_code' => 'TEST_W_FL01', 'floor_name' => 'Tầng 1', 'status' => 'ACTIVE']
        );
        $apartment = Apartment::firstOrCreate(
            ['floor_id' => $floor->id, 'apartment_number' => 'W-101'],
            [
                'block_id' => $block->id,
                'status' => 'ACTIVE',
                'gross_floor_area_sqm' => 75.0,
                'net_usable_area_sqm' => 70.0,
            ]
        );

        $waterMeter = Meter::firstOrCreate(
            ['meter_code' => 'WM-TEST-W101'],
            [
                'apartment_id' => $apartment->id,
                'meter_type' => 'WATER',
                'installation_date' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $cycle = '2026-11';
        MeterReading::where('meter_id', $waterMeter->id)->where('billing_cycle', $cycle)->delete();
        MeterReading::create([
            'meter_id' => $waterMeter->id,
            'apartment_id' => $apartment->id,
            'billing_cycle' => $cycle,
            'period_start_date' => '2026-10-01',
            'period_end_date' => '2026-10-31',
            'previous_reading' => 10,
            'current_reading' => 25,
            'consumed_units' => 15,
            'reading_source' => 'MANUAL_ENTRY',
            'is_locked_for_billing' => false,
        ]);

        $preview = app(InvoiceGenerationService::class)->previewBatch([
            'billing_period' => $cycle,
            'block_id' => $block->id,
        ]);

        $this->assertNotEmpty($preview['preview_items']);
        $aptPreview = collect($preview['preview_items'])->firstWhere('apartment_id', $apartment->id);
        $this->assertNotNull($aptPreview, 'Phải có dự toán cho căn hộ');
        $this->assertTrue($aptPreview['has_water_reading']);

        // Tiêu thụ 15 m3 theo bậc: 10 * 7500 + 5 * 9900 = 75,000 + 49,500 = 124,500 subtotal
        // VAT 5% (6,225) + BVMT 10% (12,450) = 143,175
        $waterLine = collect($aptPreview['items_preview'])->firstWhere('service_code', 'WATER');
        $this->assertNotNull($waterLine, 'Hóa đơn phải có mục tiền nước sinh hoạt');
        $this->assertEquals(15, (float) $waterLine['quantity']);
        $this->assertEquals(124500, (float) $waterLine['amount_before_tax']);
        $this->assertEquals(143175, (float) $waterLine['total_line_amount']);
    }

    /**
     * Test 14: Không cho phép cập nhật effective_to_date trước effective_from_date & config hết hạn không được tính tiền
     */
    public function test_14_cannot_update_pricing_config_with_effective_to_date_before_effective_from_date(): void
    {
        $config = ServicePricingConfig::create([
            'id' => (string) Str::uuid(),
            'service_code' => 'DATE_TEST_'.Str::upper(Str::random(4)),
            'service_name' => 'Kiểm Thử Ngày Hiệu Lực',
            'billing_type' => 'FIXED_MONTHLY',
            'unit_name' => 'tháng',
            'fixed_unit_price' => 50000,
            'vat_percentage' => 10,
            'effective_from_date' => '2026-06-01',
            'is_active' => true,
        ]);

        // Cập nhật ngày kết thúc trước ngày bắt đầu phải nhận 422
        $response = $this->withHeaders($this->authHeaders())->putJson("/api/v1/pricing-configs/{$config->id}", [
            'effective_to_date' => '2026-05-01',
        ]);
        $response->assertStatus(422);

        // Tạo config nước đã hết hạn trong quá khứ
        $expiredConfig = ServicePricingConfig::create([
            'id' => (string) Str::uuid(),
            'service_code' => 'EXPIRED_WATER_'.Str::upper(Str::random(4)),
            'service_name' => 'Biểu Giá Nước Hết Hạn',
            'meter_type' => 'WATER',
            'billing_type' => 'FIXED_MONTHLY',
            'unit_name' => 'm3',
            'fixed_unit_price' => 1000,
            'effective_from_date' => '2020-01-01',
            'effective_to_date' => '2020-12-31',
            'is_active' => true,
        ]);

        // Config hết hạn không được nạp cho kỳ hiện tại
        $reflection = new \ReflectionClass(InvoiceGenerationService::class);
        $method = $reflection->getMethod('loadPricingConfigs');
        $method->setAccessible(true);
        $loadedConfigs = $method->invoke(app(InvoiceGenerationService::class), '2026-11');

        $this->assertNotEquals($expiredConfig->id, $loadedConfigs['WATER']?->id);
    }

    /**
     * Test 15: Phân trang danh mục cấu hình đơn giá đúng quy cách (mặc định 15/trang, có meta/links)
     */
    public function test_15_can_paginate_pricing_configs_list(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            ServicePricingConfig::firstOrCreate(
                ['service_code' => 'PAGE_TEST_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)],
                [
                    'service_name' => "Cấu Hình Phân Trang {$i}",
                    'billing_type' => 'FIXED_MONTHLY',
                    'unit_name' => 'tháng',
                    'fixed_unit_price' => 10000 * $i,
                    'effective_from_date' => '2026-01-01',
                    'is_active' => true,
                ]
            );
        }

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/v1/pricing-configs?per_page=15');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
                'total',
            ]);

        $this->assertCount(15, $response->json('data'));
        $this->assertEquals(15, $response->json('meta.per_page'));
        $this->assertGreaterThanOrEqual(2, $response->json('meta.last_page'));
        $this->assertGreaterThanOrEqual(20, $response->json('meta.total'));
    }

    /**
     * Test 16: Tạo trùng service_code nhận mã lỗi 422 thay vì văng 500
     */
    public function test_16_duplicate_service_code_returns_422_instead_of_500(): void
    {
        $code = 'UNIQUE_CODE_TEST_'.Str::upper(Str::random(4));
        $payload = [
            'service_code' => $code,
            'service_name' => 'Dịch Vụ Test Duplicate',
            'billing_type' => 'FIXED_MONTHLY',
            'unit_name' => 'tháng',
            'fixed_unit_price' => 20000,
            'is_active' => true,
        ];

        // Lần 1: Thành công 201
        $res1 = $this->withHeaders($this->authHeaders())->postJson('/api/v1/pricing-configs', $payload);
        $res1->assertStatus(201);

        // Lần 2: Trùng mã phải nhận 422 (VALIDATION_ERROR), không phải 500
        $res2 = $this->withHeaders($this->authHeaders())->postJson('/api/v1/pricing-configs', $payload);
        $res2->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'VALIDATION_ERROR',
            ]);
    }
}
