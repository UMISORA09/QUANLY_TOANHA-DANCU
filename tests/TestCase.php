<?php

namespace Tests;

use App\Services\QuocTinRealtimeService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }

    protected function tearDown(): void
    {
        foreach (['rbac', 'residents', 'temporary_registrations', 'account_provisioning', 'vehicles'] as $mod) {
            QuocTinRealtimeService::clearModuleCooldown($mod);
        }

        parent::tearDown();
    }
}
