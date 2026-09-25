<?php

namespace Tests\Feature;

use Tests\TestCase;

class VercelProxyTest extends TestCase
{
    public function test_assets_use_https_when_request_is_forwarded_by_vercel(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'vercel-preview.example',
        ])->get('/home');

        $response->assertOk();
        $this->assertSame('https://vercel-preview.example/build/assets/app.js', url('/build/assets/app.js'));
    }
}
