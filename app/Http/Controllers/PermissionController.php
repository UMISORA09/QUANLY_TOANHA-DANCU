<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PermissionController extends Controller
{
    public function __construct(
        protected RbacService $rbacService
    ) {}

    /**
     * Danh sách tất cả quyền hạn (có thể nhóm theo module)
     */
    public function index(Request $request): JsonResponse
    {
        $grouped = $request->boolean('grouped', true);
        $search = trim((string) $request->input('search', ''));
        $module = trim((string) $request->input('module', ''));

        $query = Permission::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('permission_code', 'like', "%{$search}%")
                    ->orWhere('permission_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($module !== '') {
            $query->where('module', strtoupper($module));
        }

        $permissions = $query->orderBy('module')->orderBy('permission_code')->get();

        if ($grouped) {
            $byModule = $permissions->groupBy('module');

            return response()->json([
                'success' => true,
                'data' => $byModule,
                'total' => $permissions->count(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $permissions,
            'total' => $permissions->count(),
        ]);
    }

    /**
     * Chi tiết một quyền hạn
     */
    public function show(string $id): JsonResponse
    {
        $permission = Permission::with('roles')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $permission,
        ]);
    }

    /**
     * Tạo quyền hạn mới
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => 'required|string|max:50|regex:/^[A-Z0-9_]+$/',
            'permission_code' => 'required|string|max:80|regex:/^[A-Z0-9_]+:[A-Z0-9_]+$/|unique:permissions,permission_code',
            'permission_name' => 'required|string|max:150',
            'description' => 'nullable|string',
        ]);

        $actor = $request->user();

        $permission = Permission::create([
            'id' => (string) Str::uuid(),
            'module' => strtoupper(trim($validated['module'])),
            'permission_code' => strtoupper(trim($validated['permission_code'])),
            'permission_name' => trim($validated['permission_name']),
            'description' => $validated['description'] ?? null,
            'created_at' => now(),
        ]);

        $this->rbacService->logAudit('permissions', $permission->id, 'INSERT', $actor, null, $permission->toArray(), $request);

        return response()->json([
            'success' => true,
            'message' => "Tạo quyền hạn '{$permission->permission_code}' thành công.",
            'data' => $permission,
        ], 201);
    }

    /**
     * Cập nhật thông tin quyền hạn
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);
        $actor = $request->user();

        $validated = $request->validate([
            'module' => 'nullable|string|max:50|regex:/^[A-Z0-9_]+$/',
            'permission_code' => "nullable|string|max:80|regex:/^[A-Z0-9_]+:[A-Z0-9_]+$/|unique:permissions,permission_code,{$id}",
            'permission_name' => 'nullable|string|max:150',
            'description' => 'nullable|string',
        ]);

        $oldData = $permission->toArray();

        if (isset($validated['module'])) {
            $permission->module = strtoupper(trim($validated['module']));
        }
        if (isset($validated['permission_code'])) {
            $permission->permission_code = strtoupper(trim($validated['permission_code']));
        }
        if (isset($validated['permission_name'])) {
            $permission->permission_name = trim($validated['permission_name']);
        }
        if (array_key_exists('description', $validated)) {
            $permission->description = $validated['description'];
        }

        $permission->save();

        $this->rbacService->logAudit('permissions', $permission->id, 'UPDATE', $actor, $oldData, $permission->toArray(), $request);

        return response()->json([
            'success' => true,
            'message' => "Cập nhật quyền hạn '{$permission->permission_code}' thành công.",
            'data' => $permission,
        ]);
    }

    /**
     * Xóa quyền hạn
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);
        $actor = $request->user();

        // Kiểm tra xem có vai trò nào đang dùng quyền này không
        $rolesCount = $permission->roles()->count();
        if ($rolesCount > 0) {
            throw ValidationException::withMessages([
                'permission' => "Không thể xóa quyền này vì đang có {$rolesCount} vai trò đang được gán.",
            ]);
        }

        $oldData = $permission->toArray();
        $permission->delete();

        $this->rbacService->logAudit('permissions', $permission->id, 'DELETE', $actor, $oldData, null, $request);

        return response()->json([
            'success' => true,
            'message' => "Đã xóa quyền hạn '{$permission->permission_code}' thành công.",
        ]);
    }
}
