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
} from 'lucide-react';
import api, { UserRbac, RoleRbac, PermissionRbac } from '../../Services/api';
import { usePermission } from '../../Hooks/usePermission';

interface RbacManagementProps {
  embedded?: boolean;
}

export const RbacManagement: React.FC<RbacManagementProps> = ({ embedded = false }) => {
  const { can, isSuperAdmin } = usePermission();

  // Active Tab
  const [activeTab, setActiveTab] = useState<'users' | 'roles' | 'matrix' | 'permissions'>('users');

  // Common Data State
  const [users, setUsers] = useState<UserRbac[]>([]);
  const [roles, setRoles] = useState<RoleRbac[]>([]);
  const [permissionsGrouped, setPermissionsGrouped] = useState<Record<string, PermissionRbac[]>>({});
  const [permissionsList, setPermissionsList] = useState<PermissionRbac[]>([]);

  // Loading & Feedback
  const [loading, setLoading] = useState<boolean>(true);
  const [saving, setSaving] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'error' | 'info' } | null>(null);

  // Filters & Search
  const [userSearch, setUserSearch] = useState<string>('');
  const [userRoleFilter, setUserRoleFilter] = useState<string>('');
  const [userStatusFilter, setUserStatusFilter] = useState<string>('');
  const [permissionSearch, setPermissionSearch] = useState<string>('');
  const [permissionModuleFilter, setPermissionModuleFilter] = useState<string>('');

  // Selected for Modals
  const [selectedRoleForMatrix, setSelectedRoleForMatrix] = useState<RoleRbac | null>(null);
  const [matrixPermissions, setMatrixPermissions] = useState<string[]>([]);

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

  // When selected role for matrix changes, load its permissions
  useEffect(() => {
    if (!selectedRoleForMatrix) return;
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
  }, [selectedRoleForMatrix, showToast]);

  // Filtered Users
  const filteredUsers = useMemo(() => {
    return users.filter((u) => {
      const matchesSearch =
        !userSearch ||
        u.full_name?.toLowerCase().includes(userSearch.toLowerCase()) ||
        u.username?.toLowerCase().includes(userSearch.toLowerCase()) ||
        u.email?.toLowerCase().includes(userSearch.toLowerCase()) ||
        u.phone_number?.includes(userSearch);

      const matchesRole =
        !userRoleFilter ||
        u.roles?.some((r) => r.role_code === userRoleFilter || r.id === userRoleFilter);

      const matchesStatus = !userStatusFilter || u.status === userStatusFilter;

      return matchesSearch && matchesRole && matchesStatus;
    });
  }, [users, userSearch, userRoleFilter, userStatusFilter]);

  // Modules list
  const moduleKeys = useMemo(() => Object.keys(permissionsGrouped), [permissionsGrouped]);

  // Filtered Permissions
  const filteredPermissions = useMemo(() => {
    return permissionsList.filter((p) => {
      const matchesSearch =
        !permissionSearch ||
        p.permission_code.toLowerCase().includes(permissionSearch.toLowerCase()) ||
        p.permission_name.toLowerCase().includes(permissionSearch.toLowerCase()) ||
        (p.description && p.description.toLowerCase().includes(permissionSearch.toLowerCase()));

      const matchesModule = !permissionModuleFilter || p.module === permissionModuleFilter;

      return matchesSearch && matchesModule;
    });
  }, [permissionsList, permissionSearch, permissionModuleFilter]);

  // Handle Save Matrix Permissions
  const handleSaveMatrix = async () => {
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
          <Key className={`w-4 h-4 ${activeTab === 'matrix' ? 'text-emerald-400' : 'text-slate-500'}`} />
          <span>Ma Trận Quyền Hạn Vai Trò</span>
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

              <select
                value={userRoleFilter}
                onChange={(e) => setUserRoleFilter(e.target.value)}
                className="px-3 py-2 rounded-xl text-xs bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
              >
                <option value="">Tất cả vai trò</option>
                {roles.map((r) => (
                  <option key={r.id} value={r.role_code}>
                    {r.role_name} ({r.role_code})
                  </option>
                ))}
              </select>

              <select
                value={userStatusFilter}
                onChange={(e) => setUserStatusFilter(e.target.value)}
                className="px-3 py-2 rounded-xl text-xs bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
              >
                <option value="">Tất cả trạng thái</option>
                <option value="ACTIVE">Hoạt động (ACTIVE)</option>
                <option value="LOCKED">Bị khóa (LOCKED)</option>
                <option value="SUSPENDED">Tạm ngưng (SUSPENDED)</option>
              </select>
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
                              {user.roles && user.roles.length > 0 ? (
                                user.roles.map((r) => {
                                  const isAdm = r.role_code === 'SUPER_ADMIN' || r.role_code === 'SUPER_ADMI';
                                  const isMgr = r.role_code === 'BUILDING_MANAGER';
                                  const isRec = r.role_code === 'RECEPTIONIST';
                                  const isAcc = r.role_code === 'ACCOUNTANT';
                                  const isRes = r.role_code.includes('RESIDENT');

                                  const badgeClass = isAdm
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
                                      {r.role_name}
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
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white/90 backdrop-blur-xl p-4 rounded-2xl border border-neutral-200/80 shadow-xs">
            <div className="flex flex-wrap items-center gap-3">
              <label className="text-xs font-bold text-neutral-800">
                Đang cấu hình vai trò:
              </label>
              <select
                value={selectedRoleForMatrix?.id || ''}
                onChange={(e) => {
                  const r = roles.find((role) => role.id === e.target.value);
                  if (r) setSelectedRoleForMatrix(r);
                }}
                className="px-3.5 py-2 rounded-xl text-xs font-semibold bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900"
              >
                {roles.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.role_name} ({r.role_code})
                  </option>
                ))}
              </select>

              <div className="text-xs font-medium text-slate-500">
                Đã cấp: <span className="font-bold text-sky-600">{matrixPermissions.length}</span> / {permissionsList.length} quyền
              </div>
            </div>

            <div className="flex items-center gap-2">
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
                className="flex items-center gap-2 px-5 py-2 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white text-xs font-semibold shadow-xs transition-all cursor-pointer disabled:opacity-50"
              >
                {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                <span>Lưu Phân Quyền</span>
              </button>
            </div>
          </div>

          {/* Module-by-module matrix */}
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
                      return (
                        <label
                          key={p.id}
                          className={`flex items-start gap-3 p-3 rounded-xl border transition-all cursor-pointer ${
                            checked
                              ? 'bg-sky-50/70 border-sky-300/90 text-neutral-900 shadow-2xs'
                              : 'bg-slate-50/60 border-slate-200/70 hover:bg-slate-100/70 text-neutral-700'
                          }`}
                        >
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => toggleMatrixPermission(p.permission_code)}
                            className="mt-0.5 rounded text-neutral-950 focus:ring-neutral-900/20 w-4 h-4 cursor-pointer"
                          />
                          <div className="flex-1">
                            <div className="flex items-center justify-between">
                              <span className="font-bold text-xs text-neutral-900">
                                {p.permission_name}
                              </span>
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

              <select
                value={permissionModuleFilter}
                onChange={(e) => setPermissionModuleFilter(e.target.value)}
                className="px-3 py-2 rounded-xl text-xs bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
              >
                <option value="">Tất cả module</option>
                {moduleKeys.map((m) => (
                  <option key={m} value={m}>
                    Module {m}
                  </option>
                ))}
              </select>
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
                <select
                  value={userForm.status}
                  onChange={(e) => setUserForm({ ...userForm, status: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl bg-slate-50 hover:bg-white focus:bg-white border border-slate-200/90 text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900/10 focus:border-neutral-900 transition-all"
                >
                  <option value="ACTIVE">Hoạt động (ACTIVE)</option>
                  <option value="LOCKED">Bị khóa (LOCKED)</option>
                  <option value="SUSPENDED">Tạm ngưng (SUSPENDED)</option>
                </select>
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

