<?php

namespace Tests\Feature;

use App\Services\Search\AmenitySearchMetrics;
use App\Services\Search\AmenitySearchSyncState;
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
        config(['search.default' => 'elasticsearch', 'search.drivers.elasticsearch.amenities_index' => 'amenities_test']);
        foreach (DB::table('amenities')->pluck('id') as $id) {
            DB::table('amenity_search_states')->updateOrInsert(['amenity_id' => $id], ['desired_version' => 1, 'published_version' => 1, 'status' => 'synced', 'created_at' => now(), 'updated_at' => now(), 'index_fingerprint' => AmenitySearchSyncState::fingerprint()]);
        }
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
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
}
