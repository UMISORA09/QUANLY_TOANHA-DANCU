<?php

namespace App\Http\Controllers;

use App\Services\Cicd\GitHubActionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class DevOpsApiController extends Controller
{
    public function __construct(
        protected GitHubActionsService $cicdService
    ) {}

    /**
     * Lấy trạng thái hệ thống cho DevOps Dashboard: GET /api/devops/status
     */
    public function status(): JsonResponse
    {
        $dbOnline = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbOnline = false;
        }

        $overview = $this->cicdService->getOverview();
        $health = $this->cicdService->getSystemHealth();
        $commit = (string) (env('COMMIT_SHA') ?: env('VERCEL_GIT_COMMIT_SHA', ''));
        $branch = (string) env('VERCEL_GIT_COMMIT_REF', '');
        $commit = $commit !== '' ? $commit : $this->gitValue(['rev-parse', 'HEAD']);
        $branch = $branch !== '' ? $branch : $this->gitValue(['branch', '--show-current']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'system' => [
                    'application' => 'operational',
                    'api' => 'operational',
                    'database' => $dbOnline ? 'operational' : 'outage',
                    'authentication' => 'operational',
                    'amenity' => 'operational',
                    'search' => 'operational',
                ],
                'current_release' => [
                    'version' => env('APP_VERSION') ?: ($commit !== 'unknown' ? 'sha-'.substr($commit, 0, 7) : 'unknown'),
                    'branch' => $branch,
                    'commit' => $commit !== 'unknown' ? substr($commit, 0, 7) : 'unknown',
                    'environment' => app()->environment(),
                    'deployed_at' => null,
                ],
                'overview' => $overview,
                'health' => $health,
            ],
        ]);
    }

    /** @param list<string> $arguments */
    private function gitValue(array $arguments): string
    {
        try {
            $result = Process::path(base_path())->timeout(2)->run(['git', ...$arguments]);

            return $result->successful() && trim($result->output()) !== '' ? trim($result->output()) : 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Lấy các chỉ số hiệu năng hệ thống: GET /api/devops/metrics
     */
    public function metrics(): JsonResponse
    {
        $health = $this->cicdService->getSystemHealth();
        $memoryUsed = memory_get_usage(true);
        $dbPingMs = $health['metrics']['db_ping_ms'] ?? 0;

        return response()->json([
            'status' => 'success',
            'data' => [
                // shortcut: utilization needs a platform metrics source; report unknown until one is available.
                'cpu_percent' => null,
                'ram_percent' => null,
                'ram_used_mb' => round($memoryUsed / (1024 * 1024), 1),
                'ram_total_mb' => null,
                'disk_percent' => $health['metrics']['disk_usage_percent'] ?? null,
                'api_performance' => [
                    'p50_latency_ms' => null,
                    'p95_latency_ms' => null,
                    'p99_latency_ms' => null,
                    'error_rate_percent' => null,
                    'requests_per_minute' => null,
                    'db_ping_ms' => $dbPingMs,
                ],
            ],
        ]);
    }

    /**
     * Lấy lịch sử triển khai (deployments) có phân trang: GET /api/devops/deployments
     */
    public function deployments(Request $request): JsonResponse
    {
        $deployments = $this->cicdService->getDeployments();

        return response()->json([
            'status' => 'success',
            'data' => $deployments,
        ]);
    }

    /**
     * Lấy danh sách sự cố nội bộ cho DevOps: GET /api/devops/incidents
     */
    public function incidents(): JsonResponse
    {
        // Incidents cần hệ thống logging/alerting thực tế để ghi nhận.
        // Hiện tại trả mảng rỗng vì chưa có cơ sở dữ liệu incident tracker.
        return response()->json([
            'status' => 'success',
            'data' => [],
        ]);
    }

    /**
     * Public Status API cho người dùng công khai: GET /api/public/status
     * Tuyệt đối không để lộ IP, credentials, internal traces.
     */
    public function publicStatus(): JsonResponse
    {
        return Cache::remember('public_system_status', 10, function () {
            $dbOnline = true;
            try {
                DB::connection()->getPdo();
            } catch (\Throwable $e) {
                $dbOnline = false;
            }

            return response()->json([
                'title' => 'SMART CASSAVAS SYSTEM STATUS',
                'overall_status' => $dbOnline ? 'operational' : 'degraded',
                'uptime_percentage' => 'N/A',
                'services' => [
                    [
                        'name' => 'Website & Giao Diện Người Dùng',
                        'status' => 'operational',
                        'description' => 'Cổng thông tin và trang chủ truy cập ổn định',
                    ],
                    [
                        'name' => 'Cổng API & Kết Nối Dịch Vụ',
                        'status' => 'operational',
                        'description' => 'Dịch vụ REST API hoạt động bình thường',
                    ],
                    [
                        'name' => 'Xác Thực & Phân Quyền (Authentication)',
                        'status' => 'operational',
                        'description' => 'Đăng nhập, bảo mật phiên làm việc và Token ổn định',
                    ],
                    [
                        'name' => 'Quản Lý Tiện Ích & Đặt Chỗ (Amenity)',
                        'status' => 'operational',
                        'description' => 'Hệ thống đặt lịch hồ bơi, phòng gym, BBQ sẵn sàng',
                    ],
                    [
                        'name' => 'Hệ Thống Tìm Kiếm & Cư Dân (Search)',
                        'status' => 'operational',
                        'description' => 'Tra cứu thông tin tòa nhà và căn hộ phản hồi nhanh',
                    ],
                    [
                        'name' => 'Cơ Sở Dữ Liệu Tòa Nhà (Database)',
                        'status' => $dbOnline ? 'operational' : 'outage',
                        'description' => $dbOnline ? 'Đồng bộ dữ liệu an toàn' : 'Đang xử lý kết nối',
                    ],
                ],
                'last_incident' => null,
                'updated_at' => now()->toIso8601String(),
            ]);
        });
    }

    /**
     * Public Incident History cho người dùng: GET /api/public/incidents
     */
    public function publicIncidents(): JsonResponse
    {
        // Incidents cần hệ thống logging thực tế để ghi nhận.
        return response()->json([
            'status' => 'success',
            'data' => [],
        ]);
    }
}
