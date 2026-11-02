<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use App\Services\QuocTinRealtimeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow();

        parent::setUp();

        config(['cache.default' => 'array']);

        if (method_exists($this, 'withoutVite')) {
            $this->withoutVite();
        }

        foreach (['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles'] as $mod) {
            QuocTinRealtimeService::clearModuleCooldown($mod);
        }

        if (Schema::hasTable('roles')) {
            $defaultRoles = [
                ['role_code' => 'SUPER_ADMIN', 'role_name' => 'Super Administrator', 'is_system_role' => true],
                ['role_code' => 'ADMIN', 'role_name' => 'Administrator', 'is_system_role' => true],
                ['role_code' => 'MANAGER', 'role_name' => 'Building Manager', 'is_system_role' => true],
                ['role_code' => 'RECEPTIONIST', 'role_name' => 'Receptionist', 'is_system_role' => true],
                ['role_code' => 'RESIDENT', 'role_name' => 'Resident', 'is_system_role' => true],
                ['role_code' => 'ACCOUNTANT', 'role_name' => 'Accountant', 'is_system_role' => false],
                ['role_code' => 'RESIDENT_MEMBER', 'role_name' => 'Resident Member', 'is_system_role' => false],
            ];
            foreach ($defaultRoles as $defRole) {
                Role::firstOrCreate(['role_code' => $defRole['role_code']], $defRole);
            }
        }
    }

    protected function issueTestBearerToken(User $user): string
    {
        $token = Str::random(64);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(), 'is_revoked' => 0,
        ]);

        return $token;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles'] as $mod) {
            QuocTinRealtimeService::clearModuleCooldown($mod);
        }

        parent::tearDown();
    }
}
