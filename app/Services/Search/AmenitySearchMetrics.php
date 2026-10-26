<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AmenitySearchMetrics
{
    /** @param array<string, mixed> $result */
    public function record(string $kind, array $result, float $duration): void
    {
        try {
            if (! Schema::hasTable('search_request_metrics')) {
                return;
            }
            DB::table('search_request_metrics')->insert([
                'request_kind' => $kind, 'engine' => $result['engine'] ?? 'smart',
                'fallback_reason' => $result['fallback_reason'] ?? null,
                'result_count' => (int) ($result['total'] ?? 0), 'duration_ms' => max(0, $duration),
                'algolia_calls' => (int) ($result['algolia_calls'] ?? 0), 'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Optional telemetry must not prevent an otherwise successful search.
        }
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $metrics = DB::table('search_request_metrics')->where('created_at', '>=', now()->subDays(7));
        $ranked = (clone $metrics)->selectRaw('duration_ms, ROW_NUMBER() OVER (ORDER BY duration_ms) AS row_position, COUNT(*) OVER () AS sample_count');
        $latency = DB::query()->fromSub($ranked, 'ranked')->selectRaw(
            'MIN(CASE WHEN row_position >= CEIL(sample_count * 0.50) THEN duration_ms END) AS p50_ms, MIN(CASE WHEN row_position >= CEIL(sample_count * 0.95) THEN duration_ms END) AS p95_ms'
        )->first();
        $totals = (clone $metrics)->selectRaw('COUNT(*) AS requests, COALESCE(SUM(algolia_calls), 0) AS algolia_calls, COALESCE(SUM(result_count = 0), 0) AS zero_results, COALESCE(SUM(fallback_reason IS NOT NULL), 0) AS fallbacks')->first();

        return [
            'days' => 7, 'requests' => (int) $totals->requests, 'estimated_algolia_calls' => (int) $totals->algolia_calls,
            'zero_result_rate' => $totals->requests ? round($totals->zero_results / $totals->requests, 4) : 0,
            'fallback_rate' => $totals->requests ? round($totals->fallbacks / $totals->requests, 4) : 0,
            'p50_ms' => $latency->p50_ms === null ? null : round((float) $latency->p50_ms, 2),
            'p95_ms' => $latency->p95_ms === null ? null : round((float) $latency->p95_ms, 2),
            'engines' => (clone $metrics)->select('engine')->selectRaw('COUNT(*) AS requests')->groupBy('engine')->get(),
            'fallback_reasons' => (clone $metrics)->whereNotNull('fallback_reason')->select('fallback_reason')->selectRaw('COUNT(*) AS requests')->groupBy('fallback_reason')->get(),
            'dirty_documents' => DB::table('amenity_search_states')->whereColumn('published_version', '<', 'desired_version')->count(),
            'failed_documents' => DB::table('amenity_search_states')->where('status', 'failed')->count(),
            'last_synced_at' => DB::table('amenity_search_states')->max('synced_at'),
        ];
    }
}
