<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResidentTicketSeeder extends Seeder
{
    /**
     * Nạp dữ liệu mẫu cho Module Báo cáo sự cố & sửa chữa (Ticket) dành cho Cư dân.
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

        // =========================================================================
        // 1. TẠO DANH MỤC SỰ CỐ KỸ THUẬT (TICKET CATEGORIES)
        // =========================================================================
        $categoriesData = [
            [
                'code' => 'PLUMBING',
                'name' => 'Cấp thoát nước & Thiết bị vệ sinh',
                'priority' => 'HIGH',
                'response_min' => 30,
                'resolution_hr' => 12,
                'team' => 'TECHNICAL_PLUMBING',
            ],
            [
                'code' => 'ELECTRICAL',
                'name' => 'Điện sinh hoạt & Hệ thống chiếu sáng',
                'priority' => 'HIGH',
                'response_min' => 20,
                'resolution_hr' => 8,
                'team' => 'TECHNICAL_ELECTRICAL',
            ],
            [
                'code' => 'HVAC',
                'name' => 'Điều hòa không khí & Thông gió',
                'priority' => 'MEDIUM',
                'response_min' => 60,
                'resolution_hr' => 24,
                'team' => 'TECHNICAL_HVAC',
            ],
            [
                'code' => 'DOORS_WINDOWS',
                'name' => 'Cửa sổ, Khóa cửa & Kính cường lực',
                'priority' => 'MEDIUM',
                'response_min' => 45,
                'resolution_hr' => 24,
                'team' => 'TECHNICAL_CARPENTRY',
            ],
            [
                'code' => 'FIRE_SAFETY',
                'name' => 'Hệ thống PCCC & Cảm biến khói',
                'priority' => 'URGENT',
                'response_min' => 10,
                'resolution_hr' => 4,
                'team' => 'SAFETY_SECURITY',
            ],
            [
                'code' => 'WATERPROOFING',
                'name' => 'Chống thấm, Tường & Ban công',
                'priority' => 'LOW',
                'response_min' => 120,
                'resolution_hr' => 48,
                'team' => 'CIVIL_CONSTRUCTION',
            ],
            [
                'code' => 'SMART_HOME',
                'name' => 'Chuông cửa có hình & Thiết bị thông minh',
                'priority' => 'LOW',
                'response_min' => 60,
                'resolution_hr' => 24,
                'team' => 'IT_SUPPORT',
            ],
            [
                'code' => 'OTHER',
                'name' => 'Ý kiến đóng góp & Phản ánh khác',
                'priority' => 'LOW',
                'response_min' => 120,
                'resolution_hr' => 48,
                'team' => 'CUSTOMER_SERVICE',
            ],
        ];

        $categoryMap = [];
        foreach ($categoriesData as $c) {
            $existing = DB::table('ticket_categories')->where('category_code', $c['code'])->first();
            $catId = $existing ? $existing->id : (string) Str::uuid();
            $categoryMap[$c['code']] = $catId;

            DB::table('ticket_categories')->updateOrInsert(
                ['category_code' => $c['code']],
                [
                    'id' => $catId,
                    'category_name' => $c['name'],
                    'default_priority' => $c['priority'],
                    'sla_response_time_minutes' => $c['response_min'],
                    'sla_resolution_time_hours' => $c['resolution_hr'],
                    'default_assigned_team' => $c['team'],
                    'created_at' => $existing ? $existing->created_at : $now->copy()->subMonths(1),
                ]
            );
        }

        // =========================================================================
        // 2. TÀI KHOẢN CƯ DÂN DEMO (NGUYỄN VĂN AN)
        // =========================================================================
        $user = DB::table('users')
            ->where('email', 'nguyenvanan@cassavas.vn')
            ->orWhere('username', 'nguyenvanan')
            ->first();

        if ($user) {
            $userId = $user->id;
        } else {
            $userId = '0ccf6eed-ca47-40ad-ac5a-7100f723fe8d';
            DB::table('users')->insert([
                'id' => $userId,
                'username' => 'nguyenvanan',
                'email' => 'nguyenvanan@cassavas.vn',
                'password_hash' => Hash::make('password123'),
                'full_name' => 'Nguyễn Văn An',
                'phone_number' => '0901234567',
                'status' => 'ACTIVE',
                'created_at' => $now->copy()->subMonths(6),
                'updated_at' => $now,
            ]);
        }

        // Kỹ thuật viên xử lý (nếu có)
        $technicianUser = DB::table('users')->where('username', 'kythuat')->orWhere('email', 'like', '%kythuat%')->first();
        $technicianId = $technicianUser ? $technicianUser->id : null;

        // =========================================================================
        // 3. ĐẢM BẢO CƯ DÂN CÓ NHIỀU CĂN HỘ ĐỂ TEST TÍNH NĂNG CHỌN CĂN HỘ
        // =========================================================================
        $blockA = DB::table('blocks')->where('block_code', 'BLOCK_A')->first();
        $blockB = DB::table('blocks')->where('block_code', 'BLOCK_B')->first();

        $apt1 = DB::table('apartments')
            ->where('apartment_number', 'A1-05')
            ->orWhere('apartment_number', '1204')
            ->first();
        $apt1Id = $apt1 ? $apt1->id : DB::table('apartments')->value('id');

        $apt2 = DB::table('apartments')
            ->where('apartment_number', 'B2-08')
            ->orWhere('apartment_number', '1508')
            ->first();
        if (! $apt2 && $blockB) {
            $floorId = DB::table('floors')->where('block_id', $blockB->id)->where('floor_number', 2)->value('id');
            if (! $floorId) {
                $floorId = (string) Str::uuid();
                DB::table('floors')->insert([
                    'id' => $floorId,
                    'block_id' => $blockB->id,
                    'floor_number' => 2,
                    'floor_code' => 'B-F02',
                    'floor_name' => 'Tầng 02',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $apt2Id = (string) Str::uuid();
            DB::table('apartments')->insert([
                'id' => $apt2Id,
                'block_id' => $blockB->id,
                'floor_id' => $floorId,
                'apartment_number' => 'B2-08',
                'room_type' => '3_BEDROOM',
                'gross_floor_area_sqm' => 100,
                'net_usable_area_sqm' => 95,
                'bedroom_count' => 3,
                'status' => 'OCCUPIED',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $apt2Id = $apt2 ? $apt2->id : $apt1Id;
        }

        // Gán cư dân vào cả 2 căn hộ (nếu chưa gán)
        if ($apt1Id) {
            DB::table('residents')->updateOrInsert(
                ['user_id' => $userId, 'apartment_id' => $apt1Id],
                [
                    'id' => (string) Str::uuid(),
                    'resident_type' => 'OWNER',
                    'is_head_of_household' => 1,
                    'stay_start_date' => '2025-01-01',
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if ($apt2Id && $apt2Id !== $apt1Id) {
            DB::table('residents')->updateOrInsert(
                ['user_id' => $userId, 'apartment_id' => $apt2Id],
                [
                    'id' => (string) Str::uuid(),
                    'resident_type' => 'OWNER',
                    'is_head_of_household' => 1,
                    'stay_start_date' => '2025-06-01',
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // =========================================================================
        // 4. DANH SÁCH TICKETS MẪU CHO CƯ DÂN
        // =========================================================================
        $seedTickets = [
            [
                'number' => 'TCK-202610-00101',
                'category' => 'PLUMBING',
                'apt_id' => $apt1Id,
                'title' => 'Rò rỉ nước dưới bồn rửa chén khu vực bếp',
                'location' => 'Khu vực bếp',
                'priority' => 'HIGH',
                'status' => 'IN_PROGRESS',
                'desc' => 'Ống xả siphon dưới bồn rửa bị nứt nhẹ, nước rỉ ra sàn tủ bếp gây ẩm mốc và bốc mùi khó chịu.',
                'pref_time' => $now->copy()->addDay()->format('Y-m-d'),
                'created_at' => $now->copy()->subDays(2),
                'updated_at' => $now->copy()->subHours(4),
                'history' => [
                    ['action' => 'CREATED', 'old' => null, 'new' => 'NEW', 'note' => 'Cư dân gửi yêu cầu hỗ trợ sửa chữa vòi nước.', 'time' => $now->copy()->subDays(2)],
                    ['action' => 'RECEIVED', 'old' => 'NEW', 'new' => 'RECEIVED', 'note' => 'Ban quản lý đã tiếp nhận và cử kỹ thuật viên chuyên trách.', 'time' => $now->copy()->subDays(1)->subHours(5)],
                    ['action' => 'STATUS_CHANGED', 'old' => 'RECEIVED', 'new' => 'IN_PROGRESS', 'note' => 'Kỹ thuật viên đang kiểm tra phụ tùng thay thế ống xả.', 'time' => $now->copy()->subHours(4)],
                ],
            ],
            [
                'number' => 'TCK-202610-00102',
                'category' => 'ELECTRICAL',
                'apt_id' => $apt1Id,
                'title' => 'Chập công tắc đèn chiếu sáng phòng khách',
                'location' => 'Phòng khách',
                'priority' => 'URGENT',
                'status' => 'NEW',
                'desc' => 'Bật công tắc đèn trần phòng khách thấy có tiếng nổ lách tách và có mùi khét, đã tạm thời ngắt aptomat chiếu sáng.',
                'pref_time' => $now->copy()->format('Y-m-d'),
                'created_at' => $now->copy()->subHours(3),
                'updated_at' => $now->copy()->subHours(3),
                'history' => [
                    ['action' => 'CREATED', 'old' => null, 'new' => 'NEW', 'note' => 'Cư dân báo sự cố khẩn cấp về chập điện phòng khách.', 'time' => $now->copy()->subHours(3)],
                ],
            ],
            [
                'number' => 'TCK-202610-00103',
                'category' => 'HVAC',
                'apt_id' => $apt1Id,
                'title' => 'Điều hòa phòng ngủ master không mát và chảy nước',
                'location' => 'Phòng ngủ master',
                'priority' => 'MEDIUM',
                'status' => 'RESOLVED',
                'desc' => 'Điều hòa bật 18 độ nhưng chỉ ra gió thoang thoảng, dàn lạnh thỉnh thoảng nhỏ giọt nước xuống sàn gỗ.',
                'pref_time' => $now->copy()->subDays(3)->format('Y-m-d'),
                'created_at' => $now->copy()->subDays(4),
                'updated_at' => $now->copy()->subHours(6),
                'history' => [
                    ['action' => 'CREATED', 'old' => null, 'new' => 'NEW', 'note' => 'Yêu cầu kiểm tra bảo dưỡng điều hòa.', 'time' => $now->copy()->subDays(4)],
                    ['action' => 'RECEIVED', 'old' => 'NEW', 'new' => 'RECEIVED', 'note' => 'Tiếp nhận yêu cầu bảo trì.', 'time' => $now->copy()->subDays(3)],
                    ['action' => 'STATUS_CHANGED', 'old' => 'RECEIVED', 'new' => 'IN_PROGRESS', 'note' => 'Kỹ thuật viên vệ sinh dàn lạnh và thông đường ống nước thải.', 'time' => $now->copy()->subDays(1)],
                    ['action' => 'STATUS_CHANGED', 'old' => 'IN_PROGRESS', 'new' => 'RESOLVED', 'note' => 'Đã khắc phục hoàn tất, bơm bổ sung gas R32.', 'time' => $now->copy()->subHours(6)],
                ],
            ],
            [
                'number' => 'TCK-202610-00104',
                'category' => 'DOORS_WINDOWS',
                'apt_id' => $apt2Id,
                'title' => 'Khóa vân tay cửa chính báo pin yếu liên tục',
                'location' => 'Cửa ra vào',
                'priority' => 'LOW',
                'status' => 'CLOSED',
                'desc' => 'Khóa cửa vân tay thông minh kêu tít tít báo pin yếu dù mới thay pin 2 tháng trước.',
                'pref_time' => $now->copy()->subDays(8)->format('Y-m-d'),
                'created_at' => $now->copy()->subDays(10),
                'updated_at' => $now->copy()->subDays(7),
                'history' => [
                    ['action' => 'CREATED', 'old' => null, 'new' => 'NEW', 'note' => 'Yêu cầu kiểm tra khóa cửa chính.', 'time' => $now->copy()->subDays(10)],
                    ['action' => 'RECEIVED', 'old' => 'NEW', 'new' => 'RECEIVED', 'note' => 'Ghi nhận sự cố khóa cửa.', 'time' => $now->copy()->subDays(9)],
                    ['action' => 'STATUS_CHANGED', 'old' => 'RECEIVED', 'new' => 'IN_PROGRESS', 'note' => 'Kỹ thuật viên kiểm tra nguồn bo mạch khóa.', 'time' => $now->copy()->subDays(8)],
                    ['action' => 'CONFIRMED', 'old' => 'RESOLVED', 'new' => 'CLOSED', 'note' => 'Cư dân đã nghiệm thu: Khóa hoạt động bình thường, không còn hao pin.', 'time' => $now->copy()->subDays(7)],
                ],
            ],
            [
                'number' => 'TCK-202610-00105',
                'category' => 'WATERPROOFING',
                'apt_id' => $apt2Id,
                'title' => 'Nứt nhẹ kính chắn gió ban công sau bão',
                'location' => 'Ban công',
                'priority' => 'MEDIUM',
                'status' => 'CANCELLED',
                'desc' => 'Phát hiện vết rạn nhỏ ở góc mép kính ban công sau trận mưa bão lớn.',
                'pref_time' => $now->copy()->subDays(15)->format('Y-m-d'),
                'created_at' => $now->copy()->subDays(16),
                'updated_at' => $now->copy()->subDays(15),
                'history' => [
                    ['action' => 'CREATED', 'old' => null, 'new' => 'NEW', 'note' => 'Yêu cầu kiểm tra tấm kính ban công.', 'time' => $now->copy()->subDays(16)],
                    ['action' => 'CANCELLED', 'old' => 'NEW', 'new' => 'CANCELLED', 'note' => 'Cư dân hủy yêu cầu: Kiểm tra lại là vết keo dán bám bụi, không phải nứt kính.', 'time' => $now->copy()->subDays(15)],
                ],
            ],
            [
                'number' => 'TCK-202610-00106',
                'category' => 'FIRE_SAFETY',
                'apt_id' => $apt1Id,
                'title' => 'Đầu báo khói nhấp nháy đèn đỏ ngắt quãng',
                'location' => 'Hành lang',
                'priority' => 'HIGH',
                'status' => 'RECEIVED',
                'desc' => 'Đầu cảm biến nhiệt và khói PCCC trước cửa ra vào nhấp nháy đèn đỏ liên tục, cần kỹ thuật viên kiểm tra tín hiệu trung tâm.',
                'pref_time' => $now->copy()->addDays(2)->format('Y-m-d'),
                'created_at' => $now->copy()->subHours(10),
                'updated_at' => $now->copy()->subHours(8),
                'history' => [
                    ['action' => 'CREATED', 'old' => null, 'new' => 'NEW', 'note' => 'Cư dân gửi báo cáo đầu cảm biến PCCC.', 'time' => $now->copy()->subHours(10)],
                    ['action' => 'RECEIVED', 'old' => 'NEW', 'new' => 'RECEIVED', 'note' => 'Bộ phận PCCC tòa nhà đã tiếp nhận và hẹn lịch rà soát hệ thống.', 'time' => $now->copy()->subHours(8)],
                ],
            ],
        ];

        foreach ($seedTickets as $ticketData) {
            $catId = $categoryMap[$ticketData['category']] ?? $categoryMap['OTHER'];
            $existing = DB::table('tickets')->where('ticket_number', $ticketData['number'])->first();
            $ticketId = $existing ? $existing->id : (string) Str::uuid();

            DB::table('tickets')->updateOrInsert(
                ['ticket_number' => $ticketData['number']],
                [
                    'id' => $ticketId,
                    'category_id' => $catId,
                    'apartment_id' => $ticketData['apt_id'],
                    'creator_user_id' => $userId,
                    'current_technician_user_id' => $technicianId,
                    'title' => $ticketData['title'],
                    'description' => $ticketData['desc'],
                    'location' => $ticketData['location'],
                    'priority' => $ticketData['priority'],
                    'status' => $ticketData['status'],
                    'preferred_service_time' => $ticketData['pref_time'],
                    'version' => 1,
                    'sla_deadline' => $ticketData['created_at']->copy()->addHours(24),
                    'resolved_at' => in_array($ticketData['status'], ['RESOLVED', 'CLOSED']) ? $ticketData['updated_at'] : null,
                    'closed_at' => $ticketData['status'] === 'CLOSED' ? $ticketData['updated_at'] : null,
                    'created_at' => $ticketData['created_at'],
                    'updated_at' => $ticketData['updated_at'],
                ]
            );

            // Seed Lịch sử thay đổi (Timeline)
            if (! empty($ticketData['history'])) {
                foreach ($ticketData['history'] as $hist) {
                    $existsHistory = DB::table('ticket_history')
                        ->where('ticket_id', $ticketId)
                        ->where('action_type', $hist['action'])
                        ->where('notes', $hist['note'])
                        ->exists();

                    if (! $existsHistory) {
                        DB::table('ticket_history')->insert([
                            'id' => (string) Str::uuid(),
                            'ticket_id' => $ticketId,
                            'performed_by_user_id' => $userId,
                            'action_type' => $hist['action'],
                            'old_status' => $hist['old'],
                            'new_status' => $hist['new'],
                            'notes' => $hist['note'],
                            'created_at' => $hist['time'],
                        ]);
                    }
                }
            }
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->command?->info('✅ Nạp dữ liệu mẫu Ticket sự cố cư dân thành công!');
    }
}
