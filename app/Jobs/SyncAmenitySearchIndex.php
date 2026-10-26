<?php

namespace App\Jobs;

use App\Services\Search\AlgoliaTaskPendingException;
use App\Services\Search\AmenitySearchSyncState;
use App\Services\Search\Drivers\AlgoliaDriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncAmenitySearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public string $amenityId, public ?int $taskId = null, public ?int $version = null, public int $pollAttempt = 0) {}

    public function handle(): void
    {
        $states = app(AmenitySearchSyncState::class);
        $driver = new AlgoliaDriver(config('search.drivers.algolia', []));
        $version = $this->version ?? null;
        try {
            if (($this->taskId ?? null) === null || $version === null) {
                $snapshot = $states->snapshot($this->amenityId);
                if ($snapshot === null) {
                    return;
                }
                $version = $snapshot['version'];
                $this->taskId = $snapshot['task_id'] ?? $driver->submitVersionedDocument($this->amenityId, $snapshot['document'], $version);
                $this->version = $version;
                DB::table('amenity_search_states')->where('amenity_id', $this->amenityId)->where('desired_version', $version)->update([
                    'task_id' => $this->taskId, 'task_version' => $version, 'status' => 'publishing', 'error_code' => null,
                ]);
            }
            $driver->waitForTask('amenities', $this->taskId, 3);
            $states->acknowledge($this->amenityId, $version);
        } catch (AlgoliaTaskPendingException $exception) {
            if (($this->pollAttempt ?? 0) >= 9) {
                $this->recordFailure($version, 'publication_timeout');
                throw new RuntimeException('Algolia publication exceeded the bounded retry window.');
            }
            self::dispatch($this->amenityId, $exception->taskId, $version, ($this->pollAttempt ?? 0) + 1)
                ->onConnection('database')->onQueue('amenity-search')->delay(30);
        } catch (Throwable $exception) {
            $this->recordFailure($version, 'sync_failed');
            throw $exception;
        }
    }

    private function recordFailure(?int $version, string $code): void
    {
        DB::table('amenity_search_states')->where('amenity_id', $this->amenityId)->where('desired_version', $version)
            ->whereColumn('published_version', '<', 'desired_version')
            ->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => now()]);
    }
}
