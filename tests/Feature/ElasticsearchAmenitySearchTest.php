<?php

namespace Tests\Feature;

use App\Jobs\SyncAmenitySearchIndex;
use App\Jobs\SyncTicketSearchIndex;
use App\Services\AmenityService;
use App\Services\Search\AmenitySearchSyncState;
use App\Services\Search\Drivers\ElasticsearchDriver;
use App\Services\Search\ElasticsearchIndex;
use App\Services\Search\TicketSearch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ElasticsearchAmenitySearchTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        config(['search.default' => 'elasticsearch', 'search.drivers.elasticsearch.amenities_index' => 'amenities_test']);
        foreach (DB::table('amenities')->pluck('id') as $id) {
            DB::table('amenity_search_states')->updateOrInsert(['amenity_id' => $id], [
                'desired_version' => 1, 'published_version' => 1, 'status' => 'synced', 'created_at' => now(), 'updated_at' => now(),
                'index_fingerprint' => AmenitySearchSyncState::fingerprint(),
            ]);
        }
        Http::preventStrayRequests();
    }

    public function test_docker_index_sync_search_and_versioned_deletion(): void
    {
        $host = getenv('TEST_ELASTICSEARCH_URL');
        if (! $host) {
            $this->markTestSkipped('Set TEST_ELASTICSEARCH_URL to a disposable Elasticsearch instance.');
        }
        $this->assertStringEndsWith('_test', DB::getDatabaseName());
        $index = 'cassavas_test_'.strtolower(Str::random(12));
        config(['search.drivers.elasticsearch.hosts' => [$host], 'search.drivers.elasticsearch.amenities_index' => $index]);
        Http::allowStrayRequests([rtrim($host, '/').'/'.$index.'*']);
        DB::table('amenity_search_states')->update(['index_fingerprint' => AmenitySearchSyncState::fingerprint()]);
        $driver = new ElasticsearchDriver(config('search.drivers.elasticsearch'));
        $states = app(AmenitySearchSyncState::class);
        $job = new SyncAmenitySearchIndex($this->amenityId);
        $filters = ['allowed_block_ids' => [$this->blockId], 'is_active' => true];

        try {
            DB::table('amenities')->where('id', $this->amenityId)->update(['amenity_name' => 'Hồ bơi Zafiro']);
            $states->queue($this->amenityId);
            $job->handle();

            $result = $driver->search('amenities', 'ho boi zafro', $filters, ['sort' => 'created_at_desc']);
            $this->assertSame('elasticsearch', $result->engine);
            $this->assertSame([$this->amenityId], array_column($result->items, 'id'));
            $this->getJson('/api/v1/resident/amenities/search?'.http_build_query([
                'apartment_id' => $this->apartmentId, 'q' => 'ho boi zafro',
            ]))->assertOk()->assertJsonPath('engine', 'elasticsearch')->assertJsonFragment(['id' => $this->amenityId]);
            $remote = new ElasticsearchIndex(config('search.drivers.elasticsearch'), $index);
            $this->assertSame([$this->amenityId], $remote->matchingIds([$this->amenityId], 'ho boi zafro', ['amenity_name']));
            $this->assertSame([], $remote->matchingIds([(string) Str::uuid()], 'ho boi zafro', ['amenity_name']));
            $this->assertSame([], $remote->matchingIds([], 'ho boi zafro', ['amenity_name']));
            $outside = $driver->search('amenities', 'zafiro', ['allowed_block_ids' => []]);
            $this->assertSame('elasticsearch', $outside->engine);
            $this->assertSame(0, $outside->total);

            DB::table('amenities')->where('id', $this->amenityId)->update(['deleted_at' => now()]);
            $states->queue($this->amenityId);
            $job->handle();
            $driver->publish($this->amenityId, ['amenity_name' => 'Hồ bơi Zafiro'], 2);
            $deleted = $driver->search('amenities', 'zafiro', $filters);
            $this->assertSame('elasticsearch', $deleted->engine);
            $this->assertSame(0, $deleted->total);
        } finally {
            Http::delete(rtrim($host, '/').'/'.$index);
        }
    }

    public function test_transaction_rollback_also_rolls_back_sync_job(): void
    {
        $before = DB::table('jobs')->where('queue', 'amenity-search')->count();
        DB::beginTransaction();
        try {
            app(AmenityService::class)->updateAmenity($this->amenityId, [
                'amenity_name' => 'Rollback Elasticsearch QA',
                'updated_at' => DB::table('amenities')->where('id', $this->amenityId)->value('updated_at'),
            ]);
            $this->assertSame($before + 1, DB::table('jobs')->where('queue', 'amenity-search')->count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, DB::table('jobs')->where('queue', 'amenity-search')->count());
    }

    public function test_production_index_creation_lets_elastic_cloud_manage_replicas(): void
    {
        $this->app->instance('env', 'production');
        Http::fakeSequence('https://elastic.example/cassavas_test')
            ->pushStatus(404)
            ->push(['acknowledged' => true]);

        $index = new ElasticsearchIndex(['hosts' => ['https://elastic.example'], 'api_key' => 'test-key'], 'cassavas_test');
        $index->configure(['title' => ['type' => 'text', 'analyzer' => 'cassavas_text']]);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && ! array_key_exists('number_of_replicas', $request['settings'])
            && $request['mappings']['properties']['title']['analyzer'] === 'cassavas_text');
    }

    public function test_ticket_backfill_batch_is_written_in_the_business_transaction(): void
    {
        $jobs = DB::table('jobs')->where('queue', 'ticket-search');
        $before = $jobs->count();
        DB::beginTransaction();
        try {
            app(TicketSearch::class)->queueMany([(string) Str::uuid(), (string) Str::uuid()]);
            $this->assertSame($before + 2, $jobs->count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, $jobs->count());
    }

    public function test_public_search_and_suggestions_require_a_real_session(): void
    {
        $this->withHeader('Authorization', '');
        $this->getJson('/api/v1/amenities/search?q=pool')->assertUnauthorized();
        $this->getJson('/api/v1/search/suggestions?q=po')->assertUnauthorized();
        $this->withToken('smart_token_admin_demo')->getJson('/api/amenities/search?q=pool')->assertUnauthorized();
    }

    public function test_worker_endpoint_rejects_missing_or_invalid_machine_secret(): void
    {
        config(['search.sync_secret' => str_repeat('a', 40)]);
        $this->withHeader('Authorization', '')->getJson('/api/v1/internal/amenity-search/process')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/internal/amenity-search/process')->assertUnauthorized();
        $this->withToken(str_repeat('a', 40))->getJson('/api/v1/internal/amenity-search/process')->assertOk();
    }

    public function test_production_worker_can_process_all_search_queues_without_touching_other_jobs(): void
    {
        $secret = str_repeat('w', 40);
        config(['search.sync_secret' => $secret]);
        app('queue')->connection('database')->push(new SyncTicketSearchIndex((string) Str::uuid()), '', 'ticket-search');
        $this->withToken($secret)->getJson('/api/v1/internal/amenity-search/process?inspect=schema&scope=all')
            ->assertOk()->assertJsonPath('tables.tickets', true)->assertJsonPath('tables.satisfaction_surveys', true);
        Artisan::shouldReceive('call')->once()->with('queue:work', [
            'connection' => 'database', '--queue' => 'amenity-search,booking-code-search,ticket-search,announcement-search,suggestion-search,survey-search',
            '--once' => true, '--tries' => 5, '--timeout' => 45,
        ])->andReturn(0);
        $this->getJson('/api/v1/internal/amenity-search/process?scope=all')->assertOk()->assertJsonPath('pending', 1);
    }

    public function test_protected_reindex_queues_existing_rows_once(): void
    {
        config(['search.sync_secret' => str_repeat('b', 40)]);
        Http::fake(['*' => Http::response()]);
        $this->withHeader('Authorization', '')->postJson('/api/v1/internal/amenity-search/reindex')->assertUnauthorized();
        $expected = DB::table('amenities')->count();
        $this->withToken(str_repeat('b', 40))->postJson('/api/v1/internal/amenity-search/reindex')->assertStatus(202)->assertJsonPath('pending', $expected);
        $this->postJson('/api/v1/internal/amenity-search/reindex')->assertStatus(409);
        $this->assertSame($expected, DB::table('jobs')->where('queue', 'amenity-search')->count());
        Http::assertNothingSent();
    }

    public function test_reindex_retries_only_its_failed_jobs_and_redacts_diagnostics(): void
    {
        Http::fake();
        config(['search.sync_secret' => str_repeat('c', 40), 'queue.failed.driver' => 'database-uuids', 'queue.failed.database' => config('database.default')]);
        $jobId = app('queue')->connection('database')->push(new SyncAmenitySearchIndex($this->amenityId), '', 'amenity-search');
        $payload = DB::table('jobs')->where('id', $jobId)->value('payload');
        DB::table('jobs')->where('id', $jobId)->delete();
        $failedId = (string) Str::uuid();
        foreach (['amenity-search' => $failedId, 'unrelated-queue' => (string) Str::uuid()] as $queue => $uuid) {
            DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => $queue, 'payload' => $payload, 'exception' => 'RuntimeException: Elasticsearch failed to index. private-value-must-not-leak', 'failed_at' => now()]);
        }
        $this->withToken(str_repeat('c', 40))->getJson('/api/v1/internal/amenity-search/process')
            ->assertOk()->assertJsonPath('failure_codes.worker_failed', 1)->assertDontSee('private-value-must-not-leak');
        $expected = DB::table('amenities')->count() + 1;
        $this->postJson('/api/v1/internal/amenity-search/reindex')->assertStatus(202)->assertJsonPath('pending', $expected);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $failedId]);
        $this->assertDatabaseHas('failed_jobs', ['queue' => 'unrelated-queue']);
        Http::assertNothingSent();
    }

    public function test_schema_inspection_classifies_connection_errors_without_exposing_credentials(): void
    {
        config(['search.sync_secret' => str_repeat('d', 40)]);
        $exception = new PDOException('Secret hostname and password must not be returned');
        $exception->errorInfo = ['HY000', 2006, 'Secret database details'];
        Schema::shouldReceive('hasTable')->once()->with('amenity_bookings')->andThrow($exception);
        $response = $this->withToken(str_repeat('d', 40))->getJson('/api/v1/internal/amenity-search/process?inspect=schema')
            ->assertStatus(503)->assertJsonPath('error_code', 'connection_lost')
            ->assertJsonPath('database', 'unhealthy')->assertJsonStructure(['database_provider'])
            ->assertJsonMissingPath('host')->assertJsonMissingPath('password')->assertJsonMissingPath('tables');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('Secret', $response->getContent());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'amenity-search')->count());
    }

    public function test_schema_inspection_rejects_unauthorized_requests_before_reading_schema(): void
    {
        config(['search.sync_secret' => str_repeat('d', 40)]);
        Schema::shouldReceive('hasTable')->never();
        $this->withToken('invalid')->getJson('/api/v1/internal/amenity-search/process?inspect=schema')->assertUnauthorized();
    }

    public function test_worker_database_failure_is_redacted_without_retrying_work(): void
    {
        config(['search.sync_secret' => str_repeat('e', 40)]);
        $exception = new PDOException('Private hostname and password');
        $exception->errorInfo = ['HY000', 2006, 'Private database details'];
        $connection = DB::connection();
        DB::partialMock()->shouldReceive('connection')->andReturn($connection);
        DB::partialMock()->shouldReceive('table')->once()->with('jobs')->andThrow($exception);
        Artisan::shouldReceive('call')->never();

        $response = $this->withToken(str_repeat('e', 40))->getJson('/api/v1/internal/amenity-search/process')
            ->assertStatus(503)->assertJsonPath('error_code', 'connection_lost')
            ->assertJsonPath('database', 'unhealthy')->assertDontSee('Private');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_missing_worker_table_returns_schema_missing_instead_of_a_raw_sql_error(): void
    {
        config(['search.sync_secret' => str_repeat('e', 40)]);
        $exception = new PDOException('Private SQL statement');
        $exception->errorInfo = ['42S02', 1146, 'Private table name'];
        $connection = DB::connection();
        DB::partialMock()->shouldReceive('connection')->andReturn($connection);
        DB::partialMock()->shouldReceive('table')->once()->with('jobs')->andThrow($exception);
        Artisan::shouldReceive('call')->never();

        $this->withToken(str_repeat('e', 40))->getJson('/api/v1/internal/amenity-search/process')
            ->assertStatus(503)->assertJsonPath('error_code', 'schema_missing')->assertDontSee('Private');
    }

    public function test_schema_inspection_requires_machine_secret_and_does_not_run_jobs(): void
    {
        config(['search.sync_secret' => str_repeat('d', 40)]);
        $this->withHeader('Authorization', '')->getJson('/api/v1/internal/amenity-search/process?inspect=schema')->assertUnauthorized();
        $this->withToken(str_repeat('d', 40))->getJson('/api/v1/internal/amenity-search/process?inspect=schema')
            ->assertOk()->assertJsonPath('tables.amenity_bookings', true)->assertJsonPath('tables.amenity_booking_payments', true)
            ->assertJsonPath('tables.amenities', true)->assertJsonPath('tables.jobs', true)->assertJsonPath('tables.failed_jobs', true)
            ->assertJsonMissingPath('host')->assertJsonMissingPath('password');
        $this->assertSame(0, DB::table('jobs')->where('queue', 'amenity-search')->count());
    }
}
