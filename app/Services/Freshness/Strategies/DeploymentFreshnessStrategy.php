<?php

namespace App\Services\Freshness\Strategies;

use App\Services\Cicd\GitHubActionsService;
use App\Services\Freshness\Contracts\FreshnessSourceStrategyInterface;
use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeploymentFreshnessStrategy implements FreshnessSourceStrategyInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected GitHubActionsService $cicdService,
        protected array $config = []
    ) {}

    public function setCicdService(GitHubActionsService $cicdService): self
    {
        $this->cicdService = $cicdService;

        return $this;
    }

    public function source(): string
    {
        return 'deployment';
    }

    public function evaluate(Carbon $now, bool $force = false): array
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
                    'status' => FreshnessService::STATE_UNKNOWN,
                    'last_event_at' => null,
                    'source_timestamp' => null,
                    'age_seconds' => null,
                    'warning_threshold' => $warn,
                    'critical_threshold' => $crit,
                    'reason' => 'Chưa có bản phát hành/triển khai nào được ghi nhận trên môi trường mục tiêu.',
                    'message' => 'Chưa có bản phát hành/triển khai nào được ghi nhận.',
                ];
            }

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
                        // Ignore non-parseable date strings
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
                    'status' => FreshnessService::STATE_CRITICAL,
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
                    'status' => FreshnessService::STATE_UNKNOWN,
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
                    'status' => FreshnessService::STATE_UNKNOWN,
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
                FreshnessService::STATE_FRESH => "Bản phát hành [{$env}: {$version}] hoạt động bình thường, triển khai cách đây {$ageSeconds}s",
                FreshnessService::STATE_STALE => "Bản phát hành [{$env}: {$version}] đã triển khai cách đây {$ageSeconds}s (vượt warning {$warn}s)",
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
                'status' => FreshnessService::STATE_UNAVAILABLE,
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
