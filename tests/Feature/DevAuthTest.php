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

        $responseDev = $this->get('/dev');
        $responseDev->assertStatus(200);

        $responseAdmin = $this->get('/admin');
        $responseAdmin->assertStatus(200);
    }
}
