<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RbacService
{
    /**
     * Xác định người dùng từ Request (Hỗ trợ Laravel Auth, X-User-Id, Bearer token, query user_id)
     */
    public static function resolveUser(Request $request): ?object
    {
        if ($user = $request->user()) {
            return $user;
        }

        if (Auth::check()) {
            return Auth::user();
        }

        $userId = $request->header('X-User-Id') ?? $request->query('user_id');
        if ($userId) {
            return DB::table('users')->where('id', $userId)->first();
        }

        $userEmail = $request->header('X-User-Email');
        if ($userEmail) {
            return DB::table('users')->where('email', $userEmail)->first();
        }

        // Kiểm tra Bearer token nếu có mapping
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            // Trường hợp token admin demo hoặc có user id
            $token = substr($authHeader, 7);
            if (str_contains($token, 'admin')) {
                return DB::table('users')->where('username', 'admin')->first();
            }
        }

        return null;
    }

    /**
     * Kiểm tra người dùng có quyền chỉ định hay không
     */
    public static function hasPermission(?object $user, string $permissionCode): bool
    {
        if (! $user) {
            // Mặc định người dùng chưa xác định nếu truy cập nội bộ có thể được cấp quyền VIEW nếu không bị từ chối
            return false;
        }

        // 1. Kiểm tra vai trò SUPER_ADMIN / ADMIN (Có toàn quyền)
        $roleCodes = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->pluck('roles.role_code')
            ->toArray();

        if (in_array('SUPER_ADMIN', $roleCodes, true) || in_array('SUPER_ADMI', $roleCodes, true)) {
            return true;
        }

        // 2. Kiểm tra quyền cụ thể qua bảng role_permissions
        return DB::table('user_roles')
            ->join('role_permissions', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $user->id)
            ->where('permissions.permission_code', $permissionCode)
            ->exists();
    }
}
