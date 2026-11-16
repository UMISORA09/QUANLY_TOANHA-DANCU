<?php

namespace App\Http\Middleware;

use App\Services\QuocTinRealtimeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleCooldown
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        // 1. Read-only requests (GET, HEAD, OPTIONS) are NEVER blocked by cooldown
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // 2. Check server-side shared cooldown for the specified module
        $cooldown = QuocTinRealtimeService::getModuleCooldown($module);

        if ($cooldown !== null) {
            $retryAfter = (int) ($cooldown['retry_after'] ?? 1);

            return response()->json([
                'success' => false,
                'error' => 'COOLDOWN_ACTIVE',
                'message' => 'Chức năng đang tạm khóa chỉnh sửa. Vui lòng thử lại sau.',
                'module' => QuocTinRealtimeService::normalizeModuleName($module),
                'cooldown_until' => $cooldown['cooldown_until'] ?? null,
                'retry_after' => $retryAfter,
                'cooldown_seconds' => (int) ($cooldown['cooldown_seconds'] ?? 120),
                'actor_id' => $cooldown['actor_id'] ?? null,
            ], 409);
        }

        return $next($request);
    }
}
