<?php

namespace App\Modules\ResidentService\Events;

use App\Modules\ResidentService\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Ticket mới được tạo.
 */
class TicketCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket
    ) {}
}
