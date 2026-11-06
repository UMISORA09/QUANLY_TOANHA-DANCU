<?php

namespace App\Services\Search;

use App\Jobs\SyncTicketSearchIndex;
use App\Models\Ticket;
use App\Services\Concerns\HasPerformanceCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

class TicketSearch
{
    use HasPerformanceCache;

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
            throw new LogicException('Ticket search jobs must be written in the business transaction.');
        }

        Queue::connection('database')->bulk(array_map(fn (string $id): SyncTicketSearchIndex => new SyncTicketSearchIndex($id), $ids), '', 'ticket-search');
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
        $this->flushSearchEntityCache('tickets');
        $this->flushSearchEntityCache('tickets_filter');
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

        $normalizedKey = trim(mb_strtolower($keyword));

        return $this->rememberSearchResult('tickets_filter', ['q' => $normalizedKey], function () use ($scope, $keyword): ?array {
            $readScope = fn () => (clone $scope)->limit(10001)->pluck('version', 'id')->all();

            return $this->index()->matchingRevisions($readScope, $keyword, ['ticket_number^3', 'title^2', 'location']);
        }, 300);
    }

    private function index(): ElasticsearchIndex
    {
        return new ElasticsearchIndex(config('search.drivers.elasticsearch'), config('search.drivers.elasticsearch.tickets_index', 'cassavas_tickets_v1'));
    }
}
