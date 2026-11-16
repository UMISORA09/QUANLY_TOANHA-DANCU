<?php

namespace App\Jobs;

use App\Services\Search\AnnouncementSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAnnouncementSearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $announcementId) {}

    public function handle(AnnouncementSearch $search): void
    {
        $search->publish($this->announcementId);
    }
}
