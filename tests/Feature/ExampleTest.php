<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'session.driver' => 'array']);

        $response = $this->get('/');

        $response->assertOk()->assertSee('SMART CASSAVAS');

        $homeResponse = $this->get('/home');
        $homeResponse->assertStatus(200);
    }
}
