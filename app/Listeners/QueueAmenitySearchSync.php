<?php

namespace App\Listeners;

use App\Events\AmenityCreated;
use App\Events\AmenityDeleted;
use App\Events\AmenityUpdated;
use App\Services\Search\AmenitySearchSyncState;

class QueueAmenitySearchSync
{
    public function handle(AmenityCreated|AmenityUpdated|AmenityDeleted $event): void
    {
        if (! config('search.drivers.algolia.sync_enabled', false)) {
            return;
        }
        $id = $event instanceof AmenityCreated ? $event->amenityData['id'] : $event->amenityId;
        app(AmenitySearchSyncState::class)->queue($id);
    }
}
