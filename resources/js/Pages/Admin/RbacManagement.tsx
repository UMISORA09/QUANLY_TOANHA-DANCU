import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
  Shield,
  ShieldAlert,
  ShieldCheck,
  Users,
  Key,
  Lock,
  Unlock,
  Plus,
  Search,
  CheckCircle2,
  AlertTriangle,
  X,
  Edit2,
  Trash2,
  UserCheck,
  Sliders,
  Check,
  Loader2,
  RefreshCw,
  Eye,
  Filter,
  Sparkles,
  Download,
  Upload,
  LayoutGrid,
  Layers,
} from 'lucide-react';
import api, { UserRbac, RoleRbac, PermissionRbac } from '../../Services/api';
import { usePermission } from '../../Hooks/usePermission';
import { useRealtimeSync, useModuleCooldown, emitLocalRealtimeEvent } from '../../Hooks/useRealtimeSync';
import { CooldownBanner } from '../../Components/Realtime/CooldownBanner';
import { CustomSelect, SelectOption } from '../../Components/Common/CustomSelect';

interface RbacManagementProps {
  embedded?: boolean;
}

export const RbacManagement: React.FC<RbacManagementProps> = ({ embedded = false }) => {
  const { can, isSuperAdmin } = usePermission();
  const { isCooldownActive, remainingSeconds, message: cooldownMessage, startCooldown } = useModuleCooldown('rbac');

  // Active Tab: Mặc định mở ngay tab 'matrix' (Ma trận phân quyền 7 quyền) hoặc đọc từ query param
  const getInitialActiveTab = (): 'users' | 'roles' | 'matrix' | 'permissions' => {
    try {
      const params = new URLSearchParams(window.location.search);
      const sub = params.get('subtab') || params.get('rbac_tab');
      if (sub === 'matrix' || sub === 'roles' || sub === 'permissions' || sub === 'users') {
        return sub;
      }
    } catch {
      // ignore
    }
    return 'matrix';
  };
  const [activeTab, setActiveTab] = useState<'users' | 'roles' | 'matrix' | 'permissions'>(getInitialActiveTab);

  // Đọc snapshot lưu trong sessionStorage để hiển thị tức thì (0ms) cho lần tải thứ 2
  const getCachedRbacData = () => {
    try {
      sessionStorage.removeItem('smartcassavas_rbac_cache'); // dọn cache cũ
      const raw = sessionStorage.getItem('smartcassavas_rbac_cache_v2');
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  };
  const cachedRbac = getCachedRbacData();

  // Common Data State
  const [users, setUsers] = useState<UserRbac[]>(() => (Array.isArray(cachedRbac?.users) ? cachedRbac.users : []));
  const [roles, setRoles] = useState<RoleRbac[]>(() => (Array.isArray(cachedRbac?.roles) ? cachedRbac.roles : []));
  const [permissionsGrouped, setPermissionsGrouped] = useState<Record<string, PermissionRbac[]>>(() => (cachedRbac?.permissionsGrouped && typeof cachedRbac.permissionsGrouped === 'object' && !Array.isArray(cachedRbac.permissionsGrouped) ? cachedRbac.permissionsGrouped : {}));
  const [permissionsList, setPermissionsList] = useState<PermissionRbac[]>(() => (Array.isArray(cachedRbac?.permissionsList) ? cachedRbac.permissionsList : []));

  // Loading & Feedback
  const [loading, setLoading] = useState<boolean>(() => !cachedRbac);
  const [saving, setSaving] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'error' | 'info' } | null>(null);

  // Filters & Search
  const [userSearch, setUserSearch] = useState<string>('');
  const [userRoleFilter, setUserRoleFilter] = useState<string>('');
  const [userStatusFilter, setUserStatusFilter] = useState<string>('');
  const [permissionSearch, setPermissionSearch] = useState<string>('');
  const [permissionModuleFilter, setPermissionModuleFilter] = useState<string>('');

  // Selected for Modals / Matrix
  const [matrixTargetType, setMatrixTargetType] = useState<'role' | 'user'>('role');
  const [selectedRoleForMatrix, setSelectedRoleForMatrix] = useState<RoleRbac | null>(null);
  const [selectedUserForMatrix, setSelectedUserForMatrix] = useState<UserRbac | null>(null);
  const [isUserCustomized, setIsUserCustomized] = useState<boolean>(false);
  const [matrixPermissions, setMatrixPermissions] = useState<string[]>([]);
  const [matrixViewMode, setMatrixViewMode] = useState<'table' | 'cards'>('table');

  // Modals state
  const [userModalOpen, setUserModalOpen] = useState<boolean>(false);
  const [editingUser, setEditingUser] = useState<UserRbac | null>(null);
  const [userForm, setUserForm] = useState({
    username: '',
    phone_number: '',
    email: '',
    full_name: '',
    password: '',
    status: 'ACTIVE',
    roles: [] as string[],
  });

  const [roleModalOpen, setRoleModalOpen] = useState<boolean>(false);
  const [editingRole, setEditingRole] = useState<RoleRbac | null>(null);
  const [roleForm, setRoleForm] = useState({
    role_code: '',
    role_name: '',
    description: '',
  });

  const [permissionModalOpen, setPermissionModalOpen] = useState<boolean>(false);
  const [editingPermission, setEditingPermission] = useState<PermissionRbac | null>(null);
  const [permissionForm, setPermissionForm] = useState({
    module: 'USER',
    permission_code: '',
    permission_name: '',
    description: '',
  });

  const [assignRoleModalOpen, setAssignRoleModalOpen] = useState<boolean>(false);
  const [userForAssignRole, setUserForAssignRole] = useState<UserRbac | null>(null);
  const [selectedRolesForUser, setSelectedRolesForUser] = useState<string[]>([]);

  const [deleteConfirm, setDeleteConfirm] = useState<{
    open: boolean;
    type: 'user' | 'role' | 'permission';
    id: string;
    title: string;
    description: string;
  } | null>(null);

  const showToast = useCallback((text: string, type: 'success' | 'error' | 'info' = 'success') => {
    setToastMessage({ text, type });
    setTimeout(() => setToastMessage(null), 3500);
  }, []);

  // Fetch initial data
  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [rolesRes, permsRes, usersRes] = await Promise.all([
        api.getRoles(),
        api.getPermissions({ grouped: true }),
        api.getUsers({ limit: 50 }),
      ]);

      if (rolesRes.success) {
        setRoles(rolesRes.data);
        if (!selectedRoleForMatrix && rolesRes.data.length > 0) {
          const defaultRole = rolesRes.data.find((r) => r.role_code === 'BUILDING_MANAGER') || rolesRes.data[0];
          setSelectedRoleForMatrix(defaultRole);
        }
      }

      if (permsRes.success) {
        const grouped = permsRes.data as Record<string, PermissionRbac[]>;
        setPermissionsGrouped(grouped);
        const flat = Object.values(grouped).flat();
        setPermissionsList(flat);
      }

      if (usersRes.success) {
        setUsers(usersRes.data);
        if (!selectedUserForMatrix && usersRes.data.length > 0) {
          try {
            const params = new URLSearchParams(window.location.search);
            const uid = params.get('user_id');
            const foundU = uid ? usersRes.data.find((u) => u.id === uid) : null;
            if (foundU) {
              setSelectedUserForMatrix(foundU);
              setMatrixTargetType('user');
            } else {
              setSelectedUserForMatrix(usersRes.data[0]);
            }
          } catch {
            setSelectedUserForMatrix(usersRes.data[0]);
          }
        }
      }

      if (rolesRes.success && permsRes.success && usersRes.success) {
        try {
          sessionStorage.setItem('smartcassavas_rbac_cache_v2', JSON.stringify({
            roles: rolesRes.data,
            permissionsGrouped: permsRes.data,
            permissionsList: Object.values(permsRes.data as Record<string, PermissionRbac[]>).flat(),
            users: usersRes.data,
          }));
        } catch {
          // ignore storage error
        }
      }
    } catch (err: any) {
      showToast(err.message || 'Không thể tải dữ liệu phân quyền', 'error');
    } finally {
      setLoading(false);
    }
  }, [selectedRoleForMatrix, showToast]);

  useEffect(() => {
    fetchData();
  }, []);

  // Realtime Auto-Sync Listener (Quốc Tín - RBAC)
  useRealtimeSync({
    channel: 'quoc-tin.rbac',
    onEvent: (event) => {
      // 0. Cập nhật state in-memory ngay lập tức nếu là xóa
      if (event.action === 'DELETED' && event.entity_id) {
        if (event.entity === 'user') {
          setUsers((prev) => prev.filter((u) => u.id !== event.entity_id));
        } else if (event.entity === 'role') {
          setRoles((prev) => prev.filter((r) => r.id !== event.entity_id));
        } else if (event.entity === 'permission') {
          setPermissionsList((prev) => prev.filter((p) => p.id !== event.entity_id));
        }
      }

      // 1. Invalidate cache để tránh stale data
      try {
        sessionStorage.removeItem('smartcassavas_rbac_cache');
      } catch {
        // ignore
      }

      // 2. Refetch RBAC data
      fetchData();

      // 3. Nếu đang xem matrix của đúng vai trò hoặc tài khoản vừa bị đổi quyền, reload quyền tương ứng
      if (matrixTargetType === 'role' && selectedRoleForMatrix && selectedRoleForMatrix.id === event.entity_id) {
        api.getRolePermissions(selectedRoleForMatrix.id)
          .then((res) => {
            if (res.success && res.data.permissions) {
              setMatrixPermissions(res.data.permissions.map((p) => p.permission_code));
            }
          })
          .catch(() => {});
      } else if (matrixTargetType === 'user' && selectedUserForMatrix && selectedUserForMatrix.id === event.entity_id) {
        api.getUserPermissions(selectedUserForMatrix.id)
          .then((res) => {
            if (res.success && res.data) {
              setMatrixPermissions(res.data.permissions || []);
              setIsUserCustomized(Boolean(res.data.is_customized));
            }
          })
          .catch(() => {});
      }
    },
    onReconnect: () => {
      fetchData();
    },
  });

  // Khi chọn vai trò hoặc đổi chế độ sang vai trò, load quyền của vai trò đó
  useEffect(() => {
    if (matrixTargetType !== 'role' || !selectedRoleForMatrix) return;
    const loadRolePerms = async () => {
      try {
        const res = await api.getRolePermissions(selectedRoleForMatrix.id);
        if (res.success && res.data.permissions) {
          setMatrixPermissions(res.data.permissions.map((p) => p.permission_code));
        }
      } catch (err: any) {
        showToast(err.message || 'Không thể tải quyền của vai trò', 'error');
      }
    };
    loadRolePerms();
  }, [matrixTargetType, selectedRoleForMatrix, showToast]);

  // Khi chọn tài khoản hoặc đổi chế độ sang tài khoản, load quyền thực tế của tài khoản đó
  useEffect(() => {
    if (matrixTargetType !== 'user' || !selectedUserForMatrix) return;
    const loadUserPerms = async () => {
      try {
        const res = await api.getUserPermissions(selectedUserForMatrix.id);
        if (res.success && res.data) {
          setMatrixPermissions(res.data.permissions || []);
          setIsUserCustomized(Boolean(res.data.is_customized));
        }
      } catch (err: any) {
        showToast(err.message || 'Không thể tải quyền của tài khoản', 'error');
      }
    };
    loadUserPerms();
  }, [matrixTargetType, selectedUserForMatrix, showToast]);

  // Filtered Users
  const filteredUsers = useMemo(() => {
    return (Array.isArray(users) ? users : []).filter((u) => {
      if (!u) return false;
      const s = (userSearch || '').toLowerCase();
      const matchesSearch =
        !s ||
        (u.full_name || '').toLowerCase().includes(s) ||
        (u.username || '').toLowerCase().includes(s) ||
        (u.email || '').toLowerCase().includes(s) ||
        (u.phone_number || '').includes(s);

      const matchesRole =
        !userRoleFilter ||
        (Array.isArray(u.roles) && u.roles.some((r) => r && (r.role_code === userRoleFilter || r.id === userRoleFilter)));

      const matchesStatus = !userStatusFilter || u.status === userStatusFilter;

      return matchesSearch && matchesRole && matchesStatus;
    });
  }, [users, userSearch, userRoleFilter, userStatusFilter]);

  // Modules list
  const moduleKeys = useMemo(() => {
    if (!permissionsGrouped || typeof permissionsGrouped !== 'object') return [];
    return Object.keys(permissionsGrouped);
  }, [permissionsGrouped]);

  // Filtered Permissions
  const filteredPermissions = useMemo(() => {
    return (Array.isArray(permissionsList) ? permissionsList : []).filter((p) => {
      if (!p) return false;
      const code = (p.permission_code || '').toLowerCase();
      const name = (p.permission_name || '').toLowerCase();
      const desc = (p.description || '').toLowerCase();
      const s = (permissionSearch || '').toLowerCase();

      const matchesSearch = !s || code.includes(s) || name.includes(s) || desc.includes(s);
      const matchesModule = !permissionModuleFilter || p.module === permissionModuleFilter;

      return matchesSearch && matchesModule;
    });
  }, [permissionsList, permissionSearch, permissionModuleFilter]);

  // Handle Save Matrix Permissions (Vai trò hoặc Tài khoản)
  const handleSaveMatrix = async () => {
    if (matrixTargetType === 'user') {
      if (!selectedUserForMatrix) return;
      setSaving(true);
      try {
        const res = await api.syncUserPermissions(selectedUserForMatrix.id, matrixPermissions);
        if (res.success) {
          setIsUserCustomized(true);
          showToast(`Đã lưu thành công ${matrixPermissions.length} quyền cho tài khoản ${selectedUserForMatrix.full_name || selectedUserForMatrix.username}`);
          emitLocalRealtimeEvent({
            module: 'rbac',
            entity: 'user_permission',
            action: 'UPDATED',
            entity_id: selectedUserForMatrix.id,
          });
          const usersRes = await api.getUsers({ limit: 50 });
          if (usersRes.success) setUsers(usersRes.data);
        }
      } catch (err: any) {
        showToast(err.message || 'Lỗi lưu cấu hình quyền tài khoản', 'error');
      } finally {
        setSaving(false);
      }
      return;
    }

    if (!selectedRoleForMatrix) return;
    setSaving(true);
    try {
      const res = await api.syncRolePermissions(selectedRoleForMatrix.id, matrixPermissions);
      if (res.success) {
        showToast(`Đã lưu ${matrixPermissions.length} quyền cho vai trò ${selectedRoleForMatrix.role_name}`);
        // Refresh roles to update permissions count
        const rolesRes = await api.getRoles();
        if (rolesRes.success) setRoles(rolesRes.data);
      }
    } catch (err: any) {
      showToast(err.message || 'Lỗi lưu cấu hình quyền', 'error');
    } finally {
      setSaving(false);
    }
  };

  // Handle Reset User Permissions back to role defaults
  const handleResetUserMatrix = async () => {
    if (!selectedUserForMatrix) return;
    setSaving(true);
    try {
      const res = await api.resetUserPermissions(selectedUserForMatrix.id);
      if (res.success) {
        setIsUserCustomized(false);
        setMatrixPermissions(res.data.permissions || []);
        showToast(res.message || 'Đã khôi phục quyền mặc định theo vai trò cho tài khoản');
        emitLocalRealtimeEvent({
          module: 'rbac',
          entity: 'user_permission',
          action: 'RESET',
          entity_id: selectedUserForMatrix.id,
        });
        const usersRes = await api.getUsers({ limit: 50 });
        if (usersRes.success) setUsers(usersRes.data);
      }
    } catch (err: any) {
      showToast(err.message || 'Lỗi khi khôi phục quyền mặc định', 'error');
    } finally {
      setSaving(false);
    }
  };

  // Toggle single permission in matrix
  const toggleMatrixPermission = (code: string) => {
    setMatrixPermissions((prev) =>
      prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code]
    );
  };

  // Toggle all permissions in a module
  const toggleModulePermissions = (moduleName: string) => {
    const modPerms = permissionsGrouped[moduleName] || [];
    const modCodes = modPerms.map((p) => p.permission_code);
    const allSelected = modCodes.every((c) => matrixPermissions.includes(c));

    if (allSelected) {
      setMatrixPermissions((prev) => prev.filter((c) => !modCodes.includes(c)));
    } else {
      setMatrixPermissions((prev) => Array.from(new Set([...prev, ...modCodes])));
    }
  };

  // Select all / Deselect all in matrix
  const handleSelectAllMatrix = () => {
    setMatrixPermissions(permissionsList.map((p) => p.permission_code));
  };
  const handleDeselectAllMatrix = () => {
    setMatrixPermissions([]);
  };

  // 7 Nhóm Quyền RBAC Chuẩn Hóa (Bao gồm đầy đủ 7 quyền: Thêm, Xóa, Sửa, Tải về, Tải lên, Xem, Người đặc biệt)
  const CORE_7_PERMISSIONS = useMemo(() => [
    {
      key: 'create' as const,
      num: 1,
      title: 'Thêm',
      codeKey: 'create',
      badgeText: '1. Thêm (create)',
      description: 'Thêm mới bản ghi, tạo đối tượng trong module',
      badgeStyle: 'bg-emerald-50 text-emerald-700 border-emerald-200/90',
      headerIcon: Plus,
    },
    {
      key: 'delete' as const,
      num: 2,
      title: 'Xóa',
      codeKey: 'delete',
      badgeText: '2. Xóa (delete)',
      description: 'Xóa, hủy hoặc vô hiệu hóa bản ghi',
      badgeStyle: 'bg-rose-50 text-rose-700 border-rose-200/90',
      headerIcon: Trash2,
    },
    {
      key: 'edit' as const,
      num: 3,
      title: 'Sửa',
      codeKey: 'edit',
      badgeText: '3. Sửa (edit)',
      description: 'Chỉnh sửa, cập nhật thông tin trong module',
      badgeStyle: 'bg-amber-50 text-amber-700 border-amber-200/90',
      headerIcon: Edit2,
    },
    {
      key: 'download' as const,
      num: 4,
      title: 'Tải về',
      codeKey: 'download',
      badgeText: '4. Tải về (download)',
      description: 'Tải xuống, xuất dữ liệu Excel/PDF/CSV',
      badgeStyle: 'bg-indigo-50 text-indigo-700 border-indigo-200/90',
      headerIcon: Download,
    },
    {
      key: 'upload' as const,
      num: 5,
      title: 'Tải lên',
      codeKey: 'upload',
      badgeText: '5. Tải lên (upload)',
      description: 'Tải lên tệp tin, nhập dữ liệu hàng loạt',
      badgeStyle: 'bg-teal-50 text-teal-700 border-teal-200/90',
      headerIcon: Upload,
    },
    {
      key: 'view' as const,
      num: 6,
      title: 'Xem',
      codeKey: 'view',
      badgeText: '6. Xem (view)',
      description: 'Tra cứu, xem danh sách và chi tiết dữ liệu',
      badgeStyle: 'bg-sky-50 text-sky-700 border-sky-200/90',
      headerIcon: Eye,
    },
    {
      key: 'special' as const,
      num: 7,
      title: 'Người đặc biệt',
      codeKey: 'special',
      badgeText: '7. Người đặc biệt (special)',
      description: 'Quyền người đặc biệt với thẩm quyền quản trị đặc thù',
      badgeStyle: 'bg-purple-100 text-purple-800 border-purple-300 font-bold ring-1 ring-purple-400/40',
      headerIcon: Sparkles,
    },
  ], []);

  // Helper tìm quyền tương ứng với 1 trong 7 hành động chuẩn trong module
  const findModulePermissionForAction = useCallback((modPerms: PermissionRbac[], actionKey: string): PermissionRbac | undefined => {
    switch (actionKey) {
      case 'create':
        return modPerms.find((p) => p.permission_code.endsWith(':CREATE') || p.permission_code === 'CREATE' || p.permission_code.includes('CREATE'));
      case 'delete':
        return modPerms.find((p) => p.permission_code.endsWith(':DELETE') || p.permission_code === 'DELETE' || p.permission_code.includes('DELETE'));
      case 'edit':
        return modPerms.find((p) => p.permission_code.endsWith(':UPDATE') || p.permission_code === 'UPDATE' || p.permission_code.includes('UPDATE') || p.permission_code.includes('EDIT'));
      case 'download':
        return modPerms.find((p) => p.permission_code.endsWith(':DOWNLOAD') || p.permission_code === 'DOWNLOAD' || p.permission_code.includes('DOWNLOAD') || p.permission_code.endsWith(':EXPORT') || p.permission_code.includes('EXPORT'));
      case 'upload':
        return modPerms.find((p) => p.permission_code.endsWith(':UPLOAD') || p.permission_code === 'UPLOAD' || p.permission_code.includes('UPLOAD') || p.permission_code.endsWith(':IMPORT') || p.permission_code.includes('IMPORT'));
      case 'view':
        return modPerms.find((p) => p.permission_code.endsWith(':VIEW') || p.permission_code === 'VIEW' || p.permission_code.includes('VIEW') || p.permission_code.includes('READ'));
      case 'special':
        return modPerms.find((p) => p.permission_code.endsWith(':SPECIAL') || p.permission_code === 'SPECIAL' || p.permission_code.includes('SPECIAL'));
      default:
        return undefined;
    }
  }, []);

  // Helper lấy các quyền nghiệp vụ mở rộng ngoài 7 quyền chuẩn (ví dụ ASSIGN_ROLE, APPROVE, ...)
  const getModuleExtendedPermissions = useCallback((modPerms: PermissionRbac[]): PermissionRbac[] => {
    const matchedCodes = new Set(
      CORE_7_PERMISSIONS.map((action) => findModulePermissionForAction(modPerms, action.key)?.permission_code).filter(Boolean)
    );
    return modPerms.filter((p) => !matchedCodes.has(p.permission_code));
  }, [CORE_7_PERMISSIONS, findModulePermissionForAction]);

  // Bật/tắt tất cả quyền của 1 cột hành động trên toàn bộ các module
  const toggleColumnPermissions = useCallback((actionKey: string) => {
    const allActionCodes = moduleKeys
      .map((m) => findModulePermissionForAction(permissionsGrouped[m] || [], actionKey)?.permission_code)
      .filter((code): code is string => Boolean(code));

    if (allActionCodes.length === 0) return;

    const allSelected = allActionCodes.every((c) => matrixPermissions.includes(c));
    if (allSelected) {
      setMatrixPermissions((prev) => prev.filter((c) => !allActionCodes.includes(c)));
    } else {
      setMatrixPermissions((prev) => Array.from(new Set([...prev, ...allActionCodes])));
    }
  }, [moduleKeys, permissionsGrouped, matrixPermissions, findModulePermissionForAction]);

  // User Save Action
  const handleSaveUser = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (editingUser) {
        const payload: any = {
          full_name: userForm.full_name,
          phone_number: userForm.phone_number,
          email: userForm.email,
          status: userForm.status,
          roles: userForm.roles,
        };
        if (userForm.password) payload.password = userForm.password;

        const res = await api.updateUser(editingUser.id, payload);
        if (res.success) {
          showToast('Cập nhật tài khoản người dùng thành công');
          setUserModalOpen(false);
          const usersRes = await api.getUsers({ limit: 50 });
          if (usersRes.success) setUsers(usersRes.data);
        }
      } else {
        const res = await api.createUser(userForm);
        if (res.success) {
          showToast('Tạo mới người dùng thành công');
          setUserModalOpen(false);
          const usersRes = await api.getUsers({ limit: 50 });
          if (usersRes.success) setUsers(usersRes.data);
        }
      }
    } catch (err: any) {
      showToast(err.message || 'Lỗi lưu thông tin người dùng', 'error');
    } finally {
      setSaving(false);
    }
  };

  // Role Save Action
  const handleSaveRole = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (editingRole) {
        const res = await api.updateRole(editingRole.id, roleForm);
        if (res.success) {
          showToast(`Cập nhật vai trò ${editingRole.role_name} thành công`);
          setRoleModalOpen(false);
          const rolesRes = await api.getRoles();
          if (rolesRes.success) setRoles(rolesRes.data);
        }
      } else {
        const res = await api.createRole(roleForm);
        if (res.success) {
          showToast(`Tạo vai trò ${roleForm.role_name} thành công`);
          setRoleModalOpen(false);
          const rolesRes = await api.getRoles();
          if (rolesRes.success) setRoles(rolesRes.data);
        }
      }
    } catch (err: any) {
      showToast(err.message || 'Lỗi lưu vai trò', 'error');
    } finally {
      setSaving(false);
    }
  };

  // Permission Save Action
  const handleSavePermission = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (editingPermission) {
        const res = await api.updatePermission(editingPermission.id, permissionForm);
        if (res.success) {
          showToast(`Cập nhật quyền hạn ${editingPermission.permission_code} thành công`);
          setPermissionModalOpen(false);
          const permsRes = await api.getPermissions({ grouped: true });
          if (permsRes.success) {
            setPermissionsGrouped(permsRes.data as Record<string, PermissionRbac[]>);
            setPermissionsList(Object.values(permsRes.data as Record<string, PermissionRbac[]>).flat());
          }
        }
      } else {
        const res = await api.createPermission(permissionForm);
        if (res.success) {
          showToast(`Tạo quyền hạn ${permissionForm.permission_code} thành công`);
          setPermissionModalOpen(false);
          const permsRes = await api.getPermissions({ grouped: true });
          if (permsRes.success) {
            setPermissionsGrouped(permsRes.data as Record<string, PermissionRbac[]>);
            setPermissionsList(Object.values(permsRes.data as Record<string, PermissionRbac[]>).flat());
          }
        }
      }
    } catch (err: any) {
      showToast(err.message || 'Lỗi lưu quyền hạn', 'error');
    } finally {
      setSaving(false);
    }
  };

  // Assign roles for user
  const handleSaveAssignRoles = async () => {
    if (!userForAssignRole) return;
    setSaving(true);
    try {
      const res = await api.assignUserRoles(userForAssignRole.id, selectedRolesForUser);
      if (res.success) {
        showToast(`Đã gán vai trò cho người dùng ${userForAssignRole.full_name}`);
        setAssignRoleModalOpen(false);
        const usersRes = await api.getUsers({ limit: 50 });
        if (usersRes.success) setUsers(usersRes.data);
      }
    } catch (err: any) {
      showToast(err.message || 'Lỗi gán vai trò', 'error');
    } finally {
      setSaving(false);
    }
  };

  // Confirm delete handler
  const handleConfirmDelete = async () => {
    if (!deleteConfirm) return;
    setSaving(true);
    try {
      if (deleteConfirm.type === 'user') {
        await api.deleteUser(deleteConfirm.id);
        showToast('Đã xóa tài khoản người dùng thành công');
        setUsers((prev) => prev.filter((u) => u.id !== deleteConfirm.id));
      } else if (deleteConfirm.type === 'role') {
        await api.deleteRole(deleteConfirm.id);
        showToast('Đã xóa vai trò thành công');
        setRoles((prev) => prev.filter((r) => r.id !== deleteConfirm.id));
      } else if (deleteConfirm.type === 'permission') {
        await api.deletePermission(deleteConfirm.id);
        showToast('Đã xóa quyền hạn thành công');
        const permsRes = await api.getPermissions({ grouped: true });
        if (permsRes.success) {
          setPermissionsGrouped(permsRes.data as Record<string, PermissionRbac[]>);
          setPermissionsList(Object.values(permsRes.data as Record<string, PermissionRbac[]>).flat());
        }
      }
      setDeleteConfirm(null);
    } catch (err: any) {
      showToast(err.message || 'Lỗi khi xóa đối tượng', 'error');
    } finally {
      setSaving(false);
    }
  };

  // Toggle user status active/locked
  const handleToggleUserStatus = async (user: UserRbac) => {
    const nextStatus = user.status === 'ACTIVE' ? 'LOCKED' : 'ACTIVE';
    try {
      const res = await api.updateUser(user.id, { status: nextStatus });
      if (res.success) {
        showToast(`Đã ${nextStatus === 'ACTIVE' ? 'mở khóa' : 'khóa'} tài khoản ${user.full_name}`);
        setUsers((prev) =>
          prev.map((u) => (u.id === user.id ? { ...u, status: nextStatus as any } : u))
        );
      }
    } catch (err: any) {
      showToast(err.message || 'Không thể đổi trạng thái người dùng', 'error');
    }
  };

  return (
    <div className={`w-full ${embedded ? '' : 'max-w-7xl mx-auto px-4 py-6'} space-y-6 animate-in fade-in duration-300 font-sans`}>
      {/* ========================================================
          HEADER & THỐNG KÊ TỔNG QUAN
          ======================================================== */}
      <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <div className="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-wider font-mono">
              <Sparkles className="w-3.5 h-3.5 text-sky-500" />
              <span>PHÂN HỆ BẢO MẬT & PHÂN QUYỀN (RBAC)</span>
            </div>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Quản Lý Phân Quyền & Vai Trò
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1 max-w-2xl leading-relaxed">
            Hệ thống phân quyền truy cập người dùng theo vai trò (Role-Based Access Control) cho Ban Quản Lý, Lễ Tân, Kế Toán và Cư Dân.
          </p>
        </div>

        <div className="flex items-center gap-2.5 shrink-0">
          {isSuperAdmin && (
            <div className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-neutral-900 text-sky-300 ring-1 ring-white/10 text-xs font-semibold shadow-xs">
              <ShieldCheck className="w-4 h-4 text-sky-400" />
              <span>Toàn quyền Super Admin</span>
            </div>
          )}

          <button
            onClick={fetchData}
            disabled={loading}
            className="px-3.5 py-2 bg-white hover:bg-neutral-50 active:scale-95 border border-neutral-200/90 text-neutral-800 text-xs font-semibold rounded-xl flex items-center gap-1.5 transition-all shadow-2xs cursor-pointer"
            title="Làm mới dữ liệu từ máy chủ"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin text-neutral-950' : 'text-neutral-600'}`} />
            <span>Làm mới</span>
          </button>
        </div>
      </div>

      {/* Metric summary cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3.5">
        <div className="p-4 rounded-2xl bg-white/90 backdrop-blur-xl border border-neutral-200/80 flex items-center gap-3 shadow-xs">
          <div className="w-10 h-10 rounded-xl bg-neutral-900 text-white flex items-center justify-center shadow-md shadow-neutral-900/10">
            <Users className="w-5 h-5 text-sky-400" />
          </div>
          <div>
            <div className="text-2xl font-black text-neutral-900 font-mono tracking-tight">{users.length}</div>
            <div className="text-xs text-slate-500 font-medium">Người dùng hệ thống</div>
          </div>
        </div>

        <div className="p-4 rounded-2xl bg-white/90 backdrop-blur-xl border border-neutral-200/80 flex items-center gap-3 shadow-xs">
          <div className="w-10 h-10 rounded-xl bg-neutral-900 text-white flex items-center justify-center shadow-md shadow-neutral-900/10">
            <Shield className="w-5 h-5 text-indigo-400" />
          </div>
          <div>
            <div className="text-2xl font-black text-neutral-900 font-mono tracking-tight">{roles.length}</div>
            <div className="text-xs text-slate-500 font-medium">Vai trò vận hành</div>
          </div>
        </div>

        <div className="p-4 rounded-2xl bg-white/90 backdrop-blur-xl border border-neutral-200/80 flex items-center gap-3 shadow-xs">
          <div className="w-10 h-10 rounded-xl bg-neutral-900 text-white flex items-center justify-center shadow-md shadow-neutral-900/10">
            <Key className="w-5 h-5 text-emerald-400" />
          </div>
          <div>
            <div className="text-2xl font-black text-neutral-900 font-mono tracking-tight">{permissionsList.length}</div>
            <div className="text-xs text-slate-500 font-medium">Quyền hạn (Permissions)</div>
          </div>
        </div>

        <div className="p-4 rounded-2xl bg-white/90 backdrop-blur-xl border border-neutral-200/80 flex items-center gap-3 shadow-xs">
          <div className="w-10 h-10 rounded-xl bg-neutral-900 text-white flex items-center justify-center shadow-md shadow-neutral-900/10">
            <Sliders className="w-5 h-5 text-teal-400" />
          </div>
          <div>
            <div className="text-2xl font-black text-neutral-900 font-mono tracking-tight">{moduleKeys.length}</div>
            <div className="text-xs text-slate-500 font-medium">Module phân hệ</div>
          </div>
        </div>
      </div>

      {/* Cooldown Banner */}
      <CooldownBanner
        isCooldownActive={isCooldownActive}
        remainingSeconds={remainingSeconds}
        customMessage={cooldownMessage}
      />

      {/* ========================================================
          TAB NAVIGATION SWITCHER
          ======================================================== */}
      <div className="flex items-center gap-1.5 p-1.5 rounded-2xl bg-slate-100/90 border border-slate-200/80 overflow-x-auto custom-scrollbar">
        <button
          onClick={() => setActiveTab('users')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer whitespace-nowrap ${
            activeTab === 'users'
              ? 'bg-neutral-950 text-white shadow-md shadow-neutral-950/15'
              : 'text-slate-600 hover:text-neutral-900 hover:bg-white/80'
          }`}
        >
          <Users className={`w-4 h-4 ${activeTab === 'users' ? 'text-sky-400' : 'text-slate-500'}`} />
          <span>Người Dùng & Tài Khoản ({users.length})</span>
        </button>

        <button
          onClick={() => setActiveTab('roles')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer whitespace-nowrap ${
            activeTab === 'roles'
              ? 'bg-neutral-950 text-white shadow-md shadow-neutral-950/15'
              : 'text-slate-600 hover:text-neutral-900 hover:bg-white/80'
          }`}
        >
          <Shield className={`w-4 h-4 ${activeTab === 'roles' ? 'text-indigo-400' : 'text-slate-500'}`} />
          <span>Danh Sách Vai Trò ({roles.length})</span>
        </button>

        <button
          onClick={() => setActiveTab('matrix')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer whitespace-nowrap ${
            activeTab === 'matrix'
              ? 'bg-neutral-950 text-white shadow-md shadow-neutral-950/15'
              : 'text-slate-600 hover:text-neutral-900 hover:bg-white/80'
          }`}
        >
          <Key className={`w-4 h-4 ${activeTab === 'matrix' ? 'text-purple-400' : 'text-slate-500'}`} />
          <span>Ma Trận Phân Quyền (7 Quyền)</span>
          <span className="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-purple-600 text-white shadow-xs">
            Đủ 7 Quyền
          </span>
        </button>

        <button
          onClick={() => setActiveTab('permissions')}
          className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer whitespace-nowrap ${
            activeTab === 'permissions'
              ? 'bg-neutral-950 text-white shadow-md shadow-neutral-950/15'
              : 'text-slate-600 hover:text-neutral-900 hover:bg-white/80'
          }`}
        >
          <Sliders className={`w-4 h-4 ${activeTab === 'permissions' ? 'text-teal-400' : 'text-slate-500'}`} />
          <span>Danh Mục Quyền Hạn ({permissionsList.length})</span>
        </button>
      </div>

      {/* ========================================================
          TAB 1: QUẢN LÝ NGƯỜI DÙNG & TÀI KHOẢN
          ======================================================== */}
      {activeTab === 'users' && (
        <div className="space-y-4">
          {/* Controls Bar */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-neutral-200/80 shadow-xs">
            <div className="flex flex-1 flex-wrap items-center gap-2.5">
              <div className="relative flex-1 min-w-[220px]">
                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Tìm theo họ tên, username, email, sđt..."
                  value={userSearch}
                  onChange={(e) => setUserSearch(e.target.value)}
                  className="w-full pl-9 pr-3 py-2 rounded-xl text-xs bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div className="w-48">
                <CustomSelect
                  value={userRoleFilter}
                  onChange={(val) => setUserRoleFilter(val)}
                  options={[
                    { value: '', label: 'Tất cả vai trò' },
                    ...roles.map((r) => ({
                      value: r.role_code,
                      label: `${r.role_name} (${r.role_code})`,
                    })),
                  ]}
                  placeholder="Tất cả vai trò"
                  searchable={roles.length >= 4}
                />
              </div>

              <div className="w-48">
                <CustomSelect
                  value={userStatusFilter}
                  onChange={(val) => setUserStatusFilter(val)}
                  options={[
                    { value: '', label: 'Tất cả trạng thái' },
                    { value: 'ACTIVE', label: 'Hoạt động (ACTIVE)' },
                    { value: 'LOCKED', label: 'Bị khóa (LOCKED)' },
                    { value: 'SUSPENDED', label: 'Tạm ngưng (SUSPENDED)' },
                  ]}
                  placeholder="Tất cả trạng thái"
                  searchable={false}
                />
              </div>
            </div>

            {can('USER:CREATE') && (
              <button
                onClick={() => {
                  setEditingUser(null);
                  setUserForm({
                    username: '',
                    phone_number: '',
                    email: '',
                    full_name: '',
                    password: 'password123',
                    status: 'ACTIVE',
                    roles: ['RESIDENT_OWNER'],
                  });
                  setUserModalOpen(true);
                }}
                className="flex items-center justify-center gap-2 px-4 py-2 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-xl shadow-xs transition-all cursor-pointer whitespace-nowrap"
              >
                <Plus className="w-4 h-4" />
                <span>Thêm Người Dùng</span>
              </button>
            )}
          </div>

          {/* Users Table */}
          <div className="bg-white/90 backdrop-blur-xl rounded-2xl border border-neutral-200/80 overflow-hidden shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-neutral-200/80 bg-slate-50/80 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
                    <th className="py-3 px-4">Người dùng</th>
                    <th className="py-3 px-4">Tên đăng nhập / Email</th>
                    <th className="py-3 px-4">Số điện thoại</th>
                    <th className="py-3 px-4">Vai trò được gán</th>
                    <th className="py-3 px-4">Trạng thái</th>
                    <th className="py-3 px-4 text-right">Thao tác</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-neutral-800">
                  {loading ? (
                    <tr>
                      <td colSpan={6} className="py-12 text-center text-slate-400">
                        <Loader2 className="w-6 h-6 animate-spin mx-auto text-neutral-900 mb-2" />
                        <span>Đang tải danh sách người dùng...</span>
                      </td>
                    </tr>
                  ) : filteredUsers.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="py-12 text-center text-slate-400">
                        Không tìm thấy người dùng phù hợp.
                      </td>
                    </tr>
                  ) : (
                    filteredUsers.map((user) => {
                      const isSuper = user.roles?.some((r) => r.role_code === 'SUPER_ADMIN' || r.role_code === 'SUPER_ADMI');
                      return (
                        <tr key={user.id} className="hover:bg-slate-50/70 transition-colors">
                          <td className="py-3.5 px-4">
                            <div className="flex items-center gap-3">
                              <div className="w-8 h-8 rounded-full bg-neutral-900 text-sky-300 ring-1 ring-white/10 flex items-center justify-center font-bold text-xs uppercase shadow-xs">
                                {user.full_name?.charAt(0) || user.username?.charAt(0) || 'U'}
                              </div>
                              <div>
                                <div className="font-bold text-neutral-900 flex items-center gap-1.5">
                                  <span>{user.full_name}</span>
                                  {isSuper && (
                                    <span title="Quản trị viên cấp cao">
                                      <ShieldCheck className="w-3.5 h-3.5 text-sky-500" />
                                    </span>
                                  )}
                                </div>
                                <div className="text-[10px] text-slate-400 font-mono">ID: {user.id.slice(0, 8)}...</div>
                              </div>
                            </div>
                          </td>

                          <td className="py-3.5 px-4">
                            <div className="font-mono text-[11px] font-semibold text-neutral-800">@{user.username}</div>
                            <div className="text-[11px] text-slate-500">{user.email}</div>
                          </td>

                          <td className="py-3.5 px-4 font-mono text-[11px] text-neutral-700">
                            {user.phone_number || '---'}
                          </td>

                          <td className="py-3.5 px-4">
                            <div className="flex flex-wrap gap-1">
                              {Array.isArray(user.roles) && user.roles.length > 0 ? (
                                user.roles.map((r) => {
                                  if (!r) return null;
                                  const roleCode = r.role_code || '';
                                  const isCustom = roleCode.startsWith('CUSTOM_USER_');
                                  const isAdm = roleCode === 'SUPER_ADMIN' || roleCode === 'SUPER_ADMI';
                                  const isMgr = roleCode === 'BUILDING_MANAGER';
                                  const isRec = roleCode === 'RECEPTIONIST';
                                  const isAcc = roleCode === 'ACCOUNTANT';
                                  const isRes = roleCode.includes('RESIDENT');

                                  const badgeClass = isCustom
                                    ? 'bg-purple-50 text-purple-700 border border-purple-200/90 font-bold'
                                    : isAdm
                                    ? 'bg-neutral-900 text-sky-300 ring-1 ring-white/10'
                                    : isMgr
                                    ? 'bg-sky-50 text-sky-700 border border-sky-200/90'
                                    : isRec
                                    ? 'bg-indigo-50 text-indigo-700 border border-indigo-200/90'
                                    : isAcc
                                    ? 'bg-teal-50 text-teal-700 border border-teal-200/90'
                                    : isRes
                                    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/90'
                                    : 'bg-slate-100 text-slate-700 border border-slate-200/90';

                                  return (
                                    <span
                                      key={r.id}
                                      className={`px-2 py-0.5 rounded-md text-[10px] font-semibold ${badgeClass}`}
                                    >
                                      {isCustom ? '⚡ Quyền riêng' : r.role_name}
                                    </span>
                                  );
                                })
                              ) : (
                                <span className="text-[10px] text-slate-400 italic">Chưa gán vai trò</span>
                              )}
                            </div>
                          </td>

                          <td className="py-3.5 px-4">
                            <span
                              className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold ${
                                user.status === 'ACTIVE'
                                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/80'
                                  : 'bg-rose-50 text-rose-700 border border-rose-200/80'
                              }`}
                            >
                              <span className={`w-1.5 h-1.5 rounded-full ${user.status === 'ACTIVE' ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500'}`} />
                              {user.status === 'ACTIVE' ? 'Hoạt động' : 'Đã khóa'}
                            </span>
                          </td>

                          <td className="py-3.5 px-4 text-right">
                            <div className="flex items-center justify-end gap-1">
                              {can('ROLE:ASSIGN_PERMISSION|USER:UPDATE') && (
                                <button
                                  onClick={() => {
                                    setSelectedUserForMatrix(user);
                                    setMatrixTargetType('user');
                                    setActiveTab('matrix');
                                  }}
                                  title="Phân quyền theo bảng (7 quyền) cho tài khoản này"
                                  className="p-1.5 rounded-lg text-slate-500 hover:text-purple-700 hover:bg-purple-50 transition-colors cursor-pointer"
                                >
                                  <Key className="w-4 h-4 text-purple-600" />
                                </button>
                              )}

                              {can('ROLE:ASSIGN_PERMISSION|ROLE:UPDATE') && (
                                <button
                                  onClick={() => {
                                    setUserForAssignRole(user);
                                    setSelectedRolesForUser(user.roles?.map((r) => r.role_code) || []);
                                    setAssignRoleModalOpen(true);
                                  }}
                                  title="Gán vai trò"
                                  className="p-1.5 rounded-lg text-slate-500 hover:text-neutral-900 hover:bg-slate-100 transition-colors cursor-pointer"
                                >
                                  <Shield className="w-4 h-4 text-sky-600" />
                                </button>
                              )}

                              {can('USER:UPDATE') && (
                                <button
                                  onClick={() => handleToggleUserStatus(user)}
                                  title={user.status === 'ACTIVE' ? 'Khóa tài khoản' : 'Mở khóa'}
                                  className="p-1.5 rounded-lg text-slate-500 hover:text-neutral-900 hover:bg-slate-100 transition-colors cursor-pointer"
                                >
                                  {user.status === 'ACTIVE' ? <Lock className="w-4 h-4" /> : <Unlock className="w-4 h-4 text-emerald-600" />}
                                </button>
                              )}

                              {can('USER:UPDATE') && (
                                <button
                                  onClick={() => {
                                    setEditingUser(user);
                                    setUserForm({
                                      username: user.username,
                                      phone_number: user.phone_number,
                                      email: user.email,
                                      full_name: user.full_name,
                                      password: '',
                                      status: user.status,
                                      roles: user.roles?.map((r) => r.role_code) || [],
                                    });
                                    setUserModalOpen(true);
                                  }}
                                  title="Chỉnh sửa thông tin"
                                  className="p-1.5 rounded-lg text-slate-500 hover:text-sky-600 hover:bg-sky-50 transition-colors cursor-pointer"
                                >
                                  <Edit2 className="w-4 h-4" />
                                </button>
                              )}

                              {can('USER:DELETE') && !isSuper && (
                                <button
                                  onClick={() => {
                                    setDeleteConfirm({
                                      open: true,
                                      type: 'user',
                                      id: user.id,
                                      title: `Xóa người dùng ${user.full_name}`,
                                      description: `Bạn có chắc chắn muốn xóa tài khoản @${user.username}? Dữ liệu sẽ được lưu trữ an toàn qua Soft Delete.`,
                                    });
                                  }}
                                  title="Xóa người dùng"
                                  className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer"
                                >
                                  <Trash2 className="w-4 h-4" />
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          TAB 2: QUẢN LÝ DANH SÁCH VAI TRÒ (ROLES)
          ======================================================== */}
      {activeTab === 'roles' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-4">
            <div>
              <h2 className="text-sm font-bold text-neutral-900">
                Các vai trò hệ thống và vai trò tùy chỉnh ({roles.length})
              </h2>
              <p className="text-xs text-slate-500">
                Vai trò hệ thống (System Role) được bảo vệ, không thể xóa để đảm bảo tính toàn vẹn hệ thống
              </p>
            </div>

            {can('ROLE:CREATE') && (
              <button
                onClick={() => {
                  setEditingRole(null);
                  setRoleForm({ role_code: '', role_name: '', description: '' });
                  setRoleModalOpen(true);
                }}
                className="flex items-center gap-2 px-4 py-2 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-xl shadow-xs transition-all cursor-pointer whitespace-nowrap"
              >
                <Plus className="w-4 h-4" />
                <span>Tạo Vai Trò Mới</span>
              </button>
            )}
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {roles.map((role) => (
              <div
                key={role.id}
                className="p-5 rounded-2xl bg-white/90 backdrop-blur-xl border border-neutral-200/80 shadow-xs hover:shadow-md transition-all flex flex-col justify-between"
              >
                <div>
                  <div className="flex items-start justify-between gap-2 mb-3">
                    <div className="flex items-center gap-2.5">
                      <div className="w-9 h-9 rounded-xl bg-neutral-900 text-sky-400 flex items-center justify-center font-black shadow-xs">
                        <Shield className="w-4 h-4" />
                      </div>
                      <div>
                        <h3 className="font-bold text-sm text-neutral-900 leading-tight">
                          {role.role_name}
                        </h3>
                        <div className="font-mono text-[10px] text-slate-500">{role.role_code}</div>
                      </div>
                    </div>

                    {role.is_system_role ? (
                      <span className="flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-neutral-900 text-sky-300 ring-1 ring-white/10">
                        <Lock className="w-2.5 h-2.5 text-sky-400" />
                        System
                      </span>
                    ) : (
                      <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200/80">
                        Tùy chỉnh
                      </span>
                    )}
                  </div>

                  <p className="text-xs text-slate-600 line-clamp-2 min-h-[32px] mb-4">
                    {role.description || 'Không có mô tả chi tiết cho vai trò này.'}
                  </p>

                  <div className="flex items-center gap-3 text-xs text-slate-500 border-t border-slate-100 pt-3">
                    <div className="flex items-center gap-1.5">
                      <Users className="w-3.5 h-3.5 text-sky-500" />
                      <span className="font-bold text-neutral-800">{role.users_count ?? 0}</span>
                      <span>người dùng</span>
                    </div>
                    <span>·</span>
                    <div className="flex items-center gap-1.5">
                      <Key className="w-3.5 h-3.5 text-emerald-500" />
                      <span className="font-bold text-neutral-800">{role.permissions_count ?? 0}</span>
                      <span>quyền hạn</span>
                    </div>
                  </div>
                </div>

                <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                  <button
                    onClick={() => {
                      setMatrixTargetType('role');
                      setSelectedRoleForMatrix(role);
                      setActiveTab('matrix');
                    }}
                    className="flex-1 py-2 px-3 rounded-xl bg-slate-100 hover:bg-neutral-950 hover:text-white text-neutral-800 text-xs font-semibold transition-all text-center cursor-pointer"
                  >
                    Cấu hình quyền hạn
                  </button>

                  {can('ROLE:UPDATE') && (
                    <button
                      onClick={() => {
                        setEditingRole(role);
                        setRoleForm({
                          role_code: role.role_code,
                          role_name: role.role_name,
                          description: role.description || '',
                        });
                        setRoleModalOpen(true);
                      }}
                      title="Sửa vai trò"
                      className="p-2 rounded-xl text-slate-500 hover:text-sky-600 hover:bg-sky-50 transition-colors cursor-pointer"
                    >
                      <Edit2 className="w-4 h-4" />
                    </button>
                  )}

                  {can('ROLE:DELETE') && !role.is_system_role && (
                    <button
                      onClick={() => {
                        setDeleteConfirm({
                          open: true,
                          type: 'role',
                          id: role.id,
                          title: `Xóa vai trò ${role.role_name}`,
                          description: `Bạn có chắc chắn muốn xóa vai trò tùy chỉnh này? Hành động này không thể hoàn tác nếu các liên kết bị gỡ bỏ.`,
                        });
                      }}
                      title="Xóa vai trò"
                      className="p-2 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ========================================================
          TAB 3: MA TRẬN PHÂN QUYỀN VAI TRÒ (ROLE-PERMISSION MATRIX)
          ======================================================== */}
      {activeTab === 'matrix' && (
        <div className="space-y-4">
          {/* Top Control Bar */}
          <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-neutral-200/80 shadow-xs">
            <div className="flex flex-wrap items-center gap-3">
              {/* Segmented Switch: Target Type (Role vs User) */}
              <div className="flex items-center p-0.5 bg-slate-100 rounded-xl border border-slate-200/80">
                <button
                  type="button"
                  onClick={() => setMatrixTargetType('role')}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                    matrixTargetType === 'role'
                      ? 'bg-white text-neutral-900 shadow-xs'
                      : 'text-slate-500 hover:text-neutral-800'
                  }`}
                >
                  <Shield className="w-3.5 h-3.5 text-sky-600" />
                  <span>Theo Vai Trò</span>
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setMatrixTargetType('user');
                    if (!selectedUserForMatrix && users.length > 0) {
                      setSelectedUserForMatrix(users[0]);
                    }
                  }}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                    matrixTargetType === 'user'
                      ? 'bg-white text-neutral-900 shadow-xs'
                      : 'text-slate-500 hover:text-neutral-800'
                  }`}
                >
                  <Users className="w-3.5 h-3.5 text-purple-600" />
                  <span>Theo Tài Khoản</span>
                </button>
              </div>

              {matrixTargetType === 'role' ? (
                <div className="w-64">
                  <CustomSelect
                    value={selectedRoleForMatrix?.id || ''}
                    onChange={(val) => {
                      const r = roles.find((role) => role.id === val);
                      if (r) setSelectedRoleForMatrix(r);
                    }}
                    options={roles.map((r) => ({
                      value: r.id,
                      label: `${r.role_name} (${r.role_code})`,
                    }))}
                    placeholder="Chọn vai trò..."
                    searchable={roles.length >= 4}
                  />
                </div>
              ) : (
                <div className="flex flex-wrap items-center gap-2">
                  <div className="w-72">
                    <CustomSelect
                      value={selectedUserForMatrix?.id || ''}
                      onChange={(val) => {
                        const u = users.find((user) => user.id === val);
                        if (u) setSelectedUserForMatrix(u);
                      }}
                      options={users.map((u) => ({
                        value: u.id,
                        label: `${u.full_name || u.username} (@${u.username})`,
                      }))}
                      placeholder="Chọn tài khoản..."
                      searchable={users.length >= 4}
                    />
                  </div>
                  {isUserCustomized ? (
                    <div className="flex items-center gap-1.5">
                      <span className="px-2 py-1 rounded-lg text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200/90 flex items-center gap-1">
                        <Sparkles className="w-3 h-3 text-purple-600" />
                        Quyền riêng
                      </span>
                      <button
                        type="button"
                        onClick={handleResetUserMatrix}
                        disabled={saving}
                        className="text-[11px] font-semibold text-rose-600 hover:text-rose-700 hover:underline cursor-pointer disabled:opacity-50"
                        title="Khôi phục quyền theo vai trò mặc định"
                      >
                        Khôi phục mặc định
                      </button>
                    </div>
                  ) : (
                    <span className="px-2 py-1 rounded-lg text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200">
                      Quyền theo vai trò
                    </span>
                  )}
                </div>
              )}

              <div className="text-xs font-medium text-slate-500">
                Đã cấp: <span className="font-bold text-sky-600">{matrixPermissions.length}</span> / {permissionsList.length} quyền
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-2.5">
              {/* Segmented Switch: Table vs Cards */}
              <div className="flex items-center p-1 bg-slate-100 rounded-xl border border-slate-200/80">
                <button
                  type="button"
                  onClick={() => setMatrixViewMode('table')}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                    matrixViewMode === 'table'
                      ? 'bg-white text-neutral-900 shadow-xs'
                      : 'text-slate-500 hover:text-neutral-800'
                  }`}
                >
                  <LayoutGrid className="w-3.5 h-3.5" />
                  <span>Bảng Ma Trận 7 Quyền</span>
                </button>
                <button
                  type="button"
                  onClick={() => setMatrixViewMode('cards')}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                    matrixViewMode === 'cards'
                      ? 'bg-white text-neutral-900 shadow-xs'
                      : 'text-slate-500 hover:text-neutral-800'
                  }`}
                >
                  <Layers className="w-3.5 h-3.5" />
                  <span>Chi Tiết Thẻ Module</span>
                </button>
              </div>

              <button
                type="button"
                onClick={handleSelectAllMatrix}
                className="px-3 py-1.5 rounded-xl text-xs font-semibold text-neutral-700 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                Chọn tất cả
              </button>
              <button
                type="button"
                onClick={handleDeselectAllMatrix}
                className="px-3 py-1.5 rounded-xl text-xs font-semibold text-neutral-700 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                Bỏ chọn hết
              </button>
              <button
                type="button"
                onClick={handleSaveMatrix}
                disabled={saving}
                className={`flex items-center gap-2 px-5 py-2 rounded-xl text-white text-xs font-semibold shadow-xs transition-all cursor-pointer disabled:opacity-50 ${
                  matrixTargetType === 'user' ? 'bg-purple-900 hover:bg-purple-800' : 'bg-neutral-950 hover:bg-neutral-800'
                }`}
              >
                {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                <span>{matrixTargetType === 'user' ? 'Lưu Quyền Cho Tài Khoản' : 'Lưu Phân Quyền Vai Trò'}</span>
              </button>
            </div>
          </div>

          {/* 7 Standardized Permissions Legend Banner */}
          <div className="bg-linear-to-r from-slate-900 via-neutral-900 to-indigo-950 p-4 rounded-2xl text-white shadow-xs border border-white/10 space-y-2.5">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <ShieldCheck className="w-5 h-5 text-sky-400" />
                <span className="font-extrabold text-xs uppercase tracking-wider text-sky-200">
                  Hệ Thống Phân Quyền RBAC — Đầy Đủ 7 Quyền Chuẩn Hóa
                </span>
              </div>
              <span className="text-[11px] text-slate-300">
                {matrixTargetType === 'user' ? (
                  <>
                    Tài khoản đang chọn:{' '}
                    <strong className="text-white font-mono">
                      {selectedUserForMatrix ? `${selectedUserForMatrix.full_name || selectedUserForMatrix.username} (@${selectedUserForMatrix.username})` : '...'}
                    </strong>
                    {isUserCustomized && (
                      <span className="ml-2 px-1.5 py-0.5 rounded text-[10px] bg-purple-500/40 text-purple-200 border border-purple-400/50">
                        Quyền riêng
                      </span>
                    )}
                  </>
                ) : (
                  <>
                    Vai trò đang chọn: <strong className="text-white font-mono">{selectedRoleForMatrix?.role_name || '...'}</strong>
                  </>
                )}
              </span>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2 pt-1 text-[11px]">
              {CORE_7_PERMISSIONS.map((action) => {
                const ActionIcon = action.headerIcon;
                const isSpecial = action.key === 'special';
                return (
                  <div
                    key={action.key}
                    className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl border ${
                      isSpecial
                        ? 'bg-purple-950/80 border-purple-400/50 text-purple-200 ring-1 ring-purple-500/30'
                        : 'bg-white/10 border-white/10 text-slate-200'
                    }`}
                  >
                    <ActionIcon className={`w-3.5 h-3.5 shrink-0 ${isSpecial ? 'text-purple-300 animate-pulse' : 'text-sky-300'}`} />
                    <span className="font-semibold truncate">{action.badgeText}</span>
                  </div>
                );
              })}
            </div>
          </div>

          {/* VIEW 1: BẢNG MA TRẬN 7 QUYỀN (TABLE MATRIX VIEW) */}
          {matrixViewMode === 'table' && (
            <div className="bg-white/90 backdrop-blur-xl rounded-2xl border border-neutral-200/80 overflow-hidden shadow-xs">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="border-b border-neutral-200/80 bg-slate-50/90 text-slate-700 font-bold uppercase text-[10px] tracking-wider font-mono">
                      <th className="py-3.5 px-4 min-w-[170px]">Phân Hệ / Module</th>
                      {CORE_7_PERMISSIONS.map((action) => {
                        const isSpecial = action.key === 'special';
                        const ActionIcon = action.headerIcon;
                        return (
                          <th
                            key={action.key}
                            className={`py-3.5 px-2 text-center min-w-[110px] ${
                              isSpecial ? 'bg-purple-50/90 text-purple-900 border-x border-purple-200/80' : ''
                            }`}
                          >
                            <div className="flex flex-col items-center gap-1">
                              <div className="flex items-center gap-1">
                                <ActionIcon className={`w-3.5 h-3.5 ${isSpecial ? 'text-purple-600' : 'text-slate-600'}`} />
                                <span className={isSpecial ? 'font-black text-purple-700' : ''}>{action.title}</span>
                              </div>
                              <button
                                type="button"
                                onClick={() => toggleColumnPermissions(action.key)}
                                className={`text-[9px] px-1.5 py-0.5 rounded cursor-pointer transition-colors ${
                                  isSpecial
                                    ? 'bg-purple-100 hover:bg-purple-200 text-purple-800'
                                    : 'bg-slate-200 hover:bg-slate-300 text-slate-700'
                                }`}
                                title={`Bật/tắt tất cả quyền ${action.title} cho mọi module`}
                              >
                                Bật/Tắt cột
                              </button>
                            </div>
                          </th>
                        );
                      })}
                      <th className="py-3.5 px-4 min-w-[150px]">Quyền Mở Rộng</th>
                      <th className="py-3.5 px-4 text-center min-w-[110px]">Hàng</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 text-neutral-800">
                    {moduleKeys.map((moduleName) => {
                      const modPerms = permissionsGrouped[moduleName] || [];
                      const extPerms = getModuleExtendedPermissions(modPerms);
                      const allModCodes = modPerms.map((p) => p.permission_code);
                      const allModSelected = allModCodes.length > 0 && allModCodes.every((c) => matrixPermissions.includes(c));
                      const selectedModCount = modPerms.filter((p) => matrixPermissions.includes(p.permission_code)).length;

                      return (
                        <tr key={moduleName} className="hover:bg-slate-50/70 transition-colors">
                          {/* Module Name */}
                          <td className="py-3.5 px-4">
                            <div className="font-extrabold text-neutral-900 text-xs flex items-center gap-2">
                              <span className="w-2 h-2 rounded-full bg-sky-500" />
                              <span>Module: {moduleName}</span>
                            </div>
                            <div className="text-[10px] text-slate-500 mt-0.5 font-mono">
                              Đã cấp {selectedModCount}/{modPerms.length} quyền
                            </div>
                          </td>

                          {/* 7 Standard Action Columns */}
                          {CORE_7_PERMISSIONS.map((action) => {
                            const perm = findModulePermissionForAction(modPerms, action.key);
                            const isSpecial = action.key === 'special';

                            if (!perm) {
                              return (
                                <td
                                  key={action.key}
                                  className={`py-3.5 px-2 text-center text-slate-300 ${
                                    isSpecial ? 'bg-purple-50/30 border-x border-purple-100' : ''
                                  }`}
                                  title={`Module ${moduleName} chưa có quyền ${action.title}`}
                                >
                                  —
                                </td>
                              );
                            }

                            const isChecked = matrixPermissions.includes(perm.permission_code);

                            return (
                              <td
                                key={action.key}
                                className={`py-3.5 px-2 text-center ${
                                  isSpecial ? 'bg-purple-50/50 border-x border-purple-100' : ''
                                }`}
                              >
                                <label
                                  title={`${perm.permission_name} (${perm.permission_code})${perm.description ? ': ' + perm.description : ''}`}
                                  className={`inline-flex items-center justify-center p-2 rounded-xl border transition-all cursor-pointer ${
                                    isChecked
                                      ? isSpecial
                                        ? 'bg-purple-600 text-white border-purple-600 shadow-sm shadow-purple-500/20 ring-2 ring-purple-300'
                                        : 'bg-neutral-900 text-white border-neutral-900 shadow-xs'
                                      : isSpecial
                                      ? 'bg-purple-50/80 border-purple-200 text-purple-700 hover:bg-purple-100/80'
                                      : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-100'
                                  }`}
                                >
                                  <input
                                    type="checkbox"
                                    checked={isChecked}
                                    onChange={() => toggleMatrixPermission(perm.permission_code)}
                                    className="sr-only"
                                  />
                                  {isChecked ? (
                                    <Check className="w-4 h-4 stroke-[2.5]" />
                                  ) : (
                                    <div className="w-4 h-4 rounded-sm border border-slate-300" />
                                  )}
                                </label>
                              </td>
                            );
                          })}

                          {/* Extended Permissions */}
                          <td className="py-3.5 px-4">
                            {extPerms.length > 0 ? (
                              <div className="flex flex-wrap gap-1.5">
                                {extPerms.map((ep) => {
                                  const isChecked = matrixPermissions.includes(ep.permission_code);
                                  return (
                                    <button
                                      key={ep.id}
                                      type="button"
                                      onClick={() => toggleMatrixPermission(ep.permission_code)}
                                      title={`${ep.permission_name} (${ep.permission_code})${ep.description ? ': ' + ep.description : ''}`}
                                      className={`text-[10px] px-2 py-1 rounded-lg border font-mono transition-all cursor-pointer ${
                                        isChecked
                                          ? 'bg-sky-50 text-sky-700 border-sky-300 font-bold shadow-2xs'
                                          : 'bg-slate-50 text-slate-500 border-slate-200 hover:bg-slate-100'
                                      }`}
                                    >
                                      {ep.permission_code.split(':').pop() || ep.permission_code}
                                    </button>
                                  );
                                })}
                              </div>
                            ) : (
                              <span className="text-[10px] text-slate-400 italic">Không có</span>
                            )}
                          </td>

                          {/* Row Toggle */}
                          <td className="py-3.5 px-4 text-center">
                            <button
                              type="button"
                              onClick={() => toggleModulePermissions(moduleName)}
                              className={`text-[10px] px-2.5 py-1 rounded-lg font-bold transition-colors cursor-pointer ${
                                allModSelected
                                  ? 'bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200/80'
                                  : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-200/80'
                              }`}
                            >
                              {allModSelected ? 'Bỏ module' : 'Chọn module'}
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* VIEW 2: CHI TIẾT THEO THẺ MODULE (CARD GRID VIEW) */}
          {matrixViewMode === 'cards' && (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
              {moduleKeys.map((moduleName) => {
                const perms = permissionsGrouped[moduleName] || [];
                const allModSelected = perms.every((p) => matrixPermissions.includes(p.permission_code));

                return (
                  <div
                    key={moduleName}
                    className="p-5 rounded-2xl bg-white/90 backdrop-blur-xl border border-neutral-200/80 shadow-xs space-y-3"
                  >
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                      <div className="flex items-center gap-2">
                        <span className="w-2 h-2 rounded-full bg-sky-500" />
                        <h3 className="font-extrabold text-sm text-neutral-900">
                          Module: {moduleName}
                        </h3>
                        <span className="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 font-bold border border-slate-200/60">
                          {perms.filter((p) => matrixPermissions.includes(p.permission_code)).length}/{perms.length}
                        </span>
                      </div>

                      <button
                        type="button"
                        onClick={() => toggleModulePermissions(moduleName)}
                        className="text-[11px] font-bold text-sky-600 hover:text-sky-700 cursor-pointer"
                      >
                        {allModSelected ? 'Bỏ chọn cả module' : 'Chọn cả module'}
                      </button>
                    </div>

                    <div className="space-y-2">
                      {perms.map((p) => {
                        const checked = matrixPermissions.includes(p.permission_code);
                        const isSpecial = p.permission_code.includes('SPECIAL') || p.permission_code === 'SPECIAL';

                        // Check which of the 7 core permissions this is
                        const matchedCore = CORE_7_PERMISSIONS.find((action) => {
                          const actionPerm = findModulePermissionForAction(perms, action.key);
                          return actionPerm?.permission_code === p.permission_code;
                        });

                        return (
                          <label
                            key={p.id}
                            className={`flex items-start gap-3 p-3 rounded-xl border transition-all cursor-pointer ${
                              checked
                                ? isSpecial
                                  ? 'bg-purple-50/90 border-purple-400 text-neutral-900 shadow-2xs ring-1 ring-purple-300'
                                  : 'bg-sky-50/70 border-sky-300/90 text-neutral-900 shadow-2xs'
                                : isSpecial
                                ? 'bg-purple-50/30 border-purple-200/80 hover:bg-purple-50/60 text-neutral-700'
                                : 'bg-slate-50/60 border-slate-200/70 hover:bg-slate-100/70 text-neutral-700'
                            }`}
                          >
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => toggleMatrixPermission(p.permission_code)}
                              className={`mt-0.5 rounded focus:ring-neutral-900/20 w-4 h-4 cursor-pointer ${
                                isSpecial ? 'text-purple-600 accent-purple-600' : 'text-neutral-950'
                              }`}
                            />
                            <div className="flex-1">
                              <div className="flex items-center justify-between gap-2 flex-wrap">
                                <div className="flex items-center gap-1.5">
                                  <span className="font-bold text-xs text-neutral-900">
                                    {p.permission_name}
                                  </span>
                                  {isSpecial && (
                                    <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-purple-100 text-purple-800 text-[10px] font-bold border border-purple-300">
                                      <Sparkles className="w-3 h-3 text-purple-600" />
                                      <span>7. Người đặc biệt (special)</span>
                                    </span>
                                  )}
                                  {!isSpecial && matchedCore && (
                                    <span className={`px-1.5 py-0.5 rounded-md text-[10px] font-semibold border ${matchedCore.badgeStyle}`}>
                                      {matchedCore.badgeText}
                                    </span>
                                  )}
                                </div>
                                <code className="text-[10px] font-mono text-slate-600 bg-white px-1.5 py-0.5 rounded border border-slate-200">
                                  {p.permission_code}
                                </code>
                              </div>
                              {p.description && (
                                <p className="text-[11px] text-slate-500 mt-0.5">{p.description}</p>
                              )}
                            </div>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* ========================================================
          TAB 4: DANH MỤC QUYỀN HẠN (PERMISSIONS CATALOG)
          ======================================================== */}
      {activeTab === 'permissions' && (
        <div className="space-y-4">
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-neutral-200/80 shadow-xs">
            <div className="flex flex-1 flex-wrap items-center gap-2.5">
              <div className="relative flex-1 min-w-[220px]">
                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Tìm theo mã quyền, tên quyền hạn..."
                  value={permissionSearch}
                  onChange={(e) => setPermissionSearch(e.target.value)}
                  className="w-full pl-9 pr-3 py-2 rounded-xl text-xs bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div className="w-48">
                <CustomSelect
                  value={permissionModuleFilter}
                  onChange={(val) => setPermissionModuleFilter(val)}
                  options={[
                    { value: '', label: 'Tất cả module' },
                    ...moduleKeys.map((m) => ({
                      value: m,
                      label: `Module ${m}`,
                    })),
                  ]}
                  placeholder="Tất cả module"
                  searchable={moduleKeys.length >= 4}
                />
              </div>
            </div>

            {can('PERMISSION:CREATE') && (
              <button
                onClick={() => {
                  setEditingPermission(null);
                  setPermissionForm({
                    module: 'USER',
                    permission_code: '',
                    permission_name: '',
                    description: '',
                  });
                  setPermissionModalOpen(true);
                }}
                className="flex items-center gap-2 px-4 py-2 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-xl shadow-xs transition-all cursor-pointer whitespace-nowrap"
              >
                <Plus className="w-4 h-4" />
                <span>Khai Báo Quyền Mới</span>
              </button>
            )}
          </div>

          <div className="bg-white/90 backdrop-blur-xl rounded-2xl border border-neutral-200/80 overflow-hidden shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-neutral-200/80 bg-slate-50/80 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
                    <th className="py-3 px-4">Module</th>
                    <th className="py-3 px-4">Mã quyền (Code)</th>
                    <th className="py-3 px-4">Tên quyền hạn</th>
                    <th className="py-3 px-4">Mô tả nghiệp vụ</th>
                    <th className="py-3 px-4 text-right">Thao tác</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-neutral-800">
                  {filteredPermissions.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="py-10 text-center text-slate-400">
                        Không tìm thấy quyền hạn phù hợp.
                      </td>
                    </tr>
                  ) : (
                    filteredPermissions.map((perm) => (
                      <tr key={perm.id} className="hover:bg-slate-50/70 transition-colors">
                        <td className="py-3.5 px-4 font-mono font-bold text-sky-700">
                          {perm.module}
                        </td>
                        <td className="py-3.5 px-4 font-mono font-bold text-neutral-900">
                          {perm.permission_code}
                        </td>
                        <td className="py-3.5 px-4 font-semibold text-neutral-800">
                          {perm.permission_name}
                        </td>
                        <td className="py-3.5 px-4 text-slate-500">
                          {perm.description || '---'}
                        </td>
                        <td className="py-3.5 px-4 text-right">
                          <div className="flex items-center justify-end gap-1">
                            {can('PERMISSION:UPDATE') && (
                              <button
                                onClick={() => {
                                  setEditingPermission(perm);
                                  setPermissionForm({
                                    module: perm.module,
                                    permission_code: perm.permission_code,
                                    permission_name: perm.permission_name,
                                    description: perm.description || '',
                                  });
                                  setPermissionModalOpen(true);
                                }}
                                className="p-1.5 rounded-lg text-slate-500 hover:text-sky-600 hover:bg-sky-50 transition-colors cursor-pointer"
                              >
                                <Edit2 className="w-3.5 h-3.5" />
                              </button>
                            )}

                            {can('PERMISSION:DELETE') && (
                              <button
                                onClick={() => {
                                  setDeleteConfirm({
                                    open: true,
                                    type: 'permission',
                                    id: perm.id,
                                    title: `Xóa quyền ${perm.permission_code}`,
                                    description: `Bạn có chắc chắn muốn xóa quyền hạn này?`,
                                  });
                                }}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer"
                              >
                                <Trash2 className="w-3.5 h-3.5" />
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL: THÊM / SỬA NGƯỜI DÙNG
          ======================================================== */}
      {userModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="w-full max-w-lg rounded-2xl bg-white border border-neutral-200/80 shadow-2xl p-6 relative">
            <div className="flex items-center justify-between pb-3.5 border-b border-neutral-100">
              <h3 className="font-extrabold text-base text-neutral-900 flex items-center gap-2">
                <Users className="w-5 h-5 text-sky-600" />
                <span>{editingUser ? 'Chỉnh Sửa Người Dùng' : 'Thêm Người Dùng Mới'}</span>
              </h3>
              <button
                onClick={() => setUserModalOpen(false)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-neutral-900 hover:bg-slate-100 cursor-pointer transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveUser} className="mt-4 space-y-3.5 text-xs">
              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Họ và tên *</label>
                <input
                  type="text"
                  required
                  value={userForm.full_name}
                  onChange={(e) => setUserForm({ ...userForm, full_name: e.target.value })}
                  placeholder="Ví dụ: Nguyễn Văn An"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-neutral-700 mb-1">Tên đăng nhập *</label>
                  <input
                    type="text"
                    required
                    disabled={Boolean(editingUser)}
                    value={userForm.username}
                    onChange={(e) => setUserForm({ ...userForm, username: e.target.value })}
                    placeholder="nguyenvanan"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all disabled:opacity-60"
                  />
                </div>
                <div>
                  <label className="block font-semibold text-neutral-700 mb-1">Số điện thoại *</label>
                  <input
                    type="text"
                    required
                    value={userForm.phone_number}
                    onChange={(e) => setUserForm({ ...userForm, phone_number: e.target.value })}
                    placeholder="0901234567"
                    className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                  />
                </div>
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Email *</label>
                <input
                  type="email"
                  required
                  value={userForm.email}
                  onChange={(e) => setUserForm({ ...userForm, email: e.target.value })}
                  placeholder="user@cassavas.vn"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-1">
                  Mật khẩu {editingUser ? '(để trống nếu không đổi)' : '*'}
                </label>
                <input
                  type="password"
                  required={!editingUser}
                  value={userForm.password}
                  onChange={(e) => setUserForm({ ...userForm, password: e.target.value })}
                  placeholder="Mật khẩu tối thiểu 6 ký tự"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Trạng thái</label>
                <CustomSelect
                  value={userForm.status}
                  onChange={(val) => setUserForm({ ...userForm, status: val })}
                  options={[
                    { value: 'ACTIVE', label: 'Hoạt động (ACTIVE)' },
                    { value: 'LOCKED', label: 'Bị khóa (LOCKED)' },
                    { value: 'SUSPENDED', label: 'Tạm ngưng (SUSPENDED)' },
                  ]}
                  placeholder="Chọn trạng thái"
                  overlay={true}
                  searchable={false}
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-neutral-100">
                <button
                  type="button"
                  onClick={() => setUserModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-neutral-700 bg-white hover:bg-neutral-50 border border-neutral-200/90 font-semibold transition-colors"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="flex items-center gap-2 px-5 py-2 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white font-semibold shadow-xs transition-all cursor-pointer"
                >
                  {saving && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>{editingUser ? 'Lưu Thay Đổi' : 'Tạo Tài Khoản'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL: GÁN VAI TRÒ CHO NGƯỜI DÙNG
          ======================================================== */}
      {assignRoleModalOpen && userForAssignRole && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="w-full max-w-md rounded-2xl bg-white border border-neutral-200/80 shadow-2xl p-6 relative">
            <div className="flex items-center justify-between pb-3 border-b border-neutral-100">
              <h3 className="font-extrabold text-base text-neutral-900 flex items-center gap-2">
                <Shield className="w-5 h-5 text-sky-600" />
                <span>Gán Vai Trò Người Dùng</span>
              </h3>
              <button
                onClick={() => setAssignRoleModalOpen(false)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-neutral-900 hover:bg-slate-100 cursor-pointer transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="mt-4 space-y-3 text-xs">
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
                <div className="font-bold text-neutral-900 text-sm">{userForAssignRole.full_name}</div>
                <div className="text-slate-500 font-mono">@{userForAssignRole.username} · {userForAssignRole.email}</div>
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-2">
                  Chọn các vai trò áp dụng cho người dùng này:
                </label>
                <div className="space-y-2 max-h-[260px] overflow-y-auto custom-scrollbar pr-1">
                  {roles.map((role) => {
                    const isChecked = selectedRolesForUser.includes(role.role_code);
                    return (
                      <label
                        key={role.id}
                        className={`flex items-center justify-between p-3 rounded-xl border transition-all cursor-pointer ${
                          isChecked
                            ? 'bg-sky-50/70 border-sky-300 text-neutral-900 shadow-2xs'
                            : 'bg-slate-50/60 border-slate-200/70 hover:bg-slate-100/60 text-neutral-700'
                        }`}
                      >
                        <div className="flex items-center gap-2.5">
                          <input
                            type="checkbox"
                            checked={isChecked}
                            onChange={() => {
                              setSelectedRolesForUser((prev) =>
                                prev.includes(role.role_code)
                                  ? prev.filter((r) => r !== role.role_code)
                                  : [...prev, role.role_code]
                              );
                            }}
                            className="rounded text-neutral-950 focus:ring-neutral-900/20 w-4 h-4 cursor-pointer"
                          />
                          <div>
                            <div className="font-bold text-neutral-900">{role.role_name}</div>
                            <div className="font-mono text-[10px] text-slate-500">{role.role_code}</div>
                          </div>
                        </div>

                        {role.is_system_role && (
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-neutral-900 text-sky-300 ring-1 ring-white/10">
                            Hệ thống
                          </span>
                        )}
                      </label>
                    );
                  })}
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-neutral-100">
                <button
                  type="button"
                  onClick={() => setAssignRoleModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-neutral-700 bg-white hover:bg-neutral-50 border border-neutral-200/90 font-semibold transition-colors"
                >
                  Hủy
                </button>
                <button
                  type="button"
                  disabled={saving || selectedRolesForUser.length === 0}
                  onClick={handleSaveAssignRoles}
                  className="flex items-center gap-2 px-5 py-2 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white font-semibold shadow-xs transition-all cursor-pointer disabled:opacity-50"
                >
                  {saving && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>Cập Nhật Vai Trò</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL: THÊM / SỬA VAI TRÒ
          ======================================================== */}
      {roleModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="w-full max-w-md rounded-2xl bg-white border border-neutral-200/80 shadow-2xl p-6 relative">
            <div className="flex items-center justify-between pb-3 border-b border-neutral-100">
              <h3 className="font-extrabold text-base text-neutral-900 flex items-center gap-2">
                <Shield className="w-5 h-5 text-indigo-600" />
                <span>{editingRole ? 'Chỉnh Sửa Vai Trò' : 'Tạo Vai Trò Mới'}</span>
              </h3>
              <button
                onClick={() => setRoleModalOpen(false)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-neutral-900 hover:bg-slate-100 cursor-pointer transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveRole} className="mt-4 space-y-3 text-xs">
              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Mã vai trò (Role Code) *</label>
                <input
                  type="text"
                  required
                  disabled={editingRole?.is_system_role}
                  value={roleForm.role_code}
                  onChange={(e) => setRoleForm({ ...roleForm, role_code: e.target.value.toUpperCase() })}
                  placeholder="Ví dụ: IT_SUPPORT"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 font-mono text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all disabled:opacity-60"
                />
                {editingRole?.is_system_role && (
                  <p className="text-[10px] text-sky-600 mt-1">Vai trò hệ thống không được thay đổi Role Code</p>
                )}
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Tên hiển thị vai trò *</label>
                <input
                  type="text"
                  required
                  value={roleForm.role_name}
                  onChange={(e) => setRoleForm({ ...roleForm, role_name: e.target.value })}
                  placeholder="Ví dụ: Nhân viên hỗ trợ IT"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Mô tả nghiệp vụ</label>
                <textarea
                  rows={3}
                  value={roleForm.description}
                  onChange={(e) => setRoleForm({ ...roleForm, description: e.target.value })}
                  placeholder="Mô tả phạm vi quyền hạn và trách nhiệm của vai trò..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-neutral-100">
                <button
                  type="button"
                  onClick={() => setRoleModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-neutral-700 bg-white hover:bg-neutral-50 border border-neutral-200/90 font-semibold transition-colors"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="flex items-center gap-2 px-5 py-2 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white font-semibold shadow-xs transition-all cursor-pointer"
                >
                  {saving && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>{editingRole ? 'Lưu Thay Đổi' : 'Tạo Vai Trò'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL: THÊM / SỬA QUYỀN HẠN
          ======================================================== */}
      {permissionModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="w-full max-w-md rounded-2xl bg-white border border-neutral-200/80 shadow-2xl p-6 relative">
            <div className="flex items-center justify-between pb-3 border-b border-neutral-100">
              <h3 className="font-extrabold text-base text-neutral-900 flex items-center gap-2">
                <Key className="w-5 h-5 text-emerald-600" />
                <span>{editingPermission ? 'Chỉnh Sửa Quyền Hạn' : 'Khai Báo Quyền Hạn Mới'}</span>
              </h3>
              <button
                onClick={() => setPermissionModalOpen(false)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-neutral-900 hover:bg-slate-100 cursor-pointer transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSavePermission} className="mt-4 space-y-3 text-xs">
              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Thuộc Module *</label>
                <input
                  type="text"
                  required
                  value={permissionForm.module}
                  onChange={(e) => setPermissionForm({ ...permissionForm, module: e.target.value.toUpperCase() })}
                  placeholder="Ví dụ: INVOICE, TICKET, USER..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 font-mono text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Mã quyền (Permission Code) *</label>
                <input
                  type="text"
                  required
                  value={permissionForm.permission_code}
                  onChange={(e) => setPermissionForm({ ...permissionForm, permission_code: e.target.value.toUpperCase() })}
                  placeholder="MODULE:ACTION (Ví dụ: TICKET:EXPORT)"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 font-mono text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Tên quyền hạn *</label>
                <input
                  type="text"
                  required
                  value={permissionForm.permission_name}
                  onChange={(e) => setPermissionForm({ ...permissionForm, permission_name: e.target.value })}
                  placeholder="Ví dụ: Xuất báo cáo sự cố ra Excel"
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div>
                <label className="block font-semibold text-neutral-700 mb-1">Mô tả nghiệp vụ</label>
                <textarea
                  rows={3}
                  value={permissionForm.description}
                  onChange={(e) => setPermissionForm({ ...permissionForm, description: e.target.value })}
                  placeholder="Mô tả chi tiết hành động mà quyền hạn này cho phép..."
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-neutral-100">
                <button
                  type="button"
                  onClick={() => setPermissionModalOpen(false)}
                  className="px-4 py-2 rounded-xl text-neutral-700 bg-white hover:bg-neutral-50 border border-neutral-200/90 font-semibold transition-colors"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="flex items-center gap-2 px-5 py-2 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white font-semibold shadow-xs transition-all cursor-pointer"
                >
                  {saving && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>{editingPermission ? 'Lưu Thay Đổi' : 'Tạo Quyền Hạn'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ========================================================
          CONFIRM DELETE DIALOG
          ======================================================== */}
      {deleteConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="w-full max-w-md rounded-2xl bg-white border border-neutral-200/80 shadow-2xl p-6 relative">
            <div className="flex items-center gap-3 text-rose-600 mb-3">
              <div className="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 border border-rose-200/80 flex items-center justify-center">
                <AlertTriangle className="w-5 h-5" />
              </div>
              <h3 className="font-extrabold text-base text-neutral-900">
                {deleteConfirm.title}
              </h3>
            </div>

            <p className="text-xs text-slate-600 mb-5 leading-relaxed">
              {deleteConfirm.description}
            </p>

            <div className="flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setDeleteConfirm(null)}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-neutral-700 bg-white hover:bg-neutral-50 border border-neutral-200/90 transition-colors cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                disabled={saving}
                onClick={handleConfirmDelete}
                className="flex items-center gap-2 px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 active:scale-95 text-white text-xs font-semibold shadow-xs transition-all cursor-pointer"
              >
                {saving && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                <span>Xác nhận xóa</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          TOAST NOTIFICATION
          ======================================================== */}
      {toastMessage && (
        <div
          className={`fixed top-4 right-4 z-50 px-4 py-3 rounded-xl shadow-2xl flex items-center gap-2.5 text-xs font-medium animate-in fade-in slide-in-from-top-3 duration-200 border ${
            toastMessage.type === 'error'
              ? 'bg-rose-50 text-rose-900 border-rose-200'
              : 'bg-neutral-950 text-white border-white/20'
          }`}
        >
          {toastMessage.type === 'error' ? (
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
          ) : (
            <Sparkles className="w-4 h-4 text-emerald-400 shrink-0 animate-pulse" />
          )}
          <span>{toastMessage.text}</span>
        </div>
      )}
    </div>
  );
};

export default RbacManagement;

