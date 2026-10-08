<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RevenueAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueAnalyticsController extends Controller
{
    public function __construct(
        protected RevenueAnalyticsService $analyticsService
    ) {}

    /**
     * Toàn bộ dữ liệu phân tích Dashboard Doanh thu & Công nợ (Chart.js / Analytics).
     */
    public function dashboard(Request $request): JsonResponse
    {
        $year = $request->has('year') ? (int) $request->input('year') : null;
        $period = $request->input('period');

        $data = $this->analyticsService->getDashboardData($year, $period);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Xu hướng doanh thu theo 12 tháng.
     */
    public function monthlyTrend(Request $request): JsonResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $data = $this->analyticsService->getMonthlyTrend($year);

        return response()->json([
            'success' => true,
            'year' => $year,
            'data' => $data,
        ]);
    }

    /**
     * Cơ cấu doanh thu theo loại phí (Điện, Nước, Quản lý, Gửi xe,...).
     */
    public function categoryBreakdown(Request $request): JsonResponse
    {
        $year = (int) ($request->input('year') ?: date('Y'));
        $period = $request->input('period');
        $data = $this->analyticsService->getRevenueByCategory($year, $period);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Top căn hộ nợ đọng nhiều nhất.
     */
    public function topDebtors(Request $request): JsonResponse
    {
        $limit = (int) ($request->input('limit') ?: 5);
        $data = $this->analyticsService->getTopDebtors($limit);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
