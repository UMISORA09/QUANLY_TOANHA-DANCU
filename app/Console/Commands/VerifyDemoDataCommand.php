<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VerifyDemoDataCommand extends Command
{
    /**
     * Tên và chữ ký của lệnh console.
     *
     * @var string
     */
    protected $signature = 'demo:verify';

    /**
     * Mô tả lệnh console.
     *
     * @var string
     */
    protected $description = 'Kiểm tra tính toàn vẹn và hợp lệ của bộ dữ liệu ảo dùng chung (Shared Demo Data)';

    /**
     * Thực thi lệnh console.
     */
    public function handle(): int
    {
        $this->info('========================================================================');
        $this->info('KIỂM TRA CHẤT LƯỢNG VÀ TÍNH TOÀN VẸN CỦA DỮ LIỆU MẪU (DEMO DATA)');
        $this->info('========================================================================');

        $errors = [];

        // 1. Kiểm tra 7 tài khoản demo cố định
        $this->line('▶ 1. Kiểm tra 7 tài khoản demo cố định và mật khẩu đăng nhập...');
        $demoAccounts = [
            'admin' => ['email' => 'admin@demo.local', 'password' => 'Admin@123456', 'role' => 'SUPER_ADMIN'],
            'manager' => ['email' => 'manager@demo.local', 'password' => 'Manager@123456', 'role' => 'BUILDING_MANAGER'],
            'accountant' => ['email' => 'accountant@demo.local', 'password' => 'Accountant@123456', 'role' => 'ACCOUNTANT'],
            'receptionist' => ['email' => 'receptionist@demo.local', 'password' => 'Receptionist@123456', 'role' => 'RECEPTIONIST'],
            'security' => ['email' => 'security@demo.local', 'password' => 'Security@123456', 'role' => 'SECURITY_GUARD'],
            'resident.owner' => ['email' => 'resident.owner@demo.local', 'password' => 'Resident@123456', 'role' => 'RESIDENT_OWNER'],
            'resident.member' => ['email' => 'resident.member@demo.local', 'password' => 'Resident@123456', 'role' => 'RESIDENT_MEMBER'],
        ];

        foreach ($demoAccounts as $username => $info) {
            $user = DB::table('users')->where('username', $username)->first();
            if (! $user) {
                $errors[] = "Thiếu tài khoản demo: {$username} ({$info['email']})";

                continue;
            }

            if (! Hash::check($info['password'], $user->password_hash)) {
                $errors[] = "Mật khẩu không khớp cho tài khoản: {$username}";
            }

            $hasRole = DB::table('user_roles')
                ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                ->where('user_roles.user_id', $user->id)
                ->where('roles.role_code', $info['role'])
                ->exists();

            if (! $hasRole) {
                $errors[] = "Tài khoản {$username} chưa được gán vai trò: {$info['role']}";
            }
        }

        // 2. Kiểm tra trùng lặp email, username, phone
        $this->line('▶ 2. Kiểm tra trùng lặp Email, Username và Số điện thoại...');
        $duplicateEmails = DB::table('users')->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->count();
        if ($duplicateEmails > 0) {
            $errors[] = "Phát hiện {$duplicateEmails} email bị trùng lặp trong bảng users.";
        }

        $duplicateUsers = DB::table('users')->select('username')->groupBy('username')->havingRaw('COUNT(*) > 1')->count();
        if ($duplicateUsers > 0) {
            $errors[] = "Phát hiện {$duplicateUsers} username bị trùng lặp trong bảng users.";
        }

        $duplicatePhones = DB::table('users')->select('phone_number')->groupBy('phone_number')->havingRaw('COUNT(*) > 1')->count();
        if ($duplicatePhones > 0) {
            $errors[] = "Phát hiện {$duplicatePhones} số điện thoại bị trùng lặp trong bảng users.";
        }

        // 3. Kiểm tra Tòa nhà và Căn hộ
        $this->line('▶ 3. Kiểm tra cấu trúc Blocks, Floors và Apartments (tối thiểu 80 căn)...');
        $aptCount = DB::table('apartments')->count();
        if ($aptCount < 80) {
            $errors[] = "Số lượng căn hộ ({$aptCount}) chưa đạt yêu cầu tối thiểu (>= 80 căn).";
        }

        $orphanApts = DB::table('apartments')
            ->leftJoin('blocks', 'apartments.block_id', '=', 'blocks.id')
            ->whereNull('blocks.id')
            ->count();
        if ($orphanApts > 0) {
            $errors[] = "Có {$orphanApts} căn hộ không thuộc Block hợp lệ.";
        }

        $orphanFloors = DB::table('apartments')
            ->leftJoin('floors', 'apartments.floor_id', '=', 'floors.id')
            ->whereNull('floors.id')
            ->count();
        if ($orphanFloors > 0) {
            $errors[] = "Có {$orphanFloors} căn hộ không thuộc Floor hợp lệ.";
        }

        // 4. Kiểm tra Cư dân (Residents)
        $this->line('▶ 4. Kiểm tra hồ sơ cư dân (Residents >= 20)...');
        $residentCount = DB::table('residents')->count();
        if ($residentCount < 20) {
            $errors[] = "Số lượng cư dân ({$residentCount}) chưa đạt yêu cầu tối thiểu (>= 20 cư dân).";
        }

        $orphanResidents = DB::table('residents')
            ->leftJoin('users', 'residents.user_id', '=', 'users.id')
            ->whereNull('users.id')
            ->count();
        if ($orphanResidents > 0) {
            $errors[] = "Có {$orphanResidents} cư dân mồ côi (không liên kết với User).";
        }

        // 5. Kiểm tra Hóa đơn (Invoices >= 40)
        $this->line('▶ 5. Kiểm tra Hóa đơn và tính toán số dư nợ...');
        $invoiceCount = DB::table('invoices')->count();
        if ($invoiceCount < 40) {
            $errors[] = "Số lượng hóa đơn ({$invoiceCount}) chưa đạt yêu cầu tối thiểu (>= 40).";
        }

        $negativeBalanceCount = DB::table('invoices')->where('remaining_balance', '<', 0)->count();
        if ($negativeBalanceCount > 0) {
            $errors[] = "Phát hiện {$negativeBalanceCount} hóa đơn có số dư nợ âm.";
        }

        // 6. Kiểm tra Tiện ích & Lịch đặt (Amenities >= 6, Bookings >= 20)
        $this->line('▶ 6. Kiểm tra Tiện ích và Lịch đặt chỗ...');
        $amenityCount = DB::table('amenities')->count();
        if ($amenityCount < 6) {
            $errors[] = "Số lượng tiện ích ({$amenityCount}) chưa đạt yêu cầu tối thiểu (>= 6 tiện ích).";
        }

        $bookingCount = DB::table('amenity_bookings')->count();
        if ($bookingCount < 20) {
            $errors[] = "Số lượng lượt đặt tiện ích ({$bookingCount}) chưa đạt yêu cầu tối thiểu (>= 20 booking).";
        }

        // 7. Kiểm tra Phản ánh (Tickets >= 30)
        $this->line('▶ 7. Kiểm tra Phản ánh / Yêu cầu kỹ thuật (Tickets >= 30)...');
        $ticketCount = DB::table('tickets')->count();
        if ($ticketCount < 30) {
            $errors[] = "Số lượng ticket ({$ticketCount}) chưa đạt yêu cầu tối thiểu (>= 30 ticket).";
        }

        // 8. Kiểm tra Khách viếng thăm (Visitors >= 20)
        $this->line('▶ 8. Kiểm tra Khách viếng thăm (Visitors >= 20)...');
        $visitorCount = DB::table('visitor_registrations')->count();
        if ($visitorCount < 20) {
            $errors[] = "Số lượng đăng ký khách ({$visitorCount}) chưa đạt yêu cầu tối thiểu (>= 20 visitor).";
        }

        // 9. Kiểm tra Thông báo (Notifications >= 30)
        $this->line('▶ 9. Kiểm tra Thông báo trong ứng dụng (Notifications >= 30)...');
        $notifCount = DB::table('user_in_app_notifications')->count();
        if ($notifCount < 30) {
            $errors[] = "Số lượng thông báo ({$notifCount}) chưa đạt yêu cầu tối thiểu (>= 30 notification).";
        }

        // =========================================================================
        // THỐNG KÊ TỔNG HỢP SỐ LƯỢNG BẢN GHI
        // =========================================================================
        $this->newLine();
        $this->info('📊 THỐNG KÊ TỔNG QUAN BẢN GHI:');
        $this->table(
            ['Thành phần dữ liệu', 'Số lượng hiện tại', 'Tiêu chuẩn yêu cầu'],
            [
                ['Roles', DB::table('roles')->count(), '>= 7 roles'],
                ['Permissions', DB::table('permissions')->count(), '>= 20 permissions'],
                ['Users', DB::table('users')->count(), '>= 20 users'],
                ['Blocks', DB::table('blocks')->count(), '>= 2 blocks'],
                ['Floors', DB::table('floors')->count(), '>= 10 floors'],
                ['Apartments', $aptCount, '>= 80 apartments'],
                ['Residents', $residentCount, '>= 20 residents'],
                ['Invoices', $invoiceCount, '>= 40 invoices'],
                ['Invoice Items', DB::table('invoice_items')->count(), '>= 100 items'],
                ['Amenities', $amenityCount, '>= 6 amenities'],
                ['Amenity Bookings', $bookingCount, '>= 20 bookings'],
                ['Tickets', $ticketCount, '>= 30 tickets'],
                ['Visitor Registrations', $visitorCount, '>= 20 visitors'],
                ['In-App Notifications', $notifCount, '>= 30 notifications'],
            ]
        );

        if (! empty($errors)) {
            $this->newLine();
            $this->error('❌ PHÁT HIỆN CÁC LỖI DỮ LIỆU CẦN KHẮC PHỤC:');
            foreach ($errors as $idx => $err) {
                $this->error(sprintf(' [%d] %s', $idx + 1, $err));
            }

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('========================================================================');
        $this->info('Demo data verification passed.');
        $this->info('========================================================================');

        return Command::SUCCESS;
    }
}
