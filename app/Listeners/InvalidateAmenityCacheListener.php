<?php

namespace App\Listeners;

use App\Events\AmenityCreated;
use App\Events\AmenityDeleted;
use App\Events\AmenityUpdated;
use App\Services\ResidentAmenityBookingService;

class InvalidateAmenityCacheListener
{
    /**
     * Handle the event.
     */
    public function handle(AmenityCreated|AmenityUpdated|AmenityDeleted $event): void
    {
        app(ResidentAmenityBookingService::class)->invalidateAfterCommit();
    }
}
