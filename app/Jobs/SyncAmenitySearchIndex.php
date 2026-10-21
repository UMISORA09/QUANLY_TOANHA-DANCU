<?php

namespace App\Jobs;

use App\Services\Search\Drivers\AlgoliaDriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SyncAmenitySearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $amenityId) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('amenities')->where('id', $this->amenityId)->lockForUpdate()->first();
            $driver = new AlgoliaDriver(config('search.drivers.algolia', []));
            if (! $row || $row->deleted_at !== null) {
                $driver->delete('amenities', $this->amenityId);
            } else {
                $driver->index('amenities', $this->amenityId, (array) $row);
            }
        });
    }
}
