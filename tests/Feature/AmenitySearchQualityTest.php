<?php

namespace Tests\Feature;

use App\Jobs\SyncAmenitySearchIndex;
use App\Services\Search\AmenitySearchMetrics;
use App\Services\Search\AmenitySearchSyncState;
use App\Services\Search\Drivers\AlgoliaDriver;
use App\Services\Search\Drivers\SmartSearchDriver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenitySearchQualityTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        config(['search.drivers.algolia' => ['app_id' => 'TESTAPP', 'key' => 'qa-key', 'amenities_index' => 'amenities_test', 'sync_enabled' => true]]);
        foreach (DB::table('amenities')->pluck('id') as $id) {
            DB::table('amenity_search_states')->updateOrInsert(['amenity_id' => $id], ['desired_version' => 1, 'published_version' => 1, 'status' => 'synced', 'created_at' => now(), 'updated_at' => now(), 'index_fingerprint' => AmenitySearchSyncState::fingerprint()]);
        }
        Http::preventStrayRequests();
    }

    private function driver(): AlgoliaDriver
    {
        return new AlgoliaDriver(config('search.drivers.algolia'));
    }

    /** @return array<string, mixed> */
    private function hit(int $version = 1): array
    {
        return ['objectID' => $this->amenityId, 'search_version' => $version, 'updated_at' => DB::table('amenities')->where('id', $this->amenityId)->value('updated_at')];
    }

    public function test_reindex_queues_129_amenities_with_a_bounded_number_of_queries(): void
    {
        $template = (array) DB::table('amenities')->where('id', $this->amenityId)->first();
        $ids = [$this->amenityId];
        $rows = [];
        for ($index = 0; $index < 128; $index++) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $rows[] = [...$template, 'id' => $id, 'amenity_code' => 'QA-BULK-'.Str::random(14)];
        }
        DB::table('amenities')->insert($rows);
        DB::table('amenity_search_states')->insertOrIgnore(array_map(fn (string $id): array => [
            'amenity_id' => $id, 'desired_version' => 1, 'published_version' => 1,
            'status' => 'failed', 'index_fingerprint' => AmenitySearchSyncState::fingerprint(),
            'created_at' => now(), 'updated_at' => now(),
        ], $ids));
        DB::table('amenity_search_states')->whereIn('amenity_id', $ids)->update(['status' => 'failed']);
        $beforeJobs = DB::table('jobs')->where('queue', 'amenity-search')->count();
        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            $queryCount++;
        });

        $this->artisan('search:sync-amenities', ['--retry-failed' => true])->assertSuccessful();

        $this->assertLessThanOrEqual(16, $queryCount);
        $this->assertSame($beforeJobs + 129, DB::table('jobs')->where('queue', 'amenity-search')->count());
        $this->assertSame(129, DB::table('amenity_search_states')->whereIn('amenity_id', $ids)->where('desired_version', 2)->where('published_version', 1)->count());
        Http::assertNothingSent();
    }

    public function test_correction_is_applied_only_after_zero_results_and_uses_at_most_two_searches(): void
    {
        Http::fake(['*/query' => Http::sequence()->push(['hits' => [], 'nbHits' => 0])->push(['hits' => [$this->hit()], 'nbHits' => 1])]);
        $result = $this->driver()->search('amenities', 'phong gim');
        $this->assertSame('algolia', $result->engine);
        $this->assertSame('phong gym', $result->extra['applied_query']);
        $this->assertTrue($result->extra['is_corrected']);
        $this->assertSame(2, $result->extra['algolia_calls']);
        Http::assertSentCount(2);
    }

    public function test_original_hits_do_not_claim_an_unapplied_correction(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [$this->hit()], 'nbHits' => 1])]);
        $result = $this->driver()->search('amenities', 'phong gim');
        $this->assertFalse($result->extra['is_corrected']);
        $this->assertSame('phong gim', $result->extra['applied_query']);
        Http::assertSentCount(1);
    }

    public function test_changing_the_index_requires_synchronization_before_using_remote_results(): void
    {
        config(['search.drivers.algolia.amenities_index' => 'another_qa_index']);
        Http::fake();
        $this->assertSame('index_not_synchronized', $this->driver()->search('amenities', 'BBQ')->extra['fallback_reason']);
        Http::assertNothingSent();
    }

    public function test_repeated_queries_use_cached_ids_but_rehydrate_mysql_and_separate_accounts(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [$this->hit()], 'nbHits' => 1])]);
        $first = $this->driver()->search('amenities', 'BBQ', [], ['cache_context' => 'first-account']);
        $second = $this->driver()->search('amenities', 'BBQ', [], ['cache_context' => 'first-account']);
        $this->assertFalse($first->extra['cache_hit']);
        $this->assertTrue($second->extra['cache_hit']);
        $this->assertSame(0, $second->extra['algolia_calls']);
        Http::assertSentCount(1);
        $this->driver()->search('amenities', 'BBQ', [], ['cache_context' => 'second-account']);
        Http::assertSentCount(2);
        DB::table('amenities')->where('id', $this->amenityId)->update(['deleted_at' => now()]);
        $this->assertSame('smart', $this->driver()->search('amenities', 'BBQ', [], ['cache_context' => 'first-account'])->engine);
    }

    public function test_successful_sync_invalidates_cached_empty_results_even_after_the_queue_is_empty(): void
    {
        Http::fake(['*/query' => Http::sequence()->push(['hits' => [], 'nbHits' => 0])->push(['hits' => [$this->hit(2)], 'nbHits' => 1])]);
        $this->assertSame(0, $this->driver()->search('amenities', 'BBQ')->total);
        DB::table('amenity_search_states')->where('amenity_id', $this->amenityId)->update(['desired_version' => 2, 'published_version' => 2]);
        $this->assertSame(1, $this->driver()->search('amenities', 'BBQ')->total);
        Http::assertSentCount(2);
    }

    public function test_a_sync_finishing_during_search_cannot_cache_a_stale_empty_page(): void
    {
        $calls = 0;
        Http::fake(['*/query' => function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                DB::table('amenity_search_states')->where('amenity_id', $this->amenityId)->update(['desired_version' => 2, 'published_version' => 2]);

                return Http::response(['hits' => [], 'nbHits' => 0]);
            }

            return Http::response(['hits' => [$this->hit(2)], 'nbHits' => 1]);
        }]);
        $this->assertSame('smart', $this->driver()->search('amenities', 'BBQ')->engine);
        $result = $this->driver()->search('amenities', 'BBQ');
        $this->assertSame('algolia', $result->engine);
        $this->assertSame(1, $result->total);
        $this->assertFalse($result->extra['cache_hit']);
        Http::assertSentCount(2);
    }

    public function test_codes_are_not_spell_corrected_and_quota_failure_has_a_safe_reason(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [], 'nbHits' => 0])]);
        $result = $this->driver()->search('amenities', 'GYM-123');
        $this->assertSame('GYM-123', $result->extra['applied_query']);
        Http::assertSentCount(1);
    }

    public function test_unchanged_vocabulary_does_not_spend_a_second_algolia_search(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [], 'nbHits' => 0])]);
        $result = $this->driver()->search('amenities', 'BBQ');
        $this->assertFalse($result->extra['is_corrected']);
        $this->assertSame(1, $result->extra['algolia_calls']);
        Http::assertSentCount(1);
    }

    public function test_old_queued_publication_check_does_not_disable_a_current_index(): void
    {
        app('queue')->connection('database')->push(new SyncAmenitySearchIndex($this->amenityId, 42, 1), '', 'amenity-search');
        Http::fake(['*/query' => Http::response(['hits' => [$this->hit()], 'nbHits' => 1])]);
        $this->assertSame('algolia', $this->driver()->search('amenities', 'BBQ')->engine);
    }

    public function test_missing_coverage_or_an_old_version_falls_back_even_if_timestamps_match(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [$this->hit(0)], 'nbHits' => 1])]);
        $this->assertSame('stale_results', $this->driver()->search('amenities', 'BBQ')->extra['fallback_reason']);
        DB::table('amenity_search_states')->where('amenity_id', $this->amenityId)->delete();
        $this->assertSame('index_not_synchronized', $this->driver()->search('amenities', 'BBQ')->extra['fallback_reason']);
        Http::assertSentCount(1);
    }

    public function test_shared_and_block_filters_are_applied_before_remote_pagination(): void
    {
        Http::fake(['*/query' => Http::response(['hits' => [$this->hit()], 'nbHits' => 1])]);
        $result = $this->driver()->search('amenities', 'BBQ', ['allowed_block_ids' => [$this->blockId], 'is_active' => true]);
        $this->assertSame('algolia', $result->engine);
        Http::assertSent(fn ($request) => in_array(['block_id:'.$this->blockId, 'block_id:__shared__'], $request['facetFilters'], true));
        $local = (new SmartSearchDriver)->search('amenities', '', ['allowed_block_ids' => [], 'is_active' => true]);
        $this->assertNotContains($this->amenityId, array_map(fn ($row) => ((object) $row)->id, $local->items));
    }

    public function test_configuration_uploads_the_existing_synonym_dictionary_not_per_search(): void
    {
        Http::fake(['*/task/*' => Http::response(['status' => 'published']), '*' => Http::response(['taskID' => 42])]);
        $this->driver()->configure();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/synonyms/batch') && $request->data() === SmartSearchDriver::indexSynonyms());
    }

    public function test_metrics_are_anonymous_and_only_system_admin_can_read_them(): void
    {
        $service = app(AmenitySearchMetrics::class);
        foreach ([10, 20, 100] as $duration) {
            $service->record('manager_search', ['engine' => 'smart', 'total' => 0, 'fallback_reason' => 'quota_or_rate_limit', 'query' => 'private text must not persist', 'user_id' => $this->residentUser->id], $duration);
        }
        $summary = $service->summary();
        $this->assertSame(20.0, $summary['p50_ms']);
        $this->assertSame(100.0, $summary['p95_ms']);
        $this->assertSame(1.0, $summary['fallback_rate']);
        $columns = Schema::getColumnListing('search_request_metrics');
        $this->assertNotContains('query', $columns);
        $this->assertNotContains('user_id', $columns);
        $this->withToken($this->residentToken)->getJson('/api/v1/admin/amenity-search/metrics')->assertForbidden();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/amenity-search/metrics')->assertOk()->assertJsonPath('days', 7);
    }

    public function test_metrics_cleanup_keeps_recent_data_and_supports_dry_run(): void
    {
        $service = app(AmenitySearchMetrics::class);
        $this->travel(-31)->days();
        $service->record('manager_search', ['engine' => 'smart', 'total' => 1], 1);
        $this->travelBack();
        $service->record('manager_search', ['engine' => 'smart', 'total' => 1], 1);
        $this->artisan('search:cleanup-metrics', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('search_request_metrics')->count());
        $this->artisan('search:cleanup-metrics')->assertSuccessful();
        $this->assertSame(1, DB::table('search_request_metrics')->count());
    }

    public function test_rate_limit_failure_is_reported_without_remote_error_text(): void
    {
        Http::fake(['*' => Http::response(['message' => 'sensitive remote diagnostics'], 429)]);
        $result = $this->driver()->search('amenities', 'BBQ');
        $this->assertSame('smart', $result->engine);
        $this->assertSame('quota_or_rate_limit', $result->extra['fallback_reason']);
        $this->assertStringNotContainsString('sensitive remote diagnostics', json_encode($result->toArray()));
    }
}
