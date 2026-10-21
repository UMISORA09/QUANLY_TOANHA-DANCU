<?php

namespace Tests\Feature;

use App\Events\AmenityUpdated;
use App\Jobs\SyncAmenitySearchIndex;
use App\Services\Search\Drivers\AlgoliaDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AlgoliaAmenitySearchTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        config(['search.drivers.algolia' => ['app_id' => 'TESTAPP', 'key' => 'test-private-key', 'amenities_index' => 'amenities_test', 'sync_enabled' => true]]);
        Http::preventStrayRequests();
    }

    private function driver(): AlgoliaDriver
    {
        return new AlgoliaDriver(config('search.drivers.algolia'));
    }

    public function test_search_hydrates_mysql_instead_of_returning_remote_fields(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [['objectID' => $this->amenityId, 'updated_at' => DB::table('amenities')->where('id', $this->amenityId)->value('updated_at'), 'amenity_name' => 'Untrusted remote name']], 'nbHits' => 1])]);
        $result = $this->driver()->search('amenities', 'pool', ['block_id' => $this->blockId], ['page' => 2, 'per_page' => 15]);
        $this->assertSame('algolia', $result->engine);
        $this->assertSame($this->amenityId, $result->items[0]->id);
        $this->assertNotSame('Untrusted remote name', $result->items[0]->amenity_name);
        Http::assertSent(fn ($request) => $request['page'] === 1 && $request['hitsPerPage'] === 15 && str_contains(json_encode($request['facetFilters']), $this->blockId));
    }

    public function test_missing_or_out_of_scope_hits_fall_back_without_returning_them(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [['objectID' => 'deleted-or-private']], 'nbHits' => 1])]);
        $result = $this->driver()->search('amenities', 'pool', ['block_id' => $this->blockId]);
        $this->assertSame('smart', $result->engine);
        $this->assertNotContains('deleted-or-private', array_map(fn ($item) => ((object) $item)->id, $result->items));
    }

    public function test_remote_failure_and_unsupported_sort_use_database_fallback(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertSame('smart', $this->driver()->search('amenities', 'pool')->engine);
        Http::fake();
        $this->assertSame('smart', $this->driver()->search('amenities', 'pool', [], ['sort' => 'price_asc'])->engine);
        Http::assertNothingSent();
    }

    public function test_index_writes_only_public_search_fields_and_waits_for_publication(): void
    {
        Http::fake(['*/task/*' => Http::response(['status' => 'published']), '*' => Http::response(['taskID' => 42])]);
        $this->driver()->index('amenities', $this->amenityId, ['amenity_name' => 'Hồ bơi', 'amenity_code' => 'POOL', 'resident_email' => 'private@example.test', 'objectID' => 'forged']);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['objectID'] === $this->amenityId && ! isset($request['resident_email']) && $request['amenity_name_ascii'] === 'ho boi');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/task/42'));
    }

    public function test_failed_write_is_not_acknowledged_as_success(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->expectException(\RuntimeException::class);
        $this->driver()->delete('amenities', $this->amenityId);
    }

    public function test_transaction_rollback_also_rolls_back_sync_job(): void
    {
        $before = DB::table('jobs')->where('queue', 'amenity-search')->count();
        DB::beginTransaction();
        try {
            AmenityUpdated::dispatch($this->amenityId, []);
            $this->assertSame($before + 1, DB::table('jobs')->where('queue', 'amenity-search')->count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, DB::table('jobs')->where('queue', 'amenity-search')->count());
    }

    public function test_job_uses_latest_row_and_soft_delete_removes_document(): void
    {
        Http::fake(['*/task/*' => Http::response(['status' => 'published']), '*' => Http::response(['taskID' => 42])]);
        DB::table('amenities')->where('id', $this->amenityId)->update(['amenity_name' => 'Latest QA name']);
        (new SyncAmenitySearchIndex($this->amenityId))->handle();
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['amenity_name'] === 'Latest QA name');
        DB::table('amenities')->where('id', $this->amenityId)->update(['deleted_at' => now()]);
        (new SyncAmenitySearchIndex($this->amenityId))->handle();
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_public_search_and_suggestions_require_a_real_session(): void
    {
        $this->withHeader('Authorization', '');
        $this->getJson('/api/v1/amenities/search?q=pool')->assertUnauthorized();
        $this->getJson('/api/v1/search/suggestions?q=po')->assertUnauthorized();
        $this->withToken('smart_token_admin_demo')->getJson('/api/amenities/search?q=pool')->assertUnauthorized();
    }

    public function test_api_uses_configured_driver_and_preserves_rbac(): void
    {
        config(['search.default' => 'algolia']);
        Http::fake(['*/query' => Http::response(['hits' => [['objectID' => $this->amenityId, 'updated_at' => DB::table('amenities')->where('id', $this->amenityId)->value('updated_at')]], 'nbHits' => 1])]);
        $this->withToken($this->residentToken)->getJson('/api/v1/amenities/search?q=pool')->assertForbidden();
        $this->withToken($this->adminToken)->getJson('/api/v1/amenities/search?q=pool')->assertOk()->assertJsonPath('engine', 'algolia')->assertJsonPath('data.0.id', $this->amenityId);
    }

    public function test_flush_is_disabled_without_explicit_operator_configuration(): void
    {
        $this->expectException(\LogicException::class);
        $this->driver()->flush('amenities');
    }

    public function test_pending_sync_uses_database_search_and_does_not_call_algolia(): void
    {
        AmenityUpdated::dispatch($this->amenityId, []);
        Http::fake();
        $this->assertSame('smart', $this->driver()->search('amenities', 'pool')->engine);
        Http::assertNothingSent();
    }

    public function test_worker_endpoint_rejects_missing_or_invalid_machine_secret(): void
    {
        config(['search.sync_secret' => str_repeat('a', 40)]);
        $this->withHeader('Authorization', '')->getJson('/api/v1/internal/amenity-search/process')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/internal/amenity-search/process')->assertUnauthorized();
        $this->withToken(str_repeat('a', 40))->getJson('/api/v1/internal/amenity-search/process')->assertOk();
    }

    public function test_stale_document_version_uses_mysql_fallback(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [['objectID' => $this->amenityId, 'updated_at' => 'stale']], 'nbHits' => 1])]);
        $this->assertSame('smart', $this->driver()->search('amenities', 'pool')->engine);
    }

    public function test_unpublished_task_is_not_acknowledged_as_synchronized(): void
    {
        Http::fake(['*/task/*' => Http::response(['status' => 'notPublished']), '*' => Http::response(['taskID' => 42])]);
        $this->expectException(\RuntimeException::class);
        $this->driver()->delete('amenities', $this->amenityId);
    }

    public function test_protected_reindex_queues_existing_rows_once(): void
    {
        config(['search.sync_secret' => str_repeat('b', 40)]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->withHeader('Authorization', '')->postJson('/api/v1/internal/amenity-search/reindex')->assertUnauthorized();
        $expected = DB::table('amenities')->count();
        $this->withToken(str_repeat('b', 40))->postJson('/api/v1/internal/amenity-search/reindex')->assertStatus(202)->assertJsonPath('pending', $expected);
        $this->postJson('/api/v1/internal/amenity-search/reindex')->assertStatus(409);
        $this->assertSame($expected, DB::table('jobs')->where('queue', 'amenity-search')->count());
        Http::assertNothingSent();
    }
}
