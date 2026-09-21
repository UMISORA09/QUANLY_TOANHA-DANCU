<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Bỏ qua kiểm tra đối với SUPER_ADMIN
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * Xem danh sách người dùng
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('USER:VIEW');
    }

    /**
     * Xem thông tin chi tiết một người dùng cụ thể
     */
    public function view(User $user, User $model): bool
    {
        // Người dùng luôn có thể xem thông tin của chính mình
        if ($user->id === $model->id) {
            return true;
        }

        return $user->hasPermission('USER:VIEW');
    }

    /**
     * Tạo tài khoản người dùng mới
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('USER:CREATE');
    }

    /**
     * Cập nhật thông tin tài khoản người dùng
     */
    public function update(User $user, User $model): bool
    {
        // Người dùng được cập nhật thông tin cá nhân của chính mình
        if ($user->id === $model->id) {
            return true;
        }

        return $user->hasPermission('USER:UPDATE');
    }

    /**
     * Xóa tài khoản người dùng
     */
    public function delete(User $user, User $model): bool
    {
        // Không được phép tự xóa tài khoản của chính mình
        if ($user->id === $model->id) {
            return false;
        }

        return $user->hasPermission('USER:DELETE');
    }

    /**
     * Gán vai trò cho người dùng
     */
    public function assignRole(User $user, User $model): bool
    {
        // Không được tự nâng quyền cho chính mình
        if ($user->id === $model->id && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermission('USER:ASSIGN_ROLE') || $user->hasPermission('USER:UPDATE');
    }
}
