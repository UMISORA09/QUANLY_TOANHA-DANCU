<?php

namespace App\Console\Commands;

use App\Services\Search\SurveySearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSurveySearch extends Command
{
    protected $signature = 'search:sync-surveys {--dry-run}';

    protected $description = 'Queue Elasticsearch synchronization for surveys';

    public function handle(SurveySearch $search): int
    {
        $surveys = DB::table('satisfaction_surveys')->select('id');
        if ($this->option('dry-run')) {
            $this->info('Surveys to synchronize: '.$surveys->count());

            return self::SUCCESS;
        }
        if (! SurveySearch::enabled()) {
            $this->error('Set SEARCH_DRIVER=elasticsearch before synchronizing surveys.');

            return self::FAILURE;
        }

        $search->configure();
        $surveys->chunkById(100, function ($rows) use ($search): void {
            foreach ($rows as $row) {
                $search->queue($row->id);
            }
        });

        return self::SUCCESS;
    }
}
