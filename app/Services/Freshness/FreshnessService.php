<?php

namespace App\Services\Freshness;

use App\Models\FreshnessIncident;
use App\Services\Cicd\GitHubActionsService;
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
     */
    public function getOverview(bool $forceRefresh = false): array
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $collectorTimestamp = $now->toIso8601String();

        $collector = $this->evaluateCollectorFreshness($now);
        $github = $this->evaluateGitHubFreshness($now, $forceRefresh);
        $deployment = $this->evaluateDeploymentFreshness($now);
        $appHealth = $this->evaluateHealthFreshness($now);
        $database = $this->evaluateDatabaseFreshness($now, $forceRefresh);

        // Đánh giá trạng thái tổng thể dựa trên tất cả các nguồn
        $allStates = array_merge(
            [$collector['status'], $github['status'], $deployment['status'], $appHealth['status']],
            array_column($database['sources'], 'status')
        );

        $overallStatus = $this->deriveOverallStatus($allStates);

        // Ghi nhận và theo dõi các sự cố Freshness (Durable Incident Tracker)
        $incidents = $this->trackIncidents($collector, $github, $deployment, $appHealth, $database['sources']);

        return [
            'status' => strtolower($overallStatus),
            'overall_state' => $overallStatus,
            'checked_at' => $collectorTimestamp,
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
     */
    public function evaluateCollectorFreshness(Carbon $now): array
    {
        $lastSuccess = Cache::get('freshness_collector_last_success_at');
        $errorsCount = (int) Cache::get('freshness_collector_errors_count', 0);

        $warnThreshold = (int) ($this->config['collector']['warning_seconds'] ?? 60);
        $critThreshold = (int) ($this->config['collector']['critical_seconds'] ?? 300);

        if (! $lastSuccess) {
            return [
                'status' => self::STATE_UNKNOWN,
                'last_success_at' => null,
                'age_seconds' => null,
                'warning_threshold' => $warnThreshold,
                'critical_threshold' => $critThreshold,
                'errors_count' => $errorsCount,
                'message' => 'Chưa có quan sát nào từ Freshness Collector được ghi nhận trong bộ nhớ đệm.',
            ];
        }

        $lastSuccessCarbon = Carbon::parse($lastSuccess);
        $ageSeconds = (int) round(max(0, $now->diffInSeconds($lastSuccessCarbon, false) * -1));

        $state = $this->classifyState($ageSeconds, $warnThreshold, $critThreshold);

        return [
            'status' => $state,
            'last_success_at' => $lastSuccessCarbon->toIso8601String(),
            'age_seconds' => $ageSeconds,
            'warning_threshold' => $warnThreshold,
            'critical_threshold' => $critThreshold,
            'errors_count' => $errorsCount,
        ];
    }

    /**
     * Ghi nhận heartbeat từ Scheduler / Collector nền (Writer)
     */
    public function recordCollectorHeartbeat(?Carbon $timestamp = null): void
    {
        $time = ($timestamp ?: Carbon::now('Asia/Ho_Chi_Minh'))->toIso8601String();
        Cache::put('freshness_collector_last_success_at', $time, 3600);
    }

    /**
     * Đo lường độ tươi mới của GitHub Actions CI/CD Runs
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

        return Cache::remember($cacheKey, $cacheTtl, function () use ($now, $warn, $crit) {
            try {
                $pipelines = $this->cicdService->getPipelines();
                if (empty($pipelines)) {
                    return [
                        'status' => self::STATE_UNKNOWN,
                        'last_event_at' => null,
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'source' => 'github_actions',
                        'message' => 'Chưa có dữ liệu workflow runs nào được ghi nhận.',
                    ];
                }

                // Tìm completed workflow runs có ý nghĩa, ưu tiên lần chạy thành công gần nhất (LỖI #16)
                $latestSuccessful = null;
                $latestCompleted = null;
                $currentRunning = null;

                foreach ($pipelines as $p) {
                    $pStatus = strtolower($p['status'] ?? '');
                    if (in_array($pStatus, ['running', 'in_progress', 'queued'], true) && ! $currentRunning) {
                        $currentRunning = $p;
                    } elseif (in_array($pStatus, ['success', 'healthy'], true) && ! $latestSuccessful) {
                        $latestSuccessful = $p;
                    } elseif (in_array($pStatus, ['failed', 'failure', 'cancelled'], true) && ! $latestCompleted) {
                        $latestCompleted = $p;
                    }
                }

                // Nếu không có status nào khớp cụ thể (ví dụ dữ liệu mock test), dùng pipeline đầu tiên
                $targetRun = $latestSuccessful ?: ($latestCompleted ?: ($currentRunning ?: $pipelines[0]));
                $rawTimestamp = $targetRun['completed_at'] ?? $targetRun['updated_at'] ?? $targetRun['created_at'] ?? null;

                if (! $rawTimestamp) {
                    return [
                        'status' => self::STATE_UNKNOWN,
                        'last_event_at' => null,
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'source' => 'github_actions',
                        'message' => 'Không tìm thấy timestamp hợp lệ trên workflow có ý nghĩa.',
                    ];
                }

                $sourceCarbon = Carbon::parse($rawTimestamp);
                $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));
                $state = $this->classifyState($ageSeconds, $warn, $crit);

                $pipelineExecutionState = $currentRunning
                    ? 'running'
                    : (($latestSuccessful && $targetRun === $latestSuccessful) ? 'success' : ($targetRun['status'] ?? 'unknown'));

                return [
                    'status' => $state,
                    'last_event_at' => $sourceCarbon->toIso8601String(),
                    'age_seconds' => $ageSeconds,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'source' => 'github_actions',
                    'workflow' => $targetRun['name'] ?? 'Pipeline',
                    'commit_sha' => $targetRun['commit_sha'] ?? null,
                    'execution_state' => $pipelineExecutionState,
                ];
            } catch (Throwable $e) {
                Log::warning('Freshness evaluation failed for GitHub Actions: '.$e->getMessage());

                return [
                    'status' => self::STATE_UNAVAILABLE,
                    'last_event_at' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'source' => 'github_actions',
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Đo lường độ tươi mới của Triển khai & Bản phát hành (Deployment Freshness)
     */
    public function evaluateDeploymentFreshness(Carbon $now): array
    {
        $cfg = $this->config['monitoring_sources']['deployment'] ?? [];
        $warn = (int) ($cfg['warning_seconds'] ?? 604800);
        $crit = (int) ($cfg['critical_seconds'] ?? 2592000);

        try {
            $deployments = $this->cicdService->getDeployments();
            $latestDeploy = null;

            foreach ($deployments as $dep) {
                $depStatus = strtolower($dep['status'] ?? '');
                // Chỉ ghi nhận deployment đã hoàn tất thành công (healthy/success), không lấy created_at bừa bãi
                if (in_array($depStatus, ['healthy', 'success'], true)) {
                    $rawDate = $dep['deployed_at'] ?? null;
                    if (! empty($rawDate) && ! in_array($rawDate, ['Chưa kích hoạt', 'Chưa hoàn tất', 'Đang triển khai', 'N/A'], true)) {
                        $latestDeploy = $dep;
                        break;
                    }
                }
            }

            if (! $latestDeploy || empty($latestDeploy['deployed_at'])) {
                return [
                    'status' => self::STATE_UNKNOWN,
                    'last_event_at' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'source' => 'deployment',
                    'message' => 'Chưa có bản phát hành/triển khai thành công nào được ghi nhận trên môi trường mục tiêu.',
                ];
            }

            $sourceCarbon = Carbon::parse($latestDeploy['deployed_at']);
            $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));
            $state = $this->classifyState($ageSeconds, $warn, $crit);

            return [
                'status' => $state,
                'last_event_at' => $sourceCarbon->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'source' => 'deployment',
                'environment' => $latestDeploy['environment'] ?? 'production',
                'version' => $latestDeploy['version'] ?? null,
            ];
        } catch (Throwable $e) {
            Log::warning('Freshness evaluation failed for Deployment: '.$e->getMessage());

            return [
                'status' => self::STATE_UNAVAILABLE,
                'last_event_at' => null,
                'age_seconds' => null,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'source' => 'deployment',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Đo lường độ tươi mới của quan sát Health Probe (Application Health Observation Freshness)
     * FreshnessService CHỈ ĐỌC observation, KHÔNG tự cập nhật timestamp đang đo (LỖI #11)
     */
    public function evaluateHealthFreshness(Carbon $now): array
    {
        $cfg = $this->config['monitoring_sources']['application_health'] ?? [];
        $warn = (int) ($cfg['warning_seconds'] ?? 60);
        $crit = (int) ($cfg['critical_seconds'] ?? 180);

        try {
            $lastObserved = Cache::get('application_health_last_observed_at');
            if (! $lastObserved) {
                // CHỈ ĐỌC: Không tự Cache::put() để tránh hiện tượng self-observing / tự làm tươi
                return [
                    'status' => self::STATE_UNKNOWN,
                    'last_observed_at' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'source' => 'application_health',
                    'message' => 'Chưa có quan sát nào từ Health Probe được ghi nhận trong bộ nhớ đệm.',
                ];
            }

            $sourceCarbon = Carbon::parse($lastObserved);
            $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));
            $state = $this->classifyState($ageSeconds, $warn, $crit);

            // KHÔNG CẬP NHẬT CACHE TẠI ĐÂY — Health Probe lưu trữ riêng, FreshnessService chỉ đánh giá

            return [
                'status' => $state,
                'last_observed_at' => $sourceCarbon->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'source' => 'application_health',
            ];
        } catch (Throwable $e) {
            return [
                'status' => self::STATE_UNAVAILABLE,
                'last_observed_at' => null,
                'age_seconds' => null,
                'warning_threshold' => $warn,
                'critical_threshold' => $crit,
                'source' => 'application_health',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Đo lường độ tươi mới của dữ liệu CSDL (Database Data Freshness)
     * Dựa trên các bảng nghiệp vụ thực tế: residents, apartments, amenities, contracts, invoices, tickets, audit_logs, users
     */
    public function evaluateDatabaseFreshness(Carbon $now, bool $force = false): array
    {
        $dataSourcesConfig = $this->config['data_sources'] ?? [];
        $cacheTtl = (int) ($this->config['cache']['data_cache_seconds'] ?? 30);

        // Kiểm tra kết nối CSDL trước
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
                    'age_seconds' => null,
                    'warning_threshold' => $srcCfg['warning_seconds'] ?? 3600,
                    'critical_threshold' => $srcCfg['critical_seconds'] ?? 86400,
                    'error' => 'Database connection offline',
                ];
            }

            return [
                'status' => self::STATE_UNAVAILABLE,
                'last_data_update_at' => null,
                'age_seconds' => null,
                'sources' => $unavailableSources,
            ];
        }

        $sourcesResults = [];
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
                            'age_seconds' => null,
                            'warning_threshold' => $warn,
                            'critical_threshold' => $crit,
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
                            'age_seconds' => null,
                            'warning_threshold' => $warn,
                            'critical_threshold' => $crit,
                            'message' => "Không tìm thấy trường timestamp hợp lệ trên bảng {$tableName}.",
                        ];
                    }

                    // Tối ưu hóa truy vấn: CHỈ lấy MAX(column), không load toàn bộ bảng
                    $maxTimestamp = DB::table($tableName)->max($fieldToQuery);

                    if (! $maxTimestamp) {
                        return [
                            'source' => $key,
                            'table' => $tableName,
                            'name' => $srcCfg['name'] ?? $key,
                            'type' => 'data',
                            'status' => self::STATE_UNKNOWN,
                            'last_update_at' => null,
                            'age_seconds' => null,
                            'warning_threshold' => $warn,
                            'critical_threshold' => $crit,
                            'message' => "Bảng {$tableName} hiện không có bản ghi nào.",
                        ];
                    }

                    $sourceCarbon = Carbon::parse($maxTimestamp);
                    $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));
                    $state = $this->classifyState($ageSeconds, $warn, $crit);

                    return [
                        'source' => $key,
                        'table' => $tableName,
                        'name' => $srcCfg['name'] ?? $key,
                        'type' => 'data',
                        'status' => $state,
                        'last_update_at' => $sourceCarbon->toIso8601String(),
                        'age_seconds' => $ageSeconds,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'timestamp_field' => $fieldToQuery,
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
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'error' => $e->getMessage(),
                    ];
                }
            });

            $sourcesResults[] = $sourceResult;

            if (! empty($sourceResult['last_update_at'])) {
                $carbon = Carbon::parse($sourceResult['last_update_at']);
                if (! $mostRecentUpdate || $carbon->isAfter($mostRecentUpdate)) {
                    $mostRecentUpdate = $carbon;
                }
            }
        }

        $allDbStates = array_column($sourcesResults, 'status');
        $overallDbStatus = $this->deriveOverallStatus($allDbStates);

        $dbAgeSeconds = $mostRecentUpdate ? (int) round(max(0, $now->diffInSeconds($mostRecentUpdate, false) * -1)) : null;

        return [
            'status' => $overallDbStatus,
            'last_data_update_at' => $mostRecentUpdate ? $mostRecentUpdate->toIso8601String() : null,
            'age_seconds' => $dbAgeSeconds,
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
     * Theo dõi vòng đời sự cố Freshness bền vững (Durable Freshness Incident Tracking)
     * NORMAL -> STALE -> CRITICAL -> RECOVERY -> NORMAL
     */
    protected function trackIncidents(
        array $collector,
        array $github,
        array $deployment,
        array $appHealth,
        array $dbSources
    ): array {
        $observations = [
            'collector' => [
                'type' => 'monitoring',
                'status' => $collector['status'],
                'age_seconds' => $collector['age_seconds'],
                'threshold' => $collector['warning_threshold'] ?? 60,
                'source_timestamp' => $collector['last_success_at'] ?? null,
            ],
            'github_actions' => [
                'type' => 'monitoring',
                'status' => $github['status'],
                'age_seconds' => $github['age_seconds'],
                'threshold' => $github['warning_threshold'] ?? 3600,
                'source_timestamp' => $github['last_event_at'] ?? null,
            ],
            'deployment' => [
                'type' => 'monitoring',
                'status' => $deployment['status'],
                'age_seconds' => $deployment['age_seconds'],
                'threshold' => $deployment['warning_threshold'] ?? 604800,
                'source_timestamp' => $deployment['last_event_at'] ?? null,
            ],
            'application_health' => [
                'type' => 'monitoring',
                'status' => $appHealth['status'],
                'age_seconds' => $appHealth['age_seconds'],
                'threshold' => $appHealth['warning_threshold'] ?? 60,
                'source_timestamp' => $appHealth['last_observed_at'] ?? null,
            ],
        ];

        foreach ($dbSources as $src) {
            $srcKey = $src['source'] ?? $src['table'] ?? 'unknown_db';
            $observations[$srcKey] = [
                'type' => 'data',
                'status' => $src['status'],
                'age_seconds' => $src['age_seconds'],
                'threshold' => $src['warning_threshold'] ?? 86400,
                'source_timestamp' => $src['last_update_at'] ?? null,
            ];
        }

        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $hasIncidentsTable = false;

        try {
            $hasIncidentsTable = Schema::hasTable('freshness_incidents');
        } catch (Throwable $e) {
            // DB unavailable
        }

        foreach ($observations as $srcName => $obs) {
            $status = $obs['status'];
            $cacheStateKey = "freshness_state_history_{$srcName}";
            $previousState = Cache::get($cacheStateKey, self::STATE_FRESH);

            if ($status !== $previousState) {
                Cache::put($cacheStateKey, $status, 86400);

                // Phát hiện chuyển đổi trạng thái: Bắt đầu sự cố hoặc phục hồi
                if (in_array($status, [self::STATE_STALE, self::STATE_CRITICAL, self::STATE_UNAVAILABLE], true)) {
                    Log::warning("Freshness incident detected for [{$srcName}]: {$previousState} -> {$status} (age: {$obs['age_seconds']}s)");

                    if ($hasIncidentsTable) {
                        try {
                            FreshnessIncident::create([
                                'source' => $srcName,
                                'source_type' => $obs['type'],
                                'state' => $status,
                                'age_seconds' => $obs['age_seconds'],
                                'threshold_seconds' => $obs['threshold'],
                                'source_timestamp' => $obs['source_timestamp'],
                                'detected_at' => $now,
                                'details' => "Trạng thái chuyển từ {$previousState} sang {$status}",
                            ]);
                        } catch (Throwable $e) {
                            Log::error("Failed to persist freshness incident: {$e->getMessage()}");
                        }
                    }
                } elseif ($status === self::STATE_FRESH && in_array($previousState, [self::STATE_STALE, self::STATE_CRITICAL, self::STATE_UNAVAILABLE], true)) {
                    // Phục hồi từ sự cố (RECOVERED)
                    Log::info("Freshness recovered for [{$srcName}]: {$previousState} -> FRESH (age: {$obs['age_seconds']}s)");

                    if ($hasIncidentsTable) {
                        try {
                            // Đóng sự cố mở gần nhất
                            FreshnessIncident::where('source', $srcName)
                                ->whereNull('resolved_at')
                                ->update(['resolved_at' => $now]);

                            FreshnessIncident::create([
                                'source' => $srcName,
                                'source_type' => $obs['type'],
                                'state' => 'RECOVERED',
                                'age_seconds' => $obs['age_seconds'],
                                'threshold_seconds' => $obs['threshold'],
                                'source_timestamp' => $obs['source_timestamp'],
                                'detected_at' => $now,
                                'resolved_at' => $now,
                                'details' => "Phục hồi thành công về trạng thái FRESH từ {$previousState}",
                            ]);
                        } catch (Throwable $e) {
                            Log::error("Failed to record freshness recovery: {$e->getMessage()}");
                        }
                    }
                }
            }
        }

        // Lấy danh sách sự cố gần nhất từ CSDL nếu có
        if ($hasIncidentsTable) {
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

        return [];
    }
}
