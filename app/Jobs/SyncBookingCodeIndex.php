<?php

namespace App\Jobs;

use App\Services\Search\BookingCodeSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncBookingCodeIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $bookingId) {}

    public function handle(BookingCodeSearch $search): void
    {
        $search->publish($this->bookingId);
    }
}
