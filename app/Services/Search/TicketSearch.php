<?php

namespace App\Services\Search;

use App\Jobs\SyncTicketSearchIndex;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

class TicketSearch
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
            throw new LogicException('Ticket search jobs must be written in the business transaction.');
        }

        SyncTicketSearchIndex::dispatch($id)->onConnection('database')->onQueue('ticket-search')->beforeCommit();
    }

    public function configure(): void
    {
        $text = ['type' => 'text', 'analyzer' => 'cassavas_text'];
        $this->index()->configure([
            'ticket_number' => $text, 'title' => $text, 'location' => $text,
            'revision' => ['type' => 'keyword'], 'is_deleted' => ['type' => 'boolean'],
        ]);
    }

    public function publish(Ticket $ticket): void
    {
        $this->configure();
        $deleted = $ticket->trashed();
        $this->index()->write($ticket->id, [
            'is_deleted' => $deleted,
            'revision' => $ticket->id.':'.$ticket->version,
            ...($deleted ? [] : $ticket->only(['ticket_number', 'title', 'location'])),
        ], 2 * max(1, (int) $ticket->version) + (int) $deleted);
    }

    public function filter(Builder $scope, string $keyword, bool $includeDescription = false): void
    {
        $ids = $this->matching($scope, $keyword);
        $scope->where(function (Builder $search) use ($ids, $keyword, $includeDescription): void {
            if ($ids === null) {
                $search->where('ticket_number', 'like', "%{$keyword}%")
                    ->orWhere('title', 'like', "%{$keyword}%")
                    ->orWhere('location', 'like', "%{$keyword}%");
            } else {
                $search->whereKey($ids);
            }

            if ($includeDescription) {
                $search->orWhere('description', 'like', "%{$keyword}%");
            }
        });
    }

    /** @return list<string>|null */
    private function matching(Builder $scope, string $keyword): ?array
    {
        if (! self::enabled() || trim($keyword) === '') {
            return null;
        }

        $readScope = fn () => (clone $scope)->limit(10001)->pluck('version', 'id')->all();

        return $this->index()->matchingRevisions($readScope, $keyword, ['ticket_number^3', 'title^2', 'location']);
    }

    private function index(): ElasticsearchIndex
    {
        return new ElasticsearchIndex(config('search.drivers.elasticsearch'), config('search.drivers.elasticsearch.tickets_index', 'cassavas_tickets_v1'));
    }
}
