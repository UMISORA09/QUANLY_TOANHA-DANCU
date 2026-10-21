<?php

namespace Tests\Feature;

use App\Jobs\SyncAmenitySearchIndex;
use App\Services\Search\Drivers\AlgoliaDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
        $removed = false;
        try {
            $name = 'QA Pool '.str_replace('-', '', $this->amenityId);
            DB::table('amenities')->where('id', $this->amenityId)->update(['amenity_name' => $name]);
            retry(3, fn () => (new SyncAmenitySearchIndex($this->amenityId))->handle(), 1000);
            $result = $driver->search('amenities', $name, ['block_id' => $this->blockId]);
            $this->assertSame('algolia', $result->engine);
            $this->assertSame($this->amenityId, $result->items[0]->id);
            $this->assertSame($name, $result->items[0]->amenity_name);
            $this->withToken($this->adminToken)->getJson('/api/v1/amenities/search?q='.urlencode($name))->assertOk()->assertJsonPath('engine', 'algolia')->assertJsonPath('data.0.id', $this->amenityId);
            $this->assertSame($this->amenityId, $driver->suggest('amenities', $name)[0]['id']);
            DB::table('amenities')->where('id', $this->amenityId)->update(['amenity_name' => $name.' Updated']);
            retry(3, fn () => (new SyncAmenitySearchIndex($this->amenityId))->handle(), 1000);
            $this->assertSame($name.' Updated', $driver->search('amenities', $name)->items[0]->amenity_name);
            DB::table('amenities')->where('id', $this->amenityId)->update(['deleted_at' => now()]);
            retry(3, fn () => (new SyncAmenitySearchIndex($this->amenityId))->handle(), 1000);
            $removed = true;
            $result = $driver->search('amenities', $name);
            $this->assertSame(0, $result->total);
        } finally {
            if (! $removed) {
                retry(3, fn () => $driver->delete('amenities', $this->amenityId), 1000);
            }
        }
    }
}
