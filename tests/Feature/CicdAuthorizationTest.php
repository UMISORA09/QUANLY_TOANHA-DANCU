<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBearer;
use App\Models\User;
use App\Services\Cicd\GitHubActionsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CicdAuthorizationTest extends TestCase
{
    public function test_cicd_endpoints_require_authentication(): void
    {
        Http::fake();
        $this->getJson('/api/admin/cicd/overview')->assertUnauthorized();
        foreach (['pipelines/run', 'pipelines/123/retry', 'pipelines/123/cancel', 'deploy', 'rollback'] as $path) {
            $this->postJson('/api/admin/cicd/'.$path)->assertUnauthorized();
        }
        Http::assertNothingSent();
    }

    public function test_forged_admin_header_does_not_grant_access(): void
    {
        Http::fake();
        $this->authenticateWithPermissions([]);
        $this->withHeader('X-User-Role', 'admin')->getJson('/api/admin/cicd/overview')->assertForbidden();
        foreach (['pipelines/run', 'pipelines/123/retry', 'pipelines/123/cancel', 'deploy', 'rollback'] as $path) {
            $this->postJson('/api/admin/cicd/'.$path)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_view_permission_does_not_allow_pipeline_mutations(): void
    {
        Http::fake();
        $this->authenticateWithPermissions(['CICD:VIEW']);
        foreach (['pipelines/run', 'pipelines/123/retry', 'pipelines/123/cancel', 'deploy', 'rollback'] as $path) {
            $this->postJson('/api/admin/cicd/'.$path)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_authorized_admin_can_read_dashboard(): void
    {
        $this->authenticateWithPermissions([], true);
        $this->mock(GitHubActionsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getOverview')->once()->andReturn(['total_pipelines' => 5]);
        });
        $this->getJson('/api/admin/cicd/overview')->assertOk()->assertJsonPath('data.total_pipelines', 5);
    }

    /** @param list<string> $permissions */
    private function authenticateWithPermissions(array $permissions, bool $isAdmin = false): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isSuperAdmin')->andReturn($isAdmin);
        $user->shouldReceive('hasPermission')->andReturnUsing(fn (string $permission): bool => in_array($permission, $permissions, true));
        $this->mock(AuthenticateBearer::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('handle')->andReturnUsing(function (Request $request, Closure $next, string $mode) use ($user) {
                $this->assertSame('strict', $mode);
                $request->setUserResolver(fn () => $user);

                return $next($request);
            });
        });
    }
}
