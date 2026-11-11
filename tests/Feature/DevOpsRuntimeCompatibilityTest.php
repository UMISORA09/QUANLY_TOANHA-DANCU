<?php

namespace Tests\Feature;

use App\Http\Controllers\DevOpsApiController;
use App\Http\Middleware\AuthenticateBearer;
use App\Models\User;
use App\Services\Cicd\GitHubActionsService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Mockery;

class DevOpsRuntimeCompatibilityTest extends TestCase
{
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        foreach (['APP_VERSION', 'COMMIT_SHA', 'VERCEL_GIT_COMMIT_SHA', 'VERCEL_GIT_COMMIT_REF'] as $key) {
            $this->previousEnvironment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = '';
        }
        $service = Mockery::mock(GitHubActionsService::class);
        $service->shouldReceive('getOverview')->zeroOrMoreTimes()->andReturn([]);
        $service->shouldReceive('getSystemHealth')->zeroOrMoreTimes()->andReturn(['metrics' => ['db_ping_ms' => 2, 'disk_usage_percent' => 40]]);
        $this->app->instance(GitHubActionsService::class, $service);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => [$environment, $server]) {
            if ($environment === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $environment;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
        parent::tearDown();
    }

    public function test_internal_endpoints_require_authentication_but_public_routes_stay_public(): void
    {
        foreach (['status', 'metrics', 'deployments', 'incidents'] as $endpoint) {
            $this->getJson('/api/devops/'.$endpoint)->assertUnauthorized();
        }
        $this->getJson('/api/public/incidents')->assertOk();
    }

    public function test_resident_cannot_use_internal_devops_endpoints(): void
    {
        $this->withoutMiddleware(AuthenticateBearer::class);
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isSuperAdmin')->andReturn(false);
        $user->shouldReceive('hasPermission')->with('CICD:VIEW')->andReturn(false);
        $this->actingAs($user);
        foreach (['status', 'metrics', 'deployments', 'incidents'] as $endpoint) {
            $this->getJson('/api/devops/'.$endpoint)->assertForbidden();
        }
    }

    public function test_authorized_viewer_receives_metrics_without_shell_commands(): void
    {
        $this->withoutMiddleware(AuthenticateBearer::class);
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isSuperAdmin')->andReturn(false);
        $user->shouldReceive('hasPermission')->with('CICD:VIEW')->andReturn(true);
        $this->actingAs($user);
        Process::fake();
        $this->getJson('/api/devops/metrics')->assertOk()->assertJsonPath('data.cpu_percent', null)->assertJsonPath('data.ram_percent', null)->assertJsonPath('data.api_performance.db_ping_ms', 2);
        Process::assertNothingRan();
    }

    public function test_vercel_metadata_avoids_git_and_preserves_release_values(): void
    {
        $_ENV['COMMIT_SHA'] = $_SERVER['COMMIT_SHA'] = 'abcdef1234567890';
        $_ENV['VERCEL_GIT_COMMIT_REF'] = $_SERVER['VERCEL_GIT_COMMIT_REF'] = 'master';
        $_ENV['APP_VERSION'] = $_SERVER['APP_VERSION'] = 'release-qa';
        Process::fake();
        $data = app(DevOpsApiController::class)->status()->getData(true)['data']['current_release'];
        $this->assertSame('abcdef1', $data['commit']);
        $this->assertSame('master', $data['branch']);
        $this->assertSame('release-qa', $data['version']);
        Process::assertNothingRan();
    }

    public function test_local_git_uses_argument_arrays_instead_of_posix_shell_redirection(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result(output: $process->command === ['git', 'rev-parse', 'HEAD'] ? '1234567890abcdef' : 'DangNguyen/CI-CD'));
        $data = app(DevOpsApiController::class)->status()->getData(true)['data']['current_release'];
        $this->assertSame('1234567', $data['commit']);
        $this->assertSame('sha-1234567', $data['version']);
        $this->assertSame('DangNguyen/CI-CD', $data['branch']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', 'rev-parse', 'HEAD'] && $process->path === base_path() && $process->timeout === 2);
    }

    public function test_missing_git_or_disabled_process_execution_does_not_break_status(): void
    {
        Process::fake(fn () => throw new \RuntimeException('Process unavailable'));
        $data = app(DevOpsApiController::class)->status()->getData(true)['data']['current_release'];
        $this->assertSame('unknown', $data['commit']);
        $this->assertSame('unknown', $data['branch']);
        $this->assertSame('unknown', $data['version']);
    }
}
