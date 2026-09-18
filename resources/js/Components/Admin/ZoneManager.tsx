import React, { useState, useEffect, useCallback } from 'react';
import {
  Building2,
  Plus,
  Search,
  RefreshCw,
  Edit2,
  Trash2,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  Building,
  Layers,
  Phone,
  Home,
  Clock,
  Filter,
  ShieldAlert,
  Info
} from 'lucide-react';
import { ZoneForm, ZoneData } from './ZoneForm';

// Định nghĩa kiểu dữ liệu cho Zone lấy từ Backend Laravel
export interface ZoneItem {
  id: number;
  zone_code: string;
  zone_name: string;
  floor_count: number;
  basement_count: number;
  total_apartments: number;
  status: 'active' | 'maintenance' | 'inactive';
  address_line?: string | null;
  hotline_phone?: string | null;
  description?: string | null;
  created_at?: string;
  updated_at: string; // Sử dụng làm last_updated_at cho Optimistic Locking
}

// Kiểu dữ liệu thống kê trả về từ API
export interface ZoneStats {
  total_zones: number;
  active_zones: number;
  maintenance_zones: number;
  total_floors: number;
  total_apartments: number;
}

interface ToastInfo {
  id: number;
  type: 'success' | 'error' | 'warning' | 'info';
  message: string;
}

