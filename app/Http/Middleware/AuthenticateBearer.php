<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBearer
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu xác thực Bearer token.',
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        $token = trim(substr($authHeader, 7));

        if ($token === '') {
            return response()->json([
                'success' => false,
                'message' => 'Token xác thực không hợp lệ.',
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        // 1. Kiểm tra session trong bảng user_sessions
        $tokenHash = hash('sha256', $token);

        $session = DB::table('user_sessions')
            ->where(function ($q) use ($token, $tokenHash) {
                $q->where('refresh_token_hash', $tokenHash)
                    ->orWhere('refresh_token_hash', $token);
            })
            ->where('is_revoked', 0)
            ->where('expires_at', '>', now())
            ->first();

        $user = null;

        if ($session) {
            $user = User::with('roles.permissions')->find($session->user_id);
        } else {
            // 2. Fallback kiểm tra smart_token format (phục vụ tương thích ngược nếu chưa lưu session vào DB)
            // Cấu trúc token: smart_token_{uuid}_{random} hoặc kiểm tra token demo
            if (str_starts_with($token, 'smart_token_')) {
                // Kiểm tra xem có user_id gắn trong token không
                $parts = explode('_', $token);
                if (isset($parts[2]) && strlen($parts[2]) === 36) {
                    $user = User::with('roles.permissions')->find($parts[2]);
                }
            }
        }

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Phiên đăng nhập đã hết hạn hoặc không tồn tại.',
                'error' => 'UNAUTHORIZED',
            ], 401);
        }

        // Kiểm tra trạng thái tài khoản
        if ($user->status !== 'ACTIVE') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản người dùng đã bị khóa hoặc tạm ngưng hoạt động.',
                'error' => 'ACCOUNT_LOCKED',
            ], 403);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
