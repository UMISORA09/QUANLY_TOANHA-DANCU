<?php

namespace App\Services\Search\Drivers;

use App\Services\Search\AlgoliaTaskPendingException;
use App\Services\Search\AmenitySearchSyncState;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\DTOs\SearchResult;
use App\Services\Search\VietnameseNormalizer;
use App\Services\Search\VietnameseSpellCorrector;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
        $calls = 0;
        $fallbackReason = 'index_unavailable';
        try {
            $name = $this->indexName($index);
            if (($options['sort'] ?? 'relevance') !== 'relevance') {
                $fallbackReason = 'unsupported_sort';
                throw new RuntimeException('Database search is required for this sort.');
            }
            if (! ($this->config['sync_enabled'] ?? false) || ! Schema::hasTable('amenity_search_states')
                || DB::table('amenity_search_states')->whereColumn('published_version', '<', 'desired_version')->exists()
                || DB::table('amenity_search_states')->where('index_fingerprint', '!=', AmenitySearchSyncState::fingerprint())->exists()
                || DB::table('amenities')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('amenity_search_states')->whereColumn('amenity_id', 'amenities.id'))->exists()) {
                $fallbackReason = 'index_not_synchronized';
                throw new RuntimeException('Database search is required while the index is not synchronized.');
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
            if (array_key_exists('allowed_block_ids', $filters)) {
                $facets[] = array_map(fn (string $id): string => 'block_id:'.$id, [...$filters['allowed_block_ids'], '__shared__']);
                $rows->where(fn ($query) => $query->whereNull('amenities.block_id')->orWhereIn('amenities.block_id', $filters['allowed_block_ids']));
            }
            $appliedQuery = $query;
            $cacheKey = 'amenity_algolia:'.hash('sha256', json_encode([
                2, AmenitySearchSyncState::fingerprint(), (string) DB::table('amenity_search_states')->sum('desired_version'),
                $query, $filters, $page, $perPage, $options['cache_context'] ?? null,
            ]));
            $remote = Cache::get($cacheKey);
            $cacheHit = is_array($remote);
            if (! $cacheHit) {
                $parameters = [
                    'query' => $query, 'page' => $page - 1, 'hitsPerPage' => $perPage,
                    'facetFilters' => $facets, 'attributesToRetrieve' => ['objectID', 'updated_at', 'search_version'],
                    'attributesToHighlight' => [],
                    'filters' => 'is_deleted:false AND search_schema_version:2',
                ];
                $calls++;
                $response = $this->client()->post('/indexes/'.$name.'/query', $parameters);
                if ($response->successful() && $response->json('nbHits') === 0) {
                    $correction = VietnameseSpellCorrector::correctQuery($query);
                    if ($correction['is_corrected'] && ! preg_match('/[0-9_-]/u', $query)) {
                        $appliedQuery = $correction['corrected'];
                        $calls++;
                        $response = $this->client()->post('/indexes/'.$name.'/query', [...$parameters, 'query' => $appliedQuery]);
                    }
                }
                if (! $response->successful() || ! is_array($response->json('hits')) || ! is_numeric($response->json('nbHits')) || $response->json('exhaustiveNbHits') === false) {
                    $fallbackReason = match ($response->status()) {
                        402, 429 => 'quota_or_rate_limit', 401, 403 => 'credentials_or_acl', default => 'index_unavailable'
                    };
                    throw new RuntimeException('Algolia search is unavailable.');
                }
                $remote = ['data' => $response->json(), 'applied_query' => $appliedQuery];
                Cache::put($cacheKey, $remote, 60);
            }
            $data = $remote['data'];
            $appliedQuery = $remote['applied_query'];
            $ids = array_column($data['hits'], 'objectID');
            $items = $rows->whereIn('amenities.id', $ids)->get()->keyBy('id');
            $versions = DB::table('amenity_search_states')->whereIn('amenity_id', $ids)->pluck('desired_version', 'amenity_id');
            if (count($ids) !== $items->count()) {
                $fallbackReason = 'stale_results';
                throw new RuntimeException('Index contains stale or out of scope results.');
            }
            foreach ($data['hits'] as $hit) {
                if (! array_key_exists('updated_at', $hit) || $hit['updated_at'] !== $items[$hit['objectID']]->updated_at
                    || ! isset($hit['search_version']) || (int) $hit['search_version'] !== (int) $versions->get($hit['objectID'], -1)) {
                    $fallbackReason = 'stale_results';
                    throw new RuntimeException('Index document version is stale.');
                }
            }

            return new SearchResult(array_map(fn (string $id): object => $items[$id], $ids), (int) $data['nbHits'], $page, $perPage, (microtime(true) - $started) * 1000, 'algolia', false, [
                'applied_query' => $appliedQuery, 'corrected_query' => $appliedQuery, 'is_corrected' => $appliedQuery !== $query,
                'fallback_reason' => null, 'algolia_calls' => $calls, 'cache_hit' => $cacheHit,
            ]);
        } catch (Throwable) {
            $result = (new SmartSearchDriver(config('search.drivers.smart', [])))->search($index, $query, $filters, $options);
            $result->extra['fallback_reason'] = $fallbackReason;
            $result->extra['algolia_calls'] = $calls;
            $result->searchTimeMs = (microtime(true) - $started) * 1000;

            return $result;
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
            'attributesForFaceting' => ['filterOnly(category_id)', 'filterOnly(block_id)', 'filterOnly(is_active)', 'filterOnly(is_deleted)', 'filterOnly(search_schema_version)'],
            'disableTypoToleranceOnAttributes' => ['amenity_code'],
            'ranking' => ['exact', 'typo', 'words', 'filters', 'proximity', 'attribute', 'custom'],
        ]);
        $this->write('POST', 'amenities', '/synonyms/batch?replaceExistingSynonyms=true', SmartSearchDriver::indexSynonyms());
    }

    /** @param array<string, mixed> $document */
    public function submitVersionedDocument(string $id, array $document, int $version): int
    {
        $body = array_intersect_key($document, array_flip(['amenity_name', 'amenity_code', 'category_id', 'block_id', 'is_active', 'updated_at']));
        $body['amenity_name_ascii'] = VietnameseNormalizer::stripVietnameseAccents(mb_strtolower((string) ($body['amenity_name'] ?? '')));
        $body['block_id'] = $body['block_id'] ?? '__shared__';
        $body['is_active'] = (int) ($body['is_active'] ?? false);
        $body['is_deleted'] = (bool) ($document['is_deleted'] ?? false);
        $body['search_schema_version'] = 2;
        // IncrementSet rejects the entire stale update, including a resurrection after deletion.
        $body['search_version'] = ['_operation' => 'IncrementSet', 'value' => $version];
        $response = $this->client()->post('/indexes/'.$this->indexName('amenities').'/'.rawurlencode($id).'/partial?createIfNotExists=true', $body);
        if (! $response->successful() || ! is_numeric($response->json('taskID'))) {
            throw new RuntimeException('Algolia index write failed. The synchronization job must be retried.');
        }

        return (int) $response->json('taskID');
    }

    /** @param array<string, mixed> $body */
    private function write(string $method, string $index, string $suffix, array $body = []): void
    {
        $path = '/indexes/'.$this->indexName($index);
        $response = $this->client()->send($method, $path.$suffix, ['json' => $body]);
        if (! $response->successful() || ! is_numeric($response->json('taskID'))) {
            throw new RuntimeException('Algolia index write failed. The synchronization job must be retried.');
        }
        $configurationWrite = $suffix === '/settings' || str_starts_with($suffix, '/synonyms/');
        $this->waitForTask($index, (int) $response->json('taskID'), $configurationWrite ? 120 : 25);
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
