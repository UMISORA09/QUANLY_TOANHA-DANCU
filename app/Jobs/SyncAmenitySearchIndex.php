<?php

namespace App\Jobs;

use App\Services\Search\AlgoliaTaskPendingException;
use App\Services\Search\Drivers\AlgoliaDriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SyncAmenitySearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $amenityId, public ?int $taskId = null) {}

    public function handle(): void
    {
        try {
            DB::transaction(function (): void {
                $driver = new AlgoliaDriver(config('search.drivers.algolia', []));
                if (($this->taskId ?? null) !== null) {
                    $driver->waitForTask('amenities', $this->taskId, 5);

                    return;
                }
                $row = DB::table('amenities')->where('id', $this->amenityId)->lockForUpdate()->first();
                if (! $row || $row->deleted_at !== null) {
                    $driver->delete('amenities', $this->amenityId);
                } else {
                    $driver->index('amenities', $this->amenityId, (array) $row);
                }
            });
        } catch (AlgoliaTaskPendingException $exception) {
            self::dispatch($this->amenityId, $exception->taskId)->onConnection('database')->onQueue('amenity-search')->delay(30);
        }
    }
}
