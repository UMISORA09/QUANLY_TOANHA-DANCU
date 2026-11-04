<?php

namespace App\Console\Commands;

use App\Services\Search\SuggestionSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSuggestionSearch extends Command
{
    protected $signature = 'search:sync-suggestions {--dry-run} {--queue-only : Skip creating the remote index in this process}';

    protected $description = 'Queue Elasticsearch synchronization for identified suggestions';

    public function handle(SuggestionSearch $search): int
    {
        $suggestions = DB::table('suggestions')->where('is_anonymous', false)->select('id');
        if ($this->option('dry-run')) {
            $this->info('Suggestions to synchronize: '.$suggestions->count());

            return self::SUCCESS;
        }
        if (! SuggestionSearch::enabled()) {
            $this->error('Set SEARCH_DRIVER=elasticsearch before synchronizing suggestions.');

            return self::FAILURE;
        }

        if (! $this->option('queue-only')) {
            $search->configure();
        }
        $suggestions->chunkById(100, function ($rows) use ($search): void {
            foreach ($rows as $row) {
                $search->queue($row->id);
            }
        });

        return self::SUCCESS;
    }
}
