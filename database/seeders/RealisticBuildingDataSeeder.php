<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RealisticBuildingDataSeeder extends Seeder
{
    /**
     * Nạp dữ liệu thực tế cao cấp cho hệ thống Quản lý Tòa nhà & Cư dân:
     * - Tài khoản Social Login thực tế (Google, Facebook)
     * - Tòa nhà, Tầng, Căn hộ thực tế
     * - Cư dân (Chủ hộ & Khách thuê)
     * - Thông báo & Tiện ích thực tế
     */
    public function run(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }

        $now = Carbon::now();
        $defaultPassword = Hash::make('Cassavas@2026');

        // =========================================================================
        // 1. TÀI KHOẢN MẠNG XÃ HỘI THỰC TẾ (GOOGLE & FACEBOOK USERS)
        // =========================================================================
        $socialUsers = [
            [
                'username' => 'google_nam_nguyen',
                'email' => 'google.resident@gmail.com',
                'phone_number' => '0939111222',
                'full_name' => 'Nguyễn Hoàng Nam',
                'avatar_url' => 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=256&q=80',
                'gender' => 'MALE',
                'date_of_birth' => '1988-06-15',
                'national_id_number' => '079088999888',
                'google_id' => '104829104859182740192',
                'facebook_id' => null,
                'oauth_provider' => 'google',
                'role_code' => 'RESIDENT_OWNER',
                'resident_type' => 'OWNER',
            ],
            [
                'username' => 'facebook_thao_le',
                'email' => 'facebook.resident@gmail.com',
                'phone_number' => '0939333444',
                'full_name' => 'Lê Thị Thu Thảo',
                'avatar_url' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=256&q=80',
                'gender' => 'FEMALE',
                'date_of_birth' => '1995-10-22',
                'national_id_number' => '079195777666',
                'google_id' => null,
                'facebook_id' => '109283746501928374019',
                'oauth_provider' => 'facebook',
                'role_code' => 'RESIDENT_MEMBER',
                'resident_type' => 'TENANT',
            ],
        ];

        foreach ($socialUsers as $sUser) {
            $user = DB::table('users')
                ->where('email', $sUser['email'])
                ->orWhere('phone_number', $sUser['phone_number'])
                ->first();
            $userId = $user ? $user->id : (string) Str::uuid();

            DB::table('users')->updateOrInsert(
                ['id' => $userId],
                [
                    'username' => $sUser['username'],
                    'email' => $sUser['email'],
                    'phone_number' => $sUser['phone_number'],
                    'password_hash' => $defaultPassword,
                    'full_name' => $sUser['full_name'],
                    'avatar_url' => $sUser['avatar_url'],
                    'gender' => $sUser['gender'],
                    'date_of_birth' => $sUser['date_of_birth'],
                    'national_id_number' => $sUser['national_id_number'],
                    'status' => 'ACTIVE',
                    'google_id' => $sUser['google_id'],
                    'facebook_id' => $sUser['facebook_id'],
                    'oauth_provider' => $sUser['oauth_provider'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // Gán role
            $role = DB::table('roles')->where('role_code', $sUser['role_code'])->first();
            if ($role) {
                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $userId, 'role_id' => $role->id],
                    ['id' => (string) Str::uuid(), 'is_primary' => 1, 'assigned_at' => $now]
                );
            }
        }

        // =========================================================================
        // 2. KHỐI TÒA NHÀ THỰC TẾ (BLOCKS)
        // =========================================================================
        $blocksData = [
            [
                'block_code' => 'BLOCK_A',
                'block_name' => 'Tòa Sapphire (Khu A) - Cao Ốc Đẳng Cấp',
                'total_floors' => 32,
                'total_basements' => 3,
                'total_apartments' => 450,
                'address_line' => 'Số 88 Đường Nguyễn Hữu Thọ, P. Tân Hưng, Quận 7, TP. Hồ Chí Minh',
                'hotline_phone' => '028 3822 9999',
            ],
            [
                'block_code' => 'BLOCK_B',
                'block_name' => 'Tòa Ruby (Khu B) - Tháp Căn Hộ Nghỉ Dưỡng',
                'total_floors' => 30,
                'total_basements' => 3,
                'total_apartments' => 400,
                'address_line' => 'Số 88 Đường Nguyễn Hữu Thọ, P. Tân Hưng, Quận 7, TP. Hồ Chí Minh',
                'hotline_phone' => '028 3822 9998',
            ],
            [
                'block_code' => 'BLOCK_C',
                'block_name' => 'Tòa Diamond (Khu C) - Tháp Thương Mại & Căn Hộ',
                'total_floors' => 28,
                'total_basements' => 2,
                'total_apartments' => 350,
                'address_line' => 'Số 88 Đường Nguyễn Hữu Thọ, P. Tân Hưng, Quận 7, TP. Hồ Chí Minh',
                'hotline_phone' => '028 3822 9997',
            ],
        ];

        $blockIdMap = [];
        foreach ($blocksData as $bData) {
            $existing = DB::table('blocks')->where('block_code', $bData['block_code'])->first();
            $blockId = $existing ? $existing->id : (string) Str::uuid();
            $blockIdMap[$bData['block_code']] = $blockId;

            DB::table('blocks')->updateOrInsert(
                ['block_code' => $bData['block_code']],
                [
                    'id' => $blockId,
                    'block_name' => $bData['block_name'],
                    'total_floors' => $bData['total_floors'],
                    'total_basements' => $bData['total_basements'],
                    'total_apartments' => $bData['total_apartments'],
                    'address_line' => $bData['address_line'],
                    'hotline_phone' => $bData['hotline_phone'],
                    'ai_features_enabled' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // =========================================================================
        // 3. TẦNG (FLOORS) & CĂN HỘ THỰC TẾ (APARTMENTS)
        // =========================================================================
        $realApartments = [
            // Block A
            ['block' => 'BLOCK_A', 'number' => 'A-0501', 'floor' => 5, 'room_type' => '1_BEDROOM', 'area' => 54.5, 'beds' => 1, 'baths' => 1, 'status' => 'OCCUPIED', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_A', 'number' => 'A-0802', 'floor' => 8, 'room_type' => '2_BEDROOM', 'area' => 76.2, 'beds' => 2, 'baths' => 2, 'status' => 'OCCUPIED', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_A', 'number' => 'A-1204', 'floor' => 12, 'room_type' => '2_BEDROOM', 'area' => 85.0, 'beds' => 2, 'baths' => 2, 'status' => 'OCCUPIED', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_A', 'number' => 'A-1805', 'floor' => 18, 'room_type' => '3_BEDROOM', 'area' => 110.8, 'beds' => 3, 'baths' => 2, 'status' => 'VACANT', 'furnished' => 'BASIC_FURNISHED'],
            ['block' => 'BLOCK_A', 'number' => 'A-2801', 'floor' => 28, 'room_type' => 'PENTHOUSE', 'area' => 220.0, 'beds' => 4, 'baths' => 3, 'status' => 'OCCUPIED', 'furnished' => 'FULLY_FURNISHED'],

            // Block B
            ['block' => 'BLOCK_B', 'number' => 'B-0401', 'floor' => 4, 'room_type' => 'STUDIO', 'area' => 42.0, 'beds' => 1, 'baths' => 1, 'status' => 'VACANT', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_B', 'number' => 'B-0806', 'floor' => 8, 'room_type' => '1_BEDROOM', 'area' => 58.0, 'beds' => 1, 'baths' => 1, 'status' => 'OCCUPIED', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_B', 'number' => 'B-1402', 'floor' => 14, 'room_type' => '2_BEDROOM', 'area' => 78.5, 'beds' => 2, 'baths' => 2, 'status' => 'VACANT', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_B', 'number' => 'B-2005', 'floor' => 20, 'room_type' => '3_BEDROOM', 'area' => 115.0, 'beds' => 3, 'baths' => 3, 'status' => 'VACANT', 'furnished' => 'UNFURNISHED'],

            // Block C
            ['block' => 'BLOCK_C', 'number' => 'C-0601', 'floor' => 6, 'room_type' => '1_BEDROOM', 'area' => 52.0, 'beds' => 1, 'baths' => 1, 'status' => 'VACANT', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_C', 'number' => 'C-1003', 'floor' => 10, 'room_type' => '2_BEDROOM', 'area' => 82.0, 'beds' => 2, 'baths' => 2, 'status' => 'VACANT', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_C', 'number' => 'C-1502', 'floor' => 15, 'room_type' => '2_BEDROOM', 'area' => 88.0, 'beds' => 2, 'baths' => 2, 'status' => 'VACANT', 'furnished' => 'FULLY_FURNISHED'],
            ['block' => 'BLOCK_C', 'number' => 'C-2501', 'floor' => 25, 'room_type' => 'PENTHOUSE', 'area' => 240.0, 'beds' => 4, 'baths' => 4, 'status' => 'VACANT', 'furnished' => 'FULLY_FURNISHED'],
        ];

        $apartmentIdMap = [];
        foreach ($realApartments as $apt) {
            $blockId = $blockIdMap[$apt['block']] ?? null;
            if (! $blockId) {
                continue;
            }

            // Đảm bảo tầng tồn tại
            $floorName = 'Tầng '.str_pad((string) $apt['floor'], 2, '0', STR_PAD_LEFT);
            $floorCode = substr($apt['block'], -1).'-F'.str_pad((string) $apt['floor'], 2, '0', STR_PAD_LEFT);
            $floor = DB::table('floors')->where('block_id', $blockId)->where('floor_number', $apt['floor'])->first();
            $floorId = $floor ? $floor->id : (string) Str::uuid();

            DB::table('floors')->updateOrInsert(
                ['block_id' => $blockId, 'floor_number' => $apt['floor']],
                [
                    'id' => $floorId,
                    'floor_code' => $floorCode,
                    'floor_name' => $floorName,
                    'total_units' => 12,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // Tạo hoặc cập nhật căn hộ
            $existingApt = DB::table('apartments')
                ->where('block_id', $blockId)
                ->where('apartment_number', $apt['number'])
                ->first();
            $aptId = $existingApt ? $existingApt->id : (string) Str::uuid();
            $apartmentIdMap[$apt['number']] = $aptId;

            DB::table('apartments')->updateOrInsert(
                ['block_id' => $blockId, 'apartment_number' => $apt['number']],
                [
                    'id' => $aptId,
                    'floor_id' => $floorId,
                    'room_type' => $apt['room_type'],
                    'net_usable_area_sqm' => $apt['area'] * 0.92,
                    'gross_floor_area_sqm' => $apt['area'],
                    'bedroom_count' => $apt['beds'],
                    'bathroom_count' => $apt['baths'],
                    'has_balcony' => 1,
                    'furnished_status' => $apt['furnished'],
                    'status' => $apt['status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // =========================================================================
        // 4. LIÊN KẾT CĂN HỘ VỚI USER GOOGLE & FACEBOOK
        // =========================================================================
        $googleUser = DB::table('users')->where('email', 'google.resident@gmail.com')->first();
        if ($googleUser && isset($apartmentIdMap['A-1204'])) {
            $a1204Id = $apartmentIdMap['A-1204'];
            DB::table('apartment_owners')->updateOrInsert(
                ['apartment_id' => $a1204Id, 'owner_user_id' => $googleUser->id],
                [
                    'id' => (string) Str::uuid(),
                    'ownership_percentage' => 100.0,
                    'ownership_certificate_number' => 'GCN-TPHCM-2026/A1204',
                    'ownership_start_date' => '2025-01-15',
                    'is_current_owner' => 1,
                    'created_at' => $now,
                ]
            );

            DB::table('residents')->updateOrInsert(
                ['user_id' => $googleUser->id, 'apartment_id' => $a1204Id],
                [
                    'id' => (string) Str::uuid(),
                    'resident_type' => 'OWNER',
                    'is_head_of_household' => 1,
                    'stay_start_date' => '2025-02-01',
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $fbUser = DB::table('users')->where('email', 'facebook.resident@gmail.com')->first();
        if ($fbUser && isset($apartmentIdMap['B-0806'])) {
            $b0806Id = $apartmentIdMap['B-0806'];
            DB::table('residents')->updateOrInsert(
                ['user_id' => $fbUser->id, 'apartment_id' => $b0806Id],
                [
                    'id' => (string) Str::uuid(),
                    'resident_type' => 'TENANT',
                    'is_head_of_household' => 1,
                    'stay_start_date' => '2026-03-01',
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // =========================================================================
        // 5. THÔNG BÁO TÒA NHÀ THỰC TẾ (ANNOUNCEMENTS)
        // =========================================================================
        $authorUser = DB::table('users')->first();
        $authorId = $authorUser ? $authorUser->id : (string) Str::uuid();

        $announcements = [
            [
                'title' => 'Thông báo bảo dưỡng định kỳ hệ thống thang máy Otis Block A & B',
                'summary' => 'Bảo trì thang máy định kỳ quý 4/2026 từ 09:00 - 11:30 sáng, duy trì tối thiểu 2 thang hoạt động.',
                'content' => '<p>Ban Quản Lý xin thông báo kế hoạch bảo trì thang máy định kỳ quý 4/2026 nhằm đảm bảo an toàn kỹ thuật tuyệt đối. Trong thời gian bảo trì từ 09:00 - 11:30 sáng, mỗi cụm thang sẽ duy trì tối thiểu 2 thang hoạt động bình thường. Kính mong Quý Cư dân thông cảm cho sự bất tiện tạm thời.</p>',
                'category' => 'MAINTENANCE',
            ],
            [
                'title' => 'Lịch phun thuốc diệt muỗi và côn trùng toàn bộ khuôn viên tháng 10/2026',
                'summary' => 'Phun thuốc diệt muỗi khu vực hành lang, hầm xe và khuôn viên công viên từ 18:00 - 21:00.',
                'content' => '<p>Nhằm phòng ngừa dịch sốt xuất huyết và đảm bảo vệ sinh môi trường sống xanh sạch đẹp, Ban Quản Lý phối hợp với Trung tâm Y tế dự phòng triển khai phun thuốc diệt muỗi khu vực hành lang, hầm xe và khuôn viên công viên từ 18:00 - 21:00 ngày 05/10/2026.</p>',
                'category' => 'GENERAL',
            ],
            [
                'title' => 'Đăng ký vé thẻ cư dân & Hướng dẫn sử dụng Hồ bơi Sky Pool và Khu BBQ ngoài trời',
                'summary' => 'Mở cổng đặt chỗ tự động trên ứng dụng Smart Cassavas Resident cho hồ bơi và khu nướng BBQ.',
                'content' => '<p>Kính gửi Quý Cư Dân, tiện ích Hồ bơi vô cực chân mây và Khu BBQ ngoài trời đã mở cổng đặt chỗ tự động trên ứng dụng Smart Cassavas Resident. Cư dân có thể đặt lịch trước tối đa 7 ngày và theo dõi thời gian thực trạng thái tiện ích.</p>',
                'category' => 'COMMUNITY_EVENT',
            ],
        ];

        foreach ($announcements as $ann) {
            $existing = DB::table('announcements')->where('title', $ann['title'])->first();
            if (! $existing) {
                DB::table('announcements')->insert([
                    'id' => (string) Str::uuid(),
                    'title' => $ann['title'],
                    'summary_excerpt' => $ann['summary'],
                    'content_html' => $ann['content'],
                    'category' => $ann['category'],
                    'target_scope' => 'ALL_BLOCKS',
                    'is_pinned' => 1,
                    'is_urgent' => 0,
                    'author_user_id' => $authorId,
                    'publish_at' => $now->copy()->subDays(rand(1, 4)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }
    }
}
