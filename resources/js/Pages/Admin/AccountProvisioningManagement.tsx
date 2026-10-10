import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  UserPlus,
  Mail,
  Phone,
  Shield,
  CheckCircle2,
  AlertCircle,
  RefreshCw,
  Send,
  Search,
  Key,
  Clock,
  Sparkles,
  Info,
  ExternalLink,
  Pencil,
  Trash2,
  Eye,
  Upload,
  Download,
  Lock,
  Unlock,
  FileSpreadsheet,
  X,
  Check,
  FileText,
  AlertTriangle,
  UserCheck
} from 'lucide-react';
import { useRealtimeSync, useModuleCooldown, emitLocalRealtimeEvent } from '../../Hooks/useRealtimeSync';
import { CooldownBanner } from '../../Components/Realtime/CooldownBanner';

interface ProvisionedUser {
  id: string;
  username: string;
  full_name: string;
  email: string;
  phone_number: string;
  national_id_number?: string | null;
  gender?: 'MALE' | 'FEMALE' | 'OTHER';
  status: 'PENDING_ACTIVATION' | 'ACTIVE' | 'LOCKED' | 'SUSPENDED';
  created_at: string;
  roles?: Array<{ id: string; role_code: string; role_name: string }>;
  extra_preferences?: {
    provisioning?: {
      is_auto_provisioned?: boolean;
      provisioned_at?: string;
      activation_sent_at?: string;
      resend_count?: number;
      activated_at?: string | null;
    };
  };
}

interface AccountProvisioningProps {
  embedded?: boolean;
}

