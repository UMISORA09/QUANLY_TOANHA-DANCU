<?php

namespace App\Console\Commands;

use App\Services\Search\AnnouncementSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAnnouncementSearch extends Command
{
    protected $signature = 'search:sync-announcements {--dry-run} {--queue-only : Skip creating the remote index in this process}';

    protected $description = 'Queue Elasticsearch synchronization for current and archived announcements';

    public function handle(AnnouncementSearch $search): int
    {
        $announcements = DB::table('announcements')->select('id');
        if ($this->option('dry-run')) {
            $this->info('Announcements to synchronize: '.$announcements->count());

            return self::SUCCESS;
        }
        if (! AnnouncementSearch::enabled()) {
            $this->error('Set SEARCH_DRIVER=elasticsearch before synchronizing announcements.');

            return self::FAILURE;
        }

        if (! $this->option('queue-only')) {
            $search->configure();
        }
        $announcements->chunkById(100, function ($rows) use ($search): void {
            foreach ($rows as $row) {
                $search->queue($row->id);
            }
        });

        return self::SUCCESS;
    }
}
