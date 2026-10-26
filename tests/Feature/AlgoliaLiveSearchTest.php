<?php

namespace Tests\Feature;

use App\Jobs\SyncAmenitySearchIndex;
use App\Services\Search\AmenitySearchSyncState;
use App\Services\Search\Drivers\AlgoliaDriver;
use App\Services\Search\Drivers\SmartSearchDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AlgoliaLiveSearchTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    public function test_live_qa_index_search_update_and_delete(): void
    {
        if (! filter_var(env('ALGOLIA_LIVE_TEST', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Explicit opt-in required for the external QA index.');
        }
        $this->assertSame('amenities_qa', config('search.drivers.algolia.amenities_index'));
        $this->createBookingFixture();
        config(['search.drivers.algolia.sync_enabled' => true, 'search.default' => 'algolia']);
        $driver = new AlgoliaDriver(config('search.drivers.algolia'));
        $this->observeRequestBudget();
        $driver->configure();
        $removed = false;
        try {
            $name = 'QA Pool '.str_replace('-', '', $this->amenityId);
            DB::table('amenities')->where('id', $this->amenityId)->update(['amenity_name' => $name]);
            $this->synchronizeAmenity();
            $result = $driver->search('amenities', $name, ['block_id' => $this->blockId]);
            $this->assertSame('algolia', $result->engine);
            $this->assertSame($this->amenityId, $result->items[0]->id);
            $this->assertSame($name, $result->items[0]->amenity_name);
            $this->withToken($this->adminToken)->getJson('/api/v1/amenities/search?q='.urlencode($name))->assertOk()->assertJsonPath('engine', 'algolia')->assertJsonPath('data.0.id', $this->amenityId);
            $this->assertSame($this->amenityId, $driver->suggest('amenities', $name)[0]['id']);
            DB::table('amenities')->where('id', $this->amenityId)->update(['amenity_name' => $name.' Updated']);
            $this->synchronizeAmenity();
            $this->assertSame($name.' Updated', $driver->search('amenities', $name)->items[0]->amenity_name);
            DB::table('amenities')->where('id', $this->amenityId)->update(['deleted_at' => now()]);
            $this->synchronizeAmenity();
            $removed = true;
            $staleTask = $driver->submitVersionedDocument($this->amenityId, ['amenity_name' => $name, 'amenity_code' => 'OLD', 'is_active' => 1], 1);
            $driver->waitForTask('amenities', $staleTask, 45);
            $result = $driver->search('amenities', $name);
            $this->assertSame(0, $result->total);
        } finally {
            if (! $removed) {
                DB::table('amenities')->where('id', $this->amenityId)->update(['deleted_at' => now()]);
                $this->synchronizeAmenity();
            }
            $driver->delete('amenities', $this->amenityId);
        }
    }

    public function test_live_qa_top_five_and_comparative_latency(): void
    {
        if (! filter_var(env('ALGOLIA_LIVE_TEST', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Explicit opt-in required for the external QA index.');
        }
        $this->assertSame('amenities_qa', config('search.drivers.algolia.amenities_index'));
        $this->createBookingFixture();
        $this->assertSame(1, DB::table('amenities')->count(), 'Use an empty dedicated QA database for the live benchmark.');
        config(['search.drivers.algolia.sync_enabled' => true]);
        $this->observeRequestBudget();
        $driver = new AlgoliaDriver(config('search.drivers.algolia'));
        // The CRUD test configures the QA index; benchmarking must not rewrite those settings again.
        $base = (array) DB::table('amenities')->where('id', $this->amenityId)->first();
        $ids = [$this->amenityId];
        $cases = [];
        try {
            foreach (['ku nghi duon' => 'Khu Nghỉ Dưỡng', 'phong gim' => 'Phòng Gym', 'san tenit' => 'Sân Tennis', 'ho boi' => 'Hồ Bơi'] as $query => $name) {
                $id = (string) Str::uuid();
                DB::table('amenities')->insert([...$base, 'id' => $id, 'amenity_name' => $name, 'amenity_code' => 'QA-'.Str::upper(Str::random(12))]);
                $ids[] = $id;
                $cases[$query] = $id;
            }
            foreach ($ids as $id) {
                $this->synchronizeAmenity($id);
            }
            $latencies = [];
            $cacheHits = [];
            foreach (['first', 'repeat'] as $round) {
                foreach (['smart' => new SmartSearchDriver, 'algolia' => $driver] as $engine => $search) {
                    foreach ($cases as $query => $expectedId) {
                        $result = $search->search('amenities', $query, ['block_id' => $this->blockId], ['per_page' => 5]);
                        $this->assertSame($engine, $result->engine, json_encode($result->extra));
                        $this->assertContains($expectedId, array_map(fn ($row) => ((object) $row)->id, $result->items), 'Expected amenity must be in the top five.');
                        $latencies[$engine][$round][] = round($result->searchTimeMs, 2);
                        $cacheHits[$engine][$round] = ($cacheHits[$engine][$round] ?? 0) + (int) ($result->extra['cache_hit'] ?? false);
                    }
                }
            }
            $code = DB::table('amenities')->where('id', $ids[1])->value('amenity_code');
            foreach (['smart' => new SmartSearchDriver, 'algolia' => $driver] as $engine => $search) {
                $result = $search->search('amenities', $code, ['block_id' => $this->blockId]);
                $this->assertSame($engine, $result->engine);
                $this->assertSame($ids[1], ((object) $result->items[0])->id);
            }
            foreach ($latencies as $engine => &$rounds) {
                foreach ($rounds as $round => &$values) {
                    sort($values);
                    $values = ['samples' => count($values), 'cache_hits' => $cacheHits[$engine][$round], 'p50_ms' => $values[(int) ceil(count($values) * .50) - 1], 'p95_ms' => $values[(int) ceil(count($values) * .95) - 1]];
                }
                unset($values);
            }
            unset($rounds);
            fwrite(STDOUT, '\nQA_SEARCH_BENCHMARK '.json_encode($latencies).PHP_EOL);
        } finally {
            foreach ($ids as $id) {
                DB::table('amenities')->where('id', $id)->update(['deleted_at' => now()]);
                $this->synchronizeAmenity($id);
                $driver->delete('amenities', $id);
            }
        }
    }

    private function observeRequestBudget(): void
    {
        $calls = 0;
        Event::listen(RequestSending::class, function (RequestSending $event) use (&$calls): void {
            if (str_ends_with($event->request->url(), '/query')) {
                $this->assertLessThanOrEqual(100, ++$calls, 'Each of the two QA tests is limited to 100 search requests.');
            }
        });
    }

    private function synchronizeAmenity(?string $id = null): void
    {
        $id ??= $this->amenityId;
        app(AmenitySearchSyncState::class)->queue($id);
        (new SyncAmenitySearchIndex($id))->handle();
        $deadline = microtime(true) + 180;
        while (DB::table('jobs')->where('queue', 'amenity-search')->exists()) {
            $this->assertLessThan($deadline, microtime(true), 'Algolia QA publication did not finish within the bounded test window.');
            // QA exercises publication checks without waiting through the production backoff between them.
            DB::table('jobs')->where('queue', 'amenity-search')->where('payload', 'like', '%'.$id.'%')->update(['available_at' => now()->timestamp]);
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'amenity-search', '--once' => true, '--tries' => 3]);
        }
        $this->assertSame(0, DB::table('failed_jobs')->where('queue', 'amenity-search')->count());
    }
}
