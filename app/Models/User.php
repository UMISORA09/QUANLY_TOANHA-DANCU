<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'username',
        'phone_number',
        'email',
        'password_hash',
        'full_name',
        'avatar_url',
        'gender',
        'date_of_birth',
        'national_id_number',
        'status',
        'mfa_enabled',
        'mfa_secret',
        'last_login_at',
        'last_login_ip',
        'failed_login_attempts',
        'lockout_until',
        'fcm_device_token',
        'extra_preferences',
    ];

    protected $hidden = [
        'password_hash',
        'mfa_secret',
    ];

    /**
     * Cache quyền hạn trong phiên request để tối ưu hiệu năng
     *
     * @var array<string>|null
     */
    protected ?array $cachedPermissions = null;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'mfa_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'lockout_until' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'extra_preferences' => 'array',
        ];
    }

    /**
     * Lấy mật khẩu để Laravel Auth kiểm tra
     */
    public function getAuthPassword(): string
    {
        return (string) ($this->password_hash ?? '');
    }

    /**
     * Danh sách vai trò được gán cho người dùng
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_roles',
            'user_id',
            'role_id'
        )->withPivot(['is_primary', 'assigned_at', 'assigned_by']);
    }

    /**
     * Kiểm tra người dùng có một hoặc nhiều vai trò cụ thể không
     *
     * @param  string|array<string>  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $roleList = is_array($roles) ? $roles : [$roles];

        $userRoleCodes = $this->roles->pluck('role_code')->toArray();

        foreach ($roleList as $r) {
            if (in_array(strtoupper(trim($r)), $userRoleCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kiểm tra có phải Quản trị viên cấp cao (SUPER_ADMIN)
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(['SUPER_ADMIN', 'SUPER_ADMI']);
    }

    /**
     * Lấy toàn bộ mã quyền hạn (permission_code) của người dùng từ các vai trò
     *
     * @return array<string>
     */
    public function getAllPermissions(): array
    {
        if ($this->cachedPermissions !== null) {
            return $this->cachedPermissions;
        }

        if ($this->isSuperAdmin()) {
            $this->cachedPermissions = Permission::pluck('permission_code')->toArray();

            return $this->cachedPermissions;
        }

        // Tải các quyền từ các vai trò chưa bị xóa
        $permissions = Permission::query()
            ->join('role_permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('user_roles', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $this->id)
            ->pluck('permissions.permission_code')
            ->unique()
            ->values()
            ->toArray();

        $this->cachedPermissions = $permissions;

        return $this->cachedPermissions;
    }

    /**
     * Kiểm tra người dùng có quyền cụ thể hay không (hasPermission)
     */
    public function hasPermission(string $permission): bool
    {
        // SUPER_ADMIN có toàn quyền hệ thống
        if ($this->isSuperAdmin()) {
            return true;
        }

        $allPerms = $this->getAllPermissions();

        return in_array(strtoupper(trim($permission)), $allPerms, true);
    }

    /**
     * Gán vai trò cho người dùng (idempotent)
     */
    public function assignRole(Role|string $role, bool $isPrimary = false, ?string $assignedBy = null): void
    {
        $roleModel = is_string($role)
            ? Role::where('role_code', strtoupper(trim($role)))->firstOrFail()
            : $role;

        $exists = $this->roles()->where('role_id', $roleModel->id)->exists();

        if (! $exists) {
            $this->roles()->attach($roleModel->id, [
                'id' => (string) Str::uuid(),
                'is_primary' => $isPrimary,
                'assigned_at' => now(),
                'assigned_by' => $assignedBy,
            ]);
        }

        $this->cachedPermissions = null;
    }

    /**
     * Thu hồi vai trò khỏi người dùng
     */
    public function removeRole(Role|string $role): void
    {
        $roleModel = is_string($role)
            ? Role::where('role_code', strtoupper(trim($role)))->first()
            : $role;

        if ($roleModel) {
            $this->roles()->detach($roleModel->id);
        }

        $this->cachedPermissions = null;
    }
}