export const ZoneManager: React.FC = () => {
  // ================= STATE QUẢN LÝ DỮ LIỆU =================
  const [zones, setZones] = useState<ZoneItem[]>([]);
  const [stats, setStats] = useState<ZoneStats>({
    total_zones: 0,
    active_zones: 0,
    maintenance_zones: 0,
    total_floors: 0,
    total_apartments: 0,
  });
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isRefreshing, setIsRefreshing] = useState<boolean>(false);

  // Filter & Search
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('all');

  // Modal Form State
  const [isFormOpen, setIsFormOpen] = useState<boolean>(false);
  const [editingZone, setEditingZone] = useState<ZoneItem | null>(null);

  // Modal Xóa xác nhận
  const [deletingZone, setDeletingZone] = useState<ZoneItem | null>(null);
  const [isDeleting, setIsDeleting] = useState<boolean>(false);

  // Toast thông báo mượt mà
  const [toasts, setToasts] = useState<ToastInfo[]>([]);

  const showToast = (type: 'success' | 'error' | 'warning' | 'info', message: string) => {
    const id = Date.now();
    setToasts((prev) => [...prev, { id, type, message }]);
    setTimeout(() => {
      setToasts((prev) => prev.filter((t) => t.id !== id));
    }, 4000);
  };

  // ================= CALL API LẤY DANH SÁCH KHỐI TÒA NHÀ =================
  const fetchZones = useCallback(async (quiet = false) => {
    if (!quiet) setIsLoading(true);
    else setIsRefreshing(true);

    try {
      const params = new URLSearchParams();
      if (searchQuery.trim()) params.append('search', searchQuery.trim());
      if (statusFilter !== 'all') params.append('status', statusFilter);

      const url = `/api/v1/manager/zones${params.toString() ? `?${params.toString()}` : ''}`;
      const res = await fetch(url, {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const data = await res.json();
      if (res.ok && data.success) {
        setZones(data.data || []);
        if (data.stats) {
          setStats(data.stats);
        }
      } else {
        showToast('error', data.message || 'Không thể lấy dữ liệu khối tòa nhà.');
      }
    } catch (err: any) {
      console.error('Lỗi khi tải danh sách khối tòa nhà:', err);
      showToast('error', 'Không thể kết nối đến máy chủ lấy dữ liệu khối tòa nhà.');
    } finally {
      setIsLoading(false);
      setIsRefreshing(false);
    }
  }, [searchQuery, statusFilter]);

  // Load ban đầu & debounce khi thay đổi search/filter
  useEffect(() => {
    const timer = setTimeout(() => {
      fetchZones();
    }, 250);
    return () => clearTimeout(timer);
  }, [fetchZones]);

  // ================= XỬ LÝ MỞ FORM THÊM / SỬA =================
  const handleOpenCreate = () => {
    setEditingZone(null);
    setIsFormOpen(true);
  };

  const handleOpenEdit = (zone: ZoneItem) => {
    setEditingZone(zone);
    setIsFormOpen(true);
  };

  // Khi form lưu thành công
  const handleFormSuccess = (msg: string) => {
    showToast('success', msg);
    setIsFormOpen(false);
    setEditingZone(null);
    fetchZones(true);
  };

  // Xử lý tải lại dữ liệu khối đơn lẻ khi có xung đột (Optimistic Locking 409)
  const handleReloadRequested = async (id?: number) => {
    await fetchZones(true);
    if (!id) return null;
    try {
      const res = await fetch(`/api/v1/manager/zones/${id}`, {
        headers: { Accept: 'application/json' },
      });
      const data = await res.json();
      return data.success && data.data ? data.data : null;
    } catch {
      return null;
    }
  };

  // ================= XỬ LÝ XÓA KHỐI TÒA NHÀ =================
  const handleConfirmDelete = async () => {
    if (!deletingZone) return;
    setIsDeleting(true);

    try {
      const res = await fetch(`/api/v1/manager/zones/${deletingZone.id}`, {
        method: 'DELETE',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      const data = await res.json();

      if (res.ok && data.success) {
        showToast('success', data.message || `Đã xóa thành công khối tòa nhà ${deletingZone.zone_name}`);
        setDeletingZone(null);
        fetchZones(true);
      } else if (res.status === 404) {
        showToast('error', 'Khối tòa nhà không tồn tại hoặc đã bị xóa bởi người khác.');
        setDeletingZone(null);
        fetchZones(true);
      } else {
        showToast('error', data.message || 'Có lỗi xảy ra khi xóa khối tòa nhà!');
      }
    } catch (err: any) {
      showToast('error', 'Lỗi kết nối máy chủ khi thực hiện xóa khối tòa nhà.');
    } finally {
      setIsDeleting(false);
    }
  };

  // Format ngày giờ Việt Nam
  const formatDate = (isoString?: string) => {
    if (!isoString) return '--';
    try {
      const date = new Date(isoString);
      return date.toLocaleString('vi-VN', {
        hour: '2-digit',
        minute: '2-digit',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
      });
    } catch {
      return isoString;
    }
  };

  return (
    <div className="space-y-6 w-full max-w-[2000px] mx-auto pb-12">
      {/* ================= TOAST NOTIFICATION CONTAINER ================= */}
      <div className="fixed bottom-5 right-5 z-[9999] flex flex-col gap-2 pointer-events-none">
        {toasts.map((toast) => (
          <div
            key={toast.id}
            className={`pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-2xl shadow-2xl backdrop-blur-xl border text-sm font-medium transition-all duration-300 animate-in fade-in slide-in-from-bottom-3 ${
              toast.type === 'success'
                ? 'bg-emerald-500/90 border-emerald-400/50 text-white shadow-emerald-500/20'
                : toast.type === 'error'
                ? 'bg-rose-500/90 border-rose-400/50 text-white shadow-rose-500/20'
                : toast.type === 'warning'
                ? 'bg-amber-500/90 border-amber-400/50 text-white shadow-amber-500/20'
                : 'bg-sky-500/90 border-sky-400/50 text-white shadow-sky-500/20'
            }`}
          >
            {toast.type === 'success' && <CheckCircle2 className="w-5 h-5 shrink-0" />}
            {toast.type === 'error' && <XCircle className="w-5 h-5 shrink-0" />}
            {toast.type === 'warning' && <AlertTriangle className="w-5 h-5 shrink-0" />}
            {toast.type === 'info' && <Info className="w-5 h-5 shrink-0" />}
            <span>{toast.message}</span>
          </div>
        ))}
      </div>

      {/* ================= HEADER TIÊU ĐỀ & ACTIONS ================= */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-sky-600 font-mono">
            <Building2 className="w-4 h-4" />
            <span>BAN QUẢN LÝ TÒA NHÀ (MANAGER) · KHÔNG GIAN VẬN HÀNH</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 dark:text-white tracking-tight mt-1">
            Quản Lý Khối Tòa Nhà & Phân Khu (Block/Zone)
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
            Phân hệ nghiệp vụ Ban Quản Lý: Kiểm soát danh mục khối nhà (Block A, Block B,...), số tầng và căn hộ vận hành.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={() => fetchZones(true)}
            disabled={isRefreshing}
            className="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-white/40 hover:bg-white/70 dark:bg-slate-800/40 dark:hover:bg-slate-800/70 text-slate-700 dark:text-slate-200 border border-white/40 dark:border-slate-700/50 backdrop-blur-md text-xs font-semibold shadow-sm transition-all cursor-pointer disabled:opacity-50"
            title="Tải lại dữ liệu"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isRefreshing ? 'animate-spin text-sky-500' : ''}`} />
            <span className="hidden sm:inline">Làm mới</span>
          </button>

          <button
            type="button"
            onClick={handleOpenCreate}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs sm:text-sm font-semibold shadow-lg shadow-sky-500/25 hover:shadow-sky-500/40 hover:-translate-y-0.5 active:translate-y-0 transition-all cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            <span>Thêm Khối Nhà Mới</span>
          </button>
        </div>
      </div>

      {/* ================= 4 THẺ STATS GLASSMORPHISM ================= */}
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-3.5 sm:gap-4">
        {/* Thẻ 1: Tổng khối tòa nhà */}
        <div className="p-4 rounded-2xl bg-white/40 dark:bg-slate-800/40 backdrop-blur-xl border border-white/60 dark:border-slate-700/50 shadow-lg shadow-slate-200/30 dark:shadow-none hover:-translate-y-0.5 transition-all">
          <div className="flex items-center justify-between text-slate-500 dark:text-slate-400 text-xs font-medium">
            <span>Tổng khối tòa nhà</span>
            <div className="p-1.5 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400">
              <Building2 className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-black text-neutral-900 dark:text-white font-mono">
              {stats.total_zones}
            </span>
            <span className="text-xs text-slate-400">khối</span>
          </div>
        </div>

        {/* Thẻ 2: Đang hoạt động */}
        <div className="p-4 rounded-2xl bg-white/40 dark:bg-slate-800/40 backdrop-blur-xl border border-white/60 dark:border-slate-700/50 shadow-lg shadow-slate-200/30 dark:shadow-none hover:-translate-y-0.5 transition-all">
          <div className="flex items-center justify-between text-slate-500 dark:text-slate-400 text-xs font-medium">
            <span>Đang hoạt động</span>
            <div className="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-mono">
              {stats.active_zones}
            </span>
            <span className="text-xs text-slate-400">khối</span>
          </div>
        </div>

        {/* Thẻ 3: Đang bảo trì */}
        <div className="p-4 rounded-2xl bg-white/40 dark:bg-slate-800/40 backdrop-blur-xl border border-white/60 dark:border-slate-700/50 shadow-lg shadow-slate-200/30 dark:shadow-none hover:-translate-y-0.5 transition-all">
          <div className="flex items-center justify-between text-slate-500 dark:text-slate-400 text-xs font-medium">
            <span>Đang bảo trì</span>
            <div className="p-1.5 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
              <AlertTriangle className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-black text-amber-600 dark:text-amber-400 font-mono">
              {stats.maintenance_zones}
            </span>
            <span className="text-xs text-slate-400">khối</span>
          </div>
        </div>

        {/* Thẻ 4: Tổng số tầng */}
        <div className="p-4 rounded-2xl bg-white/40 dark:bg-slate-800/40 backdrop-blur-xl border border-white/60 dark:border-slate-700/50 shadow-lg shadow-slate-200/30 dark:shadow-none hover:-translate-y-0.5 transition-all">
          <div className="flex items-center justify-between text-slate-500 dark:text-slate-400 text-xs font-medium">
            <span>Tổng số tầng</span>
            <div className="p-1.5 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
              <Layers className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-black text-indigo-600 dark:text-indigo-400 font-mono">
              {stats.total_floors}
            </span>
            <span className="text-xs text-slate-400">tầng nổi</span>
          </div>
        </div>

        {/* Thẻ 5: Tổng số căn hộ */}
        <div className="col-span-2 lg:col-span-1 p-4 rounded-2xl bg-white/40 dark:bg-slate-800/40 backdrop-blur-xl border border-white/60 dark:border-slate-700/50 shadow-lg shadow-slate-200/30 dark:shadow-none hover:-translate-y-0.5 transition-all">
          <div className="flex items-center justify-between text-slate-500 dark:text-slate-400 text-xs font-medium">
            <span>Tổng căn hộ</span>
            <div className="p-1.5 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400">
              <Home className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-black text-violet-600 dark:text-violet-400 font-mono">
              {stats.total_apartments}
            </span>
            <span className="text-xs text-slate-400">căn</span>
          </div>
        </div>
      </div>

      {/* ================= THANH TÌM KIẾM & BỘ LỌC ================= */}
      <div className="p-4 rounded-2xl bg-white/30 dark:bg-slate-800/30 backdrop-blur-md border border-white/40 dark:border-slate-700/40 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
        {/* Input Tìm Kiếm */}
        <div className="relative w-full sm:w-80">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Tìm theo mã (BK-A) hoặc tên..."
            className="w-full pl-9 pr-4 py-2 text-xs rounded-xl bg-white/60 dark:bg-slate-900/60 border border-white/60 dark:border-slate-700/60 text-slate-800 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500 transition-all"
          />
        </div>

        {/* Filter Trạng thái */}
        <div className="flex items-center gap-2 w-full sm:w-auto">
          <Filter className="w-3.5 h-3.5 text-slate-400 shrink-0" />
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="w-full sm:w-auto px-3 py-2 text-xs rounded-xl bg-white/60 dark:bg-slate-900/60 border border-white/60 dark:border-slate-700/60 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-sky-500 cursor-pointer"
          >
            <option value="all">Tất cả trạng thái</option>
            <option value="active">Đang hoạt động</option>
            <option value="maintenance">Đang bảo trì</option>
            <option value="inactive">Tạm ngưng</option>
          </select>
        </div>
      </div>

      {/* ================= BẢNG DANH SÁCH GLASSMORPHISM ================= */}
      <div className="relative overflow-hidden rounded-3xl bg-white/30 dark:bg-slate-900/40 backdrop-blur-xl border border-white/40 dark:border-slate-700/40 shadow-xl">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-white/40 dark:border-slate-700/50 bg-white/40 dark:bg-slate-800/40 text-[11px] uppercase font-bold text-slate-500 dark:text-slate-400 tracking-wider">
                <th className="py-3.5 px-4">Mã Khối</th>
                <th className="py-3.5 px-4">Tên Khối Tòa Nhà</th>
                <th className="py-3.5 px-4 text-center">Số Tầng</th>
                <th className="py-3.5 px-4 text-center">Tầng Hầm</th>
                <th className="py-3.5 px-4 text-center">Tổng Căn Hộ</th>
                <th className="py-3.5 px-4">Hotline & Địa Chỉ</th>
                <th className="py-3.5 px-4 text-center">Trạng Thái</th>
                <th className="py-3.5 px-4">Cập Nhật</th>
                <th className="py-3.5 px-4 text-right">Thao Tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-white/20 dark:divide-slate-800/60 text-xs">
              {isLoading ? (
                <tr>
                  <td colSpan={9} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-3">
                      <RefreshCw className="w-6 h-6 animate-spin text-sky-500" />
                      <span>Đang tải danh sách khối tòa nhà...</span>
                    </div>
                  </td>
                </tr>
              ) : zones.length === 0 ? (
                <tr>
                  <td colSpan={9} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Building className="w-10 h-10 text-slate-300 dark:text-slate-600" />
                      <span className="font-medium text-slate-600 dark:text-slate-300">
                        Chưa có khối tòa nhà nào
                      </span>
                      <p className="text-xs text-slate-400">
                        Hãy nhấn nút "Thêm Khối Nhà Mới" để khởi tạo dữ liệu.
                      </p>
                    </div>
                  </td>
                </tr>
              ) : (
                zones.map((zone) => (
                  <tr
                    key={zone.id}
                    className="hover:bg-white/40 dark:hover:bg-slate-800/40 transition-colors group"
                  >
                    {/* Mã khối */}
                    <td className="py-3.5 px-4 font-mono font-bold text-sky-600 dark:text-sky-400">
                      <span className="px-2.5 py-1 rounded-lg bg-sky-500/10 border border-sky-500/20">
                        {zone.zone_code}
                      </span>
                    </td>

                    {/* Tên khối */}
                    <td className="py-3.5 px-4">
                      <div className="font-semibold text-neutral-900 dark:text-white">
                        {zone.zone_name}
                      </div>
                      {zone.description && (
                        <div className="text-[11px] text-slate-400 max-w-[200px] truncate">
                          {zone.description}
                        </div>
                      )}
                    </td>

                    {/* Số tầng */}
                    <td className="py-3.5 px-4 text-center font-mono font-bold text-slate-700 dark:text-slate-200">
                      {zone.floor_count}
                    </td>

                    {/* Số tầng hầm */}
                    <td className="py-3.5 px-4 text-center font-mono text-slate-600 dark:text-slate-300">
                      {zone.basement_count}
                    </td>

                    {/* Tổng số căn hộ */}
                    <td className="py-3.5 px-4 text-center font-mono font-bold text-neutral-900 dark:text-white">
                      {zone.total_apartments}
                    </td>

                    {/* Hotline & Địa chỉ */}
                    <td className="py-3.5 px-4 text-slate-600 dark:text-slate-300">
                      {zone.hotline_phone && (
                        <div className="flex items-center gap-1.5 text-xs text-sky-600 dark:text-sky-400">
                          <Phone className="w-3 h-3" />
                          <span>{zone.hotline_phone}</span>
                        </div>
                      )}
                      <div className="text-[11px] text-slate-400 max-w-[180px] truncate">
                        {zone.address_line || 'Chưa cập nhật'}
                      </div>
                    </td>

                    {/* Trạng thái */}
                    <td className="py-3.5 px-4 text-center">
                      <span
                        className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold border ${
                          zone.status === 'active'
                            ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 dark:text-emerald-400'
                            : zone.status === 'maintenance'
                            ? 'bg-amber-500/10 text-amber-600 border-amber-500/20 dark:text-amber-400'
                            : 'bg-slate-500/10 text-slate-600 border-slate-500/20 dark:text-slate-400'
                        }`}
                      >
                        <span
                          className={`w-1.5 h-1.5 rounded-full ${
                            zone.status === 'active'
                              ? 'bg-emerald-500'
                              : zone.status === 'maintenance'
                              ? 'bg-amber-500'
                              : 'bg-slate-400'
                          }`}
                        />
                        {zone.status === 'active'
                          ? 'Hoạt động'
                          : zone.status === 'maintenance'
                          ? 'Bảo trì'
                          : 'Tạm ngưng'}
                      </span>
                    </td>

                    {/* Cập nhật lần cuối */}
                    <td className="py-3.5 px-4 text-[11px] text-slate-400 font-mono">
                      <div className="flex items-center gap-1">
                        <Clock className="w-3 h-3 text-slate-300" />
                        <span>{formatDate(zone.updated_at)}</span>
                      </div>
                    </td>

                    {/* Thao tác (Sửa / Xóa) */}
                    <td className="py-3.5 px-4 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        <button
                          type="button"
                          onClick={() => handleOpenEdit(zone)}
                          className="p-1.5 rounded-lg bg-white/60 dark:bg-slate-800/60 hover:bg-sky-50 dark:hover:bg-sky-950/40 text-slate-600 hover:text-sky-600 dark:text-slate-300 dark:hover:text-sky-400 border border-white/60 dark:border-slate-700/60 shadow-xs transition-all cursor-pointer"
                          title="Sửa thông tin"
                        >
                          <Edit2 className="w-3.5 h-3.5" />
                        </button>
                        <button
                          type="button"
                          onClick={() => setDeletingZone(zone)}
                          className="p-1.5 rounded-lg bg-white/60 dark:bg-slate-800/60 hover:bg-rose-50 dark:hover:bg-rose-950/40 text-slate-600 hover:text-rose-600 dark:text-slate-300 dark:hover:text-rose-400 border border-white/60 dark:border-slate-700/60 shadow-xs transition-all cursor-pointer"
                          title="Xóa khối nhà"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* ================= MODAL FORM THÊM / SỬA (ZoneForm.tsx) ================= */}
      {isFormOpen && (
        <ZoneForm
          isOpen={isFormOpen}
          initialData={editingZone as ZoneData | null}
          onClose={() => {
            setIsFormOpen(false);
            setEditingZone(null);
          }}
          onSuccess={handleFormSuccess}
          onReloadRequested={handleReloadRequested}
        />
      )}

      {/* ================= MODAL XÁC NHẬN XÓA (GLASSMORPHISM) ================= */}
      {deletingZone && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="w-full max-w-md rounded-3xl bg-white/90 dark:bg-slate-900/90 backdrop-blur-2xl border border-white/40 dark:border-slate-700/50 shadow-2xl p-6 space-y-4">
            <div className="flex items-center gap-3">
              <div className="w-12 h-12 rounded-2xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-600 dark:text-rose-400 shrink-0">
                <AlertTriangle className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-base font-bold text-neutral-900 dark:text-white">
                  Xác nhận xóa Khối Tòa Nhà
                </h3>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  Hành động này không thể hoàn tác nếu khối tòa nhà đã có căn hộ.
                </p>
              </div>
            </div>

            <div className="p-3.5 rounded-2xl bg-rose-50/50 dark:bg-rose-950/20 border border-rose-200/50 dark:border-rose-900/50 text-xs text-rose-800 dark:text-rose-300">
              Bạn có chắc chắn muốn xóa khối <strong>{deletingZone.zone_name}</strong> (Mã:{' '}
              <span className="font-mono font-bold">{deletingZone.zone_code}</span>)?
            </div>

            <div className="flex items-center justify-end gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setDeletingZone(null)}
                disabled={isDeleting}
                className="px-4 py-2 rounded-xl bg-white/60 dark:bg-slate-800/60 hover:bg-slate-100 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 transition-all cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleConfirmDelete}
                disabled={isDeleting}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-xs font-semibold text-white shadow-lg shadow-rose-600/25 transition-all cursor-pointer disabled:opacity-50"
              >
                {isDeleting ? (
                  <>
                    <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                    <span>Đang xóa...</span>
                  </>
                ) : (
                  <span>Xác nhận Xóa</span>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ZoneManager;
