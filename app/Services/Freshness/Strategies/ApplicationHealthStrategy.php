<?php

namespace App\Services\Freshness\Strategies;

use App\Services\Freshness\Contracts\FreshnessSourceStrategyInterface;
use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ApplicationHealthStrategy implements FreshnessSourceStrategyInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {}

    public function source(): string
    {
        return 'application_health';
    }

    public function evaluate(Carbon $now, bool $force = false): array
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
                    'status' => FreshnessService::STATE_UNAVAILABLE,
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
                    'status' => FreshnessService::STATE_CRITICAL,
                    'last_observed_at' => $lastObserved,
                    'source_timestamp' => $lastObserved,
                    'age_seconds' => $ageSeconds,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'probe_status' => 'unhealthy',
                    'reason' => 'Health probe gần nhất thất bại: '.($failureReason ?: 'Dịch vụ gặp lỗi nội bộ'),
                    'details' => $lastDetails,
                ];
            }

            // 3. Nếu chưa từng có probe nào được ghi nhận -> UNKNOWN
            if (! $lastObserved) {
                return [
                    'source' => 'application_health',
                    'name' => 'Application Health Probes',
                    'type' => 'monitoring',
                    'status' => FreshnessService::STATE_UNKNOWN,
                    'last_observed_at' => null,
                    'source_timestamp' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'reason' => 'Chưa có quan sát health probe nào được thực hiện hoặc ghi nhận.',
                ];
            }

            // 4. Probe thành công: tính toán tuổi thực tế của quan sát
            $sourceCarbon = Carbon::parse($lastObserved);
            $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));
            $state = $this->classifyState($ageSeconds, $warn, $crit);

            $reason = match ($state) {
                FreshnessService::STATE_FRESH => "Health probe định kỳ hoạt động tốt, quan sát cách đây {$ageSeconds}s",
                FreshnessService::STATE_STALE => "Health probe chậm trễ, quan sát cách đây {$ageSeconds}s (vượt warning {$warn}s)",
                default => "Health probe mất tín hiệu, quan sát cách đây {$ageSeconds}s (vượt critical {$crit}s)",
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
                'probe_status' => $lastStatus ?: 'healthy',
                'reason' => $reason,
                'details' => $lastDetails,
            ];
        } catch (Throwable $e) {
            Log::warning('Freshness evaluation failed for Health: '.$e->getMessage());

            return [
                'source' => 'application_health',
                'name' => 'Application Health Probes',
                'type' => 'monitoring',
                'status' => FreshnessService::STATE_UNAVAILABLE,
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

    protected function classifyState(int $ageSeconds, int $warningThreshold, int $criticalThreshold): string
    {
        if ($ageSeconds < $warningThreshold) {
            return FreshnessService::STATE_FRESH;
        }

        if ($ageSeconds < $criticalThreshold) {
            return FreshnessService::STATE_STALE;
        }

        return FreshnessService::STATE_CRITICAL;
    }
}
