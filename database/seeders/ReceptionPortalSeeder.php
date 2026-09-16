<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceptionPortalSeeder extends Seeder
{
    /**
     * Run the database seeds for Reception Portal.
     */
    public function run(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        $this->command->info('Đang nạp dữ liệu mẫu cho Cổng Lễ Tân & An Ninh (Reception Portal)...');

        $now = Carbon::create(2026, 9, 16, 9, 30, 0);

        // Lấy blocks, apartments, users sẵn có
        $blocks = DB::table('blocks')->pluck('id', 'block_code')->toArray();
        $blockAId = $blocks['BLOCK_A'] ?? DB::table('blocks')->value('id') ?? (string) Str::uuid();

        $apartments = DB::table('apartments')->pluck('id', 'apartment_number')->toArray();
        $firstAptId = reset($apartments) ?: (string) Str::uuid();

        $letanUser = DB::table('users')->where('email', 'letan@cassavas.vn')->first();
        $letanUserId = $letanUser ? $letanUser->id : (string) Str::uuid();

        $adminUser = DB::table('users')->where('email', 'admin@cassavas.vn')->first();
        $adminUserId = $adminUser ? $adminUser->id : $letanUserId;

        $residentUser = DB::table('users')->where('email', 'nguyenvanan@cassavas.vn')->first();
        $residentUserId = $residentUser ? $residentUser->id : $adminUserId;

        // 0. DEPARTMENT & STAFF PROFILE
        $deptId = (string) Str::uuid();
        DB::table('departments')->updateOrInsert(
            ['department_code' => 'DEP_RECEPTION'],
            [
                'id' => $deptId,
                'department_name' => 'Bộ phận Lễ tân & Tiền sảnh',
                'head_user_id' => $letanUserId,
                'created_at' => $now,
            ]
        );

        $staffId = (string) Str::uuid();
        DB::table('staff_profiles')->updateOrInsert(
            ['employee_code' => 'EMP-LT001'],
            [
                'id' => $staffId,
                'user_id' => $letanUserId,
                'department_id' => $deptId,
                'job_title' => 'Trưởng ca Lễ tân sảnh chính',
                'hire_date' => '2025-01-15',
                'contract_type' => 'FULL_TIME',
                'assigned_block_id' => $blockAId,
                'base_salary' => 12000000.00,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 1. SMART LOCKERS & COMPARTMENTS
        $lockerId = (string) Str::uuid();
        DB::table('smart_lockers')->updateOrInsert(
            ['locker_code' => 'LCK-SANH-A'],
            [
                'id' => $lockerId,
                'block_id' => $blockAId,
                'locker_name' => 'Tủ Giao Hàng Thông Minh Sảnh Tòa A',
                'total_compartments' => 24,
                'is_active' => 1,
                'created_at' => $now,
            ]
        );

        for ($i = 1; $i <= 12; $i++) {
            $compNum = sprintf('A-%02d', $i);
            DB::table('locker_compartments')->updateOrInsert(
                ['locker_id' => $lockerId, 'compartment_number' => $compNum],
                [
                    'id' => (string) Str::uuid(),
                    'size_category' => $i <= 4 ? 'SMALL' : ($i <= 8 ? 'MEDIUM' : 'LARGE'),
                    'is_occupied' => ($i % 3 === 0) ? 1 : 0,
                    'created_at' => $now,
                ]
            );
        }

        // 2. PARCELS (Bưu phẩm)
        $couriers = ['Shopee Express', 'SPX', 'GHTK', 'GHN', 'ViettelPost', 'J&T'];
        $recipients = [
            ['name' => 'Nguyễn Minh Anh', 'phone' => '0901234501', 'apt' => 'A1001'],
            ['name' => 'Trần Quang Huy', 'phone' => '0901234502', 'apt' => 'A1002'],
            ['name' => 'Lê Thị Thu Thảo', 'phone' => '0901234503', 'apt' => 'A1205'],
            ['name' => 'Phạm Hoàng Long', 'phone' => '0901234504', 'apt' => 'B0802'],
            ['name' => 'Hoàng Diệu Linh', 'phone' => '0901234505', 'apt' => 'B1408'],
            ['name' => 'Vũ Tuấn Kiệt', 'phone' => '0901234506', 'apt' => 'A0504'],
            ['name' => 'Nguyễn Văn An', 'phone' => '0901234567', 'apt' => 'A0301'],
        ];

        // Item đặc biệt khớp hình ảnh mẫu: PKG-031
        DB::table('parcels')->updateOrInsert(
            ['tracking_number' => 'PKG-031'],
            [
                'id' => (string) Str::uuid(),
                'courier_company' => 'Shopee Express',
                'apartment_id' => $firstAptId,
                'recipient_user_id' => $residentUserId,
                'recipient_name' => 'Nguyễn Minh Anh',
                'recipient_phone' => '0901234501',
                'parcel_photo_url' => '/images/parcels/pkg-031.jpg',
                'stored_location_type' => 'RECEPTION_DESK',
                'pickup_pin_code' => '982711',
                'pickup_qr_code' => 'QR-PKG-031-VERIFIED',
                'status' => 'RECEIVED_AT_RECEPTION',
                'received_by_staff_id' => $staffId,
                'received_at' => $now->copy()->subMinutes(15),
                'created_at' => $now->copy()->subMinutes(15),
                'updated_at' => $now->copy()->subMinutes(15),
            ]
        );

        // Tạo 41 kiện hàng khác (trong đó 4 kiện quá hạn > 5 ngày)
        for ($k = 1; $k <= 41; $k++) {
            $trackNo = sprintf('PKG-%03d', $k + 31);
            $rec = $recipients[$k % count($recipients)];
            $aptId = $apartments[$rec['apt']] ?? $firstAptId;
            $isOverdue = ($k <= 4);
            $recvTime = $isOverdue ? $now->copy()->subDays(6)->subHours($k) : $now->copy()->subHours($k);

            DB::table('parcels')->updateOrInsert(
                ['tracking_number' => $trackNo],
                [
                    'id' => (string) Str::uuid(),
                    'courier_company' => $couriers[$k % count($couriers)],
                    'apartment_id' => $aptId,
                    'recipient_user_id' => $residentUserId,
                    'recipient_name' => $rec['name'],
                    'recipient_phone' => $rec['phone'],
                    'parcel_photo_url' => '/images/parcels/sample.jpg',
                    'stored_location_type' => ($k % 2 === 0) ? 'SMART_LOCKER' : 'RECEPTION_DESK',
                    'pickup_pin_code' => sprintf('%06d', 100000 + $k),
                    'pickup_qr_code' => 'QR-PKG-'.$trackNo,
                    'status' => 'RECEIVED_AT_RECEPTION',
                    'received_by_staff_id' => $staffId,
                    'received_at' => $recvTime,
                    'created_at' => $recvTime,
                    'updated_at' => $recvTime,
                ]
            );
        }

        // 3. KHÁCH & CHECK-IN (VISITORS) - 18 khách đang ở trong tòa
        $guestNames = [
            'Nguyễn Minh Anh', 'Lê Hoài Nam', 'Trịnh Quốc Bảo', 'Đỗ Thanh Tùng',
            'Phan Mỹ Duyên', 'Bùi Đức Trọng', 'Vương Đình Huệ', 'Hồ Cẩm Nhung',
            'Tạ Minh Tuấn', 'Đặng Kim Ngân', 'Cao Bá Quát', 'Lý Thường Kiệt',
            'Trần Hưng Đạo', 'Ngô Quyền', 'Lê Lợi', 'Quang Trung',
            'Hai Bà Trưng', 'Võ Thị Sáu',
        ];

        // Khách Nguyễn Minh Anh đã check-in khớp ảnh mẫu
        $regId01 = (string) Str::uuid();
        DB::table('visitor_registrations')->updateOrInsert(
            ['registration_code' => 'VIS-20260916-001'],
            [
                'id' => $regId01,
                'host_resident_user_id' => $residentUserId,
                'apartment_id' => $firstAptId,
                'visitor_name' => 'Nguyễn Minh Anh',
                'visitor_phone' => '0912345678',
                'expected_arrival_time' => $now->copy()->subMinutes(25),
                'visit_purpose' => 'Thăm bạn bè & làm việc',
                'visitor_count' => 1,
                'qr_access_pass_code' => 'QR-VIS-001',
                'qr_pass_status' => 'USED',
                'is_pre_approved_by_resident' => 1,
                'created_at' => $now->copy()->subMinutes(40),
                'updated_at' => $now->copy()->subMinutes(25),
            ]
        );

        DB::table('visitor_checkin_logs')->updateOrInsert(
            ['registration_id' => $regId01],
            [
                'id' => (string) Str::uuid(),
                'apartment_id' => $firstAptId,
                'visitor_name' => 'Nguyễn Minh Anh',
                'visitor_phone' => '0912345678',
                'checkin_time' => $now->copy()->subMinutes(25),
                'checkout_time' => null, // Chưa check-out => đang ở trong tòa
                'receptionist_user_id' => $letanUserId,
                'notes' => 'Khách đến đúng hẹn theo đăng ký trên app cư dân',
                'created_at' => $now->copy()->subMinutes(25),
            ]
        );

        // Thêm 17 khách khác đang ở trong tòa
        for ($v = 1; $v < 18; $v++) {
            $code = sprintf('VIS-20260916-%03d', $v + 1);
            $regId = (string) Str::uuid();
            $gName = $guestNames[$v % count($guestNames)];

            DB::table('visitor_registrations')->updateOrInsert(
                ['registration_code' => $code],
                [
                    'id' => $regId,
                    'host_resident_user_id' => $residentUserId,
                    'apartment_id' => $firstAptId,
                    'visitor_name' => $gName,
                    'visitor_phone' => sprintf('0912345%03d', $v),
                    'expected_arrival_time' => $now->copy()->subHours($v % 4),
                    'visit_purpose' => 'Khách giao dịch / giao hàng / viếng thăm',
                    'visitor_count' => 1,
                    'qr_access_pass_code' => 'QR-VIS-'.($v + 1),
                    'qr_pass_status' => 'USED',
                    'is_pre_approved_by_resident' => 1,
                    'created_at' => $now->copy()->subHours($v % 4),
                    'updated_at' => $now->copy()->subHours($v % 4),
                ]
            );

            DB::table('visitor_checkin_logs')->updateOrInsert(
                ['registration_id' => $regId],
                [
                    'id' => (string) Str::uuid(),
                    'apartment_id' => $firstAptId,
                    'visitor_name' => $gName,
                    'visitor_phone' => sprintf('0912345%03d', $v),
                    'checkin_time' => $now->copy()->subHours($v % 4)->addMinutes(5),
                    'checkout_time' => null,
                    'receptionist_user_id' => $letanUserId,
                    'notes' => 'Đã phát thẻ khách tạm tầng',
                    'created_at' => $now->copy()->subHours($v % 4),
                ]
            );
        }

        // 4. ĐĂNG KÝ XE CHỜ DUYỆT (7 xe)
        // Xe 30K-678.90 khớp ảnh mẫu
        DB::table('vehicles')->updateOrInsert(
            ['license_plate' => '30K-678.90'],
            [
                'id' => (string) Str::uuid(),
                'apartment_id' => $firstAptId,
                'owner_user_id' => $residentUserId,
                'vehicle_category' => 'CAR_4_SEATS',
                'brand' => 'Mazda',
                'model' => 'CX-5',
                'color' => 'Trắng ngọc trai',
                'registration_certificate_number' => 'CA-HN-882711',
                'vehicle_photo_url' => '/images/vehicles/30k67890.jpg',
                'monthly_parking_fee' => 1200000.00,
                'is_active' => 0, // Chờ duyệt
                'approved_by' => null,
                'approved_at' => null,
                'created_at' => $now->copy()->subHours(2),
                'updated_at' => $now->copy()->subHours(2),
            ]
        );

        $pendingPlates = [
            '29A-991.22', '51G-882.19', '30F-445.12',
            '29M1-678.99', '29B1-123.45', '30E-888.66',
        ];

        foreach ($pendingPlates as $idx => $plate) {
            DB::table('vehicles')->updateOrInsert(
                ['license_plate' => $plate],
                [
                    'id' => (string) Str::uuid(),
                    'apartment_id' => $firstAptId,
                    'owner_user_id' => $residentUserId,
                    'vehicle_category' => str_contains($plate, 'M1') || str_contains($plate, 'B1') ? 'MOTORBIKE' : 'CAR_4_SEATS',
                    'brand' => 'Honda / Toyota',
                    'model' => 'Standard',
                    'color' => 'Đen / Trắng',
                    'registration_certificate_number' => 'DKX-'.($idx + 100),
                    'monthly_parking_fee' => str_contains($plate, 'M1') ? 100000.00 : 1200000.00,
                    'is_active' => 0,
                    'approved_by' => null,
                    'approved_at' => null,
                    'created_at' => $now->copy()->subDays($idx + 1),
                    'updated_at' => $now->copy()->subDays($idx + 1),
                ]
            );
        }

        // 5. SỰ CỐ AN NINH (2 sự cố đang xử lý)
        $incidents = [
            [
                'incident_code' => 'SEC-20260916-01',
                'title' => 'Xe lạ đỗ chắn lối đi sảnh hầm B1',
                'description' => 'Xe vãng lai không đăng ký đỗ chắn cửa thoát hiểm hầm B1 Tháp A, bảo vệ đang liên hệ cẩu xe.',
                'severity_level' => 'MEDIUM',
                'status' => 'IN_PROGRESS',
            ],
            [
                'incident_code' => 'SEC-20260916-02',
                'title' => 'Cảnh báo cửa kỹ thuật tầng 14 chưa đóng',
                'description' => 'Cảm biến IoT báo cửa buồng rác / thoát hiểm tầng 14 mở quá 15 phút, đang điều kỹ thuật kiểm tra.',
                'severity_level' => 'LOW',
                'status' => 'INVESTIGATING',
            ],
        ];

        foreach ($incidents as $inc) {
            DB::table('security_incidents')->updateOrInsert(
                ['incident_code' => $inc['incident_code']],
                [
                    'id' => (string) Str::uuid(),
                    'block_id' => $blockAId,
                    'title' => $inc['title'],
                    'description' => $inc['description'],
                    'severity_level' => $inc['severity_level'],
                    'status' => $inc['status'],
                    'reporter_user_id' => $letanUserId,
                    'incident_time' => $now->copy()->subMinutes(50),
                ]
            );
        }

        // 6. ĐỒ THẤT LẠC (Lost & Found)
        $lostItems = [
            [
                'item_name' => 'Chìa khóa xe máy Smartkey Honda',
                'description' => 'Chìa khóa thông minh xe SH có gắn móc khóa hoạt hình Doraemon',
                'found_location' => 'Ghế sofa sảnh lễ tân Tháp A',
                'status' => 'UNCLAIMED',
            ],
            [
                'item_name' => 'Ví da nam màu nâu Montblanc',
                'description' => 'Bên trong có giấy tờ tùy thân và thẻ cư dân chưa rõ danh tính',
                'found_location' => 'Khu vực máy bán nước tự động tầng 1',
                'status' => 'UNCLAIMED',
            ],
        ];

        foreach ($lostItems as $lf) {
            DB::table('lost_and_found')->updateOrInsert(
                ['item_name' => $lf['item_name']],
                [
                    'id' => (string) Str::uuid(),
                    'description' => $lf['description'],
                    'found_location' => $lf['found_location'],
                    'status' => $lf['status'],
                    'found_by_user_id' => $letanUserId,
                    'found_time' => $now->copy()->subHours(3),
                    'created_at' => $now->copy()->subHours(3),
                ]
            );
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }

        $this->command->info('Đã nạp thành công 18 khách check-in, 42 bưu phẩm (4 quá hạn), 7 xe chờ duyệt, 2 sự cố an ninh!');
    }
}
