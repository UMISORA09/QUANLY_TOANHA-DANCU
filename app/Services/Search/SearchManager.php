<?php

namespace App\Services\Search;

use App\Services\Concerns\HasPerformanceCache;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Drivers\ElasticsearchDriver;
use App\Services\Search\Drivers\MeilisearchDriver;
use App\Services\Search\Drivers\SmartSearchDriver;
use App\Services\Search\DTOs\SearchResult;
use InvalidArgumentException;

class SearchManager
{
    use HasPerformanceCache;

    /**
     * @var array<string, SearchDriverInterface>
     */
    protected array $drivers = [];

    public function driver(?string $name = null): SearchDriverInterface
    {
        $name = $name ?: config('search.default', 'smart');

        if (! isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->resolve($name);
        }

        return $this->drivers[$name];
    }

    protected function resolve(string $name): SearchDriverInterface
    {
        $config = config("search.drivers.{$name}", []);

        return match ($name) {
            'smart', 'database', 'ponytail' => new SmartSearchDriver($config),
            'meilisearch' => new MeilisearchDriver($config),
            'elasticsearch' => new ElasticsearchDriver($config),
            default => throw new InvalidArgumentException("Search driver [{$name}] is not supported."),
        };
    }

    public function search(string $index, string $query, array $filters = [], array $options = []): SearchResult
    {
        $started = microtime(true);
        $cacheParams = [
            'q' => trim(mb_strtolower($query)),
            'filters' => $filters,
            'options' => $options,
            'driver' => config('search.default', 'smart'),
        ];

        if (! empty($options['no_cache'])) {
            $result = $this->driver()->search($index, $query, $filters, $options);
            if (app()->bound('request') && request()) {
                request()->attributes->set('x_cache_status', 'BYPASS');
            }
        } else {
            $isHit = true;
            $result = $this->rememberSearchResult($index, $cacheParams, function () use ($index, $query, $filters, $options, &$isHit): SearchResult {
                $isHit = false;

                return $this->driver()->search($index, $query, $filters, $options);
            }, $options['cache_ttl'] ?? 300);

            if (app()->bound('request') && request()) {
                request()->attributes->set('x_cache_status', $isHit ? 'HIT' : 'MISS');
            }
        }

        if ($index === 'amenities') {
            app(AmenitySearchMetrics::class)->record($options['request_kind'] ?? 'manager_search', [
                'engine' => $result->engine, 'total' => $result->total, ...$result->extra,
            ], (microtime(true) - $started) * 1000);
        }

        return $result;
    }

    public function suggest(string $index, string $prefix, int $limit = 5): array
    {
        $cacheParams = [
            'prefix' => trim(mb_strtolower($prefix)),
            'limit' => $limit,
            'driver' => config('search.default', 'smart'),
        ];

        return $this->rememberSearchResult("suggest_{$index}", $cacheParams, function () use ($index, $prefix, $limit): array {
            return $this->driver()->suggest($index, $prefix, $limit);
        }, 300);
    }

    public function index(string $index, string $id, array $document): void
    {
        $this->driver()->index($index, $id, $document);
        $this->flushSearchEntityCache($index);
        $this->flushSearchEntityCache("suggest_{$index}");
    }

    public function delete(string $index, string $id): void
    {
        $this->driver()->delete($index, $id);
        $this->flushSearchEntityCache($index);
        $this->flushSearchEntityCache("suggest_{$index}");
    }

    public function flush(string $index): void
    {
        $this->driver()->flush($index);
        $this->flushSearchEntityCache($index);
        $this->flushSearchEntityCache("suggest_{$index}");
    }
}
