<?php

namespace App\Console\Commands;

use App\Services\Search\AmenitySearchSyncState;
use App\Services\Search\Drivers\AlgoliaDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;

class SyncAmenitySearch extends Command
{
    protected $signature = 'search:sync-amenities {--configure : Configure the dedicated Algolia index} {--dry-run : Count rows without writing or sending data} {--retry-failed : Queue only documents whose last synchronization failed}';

    protected $description = 'Queue idempotent synchronization of all amenities, including soft deletions';

    public function handle(): int
    {
        $rows = DB::table('amenities')->select('id');
        if ($this->option('retry-failed')) {
            $rows->whereIn('id', DB::table('amenity_search_states')->where('status', 'failed')->select('amenity_id'));
        }
        if ($this->option('dry-run')) {
            $this->info('Amenities to synchronize: '.$rows->count());

            return self::SUCCESS;
        }
        if (! config('search.drivers.algolia.sync_enabled')) {
            $this->error('Enable ALGOLIA_SYNC_ENABLED after configuring a dedicated index.');

            return self::FAILURE;
        }
        $connection = config('queue.connections.database.connection') ?: config('database.default');
        if ($connection !== DB::getDefaultConnection()) {
            throw new LogicException('Amenity search queue must use the business database connection for atomic writes.');
        }
        if ($this->option('configure')) {
            (new AlgoliaDriver(config('search.drivers.algolia')))->configure();
        }
        $rows->chunkById(100, function ($rows): void {
            app(AmenitySearchSyncState::class)->queueMany($rows->pluck('id')->all());
        });
        $this->info('Queued on amenity-search. Run queue:work database --queue=amenity-search --stop-when-empty --max-jobs=100 --max-time=45.');

        return self::SUCCESS;
    }
}
