<?php

namespace App\Services\Search;

use App\Jobs\SyncSurveySearchIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

class SurveySearch
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
            throw new LogicException('Survey search jobs must be written in the business transaction.');
        }

        Queue::connection('database')->bulk(array_map(fn (string $id): SyncSurveySearchIndex => new SyncSurveySearchIndex($id), $ids), '', 'survey-search');
    }

    public function configure(): void
    {
        $this->index()->configure([
            'title' => ['type' => 'text', 'analyzer' => 'cassavas_text'],
            'survey_code' => ['type' => 'text', 'analyzer' => 'cassavas_text'],
            'revision' => ['type' => 'keyword'],
            'is_deleted' => ['type' => 'boolean'],
        ]);
    }

    public function publish(string $id): void
    {
        $row = DB::table('satisfaction_surveys')->where('id', $id)->first(['id', 'title', 'survey_code', 'version']);
        if ($row === null) {
            return;
        }

        $this->configure();
        $this->index()->write($row->id, [
            'revision' => $row->id.':'.$row->version,
            'is_deleted' => false,
            'title' => $row->title,
            'survey_code' => $row->survey_code,
        ], max(1, (int) $row->version));
    }

    public function filter(Builder $scope, string $keyword): void
    {
        $ids = null;
        if (self::enabled() && trim($keyword) !== '') {
            $readScope = fn () => (clone $scope)->limit(10001)->pluck('version', 'id')->all();
            $ids = $this->index()->matchingRevisions($readScope, $keyword, ['survey_code^3', 'title']);
        }

        if ($ids === null) {
            $scope->where(fn (Builder $query) => $query->where('title', 'like', '%'.$keyword.'%')->orWhere('survey_code', 'like', '%'.$keyword.'%'));
        } else {
            $scope->whereKey($ids);
        }
    }

    private function index(): ElasticsearchIndex
    {
        return new ElasticsearchIndex(config('search.drivers.elasticsearch'), config('search.drivers.elasticsearch.surveys_index', 'cassavas_surveys_v1'));
    }
}
