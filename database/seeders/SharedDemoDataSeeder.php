<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SharedDemoDataSeeder extends Seeder
{
    /**
     * Nạp toàn bộ dữ liệu mẫu ổn định và dùng chung cho cả nhóm.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('❌ Cảnh báo an toàn: Không được phép nạp dữ liệu ảo trên môi trường PRODUCTION!');

            return;
        }

        $this->command->info('🚀 Bắt đầu khởi tạo bộ dữ liệu ảo dùng chung (SharedDemoDataSeeder)...');

        // Cố định seed cho Faker để tất cả thành viên trong nhóm nhận cùng một bộ dữ liệu
        if (function_exists('fake')) {
            fake()->seed(20260924);
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        DB::transaction(function () {
            $baseTime = Carbon::create(2026, 9, 24, 8, 0, 0);

            // =========================================================================
            // 1. ROLES & PERMISSIONS
            // =========================================================================
            $this->command->info('1/12. Đồng bộ Roles và Permissions...');

            $roles = [
                ['role_code' => 'SUPER_ADMIN', 'role_name' => 'Quản Trị Viên Cấp Cao', 'description' => 'Toàn quyền kiểm soát hệ thống.'],
                ['role_code' => 'BUILDING_MANAGER', 'role_name' => 'Ban Quản Lý Tòa Nhà', 'description' => 'Quản lý vận hành toàn diện tòa nhà.'],
                ['role_code' => 'ACCOUNTANT', 'role_name' => 'Kế Toán Tòa Nhà', 'description' => 'Quản lý tài chính, hóa đơn và thu chi.'],
                ['role_code' => 'RECEPTIONIST', 'role_name' => 'Nhân Viên Lễ Tân', 'description' => 'Tiếp đón cư dân, khách viếng thăm và tiếp nhận bưu phẩm.'],
                ['role_code' => 'SECURITY_GUARD', 'role_name' => 'Nhân Viên Bảo Vệ', 'description' => 'Kiểm soát an ninh, ra vào và tuần tra.'],
                ['role_code' => 'TECHNICIAN', 'role_name' => 'Nhân Viên Kỹ Thuật', 'description' => 'Bảo trì trang thiết bị và xử lý sự cố kỹ thuật.'],
                ['role_code' => 'RESIDENT_OWNER', 'role_name' => 'Cư Dân Chủ Hộ', 'description' => 'Chủ sở hữu căn hộ, sử dụng dịch vụ và thanh toán phí.'],
                ['role_code' => 'RESIDENT_MEMBER', 'role_name' => 'Cư Dân Thành Viên', 'description' => 'Thành viên cùng sinh sống trong căn hộ.'],
            ];

            $roleIdMap = [];
            foreach ($roles as $r) {
                $existing = DB::table('roles')->where('role_code', $r['role_code'])->first();
                $roleId = $existing ? $existing->id : (string) Str::uuid();
                $roleIdMap[$r['role_code']] = $roleId;

                DB::table('roles')->updateOrInsert(
                    ['role_code' => $r['role_code']],
                    [
                        'id' => $roleId,
                        'role_name' => $r['role_name'],
                        'description' => $r['description'],
                        'is_system_role' => 1,
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );
            }

            $permissions = [
                ['USER:VIEW', 'USER', 'Xem danh sách người dùng'],
                ['USER:CREATE', 'USER', 'Tạo tài khoản người dùng'],
                ['USER:UPDATE', 'USER', 'Cập nhật tài khoản người dùng'],
                ['USER:DELETE', 'USER', 'Xóa tài khoản người dùng'],
                ['ROLE:VIEW', 'ROLE', 'Xem danh sách vai trò'],
                ['ROLE:MANAGE', 'ROLE', 'Quản lý vai trò và phân quyền'],
                ['BUILDING:VIEW', 'BUILDING', 'Xem thông tin tòa nhà và tầng'],
                ['BUILDING:MANAGE', 'BUILDING', 'Quản lý thông tin tòa nhà'],
                ['APARTMENT:VIEW', 'APARTMENT', 'Xem danh sách căn hộ'],
                ['APARTMENT:MANAGE', 'APARTMENT', 'Quản lý căn hộ và cư trú'],
                ['RESIDENT:VIEW', 'RESIDENT', 'Xem hồ sơ cư dân'],
                ['RESIDENT:MANAGE', 'RESIDENT', 'Quản lý hồ sơ cư dân'],
                ['INVOICE:VIEW', 'INVOICE', 'Xem danh sách hóa đơn'],
                ['INVOICE:CREATE', 'INVOICE', 'Tạo hóa đơn thu phí'],
                ['INVOICE:UPDATE', 'INVOICE', 'Cập nhật hóa đơn'],
                ['INVOICE:APPROVE', 'INVOICE', 'Duyệt và phát hành hóa đơn'],
                ['TICKET:VIEW', 'TICKET', 'Xem danh sách yêu cầu hỗ trợ'],
                ['TICKET:CREATE', 'TICKET', 'Tạo yêu cầu hỗ trợ'],
                ['TICKET:UPDATE', 'TICKET', 'Cập nhật trạng thái yêu cầu'],
                ['TICKET:ASSIGN', 'TICKET', 'Phân công kỹ thuật viên xử lý'],
                ['TICKET:CLOSE', 'TICKET', 'Đóng và đánh giá yêu cầu'],
                ['AMENITY:VIEW', 'AMENITY', 'Xem danh sách tiện ích tòa nhà'],
                ['AMENITY:MANAGE', 'AMENITY', 'Quản lý tiện ích tòa nhà'],
                ['AMENITY:BOOK', 'AMENITY', 'Đặt lịch sử dụng tiện ích'],
                ['VISITOR:VIEW', 'VISITOR', 'Xem danh sách khách viếng thăm'],
                ['VISITOR:CREATE', 'VISITOR', 'Đăng ký khách viếng thăm'],
                ['VISITOR:APPROVE', 'VISITOR', 'Phê duyệt khách viếng thăm'],
                ['REPORT:VIEW', 'REPORT', 'Xem báo cáo thống kê vận hành'],
                ['NOTIFICATION:VIEW', 'NOTIFICATION', 'Xem và gửi thông báo tòa nhà'],
            ];

            $permIdMap = [];
            foreach ($permissions as [$code, $module, $name]) {
                $existing = DB::table('permissions')->where('permission_code', $code)->first();
                $permId = $existing ? $existing->id : (string) Str::uuid();
                $permIdMap[$code] = $permId;

                DB::table('permissions')->updateOrInsert(
                    ['permission_code' => $code],
                    [
                        'id' => $permId,
                        'module' => $module,
                        'permission_name' => $name,
                        'description' => $name,
                        'created_at' => $baseTime,
                    ]
                );
            }

            // Gán quyền cho từng Role
            $rolePermConfig = [
                'SUPER_ADMIN' => array_keys($permIdMap),
                'BUILDING_MANAGER' => [
                    'BUILDING:VIEW', 'BUILDING:MANAGE', 'APARTMENT:VIEW', 'APARTMENT:MANAGE',
                    'RESIDENT:VIEW', 'RESIDENT:MANAGE', 'INVOICE:VIEW', 'TICKET:VIEW', 'TICKET:UPDATE',
                    'TICKET:ASSIGN', 'TICKET:CLOSE', 'AMENITY:VIEW', 'AMENITY:MANAGE', 'AMENITY:BOOK',
                    'VISITOR:VIEW', 'VISITOR:APPROVE', 'REPORT:VIEW', 'NOTIFICATION:VIEW',
                ],
                'ACCOUNTANT' => [
                    'INVOICE:VIEW', 'INVOICE:CREATE', 'INVOICE:UPDATE', 'INVOICE:APPROVE', 'REPORT:VIEW',
                ],
                'RECEPTIONIST' => [
                    'VISITOR:VIEW', 'VISITOR:CREATE', 'VISITOR:APPROVE', 'RESIDENT:VIEW',
                    'TICKET:VIEW', 'TICKET:CREATE', 'NOTIFICATION:VIEW',
                ],
                'SECURITY_GUARD' => [
                    'VISITOR:VIEW', 'VISITOR:APPROVE', 'TICKET:VIEW', 'TICKET:CREATE',
                ],
                'RESIDENT_OWNER' => [
                    'APARTMENT:VIEW', 'INVOICE:VIEW', 'TICKET:VIEW', 'TICKET:CREATE',
                    'AMENITY:VIEW', 'AMENITY:BOOK', 'VISITOR:VIEW', 'VISITOR:CREATE',
                ],
                'RESIDENT_MEMBER' => [
                    'APARTMENT:VIEW', 'TICKET:VIEW', 'TICKET:CREATE', 'AMENITY:VIEW',
                    'AMENITY:BOOK', 'VISITOR:VIEW', 'VISITOR:CREATE',
                ],
            ];

            foreach ($rolePermConfig as $rCode => $pCodes) {
                $rId = $roleIdMap[$rCode] ?? null;
                if (! $rId) {
                    continue;
                }
                foreach ($pCodes as $pCode) {
                    $pId = $permIdMap[$pCode] ?? null;
                    if (! $pId) {
                        continue;
                    }
                    $exists = DB::table('role_permissions')->where('role_id', $rId)->where('permission_id', $pId)->exists();
                    if (! $exists) {
                        DB::table('role_permissions')->insert([
                            'id' => (string) Str::uuid(),
                            'role_id' => $rId,
                            'permission_id' => $pId,
                            'created_at' => $baseTime,
                        ]);
                    }
                }
            }

            // =========================================================================
            // 2. TÀI KHOẢN DEMO CỐ ĐỊNH & USER ROLES
            // =========================================================================
            $this->command->info('2/12. Khởi tạo danh sách tài khoản demo cố định...');

            $demoUsers = [
                [
                    'username' => 'admin',
                    'email' => 'admin@demo.local',
                    'phone_number' => '0900000001',
                    'full_name' => 'Quản Trị Viên Hệ Thống',
                    'password' => 'Admin@123456',
                    'role' => 'SUPER_ADMIN',
                    'gender' => 'MALE',
                ],
                [
                    'username' => 'manager',
                    'email' => 'manager@demo.local',
                    'phone_number' => '0900000002',
                    'full_name' => 'Ban Quản Lý Tòa Nhà',
                    'password' => 'Manager@123456',
                    'role' => 'BUILDING_MANAGER',
                    'gender' => 'FEMALE',
                ],
                [
                    'username' => 'accountant',
                    'email' => 'accountant@demo.local',
                    'phone_number' => '0900000003',
                    'full_name' => 'Kế Toán Viên Tòa Nhà',
                    'password' => 'Accountant@123456',
                    'role' => 'ACCOUNTANT',
                    'gender' => 'FEMALE',
                ],
                [
                    'username' => 'receptionist',
                    'email' => 'receptionist@demo.local',
                    'phone_number' => '0900000004',
                    'full_name' => 'Nhân Viên Lễ Tân Sảnh',
                    'password' => 'Receptionist@123456',
                    'role' => 'RECEPTIONIST',
                    'gender' => 'FEMALE',
                ],
                [
                    'username' => 'security',
                    'email' => 'security@demo.local',
                    'phone_number' => '0900000005',
                    'full_name' => 'Đội Trưởng Bảo Vệ',
                    'password' => 'Security@123456',
                    'role' => 'SECURITY_GUARD',
                    'gender' => 'MALE',
                ],
                [
                    'username' => 'resident.owner',
                    'email' => 'resident.owner@demo.local',
                    'phone_number' => '0900000010',
                    'full_name' => 'Nguyễn Văn An (Chủ hộ)',
                    'password' => 'Resident@123456',
                    'role' => 'RESIDENT_OWNER',
                    'gender' => 'MALE',
                ],
                [
                    'username' => 'resident.member',
                    'email' => 'resident.member@demo.local',
                    'phone_number' => '0900000011',
                    'full_name' => 'Nguyễn Thị Mai (Thành viên)',
                    'password' => 'Resident@123456',
                    'role' => 'RESIDENT_MEMBER',
                    'gender' => 'FEMALE',
                ],
            ];

            $userIdMap = [];
            foreach ($demoUsers as $du) {
                $existing = DB::table('users')
                    ->where('email', $du['email'])
                    ->orWhere('username', $du['username'])
                    ->orWhere('phone_number', $du['phone_number'])
                    ->first();

                if ($existing) {
                    $uId = $existing->id;
                    DB::table('users')->where('id', $uId)->update([
                        'username' => $du['username'],
                        'email' => $du['email'],
                        'phone_number' => $du['phone_number'],
                        'password_hash' => Hash::make($du['password']),
                        'full_name' => $du['full_name'],
                        'gender' => $du['gender'],
                        'status' => 'ACTIVE',
                        'updated_at' => $baseTime,
                    ]);
                } else {
                    $uId = (string) Str::uuid();
                    DB::table('users')->insert([
                        'id' => $uId,
                        'username' => $du['username'],
                        'email' => $du['email'],
                        'phone_number' => $du['phone_number'],
                        'password_hash' => Hash::make($du['password']),
                        'full_name' => $du['full_name'],
                        'gender' => $du['gender'],
                        'status' => 'ACTIVE',
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]);
                }
                $userIdMap[$du['username']] = $uId;

                $rId = $roleIdMap[$du['role']] ?? null;
                if ($rId) {
                    DB::table('user_roles')->updateOrInsert(
                        ['user_id' => $uId, 'role_id' => $rId],
                        [
                            'id' => (string) Str::uuid(),
                            'is_primary' => 1,
                            'assigned_at' => $baseTime,
                        ]
                    );
                }
            }

            // =========================================================================
            // 3. BLOCKS, FLOORS & APARTMENTS (2 Blocks, 5 Tầng/Block, 8 Căn/Tầng = 80 Căn)
            // =========================================================================
            $this->command->info('3/12. Khởi tạo 2 Tòa nhà (Block A, Block B) với 80 căn hộ chuẩn...');

            $blockConfigs = [
                [
                    'code' => 'BLOCK_A',
                    'name' => 'Tòa Tháp Ruby Tower (Block A)',
                    'floors' => 5,
                    'apts_per_floor' => 8,
                ],
                [
                    'code' => 'BLOCK_B',
                    'name' => 'Tòa Tháp Sapphire Tower (Block B)',
                    'floors' => 5,
                    'apts_per_floor' => 8,
                ],
            ];

            $allApartmentIds = [];
            $apartmentDetails = [];

            foreach ($blockConfigs as $bc) {
                $existingBlock = DB::table('blocks')->where('block_code', $bc['code'])->first();
                $bId = $existingBlock ? $existingBlock->id : (string) Str::uuid();

                DB::table('blocks')->updateOrInsert(
                    ['block_code' => $bc['code']],
                    [
                        'id' => $bId,
                        'block_name' => $bc['name'],
                        'total_floors' => $bc['floors'],
                        'total_basements' => 2,
                        'total_apartments' => $bc['floors'] * $bc['apts_per_floor'],
                        'address_line' => 'Số 123 Đường Công Nghệ, Phường Đô Thị Mới',
                        'hotline_phone' => '024 3999 8888',
                        'building_manager_user_id' => $userIdMap['manager'],
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );

                for ($f = 1; $f <= $bc['floors']; $f++) {
                    $floorNumber = $f;
                    $floorName = "Tầng {$f}";
                    $floorCode = sprintf('%s_F%02d', $bc['code'], $floorNumber);

                    $existingFloor = DB::table('floors')
                        ->where('block_id', $bId)
                        ->where('floor_number', $floorNumber)
                        ->first();
                    $flId = $existingFloor ? $existingFloor->id : (string) Str::uuid();

                    DB::table('floors')->updateOrInsert(
                        ['block_id' => $bId, 'floor_number' => $floorNumber],
                        [
                            'id' => $flId,
                            'floor_code' => $floorCode,
                            'floor_name' => $floorName,
                            'total_units' => $bc['apts_per_floor'],
                            'created_at' => $baseTime,
                            'updated_at' => $baseTime,
                        ]
                    );

                    for ($a = 1; $a <= $bc['apts_per_floor']; $a++) {
                        $prefix = ($bc['code'] === 'BLOCK_A') ? 'A' : 'B';
                        $aptNumber = sprintf('%s%d-%02d', $prefix, $f, $a);

                        // Phân bổ trạng thái căn hộ hợp lý: OCCUPIED, VACANT, MAINTENANCE, RESERVED
                        $status = 'OCCUPIED';
                        if ($a === 7) {
                            $status = 'VACANT';
                        } elseif ($a === 8 && $f === 5) {
                            $status = 'MAINTENANCE';
                        } elseif ($a === 8) {
                            $status = 'RESERVED';
                        }

                        $existingApt = DB::table('apartments')->where('apartment_number', $aptNumber)->first();
                        $aptId = $existingApt ? $existingApt->id : (string) Str::uuid();
                        $allApartmentIds[] = $aptId;

                        $roomType = ($a % 3 === 0) ? '3_BEDROOM' : (($a % 2 === 0) ? '2_BEDROOM' : '1_BEDROOM');
                        $bedroomCount = ($roomType === '3_BEDROOM') ? 3 : (($roomType === '2_BEDROOM') ? 2 : 1);
                        $bathroomCount = ($bedroomCount >= 2) ? 2 : 1;
                        $grossArea = ($bedroomCount === 3) ? 105.0 : (($bedroomCount === 2) ? 75.0 : 50.0);
                        $netArea = round($grossArea * 0.92, 1);

                        DB::table('apartments')->updateOrInsert(
                            ['apartment_number' => $aptNumber],
                            [
                                'id' => $aptId,
                                'block_id' => $bId,
                                'floor_id' => $flId,
                                'room_type' => $roomType,
                                'gross_floor_area_sqm' => $grossArea,
                                'net_usable_area_sqm' => $netArea,
                                'bedroom_count' => $bedroomCount,
                                'bathroom_count' => $bathroomCount,
                                'status' => $status,
                                'monthly_management_fee_fixed' => $grossArea * 12000,
                                'created_at' => $baseTime,
                                'updated_at' => $baseTime,
                            ]
                        );

                        $apartmentDetails[$aptNumber] = [
                            'id' => $aptId,
                            'number' => $aptNumber,
                            'status' => $status,
                            'block_id' => $bId,
                        ];
                    }
                }
            }

            // =========================================================================
            // 4. RESIDENTS (Tối thiểu 20 cư dân: 10 chủ hộ + 10 thành viên)
            // =========================================================================
            $this->command->info('4/12. Khởi tạo 20+ cư dân (chủ hộ, thành viên hộ gia đình)...');

            // Gán căn hộ A1-01 cho tài khoản demo resident.owner và resident.member
            $primaryAptId = $apartmentDetails['A1-01']['id'] ?? $allApartmentIds[0];

            DB::table('apartments')->where('id', $primaryAptId)->update([
                'current_resident_user_id' => $userIdMap['resident.owner'],
            ]);

            // Cư dân chủ hộ demo
            DB::table('residents')->updateOrInsert(
                ['user_id' => $userIdMap['resident.owner'], 'apartment_id' => $primaryAptId],
                [
                    'id' => (string) Str::uuid(),
                    'resident_type' => 'OWNER',
                    'is_head_of_household' => 1,
                    'stay_start_date' => '2024-01-01',
                    'is_active' => 1,
                    'created_at' => $baseTime,
                    'updated_at' => $baseTime,
                ]
            );

            // Cư dân thành viên demo (cùng hộ A1-01)
            DB::table('residents')->updateOrInsert(
                ['user_id' => $userIdMap['resident.member'], 'apartment_id' => $primaryAptId],
                [
                    'id' => (string) Str::uuid(),
                    'resident_type' => 'FAMILY_MEMBER',
                    'is_head_of_household' => 0,
                    'relationship_to_head' => 'Vợ/Chồng',
                    'stay_start_date' => '2024-01-01',
                    'is_active' => 1,
                    'created_at' => $baseTime,
                    'updated_at' => $baseTime,
                ]
            );

            // Tạo thêm 9 chủ hộ và 9 thành viên khác để đạt tối thiểu 20 cư dân
            $residentUsersList = [
                $userIdMap['resident.owner'],
                $userIdMap['resident.member'],
            ];

            $vietnameseNames = [
                ['Lê Minh Trí', 'MALE'],
                ['Phạm Quỳnh Nga', 'FEMALE'],
                ['Hoàng Gia Huy', 'MALE'],
                ['Vũ Phương Thảo', 'FEMALE'],
                ['Đặng Tuấn Kiệt', 'MALE'],
                ['Bùi Thu Trang', 'FEMALE'],
                ['Đỗ Mạnh Hùng', 'MALE'],
                ['Ngô Bảo Châu', 'FEMALE'],
                ['Dương Văn Đức', 'MALE'],
            ];

            $occupiedApts = array_values(array_filter($apartmentDetails, fn ($a) => $a['status'] === 'OCCUPIED' && $a['number'] !== 'A1-01'));

            foreach ($vietnameseNames as $idx => [$name, $gender]) {
                $apt = $occupiedApts[$idx] ?? null;
                if (! $apt) {
                    continue;
                }

                $ownerUsername = 'owner_'.($idx + 2);
                $ownerEmail = "owner_{$idx}@demo.local";
                $ownerPhone = sprintf('09001000%02d', $idx + 2);

                $existingOwner = DB::table('users')->where('email', $ownerEmail)->first();
                $ownerId = $existingOwner ? $existingOwner->id : (string) Str::uuid();

                DB::table('users')->updateOrInsert(
                    ['email' => $ownerEmail],
                    [
                        'id' => $ownerId,
                        'username' => $ownerUsername,
                        'phone_number' => $ownerPhone,
                        'password_hash' => Hash::make('Resident@123456'),
                        'full_name' => $name,
                        'gender' => $gender,
                        'status' => 'ACTIVE',
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );

                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $ownerId, 'role_id' => $roleIdMap['RESIDENT_OWNER']],
                    ['id' => (string) Str::uuid(), 'is_primary' => 1, 'assigned_at' => $baseTime]
                );

                DB::table('apartments')->where('id', $apt['id'])->update([
                    'current_resident_user_id' => $ownerId,
                ]);

                DB::table('residents')->updateOrInsert(
                    ['user_id' => $ownerId, 'apartment_id' => $apt['id']],
                    [
                        'id' => (string) Str::uuid(),
                        'resident_type' => 'OWNER',
                        'is_head_of_household' => 1,
                        'stay_start_date' => '2024-06-01',
                        'is_active' => 1,
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );

                $residentUsersList[] = $ownerId;

                // Thêm 1 thành viên gia đình cho căn hộ này
                $memUsername = 'member_'.($idx + 2);
                $memEmail = "member_{$idx}@demo.local";
                $memPhone = sprintf('09002000%02d', $idx + 2);
                $memGender = ($gender === 'MALE') ? 'FEMALE' : 'MALE';
                $memName = ($memGender === 'FEMALE') ? "Trần Thị {$idx}" : "Nguyễn Văn {$idx}";

                $existingMem = DB::table('users')->where('email', $memEmail)->first();
                $memId = $existingMem ? $existingMem->id : (string) Str::uuid();

                // Tạo 1 cư dân trạng thái INACTIVE để kiểm thử filter
                $userStatus = ($idx === 8) ? 'INACTIVE' : 'ACTIVE';
                $residentActive = ($idx === 8) ? 0 : 1;

                DB::table('users')->updateOrInsert(
                    ['email' => $memEmail],
                    [
                        'id' => $memId,
                        'username' => $memUsername,
                        'phone_number' => $memPhone,
                        'password_hash' => Hash::make('Resident@123456'),
                        'full_name' => $memName,
                        'gender' => $memGender,
                        'status' => $userStatus,
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );

                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $memId, 'role_id' => $roleIdMap['RESIDENT_MEMBER']],
                    ['id' => (string) Str::uuid(), 'is_primary' => 1, 'assigned_at' => $baseTime]
                );

                DB::table('residents')->updateOrInsert(
                    ['user_id' => $memId, 'apartment_id' => $apt['id']],
                    [
                        'id' => (string) Str::uuid(),
                        'resident_type' => 'FAMILY_MEMBER',
                        'is_head_of_household' => 0,
                        'relationship_to_head' => 'Thành viên gia đình',
                        'stay_start_date' => '2024-06-01',
                        'is_active' => $residentActive,
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );

                $residentUsersList[] = $memId;
            }

            // Hộ A1-01 thêm thành viên thứ 3 để đạt yêu cầu "Ít nhất 1 hộ có 3 thành viên"
            $childEmail = 'child@demo.local';
            $existingChild = DB::table('users')->where('email', $childEmail)->first();
            $childId = $existingChild ? $existingChild->id : (string) Str::uuid();

            DB::table('users')->updateOrInsert(
                ['email' => $childEmail],
                [
                    'id' => $childId,
                    'username' => 'resident.child',
                    'phone_number' => '0900000012',
                    'password_hash' => Hash::make('Resident@123456'),
                    'full_name' => 'Nguyễn Minh Quân (Con)',
                    'gender' => 'MALE',
                    'status' => 'ACTIVE',
                    'created_at' => $baseTime,
                    'updated_at' => $baseTime,
                ]
            );

            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $childId, 'role_id' => $roleIdMap['RESIDENT_MEMBER']],
                ['id' => (string) Str::uuid(), 'is_primary' => 1, 'assigned_at' => $baseTime]
            );

            DB::table('residents')->updateOrInsert(
                ['user_id' => $childId, 'apartment_id' => $primaryAptId],
                [
                    'id' => (string) Str::uuid(),
                    'resident_type' => 'FAMILY_MEMBER',
                    'is_head_of_household' => 0,
                    'relationship_to_head' => 'Con',
                    'stay_start_date' => '2024-01-01',
                    'is_active' => 1,
                    'created_at' => $baseTime,
                    'updated_at' => $baseTime,
                ]
            );

            $residentUsersList[] = $childId;

            // =========================================================================
            // 5. INVOICES & INVOICE ITEMS (Tối thiểu 40 hóa đơn đa dạng trạng thái)
            // =========================================================================
            $this->command->info('5/12. Khởi tạo 40+ Hóa đơn phí dịch vụ đầy đủ trạng thái...');

            $invoiceStatuses = ['PAID', 'ISSUED', 'OVERDUE', 'PARTIALLY_PAID', 'DRAFT', 'CANCELLED'];
            $sampleApartments = array_slice($allApartmentIds, 0, 40);

            foreach ($sampleApartments as $i => $aptId) {
                $invNum = sprintf('INV-2026-%04d', $i + 1);
                $status = $invoiceStatuses[$i % count($invoiceStatuses)];

                $mgntFee = 900000.00;
                $waterFee = 250000.00;
                $parkingFee = 150000.00;
                $subtotal = $mgntFee + $waterFee + $parkingFee;
                $vat = round($subtotal * 0.1, 2);
                $total = $subtotal + $vat;

                $paid = 0.00;
                if ($status === 'PAID') {
                    $paid = $total;
                } elseif ($status === 'PARTIALLY_PAID') {
                    $paid = round($total / 2, 2);
                }
                $remaining = max(0.00, round($total - $paid, 2));
                $dueDate = ($status === 'OVERDUE') ? '2026-08-15' : '2026-10-15';

                $existingInv = DB::table('invoices')
                    ->where('apartment_id', $aptId)
                    ->where('billing_period', '2026-09')
                    ->first();
                $invId = $existingInv ? $existingInv->id : (string) Str::uuid();
                $invoiceNumber = $existingInv ? $existingInv->invoice_number : $invNum;

                DB::table('invoices')->updateOrInsert(
                    [
                        'apartment_id' => $aptId,
                        'billing_period' => '2026-09',
                    ],
                    [
                        'id' => $invId,
                        'invoice_number' => $invoiceNumber,
                        'resident_user_id' => $userIdMap['resident.owner'],
                        'issue_date' => '2026-09-01',
                        'due_date' => $dueDate,
                        'subtotal_amount' => $subtotal,
                        'tax_amount' => $vat,
                        'discount_amount' => 0.00,
                        'previous_debt_amount' => 0.00,
                        'total_amount' => $total,
                        'paid_amount' => $paid,
                        'remaining_balance' => $remaining,
                        'status' => $status,
                        'notes' => "Hóa đơn phí quản lý và tiện ích chu kỳ 09/2026 ({$status})",
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );

                // Chi tiết mục hóa đơn (Invoice Items)
                $items = [
                    ['SRV_MANAGEMENT', 'Phí quản lý vận hành chung cư', 1, 'Tháng', $mgntFee, $mgntFee],
                    ['SRV_WATER', 'Tiền nước sinh hoạt căn hộ', 12, 'm3', round($waterFee / 12, 2), $waterFee],
                    ['SRV_PARKING', 'Phí gửi xe máy định kỳ', 1, 'Xe', $parkingFee, $parkingFee],
                ];

                foreach ($items as $idx => [$code, $desc, $qty, $unit, $price, $amount]) {
                    $vatItem = round($amount * 0.1, 2);
                    $existingItem = DB::table('invoice_items')
                        ->where('invoice_id', $invId)
                        ->where('service_code', $code)
                        ->first();
                    $itemId = $existingItem ? $existingItem->id : (string) Str::uuid();

                    DB::table('invoice_items')->updateOrInsert(
                        ['invoice_id' => $invId, 'service_code' => $code],
                        [
                            'id' => $itemId,
                            'item_description' => $desc,
                            'quantity' => $qty,
                            'unit_name' => $unit,
                            'unit_price' => $price,
                            'amount_before_tax' => $amount,
                            'vat_amount' => $vatItem,
                            'total_line_amount' => $amount + $vatItem,
                            'created_at' => $baseTime,
                        ]
                    );
                }
            }

            // =========================================================================
            // 6. AMENITIES & AMENITY BOOKINGS (6 Tiện ích, 20+ Lịch đặt)
            // =========================================================================
            $this->command->info('6/12. Khởi tạo 6 Tiện ích chuẩn và 20+ lượt Booking...');

            $amenityCategories = [
                ['SPORTS', 'Thể thao & Thể hình'],
                ['RECREATION', 'Giải trí & Ngoài trời'],
                ['COMMUNITY', 'Không gian cộng đồng'],
            ];

            $catIdMap = [];
            foreach ($amenityCategories as [$catCode, $catName]) {
                $existingCat = DB::table('amenity_categories')->where('category_code', $catCode)->first();
                $cId = $existingCat ? $existingCat->id : (string) Str::uuid();
                $catIdMap[$catCode] = $cId;

                DB::table('amenity_categories')->updateOrInsert(
                    ['category_code' => $catCode],
                    ['id' => $cId, 'category_name' => $catName, 'created_at' => $baseTime]
                );
            }

            $firstBlockId = array_values($apartmentDetails)[0]['block_id'];

            $amenityList = [
                ['GYM', 'Phòng Gym & Fitness Cao Cấp', 'Tầng 3 Tòa Ruby', 30, 0.00, 'SPORTS'],
                ['SWIMMING_POOL', 'Bể Bơi Vô Cực Nước Ấm', 'Tầng 5 Khối đế', 50, 20000.00, 'SPORTS'],
                ['BBQ_AREA', 'Khu Tiệc Nướng BBQ Sân Vườn', 'Công viên nội khu', 15, 100000.00, 'RECREATION'],
                ['COMMUNITY_ROOM', 'Phòng Sinh Hoạt Cộng Đồng', 'Tầng 1 Tòa Sapphire', 60, 50000.00, 'COMMUNITY'],
                ['BADMINTON_COURT', 'Sân Cầu Lông Tiêu Chuẩn', 'Tầng 3 Tháp A', 8, 0.00, 'SPORTS'],
                ['ROOFTOP', 'Vườn Chân Mây & Ngắm Cảnh Rooftop', 'Tầng thượng Tháp B', 40, 0.00, 'RECREATION'],
            ];

            $amenityIdMap = [];
            foreach ($amenityList as [$code, $name, $loc, $cap, $rate, $catKey]) {
                $existingAm = DB::table('amenities')->where('amenity_code', $code)->first();
                $amId = $existingAm ? $existingAm->id : (string) Str::uuid();
                $amenityIdMap[$code] = $amId;

                DB::table('amenities')->updateOrInsert(
                    ['amenity_code' => $code],
                    [
                        'id' => $amId,
                        'category_id' => $catIdMap[$catKey],
                        'block_id' => $firstBlockId,
                        'amenity_name' => $name,
                        'location_detail' => $loc,
                        'max_capacity_per_slot' => $cap,
                        'hourly_rate' => $rate,
                        'is_active' => 1,
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );
            }

            // Tạo 20 bookings
            $bookingStatuses = ['PENDING', 'CONFIRMED', 'COMPLETED', 'CANCELLED'];
            $amenityKeys = array_keys($amenityIdMap);

            for ($b = 1; $b <= 20; $b++) {
                $bkCode = sprintf('BK-202609-%04d', $b);
                $status = $bookingStatuses[$b % count($bookingStatuses)];
                $amKey = $amenityKeys[$b % count($amenityKeys)];
                $targetApt = $allApartmentIds[$b % count($allApartmentIds)];
                $targetUser = $residentUsersList[$b % count($residentUsersList)];

                $dayOffset = ($b % 10) - 5;
                $bDate = Carbon::create(2026, 9, 24)->addDays($dayOffset)->toDateString();

                $existingBk = DB::table('amenity_bookings')->where('booking_code', $bkCode)->first();
                $bkId = $existingBk ? $existingBk->id : (string) Str::uuid();

                DB::table('amenity_bookings')->updateOrInsert(
                    ['booking_code' => $bkCode],
                    [
                        'id' => $bkId,
                        'amenity_id' => $amenityIdMap[$amKey],
                        'apartment_id' => $targetApt,
                        'resident_user_id' => $targetUser,
                        'booking_date' => $bDate,
                        'start_time' => sprintf('%02d:00:00', 8 + ($b % 12)),
                        'end_time' => sprintf('%02d:00:00', 9 + ($b % 12)),
                        'attendee_count' => ($b % 4) + 1,
                        'total_amount' => 50000.00,
                        'deposit_amount' => 0.00,
                        'is_paid' => ($status === 'CONFIRMED' || $status === 'COMPLETED') ? 1 : 0,
                        'status' => $status,
                        'checkin_qr_code' => 'QR_'.Str::random(16),
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );
            }

            // =========================================================================
            // 7. TICKET CATEGORIES & TICKETS (30+ Tickets, 7 Nhóm sự cố)
            // =========================================================================
            $this->command->info('7/12. Khởi tạo 30+ Phản ánh / Yêu cầu bảo trì (Tickets)...');

            $ticketCategories = [
                ['PLUMBING', 'Sự cố Điện nước sinh hoạt', 'HIGH'],
                ['ELECTRICITY', 'Sự cố Hệ thống điện', 'HIGH'],
                ['ELEVATOR', 'Sự cố Thang máy', 'URGENT'],
                ['CLEANING', 'Vệ sinh & Thu gom rác thải', 'MEDIUM'],
                ['SECURITY', 'An ninh & Trật tự công cộng', 'HIGH'],
                ['NOISE', 'Tiếng ồn & Cách âm', 'LOW'],
                ['OTHER', 'Ý kiến đóng góp & Phản ánh khác', 'LOW'],
            ];

            $ticketCatMap = [];
            foreach ($ticketCategories as [$tCode, $tName, $prio]) {
                $existingTC = DB::table('ticket_categories')->where('category_code', $tCode)->first();
                $tcId = $existingTC ? $existingTC->id : (string) Str::uuid();
                $ticketCatMap[$tCode] = $tcId;

                DB::table('ticket_categories')->updateOrInsert(
                    ['category_code' => $tCode],
                    [
                        'id' => $tcId,
                        'category_name' => $tName,
                        'default_priority' => $prio,
                        'sla_response_time_minutes' => 30,
                        'sla_resolution_time_hours' => 24,
                        'created_at' => $baseTime,
                    ]
                );
            }

            $ticketStatuses = ['NEW', 'RECEIVED', 'IN_PROGRESS', 'PROCESSING', 'RESOLVED', 'CLOSED', 'REJECTED'];
            $ticketPriorities = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];
            $tKeys = array_keys($ticketCatMap);

            for ($t = 1; $t <= 30; $t++) {
                $tNumber = sprintf('TICKET-2026-%04d', $t);
                $status = $ticketStatuses[$t % count($ticketStatuses)];
                $priority = $ticketPriorities[$t % count($ticketPriorities)];
                $catKey = $tKeys[$t % count($tKeys)];
                $targetApt = $allApartmentIds[$t % count($allApartmentIds)];
                $targetUser = $residentUsersList[$t % count($residentUsersList)];

                $existingTicket = DB::table('tickets')->where('ticket_number', $tNumber)->first();
                $ticketId = $existingTicket ? $existingTicket->id : (string) Str::uuid();

                DB::table('tickets')->updateOrInsert(
                    ['ticket_number' => $tNumber],
                    [
                        'id' => $ticketId,
                        'category_id' => $ticketCatMap[$catKey],
                        'apartment_id' => $targetApt,
                        'creator_user_id' => $targetUser,
                        'title' => "Yêu cầu kỹ thuật #{$t}: Xử lý phản ánh {$catKey}",
                        'description' => "Cư dân báo cáo vấn đề liên quan đến {$catKey} cần ban quản lý kiểm tra và khắc phục kịp thời.",
                        'priority' => $priority,
                        'status' => $status,
                        'sla_deadline' => Carbon::create(2026, 9, 25, 18, 0, 0),
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );
            }

            // =========================================================================
            // 8. VISITOR REGISTRATIONS (20+ Khách viếng thăm)
            // =========================================================================
            $this->command->info('8/12. Khởi tạo 20+ lượt Đăng ký khách viếng thăm...');

            $visitorStatuses = ['PENDING', 'ACTIVE', 'USED', 'EXPIRED', 'CANCELLED'];

            for ($v = 1; $v <= 20; $v++) {
                $vCode = sprintf('VIS-202609-%04d', $v);
                $status = $visitorStatuses[$v % count($visitorStatuses)];
                $targetApt = $allApartmentIds[$v % count($allApartmentIds)];
                $hostUser = $residentUsersList[$v % count($residentUsersList)];

                $existingVis = DB::table('visitor_registrations')->where('registration_code', $vCode)->first();
                $vId = $existingVis ? $existingVis->id : (string) Str::uuid();

                DB::table('visitor_registrations')->updateOrInsert(
                    ['registration_code' => $vCode],
                    [
                        'id' => $vId,
                        'host_resident_user_id' => $hostUser,
                        'apartment_id' => $targetApt,
                        'visitor_name' => "Khách Mẫu #{$v}",
                        'visitor_phone' => sprintf('09880000%02d', $v),
                        'visit_purpose' => ($v % 2 === 0) ? 'Thăm người thân gia đình' : 'Giao nhận hàng hóa / nội thất',
                        'expected_arrival_time' => Carbon::create(2026, 9, 24, 10, 0, 0)->addHours($v),
                        'expected_departure_time' => Carbon::create(2026, 9, 24, 12, 0, 0)->addHours($v),
                        'vehicle_license_plate' => sprintf('29A-%03d.%02d', 100 + $v, $v),
                        'qr_access_pass_code' => 'PASS_'.Str::random(20),
                        'qr_pass_status' => $status,
                        'is_pre_approved_by_resident' => 1,
                        'created_at' => $baseTime,
                        'updated_at' => $baseTime,
                    ]
                );
            }

            // =========================================================================
            // 9. NOTIFICATIONS (30+ Thông báo trong ứng dụng)
            // =========================================================================
            $this->command->info('9/12. Khởi tạo 30+ Thông báo hệ thống cho cư dân...');

            $notifCategories = ['INVOICE', 'TICKET', 'BOOKING', 'MAINTENANCE', 'VISITOR', 'GENERAL'];

            for ($n = 1; $n <= 30; $n++) {
                $category = $notifCategories[$n % count($notifCategories)];
                $targetUser = $residentUsersList[$n % count($residentUsersList)];
                $isRead = ($n % 2 === 0);

                DB::table('user_in_app_notifications')->updateOrInsert(
                    [
                        'recipient_user_id' => $targetUser,
                        'title' => "Thông báo hệ thống [{$category}] #{$n}",
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'body_message' => "Nội dung cập nhật mới nhất từ Ban Quản Lý Smart Cassavas về hạng mục {$category}.",
                        'deep_link_url' => "/notifications/{$n}",
                        'category' => $category,
                        'is_read' => $isRead ? 1 : 0,
                        'read_at' => $isRead ? $baseTime : null,
                        'created_at' => $baseTime,
                    ]
                );
            }

            $this->command->info('10/12. Hoàn tất chuỗi dữ liệu nghiệp vụ cốt lõi.');
        });

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }

        $this->command->info('✅ HOÀN TẤT NẠP DỮ LIỆU ẢO DÙNG CHUNG THÀNH CÔNG RỰC RỠ!');
    }
}
