<?php

namespace Tests\Feature;

use App\Services\Cicd\Adapters\GitHubApiAdapter;
use App\Services\Cicd\Adapters\VercelApiAdapter;
use App\Services\Cicd\GitHubActionsService;
use App\Services\Freshness\FreshnessService;
use App\Services\Freshness\Strategies\DatabaseFreshnessStrategy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class FreshnessReadOnlyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_get_dashboard_preserves_heartbeats_and_does_not_write_incidents(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        config(['cache.default' => 'array']);
        DB::table('freshness_heartbeats')->delete();
        $cicd = Mockery::mock(GitHubActionsService::class);
        $cicd->shouldReceive('getPipelinesWithStatus')->andReturn(['status' => 'token_missing', 'runs' => []]);
        $cicd->shouldReceive('getDeployments')->andReturn([]);
        $this->app->instance(GitHubActionsService::class, $cicd);
        $service = app(FreshnessService::class);
        $oldTimestamp = now()->subHour();
        $service->recordCollectorHeartbeat($oldTimestamp);
        $heartbeats = DB::table('freshness_heartbeats')->get()->toJson();
        $incidents = DB::table('freshness_incidents')->get()->toJson();
        $first = $this->getJson('/api/monitoring/freshness')->assertOk();
        $second = $this->getJson('/api/monitoring/freshness?force=1')->assertOk();
        $this->assertSame($first->json('collector.source_timestamp'), $second->json('collector.source_timestamp'));
        $this->assertSame('UNKNOWN', $second->json('application_health.status'));
        $this->assertSame($heartbeats, DB::table('freshness_heartbeats')->get()->toJson());
        $this->assertSame($incidents, DB::table('freshness_incidents')->get()->toJson());
        $service->getOverview(true, true);
        $this->assertGreaterThan(0, DB::table('freshness_incidents')->where('source', 'collector')->whereNull('resolved_at')->count());
    }

    public function test_deployment_api_failure_is_unavailable_without_a_timestamp(): void
    {
        $cicd = Mockery::mock(GitHubActionsService::class);
        $cicd->shouldReceive('getDeployments')->andReturn([['status' => 'unavailable', 'error' => 'GitHub Deployments API unavailable']]);
        $result = (new FreshnessService($cicd))->evaluateDeploymentFreshness(Carbon::now(), true);
        $this->assertSame('UNAVAILABLE', $result['status']);
        $this->assertNull($result['source_timestamp']);
        $this->assertNull($result['age_seconds']);
    }

    public function test_database_freshness_does_not_hide_a_missing_source(): void
    {
        $strategy = new DatabaseFreshnessStrategy(['data_sources' => [
            'real' => ['table' => 'roles', 'timestamp_field' => 'created_at'],
            'missing' => ['table' => 'nonexistent_monitoring_source'],
        ]]);
        $result = $strategy->evaluate(Carbon::now(), true);
        $this->assertSame('UNKNOWN', $result['status']);
        $this->assertSame('UNKNOWN', $result['sources'][1]['status']);
        $this->assertNull($result['sources'][1]['source_timestamp']);
    }

    public function test_vercel_api_failure_is_not_an_empty_success(): void
    {
        Http::fake(['api.vercel.com/*' => Http::response([], 503)]);
        $this->expectException(\RuntimeException::class);
        (new VercelApiAdapter('test-token', 'test-project'))->getDeployments();
    }

    public function test_github_deployment_api_failure_is_not_an_empty_success(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 403)]);
        $this->expectException(\RuntimeException::class);
        (new GitHubApiAdapter('test-owner', 'test-repo', 'test-token'))->getDeploymentsWithStatuses();
    }

    public function test_unauthorized_probe_is_not_healthy(): void
    {
        Http::fake(['https://test.example/health' => Http::response([], 401)]);
        $this->assertFalse((new VercelApiAdapter)->probeDeploymentHealth('https://test.example/health'));
    }
}
