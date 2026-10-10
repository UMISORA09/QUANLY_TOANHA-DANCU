<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class VercelProxyTest extends TestCase
{
    public function test_vercel_entrypoint_preserves_the_api_prefix_for_get_and_post_requests(): void
    {
        foreach ([
            ['GET', '/api/v1/resident/amenities?apartment_id=example', 401],
            ['POST', '/api/v1/resident/amenity-bookings', 401],
            ['POST', '/api/v1/auth/login', 422],
        ] as [$method, $uri, $status]) {
            $process = new Process([
                PHP_BINARY, '-r', <<<'PHP'
                $_SERVER['REQUEST_METHOD'] = $argv[1];
                $_SERVER['REQUEST_URI'] = $argv[2];
                $_SERVER['SCRIPT_FILENAME'] = $argv[3];
                $_SERVER['SCRIPT_NAME'] = '/api/index.php';
                $_SERVER['PHP_SELF'] = '/api/index.php';
                $_SERVER['HTTP_HOST'] = 'vercel-preview.example';
                $_SERVER['HTTP_ACCEPT'] = 'application/json';
                $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
                require $argv[3];
                fwrite(STDERR, 'HTTP_STATUS='.http_response_code());
                PHP,
                $method, $uri, base_path('api/index.php'),
            ], base_path(), [
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'false',
                'APP_CONFIG_CACHE' => sys_get_temp_dir().'/vercel-entrypoint-test-config.php',
                'APP_ROUTES_CACHE' => sys_get_temp_dir().'/vercel-entrypoint-test-routes.php',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'DB_DATABASE' => 'quanly_toanha_booking_test',
            ]);
            $process->mustRun();

            $this->assertStringContainsString('HTTP_STATUS='.$status, $process->getErrorOutput(), $method.' '.$uri);
            $this->assertIsArray(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

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
