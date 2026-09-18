<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Seeder dữ liệu mẫu ban đầu cho Khối Tòa nhà (Block/Zone).
 */
class ZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $zones = [
            [
                'zone_code' => 'BLOCK_A',
                'zone_name' => 'Tòa Nhà A - Ruby Tower',
                'floor_count' => 25,
                'basement_count' => 2,
                'total_apartments' => 200,
                'status' => 'ACTIVE',
                'address_line' => 'Khu Đô Thị Smart City, Mặt đường Đại Lộ Thăng Long',
                'hotline_phone' => '1900-1122-01',
                'description' => 'Khối căn hộ cao cấp Ruby Tower với sảnh đón sang trọng, phòng sinh hoạt cộng đồng và hồ bơi tầng thượng.',
            ],
            [
                'zone_code' => 'BLOCK_B',
                'zone_name' => 'Tòa Nhà B - Sapphire Tower',
                'floor_count' => 30,
                'basement_count' => 2,
                'total_apartments' => 250,
                'status' => 'ACTIVE',
                'address_line' => 'Khu Đô Thị Smart City, View Hồ Điều Hòa Trung Tâm',
                'hotline_phone' => '1900-1122-02',
                'description' => 'Khối Sapphire Tower đối diện công viên cây xanh, trang bị hệ thống Smart Home và thang máy tốc độ cao.',
            ],
            [
                'zone_code' => 'BLOCK_C',
                'zone_name' => 'Tòa Nhà C - Emerald Tower',
                'floor_count' => 28,
                'basement_count' => 2,
                'total_apartments' => 220,
                'status' => 'ACTIVE',
                'address_line' => 'Khu Đô Thị Smart City, Cạnh Trung Tâm Thương Mại',
                'hotline_phone' => '1900-1122-03',
                'description' => 'Khối Emerald Tower tích hợp trung tâm thương mại 3 tầng khối đế và sân tập thể thao đa năng ngoài trời.',
            ],
            [
                'zone_code' => 'BLOCK_D',
                'zone_name' => 'Tòa Nhà D - Diamond Tower',
                'floor_count' => 35,
                'basement_count' => 3,
                'total_apartments' => 320,
                'status' => 'MAINTENANCE',
                'address_line' => 'Khu Đô Thị Smart City, Khu Vực Phía Tây',
                'hotline_phone' => '1900-1122-04',
                'description' => 'Tòa tháp biểu tượng Diamond Tower đang trong giai đoạn hoàn thiện nghiệm thu kỹ thuật và bảo trì định kỳ hệ thống.',
            ],
        ];

        foreach ($zones as $zoneData) {
            Zone::updateOrCreate(
                ['zone_code' => $zoneData['zone_code']],
                $zoneData
            );
        }
    }
}
