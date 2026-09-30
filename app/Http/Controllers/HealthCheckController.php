<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthCheckController extends Controller
{
    /**
     * Lấy version và commit SHA của bản phát hành hiện tại.
     */
    protected function getAppVersion(): string
    {
        $version = env('APP_VERSION', '');
        if (! empty($version)) {
            return $version;
        }

        $vercelRef = env('VERCEL_GIT_COMMIT_REF', '');
        if (! empty($vercelRef)) {
            return $vercelRef;
        }

        return $this->getCommitSha();
    }

    protected function getCommitSha(): string
    {
        $sha = env('COMMIT_SHA', env('VERCEL_GIT_COMMIT_SHA', ''));
        if (! empty($sha)) {
            return substr($sha, 0, 7);
        }

        $headFile = base_path('.git/HEAD');
        if (file_exists($headFile)) {
            $headContent = trim(@file_get_contents($headFile) ?: '');
            if (str_starts_with($headContent, 'ref: ')) {
                $refPath = base_path('.git/'.substr($headContent, 5));
                if (file_exists($refPath)) {
                    return substr(trim(@file_get_contents($refPath) ?: ''), 0, 7);
                }
            } else {
                return substr($headContent, 0, 7);
            }
        }

        return 'unknown';
    }

    /**
     * Endpoint chính kiểm tra sức khỏe ứng dụng: GET /health hoặc GET /api/health
     */
    public function health(): JsonResponse
    {
        $databaseStatus = 'healthy';
        $databaseLatencyMs = 0;

        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $databaseLatencyMs = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            $databaseStatus = 'unhealthy';
        }

        $cacheStatus = 'healthy';
        try {
            $cacheKey = 'health_ping_'.uniqid();
            Cache::put($cacheKey, 1, 5);
            if (Cache::get($cacheKey) !== 1) {
                $cacheStatus = 'degraded';
            }
            Cache::forget($cacheKey);
        } catch (\Throwable $e) {
            $cacheStatus = 'unhealthy';
        }

        // Ứng dụng healthy khi web server và PHP runtime hoạt động bình thường
        $uptime = defined('LARAVEL_START') ? round(microtime(true) - LARAVEL_START, 2) : 0.0;

        $overallStatus = ($databaseStatus === 'healthy' && $cacheStatus !== 'unhealthy') ? 'healthy' : 'degraded';
        if ($databaseStatus === 'unhealthy') {
            $overallStatus = 'unhealthy';
        }

        $httpStatus = ($overallStatus === 'unhealthy') ? 503 : 200;

        // Ghi nhận thời điểm probe quan sát sức khỏe ứng dụng (Health Probe Observation)
        // Để FreshnessService chỉ đọc chứ không tự tạo timestamp
        if ($overallStatus === 'healthy') {
            Cache::put('application_health_last_observed_at', now('Asia/Ho_Chi_Minh')->toIso8601String(), 86400);
            Cache::put('application_health_last_status', 'healthy', 86400);
            Cache::put('application_health_last_details', [
                'database' => $databaseStatus,
                'cache' => $cacheStatus,
                'database_latency_ms' => $databaseLatencyMs,
                'uptime_seconds' => $uptime,
            ], 86400);
            Cache::forget('application_health_failure_reason');
        } else {
            Cache::put('application_health_last_status', $overallStatus, 86400);
            Cache::put('application_health_failure_reason', "Database: {$databaseStatus}, Cache: {$cacheStatus}", 86400);
            // Quan trọng: Không cập nhật application_health_last_observed_at khi probe thất bại
        }

        $data = [
            'status' => $overallStatus,
            'system' => $overallStatus,
            'database' => $databaseStatus,
            'cache' => $cacheStatus,
            'version' => $this->getAppVersion(),
            'commit_sha' => $this->getCommitSha(),
            'build_time' => env('BUILD_TIME', null),
            'timestamp' => now()->toIso8601String(),
            'uptime_seconds' => $uptime,
            'database_latency_ms' => $databaseLatencyMs,
        ];

        if (env('VERCEL_REGION')) {
            $data['region'] = env('VERCEL_REGION');
        }
        if (env('VERCEL_ENV')) {
            $data['vercel_env'] = env('VERCEL_ENV');
        }

        return response()->json($data, $httpStatus);
    }

    /**
     * Endpoint chuyên sâu kiểm tra CSDL: GET /api/db-health
     */
    public function dbHealth(): JsonResponse
    {
        try {
            $start = microtime(true);
            $result = DB::select('SELECT 1 AS ping');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            if (! empty($result) && $result[0]->ping == 1) {
                return response()->json([
                    'status' => 'healthy',
                    'connection' => 'established',
                    'latency_ms' => $latencyMs,
                    'timestamp' => now()->toIso8601String(),
                ], 200);
            }

            return response()->json([
                'status' => 'unhealthy',
                'connection' => 'unexpected_result',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'unhealthy',
                'connection' => 'failed',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }
}
