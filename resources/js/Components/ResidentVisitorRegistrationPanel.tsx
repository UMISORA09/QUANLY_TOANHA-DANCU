import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import {
  Users,
  UserCheck,
  UserX,
  Clock,
  Search,
  Plus,
  RefreshCw,
  QrCode,
  Calendar,
  Phone,
  Car,
  Home,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Pencil,
  Eye,
  Copy,
  Check,
  Building,
  ShieldCheck,
  X,
  FileText,
  ChevronLeft,
  ChevronRight,
  Loader2,
} from 'lucide-react';
import {
  api,
  ResidentVisitorItem,
  ResidentVisitorListResponse,
  ResidentVisitorDetailResponse,
  ApiError,
} from '../Services/api';
import { useRealtimeSync, RealtimeEventPayload } from '../Hooks/useRealtimeSync';

interface ResidentVisitorRegistrationPanelProps {
  embedded?: boolean;
}

export const ResidentVisitorRegistrationPanel: React.FC<ResidentVisitorRegistrationPanelProps> = ({
  embedded = false,
}) => {
  // 1. Data States
  const [visitors, setVisitors] = useState<ResidentVisitorItem[]>([]);
  const [kpis, setKpis] = useState({
    total: 0,
    active: 0,
    used: 0,
    cancelled: 0,
    expired: 0,
  });
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [successToast, setSuccessToast] = useState<string | null>(null);

  // 2. Filter & Pagination States
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [debouncedSearch, setDebouncedSearch] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [fromDate, setFromDate] = useState<string>('');
  const [toDate, setToDate] = useState<string>('');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);
  const [totalCount, setTotalCount] = useState<number>(0);
  const [perPage, setPerPage] = useState<number>(10);

  // 3. Modal States
  const [isCreateModalOpen, setIsCreateModalOpen] = useState<boolean>(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState<boolean>(false);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);
  const [isCancelModalOpen, setIsCancelModalOpen] = useState<boolean>(false);
  const [selectedVisitor, setSelectedVisitor] = useState<ResidentVisitorItem | null>(null);
  const [detailData, setDetailData] = useState<ResidentVisitorDetailResponse | null>(null);
  const [isDetailLoading, setIsDetailLoading] = useState<boolean>(false);

  // 4. Form States
  const [formData, setFormData] = useState({
    visitor_name: '',
    visitor_phone: '',
    visitor_national_id: '',
    expected_arrival_time: '',
    expected_departure_time: '',
    visit_purpose: 'Thăm người thân',
    visitor_count: 1,
    vehicle_license_plate: '',
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
  const [cancelReason, setCancelReason] = useState<string>('');
  const [copiedPassCode, setCopiedPassCode] = useState<boolean>(false);

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Show Toast
  const triggerToast = (msg: string) => {
    setSuccessToast(msg);
    setTimeout(() => {
      setSuccessToast(null);
    }, 3500);
  };

  // Fetch Visitors List
  const fetchVisitors = useCallback(async () => {
    setIsLoading(true);
    setErrorMsg(null);
    try {
      const res = await api.getResidentVisitors({
        search: debouncedSearch,
        status: statusFilter,
        from_date: fromDate,
        to_date: toDate,
        page: currentPage,
        limit: perPage,
      });

      if (res.success) {
        setVisitors(res.data || []);
        if (res.kpis) {
          setKpis(res.kpis);
        }
        if (res.pagination) {
          setCurrentPage(res.pagination.current_page);
          setTotalPages(res.pagination.last_page);
          setTotalCount(res.pagination.total);
        }
      }
    } catch (err: any) {
      console.error('Lỗi khi tải danh sách khách:', err);
      setErrorMsg(err.message || 'Không thể tải danh sách khách viếng thăm.');
    } finally {
      setIsLoading(false);
    }
  }, [debouncedSearch, statusFilter, fromDate, toDate, currentPage, perPage]);

  useEffect(() => {
    fetchVisitors();
  }, [fetchVisitors]);

  // Realtime Live Sync Handler
  const handleRealtimeEvent = useCallback(
    (event: RealtimeEventPayload) => {
      if (event.module === 'visitors' || event.entity === 'visitor_registration') {
        // Tự động làm mới danh sách khi có sự kiện thay đổi
        fetchVisitors();
      }
    },
    [fetchVisitors]
  );

  useRealtimeSync({
    channel: 'quoc-tin.visitors',
    onEvent: handleRealtimeEvent,
    onReconnect: fetchVisitors,
  });

  // Open Create Modal
  const handleOpenCreateModal = () => {
    const now = new Date();
    // Default arrival time: today + 1 hour, rounded to next 30 min
    now.setHours(now.getHours() + 1);
    now.setMinutes(Math.ceil(now.getMinutes() / 30) * 30, 0, 0);
    const arrivalIso = now.toISOString().slice(0, 16);

    const departure = new Date(now);
    departure.setHours(departure.getHours() + 3);
    const departureIso = departure.toISOString().slice(0, 16);

    setFormData({
      visitor_name: '',
      visitor_phone: '',
      visitor_national_id: '',
      expected_arrival_time: arrivalIso,
      expected_departure_time: departureIso,
      visit_purpose: 'Thăm người thân',
      visitor_count: 1,
      vehicle_license_plate: '',
    });
    setFormErrors({});
    setIsCreateModalOpen(true);
  };

  // Open Edit Modal
  const handleOpenEditModal = (item: ResidentVisitorItem) => {
    setSelectedVisitor(item);
    const arrivalDate = item.expected_arrival_time
      ? new Date(item.expected_arrival_time).toISOString().slice(0, 16)
      : '';
    const departureDate = item.expected_departure_time
      ? new Date(item.expected_departure_time).toISOString().slice(0, 16)
      : '';

    setFormData({
      visitor_name: item.visitor_name,
      visitor_phone: item.visitor_phone,
      visitor_national_id: item.visitor_national_id || '',
      expected_arrival_time: arrivalDate,
      expected_departure_time: departureDate,
      visit_purpose: item.visit_purpose || 'Thăm người thân',
      visitor_count: item.visitor_count || 1,
      vehicle_license_plate: item.vehicle_license_plate || '',
    });
    setFormErrors({});
    setIsEditModalOpen(true);
  };

  // Open Detail / QR Pass Modal
  const handleOpenDetailModal = async (item: ResidentVisitorItem) => {
    setSelectedVisitor(item);
    setIsDetailModalOpen(true);
    setIsDetailLoading(true);
    setCopiedPassCode(false);
    try {
      const res = await api.getResidentVisitorDetail(item.id);
      setDetailData(res);
    } catch (err: any) {
      console.error('Lỗi khi lấy chi tiết lượt khách:', err);
    } finally {
      setIsDetailLoading(false);
    }
  };

  // Open Cancel Modal
  const handleOpenCancelModal = (item: ResidentVisitorItem) => {
    setSelectedVisitor(item);
    setCancelReason('');
    setIsCancelModalOpen(true);
  };

  // Submit Create
  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errors: Record<string, string> = {};

    if (!formData.visitor_name.trim()) {
      errors.visitor_name = 'Vui lòng nhập họ và tên khách.';
    }
    if (!formData.visitor_phone.trim()) {
      errors.visitor_phone = 'Vui lòng nhập số điện thoại của khách.';
    } else if (!/^[0-9+-\s]{8,15}$/.test(formData.visitor_phone.trim())) {
      errors.visitor_phone = 'Số điện thoại không hợp lệ.';
    }
    if (!formData.expected_arrival_time) {
      errors.expected_arrival_time = 'Vui lòng chọn thời gian dự kiến đến.';
    }

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      return;
    }

    setIsSubmitting(true);
    try {
      const res = await api.createResidentVisitor({
        visitor_name: formData.visitor_name.trim(),
        visitor_phone: formData.visitor_phone.trim(),
        visitor_national_id: formData.visitor_national_id.trim() || undefined,
        expected_arrival_time: formData.expected_arrival_time,
        expected_departure_time: formData.expected_departure_time || undefined,
        visit_purpose: formData.visit_purpose.trim() || undefined,
        visitor_count: Number(formData.visitor_count) || 1,
        vehicle_license_plate: formData.vehicle_license_plate.trim() || undefined,
      });

      if (res.success) {
        setIsCreateModalOpen(false);
        triggerToast('Đã khai báo khách viếng thăm thành công! Mã QR Pass đã được cấp.');
        fetchVisitors();
      }
    } catch (err: any) {
      console.error('Lỗi khi tạo lượt khách:', err);
      if (err.errors) {
        const mappedErrors: Record<string, string> = {};
        Object.keys(err.errors).forEach((key) => {
          mappedErrors[key] = Array.isArray(err.errors[key]) ? err.errors[key][0] : String(err.errors[key]);
        });
        setFormErrors(mappedErrors);
      } else {
        setFormErrors({ general: err.message || 'Lỗi khi tạo lượt khai báo khách.' });
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  // Submit Update
  const handleUpdateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedVisitor) return;

    const errors: Record<string, string> = {};
    if (!formData.visitor_name.trim()) {
      errors.visitor_name = 'Vui lòng nhập họ và tên khách.';
    }
    if (!formData.visitor_phone.trim()) {
      errors.visitor_phone = 'Vui lòng nhập số điện thoại của khách.';
    }
    if (!formData.expected_arrival_time) {
      errors.expected_arrival_time = 'Vui lòng chọn thời gian dự kiến đến.';
    }

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      return;
    }

    setIsSubmitting(true);
    try {
      const res = await api.updateResidentVisitor(selectedVisitor.id, {
        visitor_name: formData.visitor_name.trim(),
        visitor_phone: formData.visitor_phone.trim(),
        visitor_national_id: formData.visitor_national_id.trim() || undefined,
        expected_arrival_time: formData.expected_arrival_time,
        expected_departure_time: formData.expected_departure_time || undefined,
        visit_purpose: formData.visit_purpose.trim() || undefined,
        visitor_count: Number(formData.visitor_count) || 1,
        vehicle_license_plate: formData.vehicle_license_plate.trim() || undefined,
      });

      if (res.success) {
        setIsEditModalOpen(false);
        triggerToast('Đã cập nhật thông tin khách viếng thăm.');
        fetchVisitors();
      }
    } catch (err: any) {
      console.error('Lỗi khi cập nhật:', err);
      if (err.status === 409) {
        setFormErrors({ general: err.message || 'Lượt khai báo này đang được bảo vệ, vui lòng chờ ít phút.' });
      } else if (err.errors) {
        const mappedErrors: Record<string, string> = {};
        Object.keys(err.errors).forEach((key) => {
          mappedErrors[key] = Array.isArray(err.errors[key]) ? err.errors[key][0] : String(err.errors[key]);
        });
        setFormErrors(mappedErrors);
      } else {
        setFormErrors({ general: err.message || 'Không thể cập nhật lượt khai báo.' });
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  // Submit Cancel
  const handleCancelSubmit = async () => {
    if (!selectedVisitor) return;

    setIsSubmitting(true);
    try {
      const res = await api.cancelResidentVisitor(selectedVisitor.id, cancelReason);
      if (res.success) {
        setIsCancelModalOpen(false);
        triggerToast('Đã hủy lượt khai báo khách.');
        fetchVisitors();
      }
    } catch (err: any) {
      console.error('Lỗi khi hủy:', err);
      alert(err.message || 'Không thể hủy lượt khai báo.');
    } finally {
      setIsSubmitting(false);
    }
  };

  // Copy QR Pass Code
  const handleCopyPassCode = (code: string) => {
    navigator.clipboard.writeText(code);
    setCopiedPassCode(true);
    setTimeout(() => {
      setCopiedPassCode(false);
    }, 2500);
  };

  // Format Date Helper
  const formatDateTime = (dateStr?: string | null) => {
    if (!dateStr) return '—';
    try {
      const d = new Date(dateStr);
      return new Intl.DateTimeFormat('vi-VN', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      }).format(d);
    } catch {
      return dateStr;
    }
  };

  // Status Badge Helper
  const renderStatusBadge = (status: string) => {
    switch (status) {
      case 'ACTIVE':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
            Đang hiệu lực
          </span>
        );
      case 'USED':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-sky-50 text-sky-700 border border-sky-200">
            <UserCheck className="w-3 h-3 text-sky-600" />
            Đã tiếp đón
          </span>
        );
      case 'CANCELLED':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-neutral-100 text-neutral-600 border border-neutral-200">
            <XCircle className="w-3 h-3 text-neutral-400" />
            Đã hủy
          </span>
        );
      case 'EXPIRED':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
            <Clock className="w-3 h-3 text-amber-600" />
            Quá hạn
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-neutral-100 text-neutral-700">
            {status}
          </span>
        );
    }
  };

  return (
    <div className="max-w-7xl mx-auto space-y-6 animate-in fade-in duration-200">
      {/* Toast Alert */}
      {successToast && (
        <div className="fixed top-5 right-5 z-50 flex items-center gap-2.5 px-4 py-3 bg-emerald-900 text-white text-xs font-medium rounded-xl shadow-xl border border-emerald-700 animate-in slide-in-from-top-3">
          <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
          <span>{successToast}</span>
        </div>
      )}

      {/* 1. Header Section */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-neutral-200/80 pb-5">
        <div>
          <div className="flex items-center gap-2 text-xs font-semibold text-neutral-500 uppercase tracking-wider mb-1">
            <Users className="w-3.5 h-3.5 text-neutral-400" />
            <span>CỔNG CƯ DÂN · TIỆN ÍCH & AN NINH</span>
          </div>
          <h1 className="text-2xl font-extrabold text-neutral-900 tracking-tight flex items-center gap-2.5">
            Khai báo khách viếng thăm
            <span className="text-xs px-2.5 py-0.5 rounded-full font-semibold bg-neutral-100 text-neutral-700 border border-neutral-200">
              Module #7: KBTTKVT
            </span>
          </h1>
          <p className="text-xs text-neutral-500 mt-1">
            Đăng ký trước thông tin khách dự kiến đến thăm căn hộ. Khách xuất trình thẻ QR Pass tại cổng để tiếp đón nhanh chóng.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={fetchVisitors}
            disabled={isLoading}
            className="p-2.5 rounded-xl border border-neutral-200 bg-white hover:bg-neutral-50 text-neutral-600 transition-all flex items-center justify-center cursor-pointer shadow-2xs"
            title="Làm mới dữ liệu"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin text-emerald-600' : ''}`} />
          </button>
          <button
            type="button"
            onClick={handleOpenCreateModal}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white text-xs font-semibold shadow-sm transition-all cursor-pointer"
          >
            <Plus className="w-4 h-4 text-emerald-400" />
            <span>Khai báo khách mới</span>
          </button>
        </div>
      </div>

      {/* 2. KPI Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {/* Total Registrations */}
        <div className="p-4 rounded-2xl bg-white border border-neutral-200/80 shadow-2xs flex flex-col justify-between">
          <div className="flex items-center justify-between text-xs font-medium text-neutral-500">
            <span>Tổng lượt khai báo</span>
            <div className="w-7 h-7 rounded-lg bg-neutral-100 text-neutral-700 flex items-center justify-center">
              <Users className="w-3.5 h-3.5" />
            </div>
          </div>
          <div className="mt-3">
            <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight">
              {kpis.total}
            </span>
            <span className="text-xs text-neutral-400 ml-1.5 font-normal">lượt</span>
          </div>
        </div>

        {/* Active Cards */}
        <div className="p-4 rounded-2xl bg-white border border-neutral-200/80 shadow-2xs flex flex-col justify-between">
          <div className="flex items-center justify-between text-xs font-medium text-neutral-500">
            <span>Đang hiệu lực (Chờ đến)</span>
            <div className="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center border border-emerald-100">
              <Clock className="w-3.5 h-3.5" />
            </div>
          </div>
          <div className="mt-3">
            <span className="text-2xl font-extrabold text-emerald-600 font-mono tracking-tight">
              {kpis.active}
            </span>
            <span className="text-xs text-emerald-600/70 ml-1.5 font-normal">thẻ QR sẵn sàng</span>
          </div>
        </div>

        {/* Used Cards */}
        <div className="p-4 rounded-2xl bg-white border border-neutral-200/80 shadow-2xs flex flex-col justify-between">
          <div className="flex items-center justify-between text-xs font-medium text-neutral-500">
            <span>Đã đến thăm (Hoàn tất)</span>
            <div className="w-7 h-7 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center border border-sky-100">
              <UserCheck className="w-3.5 h-3.5" />
            </div>
          </div>
          <div className="mt-3">
            <span className="text-2xl font-extrabold text-sky-600 font-mono tracking-tight">
              {kpis.used}
            </span>
            <span className="text-xs text-sky-600/70 ml-1.5 font-normal">đã tiếp đón</span>
          </div>
        </div>

        {/* Cancelled / Expired */}
        <div className="p-4 rounded-2xl bg-white border border-neutral-200/80 shadow-2xs flex flex-col justify-between">
          <div className="flex items-center justify-between text-xs font-medium text-neutral-500">
            <span>Đã hủy / Quá hạn</span>
            <div className="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center border border-amber-100">
              <UserX className="w-3.5 h-3.5" />
            </div>
          </div>
          <div className="mt-3">
            <span className="text-2xl font-extrabold text-neutral-600 font-mono tracking-tight">
              {kpis.cancelled + kpis.expired}
            </span>
            <span className="text-xs text-neutral-400 ml-1.5 font-normal">lượt</span>
          </div>
        </div>
      </div>

      {/* 3. Filter Bar */}
      <div className="p-4 rounded-2xl bg-white border border-neutral-200/80 shadow-2xs space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
          {/* Quick Search */}
          <div className="relative md:col-span-2">
            <Search className="w-4 h-4 absolute left-3 top-3 text-neutral-400" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Tìm theo tên khách, số điện thoại, mã đăng ký, biển số..."
              className="w-full pl-9 pr-3 py-2 rounded-xl border border-neutral-200 text-xs bg-neutral-50/50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all text-neutral-900"
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                className="absolute right-3 top-2.5 text-neutral-400 hover:text-neutral-600"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>

          {/* Status Filter */}
          <div>
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setCurrentPage(1);
              }}
              className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-neutral-50/50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-all text-neutral-800"
            >
              <option value="ALL">Tất cả trạng thái</option>
              <option value="ACTIVE">Đang hiệu lực (Chờ đến)</option>
              <option value="USED">Đã tiếp đón (Đã check-in)</option>
              <option value="CANCELLED">Đã hủy</option>
              <option value="EXPIRED">Quá hạn</option>
            </select>
          </div>

          {/* Reset button */}
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => {
                setSearchQuery('');
                setStatusFilter('ALL');
                setFromDate('');
                setToDate('');
                setCurrentPage(1);
              }}
              className="w-full py-2 px-3 rounded-xl border border-neutral-200 hover:bg-neutral-50 text-neutral-600 text-xs font-medium transition-all"
            >
              Xóa bộ lọc
            </button>
          </div>
        </div>
      </div>

      {/* 4. Table / List Section */}
      <div className="rounded-2xl bg-white border border-neutral-200/80 shadow-2xs overflow-hidden">
        {isLoading ? (
          <div className="py-20 text-center space-y-3">
            <Loader2 className="w-7 h-7 text-emerald-600 animate-spin mx-auto" />
            <p className="text-xs text-neutral-500">Đang tải danh sách khách viếng thăm...</p>
          </div>
        ) : errorMsg ? (
          <div className="p-8 text-center space-y-3">
            <AlertCircle className="w-7 h-7 text-rose-500 mx-auto" />
            <p className="text-xs font-semibold text-rose-600">{errorMsg}</p>
            <button
              type="button"
              onClick={fetchVisitors}
              className="px-4 py-2 bg-neutral-900 text-white text-xs font-semibold rounded-xl"
            >
              Thử lại
            </button>
          </div>
        ) : visitors.length === 0 ? (
          <div className="py-20 text-center space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-neutral-100 text-neutral-400 mx-auto flex items-center justify-center">
              <Users className="w-6 h-6" />
            </div>
            <div className="space-y-1">
              <p className="text-sm font-bold text-neutral-800">Không có lượt khách nào</p>
              <p className="text-xs text-neutral-400 max-w-sm mx-auto">
                {searchQuery || statusFilter !== 'ALL'
                  ? 'Không tìm thấy kết quả phù hợp với điều kiện tìm kiếm hiện tại.'
                  : 'Bạn chưa tạo lượt khai báo khách viếng thăm nào. Hãy tạo lượt mới ngay.'}
              </p>
            </div>
            <div>
              <button
                type="button"
                onClick={handleOpenCreateModal}
                className="inline-flex items-center gap-1.5 px-4 py-2 bg-neutral-950 text-white rounded-xl text-xs font-semibold hover:bg-neutral-800 transition-all cursor-pointer"
              >
                <Plus className="w-3.5 h-3.5 text-emerald-400" />
                <span>Khai báo khách ngay</span>
              </button>
            </div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-neutral-100 bg-neutral-50/60 text-neutral-500 font-semibold uppercase tracking-wider">
                  <th className="py-3 px-4">Mã ĐK / Khách</th>
                  <th className="py-3 px-4">Liên hệ</th>
                  <th className="py-3 px-4">Thời gian dự kiến</th>
                  <th className="py-3 px-4">Căn hộ / Mục đích</th>
                  <th className="py-3 px-4">Phương tiện</th>
                  <th className="py-3 px-4">Trạng thái</th>
                  <th className="py-3 px-4 text-right">Thao tác</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {visitors.map((item) => (
                  <tr
                    key={item.id}
                    className="hover:bg-neutral-50/80 transition-colors group"
                  >
                    {/* Visitor name & code */}
                    <td className="py-3.5 px-4">
                      <div className="font-bold text-neutral-900 flex items-center gap-2">
                        <span>{item.visitor_name}</span>
                        {item.visitor_count > 1 && (
                          <span className="text-[10px] px-1.5 py-0.2 rounded font-medium bg-neutral-100 text-neutral-600">
                            +{item.visitor_count - 1} người
                          </span>
                        )}
                      </div>
                      <div className="text-[11px] text-neutral-400 font-mono mt-0.5">
                        {item.registration_code}
                      </div>
                    </td>

                    {/* Phone & ID */}
                    <td className="py-3.5 px-4">
                      <div className="flex items-center gap-1.5 text-neutral-700 font-medium">
                        <Phone className="w-3 h-3 text-neutral-400" />
                        <span>{item.visitor_phone}</span>
                      </div>
                      {item.visitor_national_id && (
                        <div className="text-[11px] text-neutral-400 font-mono mt-0.5">
                          CCCD: {item.visitor_national_id}
                        </div>
                      )}
                    </td>

                    {/* Times */}
                    <td className="py-3.5 px-4">
                      <div className="flex items-center gap-1.5 text-neutral-900 font-medium">
                        <Clock className="w-3 h-3 text-emerald-500" />
                        <span>{formatDateTime(item.expected_arrival_time)}</span>
                      </div>
                      {item.expected_departure_time && (
                        <div className="text-[11px] text-neutral-400 mt-0.5">
                          Rời đi: {formatDateTime(item.expected_departure_time)}
                        </div>
                      )}
                    </td>

                    {/* Apartment & Purpose */}
                    <td className="py-3.5 px-4">
                      <div className="font-medium text-neutral-800">
                        {item.apartment_number ? `Căn ${item.apartment_number}` : 'Căn hộ'}
                        {item.block_name ? ` · ${item.block_name}` : ''}
                      </div>
                      <div className="text-[11px] text-neutral-500 mt-0.5 line-clamp-1">
                        {item.visit_purpose}
                      </div>
                    </td>

                    {/* Vehicle */}
                    <td className="py-3.5 px-4">
                      {item.vehicle_license_plate ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded font-mono font-bold text-[11px] bg-neutral-100 text-neutral-800 border border-neutral-200">
                          <Car className="w-3 h-3 text-neutral-500" />
                          {item.vehicle_license_plate}
                        </span>
                      ) : (
                        <span className="text-neutral-400 text-[11px]">Không đăng ký xe</span>
                      )}
                    </td>

                    {/* Status */}
                    <td className="py-3.5 px-4">
                      {renderStatusBadge(item.qr_pass_status)}
                    </td>

                    {/* Actions */}
                    <td className="py-3.5 px-4 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        {/* View QR Pass / Details */}
                        <button
                          type="button"
                          onClick={() => handleOpenDetailModal(item)}
                          className="p-1.5 rounded-lg border border-neutral-200 text-neutral-600 hover:text-emerald-600 hover:border-emerald-200 hover:bg-emerald-50/50 transition-all cursor-pointer"
                          title="Xem mã QR Pass thông hành"
                        >
                          <QrCode className="w-4 h-4" />
                        </button>

                        {/* Edit button (Only if ACTIVE) */}
                        {item.qr_pass_status === 'ACTIVE' && (
                          <button
                            type="button"
                            onClick={() => handleOpenEditModal(item)}
                            className="p-1.5 rounded-lg border border-neutral-200 text-neutral-600 hover:text-neutral-900 hover:border-neutral-300 hover:bg-neutral-50 transition-all cursor-pointer"
                            title="Chỉnh sửa thông tin"
                          >
                            <Pencil className="w-4 h-4" />
                          </button>
                        )}

                        {/* Cancel button (Only if ACTIVE) */}
                        {item.qr_pass_status === 'ACTIVE' && (
                          <button
                            type="button"
                            onClick={() => handleOpenCancelModal(item)}
                            className="p-1.5 rounded-lg border border-neutral-200 text-neutral-400 hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 transition-all cursor-pointer"
                            title="Hủy lượt khai báo"
                          >
                            <XCircle className="w-4 h-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination Bar */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-neutral-100 bg-neutral-50/40 text-xs text-neutral-500">
            <div>
              Hiển thị <span className="font-semibold text-neutral-800">{visitors.length}</span> / {totalCount} bản ghi
            </div>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                disabled={currentPage <= 1 || isLoading}
                className="p-1.5 rounded-lg border border-neutral-200 hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <ChevronLeft className="w-4 h-4" />
              </button>
              <span className="px-2 font-medium">
                Trang {currentPage} / {totalPages}
              </span>
              <button
                type="button"
                onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                disabled={currentPage >= totalPages || isLoading}
                className="p-1.5 rounded-lg border border-neutral-200 hover:bg-white disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ================= MODAL: TẠO MỚI LƯỢT KHÁCH ================= */}
      {isCreateModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-2xl border border-neutral-200 shadow-2xl max-w-lg w-full p-6 space-y-4">
            <div className="flex items-start justify-between border-b border-neutral-100 pb-3">
              <div>
                <h3 className="font-extrabold text-base text-neutral-950">
                  Khai báo khách viếng thăm mới
                </h3>
                <p className="text-xs text-neutral-500">
                  Nhập thông tin người khách dự kiến đến thăm căn hộ của bạn.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setIsCreateModalOpen(false)}
                className="text-neutral-400 hover:text-neutral-700 p-1 rounded-lg"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleCreateSubmit} className="space-y-3.5">
              {formErrors.general && (
                <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-xs flex items-center gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0 text-rose-500" />
                  <span>{formErrors.general}</span>
                </div>
              )}
              {/* Visitor Name & Phone */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Họ và tên khách <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.visitor_name}
                    onChange={(e) => setFormData({ ...formData, visitor_name: e.target.value })}
                    placeholder="Nguyễn Văn B"
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                  {formErrors.visitor_name && (
                    <p className="text-[11px] text-rose-500 mt-0.5">{formErrors.visitor_name}</p>
                  )}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Số điện thoại <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="tel"
                    required
                    value={formData.visitor_phone}
                    onChange={(e) => setFormData({ ...formData, visitor_phone: e.target.value })}
                    placeholder="0912 345 678"
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                  {formErrors.visitor_phone && (
                    <p className="text-[11px] text-rose-500 mt-0.5">{formErrors.visitor_phone}</p>
                  )}
                </div>
              </div>

              {/* National ID & Number of Guests */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Số CCCD / CMND <span className="text-neutral-400 font-normal">(tùy chọn)</span>
                  </label>
                  <input
                    type="text"
                    value={formData.visitor_national_id}
                    onChange={(e) => setFormData({ ...formData, visitor_national_id: e.target.value })}
                    placeholder="001095012345"
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Số lượng người đi cùng
                  </label>
                  <input
                    type="number"
                    min={1}
                    max={20}
                    value={formData.visitor_count}
                    onChange={(e) => setFormData({ ...formData, visitor_count: Number(e.target.value) || 1 })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>
              </div>

              {/* Arrival Time & Departure Time */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Thời gian dự kiến đến <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="datetime-local"
                    required
                    value={formData.expected_arrival_time}
                    onChange={(e) => setFormData({ ...formData, expected_arrival_time: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                  {formErrors.expected_arrival_time && (
                    <p className="text-[11px] text-rose-500 mt-0.5">{formErrors.expected_arrival_time}</p>
                  )}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Dự kiến rời đi <span className="text-neutral-400 font-normal">(tùy chọn)</span>
                  </label>
                  <input
                    type="datetime-local"
                    value={formData.expected_departure_time}
                    onChange={(e) => setFormData({ ...formData, expected_departure_time: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                  {formErrors.expected_departure_time && (
                    <p className="text-[11px] text-rose-500 mt-0.5">{formErrors.expected_departure_time}</p>
                  )}
                </div>
              </div>

              {/* Vehicle License Plate & Visit Purpose */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Biển số xe khách <span className="text-neutral-400 font-normal">(nếu đi xe)</span>
                  </label>
                  <input
                    type="text"
                    value={formData.vehicle_license_plate}
                    onChange={(e) => setFormData({ ...formData, vehicle_license_plate: e.target.value.toUpperCase() })}
                    placeholder="29A-888.88"
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 font-mono"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Mục đích viếng thăm
                  </label>
                  <select
                    value={formData.visit_purpose}
                    onChange={(e) => setFormData({ ...formData, visit_purpose: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  >
                    <option value="Thăm người thân">Thăm người thân</option>
                    <option value="Giao nhận hàng hóa / Bưu phẩm">Giao nhận hàng hóa / Bưu phẩm</option>
                    <option value="Sửa chữa / Lắp đặt thiết bị">Sửa chữa / Lắp đặt thiết bị</option>
                    <option value="Gặp gỡ công việc">Gặp gỡ công việc</option>
                    <option value="Khác">Mục đích khác</option>
                  </select>
                </div>
              </div>

              <div className="p-3 bg-neutral-50 rounded-xl border border-neutral-100 text-[11px] text-neutral-600 flex items-start gap-2">
                <ShieldCheck className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                <span>
                  Sau khi khai báo thành công, hệ thống tự động sinh <strong>Thẻ QR Pass</strong>. Bạn có thể gửi ảnh mã QR này cho khách để được bảo vệ tiếp đón nhanh mà không cần khai báo giấy.
                </span>
              </div>

              <div className="pt-3 border-t border-neutral-100 flex items-center justify-end gap-2.5">
                <button
                  type="button"
                  onClick={() => setIsCreateModalOpen(false)}
                  className="px-4 py-2 rounded-xl border border-neutral-200 hover:bg-neutral-50 text-neutral-700 text-xs font-medium cursor-pointer"
                >
                  Đóng
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white text-xs font-semibold shadow-sm disabled:opacity-50 cursor-pointer"
                >
                  {isSubmitting && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>Xác nhận khai báo</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ================= MODAL: CHỈNH SỬA LƯỢT KHÁCH ================= */}
      {isEditModalOpen && selectedVisitor && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-2xl border border-neutral-200 shadow-2xl max-w-lg w-full p-6 space-y-4">
            <div className="flex items-start justify-between border-b border-neutral-100 pb-3">
              <div>
                <h3 className="font-extrabold text-base text-neutral-950">
                  Chỉnh sửa thông tin khách viếng thăm
                </h3>
                <p className="text-xs text-neutral-500">
                  Mã đăng ký: <span className="font-mono font-bold text-neutral-800">{selectedVisitor.registration_code}</span>
                </p>
              </div>
              <button
                type="button"
                onClick={() => setIsEditModalOpen(false)}
                className="text-neutral-400 hover:text-neutral-700 p-1 rounded-lg"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleUpdateSubmit} className="space-y-3.5">
              {formErrors.general && (
                <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-xs flex items-center gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0 text-rose-500" />
                  <span>{formErrors.general}</span>
                </div>
              )}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Họ và tên khách <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={formData.visitor_name}
                    onChange={(e) => setFormData({ ...formData, visitor_name: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                  {formErrors.visitor_name && (
                    <p className="text-[11px] text-rose-500 mt-0.5">{formErrors.visitor_name}</p>
                  )}
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Số điện thoại <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="tel"
                    required
                    value={formData.visitor_phone}
                    onChange={(e) => setFormData({ ...formData, visitor_phone: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                  {formErrors.visitor_phone && (
                    <p className="text-[11px] text-rose-500 mt-0.5">{formErrors.visitor_phone}</p>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Số CCCD / CMND
                  </label>
                  <input
                    type="text"
                    value={formData.visitor_national_id}
                    onChange={(e) => setFormData({ ...formData, visitor_national_id: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Số lượng người
                  </label>
                  <input
                    type="number"
                    min={1}
                    max={20}
                    value={formData.visitor_count}
                    onChange={(e) => setFormData({ ...formData, visitor_count: Number(e.target.value) || 1 })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Thời gian dự kiến đến <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="datetime-local"
                    required
                    value={formData.expected_arrival_time}
                    onChange={(e) => setFormData({ ...formData, expected_arrival_time: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Dự kiến rời đi
                  </label>
                  <input
                    type="datetime-local"
                    value={formData.expected_departure_time}
                    onChange={(e) => setFormData({ ...formData, expected_departure_time: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Biển số xe khách
                  </label>
                  <input
                    type="text"
                    value={formData.vehicle_license_plate}
                    onChange={(e) => setFormData({ ...formData, vehicle_license_plate: e.target.value.toUpperCase() })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 font-mono"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 mb-1">
                    Mục đích viếng thăm
                  </label>
                  <input
                    type="text"
                    value={formData.visit_purpose}
                    onChange={(e) => setFormData({ ...formData, visit_purpose: e.target.value })}
                    className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>
              </div>

              <div className="pt-3 border-t border-neutral-100 flex items-center justify-end gap-2.5">
                <button
                  type="button"
                  onClick={() => setIsEditModalOpen(false)}
                  className="px-4 py-2 rounded-xl border border-neutral-200 hover:bg-neutral-50 text-neutral-700 text-xs font-medium cursor-pointer"
                >
                  Đóng
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white text-xs font-semibold shadow-sm disabled:opacity-50 cursor-pointer"
                >
                  {isSubmitting && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>Lưu thay đổi</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ================= MODAL: QR PASS & CHI TIẾT TIẾP ĐÓN ================= */}
      {isDetailModalOpen && selectedVisitor && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-3xl border border-neutral-200 shadow-2xl max-w-md w-full overflow-hidden">
            {/* Top Pass Card Header */}
            <div className="bg-neutral-950 text-white p-5 space-y-3 relative overflow-hidden">
              <div className="absolute -right-6 -bottom-6 w-32 h-32 bg-emerald-500/10 rounded-full blur-xl pointer-events-none" />
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <div className="w-8 h-8 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center border border-emerald-500/30">
                    <ShieldCheck className="w-4 h-4" />
                  </div>
                  <div>
                    <h4 className="text-xs font-extrabold tracking-wider uppercase text-neutral-300">
                      THẺ THÔNG HÀNH KHÁCH
                    </h4>
                    <span className="text-[10px] text-emerald-400 font-medium">SMART VISITOR PASS</span>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setIsDetailModalOpen(false)}
                  className="text-neutral-400 hover:text-white p-1 rounded-lg"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>

              {/* QR Code Graphic Mockup */}
              <div className="bg-white rounded-2xl p-5 text-neutral-900 flex flex-col items-center justify-center text-center space-y-3 shadow-inner my-2">
                {/* SVG QR Code Simulation */}
                <div className="w-40 h-40 bg-neutral-900 p-2 rounded-xl flex items-center justify-center relative">
                  <div className="w-full h-full bg-white rounded-lg p-2 flex flex-col justify-between items-center">
                    <div className="w-full flex justify-between">
                      <div className="w-8 h-8 border-4 border-neutral-900 rounded-sm p-1 flex items-center justify-center">
                        <div className="w-2.5 h-2.5 bg-neutral-900" />
                      </div>
                      <div className="w-8 h-8 border-4 border-neutral-900 rounded-sm p-1 flex items-center justify-center">
                        <div className="w-2.5 h-2.5 bg-neutral-900" />
                      </div>
                    </div>
                    <div className="my-auto flex flex-col items-center">
                      <QrCode className="w-12 h-12 text-neutral-900" />
                      <span className="text-[9px] font-mono font-bold tracking-widest text-neutral-500">
                        VERIFIED PASS
                      </span>
                    </div>
                    <div className="w-full flex justify-between">
                      <div className="w-8 h-8 border-4 border-neutral-900 rounded-sm p-1 flex items-center justify-center">
                        <div className="w-2.5 h-2.5 bg-neutral-900" />
                      </div>
                      <div className="w-8 h-8 border-2 border-dashed border-neutral-400 rounded-sm flex items-center justify-center">
                        <div className="w-2 h-2 bg-emerald-500 rounded-full" />
                      </div>
                    </div>
                  </div>
                </div>

                {/* Pass Code */}
                <div>
                  <div className="text-[11px] text-neutral-400 font-semibold uppercase">Mã thông hành QR</div>
                  <div className="font-mono font-extrabold text-sm text-neutral-900 tracking-wider">
                    {selectedVisitor.qr_access_pass_code}
                  </div>
                </div>

                {/* Copy button */}
                <button
                  type="button"
                  onClick={() => handleCopyPassCode(selectedVisitor.qr_access_pass_code)}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-neutral-100 hover:bg-neutral-200 text-neutral-700 text-xs font-semibold transition-all cursor-pointer"
                >
                  {copiedPassCode ? (
                    <>
                      <Check className="w-3.5 h-3.5 text-emerald-600" />
                      <span className="text-emerald-700">Đã sao chép mã!</span>
                    </>
                  ) : (
                    <>
                      <Copy className="w-3.5 h-3.5 text-neutral-500" />
                      <span>Sao chép mã cho khách</span>
                    </>
                  )}
                </button>
              </div>
            </div>

            {/* Pass Detail Info */}
            <div className="p-5 space-y-3.5 text-xs">
              <div className="space-y-2 border-b border-neutral-100 pb-3">
                <div className="flex justify-between items-center">
                  <span className="text-neutral-500">Khách viếng thăm:</span>
                  <span className="font-bold text-neutral-900">{selectedVisitor.visitor_name}</span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-neutral-500">Số điện thoại:</span>
                  <span className="font-medium text-neutral-800 font-mono">{selectedVisitor.visitor_phone}</span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-neutral-500">Căn hộ đón tiếp:</span>
                  <span className="font-bold text-emerald-700">
                    {selectedVisitor.apartment_number ? `Căn ${selectedVisitor.apartment_number}` : 'Căn hộ cư dân'}
                    {selectedVisitor.block_name ? ` · ${selectedVisitor.block_name}` : ''}
                  </span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-neutral-500">Thời gian dự kiến đến:</span>
                  <span className="font-medium text-neutral-800">
                    {formatDateTime(selectedVisitor.expected_arrival_time)}
                  </span>
                </div>
                {selectedVisitor.expected_departure_time && (
                  <div className="flex justify-between items-center">
                    <span className="text-neutral-500">Dự kiến rời đi:</span>
                    <span className="text-neutral-600">
                      {formatDateTime(selectedVisitor.expected_departure_time)}
                    </span>
                  </div>
                )}
                {selectedVisitor.vehicle_license_plate && (
                  <div className="flex justify-between items-center">
                    <span className="text-neutral-500">Biển số phương tiện:</span>
                    <span className="font-mono font-bold text-neutral-900">
                      {selectedVisitor.vehicle_license_plate}
                    </span>
                  </div>
                )}
                <div className="flex justify-between items-center">
                  <span className="text-neutral-500">Trạng thái vé:</span>
                  {renderStatusBadge(selectedVisitor.qr_pass_status)}
                </div>
              </div>

              {/* Checkin Log note */}
              {detailData?.checkin_log && (
                <div className="p-3 rounded-xl bg-sky-50 border border-sky-100 text-[11px] text-sky-800 space-y-1">
                  <div className="font-bold flex items-center gap-1.5">
                    <UserCheck className="w-3.5 h-3.5" />
                    <span>Khách đã check-in tại quầy:</span>
                  </div>
                  <div>Thời gian: {formatDateTime(detailData.checkin_log.checkin_time)}</div>
                </div>
              )}

              <div className="flex items-center justify-end">
                <button
                  type="button"
                  onClick={() => setIsDetailModalOpen(false)}
                  className="w-full py-2.5 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white font-semibold text-xs transition-all cursor-pointer"
                >
                  Đóng thẻ thông hành
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL: HỦY LƯỢT KHAI BÁO ================= */}
      {isCancelModalOpen && selectedVisitor && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-2xl border border-neutral-200 shadow-2xl max-w-sm w-full p-5 space-y-4">
            <div className="flex items-start gap-3">
              <div className="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0 border border-rose-100">
                <AlertCircle className="w-5 h-5" />
              </div>
              <div>
                <h3 className="font-extrabold text-sm text-neutral-950">
                  Xác nhận hủy lượt khai báo?
                </h3>
                <p className="text-xs text-neutral-500 mt-1">
                  Thẻ QR Pass của khách <strong>{selectedVisitor.visitor_name}</strong> sẽ bị vô hiệu hóa ngay lập tức.
                </p>
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Lý do hủy <span className="text-neutral-400 font-normal">(tùy chọn)</span>
              </label>
              <textarea
                rows={2}
                value={cancelReason}
                onChange={(e) => setCancelReason(e.target.value)}
                placeholder="Khách thay đổi lịch trình, hoãn gặp mặt..."
                className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-rose-500"
              />
            </div>

            <div className="flex items-center justify-end gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setIsCancelModalOpen(false)}
                className="px-3.5 py-2 rounded-xl border border-neutral-200 hover:bg-neutral-50 text-neutral-700 text-xs font-medium cursor-pointer"
              >
                Không hủy
              </button>
              <button
                type="button"
                onClick={handleCancelSubmit}
                disabled={isSubmitting}
                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-sm disabled:opacity-50 cursor-pointer"
              >
                {isSubmitting && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                <span>Xác nhận hủy</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ResidentVisitorRegistrationPanel;
