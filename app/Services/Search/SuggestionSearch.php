<?php

namespace App\Services\Search;

use App\Jobs\SyncSuggestionSearchIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

class SuggestionSearch
{
    public static function enabled(): bool
    {
        return config('search.default') === 'elasticsearch';
    }

    public function queue(string $id): void
    {
        $this->queueMany([$id]);
    }

    /** @param list<string> $ids */
    public function queueMany(array $ids): void
    {
        if ($ids === [] || ! self::enabled()) {
            return;
        }

        $connection = config('queue.connections.database.connection') ?: config('database.default');
        if ($connection !== DB::getDefaultConnection() || config('queue.connections.database.after_commit')) {
            throw new LogicException('Suggestion search jobs must be written in the business transaction.');
        }

        Queue::connection('database')->bulk(array_map(fn (string $id): SyncSuggestionSearchIndex => new SyncSuggestionSearchIndex($id), $ids), '', 'suggestion-search');
    }

    public function configure(): void
    {
        $this->index()->configure([
            'title' => ['type' => 'text', 'analyzer' => 'cassavas_text'],
            'revision' => ['type' => 'keyword'],
            'is_deleted' => ['type' => 'boolean'],
        ]);
    }

    public function publish(string $id): void
    {
        $row = DB::table('suggestions')->where('id', $id)->where('is_anonymous', false)->first(['id', 'title', 'version']);
        if ($row === null) {
            return;
        }

        $this->configure();
        $this->index()->write($row->id, [
            'revision' => $row->id.':'.$row->version,
            'is_deleted' => false,
            'title' => $row->title,
        ], max(1, (int) $row->version));
    }

    public function filter(Builder $scope, string $keyword): void
    {
        $ids = null;
        if (self::enabled() && trim($keyword) !== '') {
            $readScope = fn () => (clone $scope)->where('is_anonymous', false)->limit(10001)->pluck('version', 'id')->all();
            $ids = $this->index()->matchingRevisions($readScope, $keyword, ['title']);
        }

        $scope->where(function (Builder $query) use ($keyword, $ids): void {
            $pattern = '%'.$keyword.'%';
            if ($ids === null) {
                $query->where('title', 'like', $pattern);
            } else {
                $query->whereKey($ids)->orWhere(fn (Builder $anonymous) => $anonymous->where('is_anonymous', true)->where('title', 'like', $pattern));
            }
            $query->orWhere('content_text', 'like', $pattern);
        });
    }

    private function index(): ElasticsearchIndex
    {
        return new ElasticsearchIndex(config('search.drivers.elasticsearch'), config('search.drivers.elasticsearch.suggestions_index', 'cassavas_suggestions_v1'));
    }
}
