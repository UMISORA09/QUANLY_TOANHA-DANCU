<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(
        protected RbacService $rbacService
    ) {}

    /**
     * Danh sách người dùng hệ thống (kèm tìm kiếm, lọc theo vai trò & phân trang)
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $roleFilter = trim((string) $request->input('role', ''));
        $statusFilter = trim((string) $request->input('status', ''));
        $perPage = min(max((int) $request->input('limit', 15), 5), 100);

        $query = User::with(['roles' => function ($q) {
            $q->select('roles.id', 'roles.role_code', 'roles.role_name', 'roles.is_system_role');
        }]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        if ($statusFilter !== '') {
            $query->where('status', strtoupper($statusFilter));
        }

        if ($roleFilter !== '') {
            $query->whereHas('roles', function ($q) use ($roleFilter) {
                $q->where('role_code', strtoupper($roleFilter))
                    ->orWhere('id', $roleFilter);
            });
        }

        $users = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Lấy chi tiết một người dùng
     */
    public function show(string $id): JsonResponse
    {
        $user = User::with('roles.permissions')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'full_name' => $user->full_name,
                'status' => $user->status,
                'roles' => $user->roles,
                'permissions' => $user->getAllPermissions(),
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * Tạo tài khoản người dùng mới
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:60|unique:users,username',
            'phone_number' => 'required|string|max:20|unique:users,phone_number',
            'email' => 'required|email|max:120|unique:users,email',
            'full_name' => 'required|string|max:150',
            'password' => 'required|string|min:6',
            'status' => 'nullable|in:ACTIVE,LOCKED,SUSPENDED',
            'roles' => 'nullable|array',
            'roles.*' => 'string',
        ]);

        $actor = $request->user();

        $user = User::create([
            'username' => trim($validated['username']),
            'phone_number' => trim($validated['phone_number']),
            'email' => strtolower(trim($validated['email'])),
            'full_name' => trim($validated['full_name']),
            'password_hash' => Hash::make($validated['password']),
            'status' => $validated['status'] ?? 'ACTIVE',
        ]);

        // Gán vai trò ban đầu nếu có
        $roles = $validated['roles'] ?? ['RESIDENT_OWNER'];
        $this->rbacService->syncUserRoles($user, $roles, null, $actor, $request);

        $this->rbacService->logAudit('users', $user->id, 'INSERT', $actor, null, $user->toArray(), $request);

        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Tạo người dùng mới thành công.',
            'data' => $user,
        ], 201);
    }

    /**
     * Cập nhật thông tin người dùng
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $actor = $request->user();

        $validated = $request->validate([
            'full_name' => 'nullable|string|max:150',
            'phone_number' => "nullable|string|max:20|unique:users,phone_number,{$id}",
            'email' => "nullable|email|max:120|unique:users,email,{$id}",
            'status' => 'nullable|in:ACTIVE,LOCKED,SUSPENDED',
            'password' => 'nullable|string|min:6',
            'roles' => 'nullable|array',
            'roles.*' => 'string',
        ]);

        $oldData = $user->toArray();

        if (! empty($validated['full_name'])) {
            $user->full_name = trim($validated['full_name']);
        }
        if (! empty($validated['phone_number'])) {
            $user->phone_number = trim($validated['phone_number']);
        }
        if (! empty($validated['email'])) {
            $user->email = strtolower(trim($validated['email']));
        }
        if (! empty($validated['status'])) {
            $user->status = $validated['status'];
        }
        if (! empty($validated['password'])) {
            $user->password_hash = Hash::make($validated['password']);
        }

        $user->save();

        if (isset($validated['roles'])) {
            $this->rbacService->syncUserRoles($user, $validated['roles'], null, $actor, $request);
        }

        $this->rbacService->logAudit('users', $user->id, 'UPDATE', $actor, $oldData, $user->toArray(), $request);

        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật thông tin người dùng thành công.',
            'data' => $user,
        ]);
    }

    /**
     * Xóa tài khoản người dùng (Soft Delete)
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $actor = $request->user();

        if ($actor && $actor->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không thể tự xóa tài khoản của chính mình.',
            ], 422);
        }

        if ($user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa tài khoản Quản trị viên cấp cao (SUPER_ADMIN).',
            ], 422);
        }

        $oldData = $user->toArray();
        $user->delete();

        $this->rbacService->logAudit('users', $user->id, 'DELETE', $actor, $oldData, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa người dùng thành công.',
        ]);
    }

    /**
     * Lấy danh sách vai trò của một người dùng
     */
    public function getUserRoles(string $id): JsonResponse
    {
        $user = User::with('roles')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user->roles,
        ]);
    }

    /**
     * Gán hoặc cập nhật vai trò cho người dùng
     */
    public function assignRoles(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $actor = $request->user();

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string',
            'primary_role' => 'nullable|string',
        ]);

        $this->rbacService->syncUserRoles(
            $user,
            $validated['roles'],
            $validated['primary_role'] ?? null,
            $actor,
            $request
        );

        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật vai trò người dùng thành công.',
            'data' => $user->roles,
        ]);
    }
}
