<?php

namespace App\Console\Commands;

use App\Models\FreshnessHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class HealthProbeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'health:probe {--url= : URL của service cần probe (mặc định lấy từ PRODUCTION_URL hoặc HEALTH_PROBE_URL)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Thực hiện probe HTTP thực tế tới endpoint /health và ghi nhận kết quả quan sát';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = $this->option('url')
            ?: env('HEALTH_PROBE_URL')
            ?: env('PRODUCTION_URL')
            ?: config('freshness.health_probe_url');

        if (empty($url)) {
            $this->warn('Chưa cấu hình URL để probe (PRODUCTION_URL hoặc HEALTH_PROBE_URL). Trạng thái giữ nguyên UNKNOWN.');

            return self::INVALID;
        }

        $endpoint = rtrim($url, '/').'/health';
        $this->info("Đang thực hiện probe: {$endpoint} ...");

        $now = Carbon::now('Asia/Ho_Chi_Minh');

        try {
            $start = microtime(true);
            $response = Http::timeout(10)
                ->acceptJson()
                ->get($endpoint);
            $durationMs = round((microtime(true) - $start) * 1000, 2);

            $httpCode = $response->status();
            $data = $response->json();
            $status = is_array($data) ? ($data['status'] ?? 'unknown') : 'unknown';

            if ($httpCode === 200 && in_array(strtolower($status), ['healthy', 'ok'], true)) {
                $details = "Probe HTTP 200 OK ({$durationMs}ms) tới {$endpoint}";
                $metadata = [
                    'probe_url' => $endpoint,
                    'http_status' => $httpCode,
                    'latency_ms' => $durationMs,
                    'response' => $data,
                ];

                // Ghi nhận thành công vào DB và Cache
                try {
                    FreshnessHeartbeat::updateOrCreate(
                        ['channel' => 'application_health'],
                        [
                            'last_success_at' => $now,
                            'status' => 'healthy',
                            'details' => $details,
                            'metadata' => $metadata,
                        ]
                    );
                } catch (Throwable $e) {
                    $this->warn('Không thể lưu heartbeat vào DB: '.$e->getMessage());
                }

                Cache::put('application_health_last_observed_at', $now->toIso8601String(), 86400);
                Cache::put('application_health_last_status', 'healthy', 86400);
                Cache::put('application_health_last_details', $metadata, 86400);
                Cache::forget('application_health_failure_reason');

                $this->info("✅ Health probe thành công: {$details}");

                return self::SUCCESS;
            }

            // Probe phản hồi nhưng HTTP code != 200 hoặc status != healthy
            $failReason = "Probe phản hồi HTTP {$httpCode}, status '{$status}' tại {$endpoint}";
            $this->recordFailure($failReason, $now, [
                'probe_url' => $endpoint,
                'http_status' => $httpCode,
                'latency_ms' => $durationMs,
                'response' => $data,
            ]);

            $this->error("❌ Health probe thất bại: {$failReason}");

            return self::FAILURE;
        } catch (Throwable $e) {
            // Không kết nối được tới endpoint
            $failReason = "Không thể kết nối tới {$endpoint}: ".$e->getMessage();
            $this->recordFailure($failReason, $now, [
                'probe_url' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            $this->error("❌ Health probe lỗi mạng: {$failReason}");

            return self::FAILURE;
        }
    }

    /**
     * Ghi nhận probe thất bại vào DB và Cache (không cập nhật last_success_at).
     */
    protected function recordFailure(string $reason, Carbon $time, array $metadata): void
    {
        try {
            FreshnessHeartbeat::updateOrCreate(
                ['channel' => 'application_health'],
                [
                    'status' => 'unhealthy',
                    'details' => $reason,
                    'metadata' => $metadata,
                ]
            );
        } catch (Throwable) {
            // Ignore DB error
        }

        Cache::put('application_health_last_status', 'unhealthy', 86400);
        Cache::put('application_health_failure_reason', $reason, 86400);
        Cache::put('application_health_last_failed_at', $time->toIso8601String(), 86400);
    }
}
