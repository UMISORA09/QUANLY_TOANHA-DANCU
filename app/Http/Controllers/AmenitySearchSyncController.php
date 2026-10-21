<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class AmenitySearchSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('search.sync_secret', '');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $request->bearerToken()), 401);
        abort_unless(config('search.drivers.algolia.sync_enabled'), 503);

        if ($request->isMethod('post')) {
            abort_if(DB::table('jobs')->where('queue', 'amenity-search')->exists(), 409, 'Synchronization is already queued.');
            DB::transaction(function (): void {
                DB::table('amenities')->orderBy('id')->lockForUpdate()->first();
                abort_if(DB::table('jobs')->where('queue', 'amenity-search')->exists(), 409, 'Synchronization is already queued.');
                abort_unless(Artisan::call('search:sync-amenities') === 0, 503);
            });

            return response()->json(['pending' => DB::table('jobs')->where('queue', 'amenity-search')->count()], 202);
        }

        $exitCode = 0;
        if (DB::table('jobs')->where('queue', 'amenity-search')->exists()) {
            $exitCode = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'amenity-search', '--once' => true, '--tries' => 5, '--timeout' => 25]);
        }

        return response()->json(['pending' => DB::table('jobs')->where('queue', 'amenity-search')->count(), 'failed' => DB::table('failed_jobs')->where('queue', 'amenity-search')->count()], $exitCode === 0 ? 200 : 503);
    }
}
