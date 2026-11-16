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

            // VEHICLE MODULE
            ['module' => 'VEHICLE', 'permission_code' => 'VEHICLE:VIEW', 'permission_name' => 'Xem danh sách phương tiện', 'description' => 'Tra cứu danh sách xe máy, ô tô và tình trạng duyệt'],
            ['module' => 'VEHICLE', 'permission_code' => 'VEHICLE:CREATE', 'permission_name' => 'Đăng ký xe & đẩy phí hóa đơn', 'description' => 'Đăng ký phương tiện cho căn hộ và tự động tạo phí gửi xe vào hóa đơn'],
            ['module' => 'VEHICLE', 'permission_code' => 'VEHICLE:UPDATE', 'permission_name' => 'Cập nhật thông tin phương tiện', 'description' => 'Chỉnh sửa biển số, dòng xe, màu sắc và trạng thái'],
            ['module' => 'VEHICLE', 'permission_code' => 'VEHICLE:DELETE', 'permission_name' => 'Xóa / ngừng theo dõi xe', 'description' => 'Xóa mềm phương tiện ra khỏi bãi đỗ'],
            ['module' => 'VEHICLE', 'permission_code' => 'VEHICLE:APPROVE', 'permission_name' => 'Phê duyệt xe & kích hoạt thu phí', 'description' => 'Duyệt phương tiện và kích hoạt đẩy phí vào kỳ hóa đơn'],

            // VEHICLE MODULE EXTENSIONS
            ['module' => 'VEHICLE', 'permission_code' => 'VEHICLE:DOWNLOAD', 'permission_name' => 'Tải về danh sách xe', 'description' => 'Xuất file Excel danh sách phương tiện'],
            ['module' => 'VEHICLE', 'permission_code' => 'VEHICLE:UPLOAD', 'permission_name' => 'Tải lên danh sách xe', 'description' => 'Nhập dữ liệu xe hàng loạt'],
            ['module' => 'VEHICLE', 'permission_code' => 'VEHICLE:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ phương tiện', 'description' => 'Quyền người đặc biệt cấp thẻ xe ưu tiên đặc thù'],

            // ROLE MODULE EXTENSIONS
            ['module' => 'ROLE', 'permission_code' => 'ROLE:DOWNLOAD', 'permission_name' => 'Tải về danh sách vai trò', 'description' => 'Tải về dữ liệu vai trò'],
            ['module' => 'ROLE', 'permission_code' => 'ROLE:UPLOAD', 'permission_name' => 'Tải lên danh sách vai trò', 'description' => 'Tải lên cấu hình vai trò'],
            ['module' => 'ROLE', 'permission_code' => 'ROLE:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ vai trò', 'description' => 'Quyền người đặc biệt thao tác vai trò đặc thù'],

            // PERMISSION MODULE EXTENSIONS
            ['module' => 'PERMISSION', 'permission_code' => 'PERMISSION:DOWNLOAD', 'permission_name' => 'Tải về danh mục quyền hạn', 'description' => 'Tải về danh mục quyền hạn hệ thống'],
            ['module' => 'PERMISSION', 'permission_code' => 'PERMISSION:UPLOAD', 'permission_name' => 'Tải lên danh mục quyền hạn', 'description' => 'Tải lên danh mục quyền hạn hệ thống'],
            ['module' => 'PERMISSION', 'permission_code' => 'PERMISSION:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ quyền hạn', 'description' => 'Quyền người đặc biệt quản lý quyền hạn đặc thù'],

            // AMENITY MODULE EXTENSIONS
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:DOWNLOAD', 'permission_name' => 'Tải về dữ liệu tiện ích', 'description' => 'Tải về danh sách tiện ích và đặt chỗ'],
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:UPLOAD', 'permission_name' => 'Tải lên dữ liệu tiện ích', 'description' => 'Tải lên thông tin tiện ích và biểu phí'],
            ['module' => 'AMENITY', 'permission_code' => 'AMENITY:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ tiện ích', 'description' => 'Quyền người đặc biệt phê duyệt đặt chỗ đặc thù'],

            // INVOICE MODULE EXTENSIONS
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:DELETE', 'permission_name' => 'Xóa hóa đơn', 'description' => 'Hủy hoặc xóa hóa đơn'],
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:DOWNLOAD', 'permission_name' => 'Tải về hóa đơn', 'description' => 'Xuất và tải về hóa đơn chứng từ'],
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:UPLOAD', 'permission_name' => 'Tải lên biểu phí hóa đơn', 'description' => 'Tải lên file dữ liệu hóa đơn'],
            ['module' => 'INVOICE', 'permission_code' => 'INVOICE:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ hóa đơn', 'description' => 'Quyền người đặc biệt can thiệp tài chính đặc thù'],

            // TICKET MODULE EXTENSIONS
            ['module' => 'TICKET', 'permission_code' => 'TICKET:DELETE', 'permission_name' => 'Xóa phản ánh sự cố', 'description' => 'Xóa phản ánh hoặc báo cáo rác'],
            ['module' => 'TICKET', 'permission_code' => 'TICKET:DOWNLOAD', 'permission_name' => 'Tải về danh sách sự cố', 'description' => 'Xuất và tải về danh sách sự cố'],
            ['module' => 'TICKET', 'permission_code' => 'TICKET:UPLOAD', 'permission_name' => 'Tải lên tài liệu kỹ thuật', 'description' => 'Tải lên file đính kèm biên bản xử lý'],
            ['module' => 'TICKET', 'permission_code' => 'TICKET:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ sự cố', 'description' => 'Quyền người đặc biệt xử lý khiếu nại đặc thù'],

            // VISITOR MODULE EXTENSIONS
            ['module' => 'VISITOR', 'permission_code' => 'VISITOR:UPDATE', 'permission_name' => 'Cập nhật thông tin khách', 'description' => 'Chỉnh sửa thông tin khách đến'],
            ['module' => 'VISITOR', 'permission_code' => 'VISITOR:DELETE', 'permission_name' => 'Xóa nhật ký khách đến', 'description' => 'Xóa dữ liệu khách viếng thăm'],
            ['module' => 'VISITOR', 'permission_code' => 'VISITOR:DOWNLOAD', 'permission_name' => 'Tải về nhật ký khách đến', 'description' => 'Xuất nhật ký khách ra vào'],
            ['module' => 'VISITOR', 'permission_code' => 'VISITOR:UPLOAD', 'permission_name' => 'Tải lên danh sách khách', 'description' => 'Nhập danh sách khách sự kiện từ tệp'],
            ['module' => 'VISITOR', 'permission_code' => 'VISITOR:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ khách đến', 'description' => 'Quyền người đặc biệt cấp phép đón khách đặc biệt'],

            // REPORT MODULE EXTENSIONS
            ['module' => 'REPORT', 'permission_code' => 'REPORT:CREATE', 'permission_name' => 'Tạo mẫu báo cáo mới', 'description' => 'Thiết lập mẫu báo cáo thống kê mới'],
            ['module' => 'REPORT', 'permission_code' => 'REPORT:UPDATE', 'permission_name' => 'Cập nhật mẫu báo cáo', 'description' => 'Chỉnh sửa cấu hình báo cáo'],
            ['module' => 'REPORT', 'permission_code' => 'REPORT:DELETE', 'permission_name' => 'Xóa mẫu báo cáo', 'description' => 'Xóa báo cáo tùy chỉnh'],
            ['module' => 'REPORT', 'permission_code' => 'REPORT:DOWNLOAD', 'permission_name' => 'Tải về báo cáo thống kê', 'description' => 'Tải về dữ liệu báo cáo dạng Excel/PDF'],
            ['module' => 'REPORT', 'permission_code' => 'REPORT:UPLOAD', 'permission_name' => 'Tải lên dữ liệu đối soát', 'description' => 'Tải lên tệp dữ liệu số liệu đối soát'],
            ['module' => 'REPORT', 'permission_code' => 'REPORT:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ báo cáo', 'description' => 'Quyền người đặc biệt xem báo cáo tài chính bảo mật'],

            // USER MODULE EXTENSIONS (DOWNLOAD, UPLOAD, SPECIAL)
            ['module' => 'USER', 'permission_code' => 'USER:DOWNLOAD', 'permission_name' => 'Tải về danh sách người dùng', 'description' => 'Tải về danh sách tài khoản định dạng Excel/CSV'],
            ['module' => 'USER', 'permission_code' => 'USER:UPLOAD', 'permission_name' => 'Tải lên danh sách người dùng', 'description' => 'Tải lên danh sách tài khoản hàng loạt từ tệp tin'],
            ['module' => 'USER', 'permission_code' => 'USER:SPECIAL', 'permission_name' => 'Người đặc biệt phân hệ người dùng', 'description' => 'Quyền người đặc biệt thao tác tài khoản đặc thù'],

            // SYSTEM MODULE (7 CORE RBAC PERMISSIONS INCL. SPECIAL)
            ['module' => 'SYSTEM', 'permission_code' => 'SYSTEM:CREATE', 'permission_name' => 'Thêm thiết lập hệ thống', 'description' => 'Thêm mới cấu hình và tham số hệ thống'],
            ['module' => 'SYSTEM', 'permission_code' => 'SYSTEM:DELETE', 'permission_name' => 'Xóa thiết lập hệ thống', 'description' => 'Xóa cấu hình và dữ liệu hệ thống'],
            ['module' => 'SYSTEM', 'permission_code' => 'SYSTEM:UPDATE', 'permission_name' => 'Sửa thiết lập hệ thống', 'description' => 'Chỉnh sửa cấu hình và tham số hệ thống'],
            ['module' => 'SYSTEM', 'permission_code' => 'SYSTEM:DOWNLOAD', 'permission_name' => 'Tải về sao lưu hệ thống', 'description' => 'Tải về tệp tin sao lưu và nhật ký hệ thống'],
            ['module' => 'SYSTEM', 'permission_code' => 'SYSTEM:UPLOAD', 'permission_name' => 'Tải lên cấu hình hệ thống', 'description' => 'Tải lên gói cập nhật và tệp tin hệ thống'],
            ['module' => 'SYSTEM', 'permission_code' => 'SYSTEM:VIEW', 'permission_name' => 'Xem cấu hình hệ thống', 'description' => 'Tra cứu thông tin và nhật ký hoạt động hệ thống'],
            ['module' => 'SYSTEM', 'permission_code' => 'SYSTEM:SPECIAL', 'permission_name' => 'Người đặc biệt (special)', 'description' => 'Quyền người đặc biệt với thẩm quyền quản trị đặc thù'],
            ['module' => 'SYSTEM', 'permission_code' => 'SPECIAL', 'permission_name' => 'Người đặc biệt (special)', 'description' => 'Quyền người đặc biệt toàn hệ thống'],
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
                'VEHICLE:VIEW', 'VEHICLE:CREATE', 'VEHICLE:UPDATE', 'VEHICLE:DELETE', 'VEHICLE:APPROVE',
            ],
            'ACCOUNTANT' => [
                'INVOICE:VIEW', 'INVOICE:CREATE', 'INVOICE:UPDATE', 'INVOICE:APPROVE', 'INVOICE:PAY',
                'REPORT:VIEW', 'REPORT:EXPORT',
            ],
            'RECEPTIONIST' => [
                'VISITOR:VIEW', 'VISITOR:CREATE', 'VISITOR:CHECKIN',
                'TICKET:VIEW', 'TICKET:CREATE',
                'AMENITY:VIEW',
                'VEHICLE:VIEW', 'VEHICLE:CREATE', 'VEHICLE:UPDATE', 'VEHICLE:APPROVE',
            ],
            'SECURITY_GUARD' => [
                'VISITOR:VIEW', 'VISITOR:CREATE', 'VISITOR:CHECKIN',
                'TICKET:VIEW', 'TICKET:CREATE',
                'VEHICLE:VIEW', 'VEHICLE:CREATE', 'VEHICLE:UPDATE', 'VEHICLE:APPROVE',
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
