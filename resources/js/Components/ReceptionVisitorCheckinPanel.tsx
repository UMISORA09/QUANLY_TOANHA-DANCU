import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { createPortal } from 'react-dom';
import {
  Users,
  UserCheck,
  UserX,
  Clock,
  Search,
  RefreshCw,
  QrCode,
  Calendar,
  Phone,
  Car,
  Home,
  CheckCircle2,
  XCircle,
  AlertCircle,
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
  LogOut,
  ArrowRight,
  CreditCard,
  Hash,
  User,
  Sparkles,
  Zap,
} from 'lucide-react';
import {
  api,
  ReceptionVisitorItem,
  ReceptionVisitorListResponse,
  ReceptionVisitorLookupResponse,
  ApiError,
} from '../Services/api';
import { useRealtimeSync, RealtimeEventPayload } from '../Hooks/useRealtimeSync';

interface ReceptionVisitorCheckinPanelProps {
  embedded?: boolean;
}

// Persistent in-memory & localStorage cache across unmounts/tab switching (0ms instant display - Hiện liền)
const VISITOR_CACHE_STORAGE_KEY = 'smartcassavas_reception_visitors_cache_v1';

const globalVisitorTabCache = new Map<string, {
  data: ReceptionVisitorItem[];
  pagination: { current_page: number; total_pages: number; total_items: number };
  kpis: { expected_today: number; currently_inside: number; checked_out_today: number; total_registrations: number };
}>();

const getInitialVisitorCache = () => {
  const memCached = globalVisitorTabCache.get('ALL__1');
  if (memCached && memCached.data && memCached.data.length > 0) {
    return memCached;
  }
  try {
    const raw = localStorage.getItem(VISITOR_CACHE_STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && Array.isArray(parsed.data) && parsed.data.length > 0) {
        return parsed;
      }
    }
  } catch {
    // ignore
  }
  return null;
};

