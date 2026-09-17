<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            [
                'module' => 'AMENITY',
                'permission_code' => 'AMENITY:VIEW',
                'permission_name' => 'Xem danh sách & tìm kiếm tiện ích',
                'description' => 'Cho phép tra cứu, tìm kiếm và xem danh sách tiện ích',
            ],
            [
                'module' => 'AMENITY',
                'permission_code' => 'AMENITY:CREATE',
                'permission_name' => 'Thêm mới tiện ích',
                'description' => 'Cho phép tạo mới tiện ích trong tòa nhà',
            ],
            [
                'module' => 'AMENITY',
                'permission_code' => 'AMENITY:UPDATE',
                'permission_name' => 'Cập nhật tiện ích',
                'description' => 'Cho phép chỉnh sửa thông tin, biểu phí, trạng thái tiện ích',
            ],
            [
                'module' => 'AMENITY',
                'permission_code' => 'AMENITY:DELETE',
                'permission_name' => 'Xóa tiện ích',
                'description' => 'Cho phép xóa hoặc đóng tiện ích vĩnh viễn',
            ],
            [
                'module' => 'AMENITY',
                'permission_code' => 'AMENITY:CONFIG_SLOT',
                'permission_name' => 'Cấu hình khung giờ & Slot đặt chỗ',
                'description' => 'Cho phép cấu hình time-slots, sức chứa tối đa và ngày bảo trì',
            ],
        ];

        foreach ($permissions as $p) {
            $exists = DB::table('permissions')->where('permission_code', $p['permission_code'])->first();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'id' => (string) Str::uuid(),
                    'module' => $p['module'],
                    'permission_code' => $p['permission_code'],
                    'permission_name' => $p['permission_name'],
                    'description' => $p['description'],
                    'created_at' => now(),
                ]);
            }
        }

        // Gán quyền cho các Roles tương ứng
        $allRoles = DB::table('roles')->get();
        $adminRoles = $allRoles->filter(fn ($r) => in_array($r->role_code, ['SUPER_ADMIN', 'SUPER_ADMI', 'BUILDING_MANAGER']));
        $viewRoles = $allRoles->filter(fn ($r) => in_array($r->role_code, [
            'SUPER_ADMIN', 'SUPER_ADMI', 'BUILDING_MANAGER', 'ACCOUNTANT', 'RECEPTIONIST',
            'SECURITY_GUARD', 'TECHNICIAN', 'TECHNICIA', 'RESIDENT_OWNER', 'RESIDENT_MEMBER', 'DEVELOPER',
        ]));

        $viewPerm = DB::table('permissions')->where('permission_code', 'AMENITY:VIEW')->first();
        if ($viewPerm) {
            foreach ($viewRoles as $role) {
                $hasPerm = DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $viewPerm->id)->exists();
                if (! $hasPerm) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $viewPerm->id,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        $adminPerms = DB::table('permissions')->whereIn('permission_code', [
            'AMENITY:CREATE', 'AMENITY:UPDATE', 'AMENITY:DELETE', 'AMENITY:CONFIG_SLOT',
        ])->get();

        foreach ($adminRoles as $role) {
            foreach ($adminPerms as $perm) {
                $hasPerm = DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $perm->id)->exists();
                if (! $hasPerm) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $perm->id,
                        'created_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $permIds = DB::table('permissions')->where('module', 'AMENITY')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->where('module', 'AMENITY')->delete();
    }
};
