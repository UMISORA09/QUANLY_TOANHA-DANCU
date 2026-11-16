<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\QuocTinRealtimeService;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        $roles = Cache::remember('rbac_roles_all', 120, function () {
            return Role::select(['id', 'role_code', 'role_name', 'description', 'is_system_role', 'created_at', 'updated_at'])
                ->withCount(['users', 'permissions'])
                ->orderBy('is_system_role', 'desc')
                ->orderBy('role_name', 'asc')
                ->get();
        });

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
        $role = Role::select(['id', 'role_code', 'role_name', 'description', 'is_system_role', 'created_at', 'updated_at'])
            ->with(['permissions:id,module,permission_code,permission_name,description'])
            ->findOrFail($id);

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
        QuocTinRealtimeService::assertNotInCooldown('rbac');

        $validated = $request->validate([
            'role_code' => 'required|string|max:50|regex:/^[A-Z0-9_]+$/',
            'role_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        $actor = $request->user();

        $role = DB::transaction(function () use ($validated, $actor, $request) {
            $newRole = $this->rbacService->createRole($validated, $actor, $request);

            if (! empty($validated['permissions'])) {
                $this->rbacService->syncRolePermissions($newRole, $validated['permissions'], $actor, $request);
            }

            return $newRole;
        });

        $role->load('permissions');
        Cache::forget('rbac_roles_all');
        QuocTinRealtimeService::emit('rbac', 'role', 'CREATED', $role->id, ['role_code' => $role->role_code]);

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
        QuocTinRealtimeService::assertNotInCooldown('rbac');

        $role = Role::findOrFail($id);
        $actor = $request->user();

        $validated = $request->validate([
            'role_code' => 'nullable|string|max:50|regex:/^[A-Z0-9_]+$/',
            'role_name' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
        ]);

        $role = DB::transaction(function () use ($role, $validated, $actor, $request) {
            $updatedRole = $this->rbacService->updateRole($role, $validated, $actor, $request);

            if (isset($validated['permissions'])) {
                $this->rbacService->syncRolePermissions($updatedRole, $validated['permissions'], $actor, $request);
            }

            return $updatedRole;
        });

        $role->load('permissions');
        Cache::forget('rbac_roles_all');
        QuocTinRealtimeService::emit('rbac', 'role', 'UPDATED', $role->id, ['role_code' => $role->role_code]);

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
        QuocTinRealtimeService::assertNotInCooldown('rbac');

        $role = Role::findOrFail($id);
        $actor = $request->user();

        DB::transaction(function () use ($role, $actor, $request) {
            $this->rbacService->deleteRole($role, $actor, $request);
        });

        Cache::forget('rbac_roles_all');
        QuocTinRealtimeService::emit('rbac', 'role', 'DELETED', $id);

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
        $role = Role::select(['id', 'role_code', 'role_name'])->findOrFail($id);
        $permissions = $role->permissions()
            ->select(['permissions.id', 'permissions.module', 'permissions.permission_code', 'permissions.permission_name', 'permissions.description'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'role_id' => $role->id,
                'role_code' => $role->role_code,
                'role_name' => $role->role_name,
                'permissions' => $permissions,
            ],
        ]);
    }

    /**
     * Cập nhật toàn bộ quyền hạn cho vai trò
     */
    public function syncRolePermissions(Request $request, string $id): JsonResponse
    {
        QuocTinRealtimeService::assertNotInCooldown('rbac');

        $role = Role::findOrFail($id);
        $actor = $request->user();

        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => 'string',
        ]);

        $this->rbacService->syncRolePermissions($role, $validated['permissions'], $actor, $request);

        $role->load('permissions');
        Cache::forget('rbac_roles_all');
        QuocTinRealtimeService::emit('rbac', 'role_permission', 'SYNCED', $id, ['role_code' => $role->role_code]);

        return response()->json([
            'success' => true,
            'message' => "Đã cập nhật quyền hạn cho vai trò '{$role->role_name}' thành công.",
            'data' => $role->permissions,
        ]);
    }
}
