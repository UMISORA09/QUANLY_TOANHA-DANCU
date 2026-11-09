<?php

namespace App\Jobs;

use App\Services\Search\AmenitySearchSyncState;
use App\Services\Search\Drivers\ElasticsearchDriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncAmenitySearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $amenityId) {}

    public function handle(): void
    {
        $states = app(AmenitySearchSyncState::class);
        $snapshot = $states->snapshot($this->amenityId);
        if ($snapshot === null) {
            return;
        }

        $version = $snapshot['version'];
        try {
            (new ElasticsearchDriver(config('search.drivers.elasticsearch')))
                ->publish($this->amenityId, $snapshot['document'], $version);
            $states->acknowledge($this->amenityId, $version);
        } catch (Throwable $exception) {
            DB::table('amenity_search_states')->where('amenity_id', $this->amenityId)->where('desired_version', $version)
                ->whereColumn('published_version', '<', 'desired_version')
                ->update(['status' => 'failed', 'error_code' => 'sync_failed', 'updated_at' => now()]);

            throw $exception;
        }
    }
}
