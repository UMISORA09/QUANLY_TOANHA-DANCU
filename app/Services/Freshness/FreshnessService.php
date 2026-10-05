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

    protected FreshnessStrategyRegistry $registry;

    public function __construct(
        protected GitHubActionsService $cicdService,
        ?FreshnessStrategyRegistry $registry = null
    ) {
        $this->config = config('freshness', []);
        $this->registry = $registry ?: app(FreshnessStrategyRegistry::class);
        $this->registry->bindCicdService($this->cicdService);
    }

    public function getRegistry(): FreshnessStrategyRegistry
    {
        return $this->registry;
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
        return $this->registry->for('collector')->evaluate($now);
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
     */
    public function evaluateGitHubFreshness(Carbon $now, bool $force = false): array
    {
        return $this->registry->for('github_actions')->evaluate($now, $force);
    }

    /**
     * Đo lường độ tươi mới của Triển khai & Bản phát hành (Deployment Freshness)
     */
    public function evaluateDeploymentFreshness(Carbon $now, bool $force = false): array
    {
        return $this->registry->for('deployment')->evaluate($now, $force);
    }

    /**
     * Đo lường độ tươi mới của quan sát Health Probe (Application Health Observation Freshness)
     * DB table freshness_heartbeats là Source of Truth, Cache dùng để tối ưu đọc.
     * FreshnessService CHỈ ĐỌC observation, TUYỆT ĐỐI KHÔNG tự cập nhật timestamp đang đo.
     */
    public function evaluateHealthFreshness(Carbon $now): array
    {
        return $this->registry->for('application_health')->evaluate($now);
    }

    /**
     * Đo lường độ tươi mới của dữ liệu CSDL (Database Data Freshness)
     * Dựa trên các bảng nghiệp vụ thực tế: audit_logs, tickets, residents, apartments, amenities, contracts, invoices, users
     */
    public function evaluateDatabaseFreshness(Carbon $now, bool $force = false): array
    {
        return $this->registry->for('database')->evaluate($now, $force);
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
