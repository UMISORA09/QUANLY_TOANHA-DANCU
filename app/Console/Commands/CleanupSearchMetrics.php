<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('search:cleanup-metrics {--dry-run : Count expired metrics without deleting them}')]
#[Description('Remove anonymous search measurements older than 30 days')]
class CleanupSearchMetrics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = DB::table('search_request_metrics')->where('created_at', '<', now()->subDays(30));
        $count = $this->option('dry-run') ? $query->count() : $query->delete();
        $this->info(($this->option('dry-run') ? 'Expired measurements: ' : 'Removed measurements: ').$count);

        return self::SUCCESS;
    }
}
