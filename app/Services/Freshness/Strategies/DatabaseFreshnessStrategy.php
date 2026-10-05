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

class DatabaseFreshnessStrategy implements FreshnessSourceStrategyInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {}

    public function source(): string
    {
        return 'database';
    }

    public function evaluate(Carbon $now, bool $force = false): array
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
                    'status' => FreshnessService::STATE_UNAVAILABLE,
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
                'status' => FreshnessService::STATE_UNAVAILABLE,
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
                            'status' => FreshnessService::STATE_UNKNOWN,
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
                            'status' => FreshnessService::STATE_UNKNOWN,
                            'last_update_at' => null,
                            'source_timestamp' => null,
                            'age_seconds' => null,
                            'warning_threshold' => $warn,
                            'critical_threshold' => $crit,
                            'reason' => "Không tìm thấy cột thời gian trên bảng {$tableName}.",
                            'message' => "Không tìm thấy cột thời gian trên bảng {$tableName}.",
                        ];
                    }

                    $rawVal = DB::table($tableName)
                        ->whereNotNull($fieldToQuery)
                        ->max($fieldToQuery);

                    if (! $rawVal) {
                        return [
                            'source' => $key,
                            'table' => $tableName,
                            'name' => $srcCfg['name'] ?? $key,
                            'type' => 'data',
                            'status' => FreshnessService::STATE_UNKNOWN,
                            'last_update_at' => null,
                            'source_timestamp' => null,
                            'age_seconds' => null,
                            'warning_threshold' => $warn,
                            'critical_threshold' => $crit,
                            'reason' => "Bảng {$tableName} chưa có bản ghi nào (Dữ liệu trống).",
                            'message' => "Bảng {$tableName} chưa có bản ghi nào (Dữ liệu trống).",
                        ];
                    }

                    $sourceCarbon = Carbon::parse($rawVal);
                    $ageSeconds = (int) round(max(0, $now->diffInSeconds($sourceCarbon, false) * -1));
                    $state = $this->classifyState($ageSeconds, $warn, $crit);

                    $reason = match ($state) {
                        FreshnessService::STATE_FRESH => "Dữ liệu bảng {$tableName} mới cập nhật ({$ageSeconds}s trước)",
                        FreshnessService::STATE_STALE => "Dữ liệu bảng {$tableName} chậm cập nhật ({$ageSeconds}s trước, vượt {$warn}s)",
                        default => "Dữ liệu bảng {$tableName} quá hạn ({$ageSeconds}s trước, vượt {$crit}s)",
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
                        'reason' => $reason,
                    ];
                } catch (Throwable $e) {
                    Log::error("Error evaluating database freshness for table {$tableName}: ".$e->getMessage());

                    return [
                        'source' => $key,
                        'table' => $tableName,
                        'name' => $srcCfg['name'] ?? $key,
                        'type' => 'data',
                        'status' => FreshnessService::STATE_UNAVAILABLE,
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

    protected function deriveOverallStatus(array $states): string
    {
        if (empty($states)) {
            return FreshnessService::STATE_UNKNOWN;
        }

        if (in_array(FreshnessService::STATE_CRITICAL, $states, true)) {
            return FreshnessService::STATE_CRITICAL;
        }

        if (in_array(FreshnessService::STATE_UNAVAILABLE, $states, true)) {
            return FreshnessService::STATE_UNAVAILABLE;
        }

        if (in_array(FreshnessService::STATE_STALE, $states, true)) {
            return FreshnessService::STATE_STALE;
        }

        $knownStates = array_filter($states, fn ($s) => $s !== FreshnessService::STATE_UNKNOWN);
        if (empty($knownStates)) {
            return FreshnessService::STATE_UNKNOWN;
        }

        if (count(array_filter($knownStates, fn ($s) => $s === FreshnessService::STATE_FRESH)) === count($knownStates)) {
            return FreshnessService::STATE_FRESH;
        }

        return FreshnessService::STATE_UNKNOWN;
    }
}
