<?php

namespace App\Listeners;

use App\Events\AmenityCreated;
use App\Events\AmenityDeleted;
use App\Events\AmenityUpdated;
use App\Services\Search\SearchCacheService;
use Illuminate\Support\Facades\Cache;

class InvalidateAmenityCacheListener
{
    /**
     * Handle the event.
     */
    public function handle(AmenityCreated|AmenityUpdated|AmenityDeleted $event): void
    {
        if (! Cache::has('amenities_data_version')) {
            Cache::forever('amenities_data_version', 1);
        }
        Cache::increment('amenities_data_version');

        SearchCacheService::invalidate();
    }
}
