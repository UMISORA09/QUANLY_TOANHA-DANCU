import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { createPortal } from 'react-dom';
import {
  Users,
  UserCheck,
  Clock,
  Search,
  RefreshCw,
  QrCode,
  Phone,
  Car,
  CheckCircle2,
  AlertCircle,
  Eye,
  Copy,
  Check,
  Building,
  ShieldCheck,
  X,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  ChevronUp,
  Filter,
  Loader2,
  LogOut,
  User,
} from 'lucide-react';
import {
  api,
  ReceptionVisitorItem,
  ReceptionVisitorLookupResponse,
} from '../Services/api';
import { useRealtimeSync, RealtimeEventPayload } from '../Hooks/useRealtimeSync';

interface ReceptionVisitorCheckinPanelProps {
  embedded?: boolean;
}

export const ReceptionVisitorCheckinPanel: React.FC<ReceptionVisitorCheckinPanelProps> = ({
  embedded = false,
}) => {
  // 1. Dữ liệu chính & KPIs
  const [visitors, setVisitors] = useState<ReceptionVisitorItem[]>([]);
  const [kpis, setKpis] = useState({
    expected_today: 0,
    currently_inside: 0,
    checked_out_today: 0,
    total_registrations: 0,
  });
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isFetching, setIsFetching] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'error' | 'info' } | null>(null);

  // Bộ nhớ đệm Client (In-memory SWR Cache) cho các tab và trang (Chuyển tab 0ms)
  const tabCacheRef = useRef<Map<string, {
    data: ReceptionVisitorItem[];
    pagination: { current_page: number; total_pages: number; total_items: number };
    kpis: { expected_today: number; currently_inside: number; checked_out_today: number; total_registrations: number };
  }>>(new Map());

  // 2. Bộ lọc & Phân trang
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [debouncedSearch, setDebouncedSearch] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [perPage, setPerPage] = useState<number>(15);
  const [totalPages, setTotalPages] = useState<number>(1);
  const [totalCount, setTotalCount] = useState<number>(0);

  // 3. Tra cứu nhanh / Quét mã QR
  const [quickScanCode, setQuickScanCode] = useState<string>('');
  const [isScanning, setIsScanning] = useState<boolean>(false);
  const [lookupResult, setLookupResult] = useState<ReceptionVisitorLookupResponse['data'] | null>(null);
  const [isLookupModalOpen, setIsLookupModalOpen] = useState<boolean>(false);

  // Custom Dropdown Open States (Chuẩn Ảnh 2)
  const [isStatusDropdownOpen, setIsStatusDropdownOpen] = useState<boolean>(false);
  const [isPerPageDropdownOpen, setIsPerPageDropdownOpen] = useState<boolean>(false);
  const statusDropdownRef = useRef<HTMLDivElement>(null);
  const perPageDropdownRef = useRef<HTMLDivElement>(null);

  // Click outside to close dropdowns
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (statusDropdownRef.current && !statusDropdownRef.current.contains(event.target as Node)) {
        setIsStatusDropdownOpen(false);
      }
      if (perPageDropdownRef.current && !perPageDropdownRef.current.contains(event.target as Node)) {
        setIsPerPageDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, []);

  // 4. Modal Check-in
  const [isCheckinModalOpen, setIsCheckinModalOpen] = useState<boolean>(false);
  const [checkinTarget, setCheckinTarget] = useState<ReceptionVisitorItem | null>(null);
  const [checkinFormData, setCheckinFormData] = useState({
    national_id_number: '',
    temp_card_id: '',
    notes: '',
  });
  const [isSubmittingCheckin, setIsSubmittingCheckin] = useState<boolean>(false);

  // 5. Modal Check-out
  const [isCheckoutModalOpen, setIsCheckoutModalOpen] = useState<boolean>(false);
  const [checkoutTarget, setCheckoutTarget] = useState<ReceptionVisitorItem | null>(null);
  const [checkoutNotes, setCheckoutNotes] = useState<string>('');
  const [isSubmittingCheckout, setIsSubmittingCheckout] = useState<boolean>(false);

  // 6. Modal Chi tiết
  const [selectedVisitor, setSelectedVisitor] = useState<ReceptionVisitorItem | null>(null);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);
  const [copiedCode, setCopiedCode] = useState<string | null>(null);

  // AbortController ref để hủy các request cũ khi người dùng gõ tìm kiếm liên tục
  const searchAbortControllerRef = useRef<AbortController | null>(null);

  // Debounce tìm kiếm 350ms
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Khóa cuộn trang khi modal đang mở để modal luôn cố định chính giữa khung nhìn màn hình
  useEffect(() => {
    const isAnyModalOpen = isCheckinModalOpen || isCheckoutModalOpen || isLookupModalOpen || isDetailModalOpen;
    if (isAnyModalOpen) {
      const originalOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      return () => {
        document.body.style.overflow = originalOverflow;
      };
    }
  }, [isCheckinModalOpen, isCheckoutModalOpen, isLookupModalOpen, isDetailModalOpen]);

  const showToast = (text: string, type: 'success' | 'error' | 'info' = 'success') => {
    setToastMessage({ text, type });
    setTimeout(() => setToastMessage(null), 3500);
  };

  // Sao chép mã vào clipboard
  const handleCopyCode = (code: string) => {
    navigator.clipboard.writeText(code);
    setCopiedCode(code);
    showToast(`Đã sao chép mã: ${code}`, 'info');
    setTimeout(() => setCopiedCode(null), 2000);
  };

  // Fetch danh sách khách từ Server (SWR pattern)
  const fetchVisitors = useCallback(
    async (
      targetStatus: string = statusFilter,
      targetPage: number = currentPage,
      targetSearch: string = debouncedSearch,
      targetLimit: number = perPage,
      isSilent: boolean = false
    ) => {
      if (searchAbortControllerRef.current) {
        searchAbortControllerRef.current.abort();
      }
      const abortController = new AbortController();
      searchAbortControllerRef.current = abortController;

      const cacheKey = `${targetStatus}_${targetSearch}_${targetPage}_${targetLimit}`;
      const cached = tabCacheRef.current.get(cacheKey);

      if (!isSilent && !cached) {
        setIsLoading(true);
      }
      setIsFetching(true);
      setErrorMsg(null);

      try {
        const res = await api.getReceptionVisitors(
          {
            search: targetSearch,
            status: targetStatus,
            page: targetPage,
            limit: targetLimit,
          },
          { signal: abortController.signal }
        );

        if (res.success) {
          const fetchedData = res.data || [];
          setVisitors(fetchedData);

          if (res.kpis) {
            setKpis(res.kpis);
          }
          if (res.pagination) {
            setCurrentPage(res.pagination.current_page);
            setTotalPages(res.pagination.total_pages);
            setTotalCount(res.pagination.total_items);
          }

          // Lưu vào cache để chuyển tab/trang 0ms
          tabCacheRef.current.set(cacheKey, {
            data: fetchedData,
            pagination: {
              current_page: res.pagination?.current_page || targetPage,
              total_pages: res.pagination?.total_pages || 1,
              total_items: res.pagination?.total_items || fetchedData.length,
            },
            kpis: res.kpis || kpis,
          });
        }
      } catch (err: any) {
        if (err?.name === 'AbortError') {
          return;
        }
        console.error('Lỗi khi tải danh sách khách:', err);
        setErrorMsg(err.message || 'Không thể tải danh sách khách viếng thăm.');
      } finally {
        if (!abortController.signal.aborted) {
          setIsLoading(false);
          setIsFetching(false);
        }
      }
    },
    [statusFilter, currentPage, debouncedSearch, perPage]
  );

  // Chuyển tab: Chuyển ngay và nạp dữ liệu chuẩn xác cho từng tab
  const handleTabChange = useCallback(
    (newStatus: string) => {
      if (newStatus === statusFilter) return;

      setStatusFilter(newStatus);
      setCurrentPage(1);

      const cacheKey = `${newStatus}_${debouncedSearch}_1_${perPage}`;
      const cached = tabCacheRef.current.get(cacheKey);

      if (cached) {
        // 1. Phục vụ ngay lập tức từ bộ nhớ đệm (0ms)
        setVisitors(cached.data);
        setTotalPages(cached.pagination.total_pages);
        setTotalCount(cached.pagination.total_items);
        // Đồng bộ ngầm trong nền
        fetchVisitors(newStatus, 1, debouncedSearch, perPage, true);
      } else {
        // 2. Chưa có trong cache -> hiện loading và fetch dữ liệu thực từ Server
        setVisitors([]);
        setIsLoading(true);
        fetchVisitors(newStatus, 1, debouncedSearch, perPage, false);
      }
    },
    [statusFilter, debouncedSearch, perPage, fetchVisitors]
  );

  // Chuyển trang siêu tốc tận dụng cache
  const handlePageChange = useCallback(
    (newPage: number) => {
      setCurrentPage(newPage);
      const cacheKey = `${statusFilter}_${debouncedSearch}_${newPage}_${perPage}`;
      const cached = tabCacheRef.current.get(cacheKey);

      if (cached) {
        setVisitors(cached.data);
        setTotalPages(cached.pagination.total_pages);
        setTotalCount(cached.pagination.total_items);
        fetchVisitors(statusFilter, newPage, debouncedSearch, perPage, true);
      } else {
        setIsLoading(true);
        fetchVisitors(statusFilter, newPage, debouncedSearch, perPage, false);
      }
    },
    [statusFilter, debouncedSearch, perPage, fetchVisitors]
  );

  // Thay đổi số dòng trên 1 trang
  const handlePerPageChange = (newLimit: number) => {
    setPerPage(newLimit);
    setCurrentPage(1);
    tabCacheRef.current.clear();
    fetchVisitors(statusFilter, 1, debouncedSearch, newLimit, false);
  };

  const isFirstMountRef = useRef<boolean>(true);

  // Khi mount hoặc từ khóa tìm kiếm thay đổi (sau 350ms debounce)
  useEffect(() => {
    if (isFirstMountRef.current) {
      isFirstMountRef.current = false;
      fetchVisitors('ALL', 1, '', perPage, false);
      return;
    }
    fetchVisitors(statusFilter, 1, debouncedSearch, perPage, false);
  }, [debouncedSearch]);

  // Realtime Live Sync: Khi có bất kỳ mutation nào (A check-in / B check-out)
  const handleRealtimeEvent = useCallback(
    (event: RealtimeEventPayload) => {
      if (event.module === 'visitors' || event.entity === 'visitor_checkin' || event.entity === 'visitor_registration') {
        tabCacheRef.current.clear();
        fetchVisitors(statusFilter, currentPage, debouncedSearch, perPage, true);

        if (event.action === 'CHECKED_IN') {
          showToast(`⚡ Realtime: Khách ${event.visitor_name || ''} vừa check-in vào tòa nhà!`, 'info');
        } else if (event.action === 'CHECKED_OUT') {
          showToast(`⚡ Realtime: Khách ${event.visitor_name || ''} vừa check-out khỏi tòa nhà!`, 'info');
        }
      }
    },
    [statusFilter, currentPage, debouncedSearch, perPage, fetchVisitors]
  );

  useRealtimeSync({
    channel: 'quoc-tin.visitors',
    onEvent: handleRealtimeEvent,
    onReconnect: () => {
      tabCacheRef.current.clear();
      fetchVisitors(statusFilter, currentPage, debouncedSearch, perPage, true);
    },
  });

  // Xử lý Tra cứu nhanh / Quét mã QR
  const handleQuickLookup = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    const code = quickScanCode.trim();
    if (!code) {
      showToast('Vui lòng nhập hoặc quét mã QR Pass Code / Mã đăng ký', 'error');
      return;
    }

    setIsScanning(true);
    try {
      const res = await api.lookupReceptionVisitor(code);
      if (res.success && res.data) {
        setLookupResult(res.data);
        setIsLookupModalOpen(true);
      }
    } catch (err: any) {
      showToast(err.message || `Không tìm thấy lượt khách với mã: ${code}`, 'error');
    } finally {
      setIsScanning(false);
    }
  };

  // Mở modal Check-in từ danh sách
  const handleOpenCheckinModal = (item: ReceptionVisitorItem) => {
    setCheckinTarget(item);
    setCheckinFormData({
      national_id_number: item.visitor_national_id || '',
      temp_card_id: '',
      notes: '',
    });
    setIsCheckinModalOpen(true);
  };

  // Xác nhận Check-in
  const handleConfirmCheckin = async () => {
    if (!checkinTarget) return;

    setIsSubmittingCheckin(true);
    try {
      const res = await api.checkInReceptionVisitor({
        registration_id: checkinTarget.registration_id,
        national_id_number: checkinFormData.national_id_number,
        temp_card_id: checkinFormData.temp_card_id,
        notes: checkinFormData.notes,
      });

      if (res.success) {
        showToast(res.message || `Check-in thành công cho khách ${checkinTarget.visitor_name}!`, 'success');
        setIsCheckinModalOpen(false);
        setCheckinTarget(null);
        if (isLookupModalOpen) setIsLookupModalOpen(false);
        tabCacheRef.current.clear();
        fetchVisitors();
      }
    } catch (err: any) {
      if (err.status === 409) {
        showToast(`⚠️ Xung đột: ${err.message}`, 'error');
      } else {
        showToast(err.message || 'Lỗi khi thực hiện check-in.', 'error');
      }
      tabCacheRef.current.clear();
      fetchVisitors();
    } finally {
      setIsSubmittingCheckin(false);
    }
  };

  // Mở modal Check-out từ danh sách
  const handleOpenCheckoutModal = (item: ReceptionVisitorItem) => {
    setCheckoutTarget(item);
    setCheckoutNotes('');
    setIsCheckoutModalOpen(true);
  };

  // Xác nhận Check-out
  const handleConfirmCheckout = async () => {
    if (!checkoutTarget) return;

    setIsSubmittingCheckout(true);
    try {
      const res = await api.checkOutReceptionVisitor({
        checkin_log_id: checkoutTarget.checkin_log?.id,
        registration_id: checkoutTarget.registration_id,
        notes: checkoutNotes,
      });

      if (res.success) {
        showToast(res.message || `Check-out thành công cho khách ${checkoutTarget.visitor_name}!`, 'success');
        setIsCheckoutModalOpen(false);
        setCheckoutTarget(null);
        if (isLookupModalOpen) setIsLookupModalOpen(false);
        tabCacheRef.current.clear();
        fetchVisitors();
      }
    } catch (err: any) {
      if (err.status === 409) {
        showToast(`⚠️ Xung đột: ${err.message}`, 'error');
      } else {
        showToast(err.message || 'Lỗi khi thực hiện check-out.', 'error');
      }
      tabCacheRef.current.clear();
      fetchVisitors();
    } finally {
      setIsSubmittingCheckout(false);
    }
  };

  // Format ngày giờ Việt Nam
  const formatDateTime = (dateStr?: string | null) => {
    if (!dateStr) return '—';
    try {
      const d = new Date(dateStr);
      return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')} - ${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
    } catch {
      return dateStr;
    }
  };

  // Badge trạng thái chuẩn phong cách RFID Management
  const getStatusBadge = (status: string, statusLabel: string) => {
    switch (status) {
      case 'INSIDE':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-sky-100 text-sky-800 border border-sky-300">
            <span className="w-1.5 h-1.5 rounded-full bg-sky-500 animate-ping" />
            <span>{statusLabel || 'ĐANG TRONG TÒA'}</span>
          </span>
        );
      case 'EXPECTED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800 border border-indigo-200">
            <Clock className="w-3 h-3 text-indigo-600" />
            <span>{statusLabel || 'CHỜ CHECK-IN'}</span>
          </span>
        );
      case 'CHECKED_OUT':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
            <CheckCircle2 className="w-3 h-3 text-emerald-600" />
            <span>{statusLabel || 'ĐÃ CHECK-OUT'}</span>
          </span>
        );
      case 'CANCELLED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
            <span>{statusLabel || 'ĐÃ HỦY / HẾT HẠN'}</span>
          </span>
        );
      default:
        return (
          <span className="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-700">
            {statusLabel || status}
          </span>
        );
    }
  };

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* Toast Notification (Chuẩn phong cách RFID Management) */}
      {toastMessage && typeof document !== 'undefined' && createPortal(
        <div
          className={`fixed top-5 right-5 z-[9999] flex items-center gap-2 px-4 py-3 rounded-lg shadow-xl text-sm font-medium border transition-all duration-300 ${
            toastMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-900 border-emerald-300'
              : toastMessage.type === 'error'
              ? 'bg-rose-50 text-rose-900 border-rose-300'
              : 'bg-sky-50 text-sky-900 border-sky-300'
          }`}
        >
          {toastMessage.type === 'success' ? (
            <CheckCircle2 className="w-5 h-5 text-emerald-600" />
          ) : toastMessage.type === 'error' ? (
            <AlertCircle className="w-5 h-5 text-rose-600" />
          ) : (
            <CheckCircle2 className="w-5 h-5 text-sky-600 animate-pulse" />
          )}
          <span>{toastMessage.text}</span>
          <button
            type="button"
            onClick={() => setToastMessage(null)}
            className="ml-2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>,
        document.body
      )}

      {/* 1. Header & Title (Đồng bộ chuẩn RFID Management) */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-indigo-600">
            <Users className="w-4 h-4 text-indigo-600" />
            <span>CỔNG TIẾP ĐÓN LỄ TÂN · KHÁCH VIẾNG THĂM</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Tra Cứu & Check-in / Check-out Khách Viếng Thăm
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Kiểm soát khách vào/ra tòa nhà tức thời, tra cứu mã QR Pass và đồng bộ thời gian thực giữa các quầy tiếp đón.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={() => {
              tabCacheRef.current.clear();
              fetchVisitors(statusFilter, currentPage, debouncedSearch, perPage, false);
            }}
            className="flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition shadow-sm cursor-pointer"
            title="Làm mới dữ liệu từ server"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isFetching ? 'animate-spin text-indigo-600' : ''}`} />
            <span>Tải lại</span>
          </button>
        </div>
      </div>

      {/* 2. KPI Statistics Cards (Đồng bộ 100% chuẩn RFID Management) */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {/* KPI 1: Chờ check-in hôm nay */}
        <div
          onClick={() => handleTabChange('EXPECTED')}
          className={`bg-white p-4 sm:p-5 rounded-2xl border shadow-sm flex items-center gap-4 cursor-pointer transition ${
            statusFilter === 'EXPECTED'
              ? 'border-blue-400 ring-2 ring-blue-100'
              : 'border-slate-200/80 hover:border-blue-300'
          }`}
        >
          <div className="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
            <Clock className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Chờ check-in hôm nay</div>
            <div className="text-2xl font-black text-slate-900">{kpis.expected_today}</div>
            <div className="text-[11px] text-blue-600 font-semibold mt-0.5">Khách đã hẹn trước</div>
          </div>
        </div>

        {/* KPI 2: Đang trong tòa nhà */}
        <div
          onClick={() => handleTabChange('INSIDE')}
          className={`bg-white p-4 sm:p-5 rounded-2xl border shadow-sm flex items-center gap-4 cursor-pointer transition ${
            statusFilter === 'INSIDE'
              ? 'border-emerald-400 ring-2 ring-emerald-100'
              : 'border-slate-200/80 hover:border-emerald-300'
          }`}
        >
          <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <UserCheck className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Đang trong tòa nhà</div>
            <div className="text-2xl font-black text-emerald-700">{kpis.currently_inside}</div>
            <div className="text-[11px] text-emerald-600 font-semibold mt-0.5">Cần check-out khi về</div>
          </div>
        </div>

        {/* KPI 3: Đã check-out hôm nay */}
        <div
          onClick={() => handleTabChange('CHECKED_OUT')}
          className={`bg-white p-4 sm:p-5 rounded-2xl border shadow-sm flex items-center gap-4 cursor-pointer transition ${
            statusFilter === 'CHECKED_OUT'
              ? 'border-amber-400 ring-2 ring-amber-100'
              : 'border-slate-200/80 hover:border-amber-300'
          }`}
        >
          <div className="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <LogOut className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Đã check-out hôm nay</div>
            <div className="text-2xl font-black text-amber-700">{kpis.checked_out_today}</div>
            <div className="text-[11px] text-amber-600 font-semibold mt-0.5">Đã rời khỏi tòa nhà</div>
          </div>
        </div>

        {/* KPI 4: Tổng lượt khai báo */}
        <div
          onClick={() => handleTabChange('ALL')}
          className={`bg-white p-4 sm:p-5 rounded-2xl border shadow-sm flex items-center gap-4 cursor-pointer transition ${
            statusFilter === 'ALL'
              ? 'border-purple-400 ring-2 ring-purple-100'
              : 'border-slate-200/80 hover:border-purple-300'
          }`}
        >
          <div className="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center flex-shrink-0">
            <Building className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tổng lượt khai báo</div>
            <div className="text-2xl font-black text-purple-700">{kpis.total_registrations}</div>
            <div className="text-[11px] text-purple-600 font-semibold mt-0.5">Tất cả hồ sơ đăng ký</div>
          </div>
        </div>
      </div>

      {/* 3. Quick QR Pass / Barcode Scanner Panel (Phong cách Card sạch sẽ, hiện đại) */}
      <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-indigo-600">
            <QrCode className="w-4 h-4 text-indigo-600" />
            <span>MÁY QUÉT MÃ QR & TIẾP ĐÓN NHANH TẠI QUẦY</span>
          </div>
          <div className="text-sm font-bold text-slate-900 mt-1">
            Tra cứu tức thì bằng Mã QR Pass hoặc Mã đăng ký
          </div>
          <p className="text-xs text-slate-500 mt-0.5">
            Hỗ trợ máy quét mã vạch USB, súng quét QR hoặc nhập trực tiếp mã số (VD: VR-XXXXXXXX hoặc SĐT).
          </p>
        </div>

        <form
          onSubmit={handleQuickLookup}
          className="flex items-center gap-2 w-full md:w-auto"
        >
          <div className="relative flex-1 md:w-80">
            <QrCode className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              value={quickScanCode}
              onChange={(e) => setQuickScanCode(e.target.value)}
              placeholder="Quét mã QR hoặc nhập VR-XXXX..."
              className="w-full pl-9 pr-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono text-slate-800 placeholder-slate-400"
            />
            {quickScanCode && (
              <button
                type="button"
                onClick={() => setQuickScanCode('')}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>

          <button
            type="submit"
            disabled={isScanning || !quickScanCode.trim()}
            className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded-lg transition shadow-sm cursor-pointer shrink-0"
          >
            {isScanning ? (
              <Loader2 className="w-3.5 h-3.5 animate-spin" />
            ) : (
              <Search className="w-3.5 h-3.5" />
            )}
            <span>Tra cứu</span>
          </button>
        </form>
      </div>

      {/* 4. Filter & Search Bar (Đồng bộ chuẩn RFID Management) */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          {/* Search Input */}
          <div className="relative lg:col-span-2">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Tìm theo tên khách, SĐT, mã QR, căn hộ, biển số..."
              className="w-full pl-9 pr-8 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white text-slate-800 placeholder-slate-400"
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 cursor-pointer"
                title="Xóa tìm kiếm"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>

          {/* Trạng thái filter dropdown chuẩn 100% Ảnh 2 */}
          <div className="relative" ref={statusDropdownRef}>
            {(() => {
              const statusOptions = [
                { id: 'ALL', label: '-- Tất cả trạng thái --', isAll: true },
                { id: 'EXPECTED', label: 'Đang hiệu lực (Chờ đến)', dotColor: 'bg-emerald-500' },
                { id: 'INSIDE', label: 'Đã tiếp đón (Đã check-in)', dotColor: 'bg-blue-500' },
                { id: 'CHECKED_OUT', label: 'Đã check-out (Đã rời đi)', dotColor: 'bg-amber-500' },
                { id: 'CANCELLED', label: 'Đã hủy', dotColor: 'bg-rose-500' },
                { id: 'EXPIRED', label: 'Quá hạn', dotColor: 'bg-slate-400' },
              ];
              const selectedOption = statusOptions.find((opt) => opt.id === statusFilter) || statusOptions[0];

              return (
                <>
                  <button
                    type="button"
                    onClick={() => {
                      setIsStatusDropdownOpen((prev) => !prev);
                      setIsPerPageDropdownOpen(false);
                    }}
                    className={`w-full px-3 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center justify-between cursor-pointer ${
                      isStatusDropdownOpen
                        ? 'border-indigo-500 ring-2 ring-indigo-200 bg-white text-indigo-900 shadow-xs'
                        : 'border-slate-200 hover:border-slate-300 bg-white text-slate-700'
                    }`}
                  >
                    <div className="flex items-center gap-2 truncate">
                      {selectedOption.isAll ? (
                        <Filter className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                      ) : (
                        <span className={`w-2 h-2 rounded-full ${selectedOption.dotColor} shrink-0`} />
                      )}
                      <span className="truncate">{selectedOption.label}</span>
                    </div>
                    {isStatusDropdownOpen ? (
                      <ChevronUp className="w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5" />
                    ) : (
                      <ChevronDown className="w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5" />
                    )}
                  </button>

                  {/* Dropdown Menu Popup (Chuẩn 100% Ảnh 2) */}
                  {isStatusDropdownOpen && (
                    <div className="absolute z-50 left-0 right-0 mt-1.5 bg-white rounded-2xl shadow-2xl border border-slate-100 p-1.5 space-y-1 min-w-[220px]">
                      {statusOptions.map((opt) => {
                        const isSelected = statusFilter === opt.id;
                        return (
                          <div
                            key={opt.id}
                            onClick={() => {
                              handleTabChange(opt.id);
                              setIsStatusDropdownOpen(false);
                            }}
                            className={`rounded-xl px-3 py-2 text-xs transition-colors cursor-pointer flex items-center justify-between ${
                              isSelected
                                ? 'bg-indigo-600 text-white font-bold shadow-xs'
                                : 'hover:bg-slate-50 text-slate-700 font-medium'
                            }`}
                          >
                            <div className="flex items-center gap-2.5 truncate">
                              {opt.isAll ? (
                                <Filter
                                  className={`w-3.5 h-3.5 shrink-0 ${isSelected ? 'text-white' : 'text-slate-400'}`}
                                />
                              ) : (
                                <span
                                  className={`w-2 h-2 rounded-full shrink-0 ${
                                    isSelected ? 'bg-white ring-2 ring-white/30' : opt.dotColor
                                  }`}
                                />
                              )}
                              <span className="truncate">{opt.label}</span>
                            </div>
                            {isSelected && <Check className="w-4 h-4 text-white shrink-0 ml-2" />}
                          </div>
                        );
                      })}
                    </div>
                  )}
                </>
              );
            })()}
          </div>

          {/* Số dòng trên 1 trang dropdown tùy chỉnh */}
          <div className="relative" ref={perPageDropdownRef}>
            <button
              type="button"
              onClick={() => {
                setIsPerPageDropdownOpen((prev) => !prev);
                setIsStatusDropdownOpen(false);
              }}
              className={`w-full px-3 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center justify-between cursor-pointer ${
                isPerPageDropdownOpen
                  ? 'border-indigo-500 ring-2 ring-indigo-200 bg-white text-indigo-900 shadow-xs'
                  : 'border-slate-200 hover:border-slate-300 bg-white text-slate-700'
              }`}
            >
              <span>{perPage} dòng / trang</span>
              {isPerPageDropdownOpen ? (
                <ChevronUp className="w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5" />
              ) : (
                <ChevronDown className="w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5" />
              )}
            </button>

            {isPerPageDropdownOpen && (
              <div className="absolute z-50 left-0 right-0 mt-1.5 bg-white rounded-2xl shadow-2xl border border-slate-100 p-1.5 space-y-1 min-w-[140px]">
                {[10, 15, 25, 50].map((limit) => {
                  const isSelected = perPage === limit;
                  return (
                    <div
                      key={limit}
                      onClick={() => {
                        handlePerPageChange(limit);
                        setIsPerPageDropdownOpen(false);
                      }}
                      className={`rounded-xl px-3 py-2 text-xs transition-colors cursor-pointer flex items-center justify-between ${
                        isSelected
                          ? 'bg-indigo-600 text-white font-bold shadow-xs'
                          : 'hover:bg-slate-50 text-slate-700 font-medium'
                      }`}
                    >
                      <span>{limit} dòng / trang</span>
                      {isSelected && <Check className="w-4 h-4 text-white shrink-0 ml-2" />}
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          {/* Nút Đặt lại bộ lọc */}
          <div>
            <button
              type="button"
              onClick={() => {
                setSearchQuery('');
                handleTabChange('ALL');
              }}
              className="w-full flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition cursor-pointer"
            >
              <RefreshCw className="w-3.5 h-3.5 text-slate-500" />
              <span>Đặt lại bộ lọc</span>
            </button>
          </div>
        </div>

        {/* Clear filters row if active */}
        {(searchQuery || statusFilter !== 'ALL') && (
          <div className="flex items-center justify-between pt-2 border-t border-slate-100 text-xs text-slate-500">
            <span>Đang áp dụng bộ lọc tùy chỉnh</span>
            <button
              type="button"
              onClick={() => {
                setSearchQuery('');
                handleTabChange('ALL');
              }}
              className="text-indigo-600 hover:text-indigo-700 font-semibold flex items-center gap-1 cursor-pointer"
            >
              <X className="w-3.5 h-3.5" />
              <span>Xóa bộ lọc</span>
            </button>
          </div>
        )}
      </div>

      {/* 5. RFID / Visitor Table (Đồng bộ 100% chuẩn RFID Management) */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden relative">
        {isFetching && (
          <div className="absolute top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-indigo-500 via-sky-500 to-indigo-500 animate-pulse z-10" />
        )}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider font-mono">
                <th className="py-3 px-4">Khách Viếng Thăm</th>
                <th className="py-3 px-4">Căn Hộ & Chủ Hộ</th>
                <th className="py-3 px-4">Thời Gian Ra Vào</th>
                <th className="py-3 px-4">Mã QR Pass</th>
                <th className="py-3 px-4 text-center">Trạng Thái</th>
                <th className="py-3 px-4 text-right">Thao Tác Tại Quầy</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {(isLoading || isFetching) && visitors.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Loader2 className="w-6 h-6 animate-spin text-indigo-600" />
                      <span className="font-medium text-slate-600">Đang tải...</span>
                    </div>
                  </td>
                </tr>
              ) : visitors.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Loader2 className="w-6 h-6 animate-spin text-indigo-600" />
                      <span className="font-medium text-slate-600">Đang tải...</span>
                    </div>
                  </td>
                </tr>
              ) : (
                visitors.map((item) => {
                  const isExpected = item.status === 'EXPECTED';
                  const isInside = item.status === 'INSIDE';

                  return (
                    <tr key={item.id} className="hover:bg-slate-50/70 transition">
                      {/* Cột 1: Thông tin khách */}
                      <td className="py-3 px-4">
                        <div className="font-semibold text-slate-900 flex items-center gap-1.5">
                          <User className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                          <span className="font-bold">{item.visitor_name}</span>
                        </div>
                        <div className="text-[11px] text-slate-500 mt-0.5 font-mono flex items-center gap-1">
                          <Phone className="w-3 h-3 text-slate-400" />
                          <span>{item.visitor_phone}</span>
                          {item.visitor_count > 1 && (
                            <span className="bg-slate-100 text-slate-600 px-1.5 py-0.2 rounded font-medium text-[10px]">
                              +{item.visitor_count - 1} người
                            </span>
                          )}
                        </div>
                        {item.vehicle_license_plate && (
                          <div className="text-[11px] text-slate-400 mt-0.5 font-mono flex items-center gap-1">
                            <Car className="w-3 h-3 text-slate-400" />
                            <span>{item.vehicle_license_plate}</span>
                          </div>
                        )}
                      </td>

                      {/* Cột 2: Căn hộ & Chủ hộ */}
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-1.5 font-semibold text-slate-800">
                          <Building className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                          <span>Căn {item.apartment.number}</span>
                          {item.apartment.block_name && (
                            <span className="text-slate-400 text-xs font-normal">({item.apartment.block_name})</span>
                          )}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-0.5">
                          Chủ hộ: <span className="font-medium text-slate-700">{item.host.name}</span>
                        </div>
                        {item.visit_purpose && (
                          <div className="text-[11px] text-slate-400 italic mt-0.5 truncate max-w-[180px]">
                            {item.visit_purpose}
                          </div>
                        )}
                      </td>

                      {/* Cột 3: Thời gian */}
                      <td className="py-3 px-4 text-slate-600">
                        <div>
                          Dự kiến: <span className="font-medium text-slate-700">{formatDateTime(item.expected_arrival_time)}</span>
                        </div>
                        {item.checkin_log?.checkin_time && (
                          <div className="text-[11px] text-sky-700 font-semibold mt-0.5">
                            Vào: {formatDateTime(item.checkin_log.checkin_time)}
                          </div>
                        )}
                        {item.checkin_log?.checkout_time && (
                          <div className="text-[11px] text-emerald-700 font-semibold mt-0.5">
                            Ra: {formatDateTime(item.checkin_log.checkout_time)}
                          </div>
                        )}
                      </td>

                      {/* Cột 4: Mã QR Access Pass */}
                      <td className="py-3 px-4 font-mono">
                        <div className="font-extrabold text-slate-900 tracking-wider text-xs bg-slate-100 px-2 py-0.5 rounded border border-slate-300 inline-flex items-center gap-1.5">
                          <span>{item.qr_access_pass_code}</span>
                          <button
                            type="button"
                            onClick={() => handleCopyCode(item.qr_access_pass_code)}
                            className="text-slate-400 hover:text-slate-600 cursor-pointer"
                            title="Sao chép mã QR Pass"
                          >
                            {copiedCode === item.qr_access_pass_code ? (
                              <Check className="w-3 h-3 text-emerald-600" />
                            ) : (
                              <Copy className="w-3 h-3" />
                            )}
                          </button>
                        </div>
                        <div className="text-[11px] text-slate-400 font-mono mt-0.5">
                          {item.registration_code}
                        </div>
                      </td>

                      {/* Cột 5: Trạng thái */}
                      <td className="py-3 px-4 text-center">
                        {getStatusBadge(item.status, item.status_label)}
                      </td>

                      {/* Cột 6: Thao tác tại quầy */}
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          {/* Nút Check-in: Chỉ hiển thị khi trạng thái là Chờ check-in */}
                          {isExpected && (
                            <button
                              type="button"
                              onClick={() => handleOpenCheckinModal(item)}
                              className="flex items-center gap-1 px-3 py-1.5 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition shadow-sm cursor-pointer"
                              title="Thực hiện check-in cho khách vào tòa nhà"
                            >
                              <UserCheck className="w-3.5 h-3.5" />
                              <span>Check-in</span>
                            </button>
                          )}

                          {/* Nút Check-out: Chỉ hiển thị khi khách đang ở trong tòa nhà */}
                          {isInside && (
                            <button
                              type="button"
                              onClick={() => handleOpenCheckoutModal(item)}
                              className="flex items-center gap-1 px-3 py-1.5 text-xs font-bold text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition shadow-sm cursor-pointer"
                              title="Thực hiện check-out khi khách rời tòa nhà"
                            >
                              <LogOut className="w-3.5 h-3.5" />
                              <span>Check-out</span>
                            </button>
                          )}

                          {/* Nút Xem chi tiết */}
                          <button
                            type="button"
                            onClick={() => {
                              setSelectedVisitor(item);
                              setIsDetailModalOpen(true);
                            }}
                            className="p-1.5 text-slate-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition cursor-pointer"
                            title="Xem chi tiết hồ sơ khách"
                          >
                            <Eye className="w-4 h-4" />
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

        {/* 6. Phân trang (Đồng bộ 100% chuẩn RFID Management) */}
        <div className="flex items-center justify-between px-4 py-3 bg-slate-50 border-t border-slate-200 text-xs text-slate-500">
          <div>
            Hiển thị trang <span className="font-bold text-slate-700">{currentPage}</span> / {totalPages} (Tổng{' '}
            <span className="font-bold text-slate-700">{totalCount}</span> lượt khách)
          </div>
          <div className="flex items-center gap-1">
            <button
              type="button"
              onClick={() => handlePageChange(currentPage - 1)}
              disabled={currentPage <= 1 || isLoading}
              className="p-1.5 border border-slate-200 rounded-lg bg-white disabled:opacity-40 hover:bg-slate-100 transition cursor-pointer"
              title="Trang trước"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <span className="px-2 font-semibold text-slate-700">
              {currentPage} / {totalPages}
            </span>
            <button
              type="button"
              onClick={() => handlePageChange(currentPage + 1)}
              disabled={currentPage >= totalPages || isLoading}
              className="p-1.5 border border-slate-200 rounded-lg bg-white disabled:opacity-40 hover:bg-slate-100 transition cursor-pointer"
              title="Trang sau"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      {/* MODAL 1: CHECK-IN XÁC NHẬN (Chuẩn form RFID Management) */}
      {isCheckinModalOpen && checkinTarget && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-lg w-full p-6 space-y-4 my-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                  <UserCheck className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="text-base font-extrabold text-slate-900">Xác Nhận Check-in Khách</h3>
                  <p className="text-xs text-slate-500">Ghi nhận giờ vào bằng máy chủ hệ thống</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsCheckinModalOpen(false)}
                className="p-1 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Thông tin đối chiếu */}
            <div className="bg-slate-50 p-3.5 rounded-xl space-y-1.5 text-xs border border-slate-200/80">
              <div className="flex justify-between">
                <span className="text-slate-500">Khách viếng thăm:</span>
                <span className="font-bold text-slate-900">{checkinTarget.visitor_name}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Số điện thoại:</span>
                <span className="font-mono text-slate-800">{checkinTarget.visitor_phone}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Căn hộ đón tiếp:</span>
                <span className="font-semibold text-slate-800">Căn {checkinTarget.apartment.number}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Chủ hộ bảo lãnh:</span>
                <span className="text-slate-800">{checkinTarget.host.name}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Mã QR Pass:</span>
                <span className="font-mono font-bold text-indigo-700">{checkinTarget.qr_access_pass_code}</span>
              </div>
            </div>

            {/* Form trường bổ sung tại quầy */}
            <div className="space-y-3">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Số CCCD / CMND (Đối chiếu tại quầy)
                </label>
                <input
                  type="text"
                  value={checkinFormData.national_id_number}
                  onChange={(e) => setCheckinFormData({ ...checkinFormData, national_id_number: e.target.value })}
                  placeholder="Nhập số CCCD xác minh..."
                  className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono text-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Mã thẻ tạm RFID / Thẻ thang máy cấp cho khách (Tùy chọn)
                </label>
                <input
                  type="text"
                  value={checkinFormData.temp_card_id}
                  onChange={(e) => setCheckinFormData({ ...checkinFormData, temp_card_id: e.target.value })}
                  placeholder="VD: RFID-TEMP-04..."
                  className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono text-slate-800"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Ghi chú của Lễ tân / An ninh
                </label>
                <textarea
                  value={checkinFormData.notes}
                  onChange={(e) => setCheckinFormData({ ...checkinFormData, notes: e.target.value })}
                  placeholder="Ghi chú về đồ mang theo, hướng dẫn..."
                  rows={2}
                  className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white text-slate-800"
                />
              </div>
            </div>

            {/* Nút hành động */}
            <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
              <button
                type="button"
                onClick={() => setIsCheckinModalOpen(false)}
                disabled={isSubmittingCheckin}
                className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleConfirmCheckin}
                disabled={isSubmittingCheckin}
                className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition shadow-sm cursor-pointer disabled:opacity-50"
              >
                {isSubmittingCheckin ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <UserCheck className="w-3.5 h-3.5" />
                )}
                <span>Xác nhận Check-in ngay</span>
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 2: CHECK-OUT XÁC NHẬN (Chuẩn form RFID Management) */}
      {isCheckoutModalOpen && checkoutTarget && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-lg w-full p-6 space-y-4 my-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                  <LogOut className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="text-base font-extrabold text-slate-900">Xác Nhận Check-out Khách</h3>
                  <p className="text-xs text-slate-500">Ghi nhận giờ rời tòa nhà bằng máy chủ</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsCheckoutModalOpen(false)}
                className="p-1 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="bg-slate-50 p-3.5 rounded-xl space-y-1.5 text-xs border border-slate-200/80">
              <div className="flex justify-between">
                <span className="text-slate-500">Khách rời đi:</span>
                <span className="font-bold text-slate-900">{checkoutTarget.visitor_name}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Thời gian Check-in:</span>
                <span className="font-semibold text-slate-800">
                  {formatDateTime(checkoutTarget.checkin_log?.checkin_time)}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Căn hộ đã thăm:</span>
                <span className="font-semibold text-slate-800">Căn {checkoutTarget.apartment.number}</span>
              </div>
              {checkoutTarget.checkin_log?.temp_card_id && (
                <div className="flex justify-between text-amber-800 bg-amber-50 p-2 rounded-lg font-medium border border-amber-200">
                  <span>Lưu ý thu hồi thẻ tạm:</span>
                  <span className="font-mono font-bold">{checkoutTarget.checkin_log.temp_card_id}</span>
                </div>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Ghi chú khi Check-out (Tùy chọn)
              </label>
              <textarea
                value={checkoutNotes}
                onChange={(e) => setCheckoutNotes(e.target.value)}
                placeholder="Đã thu hồi thẻ tạm, không có sự cố phát sinh..."
                rows={2}
                className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white text-slate-800"
              />
            </div>

            <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
              <button
                type="button"
                onClick={() => setIsCheckoutModalOpen(false)}
                disabled={isSubmittingCheckout}
                className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleConfirmCheckout}
                disabled={isSubmittingCheckout}
                className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition shadow-sm cursor-pointer disabled:opacity-50"
              >
                {isSubmittingCheckout ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <LogOut className="w-3.5 h-3.5" />
                )}
                <span>Xác nhận Check-out ngay</span>
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 3: KẾT QUẢ QUÉT MÃ QR / TRA CỨU NHANH (Chuẩn form RFID Management) */}
      {isLookupModalOpen && lookupResult && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-lg w-full p-6 space-y-4 my-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                  <QrCode className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="text-base font-extrabold text-slate-900">Kết Quả Quét Mã QR Pass</h3>
                  <p className="text-xs text-slate-500">Đối chiếu thông tin tức thời</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsLookupModalOpen(false)}
                className="p-1 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Thẻ thông tin khách */}
            <div className="bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-3">
              <div className="flex items-start justify-between">
                <div>
                  <div className="text-[11px] text-slate-500 font-mono">
                    {lookupResult.registration.registration_code}
                  </div>
                  <div className="text-base font-black text-slate-900 mt-0.5">
                    {lookupResult.registration.visitor_name}
                  </div>
                  <div className="text-xs text-slate-600 font-mono">
                    {lookupResult.registration.visitor_phone}
                  </div>
                </div>
                <div>
                  {getStatusBadge(lookupResult.status, lookupResult.status_label)}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-2 pt-2 border-t border-slate-200 text-xs">
                <div>
                  <span className="text-slate-400 text-[11px] block">Căn hộ đến thăm:</span>
                  <span className="font-bold text-slate-800">Căn {lookupResult.registration.apartment.number}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Chủ hộ đón tiếp:</span>
                  <span className="font-semibold text-slate-800">{lookupResult.registration.host.name}</span>
                </div>
              </div>
            </div>

            {/* Nút thao tác tương ứng */}
            <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
              <button
                type="button"
                onClick={() => setIsLookupModalOpen(false)}
                className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition cursor-pointer"
              >
                Đóng
              </button>

              {lookupResult.can_checkin && (
                <button
                  type="button"
                  onClick={() => {
                    setIsLookupModalOpen(false);
                    setCheckinTarget({
                      ...lookupResult.registration,
                      registration_id: lookupResult.registration.id,
                      status: lookupResult.status as any,
                      status_label: lookupResult.status_label,
                      status_color: lookupResult.status_color as any,
                    });
                    setCheckinFormData({
                      national_id_number: lookupResult.registration.visitor_national_id || '',
                      temp_card_id: '',
                      notes: '',
                    });
                    setIsCheckinModalOpen(true);
                  }}
                  className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition shadow-sm cursor-pointer"
                >
                  <UserCheck className="w-3.5 h-3.5" />
                  <span>Tiến hành Check-in</span>
                </button>
              )}

              {lookupResult.can_checkout && (
                <button
                  type="button"
                  onClick={() => {
                    setIsLookupModalOpen(false);
                    setCheckoutTarget({
                      ...lookupResult.registration,
                      registration_id: lookupResult.registration.id,
                      status: lookupResult.status as any,
                      status_label: lookupResult.status_label,
                      status_color: lookupResult.status_color as any,
                      checkin_log: lookupResult.latest_checkin_log,
                    });
                    setCheckoutNotes('');
                    setIsCheckoutModalOpen(true);
                  }}
                  className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition shadow-sm cursor-pointer"
                >
                  <LogOut className="w-3.5 h-3.5" />
                  <span>Tiến hành Check-out</span>
                </button>
              )}
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 4: CHI TIẾT HỒ SƠ LƯỢT KHÁCH (Chuẩn form RFID Management) */}
      {isDetailModalOpen && selectedVisitor && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl border border-slate-200 shadow-2xl max-w-lg w-full p-6 space-y-4 my-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div>
                <h3 className="text-base font-extrabold text-slate-900">Chi Tiết Khách Viếng Thăm</h3>
                <p className="text-xs text-slate-500 font-mono">{selectedVisitor.registration_code}</p>
              </div>
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="p-1 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div className="grid grid-cols-2 gap-3 bg-slate-50 p-3.5 rounded-xl border border-slate-200/80">
                <div>
                  <span className="text-slate-400 text-[11px] block">Họ tên khách:</span>
                  <span className="text-slate-900 font-bold text-sm">{selectedVisitor.visitor_name}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Số điện thoại:</span>
                  <span className="font-mono text-slate-800 font-semibold">{selectedVisitor.visitor_phone}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Số CCCD / CMND:</span>
                  <span className="font-mono text-slate-800">{selectedVisitor.visitor_national_id || '—'}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Số người đi cùng:</span>
                  <span className="text-slate-800 font-medium">{selectedVisitor.visitor_count} người</span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3 bg-slate-50 p-3.5 rounded-xl border border-slate-200/80">
                <div>
                  <span className="text-slate-400 text-[11px] block">Căn hộ:</span>
                  <span className="font-bold text-slate-800">Căn {selectedVisitor.apartment.number}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Cư dân bảo lãnh:</span>
                  <span className="text-slate-800 font-medium">{selectedVisitor.host.name}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Mục đích thăm:</span>
                  <span className="text-slate-700">{selectedVisitor.visit_purpose || '—'}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Biển số xe:</span>
                  <span className="font-mono text-slate-700">{selectedVisitor.vehicle_license_plate || '—'}</span>
                </div>
              </div>

              {/* Lịch sử check-in / check-out */}
              <div className="border border-slate-200 p-3.5 rounded-xl space-y-2">
                <div className="font-bold text-slate-800 text-xs">Nhật Ký Ra Vào</div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Dự kiến đến:</span>
                  <span className="font-medium text-slate-700">{formatDateTime(selectedVisitor.expected_arrival_time)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Check-in thực tế:</span>
                  <span className="font-bold text-sky-700">{formatDateTime(selectedVisitor.checkin_log?.checkin_time)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Check-out thực tế:</span>
                  <span className="font-bold text-emerald-700">{formatDateTime(selectedVisitor.checkin_log?.checkout_time)}</span>
                </div>
                {selectedVisitor.checkin_log?.temp_card_id && (
                  <div className="flex justify-between">
                    <span className="text-slate-500">Mã thẻ tạm RFID:</span>
                    <span className="font-mono font-bold text-indigo-700">{selectedVisitor.checkin_log.temp_card_id}</span>
                  </div>
                )}
                {selectedVisitor.checkin_log?.notes && (
                  <div className="text-[11px] text-slate-500 italic pt-1 border-t border-slate-100">
                    Ghi chú: {selectedVisitor.checkin_log.notes}
                  </div>
                )}
              </div>
            </div>

            <div className="flex justify-end pt-3 border-t border-slate-100">
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition cursor-pointer"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}
    </div>
  );
};

export default ReceptionVisitorCheckinPanel;
