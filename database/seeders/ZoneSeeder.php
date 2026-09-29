<?php

namespace Database\Seeders;

use App\Models\Block;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder dữ liệu mẫu cho Khối Tòa nhà (Block/Zone).
 * Có kiểm soát môi trường (chặn trên production), đảm bảo tính idempotent và an toàn dữ liệu.
 */
class ZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Chặn tuyệt đối thực thi trên môi trường PRODUCTION
        if (app()->environment('production')) {
            $this->command?->warn('⚠️ Bỏ qua ZoneSeeder: Không được phép nạp dữ liệu demo trên môi trường PRODUCTION!');

            return;
        }

        // 2. Danh sách dữ liệu mẫu chuẩn hóa, đồng nhất với kiến trúc blocks của hệ thống
        $zones = [
            [
                'block_code' => 'BLOCK_A',
                'block_name' => 'Tòa Tháp Ruby Tower (Block A)',
                'total_floors' => 12,
                'total_basements' => 2,
                'total_apartments' => 120,
                'status' => 'ACTIVE',
                'address_line' => 'Khối A, Khu phức hợp Smart Cassavas, Mặt đường Đại Lộ Thăng Long',
                'hotline_phone' => '024 3999 1111',
                'description' => 'Khối căn hộ cao cấp Ruby Tower với sảnh đón sang trọng, phòng sinh hoạt cộng đồng và hồ bơi tầng thượng.',
            ],
            [
                'block_code' => 'BLOCK_B',
                'block_name' => 'Tòa Tháp Sapphire Tower (Block B)',
                'total_floors' => 10,
                'total_basements' => 2,
                'total_apartments' => 86,
                'status' => 'ACTIVE',
                'address_line' => 'Khối B, Khu phức hợp Smart Cassavas, View Hồ Điều Hòa Trung Tâm',
                'hotline_phone' => '024 3999 2222',
                'description' => 'Khối Sapphire Tower đối diện công viên cây xanh, trang bị hệ thống Smart Home và thang máy tốc độ cao.',
            ],
            [
                'block_code' => 'BLOCK_C',
                'block_name' => 'Tòa Tháp Emerald Tower (Block C)',
                'total_floors' => 8,
                'total_basements' => 1,
                'total_apartments' => 64,
                'status' => 'ACTIVE',
                'address_line' => 'Khối C, Khu phức hợp Smart Cassavas, Cạnh Trung Tâm Thương Mại',
                'hotline_phone' => '024 3999 3333',
                'description' => 'Khối Emerald Tower tích hợp trung tâm thương mại 3 tầng khối đế và sân tập thể thao đa năng ngoài trời.',
            ],
            [
                'block_code' => 'BLOCK_D',
                'block_name' => 'Tòa Tháp Diamond Tower (Block D)',
                'total_floors' => 15,
                'total_basements' => 2,
                'total_apartments' => 100,
                'status' => 'MAINTENANCE',
                'address_line' => 'Khối D, Khu phức hợp Smart Cassavas, Khu Vực Phía Tây',
                'hotline_phone' => '024 3999 4444',
                'description' => 'Tòa tháp Diamond Tower đang trong giai đoạn hoàn thiện nghiệm thu kỹ thuật và bảo trì định kỳ hệ thống.',
            ],
        ];

        foreach ($zones as $zoneData) {
            $existing = Block::withTrashed()->where('block_code', $zoneData['block_code'])->first();

            if (! $existing) {
                Block::create(array_merge($zoneData, [
                    'id' => (string) Str::uuid(),
                    'ai_features_enabled' => true,
                    'metadata' => ['seeded_by' => 'ZoneSeeder'],
                    'version' => 1,
                ]));
            } else {
                // Không ghi đè nếu bản ghi đã tồn tại hoặc do người dùng chỉnh sửa
                // Chỉ bổ sung description / status nếu đang để trống
                $updates = [];
                if (empty($existing->description) && ! empty($zoneData['description'])) {
                    $updates['description'] = $zoneData['description'];
                }
                if (empty($existing->status)) {
                    $updates['status'] = $zoneData['status'];
                }
                if (! empty($updates)) {
                    $existing->update($updates);
                }
            }
        }
    }
}
