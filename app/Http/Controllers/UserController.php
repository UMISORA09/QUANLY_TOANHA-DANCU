<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\QuocTinRealtimeService;
use App\Services\RbacService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Mã vai trò quản trị tối cao.
     *
     * @var array<int, string>
     */
    protected const SUPER_ADMIN_ROLE_CODES = [
        'SUPER_ADMIN',
        'SUPER_ADMI',
    ];

    /**
     * Mã vai trò quản trị đặc quyền cao chỉ SUPER_ADMIN được phép gán.
     *
     * @var array<int, string>
     */
    protected const RESTRICTED_ADMIN_ROLE_CODES = [
        'SUPER_ADMIN',
        'SUPER_ADMI',
        'ADMIN',
    ];

    /**
     * Tiền tố vai trò phân quyền riêng gắn theo từng tài khoản.
     */
    protected const CUSTOM_USER_ROLE_PREFIX = 'CUSTOM_USER_';

    /**
     * Tập permission đặc biệt / đặc quyền hệ thống không cho phép tài khoản dưới SUPER_ADMIN cấp.
     *
     * @var array<int, string>
     */
    protected const PRIVILEGED_PERMISSIONS = [
        'SPECIAL',
        'SYSTEM:SPECIAL',
        'USER:SPECIAL',
        'ROLE:SPECIAL',
        'PERMISSION:SPECIAL',
        'VEHICLE:SPECIAL',
        'AMENITY:SPECIAL',
        'INVOICE:SPECIAL',
        'TICKET:SPECIAL',
        'VISITOR:SPECIAL',
        'REPORT:SPECIAL',
        'ROLE:ASSIGN_PERMISSION',
        'ROLE:CREATE',
        'ROLE:UPDATE',
        'ROLE:DELETE',
        'ROLE:DOWNLOAD',
        'ROLE:UPLOAD',
        'PERMISSION:CREATE',
        'PERMISSION:UPDATE',
        'PERMISSION:DELETE',
        'PERMISSION:DOWNLOAD',
        'PERMISSION:UPLOAD',
        'USER:ASSIGN_ROLE',
        'USER:DELETE',
        'CICD:RUN',
        'CICD:DEPLOY',
        'CICD:ROLLBACK',
    ];

    /**
     * Danh sách key payload có nguy cơ dùng để giả mạo vai trò/quyền hạn.
     *
     * @var array<int, string>
     */
    protected const PRIVILEGE_PAYLOAD_KEYS = [
        'role',
        'role_code',
        'role_codes',
        'role_id',
        'role_ids',
        'primary_role',
        'roles',
        'permissions',
        'permission_codes',
        'is_super_admin',
        'custom_role',
        'extra_preferences',
        'user_id',
        'bypass_rbac',
    ];

    public function __construct(
        protected RbacService $rbacService
    ) {}

    /**
     * Trả về phản hồi lỗi 403 Forbidden chuẩn hóa.
     */
    protected function forbiddenResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => 'FORBIDDEN',
        ], 403);
    }

    /**
     * Kiểm tra tài khoản người dùng đang ở trạng thái ACTIVE và chưa bị xóa mềm.
     */
    protected function isUserRecordActive(?User $user): bool
    {
        if (! $user || empty($user->id)) {
            return false;
        }

        return DB::table('users')
            ->where('id', $user->id)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Lấy danh sách mã vai trò hợp lệ từ CSDL (loại trừ vai trò đã xóa mềm).
     *
     * @return array<int, string>
     */
    protected function getUserRoleCodesFromDb(User $user): array
    {
        if (empty($user->id)) {
            return [];
        }

        return DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->whereNull('roles.deleted_at')
            ->pluck('roles.role_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Kiểm tra người thực hiện có phải SUPER_ADMIN đang ACTIVE trong CSDL hay không.
     */
    protected function isActiveSuperAdminFromDb(?User $user): bool
    {
        if (! $this->isUserRecordActive($user)) {
            return false;
        }

        /** @var User $user */
        return $this->userIsSuperAdminFromDb($user);
    }

    /**
     * Kiểm tra tài khoản bất kỳ có đang giữ vai trò SUPER_ADMIN trong CSDL hay không.
     */
    protected function userIsSuperAdminFromDb(User $user): bool
    {
        $roleCodes = $this->getUserRoleCodesFromDb($user);

        foreach (self::SUPER_ADMIN_ROLE_CODES as $superCode) {
            if (in_array($superCode, $roleCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lấy tập permission_code thực tế đang hiệu lực từ CSDL (tôn trọng CUSTOM_USER_* và lọc role deleted_at).
     *
     * @return array<int, string>
     */
    protected function getUserEffectivePermissionsFromDb(User $user): array
    {
        if ($this->userIsSuperAdminFromDb($user)) {
            return Permission::query()
                ->pluck('permission_code')
                ->map(fn ($code): string => strtoupper(trim((string) $code)))
                ->filter(fn (string $code): bool => $code !== '')
                ->unique()
                ->values()
                ->toArray();
        }

        $customRoleId = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->where('user_roles.user_id', $user->id)
            ->whereNull('roles.deleted_at')
            ->where('roles.role_code', 'LIKE', self::CUSTOM_USER_ROLE_PREFIX.'%')
            ->value('roles.id');

        if ($customRoleId) {
            return DB::table('role_permissions')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('role_permissions.role_id', $customRoleId)
                ->pluck('permissions.permission_code')
                ->map(fn ($code): string => strtoupper(trim((string) $code)))
                ->filter(fn (string $code): bool => $code !== '')
                ->unique()
                ->values()
                ->toArray();
        }

        return DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $user->id)
            ->whereNull('roles.deleted_at')
            ->pluck('permissions.permission_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Lấy danh sách permission của các vai trò gốc (bỏ qua CUSTOM_USER_%) để kiểm tra khi reset quyền.
     *
     * @return array<int, string>
     */
    protected function getUserBaseRolePermissionsFromDb(User $user): array
    {
        return DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('user_roles.user_id', $user->id)
            ->whereNull('roles.deleted_at')
            ->where('roles.role_code', 'NOT LIKE', self::CUSTOM_USER_ROLE_PREFIX.'%')
            ->pluck('permissions.permission_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Kiểm tra mã permission có thuộc nhóm đặc biệt / đặc quyền cao hay không.
     */
    protected function isPrivilegedPermissionCode(string $code): bool
    {
        $upper = strtoupper(trim($code));
        if ($upper === '') {
            return false;
        }

        if ($upper === 'SPECIAL' || str_ends_with($upper, ':SPECIAL') || str_starts_with($upper, 'SYSTEM:')) {
            return true;
        }

        return in_array($upper, self::PRIVILEGED_PERMISSIONS, true);
    }

    /**
     * Từ chối các key payload giả mạo vai trò/quyền hạn không hợp lệ cho endpoint.
     *
     * @param  array<int, string>  $allowedKeys
     */
    protected function rejectSpoofedPrivilegeFields(Request $request, array $allowedKeys = []): ?JsonResponse
    {
        $payload = $request->all();

        foreach (self::PRIVILEGE_PAYLOAD_KEYS as $key) {
            if (in_array($key, $allowedKeys, true) || ! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if ($value !== null && $value !== '' && $value !== [] && $value !== false) {
                return $this->forbiddenResponse("Không được phép giả mạo hoặc truyền trường đặc quyền '{$key}' trong yêu cầu này.");
            }
        }

        return null;
    }

    /**
     * Kiểm tra và phân giải danh sách permission trong syncUserPermissions trước khi ghi DB.
     *
     * @return array{error: ?JsonResponse, permission_codes: array<int, string>}
     */
    protected function authorizeAndResolvePermissionSyncPayload(Request $request, User $targetUser): array
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->isUserRecordActive($actor)) {
            return ['error' => $this->forbiddenResponse('Tài khoản người thực hiện không hợp lệ hoặc đã bị khóa.'), 'permission_codes' => []];
        }

        if ($spoofError = $this->rejectSpoofedPrivilegeFields($request, ['permissions'])) {
            return ['error' => $spoofError, 'permission_codes' => []];
        }

        $actorIsSuperAdmin = $this->isActiveSuperAdminFromDb($actor);
        $actorPermissions = $this->getUserEffectivePermissionsFromDb($actor);

        if (! $actorIsSuperAdmin && ! in_array('ROLE:ASSIGN_PERMISSION', $actorPermissions, true)) {
            return ['error' => $this->forbiddenResponse('Bạn không có quyền phân quyền cho người dùng (yêu cầu quyền ROLE:ASSIGN_PERMISSION).'), 'permission_codes' => []];
        }

        if (! $actorIsSuperAdmin && (string) $actor->id === (string) $targetUser->id) {
            return ['error' => $this->forbiddenResponse('Bạn không được phép tự cấp hoặc thay đổi quyền hạn cho chính mình.'), 'permission_codes' => []];
        }

        if (! $actorIsSuperAdmin && $this->userIsSuperAdminFromDb($targetUser)) {
            return ['error' => $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền thay đổi quyền hạn của tài khoản SUPER_ADMIN.'), 'permission_codes' => []];
        }

        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => 'string',
        ]);

        $resolvedCodes = [];
        foreach ($validated['permissions'] as $rawItem) {
            $trimmed = trim((string) $rawItem);
            if ($trimmed === '') {
                throw ValidationException::withMessages([
                    'permissions' => 'Danh sách quyền hạn không được chứa giá trị rỗng.',
                ]);
            }

            $upper = strtoupper($trimmed);

            if (! $actorIsSuperAdmin && $this->isPrivilegedPermissionCode($upper)) {
                return ['error' => $this->forbiddenResponse("Bạn không có quyền cấp quyền đặc biệt hoặc đặc quyền hệ thống ({$upper})."), 'permission_codes' => []];
            }

            if ($upper === 'SPECIAL') {
                $matched = Permission::query()
                    ->whereIn('permission_code', ['SPECIAL', 'SYSTEM:SPECIAL'])
                    ->get();
            } else {
                $matched = Permission::query()
                    ->where(function ($query) use ($trimmed, $upper): void {
                        $query->where('permission_code', $upper);
                        if (Str::isUuid($trimmed)) {
                            $query->orWhere('id', $trimmed);
                        }
                    })
                    ->get();
            }

            if ($matched->isEmpty()) {
                if (! $actorIsSuperAdmin) {
                    return ['error' => $this->forbiddenResponse("Bạn không được phép cấp quyền '{$trimmed}' vì không nằm trong phạm vi quyền bạn sở hữu."), 'permission_codes' => []];
                }

                throw ValidationException::withMessages([
                    'permissions' => "Quyền hạn '{$trimmed}' không tồn tại trong hệ thống.",
                ]);
            }

            foreach ($matched as $permModel) {
                $resolvedCode = strtoupper(trim((string) $permModel->permission_code));

                if (! $actorIsSuperAdmin && $this->isPrivilegedPermissionCode($resolvedCode)) {
                    return ['error' => $this->forbiddenResponse("Bạn không có quyền cấp quyền đặc biệt hoặc đặc quyền hệ thống ({$resolvedCode})."), 'permission_codes' => []];
                }

                if (! $actorIsSuperAdmin && ! in_array($resolvedCode, $actorPermissions, true)) {
                    return ['error' => $this->forbiddenResponse("Bạn không được phép cấp quyền '{$resolvedCode}' vượt quá phạm vi quyền hiện tại của bản thân."), 'permission_codes' => []];
                }

                $resolvedCodes[] = $resolvedCode;
            }
        }

        return [
            'error' => null,
            'permission_codes' => array_values(array_unique($resolvedCodes)),
        ];
    }

    /**
     * Kiểm tra quyền gán danh sách vai trò cho người dùng trong store/update/assignRoles.
     *
     * @param  array<int, string>  $rawRoleInputs
     */
    protected function authorizeRoleAssignmentForTarget(
        User $actor,
        ?User $targetUser,
        array $rawRoleInputs,
        bool $requireAssignRolePermission,
        bool $useValidationErrorForSuperAdmin
    ): ?JsonResponse {
        $actorIsSuperAdmin = $this->isActiveSuperAdminFromDb($actor);
        $actorPermissions = $this->getUserEffectivePermissionsFromDb($actor);

        $isSelfUpdate = $targetUser !== null && (string) $actor->id === (string) $targetUser->id;

        if ($targetUser !== null && ! $actorIsSuperAdmin && $this->userIsSuperAdminFromDb($targetUser)) {
            return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền chỉnh sửa vai trò của tài khoản SUPER_ADMIN.');
        }

        if ($requireAssignRolePermission && ! $actorIsSuperAdmin && ! in_array('USER:ASSIGN_ROLE', $actorPermissions, true)) {
            return $this->forbiddenResponse('Bạn không có quyền gán hoặc thay đổi vai trò người dùng (yêu cầu quyền USER:ASSIGN_ROLE).');
        }

        if ($isSelfUpdate && ! $actorIsSuperAdmin) {
            return $this->forbiddenResponse('Bạn không được phép tự thay đổi hoặc nâng vai trò của chính mình.');
        }

        $trimmedInputs = [];
        $upperCodes = [];
        $uuids = [];
        foreach ($rawRoleInputs as $item) {
            $trimmed = trim((string) $item);
            if ($trimmed === '') {
                continue;
            }

            $upper = strtoupper($trimmed);
            if (! $actorIsSuperAdmin && str_starts_with($upper, self::CUSTOM_USER_ROLE_PREFIX)) {
                return $this->forbiddenResponse('Không được phép gán vai trò phân quyền riêng (CUSTOM_USER) thông qua chức năng gán vai trò.');
            }

            $trimmedInputs[] = $trimmed;
            $upperCodes[] = $upper;
            if (Str::isUuid($trimmed)) {
                $uuids[] = $trimmed;
            }
        }

        if (empty($trimmedInputs)) {
            if ($actorIsSuperAdmin) {
                return null;
            }

            throw ValidationException::withMessages([
                'roles' => 'Danh sách vai trò không được rỗng.',
            ]);
        }

        /** @var EloquentCollection<int, Role> $resolvedRoles */
        $resolvedRoles = Role::query()
            ->where(function ($query) use ($upperCodes, $uuids): void {
                $query->whereIn('role_code', $upperCodes);
                if (! empty($uuids)) {
                    $query->orWhereIn('id', $uuids);
                }
            })
            ->get();

        $matchedCodes = $resolvedRoles
            ->pluck('role_code')
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->all();

        $matchedIds = $resolvedRoles
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ($trimmedInputs as $token) {
            $upperToken = strtoupper($token);
            $matchedByCode = in_array($upperToken, $matchedCodes, true);
            $matchedById = Str::isUuid($token) && in_array($token, $matchedIds, true);

            if (! $actorIsSuperAdmin && in_array($upperToken, self::SUPER_ADMIN_ROLE_CODES, true)) {
                if ($useValidationErrorForSuperAdmin) {
                    throw ValidationException::withMessages([
                        'roles' => 'Bạn không có quyền gán vai trò Quản trị viên cấp cao (SUPER_ADMIN).',
                    ]);
                }

                return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền gán vai trò SUPER_ADMIN.');
            }

            if (! $matchedByCode && ! $matchedById) {
                throw ValidationException::withMessages([
                    'roles' => "Vai trò '{$token}' không tồn tại hoặc không hợp lệ.",
                ]);
            }
        }

        if ($actorIsSuperAdmin) {
            return null;
        }

        foreach ($resolvedRoles as $role) {
            $roleCode = strtoupper(trim((string) $role->role_code));

            if (str_starts_with($roleCode, self::CUSTOM_USER_ROLE_PREFIX)) {
                return $this->forbiddenResponse('Không được phép gán vai trò phân quyền riêng (CUSTOM_USER) thông qua chức năng gán vai trò.');
            }

            if (in_array($roleCode, self::SUPER_ADMIN_ROLE_CODES, true)) {
                if ($useValidationErrorForSuperAdmin) {
                    throw ValidationException::withMessages([
                        'roles' => 'Bạn không có quyền gán vai trò Quản trị viên cấp cao (SUPER_ADMIN).',
                    ]);
                }

                return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền gán vai trò SUPER_ADMIN.');
            }

            if (in_array($roleCode, self::RESTRICTED_ADMIN_ROLE_CODES, true)) {
                return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền gán vai trò quản trị tối cao.');
            }

            if (! $requireAssignRolePermission && in_array($roleCode, ['BUILDING_MANAGER', 'MANAGER'], true) && ! in_array('USER:ASSIGN_ROLE', $actorPermissions, true)) {
                return $this->forbiddenResponse('Bạn không có quyền gán vai trò quản lý (yêu cầu quyền USER:ASSIGN_ROLE).');
            }

            $rolePermissions = DB::table('role_permissions')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('role_permissions.role_id', $role->id)
                ->pluck('permissions.permission_code')
                ->map(fn ($code): string => strtoupper(trim((string) $code)))
                ->filter(fn (string $code): bool => $code !== '')
                ->unique()
                ->all();

            foreach ($rolePermissions as $permCode) {
                if ($this->isPrivilegedPermissionCode($permCode)) {
                    return $this->forbiddenResponse("Bạn không có quyền gán vai trò chứa đặc quyền hệ thống ({$permCode}).");
                }

                $isSensitiveGovernancePerm = str_starts_with($permCode, 'USER:')
                    || str_starts_with($permCode, 'ROLE:')
                    || str_starts_with($permCode, 'PERMISSION:');

                if ($isSensitiveGovernancePerm && ! in_array($permCode, $actorPermissions, true)) {
                    return $this->forbiddenResponse("Bạn không có quyền gán vai trò chứa quyền quản trị '{$permCode}' mà bạn không sở hữu.");
                }
            }
        }

        return null;
    }

    /**
     * Danh sách người dùng hệ thống (kèm tìm kiếm, lọc theo vai trò & phân trang)
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $roleFilter = trim((string) $request->input('role', ''));
        $statusFilter = trim((string) $request->input('status', ''));
        $perPage = min(max((int) $request->input('limit', 15), 5), 100);

        $query = User::select([
            'id',
            'username',
            'phone_number',
            'email',
            'full_name',
            'avatar_url',
            'status',
            'created_at',
        ])->with(['roles' => function ($q) {
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
        $user = User::select([
            'id',
            'username',
            'email',
            'phone_number',
            'full_name',
            'avatar_url',
            'gender',
            'date_of_birth',
            'national_id_number',
            'status',
            'created_at',
            'updated_at',
        ])->with(['roles:id,role_code,role_name,is_system_role'])->findOrFail($id);

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
        QuocTinRealtimeService::assertNotInCooldown('rbac');
        QuocTinRealtimeService::assertNotInCooldown('account_provisioning');

        $actor = $request->user();
        if (! $actor instanceof User || ! $this->isUserRecordActive($actor)) {
            return $this->forbiddenResponse('Tài khoản người thực hiện không hợp lệ hoặc đã bị khóa.');
        }

        if ($spoofError = $this->rejectSpoofedPrivilegeFields($request, ['roles'])) {
            return $spoofError;
        }

        if ($request->has('roles') && is_array($request->input('roles'))) {
            if ($authError = $this->authorizeRoleAssignmentForTarget($actor, null, $request->input('roles'), false, false)) {
                return $authError;
            }
        }

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

        $roles = $validated['roles'] ?? ['RESIDENT_OWNER'];
        if ($authError = $this->authorizeRoleAssignmentForTarget($actor, null, $roles, false, false)) {
            return $authError;
        }

        $user = DB::transaction(function () use ($validated, $roles, $actor, $request) {
            $created = User::create([
                'username' => trim($validated['username']),
                'phone_number' => trim($validated['phone_number']),
                'email' => strtolower(trim($validated['email'])),
                'full_name' => trim($validated['full_name']),
                'password_hash' => Hash::make($validated['password']),
                'status' => $validated['status'] ?? 'ACTIVE',
            ]);

            $this->rbacService->syncUserRoles($created, $roles, null, $actor, $request);

            return $created;
        });

        $this->rbacService->logAudit('users', $user->id, 'INSERT', $actor, null, $user->toArray(), $request);

        $user->clearPermissionCache();
        $user->load('roles');
        QuocTinRealtimeService::emit('account-provisioning', 'user', 'CREATED', $user->id);
        QuocTinRealtimeService::emit('rbac', 'user_role', 'ASSIGNED', $user->id);

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
        QuocTinRealtimeService::assertNotInCooldown('rbac');
        QuocTinRealtimeService::assertNotInCooldown('account_provisioning');

        $actor = $request->user();
        if (! $actor instanceof User || ! $this->isUserRecordActive($actor)) {
            return $this->forbiddenResponse('Tài khoản người thực hiện không hợp lệ hoặc đã bị khóa.');
        }

        $user = User::findOrFail($id);

        if (! $this->isActiveSuperAdminFromDb($actor) && $this->userIsSuperAdminFromDb($user)) {
            return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền chỉnh sửa tài khoản SUPER_ADMIN.');
        }

        if ($spoofError = $this->rejectSpoofedPrivilegeFields($request, ['roles'])) {
            return $spoofError;
        }

        if ($request->has('roles') && is_array($request->input('roles'))) {
            if ($authError = $this->authorizeRoleAssignmentForTarget($actor, $user, $request->input('roles'), true, false)) {
                return $authError;
            }
        }

        $validated = $request->validate([
            'full_name' => 'nullable|string|max:150',
            'phone_number' => "nullable|string|max:20|unique:users,phone_number,{$id}",
            'email' => "nullable|email|max:120|unique:users,email,{$id}",
            'status' => 'nullable|in:ACTIVE,LOCKED,SUSPENDED',
            'password' => 'nullable|string|min:6',
            'roles' => 'nullable|array',
            'roles.*' => 'string',
        ]);

        if (isset($validated['roles'])) {
            if ($authError = $this->authorizeRoleAssignmentForTarget($actor, $user, $validated['roles'], true, false)) {
                return $authError;
            }
        }

        $oldData = $user->toArray();

        DB::transaction(function () use ($user, $validated, $actor, $request) {
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
        });

        $user->clearPermissionCache();
        $user->unsetRelation('roles');

        if (isset($validated['roles'])) {
            QuocTinRealtimeService::emit('rbac', 'user_role', 'ASSIGNED', $user->id);
        }

        $this->rbacService->logAudit('users', $user->id, 'UPDATE', $actor, $oldData, $user->toArray(), $request);

        $user->load('roles');
        QuocTinRealtimeService::emit('account-provisioning', 'user', 'UPDATED', $user->id);

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
        QuocTinRealtimeService::assertNotInCooldown('rbac');
        QuocTinRealtimeService::assertNotInCooldown('account_provisioning');

        $actor = $request->user();
        if (! $actor instanceof User || ! $this->isUserRecordActive($actor)) {
            return $this->forbiddenResponse('Tài khoản người thực hiện không hợp lệ hoặc đã bị khóa.');
        }

        $user = User::findOrFail($id);

        if ((string) $actor->id === (string) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không thể tự xóa tài khoản của chính mình.',
            ], 422);
        }

        if ($this->userIsSuperAdminFromDb($user)) {
            if (! $this->isActiveSuperAdminFromDb($actor)) {
                return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền xóa tài khoản SUPER_ADMIN.');
            }

            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa tài khoản Quản trị viên cấp cao (SUPER_ADMIN).',
            ], 422);
        }

        $oldData = $user->toArray();
        $user->delete();

        $this->rbacService->logAudit('users', $user->id, 'DELETE', $actor, $oldData, null, $request);
        QuocTinRealtimeService::emit('account-provisioning', 'user', 'DELETED', $id);

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
        $user = User::select(['id'])->findOrFail($id);
        $roles = $user->roles()
            ->select(['roles.id', 'roles.role_code', 'roles.role_name', 'roles.is_system_role', 'roles.description'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Gán hoặc cập nhật vai trò cho người dùng
     */
    public function assignRoles(Request $request, string $id): JsonResponse
    {
        QuocTinRealtimeService::assertNotInCooldown('rbac');

        $actor = $request->user();
        if (! $actor instanceof User || ! $this->isUserRecordActive($actor)) {
            return $this->forbiddenResponse('Tài khoản người thực hiện không hợp lệ hoặc đã bị khóa.');
        }

        if ($spoofError = $this->rejectSpoofedPrivilegeFields($request, ['roles', 'primary_role'])) {
            return $spoofError;
        }

        $user = User::findOrFail($id);

        $actorIsSuperAdmin = $this->isActiveSuperAdminFromDb($actor);
        $actorPermissions = $this->getUserEffectivePermissionsFromDb($actor);

        if (! $actorIsSuperAdmin && ! in_array('USER:ASSIGN_ROLE', $actorPermissions, true)) {
            return $this->forbiddenResponse('Bạn không có quyền gán hoặc thay đổi vai trò người dùng (yêu cầu quyền USER:ASSIGN_ROLE).');
        }

        if (! $actorIsSuperAdmin && (string) $actor->id === (string) $user->id) {
            return $this->forbiddenResponse('Bạn không được phép tự thay đổi hoặc nâng vai trò của chính mình.');
        }

        if (! $actorIsSuperAdmin && $this->userIsSuperAdminFromDb($user)) {
            return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền thay đổi vai trò của tài khoản SUPER_ADMIN.');
        }

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string',
            'primary_role' => 'nullable|string',
        ]);

        if ($authError = $this->authorizeRoleAssignmentForTarget($actor, $user, $validated['roles'], true, true)) {
            return $authError;
        }

        DB::transaction(function () use ($user, $validated, $actor, $request): void {
            $this->rbacService->syncUserRoles(
                $user,
                $validated['roles'],
                $validated['primary_role'] ?? null,
                $actor,
                $request
            );
        });

        $user->clearPermissionCache();
        $user->unsetRelation('roles');
        $user->load('roles');
        QuocTinRealtimeService::emit('rbac', 'user_role', 'ASSIGNED', $id, ['user_id' => $id]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật vai trò người dùng thành công.',
            'data' => $user->roles,
        ]);
    }

    /**
     * Lấy danh sách quyền hạn thực tế của một tài khoản người dùng
     */
    public function getUserPermissions(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $customRole = $user->roles()->where('role_code', 'LIKE', 'CUSTOM_USER_%')->first();
        $permissions = $user->getAllPermissions();

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'is_customized' => $customRole !== null,
                'permissions' => $permissions,
            ],
        ]);
    }

    /**
     * Cập nhật danh sách phân quyền riêng cho từng tài khoản người dùng theo ma trận 7 quyền
     */
    public function syncUserPermissions(Request $request, string $id): JsonResponse
    {
        QuocTinRealtimeService::assertNotInCooldown('rbac');

        $user = User::findOrFail($id);

        $authResult = $this->authorizeAndResolvePermissionSyncPayload($request, $user);
        if ($authResult['error'] !== null) {
            return $authResult['error'];
        }

        $actor = $request->user();
        $resolvedPermissionCodes = $authResult['permission_codes'];

        DB::transaction(function () use ($user, $resolvedPermissionCodes, $actor, $request): void {
            // Tìm (bao gồm vai trò đã xóa mềm) hoặc khởi tạo vai trò riêng cho tài khoản người dùng này
            $customRoleCode = self::CUSTOM_USER_ROLE_PREFIX.substr(str_replace('-', '', $user->id), 0, 12);
            $role = Role::withTrashed()->firstOrNew(['role_code' => $customRoleCode]);
            if (! $role->exists || $role->trashed()) {
                $role->fill([
                    'role_name' => 'Quyền riêng: '.($user->full_name ?: $user->username),
                    'description' => 'Vai trò phân quyền tùy chỉnh dành riêng cho tài khoản @'.$user->username,
                    'is_system_role' => false,
                ]);

                if ($role->trashed()) {
                    $role->restore();
                } else {
                    $role->save();
                }
            }

            // Đảm bảo vai trò riêng này được gán cho người dùng
            $existingRoleIds = DB::table('user_roles')->where('user_id', $user->id)->pluck('role_id')->toArray();
            if (! in_array($role->id, $existingRoleIds, true)) {
                DB::table('user_roles')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'is_primary' => 0,
                    'assigned_at' => now(),
                    'assigned_by' => $actor?->id,
                ]);
            }

            // Đồng bộ quyền hạn vào vai trò riêng của tài khoản
            $this->rbacService->syncRolePermissions($role, $resolvedPermissionCodes, $actor, $request);
        });

        // Xóa cache quyền hạn in-memory trước khi đọc lại quyền mới
        $user->clearPermissionCache();
        $user->unsetRelation('roles');

        QuocTinRealtimeService::emit('rbac', 'user_permission', 'UPDATED', $id, ['user_id' => $id]);

        return response()->json([
            'success' => true,
            'message' => "Cập nhật phân quyền thành công cho tài khoản {$user->full_name}.",
            'data' => [
                'user_id' => $user->id,
                'username' => $user->username,
                'is_customized' => true,
                'permissions' => $user->getAllPermissions(),
            ],
        ]);
    }

    /**
     * Khôi phục quyền hạn mặc định theo vai trò cho tài khoản (xóa phân quyền riêng)
     */
    public function resetUserPermissions(Request $request, string $id): JsonResponse
    {
        QuocTinRealtimeService::assertNotInCooldown('rbac');

        $actor = $request->user();
        if (! $actor instanceof User || ! $this->isUserRecordActive($actor)) {
            return $this->forbiddenResponse('Tài khoản người thực hiện không hợp lệ hoặc đã bị khóa.');
        }

        if ($spoofError = $this->rejectSpoofedPrivilegeFields($request, [])) {
            return $spoofError;
        }

        $user = User::findOrFail($id);

        $actorIsSuperAdmin = $this->isActiveSuperAdminFromDb($actor);
        $actorPermissions = $this->getUserEffectivePermissionsFromDb($actor);

        if (! $actorIsSuperAdmin && ! in_array('ROLE:ASSIGN_PERMISSION', $actorPermissions, true)) {
            return $this->forbiddenResponse('Bạn không có quyền khôi phục phân quyền chi tiết của người dùng (yêu cầu quyền ROLE:ASSIGN_PERMISSION).');
        }

        if (! $actorIsSuperAdmin && (string) $actor->id === (string) $user->id) {
            return $this->forbiddenResponse('Bạn không được phép tự khôi phục hoặc thay đổi quyền hạn của chính mình.');
        }

        if (! $actorIsSuperAdmin && $this->userIsSuperAdminFromDb($user)) {
            return $this->forbiddenResponse('Chỉ Quản trị viên cấp cao (SUPER_ADMIN) mới có quyền thay đổi quyền hạn của tài khoản SUPER_ADMIN.');
        }

        if (! $actorIsSuperAdmin) {
            $basePermissions = $this->getUserBaseRolePermissionsFromDb($user);
            foreach ($basePermissions as $basePerm) {
                if ($this->isPrivilegedPermissionCode($basePerm) || ! in_array($basePerm, $actorPermissions, true)) {
                    return $this->forbiddenResponse("Bạn không có quyền khôi phục vai trò mặc định chứa quyền hạn '{$basePerm}' vượt quá phạm vi quyền hiện tại.");
                }
            }
        }

        DB::transaction(function () use ($user): void {
            $customRoleCode = self::CUSTOM_USER_ROLE_PREFIX.substr(str_replace('-', '', $user->id), 0, 12);
            $role = Role::where('role_code', $customRoleCode)->first();

            if ($role) {
                DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->where('role_id', $role->id)
                    ->delete();

                DB::table('role_permissions')->where('role_id', $role->id)->delete();
                $role->delete();
            }
        });

        $user->clearPermissionCache();
        $user->unsetRelation('roles');

        QuocTinRealtimeService::emit('rbac', 'user_permission', 'RESET', $id, ['user_id' => $id]);

        return response()->json([
            'success' => true,
            'message' => "Đã khôi phục quyền mặc định theo vai trò cho tài khoản {$user->full_name}.",
            'data' => [
                'user_id' => $user->id,
                'username' => $user->username,
                'is_customized' => false,
                'permissions' => $user->getAllPermissions(),
            ],
        ]);
    }
}
