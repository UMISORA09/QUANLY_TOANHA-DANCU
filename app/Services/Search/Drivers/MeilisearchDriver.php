<?php

namespace App\Services\Search\Drivers;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\DTOs\SearchResult;
use Illuminate\Support\Facades\Http;

class MeilisearchDriver implements SearchDriverInterface
{
    public function __construct(protected array $config = []) {}

    public function search(string $index, string $query, array $filters = [], array $options = []): SearchResult
    {
        $startTime = microtime(true);
        $host = rtrim($this->config['host'] ?? 'http://127.0.0.1:7700', '/');
        $key = $this->config['key'] ?? null;
        $page = max((int) ($options['page'] ?? 1), 1);
        $perPage = max((int) ($options['per_page'] ?? 10), 1);

        try {
            $headers = ['Content-Type' => 'application/json'];
            if ($key) {
                $headers['Authorization'] = 'Bearer '.$key;
            }

            $response = Http::withHeaders($headers)
                ->timeout(2)
                ->post("{$host}/indexes/{$index}/search", [
                    'q' => $query,
                    'offset' => ($page - 1) * $perPage,
                    'limit' => $perPage,
                ]);

            if ($response->successful()) {
                $body = $response->json();
                $elapsedMs = (microtime(true) - $startTime) * 1000;

                return new SearchResult(
                    $body['hits'] ?? [],
                    $body['estimatedTotalHits'] ?? count($body['hits'] ?? []),
                    $page,
                    $perPage,
                    $elapsedMs,
                    'meilisearch'
                );
            }
        } catch (\Throwable) {
            // Fallback sang database nếu Meilisearch down
        }

        // Graceful fallback
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
