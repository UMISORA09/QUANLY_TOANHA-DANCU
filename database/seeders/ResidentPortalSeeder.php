<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResidentPortalSeeder extends Seeder
{
    /**
     * Nạp dữ liệu Cổng Cư Dân (Resident Portal) cho tài khoản Nguyễn Văn A - Căn hộ A1-05
     */
    public function run(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        $now = Carbon::create(2026, 9, 8, 9, 0, 0);

        // 1. TẠO HOẶC LẤY ROLE RESIDENT_OWNER
        $residentRole = DB::table('roles')->where('role_code', 'RESIDENT_OWNER')->first();
        if (! $residentRole) {
            $residentRoleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $residentRoleId,
                'role_code' => 'RESIDENT_OWNER',
                'role_name' => 'Cư Dân Chủ Hộ',
                'description' => 'Chủ sở hữu căn hộ',
                'is_system_role' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $residentRoleId = $residentRole->id;
        }

        // 2. TÌM HOẶC CẬP NHẬT TÀI KHOẢN NGUYỄN VĂN AN (CƯ DÂN CHỦ HỘ A1-05)
        $defaultPassword = Hash::make('123567');
        $existingUser = DB::table('users')
            ->where('email', 'nguyenvanan@cassavas.vn')
            ->orWhere('username', 'nguyenvanan')
            ->orWhere('phone_number', '0901234567')
            ->first();

        if ($existingUser) {
            $userId = $existingUser->id;
            DB::table('users')->where('id', $userId)->update([
                'username' => 'nguyenvanan',
                'email' => 'nguyenvanan@cassavas.vn',
                'password_hash' => $defaultPassword,
                'full_name' => 'Nguyễn Văn An',
                'phone_number' => '0901234567',
                'gender' => 'MALE',
                'date_of_birth' => '1990-05-15',
                'national_id_number' => '079090123456',
                'status' => 'ACTIVE',
                'updated_at' => $now,
            ]);
        } else {
            $userId = 'c0000001-0000-0000-0000-000000000001';
            DB::table('users')->insert([
                'id' => $userId,
                'username' => 'nguyenvanan',
                'email' => 'nguyenvanan@cassavas.vn',
                'password_hash' => $defaultPassword,
                'full_name' => 'Nguyễn Văn An',
                'phone_number' => '0901234567',
                'gender' => 'MALE',
                'date_of_birth' => '1990-05-15',
                'national_id_number' => '079090123456',
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Gán Role cho user
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $userId, 'role_id' => $residentRoleId],
            ['assigned_at' => $now]
        );

        // 3. TẠO CĂN HỘ A1-05
        $block = DB::table('blocks')->where('block_code', 'BLOCK_A')->first();
        $blockId = $block ? $block->id : 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

        $floor = DB::table('floors')->where('block_id', $blockId)->first();
        $floorId = $floor ? $floor->id : '02870910-7e47-4c24-b5e7-e23f763c2964';

        $apartmentId = 'a1050000-0000-0000-0000-000000000105';
        DB::table('apartments')->updateOrInsert(
            ['apartment_number' => 'A1-05'],
            [
                'id' => $apartmentId,
                'block_id' => $blockId,
                'floor_id' => $floorId,
                'room_type' => '2_BEDROOM',
                'gross_floor_area_sqm' => 85.50,
                'net_usable_area_sqm' => 80.20,
                'bedroom_count' => 2,
                'bathroom_count' => 2,
                'status' => 'OCCUPIED',
                'current_resident_user_id' => $userId,
                'monthly_management_fee_fixed' => 1250000.00,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // Gán Cư dân vào Căn hộ A1-05
        DB::table('residents')->updateOrInsert(
            ['user_id' => $userId, 'apartment_id' => $apartmentId],
            [
                'id' => (string) Str::uuid(),
                'resident_type' => 'OWNER',
                'is_head_of_household' => 1,
                'stay_start_date' => '2025-01-15',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 4. HÓA ĐƠN THÁNG 09/2026: 2.450.000đ - HẠN 15/09/2026 - CHƯA THANH TOÁN
        $invoiceId = 'inv-202609-a105-0000-000000000001';
        DB::table('invoices')->updateOrInsert(
            ['invoice_number' => 'INV-2026-09-A105'],
            [
                'id' => $invoiceId,
                'apartment_id' => $apartmentId,
                'resident_user_id' => $userId,
                'billing_period' => '2026-09',
                'issue_date' => '2026-09-01',
                'due_date' => '2026-09-15',
                'subtotal_amount' => 2227272.73,
                'tax_amount' => 222727.27,
                'total_amount' => 2450000.00,
                'paid_amount' => 0.00,
                'remaining_balance' => 2450000.00,
                'status' => 'ISSUED',
                'notes' => 'Hóa đơn dịch vụ quản lý tòa nhà và điện nước tháng 09/2026',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 5. TICKET SỰ CỐ: 02 YÊU CẦU ĐANG XỬ LÝ (01 ƯU TIÊN CAO)
        $plumbingCategory = DB::table('ticket_categories')
            ->where('category_name', 'LIKE', '%Điện nước%')
            ->orWhere('category_code', 'PLUMBING')
            ->first();
        $categoryId = $plumbingCategory ? $plumbingCategory->id : '6bfc231f-09cc-488a-a873-79aaf6adaa5d';

        // Ticket 1: TICKET-1024 - Sửa vòi nước phòng tắm (Ưu tiên cao - Đang xử lý)
        DB::table('tickets')->updateOrInsert(
            ['ticket_number' => 'TICKET-1024'],
            [
                'id' => (string) Str::uuid(),
                'category_id' => $categoryId,
                'apartment_id' => $apartmentId,
                'creator_user_id' => $userId,
                'title' => 'Sửa vòi nước phòng tắm',
                'description' => 'Vòi sen phòng tắm master bị rò rỉ nước ở khớp nối van điều áp.',
                'priority' => 'HIGH',
                'status' => 'IN_PROGRESS',
                'preferred_service_time' => Carbon::create(2026, 9, 8, 14, 0, 0),
                'sla_deadline' => Carbon::create(2026, 9, 9, 17, 0, 0),
                'created_at' => Carbon::create(2026, 9, 8, 8, 15, 0),
                'updated_at' => Carbon::create(2026, 9, 8, 9, 0, 0),
            ]
        );

        // Ticket 2: TICKET-1025 - Kiểm tra cảm biến báo cháy (Ưu tiên trung bình - Đang xử lý)
        DB::table('tickets')->updateOrInsert(
            ['ticket_number' => 'TICKET-1025'],
            [
                'id' => (string) Str::uuid(),
                'category_id' => $categoryId,
                'apartment_id' => $apartmentId,
                'creator_user_id' => $userId,
                'title' => 'Kiểm tra cảm biến báo cháy',
                'description' => 'Cảm biến khói tại bếp nhấp nháy đèn vàng cần kiểm tra định kỳ.',
                'priority' => 'MEDIUM',
                'status' => 'IN_PROGRESS',
                'preferred_service_time' => Carbon::create(2026, 9, 9, 10, 0, 0),
                'sla_deadline' => Carbon::create(2026, 9, 10, 18, 0, 0),
                'created_at' => Carbon::create(2026, 9, 7, 16, 20, 0),
                'updated_at' => Carbon::create(2026, 9, 8, 7, 30, 0),
            ]
        );

        // 6. TIỆN ÍCH & LỊCH ĐẶT: 03 LỊCH SẮP TỚI (Bao gồm Sân cầu lông 12/09 - 18:00)
        // Tìm hoặc tạo Tiện ích Sân cầu lông
        $badminton = DB::table('amenities')->where('amenity_name', 'LIKE', '%Sân cầu lông%')->first();
        if (! $badminton) {
            $sportCategory = DB::table('amenity_categories')->first();
            $badmintonId = (string) Str::uuid();
            DB::table('amenities')->insert([
                'id' => $badmintonId,
                'category_id' => $sportCategory ? $sportCategory->id : (string) Str::uuid(),
                'block_id' => $blockId,
                'amenity_name' => 'Sân cầu lông',
                'amenity_code' => 'AMENITY_BADMINTON_01',
                'location_detail' => 'Khu liên hợp thể thao Tầng 3 Tháp A',
                'max_capacity_per_slot' => 4,
                'hourly_rate' => 0.00,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $badmintonId = $badminton->id;
        }

        // Lấy thêm tiện ích khác để tạo 3 lịch đặt sắp tới
        $otherAmenities = DB::table('amenities')->where('id', '!=', $badmintonId)->limit(2)->get();
        $gymId = $otherAmenities->count() > 0 ? $otherAmenities[0]->id : $badmintonId;
        $bbqId = $otherAmenities->count() > 1 ? $otherAmenities[1]->id : $badmintonId;

        // Lịch 1: Đặt sân cầu lông (12/09 - 18:00) - Đã xác nhận
        DB::table('amenity_bookings')->updateOrInsert(
            ['booking_code' => 'BK-CL-20260912'],
            [
                'id' => (string) Str::uuid(),
                'amenity_id' => $badmintonId,
                'apartment_id' => $apartmentId,
                'resident_user_id' => $userId,
                'booking_date' => '2026-09-12',
                'start_time' => '18:00:00',
                'end_time' => '19:30:00',
                'attendee_count' => 4,
                'total_amount' => 0.00,
                'is_paid' => 1,
                'status' => 'CONFIRMED',
                'checkin_qr_code' => 'QR_CL_20260912_'.Str::random(16),
                'created_at' => Carbon::create(2026, 9, 6, 11, 0, 0),
                'updated_at' => Carbon::create(2026, 9, 6, 11, 0, 0),
            ]
        );

        // Lịch 2: Phòng tập thể thao (18/09 - 07:00) - Đã xác nhận
        DB::table('amenity_bookings')->updateOrInsert(
            ['booking_code' => 'BK-GYM-20260918'],
            [
                'id' => (string) Str::uuid(),
                'amenity_id' => $gymId,
                'apartment_id' => $apartmentId,
                'resident_user_id' => $userId,
                'booking_date' => '2026-09-18',
                'start_time' => '07:00:00',
                'end_time' => '08:30:00',
                'attendee_count' => 2,
                'total_amount' => 0.00,
                'is_paid' => 1,
                'status' => 'CONFIRMED',
                'checkin_qr_code' => 'QR_GYM_20260918_'.Str::random(16),
                'created_at' => Carbon::create(2026, 9, 7, 14, 0, 0),
                'updated_at' => Carbon::create(2026, 9, 7, 14, 0, 0),
            ]
        );

        // Lịch 3: Tiện ích ngoài trời / BBQ (25/09 - 18:00) - Đã xác nhận
        DB::table('amenity_bookings')->updateOrInsert(
            ['booking_code' => 'BK-BBQ-20260925'],
            [
                'id' => (string) Str::uuid(),
                'amenity_id' => $bbqId,
                'apartment_id' => $apartmentId,
                'resident_user_id' => $userId,
                'booking_date' => '2026-09-25',
                'start_time' => '18:00:00',
                'end_time' => '21:00:00',
                'attendee_count' => 6,
                'total_amount' => 150000.00,
                'is_paid' => 1,
                'status' => 'CONFIRMED',
                'checkin_qr_code' => 'QR_BBQ_20260925_'.Str::random(16),
                'created_at' => Carbon::create(2026, 9, 8, 8, 30, 0),
                'updated_at' => Carbon::create(2026, 9, 8, 8, 30, 0),
            ]
        );

        // 7. KHÁCH ĐÃ KHAI BÁO: 04 KHÁCH TRONG THÁNG NÀY (THÁNG 09/2026)
        $visitors = [
            [
                'code' => 'VIS-20260904-001',
                'name' => 'Trần Văn Bình',
                'phone' => '0918112233',
                'purpose' => 'Giao hàng nội thất phòng khách',
                'arrival' => '2026-09-04 10:30:00',
                'plate' => '29B1-888.99',
            ],
            [
                'code' => 'VIS-20260906-002',
                'name' => 'Lê Thị Thu',
                'phone' => '0977223344',
                'purpose' => 'Thăm người thân',
                'arrival' => '2026-09-06 15:00:00',
                'plate' => '30A-678.90',
            ],
            [
                'code' => 'VIS-20260910-003',
                'name' => 'Nguyễn Quốc Hưng',
                'phone' => '0933445566',
                'purpose' => 'Khách đến dự tiệc gia đình',
                'arrival' => '2026-09-10 18:00:00',
                'plate' => '51G-123.45',
            ],
            [
                'code' => 'VIS-20260914-004',
                'name' => 'Phạm Hoàng Nam',
                'phone' => '0988556677',
                'purpose' => 'Bảo hành điện tử / Lắp rèm',
                'arrival' => '2026-09-14 09:00:00',
                'plate' => '29D-456.78',
            ],
        ];

        foreach ($visitors as $v) {
            DB::table('visitor_registrations')->updateOrInsert(
                ['registration_code' => $v['code']],
                [
                    'id' => (string) Str::uuid(),
                    'host_resident_user_id' => $userId,
                    'apartment_id' => $apartmentId,
                    'visitor_name' => $v['name'],
                    'visitor_phone' => $v['phone'],
                    'visit_purpose' => $v['purpose'],
                    'expected_arrival_time' => $v['arrival'],
                    'vehicle_license_plate' => $v['plate'],
                    'qr_access_pass_code' => 'PASS_'.Str::random(20),
                    'qr_pass_status' => 'ACTIVE',
                    'is_pre_approved_by_resident' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }

        $this->command->info('✅ Đã nạp thành công dữ liệu ảo Cổng Cư Dân (Resident Portal - A1-05).');
    }
}
