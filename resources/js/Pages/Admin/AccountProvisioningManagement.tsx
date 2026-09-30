import React, { useState, useEffect } from 'react';
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
  ExternalLink
} from 'lucide-react';
import { api } from '../../Services/api';

interface ProvisionedUser {
  id: string;
  username: string;
  full_name: string;
  email: string;
  phone_number: string;
  national_id_number?: string | null;
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
  // Form State
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [phoneNumber, setPhoneNumber] = useState('');
  const [nationalIdNumber, setNationalIdNumber] = useState('');
  const [gender, setGender] = useState<'MALE' | 'FEMALE' | 'OTHER'>('MALE');
  const [roleCode, setRoleCode] = useState('RECEPTIONIST');

  // UI State
  const [submitting, setSubmitting] = useState(false);
  const [loadingList, setLoadingList] = useState(false);
  const [resendingId, setResendingId] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [users, setUsers] = useState<ProvisionedUser[]>([]);
  const [createdResult, setCreatedResult] = useState<any | null>(null);
  const [toast, setToast] = useState<{ message: string; type: 'success' | 'error' | 'info' } | null>(null);

  // Hiển thị toast
  const showToast = (message: string, type: 'success' | 'error' | 'info' = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 4000);
  };

  // Load danh sách người dùng
  const loadUsers = async () => {
    try {
      setLoadingList(true);
      const token = localStorage.getItem('smart_cassavas_token');
      const query = new URLSearchParams();
      if (searchTerm) query.append('search', searchTerm);
      if (statusFilter) query.append('status', statusFilter);

      const res = await fetch(`/api/v1/account-provisioning?${query.toString()}`, {
        headers: {
          'Accept': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {})
        }
      });

      if (res.ok) {
        const json = await res.json();
        setUsers(json.data || []);
      }
    } catch (err: any) {
      console.error('Lỗi tải danh sách tài khoản:', err);
    } finally {
      setLoadingList(false);
    }
  };

  useEffect(() => {
    loadUsers();
  }, [statusFilter]);

  // Xử lý nộp form cấp phát
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

      // Reload danh sách
      loadUsers();
    } catch (err: any) {
      showToast(err.message || 'Lỗi cấp phát tài khoản.', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  // Gửi lại email kích hoạt
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

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* Toast Notification */}
      {toast && (
        <div className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-2xl shadow-2xl text-xs font-semibold backdrop-blur-md border animate-in fade-in slide-in-from-bottom-3 duration-200 ${
          toast.type === 'error'
            ? 'bg-rose-950/90 text-rose-200 border-rose-800'
            : toast.type === 'info'
            ? 'bg-sky-950/90 text-sky-200 border-sky-800'
            : 'bg-emerald-950/90 text-emerald-200 border-emerald-800'
        }`}>
          {toast.type === 'error' ? <AlertCircle className="w-4 h-4 text-rose-400 shrink-0" /> : <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />}
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
                Module 4
              </span>
            </h2>
            <p className="text-xs text-slate-500 mt-1">
              Hệ thống tự động sinh username duy nhất, hash mật khẩu phức tạp và gửi email kích hoạt an toàn.
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => loadUsers()}
            disabled={loadingList}
            className="flex items-center gap-2 px-3.5 py-2 text-xs font-medium text-slate-600 bg-white hover:bg-slate-50 border border-slate-200/80 rounded-xl transition-all shadow-sm disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loadingList ? 'animate-spin' : ''}`} />
            Làm mới danh sách
          </button>
        </div>
      </div>

      {/* Layout Grid 2 cột: Form Cấp phát & Bảng Danh sách */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* CỘT TRÁI: FORM CẤP PHÁT TÀI KHOẢN (4 Cột) */}
        <div className="lg:col-span-4">
          <div className="bg-white/80 backdrop-blur-xl border border-white/80 rounded-3xl p-6 shadow-sm sticky top-6">
            <div className="flex items-center gap-2.5 mb-5 pb-4 border-b border-slate-100">
              <Sparkles className="w-4 h-4 text-sky-600" />
              <h3 className="text-sm font-bold text-slate-900">Cấp phát tài khoản</h3>
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
                <label className="block text-xs font-medium text-slate-700 mb-1.5 flex items-center justify-between">
                  <span>Email <span className="text-rose-500">*</span></span>
                  <span className="text-[10px] text-slate-400">Gửi link kích hoạt</span>
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
                    Số CCCD / Passport
                  </label>
                  <input
                    type="text"
                    placeholder="079..."
                    value={nationalIdNumber}
                    onChange={(e) => setNationalIdNumber(e.target.value)}
                    className="w-full px-3 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-slate-700 mb-1.5">
                    Giới tính
                  </label>
                  <select
                    value={gender}
                    onChange={(e) => setGender(e.target.value as any)}
                    className="w-full px-3 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                  >
                    <option value="MALE">Nam</option>
                    <option value="FEMALE">Nữ</option>
                    <option value="OTHER">Khác</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Vai trò phân quyền ban đầu (RBAC)
                </label>
                <select
                  value={roleCode}
                  onChange={(e) => setRoleCode(e.target.value)}
                  className="w-full px-3 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                >
                  <option value="RECEPTIONIST">Nhân viên Lễ Tân</option>
                  <option value="SECURITY_GUARD">Nhân viên Bảo Vệ</option>
                  <option value="TECHNICIAN">Nhân viên Kỹ Thuật</option>
                  <option value="ACCOUNTANT">Nhân viên Kế Toán</option>
                  <option value="BUILDING_MANAGER">Ban Quản Lý Tòa Nhà</option>
                  <option value="RESIDENT_OWNER">Cư Dân Chủ Hộ</option>
                </select>
              </div>

              {/* Thông báo cơ chế backend */}
              <div className="bg-sky-50/70 border border-sky-100 rounded-2xl p-3.5 text-[11px] text-sky-800 space-y-1.5">
                <div className="font-semibold flex items-center gap-1.5 text-sky-900">
                  <Info className="w-3.5 h-3.5" />
                  Quy trình sinh tự động:
                </div>
                <p>• Backend tự sinh username duy nhất dạng <code>NV_xxxxxx</code> (ví dụ: NV_A82K91).</p>
                <p>• Mật khẩu tạm thời được hash an toàn, tuyệt đối không gửi qua email.</p>
                <p>• Email kèm link kích hoạt sẽ được gửi tới nhân viên để tự đặt mật khẩu.</p>
              </div>

              <button
                type="submit"
                disabled={submitting}
                className="w-full mt-2 py-3 px-4 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-sky-600/20 flex items-center justify-center gap-2 transition-all disabled:opacity-50"
              >
                {submitting ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin" />
                    Đang sinh tài khoản & gửi email...
                  </>
                ) : (
                  <>
                    <Sparkles className="w-4 h-4" />
                    Cấp tài khoản
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
                <p className="text-[11px] text-slate-400">Quản lý trạng thái kích hoạt và hỗ trợ gửi lại email cho cư dân</p>
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
                    className="pl-8 pr-3 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 focus:ring-sky-500 w-48 sm:w-56"
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
                            {isPending ? (
                              <button
                                onClick={() => handleResend(u)}
                                disabled={resendingId === u.id}
                                className="inline-flex items-center gap-1 px-3 py-1.5 text-[11px] font-semibold text-sky-700 bg-sky-50 hover:bg-sky-100 border border-sky-200 rounded-xl transition-all disabled:opacity-50"
                                title="Gửi lại email liên kết kích hoạt"
                              >
                                <Send className={`w-3 h-3 ${resendingId === u.id ? 'animate-pulse' : ''}`} />
                                {resendingId === u.id ? 'Đang gửi...' : 'Gửi lại email'}
                              </button>
                            ) : (
                              <span className="text-[11px] text-slate-400 italic">
                                Đã hoàn tất
                              </span>
                            )}
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
    </div>
  );
};
export default AccountProvisioningManagement;
