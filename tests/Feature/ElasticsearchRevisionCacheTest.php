<?php

namespace Tests\Feature;

use App\Models\SatisfactionSurvey;
use App\Models\Suggestion;
use App\Models\Ticket;
use App\Services\Search\AnnouncementSearch;
use App\Services\Search\ElasticsearchIndex;
use App\Services\Search\SuggestionSearch;
use App\Services\Search\SurveySearch;
use App\Services\Search\TicketSearch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ElasticsearchRevisionCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        Cache::flush();
        config(['search.default' => 'elasticsearch', 'search.drivers.elasticsearch.hosts' => ['http://elastic.qa']]);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response($this->searchResponse(
            array_map(fn (string $revision): string => explode(':', $revision)[0], $request['query']['bool']['filter'][0]['terms']['revision'])
        )));
    }

    private function index(): ElasticsearchIndex
    {
        return new ElasticsearchIndex(['hosts' => ['http://elastic.qa']], 'qa_revisions');
    }

    private function searchResponse(array $ids): array
    {
        return ['hits' => ['hits' => array_map(fn (string $id): array => ['_id' => $id], $ids), 'total' => ['value' => count($ids)]],
            'aggregations' => ['current_scope' => ['doc_count' => count($ids)]]];
    }

    public function test_warm_hits_still_recheck_scope_and_version(): void
    {
        $index = $this->index();
        $calls = 0;
        $scope = function () use (&$calls): array {
            $calls++;

            return ['a' => 1];
        };
        $this->assertSame(['a'], $index->matchingRevisions($scope, 'gym', ['title']));
        $this->assertSame(['a'], $index->matchingRevisions($scope, 'gym', ['title']));
        Http::assertSentCount(1);
        $this->assertSame(4, $calls);
        $calls = 0;
        $changed = function () use (&$calls): array {
            return ['a' => ++$calls === 1 ? 1 : 2];
        };
        $this->assertNull($index->matchingRevisions($changed, 'gym', ['title']));
        $this->assertSame(['a'], $index->matchingRevisions(fn (): array => ['a' => 2], 'gym', ['title']));
        Http::assertSentCount(2);
    }

    public function test_incomplete_index_is_not_cached_and_can_recover(): void
    {
        Http::swap(new Factory);
        Http::fakeSequence()->push($this->searchResponse([]))->push($this->searchResponse(['a']));
        $index = $this->index();
        $this->assertNull($index->matchingRevisions(fn (): array => ['a' => 1], 'gym', ['title']));
        $this->assertSame(['a'], $index->matchingRevisions(fn (): array => ['a' => 1], 'gym', ['title']));
        Http::assertSentCount(2);
    }

    public static function scopedServices(): array
    {
        return [
            'announcements' => [AnnouncementSearch::class, 'announcements', null],
            'tickets' => [TicketSearch::class, 'tickets', Ticket::class],
            'suggestions' => [SuggestionSearch::class, 'suggestions', Suggestion::class],
            'surveys' => [SurveySearch::class, 'satisfaction_surveys', SatisfactionSurvey::class],
        ];
    }

    #[DataProvider('scopedServices')]
    public function test_same_keyword_does_not_reuse_another_scope(string $serviceClass, string $table, ?string $modelClass): void
    {
        $this->createSearchTable($table);
        $scope = fn (string $id) => ($modelClass ? $modelClass::query() : DB::table($table))->where('id', $id);
        $first = $scope('a');
        $second = $scope('b');
        $service = app($serviceClass);
        if ($service instanceof AnnouncementSearch) {
            $service->filter($first, 'gym', $table);
            $service->filter($second, 'gym', $table);
        } else {
            $service->filter($first, 'gym');
            $service->filter($second, 'gym');
        }
        $this->assertSame(['a'], $first->pluck('id')->all());
        $this->assertSame(['b'], $second->pluck('id')->all());
    }

    private function createSearchTable(string $table): void
    {
        Schema::create($table, function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->boolean('is_anonymous')->default(false);
            $table->string('content_text')->nullable();
            $table->softDeletes();
        });
        DB::table($table)->insert([['id' => 'a', 'version' => 1, 'title' => 'gym'], ['id' => 'b', 'version' => 1, 'title' => 'gym']]);
    }

    public function test_reports_cold_and_warm_scoped_search_latency_without_changing_results(): void
    {
        $this->createSearchTable('announcements');
        Http::swap(new Factory);
        Http::fake(function (): mixed {
            usleep(10000);

            return Http::response($this->searchResponse(['a']));
        });
        $measure = function (): float {
            $query = DB::table('announcements')->where('id', 'a');
            $start = hrtime(true);
            app(AnnouncementSearch::class)->filter($query, 'gym', 'announcements');
            $this->assertSame(['a'], $query->pluck('id')->all());

            return (hrtime(true) - $start) / 1_000_000;
        };
        $cold = $warm = [];
        for ($sample = 0; $sample < 20; $sample++) {
            Cache::flush();
            $cold[] = $measure();
        }
        Http::assertSentCount(20);
        for ($sample = 0; $sample < 20; $sample++) {
            $warm[] = $measure();
        }
        Http::assertSentCount(20);
        sort($cold);
        sort($warm);
        fwrite(STDERR, PHP_EOL.json_encode(['benchmark' => 'SQLite QA, simulated Elasticsearch transport 10ms, 20 samples per phase',
            'cold_p50_ms' => round($cold[9], 3), 'cold_p95_ms' => round($cold[18], 3),
            'warm_p50_ms' => round($warm[9], 3), 'warm_p95_ms' => round($warm[18], 3)]).PHP_EOL);
    }

    public function test_docker_scoped_search_cold_and_warm_benchmark(): void
    {
        $host = getenv('TEST_ELASTICSEARCH_URL');
        if (! $host || ! in_array(parse_url($host, PHP_URL_HOST), ['elasticsearch', 'localhost', '127.0.0.1'], true)) {
            $this->markTestSkipped('Requires an explicitly configured local QA Elasticsearch instance.');
        }
        $name = 'cassavas_perf_test_'.strtolower(Str::random(12));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::allowStrayRequests([rtrim($host, '/').'/'.$name.'*']);
        Http::record();
        $index = new ElasticsearchIndex(['hosts' => [$host]], $name);
        try {
            $index->configure(['title' => ['type' => 'text', 'analyzer' => 'cassavas_text'],
                'revision' => ['type' => 'keyword'], 'is_deleted' => ['type' => 'boolean']]);
            $index->write('a', ['title' => 'Hồ bơi QA', 'revision' => 'a:1', 'is_deleted' => false], 1);
            $samples = [];
            foreach (['cold', 'warm'] as $phase) {
                for ($sample = 0; $sample < 20; $sample++) {
                    if ($phase === 'cold') {
                        Cache::flush();
                    }
                    $start = hrtime(true);
                    $this->assertSame(['a'], $index->matchingRevisions(fn (): array => ['a' => 1], 'ho boi', ['title']));
                    $samples[$phase][] = (hrtime(true) - $start) / 1_000_000;
                }
                Http::assertSentCount(23);
                sort($samples[$phase]);
            }
            fwrite(STDERR, PHP_EOL.json_encode(['benchmark' => 'Real local Elasticsearch Docker, 20 samples per phase',
                'cold_p50_ms' => round($samples['cold'][9], 3), 'cold_p95_ms' => round($samples['cold'][18], 3),
                'warm_p50_ms' => round($samples['warm'][9], 3), 'warm_p95_ms' => round($samples['warm'][18], 3)]).PHP_EOL);
        } finally {
            Http::delete(rtrim($host, '/').'/'.$name);
        }
    }
}
