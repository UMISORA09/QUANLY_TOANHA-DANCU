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

    public function test_get_default_branch_respects_explicit_env_configuration(): void
    {
        putenv('GIT_DEPLOY_BRANCH=release-2026');

        $service = new GitHubActionsService;
        $this->assertEquals('release-2026', $service->getDefaultBranch());

        putenv('GIT_DEPLOY_BRANCH'); // unset
    }

    public function test_get_default_branch_falls_back_to_master_not_main(): void
    {
        putenv('GIT_DEPLOY_BRANCH');
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
            ], 200),
        ]);

        $service = new GitHubActionsService;
        $deployments = $service->getDeployments();

        $this->assertCount(3, $deployments);

        // Deployment 101: success -> healthy, deployed_at = 2026-09-30T01:05:00Z
        $this->assertEquals('healthy', $deployments[0]['status']);
        $this->assertEquals('2026-09-30T01:05:00Z', $deployments[0]['deployed_at']);

        // Deployment 102: in_progress -> deploying, NOT healthy!
        $this->assertEquals('deploying', $deployments[1]['status']);
        $this->assertEquals('Đang triển khai', $deployments[1]['deployed_at']);

        // Deployment 103: failure -> failed, NOT healthy!
        $this->assertEquals('failed', $deployments[2]['status']);
        $this->assertEquals('Chưa hoàn tất', $deployments[2]['deployed_at']);
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
}
