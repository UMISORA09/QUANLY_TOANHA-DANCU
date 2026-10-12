<?php

namespace Tests\Unit;

use App\Services\Cicd\GitHubActionsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubActionsServiceTest extends TestCase
{
    public function test_jobs_include_all_pages_and_actual_step_statuses(): void
    {
        config(['services.github.token' => 'test-token', 'services.github.owner' => 'test-owner', 'services.github.repo' => 'test-repo']);
        $job = ['id' => 456, 'name' => 'Build', 'status' => 'in_progress', 'conclusion' => null, 'steps' => [
            ['name' => 'Checkout', 'number' => 1, 'status' => 'completed', 'conclusion' => 'success'],
            ['name' => 'Test', 'number' => 2, 'status' => 'in_progress', 'conclusion' => null],
            ['name' => 'Deploy', 'number' => 3, 'status' => 'completed', 'conclusion' => 'skipped'],
        ]];
        Http::fake(function ($request) use ($job) {
            $this->assertSame('latest', $request['filter']);
            $this->assertEquals(100, $request['per_page']);

            return Http::response(['total_count' => 101, 'jobs' => $request['page'] === 1 ? array_fill(0, 100, $job) : [array_replace($job, ['id' => 457, 'status' => 'waiting'])]]);
        });
        $jobs = (new GitHubActionsService)->getPipelineJobs('123');
        $this->assertCount(101, $jobs);
        $this->assertSame(['success', 'running', 'skipped'], array_column($jobs[0]['steps'], 'status'));
        $this->assertSame('queued', $jobs[100]['status']);
        $this->assertNull($jobs[0]['started_at']);
        Http::assertSentCount(2);
    }

    public function test_jobs_api_failure_is_not_reported_as_an_empty_workflow(): void
    {
        config(['services.github.token' => 'test-token']);
        Http::fake(['*' => Http::response(['message' => 'Rate limited'], 403)]);
        $this->expectException(RequestException::class);
        (new GitHubActionsService)->getPipelineJobs('123');
    }

    public function test_step_logs_use_the_requested_job_and_zero_based_step_position(): void
    {
        config(['services.github.token' => 'test-token']);
        $service = $this->getMockBuilder(GitHubActionsService::class)->onlyMethods(['getPipelineJobs'])->getMock();
        $service->method('getPipelineJobs')->with('123')->willReturn([['id' => '456', 'steps' => [['number' => 1, 'name' => 'Checkout']]]]);
        Http::fake(['*/actions/jobs/456/steps/0/logs' => Http::response('Checkout log')]);
        $this->assertStringContainsString('Checkout log', $service->getLogs('123', '456', 0));
        $this->assertStringContainsString('Không tìm thấy bước', $service->getLogs('123', '999', 0));
        $this->assertStringContainsString('Không tìm thấy bước', $service->getLogs('123', '456', 2));
        Http::assertSentCount(1);
    }

    public function test_unavailable_step_logs_fall_back_to_the_selected_job_with_an_explicit_notice(): void
    {
        config(['services.github.token' => 'test-token']);
        $service = $this->getMockBuilder(GitHubActionsService::class)->onlyMethods(['getPipelineJobs'])->getMock();
        $service->method('getPipelineJobs')->with('123')->willReturn([['id' => '456', 'steps' => [['number' => 1, 'name' => 'Checkout']]]]);
        Http::fake([
            '*/actions/jobs/456/steps/0/logs' => Http::response([], 404),
            '*/actions/jobs/456/logs' => Http::response('Actual selected job log'),
        ]);
        $logs = $service->getLogs('123', '456', 0);
        $this->assertStringContainsString('chưa cung cấp log riêng', $logs);
        $this->assertStringContainsString('Actual selected job log', $logs);
        Http::assertSentCount(2);
    }

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

    public function test_pipeline_api_reports_missing_token_empty_runs_http_errors_and_timeout(): void
    {
        $status = 200;
        Http::fake(function () use (&$status) {
            if ($status === 0) {
                throw new ConnectionException('Request timed out');
            }

            return Http::response(['workflow_runs' => []], $status);
        });
        config(['services.github.token' => null]);
        $this->assertSame('token_missing', (new GitHubActionsService)->getPipelinesWithStatus()['status']);
        Http::assertNothingSent();

        config(['services.github.token' => 'test-token']);
        foreach ([401, 403, 500, 200, 0] as $status) {
            $result = (new GitHubActionsService)->getPipelinesWithStatus();
            $this->assertSame($status === 200 ? 'empty_runs' : 'api_unavailable', $result['status']);
            if ($status >= 400) {
                $this->assertStringContainsString((string) $status, $result['reason']);
            }
        }
    }

    public function test_unknown_pipeline_id_does_not_return_a_different_run(): void
    {
        config(['services.github.token' => null]);
        $service = $this->getMockBuilder(GitHubActionsService::class)->onlyMethods(['getPipelines'])->getMock();
        $service->method('getPipelines')->willReturn([['id' => '123', 'name' => 'Actual run']]);
        $this->assertNull($service->getPipelineDetail('999'));
        $this->assertSame('123', $service->getPipelineDetail('123')['id']);
    }

    public function test_history_reads_old_pages_and_keeps_real_activity_statuses(): void
    {
        config(['services.github.token' => 'test-token']);
        $run = [
            'id' => 999, 'run_number' => 1, 'name' => 'Vercel CD', 'path' => '.github/workflows/vercel.yml',
            'status' => 'completed', 'conclusion' => 'cancelled', 'head_branch' => 'master',
            'head_sha' => 'abcdef123456', 'head_commit' => ['message' => 'Old commit', 'author' => ['name' => 'QA']],
            'actor' => ['login' => 'dependabot[bot]'], 'event' => 'push',
            'created_at' => '2025-01-01T00:00:00Z', 'updated_at' => '2025-01-01T00:01:00Z',
        ];
        Http::fake([
            '*/actions/runs/999' => Http::response($run),
            '*/actions/runs?*' => Http::response(['total_count' => 1501, 'workflow_runs' => [$run]]),
            '*/commits?*' => Http::response([[
                'sha' => '1234567890abcdef', 'author' => ['login' => 'octocat'],
                'commit' => ['author' => ['name' => 'QA', 'date' => '2025-01-01T00:00:00Z'], 'message' => 'Old commit'],
            ]]),
        ]);
        $service = new GitHubActionsService;
        $result = $service->getHistoryPage(15);
        $this->assertSame(1501, $result['total_pipelines']);
        $this->assertSame('dependabot[bot]', $result['pipelines'][0]['author']);
        $this->assertTrue($result['has_more']);
        $this->assertSame('deploy', $result['activities'][0]['type']);
        $this->assertSame('cancelled', $result['activities'][0]['status']);
        $this->assertSame('recorded', $result['activities'][1]['status']);
        $this->assertSame('commit', $result['activities'][1]['type']);
        Http::assertSent(fn ($request) => $request['page'] === 15 && $request['per_page'] === 100);
        $this->assertSame($result, $service->getHistoryPage(15));
        Http::assertSentCount(2);
        $service->getHistoryPage(15, true);
        Http::assertSentCount(4);
        $this->assertSame('999', $service->getPipelineDetail('999')['id']);
    }

    public function test_failed_history_request_is_not_cached_as_empty_history(): void
    {
        config(['services.github.token' => 'test-token']);
        Http::fake(['*' => Http::response(['message' => 'Rate limited'], 403)]);
        $this->expectException(RequestException::class);
        (new GitHubActionsService)->getHistoryPage(1);
    }
}
