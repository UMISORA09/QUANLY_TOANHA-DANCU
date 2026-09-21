<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(
        protected RbacService $rbacService
    ) {}

    /**
     * Danh sách tất cả vai trò (kèm số lượng người dùng và quyền hạn)
     */
    public function index(): JsonResponse
    {
        $roles = Role::withCount(['users', 'permissions'])
            ->orderBy('is_system_role', 'desc')
            ->orderBy('role_name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Chi tiết một vai trò kèm danh sách quyền hạn
     */
    public function show(string $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $role,
        ]);
    }

    /**
     * Tạo vai trò mới
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role_code' => 'required|string|max:50|regex:/^[A-Z0-9_]+$/',
            'role_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        $actor = $request->user();

        $role = $this->rbacService->createRole($validated, $actor, $request);

        if (! empty($validated['permissions'])) {
            $this->rbacService->syncRolePermissions($role, $validated['permissions'], $actor, $request);
        }

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => "Tạo vai trò '{$role->role_name}' thành công.",
            'data' => $role,
        ], 201);
    }

    /**
     * Cập nhật thông tin vai trò
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $actor = $request->user();

        $validated = $request->validate([
            'role_code' => 'nullable|string|max:50|regex:/^[A-Z0-9_]+$/',
            'role_name' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        $role = $this->rbacService->updateRole($role, $validated, $actor, $request);

        if (isset($validated['permissions'])) {
            $this->rbacService->syncRolePermissions($role, $validated['permissions'], $actor, $request);
        }

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => "Cập nhật vai trò '{$role->role_name}' thành công.",
            'data' => $role,
        ]);
    }

    /**
     * Xóa vai trò
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $actor = $request->user();

        $this->rbacService->deleteRole($role, $actor, $request);

        return response()->json([
            'success' => true,
            'message' => "Đã xóa vai trò '{$role->role_name}' thành công.",
        ]);
    }

    /**
     * Lấy danh sách quyền hạn được gán cho vai trò
     */
    public function getRolePermissions(string $id): JsonResponse
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'role_id' => $role->id,
                'role_code' => $role->role_code,
                'role_name' => $role->role_name,
                'permissions' => $role->permissions,
            ],
        ]);
    }

    /**
     * Cập nhật toàn bộ quyền hạn cho vai trò
     */
    public function syncRolePermissions(Request $request, string $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $actor = $request->user();

        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => 'string',
        ]);

        $this->rbacService->syncRolePermissions($role, $validated['permissions'], $actor, $request);

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => "Đã cập nhật quyền hạn cho vai trò '{$role->role_name}' thành công.",
            'data' => $role->permissions,
        ]);
    }
}
