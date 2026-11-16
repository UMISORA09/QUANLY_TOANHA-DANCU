<?php

namespace App\Http\Controllers;

use App\Services\RbacService;
use App\Services\ReceptionDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReceptionDashboardController extends Controller
{
    public function __construct(
        protected ReceptionDashboardService $dashboardService
    ) {}

    /**
     * Xác thực thông tin người dùng đang gọi request
     */
    protected function resolveAuthUser(Request $request): ?object
    {
        return $request->user() ?? RbacService::resolveUser($request);
    }

    /**
     * GET /api/v1/reception/dashboard
     * Lấy toàn bộ chỉ số KPI, xu hướng, cơ cấu và danh sách tóm tắt theo thời gian thực
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveAuthUser($request);
            $this->dashboardService->authorizeViewer($user);

            $filters = [
                'preset' => $request->query('preset', 'today'),
                'from_date' => $request->query('from_date', $request->query('start_date')),
                'to_date' => $request->query('to_date', $request->query('end_date')),
                'top_limit' => (int) $request->query('top_limit', 10),
            ];

            $metrics = $this->dashboardService->getDashboardMetrics($filters);

            return response()->json([
                'success' => true,
                'message' => 'Lấy dữ liệu Dashboard Giám sát Lễ tân thành công.',
                'data' => $metrics,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu bộ lọc không hợp lệ.',
                'errors' => $e->errors(),
            ], 422);
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi khi tổng hợp số liệu Dashboard: '.$e->getMessage(),
            ], 500);
        }
    }
}
