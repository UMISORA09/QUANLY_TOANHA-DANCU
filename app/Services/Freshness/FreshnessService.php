<?php

namespace App\Services\Freshness;

use App\Models\FreshnessIncident;
use App\Services\Cicd\GitHubActionsService;
use App\Services\Freshness\Strategies\CollectorFreshnessEvaluator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FreshnessService
{
    public const STATE_FRESH = 'FRESH';

    public const STATE_STALE = 'STALE';

    public const STATE_CRITICAL = 'CRITICAL';

    public const STATE_UNKNOWN = 'UNKNOWN';

    public const STATE_UNAVAILABLE = 'UNAVAILABLE';

    public const ACCEPTABLE_PRODUCTION_STATES = ['FRESH', 'HEALTHY', 'WARNING'];

    public const ACCEPTABLE_PREVIEW_STATES = ['FRESH', 'HEALTHY', 'WARNING', 'STALE'];

    public static function isAcceptableState(string $state, bool $isProduction = true): bool
    {
        $allowed = $isProduction ? self::ACCEPTABLE_PRODUCTION_STATES : self::ACCEPTABLE_PREVIEW_STATES;

        return in_array(strtoupper($state), $allowed, true);
    }

    protected array $config;

    public function __construct(
        protected GitHubActionsService $cicdService
    ) {
        $this->config = config('freshness', []);
    }

    /**
     * Thu thập và tính toán toàn bộ Freshness Overview (Data + Monitoring)
     * Phản ánh 100% dữ liệu thực tế, không mock, không tự làm tươi timestamps.
     */
    public function getOverview(bool $forceRefresh = false): array
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $collectorTimestamp = $now->toIso8601String();

        $collector = $this->evaluateCollectorFreshness($now);
        $github = $this->evaluateGitHubFreshness($now, $forceRefresh);
        $deployment = $this->evaluateDeploymentFreshness($now, $forceRefresh);
        $appHealth = $this->evaluateHealthFreshness($now);
        $database = $this->evaluateDatabaseFreshness($now, $forceRefresh);

        // Gom tất cả các nguồn quan sát
        $allSources = [
            'collector' => $collector,
            'github_actions' => $github,
            'deployment' => $deployment,
            'application_health' => $appHealth,
        ];
        foreach ($database['sources'] as $dbSrc) {
            $srcKey = $dbSrc['source'] ?? $dbSrc['table'] ?? 'unknown_db';
            $allSources[$srcKey] = $dbSrc;
        }

        // Đánh giá trạng thái tổng thể minh bạch, có lý do cụ thể và xác định Worst Source
        $assessment = $this->deriveOverallAssessment($allSources);
        $overallStatus = $assessment['state'];
        $overallReason = $assessment['reason'];
        $worstSource = $assessment['worst_source'];

        // Ghi nhận và theo dõi các sự cố Freshness bền vững với DB là Single Source of Truth
        $incidents = $this->trackIncidents($allSources);

        // Tính toán các metrics dữ liệu: Newest Data Age, Oldest Data Age
        $newestDataAge = $database['newest_data_age_seconds'] ?? null;
        $oldestDataAge = $database['oldest_data_age_seconds'] ?? null;

        return [
            'status' => strtolower($overallStatus),
            'overall_state' => $overallStatus,
            'overall_reason' => $overallReason,
            'checked_at' => $collectorTimestamp,
            'metrics' => [
                'newest_data_age_seconds' => $newestDataAge,
                'oldest_data_age_seconds' => $oldestDataAge,
                'worst_source' => $worstSource,
                'overall_state' => $overallStatus,
                'overall_reason' => $overallReason,
            ],
            'worst_source' => $worstSource,
            'newest_data_age_seconds' => $newestDataAge,
            'oldest_data_age_seconds' => $oldestDataAge,
            'collector' => $collector,
            'github_actions' => $github,
            'deployment' => $deployment,
            'application_health' => $appHealth,
            'database' => $database,
            'incidents' => $incidents,
        ];
    }

    /**
     * Đo lường độ tươi mới của chính Collector (Monitoring of Monitoring)
     * DB table freshness_heartbeats là Source of Truth, Cache dùng để tối ưu đọc.
     */
    public function evaluateCollectorFreshness(Carbon $now): array
    {
        return (new CollectorFreshnessEvaluator($this->config))->evaluate($now);
    }

    /**
     * Ghi nhận heartbeat từ Scheduler / Collector nền thực tế
     * DB là Source of Truth, Cache dùng để tối ưu đọc.
     */
    public function recordCollectorHeartbeat(?Carbon $timestamp = null): void
    {
        $time = $timestamp ?: Carbon::now('Asia/Ho_Chi_Minh');
        $isoTime = $time->toIso8601String();

        // 1. Cache
        Cache::put('freshness_collector_last_success_at', $isoTime, 86400);

        // 2. DB Persistence (Durable)
        try {
            if (Schema::hasTable('freshness_heartbeats')) {
                DB::table('freshness_heartbeats')->updateOrInsert(
                    ['channel' => 'collector'],
                    [
                        'last_success_at' => $time,
                        'status' => 'healthy',
                        'details' => "Scheduler heartbeat ghi nhận tại {$isoTime}",
                        'updated_at' => $time,
                    ]
                );
            }
        } catch (Throwable $e) {
            Log::warning('Failed to persist collector heartbeat to DB: '.$e->getMessage());
        }
    }

    /**
     * Đo lường độ tươi mới của GitHub Actions CI/CD Runs
     * - Xác định workflow run mới nhất dựa trên timestamp thực tế (completed_at -> updated_at -> created_at)
     * - Tuyệt đối không chọn latestSuccessful trước latestFailed
     * - Phân biệt rõ ràng: token missing (UNKNOWN), API unavailable (UNAVAILABLE), no runs (UNKNOWN)
     */
    public function evaluateGitHubFreshness(Carbon $now, bool $force = false): array
    {
        $cfg = $this->config['monitoring_sources']['github_actions'] ?? [];
        $warn = (int) ($cfg['warning_seconds'] ?? 3600);
        $crit = (int) ($cfg['critical_seconds'] ?? 86400);

        $cacheTtl = (int) ($this->config['cache']['monitoring_cache_seconds'] ?? 5);
        $cacheKey = 'freshness_obs_github';

        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, $cacheTtl, function () use ($now, $warn, $crit, $force) {
            try {
                $statusResult = null;
                try {
                    if (method_exists($this->cicdService, 'getPipelinesWithStatus')) {
                        $statusResult = $this->cicdService->getPipelinesWithStatus([], $force);
                    }
                } catch (\BadMethodCallException|\Mockery\Exception\BadMethodCallException) {
                    $statusResult = null;
                }

                if ($statusResult !== null) {
                    $apiStatus = $statusResult['status'] ?? 'ok';
                    $apiReason = $statusResult['reason'] ?? null;
                    $pipelines = $statusResult['runs'] ?? [];
                } else {
                    $pipelines = $this->cicdService->getPipelines([], $force);
                    $apiStatus = empty($pipelines) ? 'empty_runs' : 'ok';
                    $apiReason = null;
                }

                if ($apiStatus === 'token_missing') {
                    return [
                        'source' => 'github_actions',
                        'name' => 'GitHub Actions CI/CD Runs',
                        'type' => 'monitoring',
                        'status' => self::STATE_UNKNOWN,
                        'last_event_at' => null,
                        'source_timestamp' => null,
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'reason' => $apiReason ?: 'GitHub token missing',
                        'message' => $apiReason ?: 'GitHub token missing',
                    ];
                }

                if ($apiStatus === 'api_unavailable') {
                    return [
                        'source' => 'github_actions',
                        'name' => 'GitHub Actions CI/CD Runs',
                        'type' => 'monitoring',
                        'status' => self::STATE_UNAVAILABLE,
                        'last_event_at' => null,
                        'source_timestamp' => null,
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'reason' => $apiReason ?: 'GitHub Actions API unavailable',
                        'message' => $apiReason ?: 'GitHub Actions API unavailable',
                    ];
                }

                if (empty($pipelines)) {
                    return [
                        'source' => 'github_actions',
                        'name' => 'GitHub Actions CI/CD Runs',
                        'type' => 'monitoring',
                        'status' => self::STATE_UNKNOWN,
                        'last_event_at' => null,
                        'source_timestamp' => null,
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'reason' => 'no workflow runs',
                        'message' => 'no workflow runs',
                    ];
                }

                // 1. Kiểm tra xem có workflow nào đang chạy
                $hasRunning = false;
                foreach ($pipelines as $pipe) {
                    $st = strtolower((string) ($pipe['status'] ?? $pipe['raw_status'] ?? $pipe['conclusion'] ?? ''));
                    if (in_array($st, ['running', 'in_progress', 'queued', 'pending'], true)) {
                        $hasRunning = true;
                        break;
                    }
                }

                // 2. Xác định workflow run mới nhất DỰA TRÊN TIMESTAMP THỰC TẾ
                // Ưu tiên latest completed run để đo lường độ tươi mới thực tế
                $latestCompletedRun = null;
                $latestCompletedTimestamp = null;
                $latestAnyRun = null;
                $latestAnyTimestamp = null;

                foreach ($pipelines as $p) {
                    $rawDate = $p['completed_at'] ?? $p['updated_at'] ?? $p['created_at'] ?? null;
                    if ($rawDate) {
                        try {
                            $parsed = Carbon::parse($rawDate);
                            if (! $latestAnyTimestamp || $parsed->isAfter($latestAnyTimestamp)) {
                                $latestAnyTimestamp = $parsed;
                                $latestAnyRun = $p;
                            }

                            $cStatus = strtolower((string) ($p['status'] ?? $p['raw_status'] ?? ''));
                            $cConc = strtolower((string) ($p['conclusion'] ?? ''));
                            $isCompleted = ! empty($p['completed_at'])
                                || in_array($cStatus, ['completed', 'success', 'successful', 'failed', 'failure', 'cancelled', 'timed_out'], true)
                                || in_array($cConc, ['success', 'failure', 'cancelled', 'timed_out', 'neutral'], true);

                            if ($isCompleted) {
                                if (! $latestCompletedTimestamp || $parsed->isAfter($latestCompletedTimestamp)) {
                                    $latestCompletedTimestamp = $parsed;
                                    $latestCompletedRun = $p;
                                }
                            }
                        } catch (Throwable) {
                            // Ignore date parse errors
                        }
                    }
                }

                $latestRun = $latestCompletedRun ?: ($latestAnyRun ?: $pipelines[0]);
                $latestTimestamp = $latestCompletedTimestamp ?: ($latestAnyTimestamp ?: null);

                if (! $latestRun || ! $latestTimestamp) {
                    return [
                        'source' => 'github_actions',
                        'name' => 'GitHub Actions CI/CD Runs',
                        'type' => 'monitoring',
                        'status' => self::STATE_UNKNOWN,
                        'last_event_at' => null,
                        'source_timestamp' => null,
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'reason' => 'Không tìm thấy timestamp hợp lệ trên workflow runs.',
                    ];
                }

                $ageSeconds = (int) round(max(0, $now->diffInSeconds($latestTimestamp, false) * -1));
                $runName = $latestRun['name'] ?? 'Pipeline';
                $commitSha = $latestRun['commit_sha'] ?? null;
                $conclusion = strtolower((string) ($latestRun['conclusion'] ?? ''));
                $status = strtolower((string) ($latestRun['status'] ?? $latestRun['raw_status'] ?? ''));

                $isFailed = in_array($conclusion, ['failure', 'failed', 'cancelled', 'timed_out'], true)
                    || in_array($status, ['failed', 'failure', 'cancelled'], true);
                $isRunning = in_array($status, ['running', 'in_progress', 'queued'], true)
                    || in_array($conclusion, ['running', 'in_progress'], true);

                if ($isFailed) {
                    $state = self::STATE_CRITICAL;
                    $reason = "Workflow gần nhất [{$runName}] thất bại tại commit {$commitSha}";
                } elseif ($isRunning) {
                    $state = $this->classifyState($ageSeconds, $warn, $crit);
                    $reason = "Workflow [{$runName}] đang chạy tại commit {$commitSha}";
                } else {
                    $state = $this->classifyState($ageSeconds, $warn, $crit);
                    $reason = match ($state) {
                        self::STATE_FRESH => "Workflow [{$runName}] thành công, cách đây {$ageSeconds}s",
                        self::STATE_STALE => "Workflow [{$runName}] chạy cách đây {$ageSeconds}s (vượt warning {$warn}s)",
                        default => "Workflow [{$runName}] chạy cách đây {$ageSeconds}s (vượt critical {$crit}s)",
                    };
                }

                $executionState = $hasRunning
                    ? 'running'
                    : ($status ?: ($conclusion ?: 'unknown'));

                return [
                    'source' => 'github_actions',
                    'name' => 'GitHub Actions CI/CD Runs',
                    'type' => 'monitoring',
                    'status' => $state,
                    'last_event_at' => $latestTimestamp->toIso8601String(),
                    'source_timestamp' => $latestTimestamp->toIso8601String(),
                    'age_seconds' => $ageSeconds,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'workflow' => $runName,
                    'commit_sha' => $commitSha,
                    'execution_state' => $executionState,
                    'conclusion' => $conclusion ?: $status,
                    'reason' => $reason,
                ];
            } catch (Throwable $e) {
                Log::warning('Freshness evaluation failed for GitHub Actions: '.$e->getMessage());

                return [
                    'source' => 'github_actions',
                    'name' => 'GitHub Actions CI/CD Runs',
                    'type' => 'monitoring',
                    'status' => self::STATE_UNAVAILABLE,
                    'last_event_at' => null,
                    'source_timestamp' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'error' => $e->getMessage(),
                    'reason' => 'GitHub Actions API unavailable: '.$e->getMessage(),
                ];
            }
        });
    }

    /**
     * Đo lường độ tươi mới của Triển khai & Bản phát hành (Deployment Freshness)
     * Phân biệt rõ: deployment success, deployment failed, deployment pending, no deployment data.
     */
    public function evaluateDeploymentFreshness(Carbon $now, bool $force = false): array
    {
        $cfg = $this->config['monitoring_sources']['deployment'] ?? [];
        $warn = (int) ($cfg['warning_seconds'] ?? 604800);
        $crit = (int) ($cfg['critical_seconds'] ?? 2592000);

        try {
            $deployments = $this->cicdService->getDeployments($force);
            if (empty($deployments)) {
                return [
                    'source' => 'deployment',
                    'name' => 'Deployment & Releases',
                    'type' => 'monitoring',
                    'status' => self::STATE_UNKNOWN,
                    'last_event_at' => null,
                    'source_timestamp' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'reason' => 'Chưa có bản phát hành/triển khai nào được ghi nhận trên môi trường mục tiêu.',
                    'message' => 'Chưa có bản phát hành/triển khai nào được ghi nhận.',
                ];
            }

            // Tìm deployment mới nhất theo timestamp
            $latestDeploy = null;
            $latestTimestamp = null;
            foreach ($deployments as $dep) {
                $rawDate = $dep['deployed_at'] ?? $dep['created_at'] ?? null;
                if ($rawDate) {
                    try {
                        $c = Carbon::parse($rawDate);
                        if (! $latestTimestamp || $c->isAfter($latestTimestamp)) {
                            $latestTimestamp = $c;
                            $latestDeploy = $dep;
                        }
                    } catch (Throwable) {
                        // Ignore non-parseable date strings (e.g. 'Đang triển khai')
                    }
                }
            }

            if (! $latestDeploy) {
                $latestDeploy = $deployments[0];
            }

            $depStatus = strtolower($latestDeploy['status'] ?? 'unknown');
            $env = $latestDeploy['environment'] ?? 'production';
            $version = $latestDeploy['version'] ?? 'unknown';

            // 1. Deployment failed -> CRITICAL
            if (in_array($depStatus, ['failed', 'failure', 'error'], true)) {
                $ageSeconds = $latestTimestamp ? (int) round(max(0, $now->diffInSeconds($latestTimestamp, false) * -1)) : null;

                return [
                    'source' => 'deployment',
                    'name' => 'Deployment & Releases',
                    'type' => 'monitoring',
                    'status' => self::STATE_CRITICAL,
                    'last_event_at' => $latestTimestamp?->toIso8601String(),
                    'source_timestamp' => $latestTimestamp?->toIso8601String(),
                    'age_seconds' => $ageSeconds,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'environment' => $env,
                    'version' => $version,
                    'reason' => "Bản triển khai gần nhất [{$env}: {$version}] thất bại.",
                ];
            }

            // 2. Deployment pending / deploying -> UNKNOWN
            if (in_array($depStatus, ['deploying', 'in_progress', 'queued', 'pending'], true)) {
                return [
                    'source' => 'deployment',
                    'name' => 'Deployment & Releases',
                    'type' => 'monitoring',
                    'status' => self::STATE_UNKNOWN,
                    'last_event_at' => $latestTimestamp?->toIso8601String(),
                    'source_timestamp' => $latestTimestamp?->toIso8601String(),
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'environment' => $env,
                    'version' => $version,
                    'reason' => "Bản triển khai [{$env}: {$version}] đang được tiến hành.",
                ];
            }

            // 3. Không có deployed_at hoặc không thành công -> UNKNOWN
            if (! in_array($depStatus, ['healthy', 'success'], true) || empty($latestDeploy['deployed_at'])) {
                return [
                    'source' => 'deployment',
                    'name' => 'Deployment & Releases',
                    'type' => 'monitoring',
                    'status' => self::STATE_UNKNOWN,
                    'last_event_at' => null,
                    'source_timestamp' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'environment' => $env,
                    'version' => $version,
                    'reason' => "Chưa có bản phát hành/triển khai thành công nào được ghi nhận trên môi trường {$env}.",
                ];
            }

            // 4. Deployment thành công: đo lường theo tuổi thực tế
            $sourceCarbon = Carbon::parse($latestDeploy['deployed_at']);
            $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));
            $state = $this->classifyState($ageSeconds, $warn, $crit);

            $reason = match ($state) {
                self::STATE_FRESH => "Bản phát hành [{$env}: {$version}] hoạt động bình thường, triển khai cách đây {$ageSeconds}s",
                self::STATE_STALE => "Bản phát hành [{$env}: {$version}] đã triển khai cách đây {$ageSeconds}s (vượt warning {$warn}s)",
                default => "Bản phát hành [{$env}: {$version}] đã quá hạn {$ageSeconds}s (vượt critical {$crit}s)",
            };

            return [
                'source' => 'deployment',
                'name' => 'Deployment & Releases',
                'type' => 'monitoring',
                'status' => $state,
                'last_event_at' => $sourceCarbon->toIso8601String(),
                'source_timestamp' => $sourceCarbon->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'environment' => $env,
                'version' => $version,
                'reason' => $reason,
            ];
        } catch (Throwable $e) {
            Log::warning('Freshness evaluation failed for Deployment: '.$e->getMessage());

            return [
                'source' => 'deployment',
                'name' => 'Deployment & Releases',
                'type' => 'monitoring',
                'status' => self::STATE_UNAVAILABLE,
                'last_event_at' => null,
                'source_timestamp' => null,
                'age_seconds' => null,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'error' => $e->getMessage(),
                'reason' => 'Lỗi truy vấn deployment: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Đo lường độ tươi mới của quan sát Health Probe (Application Health Observation Freshness)
     * DB table freshness_heartbeats là Source of Truth, Cache dùng để tối ưu đọc.
     * FreshnessService CHỈ ĐỌC observation, TUYỆT ĐỐI KHÔNG tự cập nhật timestamp đang đo.
     */
    public function evaluateHealthFreshness(Carbon $now): array
    {
        $cfg = $this->config['monitoring_sources']['application_health'] ?? [];
        $warn = (int) ($cfg['warning_seconds'] ?? 60);
        $crit = (int) ($cfg['critical_seconds'] ?? 180);

        try {
            $lastObserved = null;
            $lastStatus = null;
            $failureReason = null;
            $lastDetails = null;

            // 1. DB là Source of Truth bền vững
            try {
                if (Schema::hasTable('freshness_heartbeats')) {
                    $dbRow = DB::table('freshness_heartbeats')->where('channel', 'application_health')->first();
                    if ($dbRow) {
                        $lastObserved = $dbRow->last_success_at
                            ? Carbon::parse($dbRow->last_success_at)->toIso8601String()
                            : ($dbRow->updated_at ? Carbon::parse($dbRow->updated_at)->toIso8601String() : null);
                        $lastStatus = $dbRow->status;
                        $failureReason = $dbRow->details;
                        $lastDetails = $dbRow->metadata ? (is_array($dbRow->metadata) ? $dbRow->metadata : json_decode($dbRow->metadata, true)) : null;
                    }
                }
            } catch (Throwable $e) {
                return [
                    'source' => 'application_health',
                    'name' => 'Application Health Probes',
                    'type' => 'monitoring',
                    'status' => self::STATE_UNAVAILABLE,
                    'last_observed_at' => null,
                    'source_timestamp' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'reason' => 'Không thể kết nối cơ sở dữ liệu để kiểm tra health probe: '.$e->getMessage(),
                ];
            }

            // 2. Fallback Cache nếu chưa có trong DB
            if (! $lastObserved) {
                $lastObserved = Cache::get('application_health_last_observed_at') ?: Cache::get('application_health_last_failed_at');
                $lastStatus = $lastStatus ?: Cache::get('application_health_last_status');
                $failureReason = $failureReason ?: Cache::get('application_health_failure_reason');
                $lastDetails = $lastDetails ?: Cache::get('application_health_last_details');
            }

            // Nếu probe gần nhất báo lỗi không lành mạnh
            if ($lastStatus === 'unhealthy') {
                $sourceCarbon = $lastObserved ? Carbon::parse($lastObserved) : null;
                $ageSeconds = $sourceCarbon ? (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1)) : null;

                return [
                    'source' => 'application_health',
                    'name' => 'Application Health Probes',
                    'type' => 'monitoring',
                    'status' => self::STATE_CRITICAL,
                    'last_observed_at' => $sourceCarbon?->toIso8601String(),
                    'source_timestamp' => $sourceCarbon?->toIso8601String(),
                    'age_seconds' => $ageSeconds,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'reason' => 'Health probe báo cáo hệ thống không lành mạnh: '.($failureReason ?: 'Lỗi dịch vụ'),
                    'details' => $lastDetails,
                ];
            }

            if (! $lastObserved) {
                return [
                    'source' => 'application_health',
                    'name' => 'Application Health Probes',
                    'type' => 'monitoring',
                    'status' => self::STATE_UNKNOWN,
                    'last_observed_at' => null,
                    'source_timestamp' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'reason' => 'Chưa có quan sát nào từ Health Probe (/health) được ghi nhận.',
                    'message' => 'Chưa có quan sát nào từ Health Probe (/health) được ghi nhận.',
                ];
            }

            $sourceCarbon = Carbon::parse($lastObserved);
            $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));

            $state = $this->classifyState($ageSeconds, $warn, $crit);
            $reason = match ($state) {
                self::STATE_FRESH => "Health probe phản hồi tốt, quan sát cách đây {$ageSeconds}s",
                self::STATE_STALE => "Health probe observation đã cũ {$ageSeconds}s (vượt warning {$warn}s)",
                default => "Health probe observation đã quá hạn {$ageSeconds}s (vượt critical {$crit}s)",
            };

            return [
                'source' => 'application_health',
                'name' => 'Application Health Probes',
                'type' => 'monitoring',
                'status' => $state,
                'last_observed_at' => $sourceCarbon->toIso8601String(),
                'source_timestamp' => $sourceCarbon->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'reason' => $reason,
                'details' => $lastDetails,
            ];
        } catch (Throwable $e) {
            Log::warning('Freshness evaluation failed for Health: '.$e->getMessage());

            return [
                'source' => 'application_health',
                'name' => 'Application Health Probes',
                'type' => 'monitoring',
                'status' => self::STATE_UNAVAILABLE,
                'last_observed_at' => null,
                'source_timestamp' => null,
                'age_seconds' => null,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'error' => $e->getMessage(),
                'reason' => 'Lỗi kiểm tra health freshness: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Đo lường độ tươi mới của dữ liệu CSDL (Database Data Freshness)
     * Dựa trên các bảng nghiệp vụ thực tế: audit_logs, tickets, residents, apartments, amenities, contracts, invoices, users
     */
    public function evaluateDatabaseFreshness(Carbon $now, bool $force = false): array
    {
        $dataSourcesConfig = $this->config['data_sources'] ?? [];
        $cacheTtl = (int) ($this->config['cache']['data_cache_seconds'] ?? 30);

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            Log::error('Freshness Database Connection Failed: '.$e->getMessage());
            $unavailableSources = [];
            foreach ($dataSourcesConfig as $key => $srcCfg) {
                $unavailableSources[] = [
                    'source' => $key,
                    'table' => $srcCfg['table'] ?? $key,
                    'name' => $srcCfg['name'] ?? $key,
                    'type' => 'data',
                    'status' => self::STATE_UNAVAILABLE,
                    'last_update_at' => null,
                    'source_timestamp' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $srcCfg['warning_seconds'] ?? 3600,
                    'critical_threshold' => $srcCfg['critical_seconds'] ?? 86400,
                    'error' => 'Database connection offline',
                    'reason' => 'Không thể kết nối CSDL MySQL (Database connection offline)',
                ];
            }

            return [
                'status' => self::STATE_UNAVAILABLE,
                'last_data_update_at' => null,
                'newest_data_age_seconds' => null,
                'oldest_data_age_seconds' => null,
                'age_seconds' => null,
                'reason' => 'Không thể kết nối tới cơ sở dữ liệu MySQL.',
                'sources' => $unavailableSources,
            ];
        }

        $sourcesResults = [];
        $validAges = [];
        $mostRecentUpdate = null;

        foreach ($dataSourcesConfig as $key => $srcCfg) {
            $tableName = $srcCfg['table'] ?? $key;
            $primaryField = $srcCfg['timestamp_field'] ?? 'updated_at';
            $fallbackField = $srcCfg['fallback_field'] ?? 'created_at';
            $warn = (int) ($srcCfg['warning_seconds'] ?? 86400);
            $crit = (int) ($srcCfg['critical_seconds'] ?? 259200);

            $cacheKey = "freshness_data_{$tableName}";
            if ($force) {
                Cache::forget($cacheKey);
            }

            $sourceResult = Cache::remember($cacheKey, $cacheTtl, function () use ($tableName, $primaryField, $fallbackField, $now, $warn, $crit, $srcCfg, $key) {
                try {
                    if (! Schema::hasTable($tableName)) {
                        return [
                            'source' => $key,
                            'table' => $tableName,
                            'name' => $srcCfg['name'] ?? $key,
                            'type' => 'data',
                            'status' => self::STATE_UNKNOWN,
                            'last_update_at' => null,
                            'source_timestamp' => null,
                            'age_seconds' => null,
                            'warning_threshold' => $warn,
                            'critical_threshold' => $crit,
                            'reason' => "Bảng {$tableName} chưa được tạo trong CSDL.",
                            'message' => "Bảng {$tableName} chưa được tạo trong CSDL.",
                        ];
                    }

                    $fieldToQuery = Schema::hasColumn($tableName, $primaryField) ? $primaryField : (
                        Schema::hasColumn($tableName, $fallbackField) ? $fallbackField : null
                    );

                    if (! $fieldToQuery) {
                        return [
                            'source' => $key,
                            'table' => $tableName,
                            'name' => $srcCfg['name'] ?? $key,
                            'type' => 'data',
                            'status' => self::STATE_UNKNOWN,
                            'last_update_at' => null,
                            'source_timestamp' => null,
                            'age_seconds' => null,
                            'warning_threshold' => $warn,
                            'critical_threshold' => $crit,
                            'reason' => "Không tìm thấy trường timestamp hợp lệ trên bảng {$tableName}.",
                            'message' => "Không tìm thấy trường timestamp hợp lệ trên bảng {$tableName}.",
                        ];
                    }

                    $maxTimestamp = DB::table($tableName)->max($fieldToQuery);

                    if (! $maxTimestamp) {
                        return [
                            'source' => $key,
                            'table' => $tableName,
                            'name' => $srcCfg['name'] ?? $key,
                            'type' => 'data',
                            'status' => self::STATE_UNKNOWN,
                            'last_update_at' => null,
                            'source_timestamp' => null,
                            'age_seconds' => null,
                            'warning_threshold' => $warn,
                            'critical_threshold' => $crit,
                            'reason' => "Bảng {$tableName} hiện không có bản ghi nào (empty table).",
                            'message' => "Bảng {$tableName} hiện không có bản ghi nào.",
                        ];
                    }

                    $sourceCarbon = Carbon::parse($maxTimestamp);
                    $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));
                    $state = $this->classifyState($ageSeconds, $warn, $crit);

                    $reason = match ($state) {
                        self::STATE_FRESH => "Dữ liệu bảng {$tableName} mới nhất cách đây {$ageSeconds}s",
                        self::STATE_STALE => "Dữ liệu bảng {$tableName} đã cũ {$ageSeconds}s (vượt warning {$warn}s)",
                        default => "Dữ liệu bảng {$tableName} đã quá hạn {$ageSeconds}s (vượt critical {$crit}s)",
                    };

                    return [
                        'source' => $key,
                        'table' => $tableName,
                        'name' => $srcCfg['name'] ?? $key,
                        'type' => 'data',
                        'status' => $state,
                        'last_update_at' => $sourceCarbon->toIso8601String(),
                        'source_timestamp' => $sourceCarbon->toIso8601String(),
                        'age_seconds' => $ageSeconds,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'timestamp_field' => $fieldToQuery,
                        'reason' => $reason,
                    ];
                } catch (Throwable $e) {
                    Log::warning("Freshness query failed for table {$tableName}: ".$e->getMessage());

                    return [
                        'source' => $key,
                        'table' => $tableName,
                        'name' => $srcCfg['name'] ?? $key,
                        'type' => 'data',
                        'status' => self::STATE_UNAVAILABLE,
                        'last_update_at' => null,
                        'source_timestamp' => null,
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'error' => $e->getMessage(),
                        'reason' => "Truy vấn bảng {$tableName} thất bại: ".$e->getMessage(),
                    ];
                }
            });

            $sourcesResults[] = $sourceResult;

            if ($sourceResult['age_seconds'] !== null) {
                $validAges[] = $sourceResult['age_seconds'];
            }

            if (! empty($sourceResult['last_update_at'])) {
                $carbon = Carbon::parse($sourceResult['last_update_at']);
                if (! $mostRecentUpdate || $carbon->isAfter($mostRecentUpdate)) {
                    $mostRecentUpdate = $carbon;
                }
            }
        }

        $allDbStates = array_column($sourcesResults, 'status');
        $overallDbStatus = $this->deriveOverallStatus($allDbStates);

        $newestAgeSeconds = ! empty($validAges) ? min($validAges) : null;
        $oldestAgeSeconds = ! empty($validAges) ? max($validAges) : null;

        return [
            'status' => $overallDbStatus,
            'last_data_update_at' => $mostRecentUpdate ? $mostRecentUpdate->toIso8601String() : null,
            'age_seconds' => $newestAgeSeconds,
            'newest_data_age_seconds' => $newestAgeSeconds,
            'oldest_data_age_seconds' => $oldestAgeSeconds,
            'sources' => $sourcesResults,
        ];
    }

    /**
     * Phân loại trạng thái Freshness theo ngưỡng
     */
    public function classifyState(int $ageSeconds, int $warningThreshold, int $criticalThreshold): string
    {
        if ($ageSeconds < $warningThreshold) {
            return self::STATE_FRESH;
        }

        if ($ageSeconds < $criticalThreshold) {
            return self::STATE_STALE;
        }

        return self::STATE_CRITICAL;
    }

    /**
     * Suy ra trạng thái tổng thể từ tập các trạng thái thành phần
     * CRITICAL > UNAVAILABLE > STALE > UNKNOWN > FRESH
     */
    public function deriveOverallStatus(array $states): string
    {
        if (empty($states)) {
            return self::STATE_UNKNOWN;
        }

        if (in_array(self::STATE_CRITICAL, $states, true)) {
            return self::STATE_CRITICAL;
        }

        if (in_array(self::STATE_UNAVAILABLE, $states, true)) {
            return self::STATE_UNAVAILABLE;
        }

        if (in_array(self::STATE_STALE, $states, true)) {
            return self::STATE_STALE;
        }

        $knownStates = array_filter($states, fn ($s) => $s !== self::STATE_UNKNOWN);
        if (empty($knownStates)) {
            return self::STATE_UNKNOWN;
        }

        if (count(array_filter($knownStates, fn ($s) => $s === self::STATE_FRESH)) === count($knownStates)) {
            return self::STATE_FRESH;
        }

        return self::STATE_UNKNOWN;
    }

    /**
     * Đánh giá trạng thái tổng thể minh bạch, có lý do cụ thể và xác định Worst Source
     * Precedence: CRITICAL (5) > UNAVAILABLE (4) > STALE (3) > UNKNOWN (2) > FRESH (1)
     */
    public function deriveOverallAssessment(array $allSources): array
    {
        if (empty($allSources)) {
            return [
                'state' => self::STATE_UNKNOWN,
                'worst_source' => null,
                'reason' => 'Không có nguồn giám sát nào được cung cấp.',
            ];
        }

        $weights = [
            self::STATE_CRITICAL => 5,
            self::STATE_UNAVAILABLE => 4,
            self::STATE_STALE => 3,
            self::STATE_UNKNOWN => 2,
            self::STATE_FRESH => 1,
        ];

        $worstSource = null;
        $highestWeight = -1;

        foreach ($allSources as $item) {
            $state = $item['status'] ?? self::STATE_UNKNOWN;
            $weight = $weights[$state] ?? 0;
            if ($weight > $highestWeight) {
                $highestWeight = $weight;
                $worstSource = $item;
            }
        }

        $overallState = $worstSource['status'] ?? self::STATE_UNKNOWN;
        $worstName = $worstSource['name'] ?? $worstSource['source'] ?? 'unknown';
        $worstReason = $worstSource['reason'] ?? $worstSource['message'] ?? $worstSource['error'] ?? '';

        $reason = match ($overallState) {
            self::STATE_CRITICAL => "Nguồn [{$worstName}] đang ở mức CRITICAL: {$worstReason}",
            self::STATE_UNAVAILABLE => "Nguồn [{$worstName}] không khả dụng: {$worstReason}",
            self::STATE_STALE => "Nguồn [{$worstName}] đang ở mức STALE: {$worstReason}",
            self::STATE_UNKNOWN => "Nguồn [{$worstName}] chưa có quan sát hợp lệ (UNKNOWN): {$worstReason}",
            self::STATE_FRESH => 'Tất cả các nguồn dữ liệu và giám sát đều tươi mới và hoạt động bình thường.',
            default => "Trạng thái hệ thống: {$overallState}",
        };

        return [
            'state' => $overallState,
            'worst_source' => [
                'source' => $worstSource['source'] ?? 'unknown',
                'name' => $worstName,
                'status' => $overallState,
                'reason' => $worstReason,
            ],
            'reason' => $reason,
        ];
    }

    /**
     * Theo dõi vòng đời sự cố Freshness bền vững với DB là Source of Truth duy nhất
     * Một source chỉ có tối đa 1 ACTIVE incident cho cùng một failure episode.
     * NORMAL -> STALE -> CRITICAL (update incident) -> RECOVERED (resolve + record event)
     */
    protected function trackIncidents(array $allSources): array
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh');

        try {
            if (! Schema::hasTable('freshness_incidents')) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }

        foreach ($allSources as $srcName => $obs) {
            $status = $obs['status'] ?? self::STATE_UNKNOWN;
            $type = $obs['type'] ?? 'data';
            $ageSeconds = $obs['age_seconds'] ?? null;
            $threshold = $obs['warning_threshold'] ?? null;
            $sourceTimestamp = $obs['source_timestamp'] ?? $obs['last_update_at'] ?? $obs['last_event_at'] ?? $obs['last_observed_at'] ?? null;
            $reason = $obs['reason'] ?? $obs['message'] ?? $obs['error'] ?? "Trạng thái: {$status}";

            try {
                $process = function () use ($srcName, $status, $type, $ageSeconds, $threshold, $sourceTimestamp, $reason, $now) {
                    DB::transaction(function () use ($srcName, $status, $type, $ageSeconds, $threshold, $sourceTimestamp, $reason, $now) {
                        // DB LÀ SOURCE OF TRUTH DUY NHẤT: Khóa bi quan (pessimistic lock) trên các bản ghi active của source này
                        $activeIncidents = FreshnessIncident::query()
                            ->where('source', $srcName)
                            ->whereNull('resolved_at')
                            ->lockForUpdate()
                            ->orderByDesc('id')
                            ->get();

                        // Tự động dọn dẹp các bản ghi trùng lặp từ trước (nếu có): Chỉ giữ lại 1 active duy nhất
                        if ($activeIncidents->count() > 1) {
                            $activeIncidents->slice(1)->each(function ($dup) use ($now) {
                                $dup->update([
                                    'resolved_at' => $now,
                                    'details' => ($dup->details ?: 'Sự cố cũ').' (Đóng tự động bản ghi trùng lặp)',
                                ]);
                            });
                        }

                        $activeIncident = $activeIncidents->first();

                        if (in_array($status, [self::STATE_STALE, self::STATE_CRITICAL, self::STATE_UNAVAILABLE], true)) {
                            if ($activeIncident) {
                                // Cùng 1 failure episode: Chỉ cập nhật lifecycle của incident hiện tại, KHÔNG tạo duplicate rows
                                $activeIncident->update([
                                    'state' => $status,
                                    'age_seconds' => $ageSeconds,
                                    'threshold_seconds' => $threshold,
                                    'details' => $reason,
                                ]);
                            } else {
                                // Bắt đầu sự cố mới: Tạo duy nhất 1 incident active
                                FreshnessIncident::create([
                                    'source' => $srcName,
                                    'source_type' => $type,
                                    'state' => $status,
                                    'age_seconds' => $ageSeconds,
                                    'threshold_seconds' => $threshold,
                                    'source_timestamp' => $sourceTimestamp,
                                    'detected_at' => $now,
                                    'resolved_at' => null,
                                    'details' => $reason,
                                ]);
                            }
                        } elseif ($status === self::STATE_FRESH) {
                            if ($activeIncident) {
                                // Khắc phục sự cố: Resolve toàn bộ incident đang active của source này
                                $previousState = $activeIncident->state;
                                FreshnessIncident::where('source', $srcName)
                                    ->whereNull('resolved_at')
                                    ->update([
                                        'resolved_at' => $now,
                                        'details' => DB::raw("CONCAT(COALESCE(details, ''), ' | Phục hồi tại {$now->toIso8601String()}')"),
                                    ]);

                                FreshnessIncident::create([
                                    'source' => $srcName,
                                    'source_type' => $type,
                                    'state' => 'RECOVERED',
                                    'age_seconds' => $ageSeconds,
                                    'threshold_seconds' => $threshold,
                                    'source_timestamp' => $sourceTimestamp,
                                    'detected_at' => $now,
                                    'resolved_at' => $now,
                                    'details' => "Phục hồi thành công về trạng thái FRESH từ {$previousState}",
                                ]);
                            }
                        }
                    });
                };

                // Thử áp dụng atomic cache lock nếu driver hỗ trợ để serialize ở application level
                try {
                    $lock = Cache::lock("freshness_incident_lock_{$srcName}", 5);
                    $lock->block(3, $process);
                } catch (Throwable) {
                    $process();
                }
            } catch (Throwable $e) {
                Log::error("Failed to process freshness incident for {$srcName}: ".$e->getMessage());
            }
        }

        try {
            return FreshnessIncident::query()
                ->orderBy('detected_at', 'desc')
                ->limit(20)
                ->get()
                ->toArray();
        } catch (Throwable $e) {
            return [];
        }
    }
}
