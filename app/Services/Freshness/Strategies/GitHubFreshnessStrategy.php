<?php

namespace App\Services\Freshness\Strategies;

use App\Services\Cicd\GitHubActionsService;
use App\Services\Freshness\Contracts\FreshnessSourceStrategyInterface;
use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GitHubFreshnessStrategy implements FreshnessSourceStrategyInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected GitHubActionsService $cicdService,
        protected array $config = []
    ) {}

    public function source(): string
    {
        return 'github_actions';
    }

    public function evaluate(Carbon $now, bool $force = false): array
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
                        'status' => FreshnessService::STATE_UNKNOWN,
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
                        'status' => FreshnessService::STATE_UNAVAILABLE,
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
                        'status' => FreshnessService::STATE_UNKNOWN,
                        'last_event_at' => null,
                        'source_timestamp' => null,
                        'age_seconds' => null,
                        'warning_threshold' => $warn,
                        'critical_threshold' => $crit,
                        'reason' => 'no workflow runs',
                        'message' => 'no workflow runs',
                    ];
                }

                $hasRunning = false;
                foreach ($pipelines as $pipe) {
                    $st = strtolower((string) ($pipe['status'] ?? $pipe['raw_status'] ?? $pipe['conclusion'] ?? ''));
                    if (in_array($st, ['running', 'in_progress', 'queued', 'pending'], true)) {
                        $hasRunning = true;
                        break;
                    }
                }

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
                        'status' => FreshnessService::STATE_UNKNOWN,
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
                    $state = FreshnessService::STATE_CRITICAL;
                    $reason = "Workflow gần nhất [{$runName}] thất bại tại commit {$commitSha}";
                } elseif ($isRunning) {
                    $state = $this->classifyState($ageSeconds, $warn, $crit);
                    $reason = "Workflow [{$runName}] đang chạy tại commit {$commitSha}";
                } else {
                    $state = $this->classifyState($ageSeconds, $warn, $crit);
                    $reason = match ($state) {
                        FreshnessService::STATE_FRESH => "Workflow [{$runName}] thành công, cách đây {$ageSeconds}s",
                        FreshnessService::STATE_STALE => "Workflow [{$runName}] chạy cách đây {$ageSeconds}s (vượt warning {$warn}s)",
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
                    'status' => FreshnessService::STATE_UNAVAILABLE,
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
