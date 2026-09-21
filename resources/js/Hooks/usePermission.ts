import { useState, useEffect, useCallback } from 'react';

export interface UserPermissionState {
  id?: string;
  username?: string;
  email?: string;
  role?: string;
  roles?: string[];
  role_codes?: string[];
  permissions?: string[];
  is_super_admin?: boolean;
}

/**
 * Đọc quyền hạn hiện tại từ LocalStorage một cách an toàn
 */
export function getCurrentUserPermissions(): {
  permissions: string[];
  roles: string[];
  isSuperAdmin: boolean;
  user: any;
} {
  try {
    const rawUser = localStorage.getItem('smart_cassavas_user');
    const user = rawUser ? JSON.parse(rawUser) : null;

    const rawSession = localStorage.getItem('smartcassavas_session');
    const session = rawSession ? JSON.parse(rawSession) : null;

    const isSuperAdmin = Boolean(
      user?.is_super_admin ||
      user?.role_codes?.includes('SUPER_ADMIN') ||
      user?.role_codes?.includes('SUPER_ADMI') ||
      user?.roles?.includes('admin') ||
      session?.role === 'admin'
    );

    const permissions: string[] = Array.isArray(user?.permissions) ? user.permissions : [];
    const roles: string[] = Array.isArray(user?.role_codes)
      ? user.role_codes
      : Array.isArray(user?.roles)
      ? user.roles
      : session?.role
      ? [session.role]
      : [];

    return { permissions, roles, isSuperAdmin, user };
  } catch {
    return { permissions: [], roles: [], isSuperAdmin: false, user: null };
  }
}

/**
 * Helper kiểm tra quyền độc lập ngoài React Component
 */
export function can(permission: string): boolean {
  const { permissions, isSuperAdmin } = getCurrentUserPermissions();

  if (isSuperAdmin) {
    return true;
  }

  const cleanPerm = permission.trim().toUpperCase();

  // Hỗ trợ kiểm tra nhiều quyền bằng dấu gạch đứng | (OR logic)
  if (cleanPerm.includes('|')) {
    const parts = cleanPerm.split('|').map((p) => p.trim());
    return parts.some((p) => permissions.includes(p));
  }

  return permissions.includes(cleanPerm);
}

/**
 * Helper kiểm tra vai trò người dùng
 */
export function hasRole(roleCode: string): boolean {
  const { roles, isSuperAdmin } = getCurrentUserPermissions();
  const cleanRole = roleCode.trim().toUpperCase();

  if (cleanRole === 'SUPER_ADMIN' || cleanRole === 'ADMIN') {
    return isSuperAdmin;
  }

  return roles.some((r) => r.trim().toUpperCase() === cleanRole);
}

/**
 * React Hook kiểm soát phân quyền RBAC
 */
export function usePermission() {
  const [state, setState] = useState(getCurrentUserPermissions);

  const refreshPermissions = useCallback(() => {
    setState(getCurrentUserPermissions());
  }, []);

  useEffect(() => {
    const handleStorageChange = () => {
      refreshPermissions();
    };

    window.addEventListener('storage', handleStorageChange);
    return () => window.removeEventListener('storage', handleStorageChange);
  }, [refreshPermissions]);

  const checkCan = useCallback(
    (permission: string): boolean => {
      if (state.isSuperAdmin) {
        return true;
      }
      const cleanPerm = permission.trim().toUpperCase();
      if (cleanPerm.includes('|')) {
        const parts = cleanPerm.split('|').map((p) => p.trim());
        return parts.some((p) => state.permissions.includes(p));
      }
      return state.permissions.includes(cleanPerm);
    },
    [state]
  );

  const checkHasRole = useCallback(
    (roleCode: string): boolean => {
      const cleanRole = roleCode.trim().toUpperCase();
      if (cleanRole === 'SUPER_ADMIN' || cleanRole === 'ADMIN') {
        return state.isSuperAdmin;
      }
      return state.roles.some((r) => r.trim().toUpperCase() === cleanRole);
    },
    [state]
  );

  return {
    can: checkCan,
    hasRole: checkHasRole,
    isSuperAdmin: state.isSuperAdmin,
    permissions: state.permissions,
    roles: state.roles,
    currentUser: state.user,
    refreshPermissions,
  };
}

export default usePermission;
