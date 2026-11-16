<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MeasurePerformanceMiddleware
{
    /**
     * Đo lường thời gian thực thi (Latency) và gắn các diagnostic headers vào HTTP Response.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        $response = $next($request);

        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        $response->headers->set('X-Response-Time', "{$executionTimeMs}ms");

        // Gắn header trạng thái cache nếu có thông tin từ Service layer
        if ($request->attributes->has('x_cache_status')) {
            $response->headers->set('X-Cache', (string) $request->attributes->get('x_cache_status'));
        }

        return $response;
    }
}
