<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;

class AmenitySearchSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('search.sync_secret', '');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $request->bearerToken()), 401);
        abort_unless(config('search.default') === 'elasticsearch', 503);

        try {
            return $this->synchronize($request)->header('Cache-Control', 'private, no-store');
        } catch (PDOException $exception) {
            $errorCode = match ((int) ($exception->errorInfo[1] ?? $exception->getCode())) {
                2006, 2013 => 'connection_lost',
                2002, 2003 => 'connection_unavailable',
                1045 => 'authentication_failed',
                1049 => 'database_missing',
                1146 => 'schema_missing',
                2026 => 'tls_failed',
                default => 'database_operation_failed',
            };

            return response()->json(['database_provider' => $this->databaseProvider(), 'database' => 'unhealthy', 'error_code' => $errorCode], 503)
                ->header('Cache-Control', 'private, no-store');
        }
    }

    private function synchronize(Request $request): JsonResponse
    {
        if ($request->isMethod('get') && $request->query('inspect') === 'schema') {
            return response()->json([
                'database_provider' => $this->databaseProvider(),
                'tables' => collect(['amenity_bookings', 'amenity_booking_payments', 'amenities', 'jobs', 'failed_jobs'])
                    ->mapWithKeys(fn (string $table): array => [$table => Schema::hasTable($table)])->all(),
            ]);
        }

        if ($request->isMethod('post')) {
            abort_if(DB::table('jobs')->where('queue', 'amenity-search')->exists(), 409, 'Synchronization is already queued.');
            DB::transaction(function (): void {
                DB::table('amenities')->orderBy('id')->lockForUpdate()->first();
                abort_if(DB::table('jobs')->where('queue', 'amenity-search')->exists(), 409, 'Synchronization is already queued.');
                $failedIds = DB::table('failed_jobs')->where('connection', 'database')->where('queue', 'amenity-search')->pluck('uuid')->all();
                if ($failedIds !== []) {
                    abort_unless(Artisan::call('queue:retry', ['id' => $failedIds]) === 0, 503);
                }
                abort_unless(Artisan::call('search:sync-amenities') === 0, 503);
            });

            return response()->json(['pending' => DB::table('jobs')->where('queue', 'amenity-search')->count()], 202);
        }

        $exitCode = 0;
        if (DB::table('jobs')->where('queue', 'amenity-search')->exists()) {
            $exitCode = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'amenity-search', '--once' => true, '--tries' => 5, '--timeout' => 45]);
        }

        $failures = DB::table('failed_jobs')->where('queue', 'amenity-search')->count();

        return response()->json([
            'pending' => DB::table('jobs')->where('queue', 'amenity-search')->count(),
            'failed' => $failures,
            'failure_codes' => $failures > 0 ? ['worker_failed' => $failures] : [],
        ], $exitCode === 0 ? 200 : 503);
    }

    private function databaseProvider(): string
    {
        $host = (string) DB::connection()->getConfig('host');

        return collect(['aiven', 'tidbcloud', 'railway', 'amazonaws'])->first(fn (string $provider): bool => str_contains($host, $provider), 'unknown');
    }
}
