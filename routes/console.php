<?php

use App\Services\AmenityBookingPaymentService;
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

Schedule::call(fn () => app(AmenityBookingPaymentService::class)->expire())
    ->everyMinute()->name('amenity-payment-expiry')->withoutOverlapping();

Schedule::command('queue:work database --queue=amenity-search --stop-when-empty --max-jobs=10 --max-time=45 --tries=5 --timeout=25')
    ->everyMinute()->name('amenity-search-sync')->withoutOverlapping()
    ->when(fn (): bool => (bool) config('search.drivers.algolia.sync_enabled'));
