<?php

namespace App\Http\Controllers;

use App\Services\Freshness\FreshnessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreshnessController extends Controller
{
    public function __construct(
        protected FreshnessService $freshnessService
    ) {}

    /**
     * Freshness Observability API: GET /api/monitoring/freshness
     */
    public function index(Request $request): JsonResponse
    {
        $force = $request->boolean('force', false);
        $overview = $this->freshnessService->getOverview($force);

        $httpStatus = 200;
        if ($overview['overall_state'] === FreshnessService::STATE_CRITICAL) {
            // Note: Trả HTTP 200 nhưng payload mang status critical để frontend/curl có thể đọc dữ liệu chi tiết
            $httpStatus = 200;
        }

        return response()->json($overview, $httpStatus, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