export const AccountProvisioningManagement: React.FC<AccountProvisioningProps> = ({ embedded = false }) => {
  const { isCooldownActive, remainingSeconds, message: cooldownMessage, startCooldown } = useModuleCooldown('account_provisioning');

  // 1. FORM STATE (THÊM MỚI TÀI KHOẢN)
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [phoneNumber, setPhoneNumber] = useState('');
  const [nationalIdNumber, setNationalIdNumber] = useState('');
  const [gender, setGender] = useState<'MALE' | 'FEMALE' | 'OTHER'>('MALE');
  const [roleCode, setRoleCode] = useState('RECEPTIONIST');

  // 2. MODAL SỬA TÀI KHOẢN (EDIT)
  const [editingUser, setEditingUser] = useState<ProvisionedUser | null>(null);
  const [editFullName, setEditFullName] = useState('');
  const [editPhoneNumber, setEditPhoneNumber] = useState('');
  const [editNationalIdNumber, setEditNationalIdNumber] = useState('');
  const [editGender, setEditGender] = useState<'MALE' | 'FEMALE' | 'OTHER'>('MALE');
  const [editRoleCode, setEditRoleCode] = useState('RECEPTIONIST');
  const [updating, setUpdating] = useState(false);

  // 3. MODAL XÓA TÀI KHOẢN (DELETE)
  const [deletingUser, setDeletingUser] = useState<ProvisionedUser | null>(null);
  const [deleting, setDeleting] = useState(false);

  // 4. MODAL XEM CHI TIẾT (DETAIL)
  const [detailUser, setDetailUser] = useState<ProvisionedUser | null>(null);

  // 5. MODAL NHẬP HÀNG LOẠT (IMPORT EXCEL / CSV)
  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [importText, setImportText] = useState('');
  const [parsedRows, setParsedRows] = useState<Array<any>>([]);
  const [importing, setImporting] = useState(false);
  const [importReport, setImportReport] = useState<{ success: number; failed: number; errors: string[] } | null>(null);

  // UI STATE CHUNG
  const [submitting, setSubmitting] = useState(false);

  // Đọc snapshot lưu trong sessionStorage để hiển thị tức thì (0ms) cho lần tải thứ 2
  const getCachedUsers = () => {
    try {
      const raw = sessionStorage.getItem('smartcassavas_account_prov_cache');
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  };
  const cachedUsers = getCachedUsers();

  const [loadingList, setLoadingList] = useState<boolean>(() => !cachedUsers);
  const [resendingId, setResendingId] = useState<string | null>(null);
  const [batchResending, setBatchResending] = useState(false);
  const [togglingLockId, setTogglingLockId] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const searchAbortRef = useRef<AbortController | null>(null);
  const [statusFilter, setStatusFilter] = useState('');
  const [users, setUsers] = useState<ProvisionedUser[]>(() => (Array.isArray(cachedUsers) ? cachedUsers : []));
  const [createdResult, setCreatedResult] = useState<any | null>(null);
  const [toast, setToast] = useState<{ message: string; type: 'success' | 'error' | 'info' } | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchTerm);
    }, 350);
    return () => clearTimeout(timer);
  }, [searchTerm]);

  // Hiển thị toast
  const showToast = (message: string, type: 'success' | 'error' | 'info' = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 4000);
  };

  // Tải danh sách người dùng từ API
  const loadUsers = useCallback(async (customSearch?: string) => {
    if (searchAbortRef.current) {
      searchAbortRef.current.abort();
    }
    const controller = new AbortController();
    searchAbortRef.current = controller;

    try {
      setLoadingList(true);
      const token = localStorage.getItem('smart_cassavas_token');
      const query = new URLSearchParams();
      const effectiveSearch = customSearch !== undefined ? customSearch : debouncedSearch;
      if (effectiveSearch) query.append('search', effectiveSearch);
      if (statusFilter) query.append('status', statusFilter);
      query.append('limit', '50');

      const res = await fetch(`/api/v1/account-provisioning?${query.toString()}`, {
        headers: {
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        },
        signal: controller.signal,
      });

      if (res.ok) {
        const json = await res.json();
        setUsers(json.data || []);
        if (!effectiveSearch && !statusFilter) {
          try {
            sessionStorage.setItem('smartcassavas_account_prov_cache', JSON.stringify(json.data || []));
          } catch {
            // ignore
          }
        }
      }
    } catch (err: any) {
      if (err?.name !== 'AbortError') {
        console.error('Lỗi tải danh sách tài khoản:', err);
      }
    } finally {
      if (searchAbortRef.current === controller) {
        setLoadingList(false);
      }
    }
  }, [debouncedSearch, statusFilter]);

  useEffect(() => {
    loadUsers();
  }, [loadUsers]);

  // Realtime Auto-Sync Listener (Quốc Tín - Account Provisioning)
  useRealtimeSync({
    channel: 'quoc-tin.account-provisioning',
    onEvent: (event) => {
      // 0. Cập nhật state in-memory ngay lập tức
      if (event.action === 'DELETED' && event.entity_id) {
        setUsers((prev) => prev.filter((u) => u.id !== event.entity_id));
      } else if (event.action === 'STATUS_CHANGED' && event.entity_id && event.status) {
        setUsers((prev) =>
          prev.map((u) => (u.id === event.entity_id ? { ...u, status: event.status } : u))
        );
      }

      // 1. Invalidate cache
      try {
        sessionStorage.removeItem('smartcassavas_account_prov_cache');
      } catch {
        // ignore
      }

      // 2. Refetch danh sách tài khoản (bảo toàn filter, search)
      loadUsers();

      // 3. Nếu đang mở modal chi tiết của user bị sửa, cập nhật modal
      if (detailUser && detailUser.id === event.entity_id) {
        const token = localStorage.getItem('smart_cassavas_token');
        fetch(`/api/v1/account-provisioning/${detailUser.id}`, {
          headers: {
            Accept: 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
          },
        })
          .then((res) => res.json())
          .then((json: any) => {
            if (json.data) setDetailUser(json.data);
          })
          .catch(() => {});
      }
    },
    onReconnect: () => {
      loadUsers();
    },
  });

  // ========================================================
  // CHỨC NĂNG 1: THÊM MỚI / CẤP PHÁT TÀI KHOẢN TỰ ĐỘNG
  // ========================================================
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!fullName.trim() || !email.trim() || !phoneNumber.trim()) {
      showToast('Vui lòng điền đầy đủ các thông tin bắt buộc.', 'error');
      return;
    }

    try {
      setSubmitting(true);
      const token = localStorage.getItem('smart_cassavas_token');
      const payload = {
        full_name: fullName.trim(),
        email: email.trim().toLowerCase(),
        phone_number: phoneNumber.trim(),
        national_id_number: nationalIdNumber.trim() || null,
        gender,
        roles: [roleCode],
      };

      const res = await fetch('/api/v1/account-provisioning', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        },
        body: JSON.stringify(payload)
      });

      const json = await res.json();

      if (!res.ok) {
        throw new Error(json.message || 'Cấp phát tài khoản thất bại.');
      }

      setCreatedResult(json.data);
      showToast(`Cấp phát thành công tài khoản [${json.data.username}]! Email kích hoạt đã được gửi.`);

      // Reset Form
      setFullName('');
      setEmail('');
      setPhoneNumber('');
      setNationalIdNumber('');

      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Lỗi cấp phát tài khoản.', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  // Tiện ích: Tạo ngẫu nhiên dữ liệu mẫu
  const handleQuickFillDemo = () => {
    const firstNames = ['Nguyễn Văn', 'Trần Thị', 'Lê Hoàng', 'Phạm Quốc', 'Đặng Minh', 'Vũ Anh', 'Bùi Đức'];
    const lastNames = ['An', 'Bình', 'Cường', 'Dũng', 'Hải', 'Khang', 'Nam', 'Thảo', 'Trang', 'Yến'];
    const randomFullName = `${firstNames[Math.floor(Math.random() * firstNames.length)]} ${lastNames[Math.floor(Math.random() * lastNames.length)]}`;
    const randId = Math.random().toString(36).substring(2, 7);
    const randomEmail = `staff_${randId}@example.com`;
    const randomPhone = `09${Math.floor(10000000 + Math.random() * 90000000)}`;
    const randomNationalId = `079${Math.floor(100000000 + Math.random() * 900000000)}`;

    setFullName(randomFullName);
    setEmail(randomEmail);
    setPhoneNumber(randomPhone);
    setNationalIdNumber(randomNationalId);
    showToast('Đã điền thông tin nhân viên mẫu ngẫu nhiên!', 'info');
  };

  // ========================================================
  // CHỨC NĂNG 2: CHỈNH SỬA TÀI KHOẢN (EDIT)
  // ========================================================
  const handleOpenEdit = (user: ProvisionedUser) => {
    setEditingUser(user);
    setEditFullName(user.full_name || '');
    setEditPhoneNumber(user.phone_number || '');
    setEditNationalIdNumber(user.national_id_number || '');
    setEditGender(user.gender || 'MALE');
    setEditRoleCode(user.roles?.[0]?.role_code || 'RECEPTIONIST');
  };

  const handleSaveEdit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingUser) return;

    try {
      setUpdating(true);
      const token = localStorage.getItem('smart_cassavas_token');
      const payload = {
        full_name: editFullName.trim(),
        phone_number: editPhoneNumber.trim(),
        national_id_number: editNationalIdNumber.trim() || null,
        gender: editGender,
        roles: [editRoleCode],
      };

      const res = await fetch(`/api/v1/account-provisioning/${editingUser.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        },
        body: JSON.stringify(payload)
      });

      const json = await res.json();
      if (!res.ok) {
        throw new Error(json.message || 'Cập nhật tài khoản thất bại.');
      }

      showToast(`Đã cập nhật thông tin tài khoản [${editingUser.username}] thành công!`);
      setEditingUser(null);
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Lỗi khi cập nhật tài khoản.', 'error');
    } finally {
      setUpdating(false);
    }
  };

  // ========================================================
  // CHỨC NĂNG 3: XÓA TÀI KHOẢN (DELETE)
  // ========================================================
  const handleConfirmDelete = async () => {
    if (!deletingUser) return;

    try {
      setDeleting(true);
      const token = localStorage.getItem('smart_cassavas_token');
      const res = await fetch(`/api/v1/account-provisioning/${deletingUser.id}`, {
        method: 'DELETE',
        headers: {
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        }
      });

      const json = await res.json();
      if (!res.ok) {
        throw new Error(json.message || 'Xóa tài khoản thất bại.');
      }

      showToast(`Đã xóa tài khoản [${deletingUser.username}] thành công.`);
      setDeletingUser(null);
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Lỗi khi xóa tài khoản.', 'error');
    } finally {
      setDeleting(false);
    }
  };

  // ========================================================
  // CHỨC NĂNG 4: XEM CHI TIẾT TÀI KHOẢN (VIEW DETAIL)
  // ========================================================
  const handleOpenDetail = (user: ProvisionedUser) => {
    setDetailUser(user);
  };

  // ========================================================
  // CHỨC NĂNG 5: NHẬP DANH SÁCH (IMPORT EXCEL / CSV)
  // ========================================================
  const handleDownloadTemplate = () => {
    const csvContent =
      '\uFEFF' +
      'Họ và tên,Email,Số điện thoại,Số CCCD,Giới tính,Vai trò\n' +
      'Nguyễn Văn A,nguyenvana@example.com,0912345678,079123456789,MALE,RECEPTIONIST\n' +
      'Trần Thị B,tranthib@example.com,0987654321,079987654321,FEMALE,TECHNICIAN\n' +
      'Lê Quốc C,lequocc@example.com,0934567890,079345678901,MALE,SECURITY\n';

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', 'mau_nhap_tai_khoan_cassavas.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    showToast('Đã tải file mẫu CSV chuẩn!', 'info');
  };

  const parseCsvText = (text: string) => {
    const lines = text.trim().split('\n');
    if (lines.length < 2) {
      setParsedRows([]);
      return;
    }

    const rows: any[] = [];
    for (let i = 1; i < lines.length; i++) {
      const line = lines[i].trim();
      if (!line) continue;
      const parts = line.split(',').map((p) => p.trim().replace(/^["']|["']$/g, ''));
      if (parts.length >= 3 && parts[0] && parts[1] && parts[2]) {
        rows.push({
          full_name: parts[0],
          email: parts[1],
          phone_number: parts[2],
          national_id_number: parts[3] || '',
          gender: parts[4] || 'MALE',
          roles: parts[5] ? [parts[5]] : ['RECEPTIONIST'],
        });
      }
    }
    setParsedRows(rows);
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (event) => {
      const content = event.target?.result as string;
      setImportText(content);
      parseCsvText(content);
    };
    reader.readAsText(file, 'UTF-8');
  };

  const handleStartImport = async () => {
    if (parsedRows.length === 0) {
      showToast('Chưa có dữ liệu dòng hợp lệ để nhập.', 'error');
      return;
    }

    try {
      setImporting(true);
      const token = localStorage.getItem('smart_cassavas_token');

      const res = await fetch('/api/v1/account-provisioning/import', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        },
        body: JSON.stringify({ records: parsedRows })
      });

      const json = await res.json();
      if (!res.ok) {
        throw new Error(json.message || 'Lỗi khi nhập dữ liệu.');
      }

      setImportReport({
        success: json.data?.success_count || 0,
        failed: json.data?.failed_count || 0,
        errors: json.data?.errors || []
      });

      showToast(`Đã nhập thành công ${json.data?.success_count} tài khoản!`);
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Lỗi khi nhập dữ liệu.', 'error');
    } finally {
      setImporting(false);
    }
  };

  // ========================================================
  // CHỨC NĂNG 6: XUẤT DANH SÁCH (EXPORT EXCEL / CSV)
  // ========================================================
  const handleExportCsv = () => {
    if (users.length === 0) {
      showToast('Danh sách tài khoản hiện đang trống, không thể xuất file.', 'info');
      return;
    }

    let csvContent = '\uFEFFMã tài khoản,Username,Họ và tên,Email,Số điện thoại,Số CCCD,Vai trò,Trạng thái,Thời gian tạo\n';

    users.forEach((u) => {
      const roleName = u.roles?.[0]?.role_name || u.roles?.[0]?.role_code || 'Chưa gán';
      const statusText =
        u.status === 'ACTIVE'
          ? 'Đã kích hoạt'
          : u.status === 'PENDING_ACTIVATION'
          ? 'Chờ kích hoạt'
          : u.status === 'LOCKED'
          ? 'Tạm khóa'
          : u.status;
      const cleanName = `"${(u.full_name || '').replace(/"/g, '""')}"`;
      const cleanEmail = `"${(u.email || '').replace(/"/g, '""')}"`;
      const cleanPhone = `"${(u.phone_number || '').replace(/"/g, '""')}"`;
      const cleanId = `"${(u.national_id_number || '').replace(/"/g, '""')}"`;
      const cleanRole = `"${roleName.replace(/"/g, '""')}"`;

      csvContent += `${u.id},${u.username},${cleanName},${cleanEmail},${cleanPhone},${cleanId},${cleanRole},${statusText},${u.created_at}\n`;
    });

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const now = new Date();
    const dateStr = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}_${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}`;
    link.href = url;
    link.setAttribute('download', `danh_sach_tai_khoan_cassavas_${dateStr}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    showToast(`Đã xuất thành công ${users.length} tài khoản ra file CSV!`);
  };

  // ========================================================
  // CHỨC NĂNG 7: CÁC CHỨC NĂNG ĐẶC BIỆT
  // (Gửi lại email, Khóa / Mở khóa, Gửi lại hàng loạt)
  // ========================================================

  // Gửi lại email kích hoạt đơn lẻ
  const handleResend = async (user: ProvisionedUser) => {
    try {
      setResendingId(user.id);
      const token = localStorage.getItem('smart_cassavas_token');

      const res = await fetch(`/api/v1/account-provisioning/${user.id}/resend-activation`, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        }
      });

      const json = await res.json();
      if (!res.ok) {
        throw new Error(json.message || 'Gửi lại email thất bại.');
      }

      showToast(`Đã gửi lại email kích hoạt tới ${user.email}`);
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Lỗi khi gửi lại email kích hoạt.', 'error');
    } finally {
      setResendingId(null);
    }
  };

  // Khóa hoặc Mở khóa tài khoản (Toggle Lock)
  const handleToggleLock = async (user: ProvisionedUser) => {
    try {
      setTogglingLockId(user.id);
      const token = localStorage.getItem('smart_cassavas_token');

      const res = await fetch(`/api/v1/account-provisioning/${user.id}/toggle-lock`, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        }
      });

      const json = await res.json();
      if (!res.ok) {
        throw new Error(json.message || 'Không thể thay đổi trạng thái khóa.');
      }

      showToast(json.message);
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Lỗi khi khóa/mở khóa tài khoản.', 'error');
    } finally {
      setTogglingLockId(null);
    }
  };

  // Gửi lại email kích hoạt hàng loạt cho tất cả tài khoản đang chờ
  const handleBatchResend = async () => {
    const pendingCount = users.filter((u) => u.status === 'PENDING_ACTIVATION').length;
    if (pendingCount === 0) {
      showToast('Hiện không có tài khoản nào đang trong trạng thái chờ kích hoạt.', 'info');
      return;
    }

    if (!confirm(`Bạn có chắc chắn muốn gửi lại email kích hoạt cho tất cả (${pendingCount}) tài khoản đang chờ?`)) {
      return;
    }

    try {
      setBatchResending(true);
      const token = localStorage.getItem('smart_cassavas_token');

      const res = await fetch('/api/v1/account-provisioning/batch-resend', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        }
      });

      const json = await res.json();
      if (!res.ok) {
        throw new Error(json.message || 'Lỗi khi gửi email hàng loạt.');
      }

      showToast(json.message || 'Đã gửi lại email hàng loạt thành công!');
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Lỗi khi gửi lại email hàng loạt.', 'error');
    } finally {
      setBatchResending(false);
    }
  };

  const pendingUsersCount = users.filter((u) => u.status === 'PENDING_ACTIVATION').length;

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* Toast Notification */}
      {toast && (
        <div
          className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-2xl shadow-2xl text-xs font-semibold backdrop-blur-md border animate-in fade-in slide-in-from-bottom-3 duration-200 ${
            toast.type === 'error'
              ? 'bg-rose-950/90 text-rose-200 border-rose-800'
              : toast.type === 'info'
              ? 'bg-sky-950/90 text-sky-200 border-sky-800'
              : 'bg-emerald-950/90 text-emerald-200 border-emerald-800'
          }`}
        >
          {toast.type === 'error' ? (
            <AlertCircle className="w-4 h-4 text-rose-400 shrink-0" />
          ) : (
            <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
          )}
          <span>{toast.message}</span>
        </div>
      )}

      {/* Header Phân hệ */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white/70 backdrop-blur-xl border border-white/70 p-6 rounded-3xl shadow-sm">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 rounded-2xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-sky-500/20">
            <UserPlus className="w-6 h-6" />
          </div>
          <div>
            <h2 className="text-xl font-bold text-slate-900 tracking-tight flex items-center gap-2">
              Cấp Phát Tài Khoản Tự Động
              <span className="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-sky-100 text-sky-700 border border-sky-200/60">
                7 Chức Năng
              </span>
            </h2>
            <p className="text-xs text-slate-500 mt-1">
              Thêm, Sửa, Xóa, Chi tiết, Nhập Excel, Xuất Excel & Các chức năng kích hoạt / khóa tài khoản đặc biệt.
            </p>
          </div>
        </div>

        {/* Thanh tác vụ nhanh */}
        <div className="flex flex-wrap items-center gap-2">
          {pendingUsersCount > 0 && (
            <button
              onClick={handleBatchResend}
              disabled={batchResending}
              className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded-xl transition-all shadow-sm disabled:opacity-50"
              title="Gửi lại email cho tất cả tài khoản đang chờ kích hoạt"
            >
              <Send className={`w-3.5 h-3.5 ${batchResending ? 'animate-pulse' : ''}`} />
              <span>Gửi lại tất cả ({pendingUsersCount})</span>
            </button>
          )}

          <button
            onClick={() => setIsImportModalOpen(true)}
            className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-xl transition-all shadow-sm"
          >
            <Upload className="w-3.5 h-3.5" />
            <span>Nhập Excel / CSV</span>
          </button>

          <button
            onClick={handleExportCsv}
            className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 rounded-xl transition-all shadow-sm"
          >
            <Download className="w-3.5 h-3.5" />
            <span>Xuất Excel / CSV</span>
          </button>

          <button
            onClick={() => loadUsers()}
            disabled={loadingList}
            className="flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-slate-600 bg-white hover:bg-slate-50 border border-slate-200/80 rounded-xl transition-all shadow-sm disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loadingList ? 'animate-spin' : ''}`} />
            <span>Làm mới</span>
          </button>
        </div>
      </div>

      {/* Cooldown Banner */}
      <CooldownBanner
        isCooldownActive={isCooldownActive}
        remainingSeconds={remainingSeconds}
        customMessage={cooldownMessage}
      />

      {/* Layout Grid 2 cột: Form Cấp phát & Bảng Danh sách */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* CỘT TRÁI: FORM CẤP PHÁT TÀI KHOẢN (4 Cột) */}
        <div className="lg:col-span-4">
          <div className="bg-white/80 backdrop-blur-xl border border-white/80 rounded-3xl p-6 shadow-sm sticky top-6">
            <div className="flex items-center justify-between mb-5 pb-4 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <Sparkles className="w-4 h-4 text-sky-600" />
                <h3 className="text-sm font-bold text-slate-900">1. Cấp phát tài khoản</h3>
              </div>
              <button
                type="button"
                onClick={handleQuickFillDemo}
                className="text-[11px] font-semibold text-sky-600 hover:text-sky-800 bg-sky-50 hover:bg-sky-100 px-2 py-1 rounded-lg border border-sky-200/60 transition-all flex items-center gap-1"
                title="Tự động điền dữ liệu nhân viên ngẫu nhiên để test nhanh"
              >
                <Sparkles className="w-3 h-3" />
                <span>Điền mẫu</span>
              </button>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Họ tên <span className="text-rose-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  placeholder="Ví dụ: Nguyễn Văn An"
                  value={fullName}
                  onChange={(e) => setFullName(e.target.value)}
                  className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Email cá nhân <span className="text-rose-500">*</span>
                </label>
                <div className="relative">
                  <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                  <input
                    type="email"
                    required
                    placeholder="nguyenvanan@example.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className="w-full pl-9 pr-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Số điện thoại <span className="text-rose-500">*</span>
                </label>
                <div className="relative">
                  <Phone className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                  <input
                    type="tel"
                    required
                    placeholder="0912345678"
                    value={phoneNumber}
                    onChange={(e) => setPhoneNumber(e.target.value)}
                    className="w-full pl-9 pr-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-slate-700 mb-1.5">
                    CCCD / Passport
                  </label>
                  <input
                    type="text"
                    placeholder="079123456789"
                    value={nationalIdNumber}
                    onChange={(e) => setNationalIdNumber(e.target.value)}
                    className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-700 mb-1.5">
                    Giới tính
                  </label>
                  <select
                    value={gender}
                    onChange={(e: any) => setGender(e.target.value)}
                    className="w-full px-3 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none"
                  >
                    <option value="MALE">Nam</option>
                    <option value="FEMALE">Nữ</option>
                    <option value="OTHER">Khác</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Vai trò phân quyền <span className="text-rose-500">*</span>
                </label>
                <div className="relative">
                  <Shield className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                  <select
                    value={roleCode}
                    onChange={(e) => setRoleCode(e.target.value)}
                    className="w-full pl-9 pr-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                  >
                    <option value="RECEPTIONIST">Nhân Viên Lễ Tân</option>
                    <option value="TECHNICIAN">Nhân Viên Kỹ Thuật</option>
                    <option value="ACCOUNTANT">Nhân Viên Kế Toán</option>
                    <option value="SECURITY">Nhân Viên An Ninh</option>
                    <option value="BUILDING_MANAGER">Ban Quản Lý Tòa Nhà</option>
                    <option value="RESIDENT_OWNER">Cư Dân / Chủ Hộ</option>
                  </select>
                </div>
              </div>

              <div className="p-3 bg-sky-50/70 border border-sky-100 rounded-2xl text-[11px] text-sky-800 leading-relaxed">
                <span className="font-semibold block mb-0.5">Quy trình cấp phát tự động:</span>
                1. Hệ thống tự tạo username & mật khẩu ngẫu nhiên.<br />
                2. Gửi email kích hoạt tới địa chỉ người dùng.<br />
                3. Người dùng nhấp link và tự thiết lập mật khẩu an toàn.
              </div>

              <button
                type="submit"
                disabled={submitting}
                className="w-full mt-2 py-3 px-4 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-sky-600/20 flex items-center justify-center gap-2 transition-all disabled:opacity-50"
                title="Cấp tài khoản mới"
              >
                {submitting ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin" />
                    Đang sinh tài khoản & gửi email...
                  </>
                ) : (
                  <>
                    <Sparkles className="w-4 h-4" />
                    Cấp tài khoản mới
                  </>
                )}
              </button>
            </form>
          </div>
        </div>

        {/* CỘT PHẢI: BẢNG DANH SÁCH TÀI KHOẢN (8 Cột) */}
        <div className="lg:col-span-8 space-y-4">
          {/* Thông báo kết quả vừa tạo nếu có */}
          {createdResult && (
            <div className="bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200/80 rounded-3xl p-5 shadow-sm animate-in fade-in duration-300">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl bg-emerald-500 text-white flex items-center justify-center shadow-md">
                    <CheckCircle2 className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="text-xs font-bold text-emerald-950 uppercase tracking-wider">
                      Cấp phát thành công!
                    </h4>
                    <p className="text-xs text-emerald-800 mt-0.5">
                      Đã khởi tạo tài khoản <strong>{createdResult.full_name}</strong> và gửi email kích hoạt tới <strong>{createdResult.email}</strong>.
                    </p>
                  </div>
                </div>
                <button
                  onClick={() => setCreatedResult(null)}
                  className="text-xs text-emerald-700 hover:text-emerald-900 font-medium px-2 py-1 rounded-lg hover:bg-emerald-100/50"
                >
                  Đóng
                </button>
              </div>

              <div className="mt-4 pt-3 border-t border-emerald-200/50 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div className="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                  <span className="text-[10px] text-slate-400 block">Username tự sinh</span>
                  <span className="font-mono font-bold text-slate-800">{createdResult.username}</span>
                </div>
                <div className="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                  <span className="text-[10px] text-slate-400 block">Số điện thoại</span>
                  <span className="font-semibold text-slate-800">{createdResult.phone_number}</span>
                </div>
                <div className="bg-white/80 rounded-xl p-2.5 border border-emerald-100">
                  <span className="text-[10px] text-slate-400 block">Trạng thái</span>
                  <span className="inline-flex items-center gap-1 font-semibold text-amber-600">
                    <Clock className="w-3 h-3" />
                    Chờ kích hoạt
                  </span>
                </div>
              </div>
            </div>
          )}

          {/* Bảng Danh Sách */}
          <div className="bg-white/80 backdrop-blur-xl border border-white/80 rounded-3xl p-6 shadow-sm">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 pb-4 border-b border-slate-100">
              <div>
                <h3 className="text-sm font-bold text-slate-900">Danh Sách Tài Khoản Hệ Thống</h3>
                <p className="text-[11px] text-slate-400">
                  Quản lý đầy đủ thông tin, trạng thái kích hoạt, sửa đổi, xóa và xuất nhập dữ liệu
                </p>
              </div>

              <div className="flex items-center gap-2">
                <div className="relative">
                  <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-2.5" />
                  <input
                    type="text"
                    placeholder="Tìm tên, username, email..."
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && loadUsers()}
                    className="pl-8 pr-3 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-sky-500 w-44 sm:w-52"
                  />
                </div>

                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                  className="px-2.5 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none"
                >
                  <option value="">Tất cả trạng thái</option>
                  <option value="PENDING_ACTIVATION">Chờ kích hoạt</option>
                  <option value="ACTIVE">Đã kích hoạt</option>
                  <option value="LOCKED">Tạm khóa</option>
                </select>
              </div>
            </div>

            {/* Bảng dữ liệu */}
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr className="border-b border-slate-100 text-slate-400 font-medium">
                    <th className="pb-3 pl-1">Người dùng & Tài khoản</th>
                    <th className="pb-3">Thông tin liên lạc</th>
                    <th className="pb-3">Vai trò</th>
                    <th className="pb-3">Trạng thái</th>
                    <th className="pb-3 text-right pr-1">Thao tác</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {loadingList ? (
                    <tr>
                      <td colSpan={5} className="py-8 text-center text-slate-400">
                        <RefreshCw className="w-5 h-5 animate-spin mx-auto mb-2 text-slate-300" />
                        Đang tải danh sách tài khoản...
                      </td>
                    </tr>
                  ) : users.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="py-8 text-center text-slate-400">
                        Không tìm thấy tài khoản nào phù hợp.
                      </td>
                    </tr>
                  ) : (
                    users.map((u) => {
                      const isPending = u.status === 'PENDING_ACTIVATION';
                      const isLocked = u.status === 'LOCKED';
                      const roleName = u.roles?.[0]?.role_name || u.roles?.[0]?.role_code || 'Chưa gán';

                      return (
                        <tr key={u.id} className="hover:bg-slate-50/70 transition-colors">
                          <td className="py-3.5 pl-1">
                            <div className="font-semibold text-slate-800">{u.full_name}</div>
                            <div className="font-mono text-[11px] text-sky-700 bg-sky-50 inline-block px-1.5 py-0.5 rounded mt-0.5">
                              @{u.username}
                            </div>
                          </td>
                          <td className="py-3.5">
                            <div className="text-slate-700">{u.email}</div>
                            <div className="text-slate-400 text-[11px]">{u.phone_number}</div>
                          </td>
                          <td className="py-3.5">
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-700 border border-slate-200">
                              {roleName}
                            </span>
                          </td>
                          <td className="py-3.5">
                            {isPending ? (
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200/60">
                                <Clock className="w-3 h-3" />
                                Chờ kích hoạt
                              </span>
                            ) : isLocked ? (
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200/60">
                                <Lock className="w-3 h-3" />
                                Tạm khóa
                              </span>
                            ) : u.status === 'ACTIVE' ? (
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                                <CheckCircle2 className="w-3 h-3" />
                                Đã kích hoạt
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-600">
                                {u.status}
                              </span>
                            )}
                          </td>
                          <td className="py-3.5 text-right pr-1">
                            <div className="flex items-center justify-end gap-1.5">
                              {/* 1. Xem chi tiết */}
                              <button
                                onClick={() => handleOpenDetail(u)}
                                className="p-1.5 text-slate-500 hover:text-sky-600 hover:bg-sky-50 rounded-lg transition-all"
                                title="Xem chi tiết tài khoản"
                              >
                                <Eye className="w-3.5 h-3.5" />
                              </button>

                              {/* 2. Chỉnh sửa */}
                              <button
                                onClick={() => handleOpenEdit(u)}
                                className="p-1.5 text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all"
                                title="Chỉnh sửa thông tin"
                              >
                                <Pencil className="w-3.5 h-3.5" />
                              </button>

                              {/* 3. Chức năng đặc biệt: Gửi lại email (nếu PENDING) */}
                              {isPending && (
                                <button
                                  onClick={() => handleResend(u)}
                                  disabled={resendingId === u.id}
                                  className="inline-flex items-center gap-1 px-2 py-1 text-[11px] font-semibold text-sky-700 bg-sky-50 hover:bg-sky-100 border border-sky-200 rounded-lg transition-all disabled:opacity-50"
                                  title="Gửi lại email kích hoạt"
                                >
                                  <Send className={`w-3 h-3 ${resendingId === u.id ? 'animate-pulse' : ''}`} />
                                  <span>{resendingId === u.id ? '...' : 'Gửi lại'}</span>
                                </button>
                              )}

                              {/* 4. Chức năng đặc biệt: Khóa / Mở khóa (nếu không pending) */}
                              {!isPending && (
                                <button
                                  onClick={() => handleToggleLock(u)}
                                  disabled={togglingLockId === u.id}
                                  className={`p-1.5 rounded-lg transition-all ${
                                    isLocked
                                      ? 'text-emerald-600 hover:bg-emerald-50'
                                      : 'text-amber-600 hover:bg-amber-50'
                                  }`}
                                  title={isLocked ? 'Mở khóa tài khoản' : 'Khóa tài khoản'}
                                >
                                  {isLocked ? <Unlock className="w-3.5 h-3.5" /> : <Lock className="w-3.5 h-3.5" />}
                                </button>
                              )}

                              {/* 5. Xóa tài khoản */}
                              <button
                                onClick={() => setDeletingUser(u)}
                                className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-all"
                                title="Xóa tài khoản"
                              >
                                <Trash2 className="w-3.5 h-3.5" />
                              </button>
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
      </div>

      {/* ========================================================
          MODAL 1: XEM CHI TIẾT TÀI KHOẢN (DETAIL MODAL)
          ======================================================== */}
      {detailUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 space-y-5">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-9 h-9 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center font-bold">
                  <Eye className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-900">Chi Tiết Hồ Sơ Tài Khoản</h3>
                  <span className="font-mono text-[11px] text-sky-700">@{detailUser.username}</span>
                </div>
              </div>
              <button
                onClick={() => setDetailUser(null)}
                className="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="grid grid-cols-2 gap-3 text-xs">
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                <span className="text-[10px] text-slate-400 block uppercase">Họ và tên</span>
                <span className="font-bold text-slate-800 text-sm">{detailUser.full_name}</span>
              </div>
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                <span className="text-[10px] text-slate-400 block uppercase">Trạng thái</span>
                <span className="font-bold text-slate-800">
                  {detailUser.status === 'ACTIVE'
                    ? 'Đã kích hoạt'
                    : detailUser.status === 'PENDING_ACTIVATION'
                    ? 'Chờ kích hoạt'
                    : detailUser.status === 'LOCKED'
                    ? 'Tạm khóa'
                    : detailUser.status}
                </span>
              </div>
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                <span className="text-[10px] text-slate-400 block uppercase">Email</span>
                <span className="font-mono text-slate-700">{detailUser.email}</span>
              </div>
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                <span className="text-[10px] text-slate-400 block uppercase">Số điện thoại</span>
                <span className="font-semibold text-slate-700">{detailUser.phone_number}</span>
              </div>
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                <span className="text-[10px] text-slate-400 block uppercase">Số CCCD / Hộ chiếu</span>
                <span className="font-mono text-slate-700">{detailUser.national_id_number || 'Chưa cập nhật'}</span>
              </div>
              <div className="p-3 bg-slate-50 rounded-xl border border-slate-100">
                <span className="text-[10px] text-slate-400 block uppercase">Vai trò</span>
                <span className="font-semibold text-slate-700">
                  {detailUser.roles?.[0]?.role_name || detailUser.roles?.[0]?.role_code || 'Chưa gán'}
                </span>
              </div>
            </div>

            {/* Thông tin Cấp phát & Lịch sử Kích hoạt */}
            <div className="p-3.5 bg-sky-50/70 border border-sky-100 rounded-2xl text-xs space-y-1.5 text-sky-900">
              <span className="font-bold block text-[11px] uppercase tracking-wide text-sky-950">
                Lịch sử cấp phát & bảo mật:
              </span>
              <div className="flex justify-between text-[11px]">
                <span className="text-sky-700">Thời điểm khởi tạo:</span>
                <span>{detailUser.created_at}</span>
              </div>
              {detailUser.extra_preferences?.provisioning?.activation_sent_at && (
                <div className="flex justify-between text-[11px]">
                  <span className="text-sky-700">Lần gửi email gần nhất:</span>
                  <span>{detailUser.extra_preferences.provisioning.activation_sent_at}</span>
                </div>
              )}
              {detailUser.extra_preferences?.provisioning?.resend_count !== undefined && (
                <div className="flex justify-between text-[11px]">
                  <span className="text-sky-700">Số lần yêu cầu gửi lại:</span>
                  <span className="font-bold">{detailUser.extra_preferences.provisioning.resend_count} lần</span>
                </div>
              )}
              {detailUser.extra_preferences?.provisioning?.activated_at && (
                <div className="flex justify-between text-[11px]">
                  <span className="text-emerald-700 font-semibold">Thời điểm kích hoạt thành công:</span>
                  <span className="font-bold text-emerald-800">{detailUser.extra_preferences.provisioning.activated_at}</span>
                </div>
              )}
            </div>

            <div className="flex justify-end pt-2">
              <button
                type="button"
                onClick={() => setDetailUser(null)}
                className="px-4 py-2 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-all"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL 2: CHỈNH SỬA TÀI KHOẢN (EDIT MODAL)
          ======================================================== */}
      {editingUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 space-y-5">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-9 h-9 rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold">
                  <Pencil className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-900">2. Chỉnh Sửa Tài Khoản</h3>
                  <span className="font-mono text-[11px] text-sky-700">@{editingUser.username}</span>
                </div>
              </div>
              <button
                onClick={() => setEditingUser(null)}
                className="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleSaveEdit} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Họ và tên <span className="text-rose-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  value={editFullName}
                  onChange={(e) => setEditFullName(e.target.value)}
                  className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Số điện thoại <span className="text-rose-500">*</span>
                </label>
                <input
                  type="tel"
                  required
                  value={editPhoneNumber}
                  onChange={(e) => setEditPhoneNumber(e.target.value)}
                  className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-slate-700 mb-1.5">
                    CCCD / Hộ chiếu
                  </label>
                  <input
                    type="text"
                    value={editNationalIdNumber}
                    onChange={(e) => setEditNationalIdNumber(e.target.value)}
                    className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-700 mb-1.5">
                    Giới tính
                  </label>
                  <select
                    value={editGender}
                    onChange={(e: any) => setEditGender(e.target.value)}
                    className="w-full px-3 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none"
                  >
                    <option value="MALE">Nam</option>
                    <option value="FEMALE">Nữ</option>
                    <option value="OTHER">Khác</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Vai trò phân quyền
                </label>
                <select
                  value={editRoleCode}
                  onChange={(e) => setEditRoleCode(e.target.value)}
                  className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                >
                  <option value="RECEPTIONIST">Nhân Viên Lễ Tân</option>
                  <option value="TECHNICIAN">Nhân Viên Kỹ Thuật</option>
                  <option value="ACCOUNTANT">Nhân Viên Kế Toán</option>
                  <option value="SECURITY">Nhân Viên An Ninh</option>
                  <option value="BUILDING_MANAGER">Ban Quản Lý Tòa Nhà</option>
                  <option value="RESIDENT_OWNER">Cư Dân / Chủ Hộ</option>
                </select>
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setEditingUser(null)}
                  className="px-4 py-2 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-all"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={updating}
                  className="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition-all disabled:opacity-50 flex items-center gap-1.5"
                >
                  {updating ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                  <span>{updating ? 'Đang lưu...' : 'Lưu Thay Đổi'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL 3: XÁC NHẬN XÓA TÀI KHOẢN (DELETE MODAL)
          ======================================================== */}
      {deletingUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-slate-100 space-y-4 text-center">
            <div className="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mx-auto">
              <AlertTriangle className="w-6 h-6" />
            </div>

            <div>
              <h3 className="text-base font-bold text-slate-900">Xác Nhận Xóa Tài Khoản?</h3>
              <p className="text-xs text-slate-500 mt-1">
                Bạn có chắc chắn muốn xóa tài khoản <strong>{deletingUser.full_name}</strong> (
                <span className="font-mono">@{deletingUser.username}</span>)?
              </p>
              <div className="mt-2 p-2.5 bg-rose-50 border border-rose-200 rounded-xl text-[11px] text-rose-800 text-left">
                Hành động này sẽ vô hiệu hóa tài khoản và thu hồi tất cả phiên đăng nhập cũng như mã kích hoạt của người dùng.
              </div>
            </div>

            <div className="flex justify-center gap-3 pt-2">
              <button
                type="button"
                onClick={() => setDeletingUser(null)}
                className="px-4 py-2.5 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-all"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleConfirmDelete}
                disabled={deleting}
                className="px-4 py-2.5 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 rounded-xl shadow-md transition-all disabled:opacity-50 flex items-center gap-1.5"
              >
                {deleting ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <Trash2 className="w-3.5 h-3.5" />}
                <span>{deleting ? 'Đang xóa...' : 'Xóa Vĩnh Viễn'}</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL 5: NHẬP DANH SÁCH (IMPORT EXCEL / CSV MODAL)
          ======================================================== */}
      {isImportModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-slate-100 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-9 h-9 rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold">
                  <FileSpreadsheet className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-900">5. Nhập Danh Sách Tài Khoản Hàng Loạt</h3>
                  <p className="text-[11px] text-slate-400">Tự động sinh username & gửi email kích hoạt cho từng tài khoản</p>
                </div>
              </div>
              <button
                onClick={() => {
                  setIsImportModalOpen(false);
                  setImportReport(null);
                  setParsedRows([]);
                  setImportText('');
                }}
                className="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Báo cáo kết quả sau khi nhập */}
            {importReport && (
              <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl text-xs space-y-2">
                <div className="flex items-center justify-between font-bold">
                  <span className="text-emerald-700">✓ Thành công: {importReport.success} tài khoản</span>
                  <span className="text-rose-700">✕ Thất bại: {importReport.failed} tài khoản</span>
                </div>
                {importReport.errors && importReport.errors.length > 0 && (
                  <div className="mt-2 p-2 bg-rose-50 border border-rose-200 rounded-xl text-[11px] text-rose-800 max-h-32 overflow-y-auto space-y-1">
                    {(importReport.errors || []).map((err, idx) => (
                      <div key={idx}>• {err}</div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* Tải mẫu & Chọn file */}
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 p-4 bg-indigo-50/50 border border-indigo-100 rounded-2xl">
              <div>
                <span className="text-xs font-bold text-indigo-950 block">File mẫu chuẩn CSV / Excel</span>
                <span className="text-[11px] text-indigo-700">Bao gồm các cột: Họ tên, Email, SĐT, CCCD, Giới tính, Vai trò</span>
              </div>
              <button
                type="button"
                onClick={handleDownloadTemplate}
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-indigo-700 bg-white hover:bg-indigo-50 border border-indigo-200 rounded-xl transition-all shadow-sm"
              >
                <Download className="w-3.5 h-3.5" />
                <span>Tải File Mẫu (.csv)</span>
              </button>
            </div>

            {/* Upload File hoặc Dán nội dung */}
            <div className="space-y-3">
              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Cách 1: Chọn file CSV từ máy tính
                </label>
                <input
                  type="file"
                  accept=".csv,text/csv"
                  onChange={handleFileUpload}
                  className="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Cách 2: Hoặc dán trực tiếp dữ liệu CSV vào đây
                </label>
                <textarea
                  rows={4}
                  placeholder="Họ và tên,Email,Số điện thoại,Số CCCD,Giới tính,Vai trò&#10;Nguyễn Văn An,an@example.com,0912345678,079123456789,MALE,RECEPTIONIST"
                  value={importText}
                  onChange={(e) => {
                    setImportText(e.target.value);
                    parseCsvText(e.target.value);
                  }}
                  className="w-full p-3 text-xs font-mono bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />
              </div>
            </div>

            {/* Bảng xem trước dữ liệu parse được */}
            {parsedRows.length > 0 && (
              <div className="space-y-2">
                <span className="text-xs font-bold text-slate-800 flex items-center justify-between">
                  <span>Dữ liệu hợp lệ sẵn sàng nhập ({parsedRows.length} dòng):</span>
                </span>
                <div className="max-h-40 overflow-y-auto border border-slate-200 rounded-xl">
                  <table className="w-full text-left text-[11px]">
                    <thead className="bg-slate-100 text-slate-600 sticky top-0">
                      <tr>
                        <th className="p-2">Họ tên</th>
                        <th className="p-2">Email</th>
                        <th className="p-2">Số điện thoại</th>
                        <th className="p-2">Vai trò</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {parsedRows.map((r, i) => (
                        <tr key={i} className="hover:bg-slate-50">
                          <td className="p-2 font-medium text-slate-800">{r.full_name}</td>
                          <td className="p-2 text-slate-600 font-mono">{r.email}</td>
                          <td className="p-2 text-slate-600">{r.phone_number}</td>
                          <td className="p-2 text-slate-600">{r.roles?.[0] || 'RECEPTIONIST'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
              <button
                type="button"
                onClick={() => {
                  setIsImportModalOpen(false);
                  setImportReport(null);
                  setParsedRows([]);
                  setImportText('');
                }}
                className="px-4 py-2 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-all"
              >
                Đóng
              </button>
              <button
                type="button"
                onClick={handleStartImport}
                disabled={importing || parsedRows.length === 0}
                className="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition-all disabled:opacity-50 flex items-center gap-1.5"
              >
                {importing ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <Upload className="w-3.5 h-3.5" />}
                <span>{importing ? 'Đang nhập & gửi email...' : `Tiến Hành Nhập (${parsedRows.length})`}</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
export default AccountProvisioningManagement;
