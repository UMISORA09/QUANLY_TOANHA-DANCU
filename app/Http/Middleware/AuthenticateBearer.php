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
    public function handle(Request $request, Closure $next, string $mode = 'legacy'): Response
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

        $user = self::resolveSessionUser($token);

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

    public static function resolveSessionUser(string $token): ?User
    {
        $session = DB::table('user_sessions')
            ->where('refresh_token_hash', hash('sha256', $token))
            ->where('is_revoked', 0)
            ->where('expires_at', '>', now())
            ->first();

        return $session ? User::with('roles.permissions')->find($session->user_id) : null;
    }
}
