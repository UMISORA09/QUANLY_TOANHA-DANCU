<?php

namespace App\Services\Search\Drivers;

use App\Services\Search\AmenitySearchSyncState;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\DTOs\SearchResult;
use App\Services\Search\ElasticsearchIndex;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class ElasticsearchDriver implements SearchDriverInterface
{
    public function __construct(private array $config = []) {}

    private function remote(string $index): ElasticsearchIndex
    {
        if ($index !== 'amenities') {
            throw new LogicException('Only the configured Elasticsearch amenities index is supported.');
        }

        return new ElasticsearchIndex($this->config, $this->config['amenities_index'] ?? 'amenities');
    }

    public function configure(): void
    {
        $text = ['type' => 'text', 'analyzer' => 'cassavas_text'];
        $this->remote('amenities')->configure([
            'amenity_name' => [...$text, 'fields' => ['raw' => ['type' => 'keyword']]], 'amenity_code' => $text, 'location_detail' => $text,
            'document_id' => ['type' => 'keyword'], 'created_at' => ['type' => 'keyword'], 'hourly_rate' => ['type' => 'double'],
            'category_id' => ['type' => 'keyword'], 'block_id' => ['type' => 'keyword'],
            'is_active' => ['type' => 'boolean'], 'is_deleted' => ['type' => 'boolean'],
        ]);
    }

    public function search(string $index, string $query, array $filters = [], array $options = []): SearchResult
    {
        $start = microtime(true);
        $fallback = fn () => (new SmartSearchDriver(config('search.drivers.smart', [])))->search($index, $query, $filters, $options);
        $page = max(1, (int) ($options['page'] ?? 1));
        $size = max(1, min(100, (int) ($options['per_page'] ?? 10)));
        if ($index !== 'amenities' || $page * $size > 10000 || ($generation = $this->generation()) === null) {
            return $fallback();
        }

        $rows = DB::table('amenities')->leftJoin('amenity_categories', 'amenities.category_id', '=', 'amenity_categories.id')
            ->leftJoin('blocks', 'amenities.block_id', '=', 'blocks.id')->whereNull('amenities.deleted_at')
            ->select('amenities.*', 'amenity_categories.category_name', 'amenity_categories.category_code', 'blocks.block_name', 'blocks.block_code');
        $conditions = [['term' => ['is_deleted' => false]]];
        foreach (['category_id', 'block_id', 'is_active'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '' && ($field !== 'is_active' || $filters[$field] !== 'all')) {
                $value = $field === 'is_active' ? filter_var($filters[$field], FILTER_VALIDATE_BOOLEAN) : (string) $filters[$field];
                $conditions[] = ['term' => [$field => $value]];
                $rows->where('amenities.'.$field, $value);
            }
        }
        if (array_key_exists('allowed_block_ids', $filters)) {
            $conditions[] = ['terms' => ['block_id' => [...$filters['allowed_block_ids'], '__shared__']]];
            $rows->where(fn ($builder) => $builder->whereNull('amenities.block_id')->orWhereIn('amenities.block_id', $filters['allowed_block_ids']));
        }

        $sort = match ($options['sort'] ?? 'relevance') {
            'name_asc' => ['amenity_name.raw' => 'asc'],
            'name_desc' => ['amenity_name.raw' => 'desc'],
            'price_asc' => ['hourly_rate' => 'asc'],
            'price_desc' => ['hourly_rate' => 'desc'],
            'created_at_asc' => ['created_at' => 'asc'],
            'relevance' => ['_score' => 'desc', 'created_at' => 'desc'],
            default => ['created_at' => 'desc'],
        };

        $sort['document_id'] = 'asc';

        try {
            $response = $this->remote($index)->search([
                'from' => ($page - 1) * $size, 'size' => $size, 'track_total_hits' => true,
                '_source' => false,
                'sort' => array_map(fn (string $field, string $order): array => [$field => $order], array_keys($sort), array_values($sort)),
                'query' => ['bool' => [
                    'filter' => $conditions,
                    'must' => trim($query) === '' ? [['match_all' => (object) []]] : [['multi_match' => [
                        'query' => $query, 'fields' => ['amenity_name^3', 'amenity_code^2', 'location_detail'], 'fuzziness' => 'AUTO',
                    ]]],
                ]],
            ]);
        } catch (ConnectionException) {
            return $fallback();
        }
        $hits = $response->json('hits.hits');
        $total = $response->json('hits.total.value');
        if (! $response->successful() || ! is_array($hits) || ! is_int($total) || $response->json('timed_out') || $response->json('_shards.failed')) {
            return $fallback();
        }
        $ids = array_column($hits, '_id');
        $items = $rows->whereIn('amenities.id', $ids)->get()->keyBy('id');
        if (count($ids) !== count($hits) || $items->count() !== count($ids) || $generation !== $this->generation()) {
            return $fallback();
        }

        return new SearchResult(array_map(fn ($id) => $items[$id], $ids), $total, $page, $size, (microtime(true) - $start) * 1000, 'elasticsearch');
    }

    private function generation(): ?string
    {
        if (! Schema::hasTable('amenity_search_states')
            || DB::table('amenities')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('amenity_search_states')
                ->whereColumn('amenity_id', 'amenities.id'))->exists()) {
            return null;
        }

        $state = DB::table('amenity_search_states')->selectRaw(
            'COALESCE(SUM(desired_version), 0) AS generation, COALESCE(SUM(desired_version > published_version OR index_fingerprint IS NULL OR index_fingerprint <> ?), 0) AS dirty',
            [AmenitySearchSyncState::fingerprint()]
        )->first();

        return (int) $state->dirty === 0 ? (string) $state->generation : null;
    }

    public function suggest(string $index, string $prefix, int $limit = 5): array
    {
        return (new SmartSearchDriver(config('search.drivers.smart', [])))->suggest($index, $prefix, $limit);
    }

    public function index(string $index, string $id, array $document): void
    {
        $this->write($index, $id, $document);
    }

    public function publish(string $id, array $document, int $version): void
    {
        // Keep versioned tombstones so delayed workers cannot restore deleted records.
        $this->configure();
        $this->write('amenities', $id, $document, $version);
    }

    private function write(string $index, string $id, array $document, ?int $version = null): void
    {
        $this->remote($index)->write($id, [
            ...array_intersect_key($document, array_flip(['amenity_name', 'amenity_code', 'location_detail', 'category_id', 'created_at'])),
            'document_id' => $id,
            'hourly_rate' => (float) ($document['hourly_rate'] ?? 0),
            'block_id' => $document['block_id'] ?? '__shared__',
            'is_active' => (bool) ($document['is_active'] ?? false),
            'is_deleted' => (bool) ($document['is_deleted'] ?? false),
        ], $version);
    }

    public function delete(string $index, string $id): void
    {
        $this->remote($index)->delete($id);
    }

    public function flush(string $index): void
    {
        throw new LogicException('Rebuild amenities with search:sync-amenities; destructive flush is disabled.');
    }
}
