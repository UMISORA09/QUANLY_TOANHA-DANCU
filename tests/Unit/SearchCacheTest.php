<?php

namespace Tests\Unit;

use App\Services\Concerns\HasPerformanceCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SearchCacheTest extends TestCase
{
    private object $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->cacheService = new class
        {
            use HasPerformanceCache;

            public function search(string $entity, array $params, \Closure $callback): mixed
            {
                return $this->rememberSearchResult($entity, $params, $callback, 300);
            }
        };
    }

    public function test_it_caches_search_results(): void
    {
        $callCount = 0;
        $fetcher = function () use (&$callCount) {
            $callCount++;

            return ['item 1', 'item 2'];
        };

        $result1 = $this->cacheService->search('amenities', ['q' => 'swimming'], $fetcher);
        $result2 = $this->cacheService->search('amenities', ['q' => 'swimming'], $fetcher);

        $this->assertSame(['item 1', 'item 2'], $result1);
        $this->assertSame(['item 1', 'item 2'], $result2);
        $this->assertSame(1, $callCount, 'Fetcher should only execute once due to caching');
    }

    public function test_different_params_generate_different_cache(): void
    {
        $callCount = 0;
        $fetcher = function () use (&$callCount) {
            $callCount++;

            return ['result'];
        };

        $this->cacheService->search('amenities', ['q' => 'pool'], $fetcher);
        $this->cacheService->search('amenities', ['q' => 'gym'], $fetcher);

        $this->assertSame(2, $callCount, 'Different search queries should miss cache');
    }

    public function test_flush_invalidates_cached_search_results(): void
    {
        $callCount = 0;
        $fetcher = function () use (&$callCount) {
            $callCount++;

            return ['versioned data'];
        };

        $this->cacheService->search('amenities', ['q' => 'pool'], $fetcher);
        $this->assertSame(1, $callCount);

        // Xóa cache của entity amenities
        $this->cacheService->flushSearchEntityCache('amenities');

        // Lần gọi tiếp theo phải kích hoạt fetcher lại
        $this->cacheService->search('amenities', ['q' => 'pool'], $fetcher);
        $this->assertSame(2, $callCount, 'Fetcher should be executed again after cache flush');
    }
}
