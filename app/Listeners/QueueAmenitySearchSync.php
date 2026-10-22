<?php

namespace App\Listeners;

use App\Events\AmenityCreated;
use App\Events\AmenityDeleted;
use App\Events\AmenityUpdated;
use App\Jobs\SyncAmenitySearchIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

class QueueAmenitySearchSync
{
    public function handle(AmenityCreated|AmenityUpdated|AmenityDeleted $event): void
    {
        if (! config('search.drivers.algolia.sync_enabled', false)) {
            return;
        }
        $connection = config('queue.connections.database.connection') ?: config('database.default');
        if ($connection !== DB::getDefaultConnection()) {
            throw new LogicException('Amenity search queue must use the business database connection for atomic writes.');
        }
        $id = $event instanceof AmenityCreated ? $event->amenityData['id'] : $event->amenityId;
        Queue::connection('database')->push(new SyncAmenitySearchIndex($id), '', 'amenity-search');
    }
}
