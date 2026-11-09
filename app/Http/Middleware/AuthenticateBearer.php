<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\Carbon;
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

        // 1. Nếu không có Bearer token nhưng đã được xác thực qua actingAs trong test
        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            $existingUser = $request->user() ?: (Auth::check() ? Auth::user() : null);
            if ($existingUser) {
                if ($existingUser->status !== 'ACTIVE') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tài khoản người dùng đã bị khóa hoặc tạm ngưng hoạt động.',
                        'error' => 'ACCOUNT_LOCKED',
                    ], 403);
                }

                return $next($request);
            }

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

        $tokenHash = hash('sha256', $token);
        $user = null;

        // 2. Tra cứu phiên đăng nhập trực tiếp từ bảng user_sessions
        $session = DB::table('user_sessions')
            ->where(function ($q) use ($token, $tokenHash) {
                $q->where('refresh_token_hash', $tokenHash)
                    ->orWhere('refresh_token_hash', $token);
            })
            ->first();

        if ($session) {
            // Kiểm tra trạng thái thu hồi token
            if ($session->is_revoked) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phiên đăng nhập đã bị thu hồi.',
                    'error' => 'TOKEN_REVOKED',
                ], 401);
            }

            // Kiểm tra thời hạn hiệu lực của phiên
            if ($session->expires_at && Carbon::parse($session->expires_at)->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phiên đăng nhập đã hết hạn.',
                    'error' => 'TOKEN_EXPIRED',
                ], 401);
            }

            $user = User::with('roles.permissions')->find($session->user_id);
        } elseif (app()->environment('testing', 'local')) {
            // 3. Cơ chế token demo chỉ cho phép chạy trong môi trường testing/local
            if ($token === 'smart_token_admin_demo') {
                $user = User::with('roles.permissions')
                    ->whereHas('roles', fn ($q) => $q->where('role_code', 'SUPER_ADMIN'))
                    ->where('status', 'ACTIVE')
                    ->first();
            } elseif ($token === 'smart_token_manager_demo') {
                $user = User::with('roles.permissions')
                    ->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['BUILDING_MANAGER', 'SUPER_ADMIN']))
                    ->where('status', 'ACTIVE')
                    ->first();
            } elseif ($token === 'smart_token_reception_demo') {
                $user = User::with('roles.permissions')
                    ->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['RECEPTIONIST', 'SECURITY_GUARD', 'SUPER_ADMIN', 'BUILDING_MANAGER']))
                    ->where('status', 'ACTIVE')
                    ->first();
            } elseif ($token === 'smart_token_resident_demo') {
                $user = User::with('roles.permissions')
                    ->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['RESIDENT_OWNER', 'RESIDENT_MEMBER']))
                    ->where('status', 'ACTIVE')
                    ->first() ?? User::with('roles.permissions')->where('email', 'nguyenvanan@cassavas.vn')->first();
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

    public static function resolveSessionUser(string $token): ?User
    {
        $tokenHash = hash('sha256', $token);
        $session = DB::table('user_sessions')
            ->where(function ($q) use ($token, $tokenHash) {
                $q->where('refresh_token_hash', $tokenHash)
                    ->orWhere('refresh_token_hash', $token);
            })
            ->where('is_revoked', 0)
            ->where('expires_at', '>', now())
            ->first();

        if ($session) {
            return User::with('roles.permissions')->find($session->user_id);
        }

        if (app()->environment('testing', 'local')) {
            if ($token === 'smart_token_admin_demo') {
                return User::with('roles.permissions')
                    ->whereHas('roles', fn ($q) => $q->where('role_code', 'SUPER_ADMIN'))
                    ->where('status', 'ACTIVE')
                    ->first();
            } elseif ($token === 'smart_token_manager_demo') {
                return User::with('roles.permissions')
                    ->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['BUILDING_MANAGER', 'SUPER_ADMIN']))
                    ->where('status', 'ACTIVE')
                    ->first();
            } elseif ($token === 'smart_token_reception_demo') {
                return User::with('roles.permissions')
                    ->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['RECEPTIONIST', 'SECURITY_GUARD', 'SUPER_ADMIN', 'BUILDING_MANAGER']))
                    ->where('status', 'ACTIVE')
                    ->first();
            } elseif ($token === 'smart_token_resident_demo') {
                return User::with('roles.permissions')
                    ->whereHas('roles', fn ($q) => $q->whereIn('role_code', ['RESIDENT_OWNER', 'RESIDENT_MEMBER']))
                    ->where('status', 'ACTIVE')
                    ->first() ?? User::with('roles.permissions')->where('email', 'nguyenvanan@cassavas.vn')->first();
            }
        }

        return null;
    }
}