export const ReceptionVisitorCheckinPanel: React.FC<ReceptionVisitorCheckinPanelProps> = ({
  embedded = false,
}) => {
  const initialCache = useMemo(() => getInitialVisitorCache(), []);

  // 1. Dữ liệu chính & KPIs (Hiện liền tức thời 0ms từ cache)
  const [visitors, setVisitors] = useState<ReceptionVisitorItem[]>(() => initialCache?.data ?? []);
  const [kpis, setKpis] = useState(() => initialCache?.kpis ?? {
    expected_today: 0,
    currently_inside: 0,
    checked_out_today: 0,
    total_registrations: 0,
  });
  const [isLoading, setIsLoading] = useState<boolean>(() => !initialCache);
  const [isFetching, setIsFetching] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // Bộ nhớ đệm Client (In-memory SWR Cache) cho các tab và trang (Chuyển tab 0ms)
  const tabCacheRef = useRef(globalVisitorTabCache);

  // 2. Bộ lọc & Phân trang
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [debouncedSearch, setDebouncedSearch] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [perPage] = useState<number>(15);
  const [totalPages, setTotalPages] = useState<number>(() => initialCache?.pagination?.total_pages ?? 1);
  const [totalCount, setTotalCount] = useState<number>(() => initialCache?.pagination?.total_items ?? 0);

  // 3. Tra cứu nhanh / Quét mã QR
  const [quickScanCode, setQuickScanCode] = useState<string>('');
  const [isScanning, setIsScanning] = useState<boolean>(false);
  const [lookupResult, setLookupResult] = useState<ReceptionVisitorLookupResponse['data'] | null>(null);
  const [isLookupModalOpen, setIsLookupModalOpen] = useState<boolean>(false);

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

  // AbortController ref và requestId để hủy request cũ an toàn, không bị tắt nhầm trạng thái đang tải
  const searchAbortControllerRef = useRef<AbortController | null>(null);
  const requestIdRef = useRef<number>(0);

  const statusFilterRef = useRef<string>(statusFilter);
  const currentPageRef = useRef<number>(currentPage);
  const debouncedSearchRef = useRef<string>(debouncedSearch);

  useEffect(() => {
    statusFilterRef.current = statusFilter;
  }, [statusFilter]);

  useEffect(() => {
    currentPageRef.current = currentPage;
  }, [currentPage]);

  useEffect(() => {
    debouncedSearchRef.current = debouncedSearch;
  }, [debouncedSearch]);

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

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  // Sao chép mã vào clipboard
  const handleCopyCode = (code: string) => {
    navigator.clipboard.writeText(code);
    setCopiedCode(code);
    showToast(`Đã sao chép mã: ${code}`);
    setTimeout(() => setCopiedCode(null), 2000);
  };

  // Fetch danh sách khách từ Server (SWR pattern)
  const fetchVisitors = useCallback(
    async (
      targetStatus: string = statusFilterRef.current,
      targetPage: number = currentPageRef.current,
      targetSearch: string = debouncedSearchRef.current,
      isSilent: boolean = false
    ) => {
      const currentRequestId = ++requestIdRef.current;

      if (searchAbortControllerRef.current) {
        searchAbortControllerRef.current.abort();
      }
      const abortController = new AbortController();
      searchAbortControllerRef.current = abortController;

      const cacheKey = `${targetStatus}_${targetSearch}_${targetPage}`;
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
            limit: perPage,
          },
          { signal: abortController.signal }
        );

        // Bỏ qua nếu đã có request mới hơn được gửi đi
        if (currentRequestId !== requestIdRef.current) {
          return;
        }

        if (res && res.success) {
          const fetchedData = res.data || [];
          setVisitors(fetchedData);

          if (res.kpis) {
            setKpis(res.kpis);
          }
          if (res.pagination) {
            setCurrentPage(res.pagination.current_page);
            currentPageRef.current = res.pagination.current_page;
            setTotalPages(res.pagination.total_pages);
            setTotalCount(res.pagination.total_items);
          }

          const cacheItem = {
            data: fetchedData,
            pagination: {
              current_page: res.pagination?.current_page || targetPage,
              total_pages: res.pagination?.total_pages || 1,
              total_items: res.pagination?.total_items || fetchedData.length,
            },
            kpis: res.kpis || kpis,
          };

          // Lưu vào cache để chuyển tab/trang 0ms
          tabCacheRef.current.set(cacheKey, cacheItem);

          // Nếu là tab ALL mặc định, lưu localStorage để lần sau vào lại là hiện liền tức thì (0ms)
          if (targetStatus === 'ALL' && !targetSearch && targetPage === 1) {
            try {
              localStorage.setItem(VISITOR_CACHE_STORAGE_KEY, JSON.stringify(cacheItem));
            } catch {
              // ignore
            }
          }
        }
      } catch (err: any) {
        if (err?.name === 'AbortError' || currentRequestId !== requestIdRef.current) {
          return;
        }
        console.error('Lỗi khi tải danh sách khách:', err);
        setErrorMsg(err.message || 'Không thể tải danh sách khách viếng thăm.');
      } finally {
        // Chỉ tắt loading nếu đây chính là request mới nhất đang chạy
        if (currentRequestId === requestIdRef.current) {
          setIsLoading(false);
          setIsFetching(false);
        }
      }
    },
    [perPage, kpis]
  );

  // Chuyển tab: Chuyển ngay và nạp dữ liệu chuẩn xác cho từng tab
  const handleTabChange = useCallback(
    (newStatus: string) => {
      if (newStatus === statusFilterRef.current) return;

      setStatusFilter(newStatus);
      statusFilterRef.current = newStatus;
      setCurrentPage(1);
      currentPageRef.current = 1;

      const cacheKey = `${newStatus}_${debouncedSearchRef.current}_1`;
      const cached = tabCacheRef.current.get(cacheKey);

      if (cached) {
        // 1. Phục vụ ngay lập tức từ bộ nhớ đệm (0ms)
        setVisitors(cached.data);
        setTotalPages(cached.pagination.total_pages);
        setTotalCount(cached.pagination.total_items);
        setIsLoading(false);
        // Đồng bộ ngầm trong nền
        fetchVisitors(newStatus, 1, debouncedSearchRef.current, true);
      } else {
        // 2. Chưa có trong cache -> hiện "Đang tải..." ngay lập tức
        setVisitors([]);
        setIsLoading(true);
        fetchVisitors(newStatus, 1, debouncedSearchRef.current, false);
      }
    },
    [fetchVisitors]
  );

  // Chuyển trang siêu tốc tận dụng cache
  const handlePageChange = useCallback(
    (newPage: number) => {
      setCurrentPage(newPage);
      currentPageRef.current = newPage;
      const cacheKey = `${statusFilterRef.current}_${debouncedSearchRef.current}_${newPage}`;
      const cached = tabCacheRef.current.get(cacheKey);

      if (cached) {
        setVisitors(cached.data);
        setTotalPages(cached.pagination.total_pages);
        setTotalCount(cached.pagination.total_items);
        setIsLoading(false);
        fetchVisitors(statusFilterRef.current, newPage, debouncedSearchRef.current, true);
      } else {
        setVisitors([]);
        setIsLoading(true);
        fetchVisitors(statusFilterRef.current, newPage, debouncedSearchRef.current, false);
      }
    },
    [fetchVisitors]
  );

  const isInitialDebounceMounted = useRef(false);

  // Tải dữ liệu ban đầu (nếu đã có cache thì tải ngầm silent để hiện liền tức thời)
  useEffect(() => {
    fetchVisitors('ALL', 1, '', !!initialCache);
  }, []);

  // Khi từ khóa tìm kiếm thay đổi (sau 350ms debounce, không kích hoạt lần render đầu tiên)
  useEffect(() => {
    if (!isInitialDebounceMounted.current) {
      isInitialDebounceMounted.current = true;
      return;
    }
    fetchVisitors(statusFilterRef.current, 1, debouncedSearch, false);
  }, [debouncedSearch]);

  // Realtime Live Sync: Khi có bất kỳ mutation nào (A check-in / B check-out)
  const handleRealtimeEvent = useCallback(
    (event: RealtimeEventPayload) => {
      if (event.module === 'visitors' || event.entity === 'visitor_checkin' || event.entity === 'visitor_registration') {
        tabCacheRef.current.clear();
        fetchVisitors(statusFilterRef.current, currentPageRef.current, debouncedSearchRef.current, true);

        if (event.action === 'CHECKED_IN') {
          showToast(`⚡ Realtime: Khách ${event.visitor_name || ''} vừa check-in vào tòa nhà!`);
        } else if (event.action === 'CHECKED_OUT') {
          showToast(`⚡ Realtime: Khách ${event.visitor_name || ''} vừa check-out khỏi tòa nhà!`);
        }
      }
    },
    [fetchVisitors]
  );

  useRealtimeSync({
    channel: 'quoc-tin.visitors',
    onEvent: handleRealtimeEvent,
    onReconnect: () => {
      tabCacheRef.current.clear();
      fetchVisitors(statusFilter, currentPage, debouncedSearch, true);
    },
  });

  // Xử lý Tra cứu nhanh / Quét mã QR
  const handleQuickLookup = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    const code = quickScanCode.trim();
    if (!code) {
      showToast('Vui lòng nhập hoặc quét mã QR Pass Code / Mã đăng ký');
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
      showToast(err.message || `Không tìm thấy lượt khách với mã: ${code}`);
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
        showToast(res.message || `Check-in thành công cho khách ${checkinTarget.visitor_name}!`);
        setIsCheckinModalOpen(false);
        setCheckinTarget(null);
        if (isLookupModalOpen) setIsLookupModalOpen(false);
        tabCacheRef.current.clear();
        fetchVisitors();
      }
    } catch (err: any) {
      if (err.status === 409) {
        showToast(`⚠️ Xung đột: ${err.message}`);
      } else {
        showToast(err.message || 'Lỗi khi thực hiện check-in.');
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
        showToast(res.message || `Check-out thành công cho khách ${checkoutTarget.visitor_name}!`);
        setIsCheckoutModalOpen(false);
        setCheckoutTarget(null);
        if (isLookupModalOpen) setIsLookupModalOpen(false);
        tabCacheRef.current.clear();
        fetchVisitors();
      }
    } catch (err: any) {
      if (err.status === 409) {
        showToast(`⚠️ Xung đột: ${err.message}`);
      } else {
        showToast(err.message || 'Lỗi khi thực hiện check-out.');
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

  return (
    <div className="w-full space-y-6">
      {/* Toast Notification */}
      {toastMessage && typeof document !== 'undefined' && createPortal(
        <div className="fixed bottom-6 right-6 z-[9999] flex items-center gap-3 bg-neutral-900/95 text-white px-5 py-3.5 rounded-2xl shadow-2xl border border-white/20 backdrop-blur-xl animate-in fade-in slide-in-from-bottom-5 duration-300">
          <Sparkles className="w-4 h-4 text-sky-400 shrink-0 animate-spin" />
          <span className="text-xs sm:text-sm font-medium">{toastMessage}</span>
          <button
            onClick={() => setToastMessage(null)}
            className="ml-2 text-slate-400 hover:text-white p-1 cursor-pointer"
          >
            <X className="w-4 h-4" />
          </button>
        </div>,
        document.body
      )}

      {/* Header & Title */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold text-sky-600 uppercase tracking-wider font-mono">
            <ShieldCheck className="w-4 h-4" />
            <span>CỔNG TIẾP ĐÓN LỄ TÂN & BẢO VỆ</span>
            <span>•</span>
            <span className="text-emerald-600 flex items-center gap-1">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
              REALTIME LIVE
            </span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Tra cứu & Check-in / Check-out Khách viếng thăm
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Kiểm soát khách vào/ra tòa nhà tức thời, tra cứu mã QR Pass và đồng bộ thời gian thực giữa các máy trực.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={() => {
              tabCacheRef.current.clear();
              fetchVisitors();
            }}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-slate-200/90 hover:bg-slate-50 text-slate-700 text-xs sm:text-sm font-semibold shadow-xs hover:shadow-sm transition-all cursor-pointer group"
            title="Đồng bộ lại danh sách từ server"
          >
            <RefreshCw className={`w-4 h-4 text-slate-500 group-hover:rotate-180 transition-transform duration-500 ${isFetching ? 'animate-spin text-sky-600' : ''}`} />
            <span>Làm mới</span>
          </button>
        </div>
      </div>

      {/* 4 Cards Thống kê KPIs */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* KPI 1: Chờ check-in hôm nay */}
        <div
          onClick={() => handleTabChange('EXPECTED')}
          className={`bg-white/80 backdrop-blur-xl border rounded-2xl p-5 shadow-sm hover:shadow-md transition-all cursor-pointer group relative overflow-hidden ${
            statusFilter === 'EXPECTED' ? 'border-indigo-400 ring-2 ring-indigo-200 bg-indigo-50/40' : 'border-white/80 hover:border-indigo-200'
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-xs text-slate-500 font-medium">Chờ check-in hôm nay</span>
            <div className="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center group-hover:scale-110 transition-transform">
              <Clock className="w-4 h-4" />
            </div>
          </div>
          <div className="text-3xl font-black text-neutral-950 mt-3 tracking-tight">
            {kpis.expected_today}
          </div>
          <div className="text-[11px] text-indigo-600 mt-2 font-medium flex items-center gap-1">
            <span>Khách đã khai báo trước</span>
            <ArrowRight className="w-3 h-3 opacity-0 group-hover:opacity-100 transition-opacity" />
          </div>
          <div className="absolute -right-6 -bottom-6 w-20 h-20 bg-indigo-400/10 rounded-full blur-xl pointer-events-none" />
        </div>

        {/* KPI 2: Đang ở trong tòa nhà */}
        <div
          onClick={() => handleTabChange('INSIDE')}
          className={`bg-white/80 backdrop-blur-xl border rounded-2xl p-5 shadow-sm hover:shadow-md transition-all cursor-pointer group relative overflow-hidden ${
            statusFilter === 'INSIDE' ? 'border-sky-400 ring-2 ring-sky-200 bg-sky-50/40' : 'border-white/80 hover:border-sky-200'
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-xs text-slate-500 font-medium">Đang ở trong tòa nhà</span>
            <div className="w-9 h-9 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center group-hover:scale-110 transition-transform">
              <Users className="w-4 h-4" />
            </div>
          </div>
          <div className="text-3xl font-black text-neutral-950 mt-3 tracking-tight">
            {kpis.currently_inside}
          </div>
          <div className="text-[11px] text-sky-600 mt-2 font-medium flex items-center gap-1.5">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
            <span>Cần check-out khi rời đi</span>
          </div>
          <div className="absolute -right-6 -bottom-6 w-20 h-20 bg-sky-400/10 rounded-full blur-xl pointer-events-none" />
        </div>

        {/* KPI 3: Đã check-out hôm nay */}
        <div
          onClick={() => handleTabChange('CHECKED_OUT')}
          className={`bg-white/80 backdrop-blur-xl border rounded-2xl p-5 shadow-sm hover:shadow-md transition-all cursor-pointer group relative overflow-hidden ${
            statusFilter === 'CHECKED_OUT' ? 'border-emerald-400 ring-2 ring-emerald-200 bg-emerald-50/40' : 'border-white/80 hover:border-emerald-200'
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-xs text-slate-500 font-medium">Đã check-out hôm nay</span>
            <div className="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:scale-110 transition-transform">
              <CheckCircle2 className="w-4 h-4" />
            </div>
          </div>
          <div className="text-3xl font-black text-neutral-950 mt-3 tracking-tight">
            {kpis.checked_out_today}
          </div>
          <div className="text-[11px] text-emerald-600 mt-2 font-medium">
            <span>Hoàn thành lịch thăm</span>
          </div>
          <div className="absolute -right-6 -bottom-6 w-20 h-20 bg-emerald-400/10 rounded-full blur-xl pointer-events-none" />
        </div>

        {/* KPI 4: Tổng lượt khai báo */}
        <div
          onClick={() => handleTabChange('ALL')}
          className={`bg-white/80 backdrop-blur-xl border rounded-2xl p-5 shadow-sm hover:shadow-md transition-all cursor-pointer group relative overflow-hidden ${
            statusFilter === 'ALL' ? 'border-slate-400 ring-2 ring-slate-200 bg-slate-50/40' : 'border-white/80 hover:border-slate-300'
          }`}
        >
          <div className="flex items-center justify-between">
            <span className="text-xs text-slate-500 font-medium">Tổng lượt khai báo</span>
            <div className="w-9 h-9 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center group-hover:scale-110 transition-transform">
              <Building className="w-4 h-4" />
            </div>
          </div>
          <div className="text-3xl font-black text-neutral-950 mt-3 tracking-tight">
            {kpis.total_registrations}
          </div>
          <div className="text-[11px] text-slate-500 mt-2 font-medium">
            <span>Tất cả hồ sơ cư dân tạo</span>
          </div>
          <div className="absolute -right-6 -bottom-6 w-20 h-20 bg-slate-400/10 rounded-full blur-xl pointer-events-none" />
        </div>
      </div>

      {/* Prominent Quick QR / Barcode Scanner Input */}
      <div className="bg-gradient-to-r from-sky-900 via-indigo-900 to-slate-900 rounded-3xl p-5 sm:p-6 text-white shadow-xl relative overflow-hidden">
        <div className="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-5">
          <div className="max-w-xl">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-sky-500/20 text-sky-300 text-xs font-semibold mb-2 border border-sky-400/30">
              <Zap className="w-3.5 h-3.5 text-sky-400" />
              <span>MÁY QUÉT MÃ QR & TIẾP ĐÓN NHANH TẠI QUẦY</span>
            </div>
            <h2 className="text-lg sm:text-xl font-bold tracking-tight">
              Tra cứu tức thì bằng Mã QR Pass hoặc Mã đăng ký
            </h2>
            <p className="text-xs sm:text-sm text-slate-300 mt-1">
              Hỗ trợ máy quét mã vạch USB / Barcode scanner, hoặc nhập trực tiếp mã 8 ký tự hoặc số điện thoại khách.
            </p>
          </div>

          <form
            onSubmit={handleQuickLookup}
            className="flex items-center gap-2 w-full md:w-auto min-w-[320px] sm:min-w-[420px]"
          >
            <div className="relative flex-1">
              <QrCode className="w-5 h-5 text-sky-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              <input
                type="text"
                value={quickScanCode}
                onChange={(e) => setQuickScanCode(e.target.value)}
                placeholder="Quét mã QR hoặc nhập VR-XXXX..."
                className="w-full pl-11 pr-4 py-3 bg-white/10 border border-white/20 rounded-2xl text-white placeholder-slate-400 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400 focus:bg-white/15 transition-all font-mono"
              />
            </div>
            <button
              type="submit"
              disabled={isScanning || !quickScanCode.trim()}
              className="px-5 py-3 rounded-2xl bg-sky-500 hover:bg-sky-400 disabled:opacity-50 text-white font-bold text-sm shadow-lg shadow-sky-500/30 hover:shadow-sky-500/50 transition-all flex items-center gap-2 shrink-0 cursor-pointer"
            >
              {isScanning ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Search className="w-4 h-4" />
              )}
              <span>Tra cứu</span>
            </button>
          </form>
        </div>
        <div className="absolute right-0 top-0 w-80 h-80 bg-sky-500/10 rounded-full blur-3xl pointer-events-none" />
      </div>

      {/* Search & Filter Bar */}
      <div className="bg-white/90 backdrop-blur-xl border border-white/80 rounded-2xl p-4 shadow-sm space-y-3">
        <div className="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
          {/* Search Input with Debounce */}
          <div className="relative flex-1">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Tìm kiếm theo Tên khách, SĐT, Mã khai báo, Căn hộ, Chủ hộ, Biển số xe..."
              className="w-full pl-10 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-xs sm:text-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:bg-white transition-all"
            />
            {searchQuery !== debouncedSearch && (
              <Loader2 className="w-4 h-4 animate-spin text-sky-500 absolute right-10 top-1/2 -translate-y-1/2 pointer-events-none" />
            )}
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>

          {/* Status Tabs - Hiển thị tức thời 0ms & Count badges */}
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0 scrollbar-none">
            {[
              { id: 'ALL', label: 'Tất cả', count: kpis.total_registrations },
              { id: 'EXPECTED', label: 'Chờ check-in', count: kpis.expected_today },
              { id: 'INSIDE', label: 'Đang ở trong tòa', count: kpis.currently_inside },
              { id: 'CHECKED_OUT', label: 'Đã check-out', count: kpis.checked_out_today },
              { id: 'CANCELLED', label: 'Đã hủy / Hết hạn' },
            ].map((tab) => {
              const isActive = statusFilter === tab.id;
              return (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => handleTabChange(tab.id)}
                  className={`inline-flex items-center gap-1.5 px-3 py-2 sm:px-3.5 sm:py-2 rounded-xl text-xs font-semibold whitespace-nowrap transition-all duration-150 cursor-pointer ${
                    isActive
                      ? 'bg-neutral-900 text-white shadow-sm ring-1 ring-neutral-800'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  <span>{tab.label}</span>
                  {tab.count !== undefined && (
                    <span
                      className={`text-[10px] sm:text-[11px] px-1.5 py-0.2 rounded-full font-bold tabular-nums transition-colors ${
                        isActive
                          ? 'bg-white/20 text-white'
                          : 'bg-slate-200/90 text-slate-700'
                      }`}
                    >
                      {tab.count}
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        </div>
      </div>

      {/* Bảng Dữ liệu Khách viếng thăm */}
      <div className="bg-white/90 backdrop-blur-2xl border border-white/80 rounded-3xl shadow-sm overflow-hidden relative">
        {/* Subtle accent live-sync line */}
        {isFetching && (
          <div className="absolute top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-sky-400 via-indigo-500 to-sky-400 animate-pulse z-20" />
        )}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                <th className="py-3.5 px-4 sm:px-6">Khách viếng thăm</th>
                <th className="py-3.5 px-4">Căn hộ & Chủ hộ</th>
                <th className="py-3.5 px-4">Thời gian</th>
                <th className="py-3.5 px-4">Mã QR Pass</th>
                <th className="py-3.5 px-4 text-center">Trạng thái</th>
                <th className="py-3.5 px-4 sm:px-6 text-right">Thao tác tại quầy</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-xs sm:text-sm">
              {(isLoading || isFetching) && visitors.length === 0 ? (
                // ĐANG TẢI: Hiển thị trạng thái đang tải rõ ràng khi người dùng chọn tab hoặc lọc khác
                <>
                  <tr>
                    <td colSpan={6} className="py-6 px-6 bg-sky-50/40 border-b border-sky-100/60 text-center">
                      <div className="flex flex-col sm:flex-row items-center justify-center gap-2.5 text-sky-800 text-xs font-bold">
                        <Loader2 className="w-4 h-4 animate-spin text-sky-600" />
                        <span>Đang tải danh sách khách viếng thăm... Vui lòng đợi trong giây lát</span>
                      </div>
                    </td>
                  </tr>
                  {[1, 2, 3, 4, 5].map((idx) => (
                    <tr key={`skeleton-${idx}`} className="animate-pulse">
                      <td className="py-4 px-4 sm:px-6">
                        <div className="flex items-center gap-3">
                          <div className="w-9 h-9 rounded-xl bg-slate-200 shrink-0" />
                          <div className="space-y-1.5 w-32">
                            <div className="h-3.5 bg-slate-200 rounded w-28" />
                            <div className="h-2.5 bg-slate-100 rounded w-20" />
                          </div>
                        </div>
                      </td>
                      <td className="py-4 px-4">
                        <div className="h-3.5 bg-slate-200 rounded w-24 mb-1.5" />
                        <div className="h-2.5 bg-slate-100 rounded w-16" />
                      </td>
                      <td className="py-4 px-4">
                        <div className="h-3 bg-slate-200 rounded w-28" />
                      </td>
                      <td className="py-4 px-4">
                        <div className="h-6 bg-slate-100 rounded-lg w-24" />
                      </td>
                      <td className="py-4 px-4 text-center">
                        <div className="h-6 bg-slate-100 rounded-full w-24 mx-auto" />
                      </td>
                      <td className="py-4 px-4 sm:px-6 text-right">
                        <div className="h-8 bg-slate-200 rounded-xl w-24 ml-auto" />
                      </td>
                    </tr>
                  ))}
                </>
              ) : visitors.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-16 text-center">
                    <div className="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
                      <Users className="w-6 h-6" />
                    </div>
                    <div className="text-sm font-semibold text-neutral-800">
                      Không tìm thấy khách viếng thăm phù hợp
                    </div>
                    <p className="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                      Vui lòng thử tìm kiếm với từ khóa khác, hoặc kiểm tra lại bộ lọc trạng thái.
                    </p>
                  </td>
                </tr>
              ) : (
                visitors.map((item) => {
                  const isExpected = item.status === 'EXPECTED';
                  const isInside = item.status === 'INSIDE';
                  const isCheckedOut = item.status === 'CHECKED_OUT';

                  return (
                    <tr
                      key={item.id}
                      className="hover:bg-slate-50/80 transition-colors group"
                    >
                      {/* Cột 1: Thông tin khách */}
                      <td className="py-4 px-4 sm:px-6">
                        <div className="flex items-start gap-3">
                          <div className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 font-bold text-xs ${
                            isInside
                              ? 'bg-sky-100 text-sky-700 ring-2 ring-sky-300'
                              : isCheckedOut
                              ? 'bg-slate-100 text-slate-600'
                              : 'bg-indigo-50 text-indigo-700'
                          }`}>
                            <User className="w-4 h-4" />
                          </div>
                          <div className="min-w-0">
                            <div className="font-bold text-neutral-900 group-hover:text-sky-600 transition-colors">
                              {item.visitor_name}
                            </div>
                            <div className="flex items-center gap-2 text-xs text-slate-500 mt-0.5">
                              <span className="flex items-center gap-1 font-mono">
                                <Phone className="w-3 h-3 text-slate-400" />
                                {item.visitor_phone}
                              </span>
                              {item.visitor_count > 1 && (
                                <span className="bg-slate-100 text-slate-600 px-1.5 py-0.2 rounded font-medium text-[10px]">
                                  +{item.visitor_count - 1} người
                                </span>
                              )}
                            </div>
                            {item.vehicle_license_plate && (
                              <div className="flex items-center gap-1 text-[11px] text-slate-500 mt-1 font-mono">
                                <Car className="w-3 h-3 text-slate-400" />
                                <span>{item.vehicle_license_plate}</span>
                              </div>
                            )}
                          </div>
                        </div>
                      </td>

                      {/* Cột 2: Căn hộ & Chủ hộ */}
                      <td className="py-4 px-4">
                        <div className="font-semibold text-neutral-800 flex items-center gap-1.5">
                          <Home className="w-3.5 h-3.5 text-sky-500 shrink-0" />
                          <span>P.{item.apartment.number}</span>
                          {item.apartment.block_name && (
                            <span className="text-slate-400 text-xs">({item.apartment.block_name})</span>
                          )}
                        </div>
                        <div className="text-xs text-slate-500 mt-0.5">
                          Chủ hộ: <strong className="text-slate-700">{item.host.name || 'Cư dân'}</strong>
                        </div>
                        {item.visit_purpose && (
                          <div className="text-[11px] text-slate-400 truncate max-w-[180px] mt-0.5">
                            Mục đích: {item.visit_purpose}
                          </div>
                        )}
                      </td>

                      {/* Cột 3: Thời gian */}
                      <td className="py-4 px-4">
                        <div className="text-xs space-y-1">
                          <div className="text-slate-600 flex items-center gap-1">
                            <span className="text-slate-400 text-[11px]">Dự kiến:</span>
                            <span className="font-medium">{formatDateTime(item.expected_arrival_time)}</span>
                          </div>
                          {item.checkin_log?.checkin_time && (
                            <div className="text-sky-700 font-semibold flex items-center gap-1 text-[11px]">
                              <span className="text-sky-500">Check-in:</span>
                              <span>{formatDateTime(item.checkin_log.checkin_time)}</span>
                            </div>
                          )}
                          {item.checkin_log?.checkout_time && (
                            <div className="text-emerald-700 font-semibold flex items-center gap-1 text-[11px]">
                              <span className="text-emerald-500">Check-out:</span>
                              <span>{formatDateTime(item.checkin_log.checkout_time)}</span>
                            </div>
                          )}
                        </div>
                      </td>

                      {/* Cột 4: Mã QR Access Pass */}
                      <td className="py-4 px-4">
                        <div className="font-mono text-xs font-bold text-slate-700 flex items-center gap-1.5">
                          <span className="bg-slate-100 px-2 py-1 rounded-lg border border-slate-200">
                            {item.qr_access_pass_code}
                          </span>
                          <button
                            type="button"
                            onClick={() => handleCopyCode(item.qr_access_pass_code)}
                            className="p-1 text-slate-400 hover:text-slate-600 cursor-pointer"
                            title="Sao chép mã QR Pass"
                          >
                            {copiedCode === item.qr_access_pass_code ? (
                              <Check className="w-3.5 h-3.5 text-emerald-600" />
                            ) : (
                              <Copy className="w-3.5 h-3.5" />
                            )}
                          </button>
                        </div>
                        <div className="text-[10px] text-slate-400 font-mono mt-1">
                          {item.registration_code}
                        </div>
                      </td>

                      {/* Cột 5: Trạng thái */}
                      <td className="py-4 px-4 text-center">
                        <span
                          className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ${
                            isInside
                              ? 'bg-sky-100 text-sky-800 border border-sky-300'
                              : isExpected
                              ? 'bg-indigo-100 text-indigo-800 border border-indigo-200'
                              : isCheckedOut
                              ? 'bg-emerald-100 text-emerald-800 border border-emerald-200'
                              : 'bg-slate-100 text-slate-700 border border-slate-200'
                          }`}
                        >
                          {isInside && <span className="w-1.5 h-1.5 rounded-full bg-sky-500 animate-ping" />}
                          <span>{item.status_label}</span>
                        </span>
                      </td>

                      {/* Cột 6: Thao tác tại quầy */}
                      <td className="py-4 px-4 sm:px-6 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          {/* Nút Check-in: Chỉ hiển thị khi trạng thái là Chờ check-in */}
                          {isExpected && (
                            <button
                              type="button"
                              onClick={() => handleOpenCheckinModal(item)}
                              className="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-xs hover:shadow-md transition-all flex items-center gap-1 cursor-pointer"
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
                              className="px-3 py-1.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs shadow-xs hover:shadow-md transition-all flex items-center gap-1 cursor-pointer"
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
                            className="p-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 transition-colors cursor-pointer"
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

        {/* Phân trang phía Server */}
        {totalPages > 1 && (
          <div className="p-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <div>
              Hiển thị trang <strong>{currentPage}</strong> trên <strong>{totalPages}</strong> ({totalCount} lượt khách)
            </div>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => handlePageChange(Math.max(1, currentPage - 1))}
                disabled={currentPage <= 1 || isLoading}
                className="p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40 cursor-pointer"
              >
                <ChevronLeft className="w-4 h-4" />
              </button>
              <span className="px-2 font-semibold text-slate-700">
                {currentPage} / {totalPages}
              </span>
              <button
                type="button"
                onClick={() => handlePageChange(Math.min(totalPages, currentPage + 1))}
                disabled={currentPage >= totalPages || isLoading}
                className="p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40 cursor-pointer"
              >
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* MODAL 1: CHECK-IN XÁC NHẬN */}
      {isCheckinModalOpen && checkinTarget && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-in fade-in duration-150">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-white/80 space-y-5 animate-in zoom-in-95 duration-150 my-auto">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center">
                  <UserCheck className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-extrabold text-neutral-900 text-base">Xác nhận Check-in khách</h3>
                  <p className="text-xs text-slate-400">Ghi nhận giờ vào bằng Server Time</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsCheckinModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Thông tin đối chiếu */}
            <div className="bg-slate-50 p-4 rounded-2xl space-y-2 text-xs">
              <div className="flex justify-between">
                <span className="text-slate-500">Khách viếng thăm:</span>
                <strong className="text-neutral-900">{checkinTarget.visitor_name}</strong>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Số điện thoại:</span>
                <span className="font-mono text-slate-800">{checkinTarget.visitor_phone}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Căn hộ đón tiếp:</span>
                <span className="font-bold text-sky-700">P.{checkinTarget.apartment.number}</span>
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
                  className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 font-mono"
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
                  className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 font-mono"
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
                  className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
              </div>
            </div>

            {/* Nút hành động */}
            <div className="flex items-center justify-end gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setIsCheckinModalOpen(false)}
                disabled={isSubmittingCheckin}
                className="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-700 font-semibold text-xs hover:bg-slate-50 cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleConfirmCheckin}
                disabled={isSubmittingCheckin}
                className="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-md shadow-emerald-600/30 transition-all flex items-center gap-2 cursor-pointer disabled:opacity-50"
              >
                {isSubmittingCheckin ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <UserCheck className="w-4 h-4" />
                )}
                <span>Xác nhận Check-in ngay</span>
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 2: CHECK-OUT XÁC NHẬN */}
      {isCheckoutModalOpen && checkoutTarget && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-in fade-in duration-150">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-white/80 space-y-5 animate-in zoom-in-95 duration-150 my-auto">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center">
                  <LogOut className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-extrabold text-neutral-900 text-base">Xác nhận Check-out khách</h3>
                  <p className="text-xs text-slate-400">Ghi nhận giờ rời tòa nhà bằng Server Time</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsCheckoutModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="bg-slate-50 p-4 rounded-2xl space-y-2 text-xs">
              <div className="flex justify-between">
                <span className="text-slate-500">Khách rời đi:</span>
                <strong className="text-neutral-900">{checkoutTarget.visitor_name}</strong>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Thời gian Check-in:</span>
                <span className="font-semibold text-sky-700">
                  {formatDateTime(checkoutTarget.checkin_log?.checkin_time)}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500">Căn hộ đã thăm:</span>
                <span className="font-bold text-slate-800">P.{checkoutTarget.apartment.number}</span>
              </div>
              {checkoutTarget.checkin_log?.temp_card_id && (
                <div className="flex justify-between text-amber-700 bg-amber-50 p-2 rounded-lg font-medium">
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
                className="w-full px-3 py-2 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-amber-500"
              />
            </div>

            <div className="flex items-center justify-end gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setIsCheckoutModalOpen(false)}
                disabled={isSubmittingCheckout}
                className="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-700 font-semibold text-xs hover:bg-slate-50 cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleConfirmCheckout}
                disabled={isSubmittingCheckout}
                className="px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs shadow-md shadow-amber-600/30 transition-all flex items-center gap-2 cursor-pointer disabled:opacity-50"
              >
                {isSubmittingCheckout ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <LogOut className="w-4 h-4" />
                )}
                <span>Xác nhận Check-out ngay</span>
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 3: KẾT QUẢ QUÉT MÃ QR / TRA CỨU NHANH */}
      {isLookupModalOpen && lookupResult && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-in fade-in duration-150">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-white/80 space-y-5 animate-in zoom-in-95 duration-150 my-auto">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center">
                  <QrCode className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-extrabold text-neutral-900 text-base">Kết quả Quét mã QR Pass</h3>
                  <p className="text-xs text-slate-400">Đối chiếu thông tin tức thời</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsLookupModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Thông tin thẻ khách */}
            <div className="bg-gradient-to-br from-slate-900 to-sky-950 text-white p-5 rounded-2xl space-y-3 relative overflow-hidden">
              <div className="flex items-start justify-between">
                <div>
                  <div className="text-[10px] text-sky-300 font-mono uppercase tracking-wider">
                    {lookupResult.registration.registration_code}
                  </div>
                  <div className="text-lg font-black text-white mt-0.5">
                    {lookupResult.registration.visitor_name}
                  </div>
                  <div className="text-xs text-slate-300 font-mono">
                    {lookupResult.registration.visitor_phone}
                  </div>
                </div>
                <span className={`px-2.5 py-1 rounded-full text-xs font-bold ${
                  lookupResult.status === 'INSIDE'
                    ? 'bg-sky-500 text-white'
                    : lookupResult.status === 'EXPECTED'
                    ? 'bg-emerald-500 text-white'
                    : 'bg-slate-700 text-slate-300'
                }`}>
                  {lookupResult.status_label}
                </span>
              </div>

              <div className="grid grid-cols-2 gap-2 pt-2 border-t border-white/10 text-xs">
                <div>
                  <span className="text-slate-400 text-[10px] block">Căn hộ đến thăm:</span>
                  <strong className="text-sky-300">P.{lookupResult.registration.apartment.number}</strong>
                </div>
                <div>
                  <span className="text-slate-400 text-[10px] block">Chủ hộ đón tiếp:</span>
                  <strong className="text-white">{lookupResult.registration.host.name}</strong>
                </div>
              </div>
            </div>

            {/* Nút thao tác tương ứng */}
            <div className="flex items-center justify-end gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setIsLookupModalOpen(false)}
                className="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-700 font-semibold text-xs hover:bg-slate-50 cursor-pointer"
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
                  className="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-md shadow-emerald-600/30 transition-all flex items-center gap-2 cursor-pointer"
                >
                  <UserCheck className="w-4 h-4" />
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
                  className="px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs shadow-md shadow-amber-600/30 transition-all flex items-center gap-2 cursor-pointer"
                >
                  <LogOut className="w-4 h-4" />
                  <span>Tiến hành Check-out</span>
                </button>
              )}
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 4: CHI TIẾT HỒ SƠ LƯỢT KHÁCH */}
      {isDetailModalOpen && selectedVisitor && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-in fade-in duration-150">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-white/80 space-y-4 animate-in zoom-in-95 duration-150 my-auto">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div>
                <h3 className="font-extrabold text-neutral-900 text-base">Chi tiết lượt khách viếng thăm</h3>
                <p className="text-xs text-slate-400 font-mono">{selectedVisitor.registration_code}</p>
              </div>
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div className="grid grid-cols-2 gap-3 bg-slate-50 p-4 rounded-2xl">
                <div>
                  <span className="text-slate-400 text-[11px] block">Họ tên khách:</span>
                  <strong className="text-slate-800 text-sm">{selectedVisitor.visitor_name}</strong>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Số điện thoại:</span>
                  <span className="font-mono text-slate-800">{selectedVisitor.visitor_phone}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Số CCCD / CMND:</span>
                  <span className="font-mono text-slate-800">{selectedVisitor.visitor_national_id || '—'}</span>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Số người đi cùng:</span>
                  <span className="text-slate-800">{selectedVisitor.visitor_count} người</span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3 bg-slate-50 p-4 rounded-2xl">
                <div>
                  <span className="text-slate-400 text-[11px] block">Căn hộ:</span>
                  <strong className="text-sky-700">P.{selectedVisitor.apartment.number}</strong>
                </div>
                <div>
                  <span className="text-slate-400 text-[11px] block">Cư dân bảo lãnh:</span>
                  <strong className="text-slate-800">{selectedVisitor.host.name}</strong>
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
              <div className="border border-slate-200 p-4 rounded-2xl space-y-2">
                <div className="font-bold text-slate-800 text-xs">Nhật ký ra vào</div>
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

            <div className="flex justify-end pt-2">
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="px-5 py-2.5 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white font-bold text-xs cursor-pointer"
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
