<?php

namespace App\Services\Search\Drivers;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\DTOs\SearchResult;
use Illuminate\Support\Facades\Http;

class ElasticsearchDriver implements SearchDriverInterface
{
    public function __construct(protected array $config = []) {}

    public function search(string $index, string $query, array $filters = [], array $options = []): SearchResult
    {
        $startTime = microtime(true);
        $hosts = $this->config['hosts'] ?? ['http://127.0.0.1:9200'];
        $host = rtrim($hosts[0] ?? 'http://127.0.0.1:9200', '/');
        $page = max((int) ($options['page'] ?? 1), 1);
        $perPage = max((int) ($options['per_page'] ?? 10), 1);

        try {
            $req = Http::timeout(2);
            if (! empty($this->config['username'])) {
                $req = $req->withBasicAuth($this->config['username'], $this->config['password'] ?? '');
            }

            $response = $req->post("{$host}/{$index}/_search", [
                'from' => ($page - 1) * $perPage,
                'size' => $perPage,
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => ['amenity_name^3', 'amenity_code^2', 'location_detail'],
                        'fuzziness' => 'AUTO',
                    ],
                ],
            ]);

            if ($response->successful()) {
                $body = $response->json();
                $hits = array_map(fn ($h) => $h['_source'] ?? [], $body['hits']['hits'] ?? []);
                $total = $body['hits']['total']['value'] ?? count($hits);
                $elapsedMs = (microtime(true) - $startTime) * 1000;

                return new SearchResult($hits, $total, $page, $perPage, $elapsedMs, 'elasticsearch');
            }
        } catch (\Throwable) {
            // Fallback sang database
        }

        $fallback = new SmartSearchDriver($this->config);

        return $fallback->search($index, $query, $filters, $options);
    }

    public function suggest(string $index, string $prefix, int $limit = 5): array
    {
        $fallback = new SmartSearchDriver($this->config);

        return $fallback->suggest($index, $prefix, $limit);
    }

    public function index(string $index, string $id, array $document): void {}

    public function delete(string $index, string $id): void {}

    public function flush(string $index): void {}
}
