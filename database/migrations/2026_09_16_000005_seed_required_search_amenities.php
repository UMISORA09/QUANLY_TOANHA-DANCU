<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $cat = DB::table('amenity_categories')->first();
        $catId = $cat ? $cat->id : (string) Str::uuid();
        if (! $cat) {
            DB::table('amenity_categories')->insert([
                'id' => $catId,
                'category_name' => 'Thể Thao & Nghỉ Dưỡng',
                'category_code' => 'SPORTS_RESORT',
                'created_at' => now(),
            ]);
        }

        $blockA = DB::table('blocks')->where('block_code', 'BLOCK_A')->first();
        $blockB = DB::table('blocks')->where('block_code', 'BLOCK_B')->first();

        $sampleAmenities = [
            [
                'amenity_name' => 'Khu Nghỉ Dưỡng',
                'amenity_code' => 'KND-01',
                'location_detail' => 'Khuôn viên sinh thái ven hồ tầng 1',
                'description' => 'Khu nghỉ dưỡng cao cấp dành cho cư dân thư giãn cuối tuần',
                'rules_and_regulations' => 'Vui lòng giữ gìn vệ sinh chung, không mang vật nuôi vào khuôn viên',
                'block_id' => $blockB ? $blockB->id : null,
            ],
            [
                'amenity_name' => 'Phòng Gym Tòa A',
                'amenity_code' => 'GYM-TOA-A',
                'location_detail' => 'Tầng 3 Tòa A Ruby',
                'description' => 'Phòng tập thể hình hiện đại trang bị máy tập Technogym',
                'rules_and_regulations' => 'Mang giày thể thao và khăn tập cá nhân',
                'block_id' => $blockA ? $blockA->id : null,
            ],
            [
                'amenity_name' => 'Phòng Gym',
                'amenity_code' => 'GYM-MAIN',
                'location_detail' => 'Khu trung tâm thể thao',
                'description' => 'Phòng tập gym đa năng cho toàn bộ cư dân',
                'rules_and_regulations' => 'Tuân thủ hướng dẫn của huấn luyện viên',
                'block_id' => $blockA ? $blockA->id : null,
            ],
            [
                'amenity_name' => 'Sân Tennis',
                'amenity_code' => 'TEN-STD',
                'location_detail' => 'Cụm thể thao ngoài trời',
                'description' => 'Sân tennis tiêu chuẩn quốc tế có đèn chiếu sáng ban đêm',
                'rules_and_regulations' => 'Mặc trang phục thể thao và sử dụng giày đế mềm',
                'block_id' => null,
            ],
            [
                'amenity_name' => 'Khu BBQ',
                'amenity_code' => 'BBQ-GARDEN',
                'location_detail' => 'Khu vườn nướng ngoài trời ven hồ',
                'description' => 'Khu vực tiệc nướng BBQ gia đình ngoài trời',
                'rules_and_regulations' => 'Dọn dẹp sau khi sử dụng và bảo đảm an toàn phòng cháy chữa cháy',
                'block_id' => null,
            ],
            [
                'amenity_name' => 'Hồ Bơi',
                'amenity_code' => 'POOL-MAIN',
                'location_detail' => 'Tầng 5 tòa nhà trung tâm',
                'description' => 'Hồ bơi vô cực nước ấm lọc cát tuần hoàn',
                'rules_and_regulations' => 'Tắm tráng trước khi xuống hồ bơi, trẻ em phải có người lớn đi kèm',
                'block_id' => $blockA ? $blockA->id : null,
            ],
        ];

        foreach ($sampleAmenities as $item) {
            $existing = DB::table('amenities')
                ->where('amenity_name', $item['amenity_name'])
                ->whereNull('deleted_at')
                ->first();

            if (! $existing) {
                DB::table('amenities')->insert([
                    'id' => (string) Str::uuid(),
                    'category_id' => $catId,
                    'block_id' => $item['block_id'],
                    'amenity_name' => $item['amenity_name'],
                    'amenity_code' => $item['amenity_code'],
                    'location_detail' => $item['location_detail'],
                    'rules_and_regulations' => $item['rules_and_regulations'],
                    'max_capacity_per_slot' => 20,
                    'hourly_rate' => 0,
                    'security_deposit_required' => 0,
                    'advance_booking_days_limit' => 7,
                    'min_cancel_hours_before' => 12,
                    'requires_admin_approval' => 0,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('amenities')->whereIn('amenity_code', [
            'KND-01', 'GYM-TOA-A', 'GYM-MAIN', 'TEN-STD', 'BBQ-GARDEN', 'POOL-MAIN',
        ])->delete();
    }
};
