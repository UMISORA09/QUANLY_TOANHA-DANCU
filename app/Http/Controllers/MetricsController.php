<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MetricsController extends Controller
{
    /**
     * Xuất dữ liệu metric theo chuẩn Prometheus: GET /metrics
     */
    public function metrics(): Response
    {
        $dbStatus = 0;
        $dbLatencySeconds = 0;
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $dbLatencySeconds = microtime(true) - $start;
            $dbStatus = 1;
        } catch (\Throwable $e) {
            $dbStatus = 0;
        }

        $memoryBytes = memory_get_usage(true);
        $peakMemoryBytes = memory_get_peak_usage(true);
        $uptimeSeconds = microtime(true) - LARAVEL_START;

        $lines = [];
        $lines[] = '# HELP smart_cassavas_app_uptime_seconds Thời gian hoạt động của ứng dụng (giây)';
        $lines[] = '# TYPE smart_cassavas_app_uptime_seconds gauge';
        $lines[] = 'smart_cassavas_app_uptime_seconds '.sprintf('%.2f', $uptimeSeconds);

        $lines[] = '# HELP smart_cassavas_memory_bytes Bộ nhớ RAM tiến trình PHP đang sử dụng';
        $lines[] = '# TYPE smart_cassavas_memory_bytes gauge';
        $lines[] = 'smart_cassavas_memory_bytes '.$memoryBytes;

        $lines[] = '# HELP smart_cassavas_memory_peak_bytes Bộ nhớ RAM đỉnh của tiến trình';
        $lines[] = '# TYPE smart_cassavas_memory_peak_bytes gauge';
        $lines[] = 'smart_cassavas_memory_peak_bytes '.$peakMemoryBytes;

        $lines[] = '# HELP smart_cassavas_database_status Trạng thái kết nối CSDL MySQL (1 = Online, 0 = Offline)';
        $lines[] = '# TYPE smart_cassavas_database_status gauge';
        $lines[] = 'smart_cassavas_database_status '.$dbStatus;

        $lines[] = '# HELP smart_cassavas_database_query_duration_seconds Độ trễ phản hồi của CSDL (giây)';
        $lines[] = '# TYPE smart_cassavas_database_query_duration_seconds gauge';
        $lines[] = 'smart_cassavas_database_query_duration_seconds '.sprintf('%.6f', $dbLatencySeconds);

        $payload = implode("\n", $lines)."\n";

        return response($payload, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
