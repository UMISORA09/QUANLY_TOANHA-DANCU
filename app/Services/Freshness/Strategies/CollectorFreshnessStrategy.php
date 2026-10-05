<?php

namespace App\Services\Freshness\Strategies;

use App\Services\Freshness\Contracts\FreshnessSourceStrategyInterface;
use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CollectorFreshnessStrategy implements FreshnessSourceStrategyInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {}

    public function source(): string
    {
        return 'collector';
    }

    public function evaluate(Carbon $now, bool $force = false): array
    {
        $warnThreshold = (int) ($this->config['collector']['warning_seconds'] ?? 60);
        $critThreshold = (int) ($this->config['collector']['critical_seconds'] ?? 300);
        $errorsCount = (int) Cache::get('freshness_collector_errors_count', 0);

        $lastSuccess = null;
        try {
            if (Schema::hasTable('freshness_heartbeats')) {
                $dbRow = DB::table('freshness_heartbeats')->where('channel', 'collector')->first();
                if ($dbRow && $dbRow->last_success_at) {
                    $lastSuccess = Carbon::parse($dbRow->last_success_at)->toIso8601String();
                }
            }
        } catch (Throwable) {
            return [
                'source' => 'collector',
                'name' => 'Freshness Collector (Scheduler)',
                'type' => 'monitoring',
                'status' => FreshnessService::STATE_UNAVAILABLE,
                'last_success_at' => null,
                'source_timestamp' => null,
                'age_seconds' => null,
                'warning_threshold' => $warnThreshold,
                'critical_threshold' => $critThreshold,
                'errors_count' => $errorsCount,
                'reason' => 'Không thể kết nối cơ sở dữ liệu để kiểm tra collector heartbeat.',
            ];
        }

        if (! $lastSuccess) {
            $lastSuccess = Cache::get('freshness_collector_last_success_at');
        }

        if (! $lastSuccess) {
            return [
                'source' => 'collector',
                'name' => 'Freshness Collector (Scheduler)',
                'type' => 'monitoring',
                'status' => FreshnessService::STATE_UNKNOWN,
                'last_success_at' => null,
                'source_timestamp' => null,
                'age_seconds' => null,
                'warning_threshold' => $warnThreshold,
                'critical_threshold' => $critThreshold,
                'errors_count' => $errorsCount,
                'reason' => 'Chưa ghi nhận heartbeat thành công từ Collector nền.',
            ];
        }

        $lastSuccessCarbon = Carbon::parse($lastSuccess);
        $ageSeconds = (int) round(max(0, $now->diffInSeconds($lastSuccessCarbon, false) * -1));

        if ($ageSeconds < $warnThreshold) {
            $status = FreshnessService::STATE_FRESH;
            $reason = "Collector hoạt động bình thường, heartbeat cách đây {$ageSeconds}s";
        } elseif ($ageSeconds < $critThreshold) {
            $status = FreshnessService::STATE_STALE;
            $reason = "Collector chậm trễ, heartbeat cách đây {$ageSeconds}s (vượt warning {$warnThreshold}s)";
        } else {
            $status = FreshnessService::STATE_CRITICAL;
            $reason = "Collector mất tín hiệu, heartbeat cách đây {$ageSeconds}s (vượt critical {$critThreshold}s)";
        }

        return [
            'source' => 'collector',
            'name' => 'Freshness Collector (Scheduler)',
            'type' => 'monitoring',
            'status' => $status,
            'last_success_at' => $lastSuccessCarbon->toIso8601String(),
            'source_timestamp' => $lastSuccessCarbon->toIso8601String(),
            'age_seconds' => $ageSeconds,
            'warning_threshold' => $warnThreshold,
            'critical_threshold' => $critThreshold,
            'errors_count' => $errorsCount,
            'reason' => $reason,
        ];
    }
}
