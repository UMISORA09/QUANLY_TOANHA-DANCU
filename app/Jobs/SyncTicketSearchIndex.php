<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\Search\TicketSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTicketSearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $ticketId) {}

    public function handle(TicketSearch $search): void
    {
        $ticket = Ticket::withTrashed()->find($this->ticketId);
        if ($ticket !== null) {
            $search->publish($ticket);
        }
    }
}
