<?php

namespace App\Console\Commands;

use App\Models\FreshnessIncident;
use App\Services\Freshness\FreshnessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FreshnessCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freshness:check {--json : Xuất kết quả dưới định dạng JSON} {--force : Bỏ qua bộ nhớ đệm}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kiểm tra độ tươi mới thực tế của Collector, GitHub Actions, Deployment, Health và Database';

    /**
     * Execute the console command.
     */
    public function handle(FreshnessService $freshnessService): int
    {
        $force = (bool) $this->option('force');
        $overview = $freshnessService->getOverview($force);

        if ($this->option('json')) {
            $this->output->writeln(json_encode($overview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $this->resolveExitCode($overview['overall_state']);
        }

        $this->info('=== SMART CASSAVAS FRESHNESS OBSERVABILITY AUDIT ===');
        $this->line('Checked at: '.$overview['checked_at']);
        $this->line('----------------------------------------------------');

        $this->displaySourceCheck('Collector', $overview['collector']);
        $this->displaySourceCheck('GitHub Actions', $overview['github_actions']);
        $this->displaySourceCheck('Deployment', $overview['deployment']);
        $this->displaySourceCheck('Application Health', $overview['application_health']);
        $this->displayDatabaseCheck($overview['database']);
        $this->displayIncidentCheck($overview['incidents']);

        $this->line('----------------------------------------------------');
        $overallState = $overview['overall_state'];
        $overallReason = $overview['overall_reason'] ?? 'N/A';

        $stateColor = match ($overallState) {
            FreshnessService::STATE_FRESH => 'info',
            FreshnessService::STATE_STALE => 'warn',
            FreshnessService::STATE_CRITICAL => 'error',
            FreshnessService::STATE_UNAVAILABLE => 'error',
            default => 'comment',
        };

        $this->$stateColor("Overall: {$overallState}");
        $this->line("Reason: {$overallReason}");

        if (! empty($overview['worst_source'])) {
            $ws = $overview['worst_source'];
            $this->line("Worst Source: [{$ws['name']}] - {$ws['status']} ({$ws['reason']})");
        }

        return $this->resolveExitCode($overallState);
    }

    protected function displaySourceCheck(string $label, array $data): void
    {
        $status = $data['status'] ?? FreshnessService::STATE_UNKNOWN;
        $age = isset($data['age_seconds']) && $data['age_seconds'] !== null ? "{$data['age_seconds']}s" : 'N/A';
        $reason = $data['reason'] ?? $data['message'] ?? $data['error'] ?? '';

        $symbol = match ($status) {
            FreshnessService::STATE_FRESH => '<info>✓</info>',
            FreshnessService::STATE_STALE => '<comment>!</comment>',
            FreshnessService::STATE_CRITICAL, FreshnessService::STATE_UNAVAILABLE => '<error>✗</error>',
            default => '<comment>?</comment>',
        };

        $this->line("{$symbol} {$label}: [{$status}] (age: {$age}) - {$reason}");
    }

    protected function displayDatabaseCheck(array $database): void
    {
        $status = $database['status'] ?? FreshnessService::STATE_UNKNOWN;
        $newest = isset($database['newest_data_age_seconds']) && $database['newest_data_age_seconds'] !== null ? "{$database['newest_data_age_seconds']}s" : 'N/A';
        $oldest = isset($database['oldest_data_age_seconds']) && $database['oldest_data_age_seconds'] !== null ? "{$database['oldest_data_age_seconds']}s" : 'N/A';

        $symbol = match ($status) {
            FreshnessService::STATE_FRESH => '<info>✓</info>',
            FreshnessService::STATE_STALE => '<comment>!</comment>',
            FreshnessService::STATE_CRITICAL, FreshnessService::STATE_UNAVAILABLE => '<error>✗</error>',
            default => '<comment>?</comment>',
        };

        $count = count($database['sources'] ?? []);
        $this->line("{$symbol} Database: [{$status}] ({$count} bảng) - Newest Age: {$newest}, Oldest Age: {$oldest}");
    }

    protected function displayIncidentCheck(array $incidents): void
    {
        $activeCount = 0;
        $inconsistentCount = 0;

        try {
            if (Schema::hasTable('freshness_incidents')) {
                $activeCount = FreshnessIncident::whereNull('resolved_at')->count();
                // Kiểm tra tính nhất quán: Sự cố RECOVERED nhưng không có resolved_at
                $inconsistentCount = FreshnessIncident::where('state', 'RECOVERED')->whereNull('resolved_at')->count();
            }
        } catch (Throwable) {
            // DB offline
        }

        if ($inconsistentCount > 0) {
            $this->line("<error>✗</error> Incident Tracker: Phát hiện {$inconsistentCount} bản ghi sự cố không nhất quán");
        } else {
            $symbol = $activeCount === 0 ? '<info>✓</info>' : '<comment>!</comment>';
            $this->line("{$symbol} Incident Tracker: {$activeCount} sự cố đang active");
        }
    }

    protected function resolveExitCode(string $state): int
    {
        return match ($state) {
            FreshnessService::STATE_FRESH => 0,
            FreshnessService::STATE_STALE => 1,
            FreshnessService::STATE_CRITICAL, FreshnessService::STATE_UNAVAILABLE => 2,
            default => 1, // UNKNOWN -> warning/stale
        };
    }
}
