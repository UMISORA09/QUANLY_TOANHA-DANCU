<?php

namespace Tests\Feature;

use App\Models\FreshnessHeartbeat;
use App\Models\FreshnessIncident;
use App\Services\Cicd\GitHubActionsService;
use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        if (Schema::hasTable('freshness_heartbeats')) {
            FreshnessHeartbeat::whereIn('channel', ['collector', 'application_health'])->delete();
        }
        Cache::forget('freshness_collector_last_success_at');
        Cache::forget('application_health_last_observed_at');

        $cicd = Mockery::mock(GitHubActionsService::class);
        $cicd->shouldReceive('isLiveGitHubAvailable')->andReturn(false);
        $cicd->shouldReceive('getPipelinesWithStatus')->andReturn([
            'status' => 'token_missing',
            'runs' => [],
            'reason' => 'GitHub token missing',
        ]);
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
        if (Schema::hasTable('freshness_heartbeats')) {
            FreshnessHeartbeat::where('channel', 'application_health')->delete();
        }
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

    /**
     * FIX #2 - Test GitHub Actions Freshness:
     * workflow run mới nhất dựa trên timestamp thực tế.
     * Run mới hơn bị failure (10:05 failure) sau run cũ thành công (10:00 success)
     * phải ra CRITICAL, KHÔNG ĐƯỢC lấy success cũ để che failure mới.
     */
    public function test_github_freshness_prioritizes_latest_timestamp_over_past_success(): void
    {
        $now = Carbon::parse('2026-09-30T10:10:00Z');

        // Kịch bản A: 10:00 success, 10:05 failure -> Kết quả PHẢI là CRITICAL
        $runsWithNewerFailure = [
            [
                'id' => 101,
                'name' => 'CI / CD Pipeline',
                'status' => 'completed',
                'conclusion' => 'success',
                'created_at' => '2026-09-30T09:55:00Z',
                'updated_at' => '2026-09-30T10:00:00Z',
                'completed_at' => '2026-09-30T10:00:00Z',
                'head_sha' => 'abc1234',
            ],
            [
                'id' => 102,
                'name' => 'CI / CD Pipeline',
                'status' => 'completed',
                'conclusion' => 'failure',
                'created_at' => '2026-09-30T10:01:00Z',
                'updated_at' => '2026-09-30T10:05:00Z',
                'completed_at' => '2026-09-30T10:05:00Z',
                'head_sha' => 'def5678',
            ],
        ];

        $cicd = Mockery::mock(GitHubActionsService::class);
        $cicd->shouldReceive('getPipelinesWithStatus')->andReturn([
            'status' => 'ok',
            'runs' => $runsWithNewerFailure,
            'reason' => 'OK',
        ]);

        $service = new FreshnessService($cicd);
        $result = $service->evaluateGitHubFreshness($now, true);

        $this->assertEquals(FreshnessService::STATE_CRITICAL, $result['status'], 'Newer failure must produce CRITICAL, never masked by older success');
        $this->assertEquals('2026-09-30T10:05:00+00:00', Carbon::parse($result['last_event_at'])->toIso8601String());
        $this->assertEquals('failure', $result['conclusion']);

        // Kịch bản B: 10:05 failure, 10:08 success -> Kết quả PHẢI là FRESH
        $runsWithNewerSuccess = [
            [
                'id' => 102,
                'name' => 'CI / CD Pipeline',
                'status' => 'completed',
                'conclusion' => 'failure',
                'created_at' => '2026-09-30T10:01:00Z',
                'updated_at' => '2026-09-30T10:05:00Z',
                'completed_at' => '2026-09-30T10:05:00Z',
                'head_sha' => 'def5678',
            ],
            [
                'id' => 103,
                'name' => 'CI / CD Pipeline',
                'status' => 'completed',
                'conclusion' => 'success',
                'created_at' => '2026-09-30T10:06:00Z',
                'updated_at' => '2026-09-30T10:08:00Z',
                'completed_at' => '2026-09-30T10:08:00Z',
                'head_sha' => 'ghi9012',
            ],
        ];

        $cicd2 = Mockery::mock(GitHubActionsService::class);
        $cicd2->shouldReceive('getPipelinesWithStatus')->andReturn([
            'status' => 'ok',
            'runs' => $runsWithNewerSuccess,
            'reason' => 'OK',
        ]);

        $service2 = new FreshnessService($cicd2);
        $result2 = $service2->evaluateGitHubFreshness($now, true);

        $this->assertEquals(FreshnessService::STATE_FRESH, $result2['status']);
        $this->assertEquals('2026-09-30T10:08:00+00:00', Carbon::parse($result2['last_event_at'])->toIso8601String());
        $this->assertEquals('success', $result2['conclusion']);
    }

    /**
     * FIX #3 - Test phân biệt GitHub API failures:
     * - token missing -> UNKNOWN
     * - api unavailable -> UNAVAILABLE
     */
    public function test_github_freshness_api_failure_handling(): void
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh');

        // Token missing
        $cicdMissing = Mockery::mock(GitHubActionsService::class);
        $cicdMissing->shouldReceive('getPipelinesWithStatus')->andReturn([
            'status' => 'token_missing',
            'runs' => [],
            'reason' => 'GitHub Personal Access Token (GITHUB_TOKEN) is not configured',
        ]);

        $serviceMissing = new FreshnessService($cicdMissing);
        $resMissing = $serviceMissing->evaluateGitHubFreshness($now, true);

        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $resMissing['status']);
        $this->assertStringContainsString('GITHUB_TOKEN', $resMissing['reason']);

        // API unavailable
        $cicdUnavail = Mockery::mock(GitHubActionsService::class);
        $cicdUnavail->shouldReceive('getPipelinesWithStatus')->andReturn([
            'status' => 'api_unavailable',
            'runs' => [],
            'reason' => 'GitHub Actions API returned HTTP 503',
        ]);

        $serviceUnavail = new FreshnessService($cicdUnavail);
        $resUnavail = $serviceUnavail->evaluateGitHubFreshness($now, true);

        $this->assertEquals(FreshnessService::STATE_UNAVAILABLE, $resUnavail['status']);
        $this->assertStringContainsString('HTTP 503', $resUnavail['reason']);
    }

    /**
     * FIX #4 - Test Deployment Freshness:
     * - successful deployment -> valid observation (FRESH)
     * - failed deployment -> CRITICAL
     * - no deployment data -> UNKNOWN
     */
    public function test_deployment_freshness_real_data_evaluations(): void
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh');

        // 1. Successful deployment
        $cicdSuccess = Mockery::mock(GitHubActionsService::class);
        $cicdSuccess->shouldReceive('getDeployments')->andReturn([
            [
                'id' => 'dep-1',
                'environment' => 'production',
                'status' => 'success',
                'version' => 'v2.1.0',
                'deployed_at' => $now->copy()->subMinutes(15)->toIso8601String(),
            ],
        ]);

        $serviceSuccess = new FreshnessService($cicdSuccess);
        $resSuccess = $serviceSuccess->evaluateDeploymentFreshness($now);
        $this->assertEquals(FreshnessService::STATE_FRESH, $resSuccess['status']);
        $this->assertEquals('v2.1.0', $resSuccess['version']);

        // 2. Failed deployment -> CRITICAL
        $cicdFail = Mockery::mock(GitHubActionsService::class);
        $cicdFail->shouldReceive('getDeployments')->andReturn([
            [
                'id' => 'dep-2',
                'environment' => 'production',
                'status' => 'failed',
                'version' => 'v2.2.0',
                'deployed_at' => $now->copy()->subMinutes(5)->toIso8601String(),
            ],
        ]);

        $serviceFail = new FreshnessService($cicdFail);
        $resFail = $serviceFail->evaluateDeploymentFreshness($now);
        $this->assertEquals(FreshnessService::STATE_CRITICAL, $resFail['status']);
        $this->assertStringContainsString('thất bại', $resFail['reason']);

        // 3. No deployment data -> UNKNOWN
        $cicdEmpty = Mockery::mock(GitHubActionsService::class);
        $cicdEmpty->shouldReceive('getDeployments')->andReturn([]);

        $serviceEmpty = new FreshnessService($cicdEmpty);
        $resEmpty = $serviceEmpty->evaluateDeploymentFreshness($now);
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $resEmpty['status']);
    }

    /**
     * FIX #5 - Test Health Probe Command và Observation Persistence:
     * - Successful probe -> 200 OK + healthy status -> FreshnessHeartbeat DB record + FRESH
     * - Failed probe -> failure state -> FreshnessHeartbeat status unhealthy + CRITICAL
     * - No probe -> UNKNOWN
     */
    public function test_health_probe_command_and_persistence(): void
    {
        if (Schema::hasTable('freshness_heartbeats')) {
            FreshnessHeartbeat::where('channel', 'application_health')->delete();
        }
        Cache::forget('application_health_last_observed_at');
        Cache::forget('application_health_last_status');
        Cache::forget('application_health_failure_reason');

        $cicd = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicd);
        $now = Carbon::now('Asia/Ho_Chi_Minh');

        // Khi chưa probe -> UNKNOWN
        $initHealth = $service->evaluateHealthFreshness($now);
        $this->assertEquals(FreshnessService::STATE_UNKNOWN, $initHealth['status']);

        // 1. Probe thành công
        Http::fake([
            'http://test-prod-ok.app/health' => Http::response([
                'status' => 'healthy',
                'database' => 'healthy',
                'cache' => 'healthy',
            ], 200),
            'http://test-prod-fail.app/health' => Http::response([
                'status' => 'unhealthy',
                'database' => 'unhealthy',
            ], 503),
        ]);

        $exitCode = Artisan::call('health:probe', ['--url' => 'http://test-prod-ok.app']);
        $this->assertEquals(0, $exitCode);

        // Verify DB heartbeat
        if (Schema::hasTable('freshness_heartbeats')) {
            $row = FreshnessHeartbeat::where('channel', 'application_health')->first();
            $this->assertNotNull($row);
            $this->assertEquals('healthy', $row->status);
            $this->assertNotNull($row->last_success_at);
        }

        $freshHealth = $service->evaluateHealthFreshness($now);
        $this->assertEquals(FreshnessService::STATE_FRESH, $freshHealth['status']);

        // 2. Probe thất bại (HTTP 503 unhealthy)
        $failExitCode = Artisan::call('health:probe', ['--url' => 'http://test-prod-fail.app']);
        $this->assertEquals(1, $failExitCode);

        if (Schema::hasTable('freshness_heartbeats')) {
            $failRow = FreshnessHeartbeat::where('channel', 'application_health')->first();
            $this->assertEquals('unhealthy', $failRow->status);
        }

        $critHealth = $service->evaluateHealthFreshness($now);
        $this->assertEquals(FreshnessService::STATE_CRITICAL, $critHealth['status']);
    }

    /**
     * FIX #7 - Test Incident Concurrency / Race Condition:
     * 10 evaluations liên tiếp / đồng thời của cùng 1 failure episode
     * PHẢI đảm bảo chỉ có DUY NHẤT 1 active incident, KHÔNG tạo duplicate rows.
     */
    public function test_incident_concurrency_10_evaluations_produces_exactly_one_active_incident(): void
    {
        if (! Schema::hasTable('freshness_incidents')) {
            $this->markTestSkipped('freshness_incidents table not available');
        }

        $testSource = 'test_concurrency_source_'.uniqid();
        FreshnessIncident::where('source', $testSource)->delete();

        $cicd = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicd);
        $now = Carbon::now('Asia/Ho_Chi_Minh');

        $refMethod = new \ReflectionMethod(FreshnessService::class, 'trackIncidents');
        $refMethod->setAccessible(true);

        // Thực hiện 10 lần đánh giá liên tiếp trong cùng một đợt failure
        for ($i = 0; $i < 10; $i++) {
            $refMethod->invoke($service, [
                $testSource => [
                    'source' => $testSource,
                    'type' => 'data',
                    'status' => FreshnessService::STATE_CRITICAL,
                    'age_seconds' => 500 + $i,
                    'warning_threshold' => 60,
                    'source_timestamp' => $now->subSeconds(500 + $i)->toIso8601String(),
                    'reason' => "Concurrent failure evaluation {$i}",
                ],
            ]);
        }

        // Kiểm tra số active incidents cho source này: EXACTLY 1
        $activeCount = FreshnessIncident::where('source', $testSource)->whereNull('resolved_at')->count();
        $this->assertEquals(1, $activeCount, 'Chỉ được phép có duy nhất 1 active incident cho cùng một episode');

        // Kiểm tra tổng số rows cho source này: EXACTLY 1 (không có row dư thừa nào)
        $totalRows = FreshnessIncident::where('source', $testSource)->count();
        $this->assertEquals(1, $totalRows, 'Không được tạo duplicate rows khi gặp CRITICAL liên tục');

        FreshnessIncident::where('source', $testSource)->delete();
    }

    /**
     * FIX #6 & #11 - Test Redis Flush / Restart Simulation:
     * Khi Redis cache bị xóa sạch (cache flush / container restart),
     * DB là source-of-truth bền vững lưu trữ collector heartbeat và health observation,
     * không bị mất dữ liệu và không tạo false FRESH state.
     */
    public function test_persistence_survives_redis_restart_cache_flush(): void
    {
        if (! Schema::hasTable('freshness_heartbeats')) {
            $this->markTestSkipped('freshness_heartbeats table not available');
        }

        $cicd = Mockery::mock(GitHubActionsService::class);
        $service = new FreshnessService($cicd);
        $now = Carbon::now('Asia/Ho_Chi_Minh');

        // Ghi heartbeat cho Collector và Health vào DB
        $service->recordCollectorHeartbeat($now->copy()->subSeconds(20));

        FreshnessHeartbeat::updateOrCreate(
            ['channel' => 'application_health'],
            [
                'last_success_at' => $now->copy()->subSeconds(15),
                'status' => 'healthy',
                'details' => 'DB survived test',
            ]
        );

        // Mô phỏng Redis Restart / Cache Flush
        Cache::flush();

        // Kiểm tra Collector: Vẫn đọc được timestamp từ DB table freshness_heartbeats
        $collector = $service->evaluateCollectorFreshness($now);
        $this->assertEquals(FreshnessService::STATE_FRESH, $collector['status']);
        $this->assertEqualsWithDelta(20, $collector['age_seconds'], 2);

        // Kiểm tra Health: Vẫn đọc được quan sát từ DB table freshness_heartbeats
        $health = $service->evaluateHealthFreshness($now);
        $this->assertEquals(FreshnessService::STATE_FRESH, $health['status']);
        $this->assertEqualsWithDelta(15, $health['age_seconds'], 2);
    }
}
