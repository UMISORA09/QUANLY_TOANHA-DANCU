<?php

namespace Tests\Unit;

use App\Services\Cicd\GitHubActionsService;
use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
        Cache::put('freshness_collector_last_success_at', Carbon::now('UTC')->toIso8601String(), 3600);
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
            FreshnessService::STATE_UNKNOWN,
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

    public function test_evaluate_health_freshness_does_not_self_refresh_when_no_observation(): void
    {
        Cache::forget('application_health_last_observed_at');

        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);
        $now = Carbon::now('UTC');

        $result = $service->evaluateHealthFreshness($now);

        // Không tự ý tạo timestamp khi chưa có observation
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $result['status']);
        $this->assertNull($result['last_observed_at']);
        $this->assertNull(Cache::get('application_health_last_observed_at'));
    }

    public function test_evaluate_health_freshness_reads_stored_observation_without_mutating_timestamp(): void
    {
        $observedAt = Carbon::now('UTC')->subSeconds(30)->toIso8601String();
        Cache::put('application_health_last_observed_at', $observedAt, 300);

        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);
        $now = Carbon::now('UTC');

        $result = $service->evaluateHealthFreshness($now);

        $this->assertEquals(FreshnessService::STATE_FRESH, $result['status']);
        $this->assertEquals($observedAt, $result['last_observed_at']);
        // Cache không bị ghi đè thành $now
        $this->assertEquals($observedAt, Cache::get('application_health_last_observed_at'));
    }

    public function test_evaluate_deployment_freshness_ignores_in_progress_deployments(): void
    {
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $cicdService->shouldReceive('getDeployments')
            ->once()
            ->andReturn([
                [
                    'id' => '123',
                    'status' => 'deploying',
                    'deployed_at' => 'Đang triển khai',
                    'environment' => 'production',
                ],
            ]);

        $service = new FreshnessService($cicdService);
        $now = Carbon::now('UTC');

        $result = $service->evaluateDeploymentFreshness($now);

        // Deployment đang chạy hoặc chưa xong không được coi là fresh
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $result['status']);
    }

    public function test_evaluate_github_freshness_distinguishes_running_and_successful_runs(): void
    {
        $successTime = Carbon::now('UTC')->subSeconds(120);

        $cicdService = Mockery::mock(GitHubActionsService::class);
        $cicdService->shouldReceive('getPipelines')
            ->once()
            ->andReturn([
                [
                    'name' => 'CI - Integration',
                    'status' => 'in_progress',
                    'created_at' => Carbon::now('UTC')->subSeconds(10)->toIso8601String(),
                ],
                [
                    'name' => 'CI - Integration',
                    'status' => 'success',
                    'completed_at' => $successTime->toIso8601String(),
                ],
            ]);

        $service = new FreshnessService($cicdService);
        $now = Carbon::now('UTC');

        $result = $service->evaluateGitHubFreshness($now, true);

        // Vẫn nhận diện pipeline đang chạy
        $this->assertEquals('running', $result['execution_state']);
        // Tính độ tươi mới dựa trên completed run thành công gần nhất
        $this->assertEquals(FreshnessService::STATE_FRESH, $result['status']);
        $this->assertEquals($successTime->toIso8601String(), $result['last_event_at']);
    }

    public function test_evaluate_collector_freshness_returns_unknown_when_no_heartbeat(): void
    {
        Cache::forget('freshness_collector_last_success_at');
        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);

        $result = $service->evaluateCollectorFreshness(Carbon::now('Asia/Ho_Chi_Minh'));

        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $result['status']);
        $this->assertNull($result['last_success_at']);
        $this->assertNull($result['age_seconds']);
    }

    public function test_evaluate_collector_freshness_returns_stale_or_critical_for_old_heartbeat(): void
    {
        $oldTime = Carbon::now('Asia/Ho_Chi_Minh')->subSeconds(150); // Warning is 60s, Critical is 300s
        Cache::put('freshness_collector_last_success_at', $oldTime->toIso8601String(), 3600);

        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);

        $result = $service->evaluateCollectorFreshness(Carbon::now('Asia/Ho_Chi_Minh'));
        $this->assertEquals(FreshnessService::STATE_STALE, $result['status']);

        // Very old (400s >= 300s) -> CRITICAL
        $veryOldTime = Carbon::now('Asia/Ho_Chi_Minh')->subSeconds(400);
        Cache::put('freshness_collector_last_success_at', $veryOldTime->toIso8601String(), 3600);

        $critResult = $service->evaluateCollectorFreshness(Carbon::now('Asia/Ho_Chi_Minh'));
        $this->assertEquals(FreshnessService::STATE_CRITICAL, $critResult['status']);
    }

    public function test_evaluate_collector_freshness_returns_fresh_for_recent_heartbeat(): void
    {
        $recentTime = Carbon::now('Asia/Ho_Chi_Minh')->subSeconds(15);
        Cache::put('freshness_collector_last_success_at', $recentTime->toIso8601String(), 3600);

        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);

        $result = $service->evaluateCollectorFreshness(Carbon::now('Asia/Ho_Chi_Minh'));

        $this->assertEquals(FreshnessService::STATE_FRESH, $result['status']);
        $this->assertEquals($recentTime->toIso8601String(), $result['last_success_at']);
    }

    public function test_evaluate_collector_freshness_does_not_mutate_timestamp_on_multiple_calls(): void
    {
        $fixedHeartbeat = '2026-09-30T01:00:00+07:00';
        Cache::put('freshness_collector_last_success_at', $fixedHeartbeat, 3600);

        $cicdService = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicdService);

        $now = Carbon::parse('2026-09-30T01:00:20+07:00');

        $result1 = $service->evaluateCollectorFreshness($now);
        $result2 = $service->evaluateCollectorFreshness($now);

        $this->assertEquals($fixedHeartbeat, $result1['last_success_at']);
        $this->assertEquals($fixedHeartbeat, $result2['last_success_at']);
        // Cache must still hold the original heartbeat, NOT mutated to $now
        $this->assertEquals($fixedHeartbeat, Cache::get('freshness_collector_last_success_at'));
    }

    public function test_is_acceptable_state_enforces_production_vs_preview_policy(): void
    {
        // Production accepts FRESH, HEALTHY, WARNING only
        $this->assertTrue(FreshnessService::isAcceptableState('FRESH', true));
        $this->assertTrue(FreshnessService::isAcceptableState('HEALTHY', true));
        $this->assertTrue(FreshnessService::isAcceptableState('WARNING', true));
        $this->assertFalse(FreshnessService::isAcceptableState('STALE', true));
        $this->assertFalse(FreshnessService::isAcceptableState('CRITICAL', true));
        $this->assertFalse(FreshnessService::isAcceptableState('UNAVAILABLE', true));
        $this->assertFalse(FreshnessService::isAcceptableState('UNKNOWN', true));

        // Preview accepts STALE as well
        $this->assertTrue(FreshnessService::isAcceptableState('STALE', false));
        $this->assertFalse(FreshnessService::isAcceptableState('CRITICAL', false));
        $this->assertFalse(FreshnessService::isAcceptableState('UNAVAILABLE', false));
    }
}
