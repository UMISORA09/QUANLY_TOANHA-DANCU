<?php

use App\Services\Freshness\FreshnessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (FreshnessService $freshness) {
    $freshness->recordCollectorHeartbeat();
})->everyMinute()->name('freshness-collector-heartbeat');
