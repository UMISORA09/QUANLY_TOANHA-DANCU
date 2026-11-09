<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RestoreDemoAccountsSeeder extends Seeder
{
    /**
     * Nạp phục hồi đúng các tài khoản demo bị thiếu theo yêu cầu của hệ thống:
     * - admin@cassavas.vn (SUPER_ADMIN)
     * - quanly@cassavas.vn (BUILDING_MANAGER)
     * - letan@cassavas.vn (RECEPTIONIST)
     * - nguyenvanan@cassavas.vn (RESIDENT_OWNER)
     */
    public function run(): void
    {
        $now = now();
        $hash = Hash::make('123567');

        $demoUsers = [
            [
                'id' => '35f553dc-a206-4200-a3e5-8c054db0c9d6',
                'username' => 'admin',
                'email' => 'admin@cassavas.vn',
                'phone_number' => '0900000001',
                'full_name' => 'Admin Cassavas',
                'gender' => 'MALE',
                'role_code' => 'SUPER_ADMIN',
            ],
            [
                'id' => '835115e3-40b4-447b-94d7-b0d8660ac221',
                'username' => 'quanly',
                'email' => 'quanly@cassavas.vn',
                'phone_number' => '0900000002',
                'full_name' => 'Ban Quản Lý Cassavas',
                'gender' => 'FEMALE',
                'role_code' => 'BUILDING_MANAGER',
            ],
            [
                'id' => '98477ee6-dd93-4275-a359-da9fc6a1dca5',
                'username' => 'letan',
                'email' => 'letan@cassavas.vn',
                'phone_number' => '0900000004',
                'full_name' => 'Lễ Tân Sảnh Chính',
                'gender' => 'FEMALE',
                'role_code' => 'RECEPTIONIST',
            ],
            [
                'id' => '0ccf6eed-ca47-40ad-ac5a-7100f723fe8d',
                'username' => 'nguyenvanan',
                'email' => 'nguyenvanan@cassavas.vn',
                'phone_number' => '0901234567',
                'full_name' => 'Nguyễn Văn An',
                'gender' => 'MALE',
                'role_code' => 'RESIDENT_OWNER',
            ],
            [
                'id' => '71b14a22-358b-4bcf-90f7-111111111111',
                'username' => 'accountant',
                'email' => 'accountant@cassavas.vn',
                'phone_number' => '0900000003',
                'full_name' => 'Kế Toán Tòa Nhà',
                'gender' => 'FEMALE',
                'role_code' => 'ACCOUNTANT',
            ],
            [
                'id' => '71b14a22-358b-4bcf-90f7-222222222222',
                'username' => 'kythuat',
                'email' => 'kythuat@cassavas.vn',
                'phone_number' => '0900000005',
                'full_name' => 'Kỹ Thuật Viên Otis',
                'gender' => 'MALE',
                'role_code' => 'TECHNICIAN',
            ],
        ];

        DB::transaction(function () use ($demoUsers, $now, $hash) {
            foreach ($demoUsers as $item) {
                // Đảm bảo không trùng lặp id hoặc username/email
                $existing = User::where('username', $item['username'])
                    ->orWhere('email', $item['email'])
                    ->first();

                $userId = $existing ? $existing->id : $item['id'];

                DB::table('users')->updateOrInsert(
                    ['id' => $userId],
                    [
                        'username' => $item['username'],
                        'email' => $item['email'],
                        'phone_number' => $item['phone_number'],
                        'full_name' => $item['full_name'],
                        'gender' => $item['gender'],
                        'password_hash' => $hash,
                        'status' => 'ACTIVE',
                        'updated_at' => $now,
                    ]
                );

                // Gán đúng role trong bảng user_roles
                $role = Role::whereIn('role_code', [$item['role_code'], rtrim($item['role_code'], 'N')])->first();
                if ($role) {
                    DB::table('user_roles')->updateOrInsert(
                        ['user_id' => $userId, 'role_id' => $role->id],
                        [
                            'id' => (string) Str::uuid(),
                            'is_primary' => 1,
                            'assigned_at' => $now,
                        ]
                    );
                }
            }

            // Gắn cư dân nguyenvanan vào căn hộ A-1204 nếu có
            $apartment = DB::table('apartments')->where('apartment_number', 'A-1204')->first();
            if ($apartment) {
                DB::table('residents')->updateOrInsert(
                    ['user_id' => '0ccf6eed-ca47-40ad-ac5a-7100f723fe8d', 'apartment_id' => $apartment->id],
                    [
                        'id' => (string) Str::uuid(),
                        'resident_type' => 'OWNER',
                        'is_head_of_household' => 1,
                        'stay_start_date' => '2026-01-01',
                        'relationship_to_head' => 'SELF',
                        'vehicle_count' => 1,
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        });
    }
}
