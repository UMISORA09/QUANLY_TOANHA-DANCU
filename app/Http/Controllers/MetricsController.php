<?php

namespace App\Http\Controllers;

use App\Services\Freshness\FreshnessService;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class MetricsController extends Controller
{
    public function __construct(
        protected FreshnessService $freshnessService
    ) {}

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
        } catch (Throwable $e) {
            $dbStatus = 0;
        }

        $memoryBytes = memory_get_usage(true);
        $peakMemoryBytes = memory_get_peak_usage(true);
        $uptimeSeconds = defined('LARAVEL_START') ? (microtime(true) - LARAVEL_START) : 0.0;

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

        // =========================================================================
        // DATA FRESHNESS & MONITORING OBSERVABILITY METRICS
        // =========================================================================
        try {
            $freshness = $this->freshnessService->getOverview();

            // 1. Collector metrics (Monitoring of Monitoring)
            $collectorAge = $freshness['collector']['age_seconds'] ?? 0;
            $collectorErrors = $freshness['collector']['errors_count'] ?? 0;
            $collectorLastSuccessTimestamp = ! empty($freshness['collector']['last_success_at'])
                ? Carbon::parse($freshness['collector']['last_success_at'])->getTimestamp()
                : 0;

            $lines[] = '# HELP collector_data_age_seconds Thoi gian troi qua tu lan chay collector thanh cong gan nhat (giay)';
            $lines[] = '# TYPE collector_data_age_seconds gauge';
            $lines[] = 'collector_data_age_seconds '.$collectorAge;

            $lines[] = '# HELP collector_last_success_timestamp Unix timestamp lan chay collector thanh cong gan nhat';
            $lines[] = '# TYPE collector_last_success_timestamp gauge';
            $lines[] = 'collector_last_success_timestamp '.$collectorLastSuccessTimestamp;

            $lines[] = '# HELP collector_errors_total Tong so loi phat sinh trong qua trinh thu thap freshness';
            $lines[] = '# TYPE collector_errors_total counter';
            $lines[] = 'collector_errors_total '.$collectorErrors;

            // 2. Freshness Age Seconds & Status per source
            $lines[] = '# HELP freshness_age_seconds Do tuoi cua nguon du lieu hoac quan sat monitoring (giay)';
            $lines[] = '# TYPE freshness_age_seconds gauge';

            $lines[] = '# HELP freshness_source_timestamp_seconds Unix timestamp thuc te cua nguon du lieu';
            $lines[] = '# TYPE freshness_source_timestamp_seconds gauge';

            $lines[] = '# HELP freshness_status_binary Trang thai danh gia freshness theo tung state (1 neu dung, 0 neu khong)';
            $lines[] = '# TYPE freshness_status_binary gauge';

            $sourcesToExport = [
                [
                    'source' => 'collector',
                    'type' => 'monitoring',
                    'age' => $freshness['collector']['age_seconds'] ?? null,
                    'timestamp' => $collectorLastSuccessTimestamp,
                    'status' => $freshness['collector']['status'] ?? 'UNKNOWN',
                ],
                [
                    'source' => 'github_actions',
                    'type' => 'monitoring',
                    'age' => $freshness['github_actions']['age_seconds'] ?? null,
                    'timestamp' => ! empty($freshness['github_actions']['last_event_at'])
                        ? Carbon::parse($freshness['github_actions']['last_event_at'])->getTimestamp()
                        : 0,
                    'status' => $freshness['github_actions']['status'] ?? 'UNKNOWN',
                ],
                [
                    'source' => 'deployment',
                    'type' => 'monitoring',
                    'age' => $freshness['deployment']['age_seconds'] ?? null,
                    'timestamp' => ! empty($freshness['deployment']['last_event_at'])
                        ? Carbon::parse($freshness['deployment']['last_event_at'])->getTimestamp()
                        : 0,
                    'status' => $freshness['deployment']['status'] ?? 'UNKNOWN',
                ],
                [
                    'source' => 'application_health',
                    'type' => 'monitoring',
                    'age' => $freshness['application_health']['age_seconds'] ?? null,
                    'timestamp' => ! empty($freshness['application_health']['last_observed_at'])
                        ? Carbon::parse($freshness['application_health']['last_observed_at'])->getTimestamp()
                        : 0,
                    'status' => $freshness['application_health']['status'] ?? 'UNKNOWN',
                ],
            ];

            // Database Business Data Sources
            if (! empty($freshness['database']['sources'])) {
                foreach ($freshness['database']['sources'] as $dbSrc) {
                    $ts = ! empty($dbSrc['last_update_at']) ? Carbon::parse($dbSrc['last_update_at'])->getTimestamp() : 0;
                    $sourcesToExport[] = [
                        'source' => $dbSrc['source'] ?? $dbSrc['table'],
                        'type' => 'data',
                        'age' => $dbSrc['age_seconds'] ?? null,
                        'timestamp' => $ts,
                        'status' => $dbSrc['status'] ?? 'UNKNOWN',
                    ];
                }
            }

            $possibleStates = ['fresh', 'stale', 'critical', 'unknown', 'unavailable'];

            foreach ($sourcesToExport as $item) {
                $src = $item['source'];
                $type = $item['type'];
                $age = $item['age'] !== null ? (int) $item['age'] : -1;
                $ts = (int) $item['timestamp'];
                $itemState = strtolower($item['status']);

                $lines[] = sprintf('freshness_age_seconds{source="%s",type="%s"} %d', $src, $type, $age);
                $lines[] = sprintf('freshness_source_timestamp_seconds{source="%s",type="%s"} %d', $src, $type, $ts);

                foreach ($possibleStates as $state) {
                    $isMatch = ($state === $itemState) ? 1 : 0;
                    $lines[] = sprintf('freshness_status_binary{source="%s",type="%s",state="%s"} %d', $src, $type, $state, $isMatch);
                }
            }
        } catch (Throwable $e) {
            $lines[] = '# Freshness metrics export error: '.str_replace("\n", ' ', $e->getMessage());
        }

        $payload = implode("\n", $lines)."\n";

        return response($payload, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
