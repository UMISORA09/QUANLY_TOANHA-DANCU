<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnnouncementPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['VIEW', 'CREATE', 'UPDATE', 'PUBLISH', 'DELETE'] as $action) {
            $permission = Permission::firstOrCreate(['permission_code' => 'ANNOUNCEMENT:'.$action], ['module' => 'ANNOUNCEMENT', 'permission_name' => 'Bảng tin '.$action]);
            foreach (Role::whereIn('role_code', ['BUILDING_MANAGER', 'SUPER_ADMIN', 'SUPER_ADMI'])->get() as $role) {
                if (! DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->exists()) {
                    DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_id' => $permission->id, 'created_at' => now()]);
                }
            }
        }
    }
}
