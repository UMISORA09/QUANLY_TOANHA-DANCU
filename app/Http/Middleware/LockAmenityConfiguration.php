<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LockAmenityConfiguration
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $amenityId = $request->route('amenityId') ?? $request->route('id');

        if ($request->isMethod('GET') || ! $amenityId || ! $request->is('api/v1/admin/amenities/*')) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next, $amenityId): Response {
            abort_unless(DB::table('amenities')->where('id', $amenityId)->whereNull('deleted_at')->lockForUpdate()->first(), 404);

            return $next($request);
        }, 3);
    }
}
