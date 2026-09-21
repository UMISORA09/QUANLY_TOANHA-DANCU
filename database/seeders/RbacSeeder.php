<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RbacSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Danh sách Vai trò (Roles) chuẩn
        $roles = [
            [
                'role_code' => 'SUPER_ADMIN',
                'role_name' => 'Quản Trị Viên Cấp Cao',
                'description' => 'Toàn quyền quản trị hệ thống, tài khoản, vai trò và phân quyền.',
                'is_system_role' => true,
            ],
            [
                'role_code' => 'BUILDING_MANAGER',
                'role_name' => 'Ban Quản Lý Tòa Nhà',
                'description' => 'Quản lý vận hành toàn diện: cư dân, căn hộ, tiện ích, kỹ thuật, phản ánh và tài chính.',
                'is_system_role' => true,
            ],
            [
                'role_code' => 'ACCOUNTANT',
                'role_name' => 'Kế Toán Tòa Nhà',
                'description' => 'Quản lý hóa đơn, thu phí, hạch toán và đối soát thanh toán.',
                'is_system_role' => true,
            ],
            [
                'role_code' => 'RECEPTIONIST',
                'role_name' => 'Nhân Viên Lễ Tân',
                'description' => 'Tiếp nhận khách viếng thăm, giao nhận bưu phẩm và trực sảnh.',
                'is_system_role' => true,
            ],
            [
                'role_code' => 'SECURITY_GUARD',
                'role_name' => 'Nhân Viên Bảo Vệ',
                'description' => 'Giám sát an ninh, bãi giữ xe, tuần tra và xử lý sự cố khẩn cấp.',
                'is_system_role' => true,
            ],
            [
                'role_code' => 'TECHNICIAN',
                'role_name' => 'Nhân Viên Kỹ Thuật',
                'description' => 'Tiếp nhận, xử lý bảo trì trang thiết bị và khắc phục sự cố tòa nhà.',
                'is_system_role' => true,
            ],
            [
                'role_code' => 'RESIDENT_OWNER',
                'role_name' => 'Cư Dân Chủ Hộ',
                'description' => 'Cư dân sở hữu căn hộ, sử dụng dịch vụ tiện ích, thanh toán hóa đơn và phản ánh sự cố.',
                'is_system_role' => true,
            ],
            [
                'role_code' => 'RESIDENT_MEMBER',
                'role_name' => 'Thành Viên Trong Hộ',
                'description' => 'Thành viên cùng sinh hoạt trong căn hộ.',
                'is_system_role' => true,
            ],
        ];

        $roleModelMap = [];
        foreach ($roles as $rData) {
            $role = Role::firstOrCreate(
                ['role_code' => $rData['role_code']],
                [
                    'role_name' => $rData['role_name'],
                    'description' => $rData['description'],
                    'is_system_role' => $rData['is_system_role'],
                ]
            );
            $roleModelMap[$rData['role_code']] = $role;
        }

        // 2. Danh mục Quyền hạn (Permissions Catalog)
        $permissionCatalog = [
            // USER MODULE
            ['module' => 'USER', 'permission_code' => 'USER:VIEW', 'permission_name' => 'Xem danh sách người dùng', 'description' => 'Xem danh sách và thông tin tài khoản người dùng'],
            ['module' => 'USER', 'permission_code' => 'USER:CREATE', 'permission_name' => 'Tạo người dùng mới', 'description' => 'Thêm mới tài khoản người dùng vào hệ thống'],
            ['module' => 'USER', 'permission_code' => 'USER:UPDATE', 'permission_name' => 'Cập nhật người dùng', 'description' => 'Chỉnh sửa thông tin tài khoản, khóa hoặc mở khóa'],
            ['module' => 'USER', 'permission_code' => 'USER:DELETE', 'permission_name' => 'Xóa người dùng', 'description' => 'Vô hiệu hóa hoặc xóa người dùng khỏi hệ thống'],
            ['module' => 'USER', 'permission_code' => 'USER:ASSIGN_ROLE', 'permission_name' => 'Gán vai trò cho người dùng', 'description' => 'Phân quyền và gán vai trò cho tài khoản'],

            // ROLE MODULE
            ['module' => 'ROLE', 'permission_code' => 'ROLE:VIEW', 'permission_name' => 'Xem danh sách vai trò', 'description' => 'Xem danh sách các vai trò trong hệ thống'],
            ['module' => 'ROLE', 'permission_code' => 'ROLE:CREATE', 'permission_name' => 'Tạo vai trò mới', 'description' => 'Thêm vai trò quản trị mới'],
            ['module' => 'ROLE', 'permission_code' => 'ROLE:UPDATE', 'permission_name' => 'Cập nhật vai trò', 'description' => 'Chỉnh sửa tên và mô tả vai trò'],
            ['module' => 'ROLE', 'permission_code' => 'ROLE:DELETE', 'permission_name' => 'Xóa vai trò', 'description' => 'Xóa vai trò tùy chỉnh không phải vai trò hệ thống'],
            ['module' => 'ROLE', 'permission_code' => 'ROLE:ASSIGN_PERMISSION', 'permission_name' => 'Cấu hình quyền hạn vai trò', 'description' => 'Gán hoặc thu hồi quyền cho từng vai trò'],

            // PERMISSION MODULE
            ['module' => 'PERMISSION', 'permission_code' => 'PERMISSION:VIEW', 'permission_name' => 'Xem danh mục quyền', 'description' => 'Xem danh sách các quyền hạn hệ thống'],
            ['module' => 'PERMISSION', 'permission_code' => 'PERMISSION:CREATE', 'permission_name' => 'Tạo quyền hạn mới', 'description' => 'Khai báo quyền hạn mới cho module'],
            ['module' => 'PERMISSION', 'permission_code' => 'PERMISSION:UPDATE', 'permission_name' => 'Cập nhật quyền hạn', 'description' => 'Chỉnh sửa tên và mô tả quyền hạn'],
            ['module' => 'PERMISSION', 'permission_code' => 'PERMISSION:DELETE', 'permission_name' => 'Xóa quyền hạn', 'description' => 'Xóa quyền hạn tùy chỉnh'],

            // AMENITY MODULE
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:VIEW', 'permission_name' => 'Xem tiện ích & lịch đặt', 'description' => 'Xem danh sách tiện ích và thông tin slot'],
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:CREATE', 'permission_name' => 'Tạo tiện ích mới', 'description' => 'Thêm tiện ích tòa nhà mới'],
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:UPDATE', 'permission_name' => 'Cập nhật tiện ích', 'description' => 'Chỉnh sửa tiện ích, biểu phí và thời gian'],
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:DELETE', 'permission_name' => 'Xóa tiện ích', 'description' => 'Xóa hoặc ngừng phục vụ tiện ích'],
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:CONFIG_SLOT', 'permission_name' => 'Cấu hình khung giờ & Slot', 'description' => 'Cài đặt khung giờ và ngày bảo trì tiện ích'],
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:BOOK', 'permission_name' => 'Đặt chỗ tiện ích', 'description' => 'Thực hiện đăng ký và giữ chỗ tiện ích'],

            // INVOICE MODULE
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:VIEW', 'permission_name' => 'Xem hóa đơn & biểu phí', 'description' => 'Tra cứu hóa đơn và lịch sử thanh toán'],
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:CREATE', 'permission_name' => 'Tạo đợt thu & hóa đơn', 'description' => 'Lập hóa đơn dịch vụ định kỳ'],
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:UPDATE', 'permission_name' => 'Điều chỉnh hóa đơn', 'description' => 'Sửa đổi chi tiết hóa đơn hoặc miễn giảm'],
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:APPROVE', 'permission_name' => 'Duyệt phát hành hóa đơn', 'description' => 'Phê duyệt gửi hóa đơn cho cư dân'],
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:PAY', 'permission_name' => 'Thanh toán hóa đơn', 'description' => 'Thực hiện thanh toán hoặc xác nhận tiền vào'],

            // TICKET MODULE
            ['module' => 'TICKET', 'permission_code' => 'TICKET:VIEW', 'permission_name' => 'Xem phản ánh & sự cố', 'description' => 'Tra cứu danh sách phiếu hỗ trợ'],
            ['module' => 'TICKET', 'permission_code' => 'TICKET:CREATE', 'permission_name' => 'Gửi phản ánh sự cố', 'description' => 'Tạo yêu cầu sửa chữa hoặc phản ánh chất lượng'],
            ['module' => 'TICKET', 'permission_code' => 'TICKET:UPDATE', 'permission_name' => 'Tiếp nhận & xử lý phản ánh', 'description' => 'Phân công nhân viên và cập nhật tiến độ'],
            ['module' => 'TICKET', 'permission_code' => 'TICKET:RESOLVE', 'permission_name' => 'Đóng & đánh giá sự cố', 'description' => 'Nghiệm thu và hoàn thành xử lý sự cố'],

            // VISITOR MODULE
            ['module' => 'VISITOR', 'permission_code' => 'VISITOR:VIEW', 'permission_name' => 'Xem danh sách khách đến', 'description' => 'Tra cứu nhật ký ra vào của khách viếng thăm'],
            ['module' => 'VISITOR', 'permission_code' => 'VISITOR:CREATE', 'permission_name' => 'Đăng ký khách viếng thăm', 'description' => 'Khai báo thông tin khách lên căn hộ'],
            ['module' => 'VISITOR', 'permission_code' => 'VISITOR:CHECKIN', 'permission_name' => 'Lễ tân check-in khách', 'description' => 'Xác nhận và cấp thẻ ra vào cho khách'],

            // REPORT MODULE
            ['module' => 'REPORT', 'permission_code' => 'REPORT:VIEW', 'permission_name' => 'Xem báo cáo thống kê', 'description' => 'Xem biểu đồ doanh thu và vận hành'],
            ['module' => 'REPORT', 'permission_code' => 'REPORT:EXPORT', 'permission_name' => 'Xuất file báo cáo', 'description' => 'Xuất dữ liệu ra Excel/PDF'],
        ];

        $permissionModelMap = [];
        foreach ($permissionCatalog as $pData) {
            $perm = Permission::firstOrCreate(
                ['permission_code' => $pData['permission_code']],
                [
                    'module' => $pData['module'],
                    'permission_name' => $pData['permission_name'],
                    'description' => $pData['description'],
                    'created_at' => now(),
                ]
            );
            $permissionModelMap[$pData['permission_code']] = $perm;
        }

        // 3. Ma trận Phân Quyền (Role-Permission Matrix)
        $rolePermissionsMap = [
            'SUPER_ADMIN' => array_keys($permissionModelMap), // Full permissions
            'BUILDING_MANAGER' => [
                'USER:VIEW', 'USER:CREATE', 'USER:UPDATE', 'USER:ASSIGN_ROLE',
                'ROLE:VIEW',
                'PERMISSION:VIEW',
                'AMENITY:VIEW', 'AMENITY:CREATE', 'AMENITY:UPDATE', 'AMENITY:DELETE', 'AMENITY:CONFIG_SLOT', 'AMENITY:BOOK',
                'INVOICE:VIEW', 'INVOICE:CREATE', 'INVOICE:UPDATE', 'INVOICE:APPROVE',
                'TICKET:VIEW', 'TICKET:UPDATE', 'TICKET:RESOLVE',
                'VISITOR:VIEW', 'VISITOR:CREATE', 'VISITOR:CHECKIN',
                'REPORT:VIEW', 'REPORT:EXPORT',
            ],
            'ACCOUNTANT' => [
                'INVOICE:VIEW', 'INVOICE:CREATE', 'INVOICE:UPDATE', 'INVOICE:APPROVE', 'INVOICE:PAY',
                'REPORT:VIEW', 'REPORT:EXPORT',
            ],
            'RECEPTIONIST' => [
                'VISITOR:VIEW', 'VISITOR:CREATE', 'VISITOR:CHECKIN',
                'TICKET:VIEW', 'TICKET:CREATE',
                'AMENITY:VIEW',
            ],
            'TECHNICIAN' => [
                'TICKET:VIEW', 'TICKET:UPDATE', 'TICKET:RESOLVE',
                'AMENITY:VIEW', 'AMENITY:UPDATE',
            ],
            'RESIDENT_OWNER' => [
                'AMENITY:VIEW', 'AMENITY:BOOK',
                'INVOICE:VIEW', 'INVOICE:PAY',
                'TICKET:VIEW', 'TICKET:CREATE',
                'VISITOR:CREATE', 'VISITOR:VIEW',
            ],
            'RESIDENT_MEMBER' => [
                'AMENITY:VIEW', 'AMENITY:BOOK',
                'TICKET:VIEW', 'TICKET:CREATE',
                'VISITOR:CREATE',
            ],
        ];

        foreach ($rolePermissionsMap as $roleCode => $permCodes) {
            $role = $roleModelMap[$roleCode] ?? null;
            if (! $role) {
                continue;
            }

            foreach ($permCodes as $code) {
                $perm = $permissionModelMap[$code] ?? null;
                if (! $perm) {
                    continue;
                }

                $exists = DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $perm->id)
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $perm->id,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        // 4. Đảm bảo các tài khoản mẫu (Demo accounts) được gán đúng vai trò
        $accountMappings = [
            'admin' => 'SUPER_ADMIN',
            'quanly' => 'BUILDING_MANAGER',
            'letan' => 'RECEPTIONIST',
            'kythuat' => 'TECHNICIAN',
            'nguyenvanan' => 'RESIDENT_OWNER',
        ];

        foreach ($accountMappings as $username => $roleCode) {
            $user = User::where('username', $username)->first();
            $role = $roleModelMap[$roleCode] ?? null;

            if ($user && $role) {
                $exists = DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->where('role_id', $role->id)
                    ->exists();

                if (! $exists) {
                    DB::table('user_roles')->insert([
                        'id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'role_id' => $role->id,
                        'is_primary' => 1,
                        'assigned_at' => now(),
                    ]);
                }
            }
        }
    }
}
