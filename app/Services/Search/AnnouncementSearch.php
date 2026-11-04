<?php

namespace App\Services\Search;

use App\Jobs\SyncAnnouncementSearchIndex;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

class AnnouncementSearch
{
    public static function enabled(): bool
    {
        return config('search.default') === 'elasticsearch';
    }

    public function queue(string $id): void
    {
        if (! self::enabled()) {
            return;
        }

        $connection = config('queue.connections.database.connection') ?: config('database.default');
        if ($connection !== DB::getDefaultConnection() || config('queue.connections.database.after_commit')) {
            throw new LogicException('Announcement search jobs must be written in the business transaction.');
        }

        SyncAnnouncementSearchIndex::dispatch($id)->onConnection('database')->onQueue('announcement-search')->beforeCommit();
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
        $row = DB::table('announcements')->where('id', $id)->first(['id', 'title', 'version', 'deleted_at']);
        if ($row === null) {
            return;
        }

        $this->configure();
        $deleted = $row->deleted_at !== null;
        $this->index()->write($row->id, [
            'revision' => $row->id.':'.$row->version,
            'is_deleted' => $deleted,
            ...($deleted ? [] : ['title' => $row->title]),
        ], max(1, (int) $row->version));
    }

    public function filter(Builder $scope, string $keyword, string $alias): void
    {
        $ids = null;
        if (self::enabled() && trim($keyword) !== '') {
            $readScope = fn () => (clone $scope)->select($alias.'.version', $alias.'.id')
                ->limit(10001)->pluck($alias.'.version', $alias.'.id')->all();
            $ids = $this->index()->matchingRevisions($readScope, $keyword, ['title']);
        }

        if ($ids === null) {
            $scope->where($alias.'.title', 'like', '%'.$keyword.'%');
        } else {
            $scope->whereIn($alias.'.id', $ids);
        }
    }

    private function index(): ElasticsearchIndex
    {
        return new ElasticsearchIndex(config('search.drivers.elasticsearch'), config('search.drivers.elasticsearch.announcements_index', 'cassavas_announcements_v1'));
    }
}
