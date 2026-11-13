<?php

namespace Tests\Feature;

use App\Jobs\SyncAmenitySearchIndex;
use App\Services\Search\AmenitySearchSyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AmenitySearchSyncStateTest extends TestCase
{
    private string $amenityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->amenityId = (string) Str::uuid();
        config(['search.drivers.elasticsearch.amenities_index' => 'amenities_test']);
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

            return Http::response();
        });
        (new SyncAmenitySearchIndex($this->amenityId))->handle();
        (new SyncAmenitySearchIndex($this->amenityId))->handle();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request['is_deleted'] === true && str_contains($request->url(), 'version_type=external_gte&version=1'));
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

    public function test_configuration_rejects_a_queue_that_defers_the_atomic_insert(): void
    {
        config(['queue.connections.database.after_commit' => true]);
        $this->expectException(\LogicException::class);
        app(AmenitySearchSyncState::class)->queue($this->amenityId);
    }

    public function test_parallel_qa_workers_keep_both_outbox_updates(): void
    {
        $states = app(AmenitySearchSyncState::class);
        $states->queue($this->amenityId);
        DB::beginTransaction();
        DB::table('amenity_search_states')->where('amenity_id', $this->amenityId)->lockForUpdate()->first();
        $workers = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $worker = new Process(['php', 'artisan', 'test', '--compact', __FILE__, '--filter=test_concurrent_qa_queue_worker'], base_path(), ['SEARCH_RACE_AMENITY_ID' => $this->amenityId]);
                $worker->setTimeout(60)->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 40;
            foreach ($workers as $worker) {
                while (! str_contains($worker->getOutput(), 'QA_WORKER_READY') && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(50000);
                }
                $this->assertStringContainsString('QA_WORKER_READY', $worker->getOutput().$worker->getErrorOutput());
            }
            DB::commit();
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
            }
            $this->assertDatabaseHas('amenity_search_states', ['amenity_id' => $this->amenityId, 'desired_version' => 3, 'published_version' => 0]);
            $this->assertSame(3, DB::table('jobs')->where('queue', 'amenity-search')->where('payload', 'like', '%'.$this->amenityId.'%')->count());
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_concurrent_qa_queue_worker(): void
    {
        $id = getenv('SEARCH_RACE_AMENITY_ID');
        if (! $id) {
            $this->markTestSkipped('Invoked only by the isolated parallel QA worker test.');
        }
        $this->assertTrue(Str::isUuid($id));
        fwrite(STDOUT, 'QA_WORKER_READY'.PHP_EOL);
        app(AmenitySearchSyncState::class)->queue($id);
        $this->assertDatabaseHas('amenity_search_states', ['amenity_id' => $id, 'status' => 'pending']);
    }
}
