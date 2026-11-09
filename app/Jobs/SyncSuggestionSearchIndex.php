<?php

namespace App\Jobs;

use App\Services\Search\SuggestionSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSuggestionSearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $suggestionId) {}

    public function handle(SuggestionSearch $search): void
    {
        $search->publish($this->suggestionId);
    }
}
