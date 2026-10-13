<?php

namespace Tests;

use App\Models\Role;
use App\Services\QuocTinRealtimeService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
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

    protected function tearDown(): void
    {
        foreach (['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles'] as $mod) {
            QuocTinRealtimeService::clearModuleCooldown($mod);
        }

        parent::tearDown();
    }
}
