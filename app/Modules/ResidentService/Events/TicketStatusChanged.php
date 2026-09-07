<?php

namespace App\Modules\ResidentService\Events;

use App\Modules\ResidentService\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event: Ticket đổi trạng thái (State Machine transition).
 */
class TicketStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {}
}
