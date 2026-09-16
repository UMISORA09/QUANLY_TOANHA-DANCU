<?php

namespace App\Http\Controllers;

use App\Services\AmenityService;
use App\Services\RbacService;
use App\Services\Search\AiVectorSearchService;
use App\Services\Search\SearchCacheService;
use App\Services\Search\SearchManager;
use App\Services\Search\VietnameseNormalizer;
use App\Services\Search\VietnameseSpellCorrector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        protected SearchManager $searchManager,
        protected AmenityService $amenityService,
        protected AiVectorSearchService $aiVectorSearchService
    ) {}

    /**
     * Endpoint tìm kiếm tiện ích thông minh (Fuzzy Search & Relevance Scoring)
     * GET /api/amenities/search?q=ku%20nghi%20duon
     * GET /api/v1/amenities/search?q=ku%20nghi%20duon
     */
    public function searchAmenities(Request $request): JsonResponse
    {
        // 1. Kiểm tra RBAC: Người dùng phải có quyền AMENITY:VIEW nếu có định danh
        $user = RbacService::resolveUser($request);
        if ($user && ! RbacService::hasPermission($user, 'AMENITY:VIEW')) {
            return response()->json([
                'message' => 'Bạn không có quyền xem tiện ích (AMENITY:VIEW)',
                'detail' => 'Bạn không có quyền xem tiện ích (AMENITY:VIEW)',
            ], 403);
        }

        $rawQuery = (string) ($request->query('q') ?? $request->query('query') ?? $request->query('search') ?? '');
        $page = max((int) $request->query('page', 1), 1);
        $limit = max(min((int) ($request->query('limit') ?? $request->query('per_page') ?? 10), 100), 1);
        $categoryId = $request->query('category_id');
        $blockId = $request->query('block_id');
        $isActive = $request->query('is_active');
        $sort = (string) $request->query('sort', 'relevance');

        // Chuẩn hóa truy vấn và sửa lỗi gõ tiếng Việt
        $norm = VietnameseNormalizer::normalize($rawQuery);
        $correction = VietnameseSpellCorrector::correctQuery($rawQuery);
        $normalizedQuery = $norm['normalized'] !== '' ? $norm['normalized'] : 'empty';

        // Xây dựng Cache key: amenity_search:{normalized_query}:{filters}:{page}
        $filtersArray = array_filter([
            'category_id' => $categoryId ?: null,
            'block_id' => $blockId ?: null,
            'is_active' => $isActive !== null ? $isActive : null,
        ], fn ($v) => $v !== null && $v !== '');

        $filterString = ! empty($filtersArray) ? md5(json_encode($filtersArray)) : 'none';
        $cacheKey = "amenity_search:{$normalizedQuery}:{$filterString}:{$page}";

        $startTime = microtime(true);

        $responsePayload = SearchCacheService::remember($cacheKey, 60, function () use (
            $rawQuery,
            $norm,
            $correction,
            $filtersArray,
            $page,
            $limit,
            $sort,
            $startTime
        ) {
            $driver = $this->searchManager->driver('smart');
            $searchResult = $driver->search('amenities', $rawQuery, $filtersArray, [
                'page' => $page,
                'per_page' => $limit,
                'sort' => $sort,
            ]);

            $items = $searchResult->items;
            $total = $searchResult->total;
            $elapsedMs = (microtime(true) - $startTime) * 1000;

            return [
                'data' => $items,
                'query' => $rawQuery,
                'normalized_query' => $norm['normalized'],
                'corrected_query' => $searchResult->metadata['corrected_query'] ?? $correction['corrected'],
                'total' => $total,
                'search_time_ms' => round($elapsedMs, 2),
            ];
        });

        // Nếu lấy từ Cache, cập nhật search_time_ms phản ánh thời gian thực (< 1ms)
        $totalElapsed = (microtime(true) - $startTime) * 1000;
        $responsePayload['search_time_ms'] = round($totalElapsed, 2);

        return response()->json($responsePayload);
    }

    /**
     * Endpoint gợi ý Autocomplete / Typeahead siêu tốc
     * GET /api/v1/search/suggestions?q=swi&type=amenities
     */
    public function suggestions(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $type = (string) $request->query('type', 'amenities');
        $limit = max(min((int) $request->query('limit', 5), 20), 1);

        $startTime = microtime(true);
        $suggestions = $this->searchManager->suggest($type, $query, $limit);
        $elapsedMs = (microtime(true) - $startTime) * 1000;

        return response()->json([
            'suggestions' => $suggestions,
            'meta' => [
                'query' => $query,
                'count' => count($suggestions),
                'search_time_ms' => round($elapsedMs, 2),
            ],
        ]);
    }

    /**
     * Endpoint tìm kiếm ngữ nghĩa & lai (Hybrid Search) cho AI Assistant
     * GET /api/v1/search/ai-knowledge?q=gio+dong+cua+ho+boi&building_id=...
     */
    public function aiKnowledge(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');
        $buildingId = $request->query('building_id');
        $categories = $request->has('categories') ? explode(',', (string) $request->query('categories')) : [];
        $limit = max(min((int) $request->query('limit', 5), 20), 1);

        // Security check: Cư dân chỉ được phép tìm kiếm tài liệu thuộc tòa nhà của họ hoặc tài liệu chung
        $user = $request->user();
        if ($user && ! empty($user->building_id) && empty($buildingId)) {
            $buildingId = $user->building_id;
        }

        $startTime = microtime(true);
        $results = $this->aiVectorSearchService->hybridSearch(
            $query,
            null,
            $buildingId,
            $categories,
            $limit
        );
        $elapsedMs = (microtime(true) - $startTime) * 1000;

        return response()->json([
            'data' => $results,
            'meta' => [
                'query' => $query,
                'total' => count($results),
                'building_id' => $buildingId,
                'search_time_ms' => round($elapsedMs, 2),
            ],
        ]);
    }
}
