<?php

namespace Database\Seeders;

use App\Models\PricingTier;
use App\Models\ServicePricingConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServicePricingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        // 1. BIỂU GIÁ ĐIỆN SINH HOẠT LŨY TIẾN 6 BẬC (EVN)
        $electricity = ServicePricingConfig::firstOrCreate(
            ['service_code' => 'ELECTRICITY_RESIDENTIAL'],
            [
                'id' => 'c0000001-0000-0000-0000-000000000001',
                'service_name' => 'Điện Sinh Hoạt Bậc Thang',
                'meter_type' => 'ELECTRICITY',
                'billing_type' => 'TIERED_USAGE',
                'unit_name' => 'kWh',
                'fixed_unit_price' => 0,
                'vat_percentage' => 10.00,
                'environmental_protection_fee_pct' => 0.00,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        $electricityTiers = [
            ['order' => 1, 'name' => 'Bậc 1: Cho kWh từ 0 - 50', 'min' => 0, 'max' => 50, 'price' => 1806.00],
            ['order' => 2, 'name' => 'Bậc 2: Cho kWh từ 51 - 100', 'min' => 50, 'max' => 100, 'price' => 1866.00],
            ['order' => 3, 'name' => 'Bậc 3: Cho kWh từ 101 - 200', 'min' => 100, 'max' => 200, 'price' => 2167.00],
            ['order' => 4, 'name' => 'Bậc 4: Cho kWh từ 201 - 300', 'min' => 200, 'max' => 300, 'price' => 2729.00],
            ['order' => 5, 'name' => 'Bậc 5: Cho kWh từ 301 - 400', 'min' => 300, 'max' => 400, 'price' => 3050.00],
            ['order' => 6, 'name' => 'Bậc 6: Cho kWh từ 401 trở lên', 'min' => 400, 'max' => null, 'price' => 3151.00],
        ];

        foreach ($electricityTiers as $t) {
            PricingTier::firstOrCreate(
                [
                    'pricing_config_id' => $electricity->id,
                    'tier_order' => $t['order'],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'tier_name' => $t['name'],
                    'min_usage_threshold' => $t['min'],
                    'max_usage_threshold' => $t['max'],
                    'unit_price' => $t['price'],
                    'created_at' => $now,
                ]
            );
        }

        // 2. BIỂU GIÁ NƯỚC SINH HOẠT ĐỊNH MỨC 4 BẬC (SAWACO)
        $water = ServicePricingConfig::firstOrCreate(
            ['service_code' => 'WATER_RESIDENTIAL'],
            [
                'id' => 'c0000002-0000-0000-0000-000000000002',
                'service_name' => 'Nước Sinh Hoạt Định Mức',
                'meter_type' => 'COLD_WATER',
                'billing_type' => 'TIERED_USAGE',
                'unit_name' => 'm3',
                'fixed_unit_price' => 0,
                'vat_percentage' => 5.00,
                'environmental_protection_fee_pct' => 10.00, // Phí BVMT nước thải 10%
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        $waterTiers = [
            ['order' => 1, 'name' => 'Bậc 1: Định mức dưới 10 m3', 'min' => 0, 'max' => 10, 'price' => 7500.00],
            ['order' => 2, 'name' => 'Bậc 2: Từ trên 10 đến 20 m3', 'min' => 10, 'max' => 20, 'price' => 9900.00],
            ['order' => 3, 'name' => 'Bậc 3: Từ trên 20 đến 30 m3', 'min' => 20, 'max' => 30, 'price' => 12500.00],
            ['order' => 4, 'name' => 'Bậc 4: Trên 30 m3', 'min' => 30, 'max' => null, 'price' => 15200.00],
        ];

        foreach ($waterTiers as $t) {
            PricingTier::firstOrCreate(
                [
                    'pricing_config_id' => $water->id,
                    'tier_order' => $t['order'],
                ],
                [
                    'id' => (string) Str::uuid(),
                    'tier_name' => $t['name'],
                    'min_usage_threshold' => $t['min'],
                    'max_usage_threshold' => $t['max'],
                    'unit_price' => $t['price'],
                    'created_at' => $now,
                ]
            );
        }

        // 3. PHÍ DỊCH VỤ QUẢN LÝ CHUNG CƯ (TÍNH THEO M2/THÁNG)
        ServicePricingConfig::firstOrCreate(
            ['service_code' => 'MANAGEMENT_FEE'],
            [
                'id' => 'c0000003-0000-0000-0000-000000000003',
                'service_name' => 'Phí Dịch Vụ Quản Lý Chung Cư',
                'meter_type' => null,
                'billing_type' => 'UNIT_PRICE_USAGE',
                'unit_name' => 'm2/tháng',
                'fixed_unit_price' => 14000.00,
                'vat_percentage' => 10.00,
                'environmental_protection_fee_pct' => 0.00,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        // 4. PHÍ GỬI XE MÁY HÀNG THÁNG
        ServicePricingConfig::firstOrCreate(
            ['service_code' => 'PARKING_MOTORBIKE'],
            [
                'id' => 'c0000004-0000-0000-0000-000000000004',
                'service_name' => 'Phí Trông Giữ Xe Máy Tháng',
                'meter_type' => null,
                'billing_type' => 'FIXED_MONTHLY',
                'unit_name' => 'xe/tháng',
                'fixed_unit_price' => 120000.00,
                'vat_percentage' => 10.00,
                'environmental_protection_fee_pct' => 0.00,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        // 5. PHÍ GỬI Ô TÔ HÀNG THÁNG
        ServicePricingConfig::firstOrCreate(
            ['service_code' => 'PARKING_CAR'],
            [
                'id' => 'c0000005-0000-0000-0000-000000000005',
                'service_name' => 'Phí Trông Giữ Ô Tô Tháng',
                'meter_type' => null,
                'billing_type' => 'FIXED_MONTHLY',
                'unit_name' => 'xe/tháng',
                'fixed_unit_price' => 1500000.00,
                'vat_percentage' => 10.00,
                'environmental_protection_fee_pct' => 0.00,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        // 6. PHÍ TRÔNG GIỮ XE ĐẠP / XE ĐIỆN NHỎ
        ServicePricingConfig::firstOrCreate(
            ['service_code' => 'PARKING_BICYCLE'],
            [
                'id' => 'c0000006-0000-0000-0000-000000000006',
                'service_name' => 'Phí Trông Giữ Xe Đạp & Xe Điện Nhỏ',
                'meter_type' => null,
                'billing_type' => 'FIXED_MONTHLY',
                'unit_name' => 'xe/tháng',
                'fixed_unit_price' => 50000.00,
                'vat_percentage' => 10.00,
                'environmental_protection_fee_pct' => 0.00,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );

        // 7. PHÍ SẠC TRẠM XE ĐIỆN DƯỚI HẦM
        ServicePricingConfig::firstOrCreate(
            ['service_code' => 'EV_CHARGING'],
            [
                'id' => 'c0000007-0000-0000-0000-000000000007',
                'service_name' => 'Phí Sạc Trụ Trạm Điện Cư Dân',
                'meter_type' => 'ELECTRICITY',
                'billing_type' => 'UNIT_PRICE_USAGE',
                'unit_name' => 'kWh',
                'fixed_unit_price' => 3200.00,
                'vat_percentage' => 10.00,
                'environmental_protection_fee_pct' => 0.00,
                'effective_from_date' => '2026-01-01',
                'is_active' => true,
            ]
        );
    }
}
