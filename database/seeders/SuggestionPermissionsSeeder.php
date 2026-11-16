<?php

namespace Database\Seeders;

use App\Models\Block;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SuggestionBox;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SuggestionPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Permissions
        $actions = [
            'VIEW' => 'Xem danh sách và chi tiết hòm thư góp ý',
            'REPLY' => 'Tiếp nhận, xử lý trạng thái và phản hồi chính thức góp ý',
            'MANAGE' => 'Quản lý, tạo và cấu hình hòm thư góp ý',
        ];

        foreach ($actions as $action => $desc) {
            $code = 'SUGGESTION:'.$action;
            $permission = Permission::firstOrCreate(
                ['permission_code' => $code],
                [
                    'module' => 'SUGGESTION',
                    'permission_name' => 'Góp ý '.$action,
                    'description' => $desc,
                ]
            );

            // Assign to management roles
            $roles = Role::whereIn('role_code', ['BUILDING_MANAGER', 'SUPER_ADMIN', 'SUPER_ADMI', 'ADMIN', 'DEVELOPER'])->get();
            foreach ($roles as $role) {
                if (! DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->exists()) {
                    DB::table('role_permissions')->insert([
                        'id' => (string) Str::uuid(),
                        'role_id' => $role->id,
                        'permission_id' => $permission->id,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        // 2. Seed initial default suggestion boxes if none exist
        if (SuggestionBox::count() === 0) {
            // General box for entire building
            SuggestionBox::create([
                'id' => (string) Str::uuid(),
                'box_name' => 'Hòm thư Góp ý Chung - Toàn khu đô thị',
                'block_id' => null,
                'description' => 'Tiếp nhận mọi ý kiến đóng góp xây dựng văn minh đô thị và nâng cao chất lượng dịch vụ.',
                'is_open' => true,
            ]);

            // Boxes for each block
            $blocks = Block::all();
            foreach ($blocks as $block) {
                SuggestionBox::create([
                    'id' => (string) Str::uuid(),
                    'box_name' => 'Hòm thư Góp ý - '.$block->block_name,
                    'block_id' => $block->id,
                    'description' => 'Tiếp nhận ý kiến góp ý riêng cho cư dân sinh sống tại '.$block->block_name.'.',
                    'is_open' => true,
                ]);
            }
        }
    }
}
