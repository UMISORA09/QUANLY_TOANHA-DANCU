<?php

namespace Tests\Feature;

use Tests\TestCase;

class DevAuthTest extends TestCase
{
    /**
     * Test Dev and Admin login routes return successful view responses.
     */
    public function test_dev_routes_return_successful_response(): void
    {
        $this->withoutVite();

        $responseLogin = $this->get('/dev/login');
        $responseLogin->assertStatus(200);

        $responseAdminLogin = $this->get('/admin/login');
        $responseAdminLogin->assertStatus(200);

        $responseDev = $this->get('/dev');
        $responseDev->assertStatus(200);
    }
}
