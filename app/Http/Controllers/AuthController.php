<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Xử lý đăng nhập từ Form / API
     */
    public function login(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        if (empty($body)) {
            $rawContent = $request->getContent();
            $body = json_decode($rawContent, true) ?? $request->all();
        }

        $identifier = trim((string) ($body['username'] ?? $body['email'] ?? $body['phone_number'] ?? $request->input('username') ?? $request->input('email') ?? ''));
        $password = (string) ($body['password'] ?? $request->input('password') ?? '');

        if ($identifier === '' || $password === '') {
            return response()->json([
                'detail' => 'Vui lòng nhập tên đăng nhập / email / số điện thoại và mật khẩu.',
            ], 422);
        }

        // Bản đồ alias cho các tài khoản demo nhanh
        $aliasMap = [
            'admin@smartcassavas.vn' => 'admin@cassavas.vn',
            'quanly@smartcassavas.vn' => 'quanly@cassavas.vn',
            'letan@smartcassavas.vn' => 'letan@cassavas.vn',
            'cudan@smartcassavas.vn' => 'nguyenvanan@cassavas.vn',
        ];

        $searchIdentifier = $aliasMap[$identifier] ?? $identifier;

        // Tìm user trong database theo username, email hoặc số điện thoại
        $user = DB::table('users')
            ->where('username', $searchIdentifier)
            ->orWhere('email', $searchIdentifier)
            ->orWhere('phone_number', $searchIdentifier)
            ->first();

        if (! $user) {
            return response()->json([
                'detail' => 'Tài khoản không tồn tại trong hệ thống tòa nhà!',
            ], 401);
        }

        // Kiểm tra mật khẩu
        $isValidPassword = false;

        // 1. Kiểm tra hash trong database
        if (! empty($user->password_hash) && Hash::check($password, $user->password_hash)) {
            $isValidPassword = true;
        }

        // 2. Chấp nhận mật khẩu seed mặc định hoặc demo
        if ($password === '123567' || $password === 'Cassavas@2026' || $password === 'admin123' || $password === 'password') {
            $isValidPassword = true;
        }

        if (! $isValidPassword) {
            return response()->json([
                'detail' => 'Mật khẩu không chính xác. Mật khẩu mặc định hệ thống là: 123567',
            ], 401);
        }

        // Lấy danh sách vai trò của User
        $roleCodes = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->pluck('roles.role_code')
            ->toArray();

        $frontendRoles = [];
        foreach ($roleCodes as $code) {
            if ($code === 'SUPER_ADMIN') {
                $frontendRoles[] = 'admin';
            } elseif ($code === 'BUILDING_MANAGER') {
                $frontendRoles[] = 'manager';
            } elseif ($code === 'RECEPTIONIST') {
                $frontendRoles[] = 'receptionist';
            } elseif (str_contains($code, 'RESIDENT')) {
                $frontendRoles[] = 'resident';
            } else {
                $frontendRoles[] = strtolower($code);
            }
        }

        if (empty($frontendRoles)) {
            $frontendRoles = ['resident'];
        }

        $token = 'smart_token_'.Str::random(60);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'user' => [
                'id' => $user->id,
                'username' => $user->username ?? $user->email,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'full_name' => $user->full_name,
                'roles' => $frontendRoles,
                'role' => $frontendRoles[0],
            ],
        ]);
    }

    /**
     * Lấy thông tin user hiện tại
     */
    public function me(Request $request): JsonResponse
    {
        $user = DB::table('users')->where('username', 'admin')->first();

        if (! $user) {
            return response()->json(['detail' => 'Chưa đăng nhập'], 401);
        }

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'full_name' => $user->full_name,
            'roles' => ['admin'],
        ]);
    }

    /**
     * Đăng xuất
     */
    public function logout(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Đã đăng xuất thành công',
        ]);
    }
}
