<?php

namespace Tests\Unit;

use App\Services\Cicd\GitHubActionsService;
use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class FreshnessServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_classify_state_returns_fresh_when_age_is_less_than_warning(): void
    {
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);

        // Warning = 60s, Critical = 300s, Age = 30s => FRESH
        $state = $service->classifyState(30, 60, 300);
        $this->assertEquals(FreshnessService::STATE_FRESH, $state);
    }

    public function test_classify_state_returns_stale_when_age_is_between_warning_and_critical(): void
    {
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);

        // Warning = 60s, Critical = 300s, Age = 60s => STALE
        $stateExact = $service->classifyState(60, 60, 300);
        $this->assertEquals(FreshnessService::STATE_STALE, $stateExact);

        // Age = 120s => STALE
        $stateMid = $service->classifyState(120, 60, 300);
        $this->assertEquals(FreshnessService::STATE_STALE, $stateMid);
    }

    public function test_classify_state_returns_critical_when_age_is_greater_than_or_equal_to_critical(): void
    {
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);

        // Warning = 60s, Critical = 300s, Age = 300s => CRITICAL
        $stateExact = $service->classifyState(300, 60, 300);
        $this->assertEquals(FreshnessService::STATE_CRITICAL, $stateExact);

        // Age = 500s => CRITICAL
        $stateOver = $service->classifyState(500, 60, 300);
        $this->assertEquals(FreshnessService::STATE_CRITICAL, $stateOver);
    }

    public function test_derive_overall_status_prioritizes_critical_and_unavailable(): void
    {
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);

        // If one is CRITICAL, overall is CRITICAL
        $this->assertEquals(
            FreshnessService::STATE_CRITICAL,
            $service->deriveOverallStatus(['FRESH', 'FRESH', 'CRITICAL', 'STALE'])
        );

        // If no CRITICAL, but UNAVAILABLE exists, overall is UNAVAILABLE
        $this->assertEquals(
            FreshnessService::STATE_UNAVAILABLE,
            $service->deriveOverallStatus(['FRESH', 'STALE', 'UNAVAILABLE'])
        );

        // If only STALE and FRESH, overall is STALE
        $this->assertEquals(
            FreshnessService::STATE_STALE,
            $service->deriveOverallStatus(['FRESH', 'STALE', 'FRESH'])
        );

        // If all known are FRESH, overall is FRESH
        $this->assertEquals(
            FreshnessService::STATE_FRESH,
            $service->deriveOverallStatus(['FRESH', 'FRESH', 'UNKNOWN'])
        );

        // If all are UNKNOWN, overall is UNKNOWN
        $this->assertEquals(
            FreshnessService::STATE_UNKNOWN,
            $service->deriveOverallStatus(['UNKNOWN', 'UNKNOWN'])
        );
    }

    public function test_evaluate_collector_freshness_tracks_age_and_success(): void
    {
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);
        $now = Carbon::now('UTC');

        $result = $service->evaluateCollectorFreshness($now);

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('age_seconds', $result);
        $this->assertArrayHasKey('warning_threshold', $result);
        $this->assertArrayHasKey('critical_threshold', $result);
        $this->assertContains($result['status'], [
            FreshnessService::STATE_FRESH,
            FreshnessService::STATE_STALE,
            FreshnessService::STATE_CRITICAL,
        ]);
    }

    public function test_evaluate_github_freshness_handles_empty_pipelines_truthfully(): void
    {
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $cicdService->shouldReceive('getPipelines')
            ->once()
            ->andReturn([]);

        $service = new FreshnessService($cicdService);
        $now = Carbon::now('UTC');

        $result = $service->evaluateGitHubFreshness($now, true);

        // Empty runs MUST return UNKNOWN, never fake FRESH or OK
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $result['status']);
        $this->assertNull($result['age_seconds']);
        $this->assertNull($result['last_event_at']);
    }

    public function test_evaluate_github_freshness_computes_age_accurately_from_source_timestamp(): void
    {
        $now = Carbon::parse('2026-09-24T14:00:00Z');
        $runTime = Carbon::parse('2026-09-24T13:58:30Z'); // 90 seconds ago

        $cicdService = Mockery::mock(GitHubActionsService::class);
        $cicdService->shouldReceive('getPipelines')
            ->once()
            ->andReturn([
                [
                    'name' => 'CI - Continuous Integration',
                    'updated_at' => $runTime->toIso8601String(),
                    'created_at' => $runTime->toIso8601String(),
                    'commit_sha' => 'abc1234',
                ],
            ]);

        $service = new FreshnessService($cicdService);

        $result = $service->evaluateGitHubFreshness($now, true);

        $this->assertEquals(90, $result['age_seconds']);
        $this->assertEquals(FreshnessService::STATE_FRESH, $result['status']); // default warning is 3600s
        $this->assertEquals($runTime->toIso8601String(), $result['last_event_at']);
    }

    public function test_evaluate_github_freshness_handles_api_failure_as_unavailable(): void
    {
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $cicdService->shouldReceive('getPipelines')
            ->once()
            ->andThrow(new \RuntimeException('GitHub API 429 Rate Limit Exceeded'));

        $service = new FreshnessService($cicdService);
        $now = Carbon::now('UTC');

        $result = $service->evaluateGitHubFreshness($now, true);

        // API failure MUST return UNAVAILABLE, never fake FRESH
        $this->assertEquals(FreshnessService::STATE_UNAVAILABLE, $result['status']);
        $this->assertNull($result['age_seconds']);
        $this->assertStringContainsString('Rate Limit', $result['error']);
    }
}
