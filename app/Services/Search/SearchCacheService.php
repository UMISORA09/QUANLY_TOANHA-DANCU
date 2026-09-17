<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;

class SearchCacheService
{
    public const REGISTRY_KEY = 'amenity_search_registry_keys';

    /**
     * Cache kết quả tìm kiếm theo Cache Key chuẩn quy định
     * key format: amenity_search:{normalized_query}:{filters}:{page}
     *
     * @return array<string, mixed>
     */
    public static function remember(string $cacheKey, int $ttlSeconds, callable $callback): array
    {
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $callback();

        // Đăng ký key để phục vụ Invalidation khi có CRUD Tiện ích
        $keys = Cache::get(self::REGISTRY_KEY, []);
        if (! in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::put(self::REGISTRY_KEY, $keys, 86400);
        }

        Cache::put($cacheKey, $result, $ttlSeconds);

        return $result;
    }

    /**
     * Invalidate toàn bộ cache tìm kiếm khi Admin: CREATE, UPDATE, DELETE tiện ích
     */
    public static function invalidate(): void
    {
        $keys = Cache::get(self::REGISTRY_KEY, []);
        if (is_array($keys)) {
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }
        Cache::forget(self::REGISTRY_KEY);
    }
}
