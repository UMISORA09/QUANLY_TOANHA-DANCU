<?php

namespace App\Services\Search;

use App\Jobs\SyncAmenitySearchIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

class AmenitySearchSyncState
{
    public static function fingerprint(): string
    {
        return hash('sha256', config('search.drivers.algolia.app_id').':'.config('search.drivers.algolia.amenities_index'));
    }

    public function queue(string $amenityId): void
    {
        $this->queueMany([$amenityId]);
    }

    /** @param list<string> $amenityIds */
    public function queueMany(array $amenityIds): void
    {
        if ($amenityIds === []) {
            return;
        }
        $connection = config('queue.connections.database.connection') ?: config('database.default');
        if ($connection !== DB::getDefaultConnection() || config('queue.connections.database.after_commit')) {
            throw new LogicException('Amenity search state and queue must be written inside the business transaction.');
        }
        DB::transaction(function () use ($amenityIds): void {
            DB::table('amenities')->whereIn('id', $amenityIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $now = now();
            $fingerprint = self::fingerprint();
            DB::table('amenity_search_states')->insertOrIgnore(array_map(fn (string $id): array => [
                'amenity_id' => $id, 'desired_version' => 0, 'published_version' => 0,
                'index_fingerprint' => $fingerprint, 'status' => 'pending',
                'created_at' => $now, 'updated_at' => $now,
            ], $amenityIds));
            DB::table('amenity_search_states')->whereIn('amenity_id', $amenityIds)->update([
                'desired_version' => DB::raw('desired_version + 1'), 'status' => 'pending',
                'index_fingerprint' => $fingerprint,
                'error_code' => null, 'task_id' => null, 'task_version' => null, 'updated_at' => $now,
            ]);
            Queue::connection('database')->bulk(array_map(fn (string $id): SyncAmenitySearchIndex => new SyncAmenitySearchIndex($id), $amenityIds), '', 'amenity-search');
        }, 3);
    }

    /** @return array{version: int, document: array<string, mixed>, task_id: ?int}|null */
    public function snapshot(string $amenityId): ?array
    {
        return DB::transaction(function () use ($amenityId): ?array {
            $row = DB::table('amenities')->where('id', $amenityId)->lockForUpdate()->first();
            $this->initialize($amenityId);
            $state = DB::table('amenity_search_states')->where('amenity_id', $amenityId)->lockForUpdate()->first();
            $version = (int) $state->desired_version;
            if ($version === 0 || $state->index_fingerprint !== self::fingerprint()) {
                $version++;
                DB::table('amenity_search_states')->where('amenity_id', $amenityId)->update([
                    'desired_version' => $version, 'index_fingerprint' => self::fingerprint(),
                    'status' => 'pending', 'task_id' => null, 'task_version' => null, 'error_code' => null,
                ]);
                $state->task_id = null;
            } elseif ($state->published_version >= $version) {
                return null;
            }

            return [
                'version' => $version,
                'document' => $row && $row->deleted_at === null ? (array) $row : ['is_deleted' => true],
                'task_id' => (int) $state->task_version === $version && $state->task_id !== null ? (int) $state->task_id : null,
            ];
        }, 3);
    }

    public function acknowledge(string $amenityId, int $version): void
    {
        DB::table('amenity_search_states')->where('amenity_id', $amenityId)
            ->where('published_version', '<', $version)->update([
                'published_version' => $version, 'synced_at' => now(), 'updated_at' => now(),
            ]);
        DB::table('amenity_search_states')->where('amenity_id', $amenityId)
            ->whereColumn('published_version', '>=', 'desired_version')->update([
                'status' => 'synced', 'error_code' => null, 'task_id' => null, 'task_version' => null,
            ]);
    }

    private function initialize(string $amenityId): void
    {
        DB::table('amenity_search_states')->insertOrIgnore([
            'amenity_id' => $amenityId, 'desired_version' => 0, 'published_version' => 0,
            'index_fingerprint' => self::fingerprint(),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
