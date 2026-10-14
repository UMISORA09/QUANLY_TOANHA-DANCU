<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['BUILDING_MANAGER' => ['VIEW', 'CREATE', 'UPDATE'], 'TECHNICIAN' => ['VIEW'], 'TECHNICAL_STAFF' => ['VIEW']] as $code => $actions) {
            $role = Role::where('role_code', $code)->first();
            if (! $role) {
                continue;
            }
            foreach ($actions as $action) {
                $permission = Permission::firstOrCreate(['permission_code' => 'TICKET:'.$action], ['module' => 'TICKET', 'permission_name' => 'Ticket '.$action]);
                if (! DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->exists()) {
                    DB::table('role_permissions')->insert(['id' => (string) Str::uuid(), 'role_id' => $role->id, 'permission_id' => $permission->id, 'created_at' => now()]);
                }
            }
        }
    }
}
