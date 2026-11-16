<?php

namespace Tests\Feature;

use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\Concerns\HasPerformanceCache;
use App\Services\SurveyAccessService;
use App\Services\SurveyAnalyticsService;
use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PerformanceCacheIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
    }

    private function service(bool $taggable = false): object
    {
        return new class($taggable)
        {
            use HasPerformanceCache;

            public function __construct(private bool $taggable) {}

            protected function supportsCacheTags(): bool
            {
                return $this->taggable;
            }

            public function search(Closure $load): mixed
            {
                return $this->rememberSearchResult('qa', ['q' => 'gym'], $load);
            }

            public function analytics(Closure $load): mixed
            {
                return $this->rememberAnalyticsResult('qa', 'summary', $load);
            }
        };
    }

    public function test_first_invalidation_works_without_cache_tags_for_search_and_analytics(): void
    {
        $service = $this->service();
        foreach (['search', 'analytics'] as $method) {
            Cache::flush();
            $calls = 0;
            $load = function () use (&$calls): int {
                return ++$calls;
            };
            $this->assertSame(1, $service->{$method}($load));
            $this->assertSame(1, $service->{$method}($load));
            if ($method === 'search') {
                $service->flushSearchEntityCache('qa');
            } else {
                $service->flushAnalyticsCache('qa');
            }
            $this->assertSame(2, $service->{$method}($load));
        }
    }

    public function test_database_cache_initializes_its_version_before_invalidation(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        Cache::swap(new Repository(new DatabaseStore(DB::connection('sqlite'), 'cache', 'qa:')));
        $service = $this->service();
        $calls = 0;
        $load = function () use (&$calls): int {
            return ++$calls;
        };
        $this->assertSame(1, $service->search($load));
        $service->flushSearchEntityCache('qa');
        $this->assertSame(2, $service->search($load));
    }

    public function test_rolled_back_reads_cannot_populate_shared_cache(): void
    {
        Cache::flush();
        $service = $this->service();
        foreach (['search', 'analytics'] as $method) {
            DB::beginTransaction();
            try {
                $this->assertSame('uncommitted', $service->{$method}(fn (): string => 'uncommitted'));
            } finally {
                DB::rollBack();
            }
            $this->assertSame('committed', $service->{$method}(fn (): string => 'committed'));
        }
    }

    public function test_a_slow_read_cannot_repopulate_cache_after_invalidation(): void
    {
        foreach ([false, true] as $taggable) {
            Cache::flush();
            $service = $this->service($taggable);
            $calls = 0;
            $load = function () use ($service, &$calls): int {
                if (++$calls === 1) {
                    $service->flushSearchEntityCache('qa');
                }

                return $calls;
            };
            $this->assertSame(1, $service->search($load));
            $this->assertSame(2, $service->search($load));
        }
    }

    public function test_survey_events_invalidate_analytics_only_after_commit(): void
    {
        $cache = Cache::tags(['analytics_surveys']);
        $cache->put('probe', 'previous', 60);
        DB::beginTransaction();
        Event::dispatch('eloquent.saved: '.SurveyResponse::class, [new SurveyResponse]);
        $this->assertSame('previous', $cache->get('probe'));
        DB::rollBack();
        $this->assertSame('previous', $cache->get('probe'));
        DB::beginTransaction();
        Event::dispatch('eloquent.saved: '.SurveyResponse::class, [new SurveyResponse]);
        DB::commit();
        $this->assertNull(Cache::tags(['analytics_surveys'])->get('probe'));
    }

    public function test_cached_analytics_rechecks_permission_and_uses_the_current_building_scope(): void
    {
        Schema::create('satisfaction_surveys', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('target_block_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('survey_questions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('survey_id');
            $table->string('question_type');
            $table->integer('question_order');
        });
        Schema::create('survey_responses', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('survey_id');
        });
        Schema::create('survey_response_details', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('question_id');
            $table->integer('numeric_score')->nullable();
        });
        foreach (['a', 'b', 'b'] as $id => $block) {
            DB::table('satisfaction_surveys')->insert(['id' => (string) $id, 'target_block_id' => $block,
                'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString()]);
        }
        $block = 'a';
        $allowed = true;
        $access = Mockery::mock(SurveyAccessService::class);
        $access->shouldReceive('scopeForManager')->times(4)->andReturnUsing(function ($user, $query) use (&$block, &$allowed) {
            abort_unless($allowed, 403);

            return $query->where('target_block_id', $block);
        });
        $service = new SurveyAnalyticsService($access);
        $user = new User;
        $user->id = 'qa';
        $this->assertSame(1, $service->getManagementOverviewKpis($user)['total_surveys']);
        $this->assertSame(1, $service->getManagementOverviewKpis($user)['total_surveys']);
        $block = 'b';
        $this->assertSame(2, $service->getManagementOverviewKpis($user)['total_surveys']);
        $allowed = false;
        $this->expectException(HttpException::class);
        $service->getManagementOverviewKpis($user);
    }
}
