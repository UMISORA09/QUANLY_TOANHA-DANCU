<?php

namespace App\Http\Controllers;

use App\Repositories\Eloquent\DatabaseResidentAmenityBookingRepository;
use App\Services\AmenityService;
use App\Services\RbacService;
use App\Services\Search\AiVectorSearchService;
use App\Services\Search\AmenitySearchMetrics;
use App\Services\Search\SearchCacheService;
use App\Services\Search\SearchManager;
use App\Services\Search\VietnameseNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        $user = $request->user();
        if ($user && ! RbacService::hasPermission($user, 'AMENITY:VIEW')) {
            return response()->json([
                'message' => 'Bạn không có quyền xem tiện ích (AMENITY:VIEW)',
                'detail' => 'Bạn không có quyền xem tiện ích (AMENITY:VIEW)',
            ], 403);
        }

        $rawQuery = (string) ($request->query('q') ?? $request->query('query') ?? $request->query('search') ?? '');
        $page = max((int) $request->query('page', 1), 1);
        $limit = max(min((int) ($request->query('limit') ?? $request->query('per_page') ?? 10), 100), 1);
        $filtersArray = $this->amenityService->scopedFilters($request);
        $sort = (string) $request->query('sort', 'relevance');

        // Chuẩn hóa truy vấn và sửa lỗi gõ tiếng Việt
        $norm = VietnameseNormalizer::normalize($rawQuery);
        $normalizedQuery = $norm['normalized'] !== '' ? $norm['normalized'] : 'empty';

        // Xây dựng Cache key: amenity_search:{normalized_query}:{filters}:{page}
        $filterString = ! empty($filtersArray) ? md5(json_encode($filtersArray)) : 'none';
        $driverName = (string) config('search.default', 'smart');
        $context = hash('sha256', json_encode([3, $rawQuery, $limit, $sort, $driverName, config('search.drivers.elasticsearch.amenities_index'), $user?->id]));
        $cacheKey = "amenity_search:{$normalizedQuery}:{$filterString}:{$page}:{$context}";

        $startTime = microtime(true);

        $load = function () use (
            $rawQuery,
            $norm,
            $filtersArray,
            $page,
            $limit,
            $sort,
            $user,
            $startTime
        ) {
            $driver = $this->searchManager->driver();
            $searchResult = $driver->search('amenities', $rawQuery, $filtersArray, [
                'page' => $page,
                'per_page' => $limit,
                'sort' => $sort,
                'cache_context' => $user?->id,
            ]);

            $items = $searchResult->items;
            $total = $searchResult->total;
            $elapsedMs = (microtime(true) - $startTime) * 1000;

            return [
                'data' => $items,
                'query' => $rawQuery,
                'normalized_query' => $norm['normalized'],
                'corrected_query' => $searchResult->extra['corrected_query'] ?? $rawQuery,
                'applied_query' => $searchResult->extra['applied_query'] ?? $searchResult->extra['corrected_query'] ?? $rawQuery,
                'is_corrected' => $searchResult->extra['is_corrected'] ?? (($searchResult->extra['corrected_query'] ?? $rawQuery) !== $rawQuery),
                'fallback_reason' => $searchResult->extra['fallback_reason'] ?? null,
                'page' => $page, 'per_page' => $limit, 'total_pages' => $searchResult->getTotalPages(),
                'total' => $total,
                'engine' => $searchResult->engine,
                'search_time_ms' => round($elapsedMs, 2),
            ];
        };
        $isHit = Cache::has($cacheKey);
        $request->attributes->set('x_cache_status', $isHit ? 'HIT' : 'MISS');
        $responsePayload = SearchCacheService::remember($cacheKey, 300, $load);

        // Nếu lấy từ Cache, cập nhật search_time_ms phản ánh thời gian thực (< 1ms)
        $totalElapsed = (microtime(true) - $startTime) * 1000;
        $responsePayload['search_time_ms'] = round($totalElapsed, 2);
        app(AmenitySearchMetrics::class)->record('manager_search', $responsePayload, $totalElapsed);

        return response()->json($responsePayload)->header('Cache-Control', 'private, no-store');
    }

    public function metrics(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole(['ADMIN', 'SUPER_ADMIN', 'SUPER_ADMI']), 403);

        return response()->json(app(AmenitySearchMetrics::class)->summary())->header('Cache-Control', 'private, no-store');
    }

    /**
     * Endpoint gợi ý Autocomplete / Typeahead siêu tốc
     * GET /api/v1/search/suggestions?q=swi&type=amenities
     */
    public function suggestions(Request $request): JsonResponse
    {
        $request->validate(['q' => 'nullable|string|max:200', 'limit' => 'nullable|integer|min:1|max:20', 'type' => 'nullable|in:amenities']);
        $query = (string) $request->query('q', '');
        $type = (string) $request->query('type', 'amenities');
        abort_unless($type === 'amenities', 422, 'Only amenities suggestions are supported here.');
        $limit = (int) $request->query('limit', 5);
        $filters = $this->amenityService->scopedFilters($request);
        $startTime = microtime(true);
        $suggestions = [];
        if (mb_strlen(trim($query)) >= 2) {
            $result = $this->searchManager->search('amenities', $query, $filters, [
                'page' => 1, 'per_page' => $limit, 'cache_context' => $request->user()->id, 'request_kind' => 'manager_suggestions',
            ]);
            $suggestions = array_map(static function (array|object $item): array {
                $item = (object) $item;

                return [
                    'id' => $item->id, 'label' => $item->amenity_name, 'code' => $item->amenity_code,
                    'category' => $item->category_name ?? null, 'block' => $item->block_name ?? null,
                    'type' => 'amenity', 'score' => $item->relevance_score ?? 1,
                ];
            }, $result->items);
        }

        return response()->json([
            'suggestions' => $suggestions,
            'meta' => ['query' => $query, 'count' => count($suggestions), 'search_time_ms' => round((microtime(true) - $startTime) * 1000, 2)],
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Endpoint tìm kiếm ngữ nghĩa & lai (Hybrid Search) cho AI Assistant
     * GET /api/v1/search/ai-knowledge?q=gio+dong+cua+ho+boi&building_id=...
     */
    public function aiKnowledge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:500', 'building_id' => 'nullable|uuid',
            'categories' => 'nullable|string|max:500', 'limit' => 'nullable|integer|min:1|max:20',
        ]);
        $query = (string) ($data['q'] ?? '');
        $buildingId = $data['building_id'] ?? null;
        $categories = array_values(array_filter(array_map('trim', explode(',', $data['categories'] ?? ''))));
        $limit = (int) ($data['limit'] ?? 5);
        $user = $request->user();
        $allowedBlockIds = null;
        if (! $user->isSuperAdmin() && ! $user->hasRole('ADMIN')) {
            if ($user->hasRole('BUILDING_MANAGER')) {
                $profile = DB::table('staff_profiles')->where('user_id', $user->id)->first();
                abort_unless($profile, 403, 'Chưa có hồ sơ nhân viên và phạm vi tòa nhà.');
                abort_if($user->hasRole('BUILDING_MANAGER') && $profile->assigned_block_id === null, 403, 'Chưa được phân công tòa nhà.');
                $allowedBlockIds = $profile->assigned_block_id === null ? null : [$profile->assigned_block_id];
            } else {
                $allowedBlockIds = app(DatabaseResidentAmenityBookingRepository::class)->apartments($user->id)->pluck('block_id')->unique()->values()->all();
                abort_if($allowedBlockIds === [], 403, 'Bạn chưa có hồ sơ cư trú còn hiệu lực.');
            }
        }
        abort_if($buildingId !== null && $allowedBlockIds !== null && ! in_array($buildingId, $allowedBlockIds, true), 403);

        $startTime = microtime(true);
        $results = $this->aiVectorSearchService->hybridSearch(
            $query,
            null,
            $buildingId,
            $categories,
            $limit,
            $allowedBlockIds
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
        ])->header('Cache-Control', 'private, no-store');
    }
}
