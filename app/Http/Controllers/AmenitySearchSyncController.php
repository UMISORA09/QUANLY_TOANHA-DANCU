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

        $exitCode = 0;
        if (DB::table('jobs')->where('queue', 'amenity-search')->exists()) {
            $exitCode = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'amenity-search', '--once' => true, '--tries' => 5, '--timeout' => 25]);
        }

        return response()->json(['pending' => DB::table('jobs')->where('queue', 'amenity-search')->count()], $exitCode === 0 ? 200 : 503);
    }
}
