<?php

namespace Tests\Feature;

use App\Jobs\SyncAmenitySearchIndex;
use App\Services\Search\AlgoliaTaskPendingException;
use App\Services\Search\AmenitySearchSyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AmenitySearchSyncStateTest extends TestCase
{
    private string $amenityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->amenityId = (string) Str::uuid();
        config(['search.drivers.algolia' => ['app_id' => 'TESTAPP', 'key' => 'qa-key', 'amenities_index' => 'amenities_test', 'sync_enabled' => true]]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::table('amenity_search_states')->where('amenity_id', $this->amenityId)->delete();
        DB::table('jobs')->where('queue', 'amenity-search')->where('payload', 'like', '%'.$this->amenityId.'%')->delete();
        parent::tearDown();
    }

    public function test_every_remote_call_runs_without_a_database_transaction_and_duplicate_jobs_are_noops(): void
    {
        Http::fake(function ($request) {
            $this->assertSame(0, DB::transactionLevel(), 'Network calls must not retain row locks.');

            return Http::response($request->method() === 'POST' ? ['taskID' => 42] : ['status' => 'published']);
        });
        (new SyncAmenitySearchIndex($this->amenityId))->handle();
        (new SyncAmenitySearchIndex($this->amenityId))->handle();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['is_deleted'] === true && $request['search_version'] === ['_operation' => 'IncrementSet', 'value' => 1]);
        $this->assertDatabaseHas('amenity_search_states', ['amenity_id' => $this->amenityId, 'published_version' => 1, 'status' => 'synced']);
    }

    public function test_state_and_job_roll_back_together(): void
    {
        DB::beginTransaction();
        try {
            app(AmenitySearchSyncState::class)->queue($this->amenityId);
            $this->assertDatabaseHas('amenity_search_states', ['amenity_id' => $this->amenityId, 'desired_version' => 1]);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseMissing('amenity_search_states', ['amenity_id' => $this->amenityId]);
        $this->assertFalse(DB::table('jobs')->where('payload', 'like', '%'.$this->amenityId.'%')->exists());
    }

    public function test_late_acknowledgement_cannot_clear_newer_pending_work_or_reduce_published_version(): void
    {
        $states = app(AmenitySearchSyncState::class);
        $states->queue($this->amenityId);
        $states->queue($this->amenityId);
        $states->acknowledge($this->amenityId, 1);
        $this->assertDatabaseHas('amenity_search_states', ['amenity_id' => $this->amenityId, 'desired_version' => 2, 'published_version' => 1, 'status' => 'pending']);
        $states->acknowledge($this->amenityId, 2);
        $states->acknowledge($this->amenityId, 1);
        $this->assertDatabaseHas('amenity_search_states', ['amenity_id' => $this->amenityId, 'published_version' => 2, 'status' => 'synced']);
    }

    public function test_lost_task_status_response_resumes_the_persisted_task_without_another_write(): void
    {
        $published = false;
        Http::fake(function ($request) use (&$published) {
            if ($request->method() === 'POST') {
                return Http::response(['taskID' => 42]);
            }
            if (! $published) {
                throw new \RuntimeException('QA network interruption');
            }

            return Http::response(['status' => 'published']);
        });
        try {
            (new SyncAmenitySearchIndex($this->amenityId))->handle();
            $this->fail('The worker must report the network interruption.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('QA network interruption', $exception->getMessage());
        }
        $published = true;
        (new SyncAmenitySearchIndex($this->amenityId))->handle();
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertDatabaseHas('amenity_search_states', ['amenity_id' => $this->amenityId, 'status' => 'synced']);
    }

    public function test_publication_polling_has_a_finite_retry_limit(): void
    {
        app(AmenitySearchSyncState::class)->queue($this->amenityId);
        Http::fake(fn () => throw new AlgoliaTaskPendingException(42));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bounded retry window');
        try {
            (new SyncAmenitySearchIndex($this->amenityId, 42, 1, 9))->handle();
        } finally {
            $this->assertDatabaseHas('amenity_search_states', ['amenity_id' => $this->amenityId, 'status' => 'failed', 'error_code' => 'publication_timeout']);
        }
    }

    public function test_configuration_rejects_a_queue_that_defers_the_atomic_insert(): void
    {
        config(['queue.connections.database.after_commit' => true]);
        $this->expectException(\LogicException::class);
        app(AmenitySearchSyncState::class)->queue($this->amenityId);
    }
}
