<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class GlobalSearchController extends Controller
{
    public function __construct(
        protected GlobalSearchService $searchService
    ) {}

    /**
     * Endpoint tìm kiếm chung toàn hệ thống (Omni Search / Global Search)
     * GET /api/v1/search?q=...&type=...&status=...&page=1&limit=20
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user() ?: Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực tài khoản trước khi tìm kiếm.',
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'q' => ['nullable', 'string', 'max:100'],
            'query' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:all,resident,temporary_registration,vehicle,access_card,visitor,parcel'],
            'status' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'q.max' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.',
            'query.max' => 'Từ khóa tìm kiếm không được vượt quá 100 ký tự.',
            'type.in' => 'Loại dữ liệu tìm kiếm không hợp lệ.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->searchService->search($user, [
            'q' => (string) ($request->query('q') ?? $request->query('query') ?? ''),
            'type' => (string) ($request->query('type') ?? 'all'),
            'status' => $request->query('status'),
            'limit' => (int) ($request->query('limit') ?? 20),
            'page' => (int) ($request->query('page') ?? 1),
        ]);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    /**
     * Endpoint kiểm tra sức khỏe và chỉ mục Meilisearch
     * GET /api/v1/search/health
     */
    public function health(): JsonResponse
    {
        $health = $this->searchService->getHealth();

        return response()->json(array_merge([
            'success' => true,
        ], $health));
    }
}
