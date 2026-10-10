<?php

namespace Tests\Unit;

use App\Services\Cicd\GitHubActionsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubActionsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        config(['services.github.deploy_branch' => null]);
        parent::tearDown();
    }

    public function test_get_default_branch_respects_explicit_env_configuration(): void
    {
        config(['services.github.deploy_branch' => 'release-2026']);

        $service = new GitHubActionsService;
        $this->assertEquals('release-2026', $service->getDefaultBranch());

        config(['services.github.deploy_branch' => null]);
    }

    public function test_get_default_branch_falls_back_to_master_not_main(): void
    {
        config(['services.github.deploy_branch' => null]);
        config(['services.github.token' => null]);

        $service = new GitHubActionsService;
        $this->assertEquals('master', $service->getDefaultBranch());
    }

    public function test_get_deployments_maps_actual_statuses_and_completion_timestamps(): void
    {
        config(['services.github.token' => 'ghp_fake_token_for_testing']);
        config(['services.github.owner' => 'test-owner']);
        config(['services.github.repo' => 'test-repo']);

        Http::fake([
            'https://api.github.com/repos/test-owner/test-repo/deployments/101/statuses*' => Http::response([
                [
                    'state' => 'success',
                    'created_at' => '2026-09-30T01:05:00Z',
                ],
            ], 200),
            'https://api.github.com/repos/test-owner/test-repo/deployments/102/statuses*' => Http::response([
                [
                    'state' => 'in_progress',
                    'created_at' => '2026-09-30T01:11:00Z',
                ],
            ], 200),
            'https://api.github.com/repos/test-owner/test-repo/deployments/103/statuses*' => Http::response([
                [
                    'state' => 'failure',
                    'created_at' => '2026-09-30T01:22:00Z',
                ],
            ], 200),
            'https://api.github.com/repos/test-owner/test-repo/deployments/104/statuses*' => Http::response([
                [
                    'state' => 'unknown_state',
                    'created_at' => '2026-09-30T01:30:00Z',
                ],
            ], 200),
            'https://api.github.com/repos/test-owner/test-repo/deployments*' => Http::response([
                [
                    'id' => 101,
                    'environment' => 'production',
                    'sha' => '1234567890abcdef',
                    'created_at' => '2026-09-30T01:00:00Z',
                    'updated_at' => '2026-09-30T01:05:00Z',
                    'creator' => ['login' => 'octocat'],
                    'statuses_url' => 'https://api.github.com/repos/test-owner/test-repo/deployments/101/statuses',
                ],
                [
                    'id' => 102,
                    'environment' => 'preview',
                    'sha' => 'abcdef1234567890',
                    'created_at' => '2026-09-30T01:10:00Z',
                    'updated_at' => '2026-09-30T01:12:00Z',
                    'creator' => ['login' => 'octocat'],
                    'statuses_url' => 'https://api.github.com/repos/test-owner/test-repo/deployments/102/statuses',
                ],
                [
                    'id' => 103,
                    'environment' => 'production',
                    'sha' => '9999999890abcdef',
                    'created_at' => '2026-09-30T01:20:00Z',
                    'updated_at' => '2026-09-30T01:22:00Z',
                    'creator' => ['login' => 'octocat'],
                    'statuses_url' => 'https://api.github.com/repos/test-owner/test-repo/deployments/103/statuses',
                ],
                [
                    'id' => 104,
                    'environment' => 'staging',
                    'sha' => '8888888890abcdef',
                    'created_at' => '2026-09-30T01:25:00Z',
                    'updated_at' => '2026-09-30T01:30:00Z',
                    'creator' => ['login' => 'octocat'],
                    'statuses_url' => 'https://api.github.com/repos/test-owner/test-repo/deployments/104/statuses',
                ],
            ], 200),
        ]);

        $service = new GitHubActionsService;
        $deployments = $service->getDeployments();

        $this->assertCount(4, $deployments);

        // Deployment 101: success -> healthy, deployed_at = 2026-09-30T01:05:00Z
        $this->assertEquals('healthy', $deployments[0]['status']);
        $this->assertEquals('2026-09-30T01:05:00Z', $deployments[0]['deployed_at']);

        // Deployment 102: in_progress -> deploying, deployed_at = null
        $this->assertEquals('deploying', $deployments[1]['status']);
        $this->assertNull($deployments[1]['deployed_at']);

        // Deployment 103: failure -> failed, deployed_at = null
        $this->assertEquals('failed', $deployments[2]['status']);
        $this->assertNull($deployments[2]['deployed_at']);

        // Deployment 104: unknown state -> unknown, deployed_at = null
        $this->assertEquals('unknown', $deployments[3]['status']);
        $this->assertNull($deployments[3]['deployed_at']);
    }

    public function test_get_deployments_fallback_does_not_fake_healthy_without_evidence(): void
    {
        config(['services.github.token' => null]); // Disable GitHub live API

        $service = new GitHubActionsService;
        $deployments = $service->getDeployments();

        $this->assertCount(2, $deployments);
        foreach ($deployments as $dep) {
            // When live health probe is not verified, status cannot be fake healthy
            $this->assertNotEquals('healthy', $dep['status']);
            $this->assertNull($dep['deployed_at']);
        }
    }

    public function test_get_system_health_checks_search_engine_and_docker(): void
    {
        $service = new GitHubActionsService;
        $health = $service->getSystemHealth();

        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('components', $health);

        $components = collect($health['components'])->keyBy('name');

        $this->assertTrue($components->has('Vietnamese Smart Search Engine'));
        $searchComponent = $components->get('Vietnamese Smart Search Engine');
        $this->assertStringContainsString('database-backed search', $searchComponent['version']);

        $this->assertTrue($components->has('Docker Engine'));
        $dockerComponent = $components->get('Docker Engine');
        $this->assertContains($dockerComponent['status'], ['operational', 'down', 'degraded', 'not_available']);
    }

    public function test_default_logs_use_plaintext_job_endpoint_and_mask_tokens(): void
    {
        config(['services.github.token' => 'test-token']);
        $service = $this->getMockBuilder(GitHubActionsService::class)->onlyMethods(['getPipelineJobs'])->getMock();
        $service->expects($this->once())->method('getPipelineJobs')->with('123')->willReturn([['id' => '456']]);
        $secret = 'ghp_'.str_repeat('a', 36);
        Http::fake(['*/actions/jobs/456/logs' => Http::response('Build passed '.$secret)]);
        $logs = $service->getLogs('123');
        $this->assertStringContainsString('Build passed', $logs);
        $this->assertStringNotContainsString($secret, $logs);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/jobs/456/logs'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions/runs/123/logs'));
    }

    public function test_missing_jobs_do_not_download_workflow_zip(): void
    {
        config(['services.github.token' => 'test-token']);
        $service = $this->getMockBuilder(GitHubActionsService::class)->onlyMethods(['getPipelineJobs'])->getMock();
        $service->method('getPipelineJobs')->willReturn([]);
        Http::fake();
        $this->assertStringContainsString('Chưa có job', $service->getLogs('123'));
        Http::assertNothingSent();
    }

    public function test_overview_and_environments_use_actual_deployment_state(): void
    {
        $service = $this->getMockBuilder(GitHubActionsService::class)->onlyMethods([
            'getDeployments', 'getSystemHealth', 'getGitBranches', 'getPipelines', 'getRecentActivities', 'getSecurityAudit',
        ])->getMock();
        $service->method('getDeployments')->willReturn([[
            'environment' => 'production', 'status' => 'failed', 'version' => 'prod-abc1234',
            'commit_sha' => 'abc1234', 'deployed_at' => null,
        ]]);
        $service->method('getSystemHealth')->willReturn(['status' => 'unknown']);
        $service->method('getGitBranches')->willReturn([]);
        $service->method('getPipelines')->willReturn([]);
        $service->method('getRecentActivities')->willReturn([]);
        $service->method('getSecurityAudit')->willReturn([]);
        $overview = $service->getOverview();
        $this->assertSame('failed', $overview['production_status']);
        $this->assertSame('prod-abc1234', $overview['production_version']);
        $production = collect($service->getEnvironments())->firstWhere('id', 'production');
        $this->assertSame('down', $production['status']);
        $this->assertSame('abc1234', $production['commit_sha']);
        $this->assertSame('N/A', $production['last_deployment']);
    }
}
