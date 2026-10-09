<?php

namespace Tests\Feature\ServicePricing;

use App\Models\PricingTier;
use App\Models\Role;
use App\Models\ServicePricingConfig;
use App\Models\User;
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
        $this->adminToken = 'smart_token_'.$adminUser->id.'_pricing';

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
}
