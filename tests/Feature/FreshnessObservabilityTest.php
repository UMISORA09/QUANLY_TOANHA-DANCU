<?php

namespace Tests\Feature;

use App\Models\FreshnessIncident;
use App\Services\Cicd\GitHubActionsService;
use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class FreshnessObservabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * FIX #15 - Test phân loại trạng thái Freshness theo ngưỡng:
     * age < warning -> FRESH
     * warning <= age < critical -> STALE
     * age >= critical -> CRITICAL
     */
    public function test_freshness_classification_by_thresholds(): void
    {
        $cicd = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicd);

        $warn = 60;
        $crit = 300;

        // age < warning -> FRESH
        $this->assertEquals(FreshnessService::STATE_FRESH, $service->classifyState(0, $warn, $crit));
        $this->assertEquals(FreshnessService::STATE_FRESH, $service->classifyState(59, $warn, $crit));

        // warning <= age < critical -> STALE
        $this->assertEquals(FreshnessService::STATE_STALE, $service->classifyState(60, $warn, $crit));
        $this->assertEquals(FreshnessService::STATE_STALE, $service->classifyState(299, $warn, $crit));

        // age >= critical -> CRITICAL
        $this->assertEquals(FreshnessService::STATE_CRITICAL, $service->classifyState(300, $warn, $crit));
        $this->assertEquals(FreshnessService::STATE_CRITICAL, $service->classifyState(1000, $warn, $crit));
    }

    /**
     * FIX #15 - Test trạng thái UNKNOWN khi thiếu dữ liệu nguồn thật:
     * - missing collector heartbeat -> UNKNOWN
     * - missing health observation -> UNKNOWN
     * - missing GitHub data/token -> UNKNOWN
     * - no successful deployment -> UNKNOWN
     */
    public function test_unknown_states_when_no_real_observations(): void
    {
        Cache::forget('freshness_collector_last_success_at');
        Cache::forget('application_health_last_observed_at');

        $cicd = Mockery::mock(GitHubActionsService::class);
        $cicd->shouldReceive('isLiveGitHubAvailable')->andReturn(false);
        $cicd->shouldReceive('getDeployments')->andReturn([]);

        $service = new FreshnessService($cicd);
        $now = Carbon::now('Asia/Ho_Chi_Minh');

        // Collector
        $collector = $service->evaluateCollectorFreshness($now);
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $collector['status']);
        $this->assertNull($collector['age_seconds']);

        // Health
        $health = $service->evaluateHealthFreshness($now);
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $health['status']);
        $this->assertNull($health['last_observed_at']);

        // GitHub Actions
        $github = $service->evaluateGitHubFreshness($now, true);
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $github['status']);
        $this->assertNull($github['last_event_at']);

        // Deployment
        $deployment = $service->evaluateDeploymentFreshness($now);
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $deployment['status']);
        $this->assertNull($deployment['last_event_at']);
    }

    /**
     * FIX #15 - Test vòng đời sự cố (Incident Lifecycle) với DB là Single Source of Truth:
     * - FRESH -> STALE: Tạo 1 incident duy nhất
     * - STALE -> CRITICAL: Chỉ cập nhật incident hiện tại, KHÔNG tạo duplicate row
     * - CRITICAL -> CRITICAL: Polling liên tục chỉ update incident hiện tại
     * - CRITICAL -> FRESH: Resolve incident đang active và ghi duy nhất 1 recovery event
     */
    public function test_incident_lifecycle_guarantees_single_active_incident(): void
    {
        if (! Schema::hasTable('freshness_incidents')) {
            $this->markTestSkipped('freshness_incidents table not available');
        }

        $testSource = 'test_unit_service_'.uniqid();

        // Dọn dẹp trước khi test
        FreshnessIncident::where('source', $testSource)->delete();

        $cicd = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicd);

        // 1. FRESH -> STALE: Tạo 1 incident mới
        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $sourcesStale = [
            $testSource => [
                'source' => $testSource,
                'type' => 'data',
                'status' => FreshnessService::STATE_STALE,
                'age_seconds' => 120,
                'warning_threshold' => 60,
                'source_timestamp' => $now->subSeconds(120)->toIso8601String(),
                'reason' => 'Đã quá ngưỡng warning',
            ],
        ];

        $refMethod = new \ReflectionMethod(FreshnessService::class, 'trackIncidents');
        $refMethod->setAccessible(true);

        $refMethod->invoke($service, $sourcesStale);

        $activeIncidents = FreshnessIncident::where('source', $testSource)->whereNull('resolved_at')->get();
        $this->assertCount(1, $activeIncidents);
        $this->assertEquals(FreshnessService::STATE_STALE, $activeIncidents->first()->state);
        $firstId = $activeIncidents->first()->id;

        // 2. STALE -> CRITICAL: Phải UPDATE cùng incident, KHÔNG tạo bản ghi thứ 2
        $sourcesCritical = [
            $testSource => [
                'source' => $testSource,
                'type' => 'data',
                'status' => FreshnessService::STATE_CRITICAL,
                'age_seconds' => 400,
                'warning_threshold' => 60,
                'source_timestamp' => $now->subSeconds(400)->toIso8601String(),
                'reason' => 'Đã quá ngưỡng critical',
            ],
        ];

        $refMethod->invoke($service, $sourcesCritical);

        $activeAfterCritical = FreshnessIncident::where('source', $testSource)->whereNull('resolved_at')->get();
        $this->assertCount(1, $activeAfterCritical, 'Phải có duy nhất 1 active incident, không được duplicate');
        $this->assertEquals($firstId, $activeAfterCritical->first()->id, 'Phải giữ nguyên ID incident ban đầu');
        $this->assertEquals(FreshnessService::STATE_CRITICAL, $activeAfterCritical->first()->state);
        $this->assertEquals(400, $activeAfterCritical->first()->age_seconds);

        // 3. CRITICAL -> CRITICAL (Polling tiếp): Vẫn duy nhất 1 active incident
        $sourcesCriticalPoll = [
            $testSource => [
                'source' => $testSource,
                'type' => 'data',
                'status' => FreshnessService::STATE_CRITICAL,
                'age_seconds' => 450,
                'warning_threshold' => 60,
                'source_timestamp' => $now->subSeconds(450)->toIso8601String(),
                'reason' => 'Polling tiếp tục critical',
            ],
        ];

        $refMethod->invoke($service, $sourcesCriticalPoll);

        $activeAfterPoll = FreshnessIncident::where('source', $testSource)->whereNull('resolved_at')->get();
        $this->assertCount(1, $activeAfterPoll, 'Polling nhiều lần không được tạo duplicate active rows');
        $this->assertEquals($firstId, $activeAfterPoll->first()->id);
        $this->assertEquals(450, $activeAfterPoll->first()->age_seconds);

        // 4. CRITICAL -> FRESH: Resolve active incident và tạo 1 bản ghi RECOVERED
        $sourcesFresh = [
            $testSource => [
                'source' => $testSource,
                'type' => 'data',
                'status' => FreshnessService::STATE_FRESH,
                'age_seconds' => 10,
                'warning_threshold' => 60,
                'source_timestamp' => $now->subSeconds(10)->toIso8601String(),
                'reason' => 'Dữ liệu đã tươi mới',
            ],
        ];

        $refMethod->invoke($service, $sourcesFresh);

        $activeAfterFresh = FreshnessIncident::where('source', $testSource)->whereNull('resolved_at')->get();
        $this->assertCount(0, $activeAfterFresh, 'Active incident phải được đóng khi recovered');

        $resolvedOriginal = FreshnessIncident::find($firstId);
        $this->assertNotNull($resolvedOriginal->resolved_at);

        $recoveredRecords = FreshnessIncident::where('source', $testSource)->where('state', 'RECOVERED')->get();
        $this->assertCount(1, $recoveredRecords);
        $this->assertNotNull($recoveredRecords->first()->resolved_at);

        // Dọn dẹp sau test
        FreshnessIncident::where('source', $testSource)->delete();
    }

    /**
     * FIX #15 - Test kịch bản Health Probe báo Unhealthy:
     * - Health probe thất bại thì FreshnessService nhận biết là CRITICAL
     */
    public function test_health_freshness_reflects_unhealthy_probe(): void
    {
        Cache::put('application_health_last_observed_at', Carbon::now('Asia/Ho_Chi_Minh')->toIso8601String(), 300);
        Cache::put('application_health_last_status', 'unhealthy', 300);
        Cache::put('application_health_failure_reason', 'MySQL timeout', 300);

        $cicd = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicd);

        $result = $service->evaluateHealthFreshness(Carbon::now('Asia/Ho_Chi_Minh'));

        $this->assertEquals(FreshnessService::STATE_CRITICAL, $result['status']);
        $this->assertStringContainsString('MySQL timeout', $result['reason']);
    }

    /**
     * FIX #15 - Test kịch bản lệnh Artisan freshness:check
     * Output chính xác và exit codes đúng chuẩn: 0 = fresh, 1 = warning/stale, 2 = critical/unavailable
     */
    public function test_freshness_check_artisan_command_exit_codes(): void
    {
        $exitCode = Artisan::call('freshness:check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertJson($output);
        $data = json_decode($output, true);

        $this->assertArrayHasKey('overall_state', $data);
        $this->assertArrayHasKey('worst_source', $data);
        $this->assertArrayHasKey('metrics', $data);
        $this->assertArrayHasKey('collector', $data);
        $this->assertArrayHasKey('github_actions', $data);
        $this->assertArrayHasKey('deployment', $data);
        $this->assertArrayHasKey('application_health', $data);
        $this->assertArrayHasKey('database', $data);

        // Kiểm tra exit code khớp với overall_state
        $expectedExitCode = match ($data['overall_state']) {
            FreshnessService::STATE_FRESH => 0,
            FreshnessService::STATE_STALE, FreshnessService::STATE_UNKNOWN => 1,
            FreshnessService::STATE_CRITICAL, FreshnessService::STATE_UNAVAILABLE => 2,
            default => 1,
        };

        $this->assertEquals($expectedExitCode, $exitCode);
    }
}
