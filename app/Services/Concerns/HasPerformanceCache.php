<?php

namespace App\Services\Concerns;

use Closure;
use Illuminate\Support\Facades\Cache;

trait HasPerformanceCache
{
    /**
     * Kiểm tra xem Cache store hiện tại có hỗ trợ cache tags hay không.
     */
    protected function supportsCacheTags(): bool
    {
        return Cache::supportsTags();
    }

    /**
     * Lấy version hiện tại của entity để phục vụ cache invalidation trên driver không hỗ trợ tag.
     */
    protected function getEntityCacheVersion(string $entity): int
    {
        return (int) Cache::get("cache_version:{$entity}", 1);
    }

    /**
     * Xóa cache của một entity (khi có create/update/delete).
     */
    public function flushSearchEntityCache(string $entity): void
    {
        if ($this->supportsCacheTags()) {
            Cache::tags(["search_{$entity}"])->flush();
        } else {
            // Tăng version lên 1 để vô hiệu hóa toàn bộ cache cũ mà không cần duyệt file
            Cache::increment("cache_version:{$entity}");
        }
    }

    /**
     * Lưu trữ và nạp kết quả tìm kiếm với TTL và tagging.
     *
     * @param  array<string, mixed>  $params
     */
    protected function rememberSearchResult(string $entity, array $params, Closure $callback, int $ttl = 300): mixed
    {
        $version = $this->getEntityCacheVersion($entity);
        ksort($params);
        $paramHash = md5(json_encode($params));
        $cacheKey = "search:{$entity}:v{$version}:{$paramHash}";

        if ($this->supportsCacheTags()) {
            return Cache::tags(['search', "search_{$entity}"])->remember($cacheKey, $ttl, $callback);
        }

        return Cache::remember($cacheKey, $ttl, $callback);
    }

    /**
     * Lưu trữ và nạp kết quả tổng hợp báo cáo (Analytics).
     */
    protected function rememberAnalyticsResult(string $category, string $subKey, Closure $callback, int $ttl = 600): mixed
    {
        $version = $this->getEntityCacheVersion($category);
        $cacheKey = "analytics:{$category}:v{$version}:{$subKey}";

        if ($this->supportsCacheTags()) {
            return Cache::tags(['analytics', "analytics_{$category}"])->remember($cacheKey, $ttl, $callback);
        }

        return Cache::remember($cacheKey, $ttl, $callback);
    }

    /**
     * Xóa cache báo cáo thống kê của một phân hệ.
     */
    public function flushAnalyticsCache(string $category): void
    {
        if ($this->supportsCacheTags()) {
            Cache::tags(["analytics_{$category}"])->flush();
        } else {
            Cache::increment("cache_version:{$category}");
        }
    }
}
