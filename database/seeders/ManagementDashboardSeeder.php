<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ManagementDashboardSeeder extends Seeder
{
    /**
     * Run the database seeds for Management Dashboard data.
     */
    public function run(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        $this->command->info('Đang nạp dữ liệu ảo cho Trang Quản trị Tòa nhà & Dân cư...');

        $now = Carbon::create(2026, 9, 15, 8, 30, 0);

        // 1. ROLES
        $roles = [
            ['id' => (string) Str::uuid(), 'role_code' => 'SUPER_ADMIN', 'role_name' => 'Quản Trị Viên Cấp Cao', 'description' => 'Toàn quyền kiểm soát hệ thống'],
            ['id' => (string) Str::uuid(), 'role_code' => 'BUILDING_MANAGER', 'role_name' => 'Ban Quản Lý Tòa Nhà', 'description' => 'Quản lý vận hành toàn diện'],
            ['id' => (string) Str::uuid(), 'role_code' => 'ACCOUNTANT', 'role_name' => 'Kế Toán Tòa Nhà', 'description' => 'Quản lý thu chi, hóa đơn'],
            ['id' => (string) Str::uuid(), 'role_code' => 'RECEPTIONIST', 'role_name' => 'Nhân Viên Lễ Tân', 'description' => 'Tiếp đón và hỗ trợ cư dân'],
            ['id' => (string) Str::uuid(), 'role_code' => 'SECURITY_GUARD', 'role_name' => 'Nhân Viên Bảo Vệ', 'description' => 'An ninh, tuần tra, camera'],
            ['id' => (string) Str::uuid(), 'role_code' => 'TECHNICIAN', 'role_name' => 'Nhân Viên Kỹ Thuật', 'description' => 'Sửa chữa điện nước, bảo trì'],
            ['id' => (string) Str::uuid(), 'role_code' => 'RESIDENT_OWNER', 'role_name' => 'Cư Dân Chủ Hộ', 'description' => 'Chủ sở hữu căn hộ'],
            ['id' => (string) Str::uuid(), 'role_code' => 'RESIDENT_MEMBER', 'role_name' => 'Thành Viên Trong Hộ', 'description' => 'Thành viên cùng sinh sống'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['role_code' => $role['role_code']],
                [
                    'role_name' => $role['role_name'],
                    'description' => $role['description'],
                    'is_system_role' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $roleMap = DB::table('roles')->pluck('id', 'role_code')->toArray();

        // 2. CORE USERS & STAFF
        $defaultPassword = Hash::make('123567');

        $users = [
            [
                'id' => (string) Str::uuid(),
                'username' => 'admin',
                'email' => 'admin@cassavas.vn',
                'phone_number' => '0900000001',
                'full_name' => 'Admin Cassavas',
                'gender' => 'MALE',
                'role_code' => 'SUPER_ADMIN',
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'quanly',
                'email' => 'quanly@cassavas.vn',
                'phone_number' => '0900000002',
                'full_name' => 'Ban Quản Lý Cassavas',
                'gender' => 'FEMALE',
                'role_code' => 'BUILDING_MANAGER',
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'kythuat',
                'email' => 'kythuat@cassavas.vn',
                'phone_number' => '0900000003',
                'full_name' => 'Kỹ Thuật Viên Otis',
                'gender' => 'MALE',
                'role_code' => 'TECHNICIAN',
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'letan',
                'email' => 'letan@cassavas.vn',
                'phone_number' => '0900000004',
                'full_name' => 'Lễ Tân Sảnh Chính',
                'gender' => 'FEMALE',
                'role_code' => 'RECEPTIONIST',
            ],
            // Key Residents displayed on UI
            [
                'id' => (string) Str::uuid(),
                'username' => 'nguyenvanan',
                'email' => 'nguyenvanan@cassavas.vn',
                'phone_number' => '0901234567',
                'full_name' => 'Nguyễn Văn An',
                'gender' => 'MALE',
                'role_code' => 'RESIDENT_OWNER',
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'tranmai',
                'email' => 'tranmai@cassavas.vn',
                'phone_number' => '0912345678',
                'full_name' => 'Trần Thị Mai',
                'gender' => 'FEMALE',
                'role_code' => 'RESIDENT_OWNER',
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'lehoangnam',
                'email' => 'lehoangnam@cassavas.vn',
                'phone_number' => '0987654321',
                'full_name' => 'Lê Hoàng Nam',
                'gender' => 'MALE',
                'role_code' => 'RESIDENT_OWNER',
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'phamduc',
                'email' => 'phamduc@cassavas.vn',
                'phone_number' => '0971234567',
                'full_name' => 'Phạm Minh Đức',
                'gender' => 'MALE',
                'role_code' => 'RESIDENT_OWNER',
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'vulan',
                'email' => 'vulan@cassavas.vn',
                'phone_number' => '0961234567',
                'full_name' => 'Vũ Thị Lan',
                'gender' => 'FEMALE',
                'role_code' => 'RESIDENT_OWNER',
            ],
            [
                'id' => (string) Str::uuid(),
                'username' => 'dangkiet',
                'email' => 'dangkiet@cassavas.vn',
                'phone_number' => '0931234567',
                'full_name' => 'Đặng Tuấn Kiệt',
                'gender' => 'MALE',
                'role_code' => 'RESIDENT_OWNER',
            ],
        ];

        $userMap = [];
        foreach ($users as $u) {
            $existing = DB::table('users')->where('email', $u['email'])->first();
            $userId = $existing ? $existing->id : $u['id'];
            $userMap[$u['username']] = $userId;

            DB::table('users')->updateOrInsert(
                ['email' => $u['email']],
                [
                    'id' => $userId,
                    'username' => $u['username'],
                    'phone_number' => $u['phone_number'],
                    'password_hash' => $defaultPassword,
                    'full_name' => $u['full_name'],
                    'gender' => $u['gender'],
                    'status' => 'ACTIVE',
                    'created_at' => $now->copy()->subMonths(6),
                    'updated_at' => $now,
                ]
            );

            if (isset($roleMap[$u['role_code']])) {
                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $userId, 'role_id' => $roleMap[$u['role_code']]],
                    ['assigned_at' => $now]
                );
            }
        }

        // 3. BLOCKS & BUILDINGS (Khu A: 120 căn, Khu B: 86 căn, Khu C: 64 căn -> Total: 270)
        $blocks = [
            [
                'block_code' => 'BLOCK_A',
                'block_name' => 'Khu A - Tòa Nhà Ruby',
                'total_floors' => 12,
                'total_basements' => 2,
                'total_apartments' => 120,
                'address_line' => 'Khối A, Khu phức hợp Smart Cassavas',
                'hotline_phone' => '024 3999 1111',
            ],
            [
                'block_code' => 'BLOCK_B',
                'block_name' => 'Khu B - Tháp Ruby',
                'total_floors' => 10,
                'total_basements' => 2,
                'total_apartments' => 86,
                'address_line' => 'Khối B, Khu phức hợp Smart Cassavas',
                'hotline_phone' => '024 3999 2222',
            ],
            [
                'block_code' => 'BLOCK_C',
                'block_name' => 'Khu C - Tháp Sapphire',
                'total_floors' => 8,
                'total_basements' => 1,
                'total_apartments' => 64,
                'address_line' => 'Khối C, Khu phức hợp Smart Cassavas',
                'hotline_phone' => '024 3999 3333',
            ],
        ];

        $blockMap = [];
        foreach ($blocks as $b) {
            $existing = DB::table('blocks')->where('block_code', $b['block_code'])->first();
            $blockId = $existing ? $existing->id : (string) Str::uuid();
            $blockMap[$b['block_code']] = $blockId;

            DB::table('blocks')->updateOrInsert(
                ['block_code' => $b['block_code']],
                [
                    'id' => $blockId,
                    'block_name' => $b['block_name'],
                    'total_floors' => $b['total_floors'],
                    'total_basements' => $b['total_basements'],
                    'total_apartments' => $b['total_apartments'],
                    'address_line' => $b['address_line'],
                    'hotline_phone' => $b['hotline_phone'],
                    'building_manager_user_id' => $userMap['quanly'] ?? null,
                    'ai_features_enabled' => 1,
                    'created_at' => $now->copy()->subYears(1),
                    'updated_at' => $now,
                ]
            );
        }

        // 4. FLOORS
        $floorMap = [];
        foreach ($blocks as $b) {
            $bId = $blockMap[$b['block_code']];
            for ($f = 1; $f <= $b['total_floors']; $f++) {
                $floorCode = sprintf('%s_F%02d', $b['block_code'], $f);
                $floorName = "Tầng {$f}";
                $existing = DB::table('floors')
                    ->where('block_id', $bId)
                    ->where('floor_number', $f)
                    ->first();
                $floorId = $existing ? $existing->id : (string) Str::uuid();
                $floorMap[$floorCode] = $floorId;

                DB::table('floors')->updateOrInsert(
                    ['block_id' => $bId, 'floor_number' => $f],
                    [
                        'id' => $floorId,
                        'floor_code' => $floorCode,
                        'floor_name' => $floorName,
                        'floor_type' => 'RESIDENTIAL',
                        'total_units' => 10,
                        'created_at' => $now->copy()->subYears(1),
                        'updated_at' => $now,
                    ]
                );
            }
        }

        // 5. APARTMENTS (270 căn hộ tổng cộng: Khu A 120, Khu B 86, Khu C 64)
        // Khu A: 118 có người ở (98%), 2 trống
        // Khu B: 78 có người ở (91%), 8 trống
        // Khu C: 47 có người ở (74%), 17 trống
        $apartmentsConfig = [
            'BLOCK_A' => ['total' => 120, 'occupied' => 118, 'prefix' => 'A'],
            'BLOCK_B' => ['total' => 86, 'occupied' => 78, 'prefix' => 'B'],
            'BLOCK_C' => ['total' => 64, 'occupied' => 47, 'prefix' => 'C'],
        ];

        $apartmentMap = [];
        $specialApts = [
            'A012' => ['resident' => 'nguyenvanan', 'block' => 'BLOCK_A', 'floor' => 1, 'rooms' => '2_BEDROOM', 'area' => 78.5],
            'A013' => ['resident' => 'tranmai', 'block' => 'BLOCK_A', 'floor' => 1, 'rooms' => '2_BEDROOM', 'area' => 72.0],
            'A014' => ['resident' => 'lehoangnam', 'block' => 'BLOCK_A', 'floor' => 1, 'rooms' => '3_BEDROOM', 'area' => 96.0],
            'B201' => ['resident' => 'phamduc', 'block' => 'BLOCK_B', 'floor' => 2, 'rooms' => '2_BEDROOM', 'area' => 82.0],
            'C505' => ['resident' => 'vulan', 'block' => 'BLOCK_C', 'floor' => 5, 'rooms' => '1_BEDROOM', 'area' => 54.0],
            'B1405' => ['resident' => 'dangkiet', 'block' => 'BLOCK_B', 'floor' => 10, 'rooms' => '3_BEDROOM', 'area' => 110.0],
        ];

        // Create special apartments explicitly first
        foreach ($specialApts as $sNum => $sData) {
            $bId = $blockMap[$sData['block']] ?? array_values($blockMap)[0];
            $floorCode = sprintf('%s_F%02d', $sData['block'], $sData['floor']);
            $floorId = $floorMap[$floorCode] ?? DB::table('floors')->where('block_id', $bId)->value('id');

            $existing = DB::table('apartments')
                ->where('block_id', $bId)
                ->where('apartment_number', $sNum)
                ->first();
            $aptId = $existing ? $existing->id : (string) Str::uuid();
            $apartmentMap[$sNum] = $aptId;
            $residentUserId = $userMap[$sData['resident']] ?? null;

            DB::table('apartments')->updateOrInsert(
                ['block_id' => $bId, 'apartment_number' => $sNum],
                [
                    'id' => $aptId,
                    'floor_id' => $floorId,
                    'room_type' => $sData['rooms'],
                    'gross_floor_area_sqm' => $sData['area'],
                    'net_usable_area_sqm' => $sData['area'] - 5.0,
                    'bedroom_count' => str_contains($sData['rooms'], '3') ? 3 : 2,
                    'bathroom_count' => 2,
                    'water_quota_registered' => 4,
                    'status' => 'OCCUPIED',
                    'current_resident_user_id' => $residentUserId,
                    'monthly_management_fee_fixed' => 1200000,
                    'has_balcony' => 1,
                    'furnished_status' => 'FULLY_FURNISHED',
                    'created_at' => $now->copy()->subYears(1),
                    'updated_at' => $now,
                ]
            );

            if ($residentUserId) {
                DB::table('residents')->updateOrInsert(
                    ['user_id' => $residentUserId, 'apartment_id' => $aptId],
                    [
                        'resident_type' => 'OWNER',
                        'is_head_of_household' => 1,
                        'stay_start_date' => $now->copy()->subMonths(10)->toDateString(),
                        'relationship_to_head' => 'SELF',
                        'vehicle_count' => 2,
                        'is_active' => 1,
                        'created_at' => $now->copy()->subMonths(10),
                        'updated_at' => $now,
                    ]
                );
            }
        }

        foreach ($apartmentsConfig as $bCode => $cfg) {
            $bId = $blockMap[$bCode];
            $occupiedCount = 0;

            for ($i = 1; $i <= $cfg['total']; $i++) {
                $aptNum = sprintf('%s%03d', $cfg['prefix'], $i);
                if (isset($apartmentMap[$aptNum])) {
                    continue;
                }

                $floorNum = min(ceil($i / 10), $blocks[array_search($bCode, array_column($blocks, 'block_code'))]['total_floors']);
                $floorCode = sprintf('%s_F%02d', $bCode, $floorNum);
                $floorId = $floorMap[$floorCode] ?? null;

                $isOccupied = ($occupiedCount < ($cfg['occupied'] - 2));
                if ($isOccupied) {
                    $occupiedCount++;
                }

                $status = $isOccupied ? 'OCCUPIED' : 'VACANT';

                $existing = DB::table('apartments')
                    ->where('block_id', $bId)
                    ->where('apartment_number', $aptNum)
                    ->first();
                $aptId = $existing ? $existing->id : (string) Str::uuid();
                $apartmentMap[$aptNum] = $aptId;

                DB::table('apartments')->updateOrInsert(
                    ['block_id' => $bId, 'apartment_number' => $aptNum],
                    [
                        'id' => $aptId,
                        'floor_id' => $floorId,
                        'room_type' => ($i % 3 === 0 ? '3_BEDROOM' : ($i % 2 === 0 ? '2_BEDROOM' : '1_BEDROOM')),
                        'gross_floor_area_sqm' => (65.0 + ($i % 4) * 15),
                        'net_usable_area_sqm' => (60.0 + ($i % 4) * 14),
                        'bedroom_count' => ($i % 3 === 0) ? 3 : 2,
                        'bathroom_count' => 2,
                        'water_quota_registered' => 4,
                        'status' => $status,
                        'current_resident_user_id' => null,
                        'monthly_management_fee_fixed' => 1200000,
                        'has_balcony' => 1,
                        'furnished_status' => 'FULLY_FURNISHED',
                        'created_at' => $now->copy()->subYears(1),
                        'updated_at' => $now,
                    ]
                );
            }
        }

        // 6. TICKET CATEGORIES & TICKETS
        $categories = [
            ['code' => 'PLUMBING', 'name' => 'Điện nước & Rò rỉ van', 'sla' => 24, 'team' => 'TECHNICAL_DEPARTMENT'],
            ['code' => 'SECURITY_CARD', 'name' => 'Thẻ cư dân & Thang máy', 'sla' => 48, 'team' => 'RECEPTION_DEPARTMENT'],
            ['code' => 'FACILITY_LIGHTING', 'name' => 'Hành lang & Chiếu sáng', 'sla' => 24, 'team' => 'TECHNICAL_DEPARTMENT'],
            ['code' => 'SMART_LOCK', 'name' => 'Khóa vân tay & Kiểm soát ra vào', 'sla' => 12, 'team' => 'TECHNICAL_DEPARTMENT'],
            ['code' => 'NOISE_COMPLAINT', 'name' => 'Tiếng ồn & Nội quy cư dân', 'sla' => 6, 'team' => 'SECURITY_DEPARTMENT'],
        ];

        $catMap = [];
        foreach ($categories as $cat) {
            $existing = DB::table('ticket_categories')->where('category_code', $cat['code'])->first();
            $catId = $existing ? $existing->id : (string) Str::uuid();
            $catMap[$cat['code']] = $catId;

            DB::table('ticket_categories')->updateOrInsert(
                ['category_code' => $cat['code']],
                [
                    'id' => $catId,
                    'category_name' => $cat['name'],
                    'default_priority' => 'MEDIUM',
                    'sla_response_time_minutes' => 30,
                    'sla_resolution_time_hours' => $cat['sla'],
                    'default_assigned_team' => $cat['team'],
                    'created_at' => $now->copy()->subMonths(6),
                ]
            );
        }

        // The 5 primary tickets displayed prominently on the dashboard
        $primaryTickets = [
            [
                'ticket_number' => 'TK-1082',
                'title' => 'Rò rỉ nước tại ban công',
                'cat' => 'PLUMBING',
                'apt' => 'A012',
                'user' => 'nguyenvanan',
                'priority' => 'HIGH',
                'status' => 'NEW',
                'desc' => 'Chủ hộ Nguyễn Văn An báo rò rỉ nước tại van tổng ban công. Cần thợ kỹ thuật nước kiểm tra trong sáng nay.',
                'created_at' => $now->copy()->subHours(2),
                'sla_hours' => 12,
            ],
            [
                'ticket_number' => 'TK-1081',
                'title' => 'Đăng ký thẻ cư dân mới',
                'cat' => 'SECURITY_CARD',
                'apt' => 'A013',
                'user' => 'tranmai',
                'priority' => 'MEDIUM',
                'status' => 'PROCESSING',
                'desc' => 'Cư dân căn hộ A013 nộp hồ sơ xin cấp thêm 2 thẻ từ ra vào thang máy và bãi đỗ xe.',
                'created_at' => $now->copy()->subHours(2),
                'sla_hours' => 24,
            ],
            [
                'ticket_number' => 'TK-1080',
                'title' => 'Đèn hành lang tầng 8',
                'cat' => 'FACILITY_LIGHTING',
                'apt' => 'A014',
                'user' => 'lehoangnam',
                'priority' => 'MEDIUM',
                'status' => 'PROCESSING',
                'desc' => 'Đèn led chiếu sáng hành lang tầng 8 trước cửa căn A014 chập chờn cần thay mới.',
                'created_at' => $now->copy()->subHours(2),
                'sla_hours' => 24,
            ],
            [
                'ticket_number' => 'TK-1079',
                'title' => 'Khóa vân tay sảnh chính tháp B',
                'cat' => 'SMART_LOCK',
                'apt' => 'B201',
                'user' => 'phamduc',
                'priority' => 'HIGH',
                'status' => 'RECEIVED',
                'desc' => 'Khóa vân tay cửa kính sảnh chính Tháp B không quét được vân tay vào giờ cao điểm.',
                'created_at' => $now->copy()->subHours(3),
                'sla_hours' => 8,
            ],
            [
                'ticket_number' => 'TK-1078',
                'title' => 'Khiếu nại tiếng ồn sau 22h',
                'cat' => 'NOISE_COMPLAINT',
                'apt' => 'C505',
                'user' => 'vulan',
                'priority' => 'LOW',
                'status' => 'DONE',
                'desc' => 'Khiếu nại tiếng ồn từ căn hộ tầng trên sau 22h. Đội bảo vệ đã trực tiếp lên kiểm tra và nhắc nhở.',
                'created_at' => $now->copy()->subDays(1),
                'sla_hours' => 6,
            ],
        ];

        $defaultAptId = DB::table('apartments')->value('id');
        $defaultUserId = DB::table('users')->value('id');
        $defaultCatId = DB::table('ticket_categories')->value('id');

        foreach ($primaryTickets as $pt) {
            $aptId = $apartmentMap[$pt['apt']] ?? $defaultAptId;
            $userId = $userMap[$pt['user']] ?? $defaultUserId;
            $catId = $catMap[$pt['cat']] ?? $defaultCatId;

            DB::table('tickets')->updateOrInsert(
                ['ticket_number' => $pt['ticket_number']],
                [
                    'category_id' => $catId,
                    'apartment_id' => $aptId,
                    'creator_user_id' => $userId,
                    'title' => $pt['title'],
                    'description' => $pt['desc'],
                    'priority' => $pt['priority'],
                    'status' => $pt['status'],
                    'current_technician_user_id' => $userMap['kythuat'] ?? null,
                    'sla_deadline' => $pt['created_at']->copy()->addHours($pt['sla_hours']),
                    'resolved_at' => $pt['status'] === 'DONE' ? $pt['created_at']->copy()->addHours(2) : null,
                    'created_at' => $pt['created_at'],
                    'updated_at' => $now,
                ]
            );
        }

        // Additional tickets to reach exact KPI: 24 active tickets in processing, 8 overdue
        // We already have 4 active primary tickets (TK-1082: NEW, TK-1081: PROCESSING, TK-1080: PROCESSING, TK-1079: RECEIVED)
        // So we add 20 more active tickets: 8 of them with expired SLA deadlines!
        for ($k = 1; $k <= 20; $k++) {
            $tNum = sprintf('TK-%04d', 1050 + $k);
            $isOverdue = ($k <= 8); // exactly 8 overdue tickets!
            $status = ($k % 3 === 0) ? 'RECEIVED' : (($k % 2 === 0) ? 'PROCESSING' : 'NEW');
            $createdAt = $isOverdue ? $now->copy()->subDays(3 + $k) : $now->copy()->subHours($k + 1);
            $slaDeadline = $isOverdue ? $now->copy()->subDays(1) : $now->copy()->addHours(12);

            $aptKey = array_keys($apartmentMap)[$k % count($apartmentMap)];
            $aptId = $apartmentMap[$aptKey];

            DB::table('tickets')->updateOrInsert(
                ['ticket_number' => $tNum],
                [
                    'category_id' => $catMap['PLUMBING'],
                    'apartment_id' => $aptId,
                    'creator_user_id' => $userMap['nguyenvanan'] ?? null,
                    'title' => "Yêu cầu kỹ thuật định kỳ số {$k} - Căn {$aptKey}",
                    'description' => $isOverdue ? 'Ticket quá hạn SLA cần trưởng bộ phận kỹ thuật can thiệp xử lý ngay.' : 'Đang trong quy trình tiếp nhận và phân công thợ.',
                    'priority' => $isOverdue ? 'HIGH' : 'MEDIUM',
                    'status' => $status,
                    'current_technician_user_id' => $userMap['kythuat'] ?? null,
                    'sla_deadline' => $slaDeadline,
                    'created_at' => $createdAt,
                    'updated_at' => $now,
                ]
            );
        }

        // 7. INVOICES & 6-MONTH REVENUE (T3 - T8 of 2026)
        // T3: Revenue 1.15 tỷ | Collected 1.08 tỷ | Debt 70 triệu | Target 1.10 tỷ
        // T4: Revenue 1.22 tỷ | Collected 1.19 tỷ | Debt 30 triệu | Target 1.20 tỷ
        // T5: Revenue 1.28 tỷ | Collected 1.21 tỷ | Debt 70 triệu | Target 1.25 tỷ
        // T6: Revenue 1.34 tỷ | Collected 1.29 tỷ | Debt 50 triệu | Target 1.30 tỷ
        // T7: Revenue 1.39 tỷ | Collected 1.32 tỷ | Debt 70 triệu | Target 1.35 tỷ
        // T8: Revenue 1.48 tỷ | Collected 1.42 tỷ | Debt 60 triệu | Target 1.40 tỷ
        // Total 6 months = 8.42 tỷ. Collection rate = 94.6%.
        // Total unpaid invoices = 86 (12.4% of total), including 8 overdue in August!
        $revenueMonths = [
            '2026-03' => ['name' => 'T3', 'rev' => 1150000000, 'col' => 1080000000, 'debt' => 70000000, 'unpaid_count' => 12],
            '2026-04' => ['name' => 'T4', 'rev' => 1220000000, 'col' => 1190000000, 'debt' => 30000000, 'unpaid_count' => 6],
            '2026-05' => ['name' => 'T5', 'rev' => 1280000000, 'col' => 1210000000, 'debt' => 70000000, 'unpaid_count' => 14],
            '2026-06' => ['name' => 'T6', 'rev' => 1340000000, 'col' => 1290000000, 'debt' => 50000000, 'unpaid_count' => 10],
            '2026-07' => ['name' => 'T7', 'rev' => 1390000000, 'col' => 1320000000, 'debt' => 70000000, 'unpaid_count' => 16],
            '2026-08' => ['name' => 'T8', 'rev' => 1480000000, 'col' => 1420000000, 'debt' => 60000000, 'unpaid_count' => 28], // Total unpaid = 86!
        ];

        $aptKeys = array_keys($apartmentMap);
        $totalApts = count($aptKeys);

        foreach ($revenueMonths as $period => $mCfg) {
            $periodDate = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
            $issueDate = $periodDate->copy()->addDays(2)->toDateString();
            $dueDate = $periodDate->copy()->addDays(20)->toDateString();

            // Distribute across apartments
            for ($idx = 0; $idx < 100; $idx++) {
                $aptCode = $aptKeys[$idx % $totalApts];
                $aptId = $apartmentMap[$aptCode];
                $invNumber = sprintf('INV-%s-%04d', str_replace('-', '', $period), $idx + 1);

                $isUnpaid = ($idx < $mCfg['unpaid_count']);
                $isAugustOverdue = ($period === '2026-08' && $idx < 8); // exactly 8 August overdue invoices!

                $amount = round($mCfg['rev'] / 100);
                $paidAmount = $isUnpaid ? 0 : $amount;
                $status = $isUnpaid ? ($isAugustOverdue ? 'OVERDUE' : 'ISSUED') : 'PAID';

                $existing = DB::table('invoices')->where('invoice_number', $invNumber)->first();
                $invId = $existing ? $existing->id : (string) Str::uuid();

                DB::table('invoices')->updateOrInsert(
                    ['invoice_number' => $invNumber],
                    [
                        'id' => $invId,
                        'apartment_id' => $aptId,
                        'resident_user_id' => $userMap['nguyenvanan'] ?? null,
                        'billing_period' => $period,
                        'issue_date' => $issueDate,
                        'due_date' => $dueDate,
                        'subtotal_amount' => round($amount * 0.9),
                        'tax_amount' => round($amount * 0.1),
                        'total_amount' => $amount,
                        'paid_amount' => $paidAmount,
                        'remaining_balance' => $amount - $paidAmount,
                        'status' => $status,
                        'notes' => "Hóa đơn dịch vụ tháng {$mCfg['name']}",
                        'created_at' => $periodDate->copy()->addDays(2),
                        'updated_at' => $now,
                    ]
                );

                // If paid, create payment record
                if ($paidAmount > 0) {
                    DB::table('payments')->updateOrInsert(
                        ['payment_reference_code' => "PAY-{$invNumber}"],
                        [
                            'invoice_id' => $invId,
                            'apartment_id' => $aptId,
                            'payer_user_id' => $userMap['nguyenvanan'] ?? null,
                            'amount_paid' => $paidAmount,
                            'payment_gateway' => 'VIETQR_BANK_TRANSFER',
                            'payment_status' => 'SUCCESS',
                            'payment_time' => $periodDate->copy()->addDays(15),
                            'created_at' => $periodDate->copy()->addDays(15),
                            'updated_at' => $periodDate->copy()->addDays(15),
                        ]
                    );
                }
            }
        }

        // 8. AMENITIES & TODAY'S BOOKINGS (32 bookings today, 8 free slots)
        $amenityCategory = [
            'id' => (string) Str::uuid(),
            'category_code' => 'COMMUNITY_FACILITIES',
            'category_name' => 'Tiện Ích Nội Khu Cao Cấp',
        ];
        DB::table('amenity_categories')->updateOrInsert(
            ['category_code' => $amenityCategory['category_code']],
            ['category_name' => $amenityCategory['category_name'], 'icon_name' => 'sparkles']
        );
        $amenityCatId = DB::table('amenity_categories')->where('category_code', 'COMMUNITY_FACILITIES')->value('id');

        $amenities = [
            ['code' => 'POOL_A', 'name' => 'Bể bơi vô cực tháp A', 'loc' => 'Tầng 4 Tháp A', 'cap' => 30],
            ['code' => 'TENNIS_1', 'name' => 'Sân tennis ngoài trời', 'loc' => 'Khu thể thao công viên', 'cap' => 4],
            ['code' => 'GYM_B', 'name' => 'Phòng Gym & Yoga tháp B', 'loc' => 'Tầng 3 Tháp B', 'cap' => 25],
            ['code' => 'BBQ_ROOF', 'name' => 'Khu nướng BBQ sân thượng', 'loc' => 'Rooftop Tháp A', 'cap' => 15],
            ['code' => 'COMMUNITY_C', 'name' => 'Phòng sinh hoạt cộng đồng tháp C', 'loc' => 'Tầng 1 Tháp C', 'cap' => 50],
        ];

        $amenityIds = [];
        foreach ($amenities as $am) {
            $existing = DB::table('amenities')->where('amenity_code', $am['code'])->first();
            $amId = $existing ? $existing->id : (string) Str::uuid();
            $amenityIds[] = $amId;

            DB::table('amenities')->updateOrInsert(
                ['amenity_code' => $am['code']],
                [
                    'id' => $amId,
                    'category_id' => $amenityCatId,
                    'amenity_name' => $am['name'],
                    'location_detail' => $am['loc'],
                    'max_capacity_per_slot' => $am['cap'],
                    'hourly_rate' => 0,
                    'is_active' => 1,
                    'created_at' => $now->copy()->subYears(1),
                ]
            );
        }

        // Generate exactly 32 bookings for today (2026-09-15)
        for ($bIdx = 1; $bIdx <= 32; $bIdx++) {
            $bCode = sprintf('BKG-%s-%03d', $now->format('Ymd'), $bIdx);
            $amId = $amenityIds[$bIdx % count($amenityIds)];
            $aptCode = $aptKeys[$bIdx % $totalApts];
            $hour = 6 + ($bIdx % 14);

            DB::table('amenity_bookings')->updateOrInsert(
                ['booking_code' => $bCode],
                [
                    'amenity_id' => $amId,
                    'apartment_id' => $apartmentMap[$aptCode],
                    'resident_user_id' => $userMap['nguyenvanan'] ?? null,
                    'booking_date' => $now->toDateString(),
                    'start_time' => sprintf('%02d:00:00', $hour),
                    'end_time' => sprintf('%02d:00:00', $hour + 1),
                    'attendee_count' => 2,
                    'total_amount' => 0,
                    'is_paid' => 1,
                    'status' => 'CONFIRMED',
                    'checkin_qr_code' => "QR_{$bCode}",
                    'created_at' => $now->copy()->subDays(1),
                ]
            );
        }

        // 9. BUILDING ASSETS & MAINTENANCE LOGS
        // - Thang máy Otis Tòa A: 4/4 buồng đạt chuẩn
        // - Hệ thống PCCC Khu B: 2.5 bar, bình thường
        $assets = [
            [
                'code' => 'ELEVATOR_A_OTIS',
                'name' => 'Hệ thống Thang máy Otis Tòa A (4 Buồng)',
                'category' => 'ELEVATOR',
                'block' => 'BLOCK_A',
                'status' => 'OPERATIONAL',
            ],
            [
                'code' => 'FIRE_SAFETY_B',
                'name' => 'Hệ thống Cảnh Báo & Áp Lực PCCC Khu B',
                'category' => 'FIRE_PROTECTION',
                'block' => 'BLOCK_B',
                'status' => 'OPERATIONAL',
            ],
        ];

        foreach ($assets as $ast) {
            $existing = DB::table('building_assets')->where('asset_code', $ast['code'])->first();
            $assetId = $existing ? $existing->id : (string) Str::uuid();

            DB::table('building_assets')->updateOrInsert(
                ['asset_code' => $ast['code']],
                [
                    'id' => $assetId,
                    'asset_name' => $ast['name'],
                    'category' => $ast['category'],
                    'block_id' => $blockMap[$ast['block']] ?? null,
                    'manufacturer' => $ast['category'] === 'ELEVATOR' ? 'Otis Elevator Company' : 'Tyco Fire Protection',
                    'maintenance_interval_days' => 30,
                    'last_maintenance_date' => $now->copy()->subDays(1)->toDateString(),
                    'next_maintenance_due_date' => $now->copy()->addDays(29)->toDateString(),
                    'current_status' => $ast['status'],
                    'created_at' => $now->copy()->subYears(1),
                ]
            );

            // Add maintenance log
            DB::table('asset_maintenance_logs')->updateOrInsert(
                [
                    'asset_id' => $assetId,
                    'maintenance_date' => $now->copy()->subDays(1)->toDateString(),
                ],
                [
                    'maintenance_type' => 'PREVENTIVE',
                    'performed_by_vendor' => $ast['category'] === 'ELEVATOR' ? 'Otis Vietnam' : 'PCCC Hà Nội',
                    'work_summary' => $ast['category'] === 'ELEVATOR'
                        ? 'Hoàn thành kiểm định đạt chuẩn 4/4 buồng thang máy'
                        : 'Kiểm tra áp lực ống dẫn đạt 2.5 bar, hoạt động ổn định',
                    'created_at' => $now->copy()->subHours(5),
                ]
            );
        }

        // 10. NOTIFICATIONS (5 notifications matching bell popup)
        $notifications = [
            [
                'title' => 'Sự cố nước ban công',
                'msg' => 'Căn hộ A012 yêu cầu kỹ thuật kiểm tra van tổng.',
                'cat' => 'TICKET',
                'created_at' => $now->copy()->subMinutes(10),
            ],
            [
                'title' => 'Hóa đơn quá hạn',
                'msg' => '8 hóa đơn dịch vụ tháng 8 chưa hoàn tất thanh toán.',
                'cat' => 'BILLING',
                'created_at' => $now->copy()->subMinutes(45),
            ],
            [
                'title' => 'Đồng bộ chỉ số',
                'msg' => 'Đã đồng bộ 270 chỉ số công tơ điện nước thông minh.',
                'cat' => 'IOT',
                'created_at' => $now->copy()->subHours(2),
            ],
            [
                'title' => 'Lịch bảo trì thang máy',
                'msg' => 'Đội kỹ thuật Otis tiến hành kiểm định thang T2 Tháp A.',
                'cat' => 'MAINTENANCE',
                'created_at' => $now->copy()->subHours(3),
            ],
            [
                'title' => 'Cư dân đăng ký mới',
                'msg' => 'Hộ gia đình căn B1405 hoàn tất xác thực eKYC.',
                'cat' => 'EKYC',
                'created_at' => $now->copy()->subHours(5),
            ],
        ];

        foreach ($notifications as $n) {
            DB::table('user_in_app_notifications')->updateOrInsert(
                ['title' => $n['title']],
                [
                    'recipient_user_id' => $userMap['admin'] ?? null,
                    'body_message' => $n['msg'],
                    'category' => $n['cat'],
                    'is_read' => 0,
                    'created_at' => $n['created_at'],
                ]
            );
        }

        // 11. METERS (270 smart meters matching 99.2% synced)
        foreach ($apartmentMap as $aptNum => $aptId) {
            $meterCode = "MTR_{$aptNum}_ELE";
            $existing = DB::table('meters')->where('meter_code', $meterCode)->first();
            $meterId = $existing ? $existing->id : (string) Str::uuid();

            DB::table('meters')->updateOrInsert(
                ['meter_code' => $meterCode],
                [
                    'id' => $meterId,
                    'meter_type' => 'ELECTRICITY',
                    'apartment_id' => $aptId,
                    'installation_date' => $now->copy()->subYears(1)->toDateString(),
                    'initial_reading' => 0,
                    'current_reading' => 1450.5,
                    'last_reading_date' => $now->copy()->subDays(2)->toDateString(),
                    'is_active' => 1,
                    'created_at' => $now->copy()->subYears(1),
                ]
            );
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }

        $this->command->info('✅ Nạp dữ liệu ảo cho Trang Quản Trị thành công 100%!');
    }
}
