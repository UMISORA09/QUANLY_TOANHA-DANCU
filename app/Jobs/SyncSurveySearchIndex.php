<?php

namespace App\Jobs;

use App\Services\Search\SurveySearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncSurveySearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $surveyId) {}

    public function handle(SurveySearch $search): void
    {
        $search->publish($this->surveyId);
    }
}
