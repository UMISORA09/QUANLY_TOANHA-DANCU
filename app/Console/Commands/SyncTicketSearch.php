<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\Search\TicketSearch;
use Illuminate\Console\Command;

class SyncTicketSearch extends Command
{
    protected $signature = 'search:sync-tickets {--dry-run} {--queue-only : Skip creating the remote index in this process}';

    protected $description = 'Queue Elasticsearch synchronization for current and archived tickets';

    public function handle(TicketSearch $search): int
    {
        $tickets = Ticket::withTrashed()->select('id');
        if ($this->option('dry-run')) {
            $this->info('Tickets to synchronize: '.$tickets->count());

            return self::SUCCESS;
        }
        if (! TicketSearch::enabled()) {
            $this->error('Set SEARCH_DRIVER=elasticsearch before synchronizing tickets.');

            return self::FAILURE;
        }

        if (! $this->option('queue-only')) {
            $search->configure();
        }
        $tickets->chunkById(100, function ($rows) use ($search): void {
            foreach ($rows as $ticket) {
                $search->queue($ticket->id);
            }
        });

        return self::SUCCESS;
    }
}
