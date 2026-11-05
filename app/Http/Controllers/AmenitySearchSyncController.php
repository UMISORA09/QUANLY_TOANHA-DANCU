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
    private const SEARCH_COMMANDS = [
        'amenity-search' => 'search:sync-amenities',
        'booking-code-search' => 'search:sync-booking-codes',
        'ticket-search' => 'search:sync-tickets',
        'announcement-search' => 'search:sync-announcements',
        'suggestion-search' => 'search:sync-suggestions',
        'survey-search' => 'search:sync-surveys',
    ];

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
        $all = $request->query('scope') === 'all';
        $commands = $all ? self::SEARCH_COMMANDS : ['amenity-search' => self::SEARCH_COMMANDS['amenity-search']];
        $queues = array_keys($commands);

        if ($request->isMethod('get') && $request->query('inspect') === 'schema') {
            return response()->json([
                'database_provider' => $this->databaseProvider(),
                'tables' => collect(['amenity_bookings', 'amenity_booking_payments', 'amenities', 'jobs', 'failed_jobs', ...($all ? ['amenity_search_states', 'tickets', 'announcements', 'suggestions', 'satisfaction_surveys'] : [])])
                    ->mapWithKeys(fn (string $table): array => [$table => Schema::hasTable($table)])->all(),
            ]);
        }

        if ($request->isMethod('post')) {
            $retryOnly = $request->boolean('retry_failed_only');
            abort_if(! $retryOnly && DB::table('jobs')->whereIn('queue', $queues)->exists(), 409, 'Synchronization is already queued.');
            DB::transaction(function () use ($commands, $queues, $retryOnly): void {
                DB::table('amenities')->orderBy('id')->lockForUpdate()->first();
                abort_if(! $retryOnly && DB::table('jobs')->whereIn('queue', $queues)->exists(), 409, 'Synchronization is already queued.');
                $failedIds = DB::table('failed_jobs')->where('connection', 'database')->whereIn('queue', $queues)->pluck('uuid')->all();
                if ($failedIds !== []) {
                    abort_unless(Artisan::call('queue:retry', ['id' => $failedIds]) === 0, 503);
                }
                if ($retryOnly) {
                    return;
                }
                foreach ($commands as $command) {
                    abort_unless(Artisan::call($command, $command === 'search:sync-amenities' ? [] : ['--queue-only' => true]) === 0, 503);
                }
            });

            return response()->json(['pending' => DB::table('jobs')->whereIn('queue', $queues)->count()], 202);
        }

        $exitCode = 0;
        if (DB::table('jobs')->whereIn('queue', $queues)->exists()) {
            $exitCode = Artisan::call('queue:work', ['connection' => 'database', '--queue' => implode(',', $queues), '--once' => true, '--tries' => 5, '--timeout' => 45]);
        }

        $failures = DB::table('failed_jobs')->whereIn('queue', $queues)->count();

        return response()->json([
            'pending' => DB::table('jobs')->whereIn('queue', $queues)->count(),
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
