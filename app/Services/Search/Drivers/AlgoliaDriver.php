<?php

namespace App\Services\Search\Drivers;

use App\Services\Search\AlgoliaTaskPendingException;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\DTOs\SearchResult;
use App\Services\Search\VietnameseNormalizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;
use Throwable;

class AlgoliaDriver implements SearchDriverInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config = []) {}

    private function indexName(string $index): string
    {
        $name = $this->config['amenities_index'] ?? '';
        if ($index !== 'amenities' || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
            throw new LogicException('Only the configured amenities index is supported.');
        }

        return $name;
    }

    private function client(): PendingRequest
    {
        $appId = $this->config['app_id'] ?? '';
        if (! preg_match('/^[A-Z0-9]+$/D', $appId) || empty($this->config['key'])) {
            throw new LogicException('Algolia server credentials are not configured.');
        }

        return Http::baseUrl('https://'.$appId.'.algolia.net/1')
            ->withHeaders(['X-Algolia-Application-Id' => $appId, 'X-Algolia-API-Key' => $this->config['key']])
            ->acceptJson()->asJson()->connectTimeout(3)->timeout(5);
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $options */
    public function search(string $index, string $query, array $filters = [], array $options = []): SearchResult
    {
        $started = microtime(true);
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($options['per_page'] ?? 10)));
        try {
            $name = $this->indexName($index);
            if (($options['sort'] ?? 'relevance') !== 'relevance' || ! ($this->config['sync_enabled'] ?? false)
                || DB::table('jobs')->where('queue', 'amenity-search')->exists()
                || DB::table('failed_jobs')->where('queue', 'amenity-search')->exists()) {
                throw new RuntimeException('Database search is required while the index is not synchronized or for this sort.');
            }
            $facets = [];
            $rows = DB::table('amenities')->leftJoin('amenity_categories', 'amenities.category_id', '=', 'amenity_categories.id')
                ->leftJoin('blocks', 'amenities.block_id', '=', 'blocks.id')
                ->whereNull('amenities.deleted_at')->select('amenities.*', 'amenity_categories.category_name', 'amenity_categories.category_code', 'blocks.block_name', 'blocks.block_code');
            foreach (['category_id', 'block_id', 'is_active'] as $field) {
                if (isset($filters[$field]) && $filters[$field] !== '') {
                    $value = $field === 'is_active' ? (int) filter_var($filters[$field], FILTER_VALIDATE_BOOLEAN) : (string) $filters[$field];
                    $facets[] = $field.':'.$value;
                    $rows->where('amenities.'.$field, $value);
                }
            }
            $response = $this->client()->post('/indexes/'.$name.'/query', [
                'query' => $query, 'page' => $page - 1, 'hitsPerPage' => $perPage,
                'facetFilters' => $facets, 'attributesToRetrieve' => ['objectID', 'updated_at'],
                'attributesToHighlight' => [],
            ]);
            if (! $response->successful() || ! is_array($response->json('hits')) || ! is_numeric($response->json('nbHits')) || $response->json('exhaustiveNbHits') === false) {
                throw new RuntimeException('Algolia search is unavailable.');
            }
            $ids = array_column($response->json('hits'), 'objectID');
            $items = $rows->whereIn('amenities.id', $ids)->get()->keyBy('id');
            if (count($ids) !== $items->count()) {
                throw new RuntimeException('Index contains stale or out of scope results.');
            }
            foreach ($response->json('hits') as $hit) {
                if (! array_key_exists('updated_at', $hit) || $hit['updated_at'] !== $items[$hit['objectID']]->updated_at) {
                    throw new RuntimeException('Index document version is stale.');
                }
            }

            return new SearchResult(array_map(fn (string $id): object => $items[$id], $ids), (int) $response->json('nbHits'), $page, $perPage, (microtime(true) - $started) * 1000, 'algolia');
        } catch (Throwable) {
            return (new SmartSearchDriver(config('search.drivers.smart', [])))->search($index, $query, $filters, $options);
        }
    }

    public function suggest(string $index, string $prefix, int $limit = 5): array
    {
        if (mb_strlen(trim($prefix)) < 2) {
            return [];
        }
        $result = $this->search($index, $prefix, [], ['per_page' => max(1, min(20, $limit))]);

        return array_map(function ($raw): array {
            $item = (object) $raw;

            return ['id' => $item->id, 'label' => $item->amenity_name, 'code' => $item->amenity_code, 'type' => 'amenities'];
        }, $result->items);
    }

    /** @param array<string, mixed> $document */
    public function index(string $index, string $id, array $document): void
    {
        $body = array_intersect_key($document, array_flip(['amenity_name', 'amenity_code', 'category_id', 'block_id', 'is_active', 'updated_at']));
        $body['objectID'] = $id;
        $body['amenity_name_ascii'] = VietnameseNormalizer::stripVietnameseAccents(mb_strtolower((string) ($body['amenity_name'] ?? '')));
        $body['block_id'] = $body['block_id'] ?? '__shared__';
        $body['is_active'] = (int) ($body['is_active'] ?? false);
        $this->write('PUT', $index, '/'.rawurlencode($id), $body);
    }

    public function delete(string $index, string $id): void
    {
        $this->write('DELETE', $index, '/'.rawurlencode($id));
    }

    public function flush(string $index): void
    {
        if (! ($this->config['allow_flush'] ?? false)) {
            throw new LogicException('Index clearing requires explicit operator configuration.');
        }
        $this->write('POST', $index, '/clear');
    }

    public function configure(): void
    {
        $this->write('PUT', 'amenities', '/settings', [
            'searchableAttributes' => ['amenity_code', 'amenity_name', 'amenity_name_ascii'],
            'attributesForFaceting' => ['filterOnly(category_id)', 'filterOnly(block_id)', 'filterOnly(is_active)'],
            'disableTypoToleranceOnAttributes' => ['amenity_code'],
            'ranking' => ['exact', 'typo', 'words', 'filters', 'proximity', 'attribute', 'custom'],
        ]);
    }

    /** @param array<string, mixed> $body */
    private function write(string $method, string $index, string $suffix, array $body = []): void
    {
        $path = '/indexes/'.$this->indexName($index);
        $response = $this->client()->send($method, $path.$suffix, ['json' => $body]);
        if (! $response->successful() || ! is_numeric($response->json('taskID'))) {
            throw new RuntimeException('Algolia index write failed. The synchronization job must be retried.');
        }
        $this->waitForTask($index, (int) $response->json('taskID'), $suffix === '/settings' ? 45 : 25);
    }

    public function waitForTask(string $index, int $taskId, int $timeout = 25): void
    {
        $path = '/indexes/'.$this->indexName($index);
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $status = $this->client()->get($path.'/task/'.$taskId);
            if ($status->successful() && $status->json('status') === 'published') {
                return;
            }
            if (! $status->successful()) {
                throw new RuntimeException('Algolia task status request failed (HTTP '.$status->status().').');
            }
            usleep(300000);
        }
        throw new AlgoliaTaskPendingException($taskId);
    }
}
