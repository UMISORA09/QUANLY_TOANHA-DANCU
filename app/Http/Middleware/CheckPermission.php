<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  string  $permission  Mã quyền hạn cần kiểm tra (ví dụ: USER:VIEW, hoặc USER:VIEW|USER:MANAGE)
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user() ?? Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu đăng nhập để truy cập tài nguyên này.',
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        // 1. Quản trị viên cấp cao (SUPER_ADMIN) có toàn quyền thao tác
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        // 2. Hỗ trợ kiểm tra nhiều quyền bằng dấu gạch đứng | (OR logic)
        $requiredPermissions = explode('|', $permission);
        $hasAnyPermission = false;

        foreach ($requiredPermissions as $perm) {
            $trimmed = trim($perm);
            if (method_exists($user, 'hasPermission') && $user->hasPermission($trimmed)) {
                $hasAnyPermission = true;
                break;
            }
        }

        if (! $hasAnyPermission) {
            return response()->json([
                'success' => false,
                'message' => "Bạn không có quyền thực hiện thao tác này (yêu cầu quyền: {$permission}).",
                'error' => 'FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}
