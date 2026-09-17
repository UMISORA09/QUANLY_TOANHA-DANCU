import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  Building2,
  Plus,
  Search,
  Filter,
  Layers,
  CalendarOff,
  Clock,
  Edit,
  Trash2,
  Power,
  ChevronLeft,
  ChevronRight,
  Eye,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Tag,
  ArrowUpDown,
  Home,
  ChevronDown,
  Sparkles,
  RefreshCw,
  Info,
  LogIn,
  LogOut,
  Shield,
  Ticket,
  Image as ImageIcon,
  X
} from 'lucide-react';
import { api, Amenity, Category, BlockOption, AmenityListResponse } from '../../Services/api';
import { amenityCache } from '../../Services/amenityCache';
import { useDebounce } from '../../Hooks/useDebounce';
import { AmenityFormModal } from '../../Components/Admin/AmenityFormModal';
import { CategoryModal } from '../../Components/Admin/CategoryModal';
import { TimeSlotModal } from '../../Components/Admin/TimeSlotModal';
import { BlackoutModal } from '../../Components/Admin/BlackoutModal';
import { AmenityBookingsModal } from '../../Components/Admin/AmenityBookingsModal';
import { ConfirmDialog } from '../../Components/Admin/ConfirmDialog';

export interface AmenityManagementProps {
  embedded?: boolean;
}

/**
 * Chuyển chuỗi có dấu thành không dấu phục vụ so khớp vị trí highlight
 */
const stripAccents = (str: string): string => {
  return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/gi, 'd');
};

/**
 * Highlight từ khóa tìm kiếm (hỗ trợ cả tiếng Việt không dấu & đa từ)
 */
const renderHighlightedText = (text: string | null | undefined, query: string): React.ReactNode => {
  if (!text) return text ?? '';
  const trimmed = query.trim();
  if (!trimmed) return text;

  const tokens = trimmed.split(/\s+/).filter(Boolean);
  if (tokens.length === 0) return text;

  // Xây dựng regex escape cho các tokens
  const escapedTokens = tokens.map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
  const regex = new RegExp(`(${escapedTokens})`, 'gi');

  // Thử match trực tiếp với ký tự có dấu/không dấu
  const parts = text.split(regex);
  if (parts.length > 1) {
    return (
      <>
        {parts.map((part, i) =>
          regex.test(part) ? (
            <mark key={i} className="bg-amber-100 text-amber-950 font-bold px-0.5 rounded">
              {part}
            </mark>
          ) : (
            <React.Fragment key={i}>{part}</React.Fragment>
          )
        )}
      </>
    );
  }

  // Fallback: So khớp unaccented token nếu người dùng gõ không dấu
  const normalizedText = stripAccents(text).toLowerCase();
  for (const token of tokens) {
    const normToken = stripAccents(token).toLowerCase();
    const idx = normalizedText.indexOf(normToken);
    if (idx !== -1) {
      const matchLen = normToken.length;
      const before = text.slice(0, idx);
      const matched = text.slice(idx, idx + matchLen);
      const after = text.slice(idx + matchLen);
      return (
        <>
          {before}
          <mark className="bg-amber-100 text-amber-950 font-bold px-0.5 rounded">{matched}</mark>
          {after}
        </>
      );
    }
  }

  return text;
};

export const AmenityManagement: React.FC<AmenityManagementProps> = ({ embedded = false }) => {
  // Lấy dữ liệu khởi tạo ngay từ L1/L2 Cache (0ms perceived latency, không bị nháy loading)
  const initialUser = api.getUser();
  const initialCacheKey = amenityCache.buildAmenityListKey(
    { page: 1, limit: 10, sort: 'created_at_desc' },
    initialUser?.id || 'admin'
  );
  const initialLookup = amenityCache.get<AmenityListResponse>(initialCacheKey);

  // Data states
  const [amenities, setAmenities] = useState<Amenity[]>(() => initialLookup.data?.items || []);
  const [categories, setCategories] = useState<Category[]>(() => {
    const cached = amenityCache.get<Category[]>('amenities:categories');
    return cached.data || [];
  });
  const [blocks, setBlocks] = useState<BlockOption[]>(() => {
    const cached = amenityCache.get<BlockOption[]>('amenities:blocks');
    return cached.data || [];
  });
  const [total, setTotal] = useState<number>(() => initialLookup.data?.total || 0);
  const [totalPages, setTotalPages] = useState<number>(() => initialLookup.data?.total_pages || 1);
  const [loading, setLoading] = useState<boolean>(() => !initialLookup.exists);
  const [isSyncing, setIsSyncing] = useState<boolean>(false);
  const [isSearching, setIsSearching] = useState<boolean>(false);
  const [hasInitiallyLoaded, setHasInitiallyLoaded] = useState<boolean>(() => Boolean(initialLookup.exists));
  const [correctedQuery, setCorrectedQuery] = useState<string | null>(null);
  const hasInitiallyLoadedRef = useRef<boolean>(Boolean(initialLookup.exists));

  // Filter & pagination states
  const [searchInput, setSearchInput] = useState('');
  const debouncedSearch = useDebounce(searchInput, 200);
  const [selectedCategory, setSelectedCategory] = useState('');
  const [selectedBlock, setSelectedBlock] = useState('');
  const [selectedStatus, setSelectedStatus] = useState<string>('all');
  const [sortOrder, setSortOrder] = useState('created_at_desc');
  const [page, setPage] = useState(1);
  const limit = 10;

  // Autocomplete states & refs
  const [suggestions, setSuggestions] = useState<Array<{ id: string; label: string; code?: string; type?: string; category?: string }>>([]);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [isSuggesting, setIsSuggesting] = useState(false);
  const [activeSuggestionIdx, setActiveSuggestionIdx] = useState(-1);
  const searchContainerRef = useRef<HTMLDivElement | null>(null);

  // Request cancellation & sequence counter (Race-condition & stale response protection)
  const searchAbortControllerRef = useRef<AbortController | null>(null);
  const searchSequenceRef = useRef<number>(0);
  const suggestAbortControllerRef = useRef<AbortController | null>(null);
  const prevDebouncedSearchRef = useRef<string>(debouncedSearch);

  // Modals
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [editingAmenity, setEditingAmenity] = useState<Amenity | null>(null);

  const [isCategoryModalOpen, setIsCategoryModalOpen] = useState(false);

  const [slotModalAmenity, setSlotModalAmenity] = useState<Amenity | null>(null);
  const [blackoutModalAmenity, setBlackoutModalAmenity] = useState<Amenity | null>(null);
  const [detailAmenity, setDetailAmenity] = useState<Amenity | null>(null);
  const [bookingModalAmenity, setBookingModalAmenity] = useState<Amenity | null>(null);

  // Confirm dialog
  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    message: string;
    isDangerous?: boolean;
    action: () => Promise<void>;
  }>({
    isOpen: false,
    title: '',
    message: '',
    action: async () => {},
  });
  const [isConfirmLoading, setIsConfirmLoading] = useState(false);

  // Toast notification
  const [toast, setToast] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Authentication states
  const [currentUser, setCurrentUser] = useState<any>(() => api.getUser());
  const [isAuthModalOpen, setIsAuthModalOpen] = useState(false);
  const [authEmail, setAuthEmail] = useState('admin@smartcassavas.vn');
  const [authPassword, setAuthPassword] = useState('');
  const [authError, setAuthError] = useState<string | null>(null);
  const [isAuthSubmitting, setIsAuthSubmitting] = useState(false);

  const showToast = (message: string, type: 'success' | 'error' = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3500);
  };

  // Check Current User Auth (không chặn UI gọi network nếu đã có user trong localStorage)
  const checkAuth = async (force = false) => {
    const cachedUser = api.getUser();
    if (cachedUser && !force) {
      setCurrentUser(cachedUser);
      return;
    }
    try {
      const user = await api.getMe();
      api.setUser(user);
      setCurrentUser(user);
    } catch {
      api.logout();
      setCurrentUser(null);
    }
  };

  const handleAdminLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsAuthSubmitting(true);
    setAuthError(null);
    try {
      const res = await api.login(authEmail.trim(), authPassword);
      if (res.user) {
        api.setUser(res.user);
        setCurrentUser(res.user);
      }
      setIsAuthModalOpen(false);
      showToast('Đăng nhập Quản trị viên thành công!');
      await loadMetadata(true);
      await fetchAmenities();
    } catch (err: any) {
      setAuthError(err.message || 'Đăng nhập không thành công.');
    } finally {
      setIsAuthSubmitting(false);
    }
  };

  const handleLogout = () => {
    api.logout();
    setCurrentUser(null);
    setIsAuthModalOpen(true);
    showToast('Đã đăng xuất hệ thống.');
  };

  // Load Categories & Blocks via Smart Cache (L1 Memory + L2 Storage, 0ms)
  const loadMetadata = async (forceRefresh = false) => {
    try {
      const [cats, blks] = await Promise.all([
        api.getCategories(forceRefresh),
        api.getBlocks(forceRefresh),
      ]);
      setCategories(cats);
      setBlocks(blks);
    } catch (err: any) {
      console.error('Failed to load metadata', err);
    }
  };

  // Fetch Amenities with Stale-While-Revalidate (SWR), AbortController & Race-Condition sequence tracking
  const fetchAmenities = useCallback(async (force = false, targetPage?: number) => {
    const currentPage = targetPage !== undefined ? targetPage : page;
    const isSearchQuery = Boolean(debouncedSearch.trim());

    // 1. Cancel previous in-flight search request (Request Cancellation)
    if (searchAbortControllerRef.current) {
      searchAbortControllerRef.current.abort();
    }
    const abortController = new AbortController();
    searchAbortControllerRef.current = abortController;

    // 2. Increment search sequence counter (Race Condition Protection)
    const currentSeq = ++searchSequenceRef.current;

    const params = {
      search: debouncedSearch.trim() || undefined,
      category_id: selectedCategory || undefined,
      block_id: selectedBlock || undefined,
      is_active: selectedStatus === 'all' ? undefined : selectedStatus === 'active',
      sort: sortOrder,
      page: currentPage,
      limit,
    };

    const cacheKey = amenityCache.buildAmenityListKey(params, currentUser?.id || 'admin');
    const lookup = amenityCache.get<AmenityListResponse>(cacheKey);

    // CRITICAL UX: Chỉ hiển thị skeleton toàn bảng ở lần mount đầu tiên khi chưa có dữ liệu nào
    if (!hasInitiallyLoadedRef.current && !lookup.exists) {
      setLoading(true);
    }

    if (isSearchQuery) {
      setIsSearching(true);
    }

    try {
      await api.getAmenitiesSwr(params, {
        forceRefresh: force,
        signal: abortController.signal,
        onData: (data) => {
          // RACE CONDITION CHECK: Bỏ qua kết quả cũ nếu đã có truy vấn mới hơn
          if (currentSeq !== searchSequenceRef.current) {
            return;
          }
          setAmenities(data.items);
          setTotal(data.total);
          setTotalPages(data.total_pages);
          setCorrectedQuery(data.corrected_query || null);
          setLoading(false);
          setIsSearching(false);
          setHasInitiallyLoaded(true);
          hasInitiallyLoadedRef.current = true;

          // Đảm bảo không bị kẹt ở trang vượt quá tổng số trang sau khi xóa item
          if (data.total_pages > 0 && currentPage > data.total_pages) {
            setPage(data.total_pages);
          }
        },
        onSyncing: (syncing) => {
          if (currentSeq === searchSequenceRef.current) {
            setIsSyncing(syncing);
          }
        },
        onError: (err, hasCachedData) => {
          if (currentSeq !== searchSequenceRef.current) return;
          if (!hasCachedData) {
            setLoading(false);
          }
          setIsSearching(false);
          setHasInitiallyLoaded(true);
          hasInitiallyLoadedRef.current = true;
          if (
            err.message &&
            (err.message.includes('401') ||
              err.message.includes('đăng nhập') ||
              err.message.includes('Unauthorized') ||
              err.message.includes('xác thực'))
          ) {
            setIsAuthModalOpen(true);
          }
          if (hasCachedData) {
            showToast('Không thể đồng bộ dữ liệu mới nhất. Đang hiển thị bản lưu gần nhất.', 'error');
          } else {
            showToast(err.message || 'Không thể tải danh sách tiện ích.', 'error');
          }
        },
      });

      // Tự động tải trước (Prefetch) trang tiếp theo vào cache ngầm
      if (currentPage < totalPages) {
        api.prefetchAmenities({
          ...params,
          page: currentPage + 1,
        });
      }
    } catch (err: any) {
      if (currentSeq === searchSequenceRef.current) {
        setLoading(false);
        setIsSearching(false);
        setIsSyncing(false);
      }
    }
  }, [debouncedSearch, selectedCategory, selectedBlock, selectedStatus, sortOrder, page, currentUser]);

  // Handle Clear Search smoothly
  const handleClearSearch = useCallback(() => {
    setSearchInput('');
    setCorrectedQuery(null);
    setShowSuggestions(false);
    if (searchAbortControllerRef.current) {
      searchAbortControllerRef.current.abort();
    }
    if (page !== 1) {
      setPage(1);
    } else {
      fetchAmenities(false, 1);
    }
  }, [page, fetchAmenities]);

  // Autocomplete suggestions fetcher with 200ms debounce
  useEffect(() => {
    const trimmed = searchInput.trim();
    if (trimmed.length < 2) {
      setSuggestions([]);
      setShowSuggestions(false);
      return;
    }

    if (suggestAbortControllerRef.current) {
      suggestAbortControllerRef.current.abort();
    }
    const abortCtrl = new AbortController();
    suggestAbortControllerRef.current = abortCtrl;

    const timer = setTimeout(async () => {
      setIsSuggesting(true);
      try {
        const list = await api.getSearchSuggestions(trimmed, 'amenities', abortCtrl.signal);
        if (!abortCtrl.signal.aborted) {
          setSuggestions(list);
          setShowSuggestions(list.length > 0);
        }
      } catch {
        // ignore abort
      } finally {
        if (!abortCtrl.signal.aborted) {
          setIsSuggesting(false);
        }
      }
    }, 200);

    return () => {
      clearTimeout(timer);
      abortCtrl.abort();
    };
  }, [searchInput]);

  // Click outside to close autocomplete dropdown
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (searchContainerRef.current && !searchContainerRef.current.contains(e.target as Node)) {
        setShowSuggestions(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    checkAuth();
    loadMetadata();
  }, []);

  // Atomic fetch trigger (Eliminates double request waterfall)
  useEffect(() => {
    if (prevDebouncedSearchRef.current !== debouncedSearch) {
      prevDebouncedSearchRef.current = debouncedSearch;
      if (page !== 1) {
        setPage(1);
        return; // setPage(1) sẽ kích hoạt render tiếp theo với page = 1
      }
    }
    fetchAmenities(false, page);
  }, [debouncedSearch, selectedCategory, selectedBlock, selectedStatus, sortOrder, page]);

  // Lắng nghe sự kiện đồng bộ đa tab toàn diện (Comprehensive Cross-Tab Synchronization)
  useEffect(() => {
    const unsubscribe = amenityCache.subscribe((event) => {
      if (event.type === 'CATEGORY_CHANGED') {
        loadMetadata(true);
      }

      if (event.type === 'AMENITY_DELETED' && event.amenityId) {
        // Nếu đang mở xem/sửa tiện ích vừa bị xóa ở tab khác, tự đóng modal và cảnh báo
        if (detailAmenity?.id === event.amenityId) {
          setDetailAmenity(null);
          showToast('Tiện ích đang xem đã bị xóa bởi quản trị viên ở tab khác.', 'error');
        }
        if (editingAmenity?.id === event.amenityId) {
          setEditingAmenity(null);
          setIsFormOpen(false);
          showToast('Tiện ích đang chỉnh sửa đã bị xóa bởi quản trị viên ở tab khác.', 'error');
        }
        setSlotModalAmenity((prev) => (prev?.id === event.amenityId ? null : prev));
        setBlackoutModalAmenity((prev) => (prev?.id === event.amenityId ? null : prev));
        setBookingModalAmenity((prev) => (prev?.id === event.amenityId ? null : prev));
      }

      // Khi tiện ích được sửa/đổi trạng thái/đổi slot/đổi booking, nếu đang mở xem chi tiết tiện ích đó, âm thầm cập nhật dữ liệu modal
      if (
        event.amenityId &&
        ['AMENITY_UPDATED', 'AMENITY_STATUS_CHANGED', 'AMENITY_SLOT_UPDATED', 'AMENITY_BOOKING_CHANGED'].includes(
          event.type
        )
      ) {
        if (detailAmenity?.id === event.amenityId) {
          api
            .getAmenity(event.amenityId, true)
            .then((fresh) => {
              setDetailAmenity(fresh);
            })
            .catch(() => {});
        }
      }

      // Tự động re-fetch danh sách tiện ích mà KHÔNG reload toàn bộ trang
      fetchAmenities(true);
    });

    return () => {
      unsubscribe();
    };
  }, [fetchAmenities, detailAmenity, editingAmenity]);

  const handleOpenCreate = () => {
    setEditingAmenity(null);
    setIsFormOpen(true);
  };

  const handleOpenEdit = (amenity: Amenity) => {
    setEditingAmenity(amenity);
    setIsFormOpen(true);
  };

  const handleToggleStatus = (amenity: Amenity) => {
    const nextStatus = !amenity.is_active;
    setConfirmDialog({
      isOpen: true,
      title: nextStatus ? 'Kích hoạt tiện ích' : 'Tạm ngưng tiện ích',
      message: nextStatus
        ? `Bạn có chắc muốn kích hoạt lại tiện ích '${amenity.amenity_name}'? Cư dân sẽ có thể đặt chỗ theo khung giờ khả dụng.`
        : `Bạn có chắc muốn tạm ngưng tiện ích '${amenity.amenity_name}'? Lưu ý: Các booking đã đặt trước đó trong lịch sử vẫn được giữ nguyên an toàn.`,
      isDangerous: !nextStatus,
      action: async () => {
        setIsConfirmLoading(true);
        try {
          await api.patchAmenityStatus(amenity.id, nextStatus);
          showToast(`Đã ${nextStatus ? 'kích hoạt' : 'tạm ngưng'} tiện ích thành công.`);
          await fetchAmenities(true);
        } catch (err: any) {
          showToast(err.message || 'Lỗi khi đổi trạng thái tiện ích.', 'error');
        } finally {
          setIsConfirmLoading(false);
          setConfirmDialog((prev) => ({ ...prev, isOpen: false }));
        }
      },
    });
  };

  const handleDeleteAmenity = (amenity: Amenity) => {
    setConfirmDialog({
      isOpen: true,
      title: 'Xác nhận xóa tiện ích',
      message: `Bạn có chắc muốn xóa tiện ích '${amenity.amenity_name}' (${amenity.amenity_code})? Thao tác này sẽ bảo vệ lịch sử booking và nhật ký kiểm toán hệ thống.`,
      isDangerous: true,
      action: async () => {
        setIsConfirmLoading(true);
        try {
          await api.deleteAmenity(amenity.id);
          showToast('Đã xóa tiện ích thành công.');
          await fetchAmenities(true);
        } catch (err: any) {
          showToast(err.message || 'Không thể xóa tiện ích.', 'error');
        } finally {
          setIsConfirmLoading(false);
          setConfirmDialog((prev) => ({ ...prev, isOpen: false }));
        }
      },
    });
  };

  const formatCurrency = (val: number | string) => {
    const num = Number(val);
    if (!num || num === 0) return 'Miễn phí';
    return `${new Intl.NumberFormat('vi-VN').format(num)}\u00A0đ`;
  };

  const formatDate = (dateStr: string) => {
    if (!dateStr) return '-';
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

  return (
    <div className={`${embedded ? 'w-full text-neutral-900' : 'min-h-screen bg-neutral-900/10 text-neutral-900 pb-16'} font-sans`}>
      {/* Toast Notification */}
      {toast && (
        <div
          className={`fixed top-4 right-4 z-50 px-4 py-3 rounded-xl shadow-2xl flex items-center gap-2.5 text-xs sm:text-sm font-medium animate-in fade-in slide-in-from-top-3 duration-200 border ${
            toast.type === 'success'
              ? 'bg-neutral-950 text-white border-white/20'
              : 'bg-rose-50 text-rose-900 border-rose-200'
          }`}
        >
          {toast.type === 'success' ? (
            <Sparkles className="w-4 h-4 text-emerald-400 shrink-0 animate-pulse" />
          ) : (
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
          )}
          <span>{toast.message}</span>
        </div>
      )}

      {/* Top Breadcrumb & Actions Bar (Only if not embedded) */}
      {!embedded && (
        <div className="bg-white/80 backdrop-blur-xl border-b border-neutral-200/80 sticky top-0 z-20">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            {/* Breadcrumbs */}
            <div className="flex items-center gap-2 text-xs text-neutral-500">
              <a href="/" className="hover:text-neutral-950 flex items-center gap-1 transition-colors">
                <Home className="w-3.5 h-3.5" />
                <span>Trang chủ</span>
              </a>
              <span>/</span>
              <span className="font-semibold text-neutral-600">Quản lý</span>
              <span>/</span>
              <span className="font-bold text-neutral-950">Tiện ích & Cấu hình Slot</span>
            </div>

            {/* Action Buttons & Auth Info */}
            <div className="flex items-center gap-2 shrink-0">
              {currentUser ? (
                <div className="flex items-center gap-2 mr-1 sm:mr-2 pr-2 border-r border-neutral-200/80">
                  <div className="text-right hidden sm:block">
                    <div className="text-[11px] font-bold text-neutral-900 leading-tight">
                      {currentUser.full_name || currentUser.username}
                    </div>
                    <div className="text-[10px] text-neutral-500 font-mono">
                      {currentUser.roles?.[0] || 'ADMIN'}
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={handleLogout}
                    className="p-1.5 text-neutral-500 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-colors cursor-pointer"
                    title="Đăng xuất khỏi phiên quản trị"
                  >
                    <LogOut className="w-4 h-4" />
                  </button>
                </div>
              ) : (
                <button
                  type="button"
                  onClick={() => setIsAuthModalOpen(true)}
                  className="px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-200 text-xs font-semibold rounded-xl flex items-center gap-1.5 transition-all cursor-pointer mr-1"
                >
                  <LogIn className="w-3.5 h-3.5" />
                  <span>Đăng nhập Admin</span>
                </button>
              )}

              <button
                type="button"
                onClick={() => setIsCategoryModalOpen(true)}
                className="px-3.5 py-2 bg-white hover:bg-neutral-50 active:scale-95 border border-neutral-200/90 text-neutral-800 text-xs font-semibold rounded-xl flex items-center gap-1.5 transition-all shadow-2xs cursor-pointer"
              >
                <Tag className="w-3.5 h-3.5 text-neutral-600" />
                <span>Quản lý danh mục</span>
              </button>

              <button
                type="button"
                onClick={handleOpenCreate}
                className="px-4 py-2 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-xl flex items-center gap-1.5 transition-all shadow-xs cursor-pointer"
              >
                <Plus className="w-4 h-4" />
                <span>Thêm tiện ích</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Page Header */}
      <div className={`${embedded ? 'w-full' : 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8'} ${embedded ? 'pt-0' : 'pt-6'} pb-4`}>
        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <div className="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-wider font-mono">
                <Sparkles className="w-3.5 h-3.5 text-sky-500" />
                <span>PHÂN HỆ QUẢN LÝ TIỆN ÍCH</span>
              </div>
              {isSyncing && (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-medium bg-sky-50 text-sky-700 border border-sky-200/80 animate-in fade-in duration-200">
                  <RefreshCw className="w-3 h-3 animate-spin text-sky-600" />
                  <span>Đang đồng bộ ngầm...</span>
                </span>
              )}
            </div>
            <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
              Danh mục Tiện ích Tòa nhà
            </h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-1 max-w-2xl leading-relaxed">
              Quản lý danh mục dịch vụ, cấu hình sức chứa tối đa mỗi lượt đặt, biểu phí thuê theo giờ, khung giờ hoạt động và ngày đóng cửa bảo trì.
            </p>
          </div>

          <div className="flex items-center gap-2 shrink-0 self-start md:self-end">
            {embedded && (
              <>
                <button
                  type="button"
                  onClick={() => setIsCategoryModalOpen(true)}
                  className="px-3.5 py-2 bg-white hover:bg-neutral-50 active:scale-95 border border-neutral-200/90 text-neutral-800 text-xs font-semibold rounded-xl flex items-center gap-1.5 transition-all shadow-2xs cursor-pointer"
                >
                  <Tag className="w-3.5 h-3.5 text-neutral-600" />
                  <span>Quản lý danh mục</span>
                </button>

                <button
                  type="button"
                  onClick={handleOpenCreate}
                  className="px-4 py-2 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-xl flex items-center gap-1.5 transition-all shadow-xs cursor-pointer"
                >
                  <Plus className="w-4 h-4" />
                  <span>Thêm tiện ích</span>
                </button>
              </>
            )}

            <button
              type="button"
              onClick={() => fetchAmenities(true)}
              className="p-2.5 text-slate-500 hover:text-neutral-900 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors shadow-xs cursor-pointer"
              title="Làm mới dữ liệu từ máy chủ (Bỏ qua cache)"
            >
              <RefreshCw className={`w-4 h-4 ${loading || isSyncing ? 'animate-spin text-neutral-950' : ''}`} />
            </button>
          </div>
        </div>
      </div>

      {/* Main Container */}
      <div className={`${embedded ? 'w-full' : 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8'} space-y-4`}>
        {/* Filters Bar Card */}
        <div className="bg-white/90 backdrop-blur-xl border border-white/80 rounded-2xl p-4 shadow-sm space-y-3">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2.5">
            {/* Search Input with Autocomplete, Clear button & Searching spinner */}
            <div ref={searchContainerRef} className="lg:col-span-2 relative">
              <Search className="w-4 h-4 text-neutral-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
              <input
                type="text"
                placeholder="Tìm theo tên tiện ích hoặc mã tiện ích (VD: GYM, BƠI)..."
                value={searchInput}
                onChange={(e) => {
                  setSearchInput(e.target.value);
                  setActiveSuggestionIdx(-1);
                }}
                onFocus={() => {
                  if (suggestions.length > 0) setShowSuggestions(true);
                }}
                onKeyDown={(e) => {
                  if (!showSuggestions || suggestions.length === 0) return;
                  if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setActiveSuggestionIdx((prev) => (prev < suggestions.length - 1 ? prev + 1 : 0));
                  } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    setActiveSuggestionIdx((prev) => (prev > 0 ? prev - 1 : suggestions.length - 1));
                  } else if (e.key === 'Enter' && activeSuggestionIdx >= 0) {
                    e.preventDefault();
                    setSearchInput(suggestions[activeSuggestionIdx].label);
                    setShowSuggestions(false);
                  } else if (e.key === 'Escape') {
                    setShowSuggestions(false);
                  }
                }}
                className="w-full pl-9 pr-14 py-2 text-xs border border-neutral-200 rounded-xl bg-neutral-50/50 focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 transition-all"
              />

              {/* Right Indicators: Spinner & Clear Button */}
              <div className="absolute right-2.5 top-1/2 -translate-y-1/2 flex items-center gap-1.5">
                {(isSearching || isSuggesting) && (
                  <RefreshCw className="w-3.5 h-3.5 text-sky-600 animate-spin" />
                )}
                {searchInput && (
                  <button
                    type="button"
                    onClick={handleClearSearch}
                    className="p-1 text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 rounded-full transition-colors cursor-pointer"
                    title="Xóa từ khóa tìm kiếm"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>

              {/* Autocomplete Suggestions Dropdown */}
              {showSuggestions && suggestions.length > 0 && (
                <div className="absolute left-0 right-0 top-full mt-1.5 bg-white/95 backdrop-blur-xl border border-neutral-200/90 rounded-xl shadow-lg z-30 py-1 overflow-hidden animate-in fade-in slide-in-from-top-1 duration-150">
                  <div className="px-3 py-1 text-[10px] font-bold text-neutral-400 uppercase tracking-wider">
                    Gợi ý tiện ích ({suggestions.length})
                  </div>
                  {suggestions.map((s, idx) => (
                    <button
                      key={s.id}
                      type="button"
                      onClick={() => {
                        setSearchInput(s.label);
                        setShowSuggestions(false);
                      }}
                      className={`w-full px-3 py-2 text-left flex items-center justify-between text-xs cursor-pointer transition-colors ${
                        idx === activeSuggestionIdx ? 'bg-sky-50 text-sky-900' : 'hover:bg-neutral-50 text-neutral-800'
                      }`}
                    >
                      <div className="flex items-center gap-2 min-w-0">
                        <Sparkles className="w-3.5 h-3.5 text-sky-500 shrink-0" />
                        <span className="font-semibold truncate">{renderHighlightedText(s.label, searchInput)}</span>
                        {s.code && (
                          <span className="text-[10px] font-mono px-1.5 py-0.2 rounded bg-neutral-100 text-neutral-600 border border-neutral-200">
                            {renderHighlightedText(s.code, searchInput)}
                          </span>
                        )}
                      </div>
                    </button>
                  ))}
                </div>
              )}
            </div>

            {/* Category Filter */}
            <div>
              <select
                value={selectedCategory}
                onChange={(e) => {
                  setSelectedCategory(e.target.value);
                  setPage(1);
                }}
                className="w-full px-3 py-2 text-xs border border-neutral-200 rounded-xl bg-neutral-50/50 focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 cursor-pointer"
              >
                <option value="">Tất cả danh mục</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.category_name}
                  </option>
                ))}
              </select>
            </div>

            {/* Block Filter */}
            <div>
              <select
                value={selectedBlock}
                onChange={(e) => {
                  setSelectedBlock(e.target.value);
                  setPage(1);
                }}
                className="w-full px-3 py-2 text-xs border border-neutral-200 rounded-xl bg-neutral-50/50 focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 cursor-pointer"
              >
                <option value="">Tất cả tòa nhà</option>
                {blocks.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.block_name}
                  </option>
                ))}
              </select>
            </div>

            {/* Status Filter */}
            <div>
              <select
                value={selectedStatus}
                onChange={(e) => {
                  setSelectedStatus(e.target.value);
                  setPage(1);
                }}
                className="w-full px-3 py-2 text-xs border border-neutral-200 rounded-xl bg-neutral-50/50 focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 cursor-pointer"
              >
                <option value="all">Tất cả trạng thái</option>
                <option value="active">Đang hoạt động</option>
                <option value="inactive">Tạm ngưng</option>
              </select>
            </div>
          </div>

          {/* Did-you-mean Spell Correction Banner */}
          {debouncedSearch.trim() && correctedQuery && correctedQuery.toLowerCase() !== debouncedSearch.toLowerCase().trim() && (
            <div className="mt-3 pt-3 border-t border-neutral-100 flex items-center gap-2 px-3 py-2 bg-sky-50/80 border border-sky-200/80 rounded-xl text-xs text-sky-900 animate-in fade-in duration-150">
              <Sparkles className="w-4 h-4 text-sky-600 shrink-0" />
              <span>Có phải bạn muốn tìm:</span>
              <button
                type="button"
                onClick={() => {
                  setSearchInput(correctedQuery);
                  setPage(1);
                }}
                className="font-bold text-sky-700 hover:text-sky-900 underline underline-offset-2 cursor-pointer transition-colors"
              >
                &quot;{correctedQuery}&quot;
              </button>
              <span className="text-sky-500 text-[11px] hidden sm:inline">(Bấm để tìm theo từ khóa chuẩn đã sửa lỗi)</span>
            </div>
          )}
        </div>

        {/* Data Table Card */}
        <div className="bg-white/95 backdrop-blur-xl border border-white/90 rounded-2xl shadow-sm overflow-hidden flex flex-col">
          {/* Table Container */}
          <div className="overflow-x-auto table-scrollbar">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-neutral-200/80 bg-neutral-50/80 text-[11px] font-bold text-neutral-600 uppercase tracking-wider">
                  <th className="py-3.5 px-4 whitespace-nowrap min-w-[220px]">Tên tiện ích</th>
                  <th className="py-3.5 px-3 whitespace-nowrap min-w-[110px]">Mã tiện ích</th>
                  <th className="py-3.5 px-3 whitespace-nowrap min-w-[140px]">Danh mục</th>
                  <th className="py-3.5 px-3 whitespace-nowrap min-w-[120px]">Tòa nhà</th>
                  <th className="py-3.5 px-3 whitespace-nowrap min-w-[160px]">Vị trí</th>
                  <th className="py-3.5 px-3 text-center whitespace-nowrap min-w-[125px]">Sức chứa / slot</th>
                  <th className="py-3.5 px-3 text-center whitespace-nowrap min-w-[135px]">Lượt đặt chỗ / slot</th>
                  <th className="py-3.5 px-3 whitespace-nowrap min-w-[110px]">Giá / giờ</th>
                  <th className="py-3.5 px-3 whitespace-nowrap min-w-[110px]">Tiền cọc</th>
                  <th className="py-3.5 px-3 text-center whitespace-nowrap min-w-[105px]">Duyệt BQL</th>
                  <th className="py-3.5 px-3 text-center whitespace-nowrap min-w-[120px]">Trạng thái</th>
                  <th className="py-3.5 px-3 whitespace-nowrap min-w-[130px]">Cập nhật</th>
                  <th className="py-3.5 px-4 text-right whitespace-nowrap min-w-[210px] sticky right-0 bg-neutral-50 border-l border-neutral-200/80 shadow-[-6px_0_12px_rgba(0,0,0,0.04)] z-10">Thao tác</th>
                </tr>
              </thead>
              <tbody className={`divide-y divide-neutral-100 transition-opacity duration-200 ${isSearching ? 'opacity-70' : 'opacity-100'}`}>
                {loading ? (
                  Array.from({ length: 5 }).map((_, idx) => (
                    <tr key={`skeleton-${idx}`} className="animate-pulse">
                      <td className="py-3.5 px-4">
                        <div className="flex items-center gap-2.5">
                          <div className="w-8 h-8 rounded-lg bg-neutral-200 shrink-0" />
                          <div className="space-y-1.5 flex-1 min-w-0">
                            <div className="h-3 bg-neutral-200 rounded w-28" />
                            <div className="h-2 bg-neutral-100 rounded w-16" />
                          </div>
                        </div>
                      </td>
                      <td className="py-3.5 px-3"><div className="h-3.5 bg-neutral-100 rounded w-14" /></td>
                      <td className="py-3.5 px-3"><div className="h-3.5 bg-neutral-100 rounded w-20" /></td>
                      <td className="py-3.5 px-3"><div className="h-3.5 bg-neutral-100 rounded w-16" /></td>
                      <td className="py-3.5 px-3"><div className="h-3.5 bg-neutral-100 rounded w-24" /></td>
                      <td className="py-3.5 px-3 text-center"><div className="h-3.5 bg-neutral-100 rounded w-16 mx-auto" /></td>
                      <td className="py-3.5 px-3 text-center"><div className="h-3.5 bg-neutral-100 rounded w-16 mx-auto" /></td>
                      <td className="py-3.5 px-3"><div className="h-3.5 bg-neutral-100 rounded w-16" /></td>
                      <td className="py-3.5 px-3"><div className="h-3.5 bg-neutral-100 rounded w-16" /></td>
                      <td className="py-3.5 px-3 text-center"><div className="h-3.5 bg-neutral-100 rounded w-14 mx-auto" /></td>
                      <td className="py-3.5 px-3 text-center"><div className="h-3.5 bg-neutral-100 rounded w-16 mx-auto" /></td>
                      <td className="py-3.5 px-3"><div className="h-3.5 bg-neutral-100 rounded w-20" /></td>
                      <td className="py-3.5 px-4 text-right sticky right-0 bg-white">
                        <div className="h-4 bg-neutral-100 rounded w-20 ml-auto" />
                      </td>
                    </tr>
                  ))
                ) : amenities.length === 0 ? (
                  <tr>
                    <td colSpan={13} className="py-16 text-center text-neutral-400">
                      <div className="flex flex-col items-center justify-center gap-2">
                        <Building2 className="w-10 h-10 text-neutral-300 stroke-1" />
                        {isSearching ? (
                          <>
                            <p className="text-sm font-semibold text-neutral-700">Đang tìm kiếm tiện ích...</p>
                            <p className="text-xs text-neutral-400">Hệ thống đang đối soát dữ liệu với từ khóa của bạn.</p>
                          </>
                        ) : searchInput.trim() ? (
                          <>
                            <p className="text-sm font-semibold text-neutral-700">
                              Không tìm thấy tiện ích phù hợp với &quot;{searchInput}&quot;
                            </p>
                            <p className="text-xs text-neutral-400 max-w-sm">
                              Thử kiểm tra lỗi chính tả hoặc bấm nút bên dưới để quay lại danh sách đầy đủ.
                            </p>
                            <button
                              type="button"
                              onClick={handleClearSearch}
                              className="mt-2 px-3.5 py-1.5 bg-neutral-900 text-white text-xs font-semibold rounded-xl hover:bg-neutral-800 transition-colors cursor-pointer shadow-xs"
                            >
                              Xóa tìm kiếm
                            </button>
                          </>
                        ) : (
                          <>
                            <p className="text-sm font-semibold text-neutral-700">Không tìm thấy tiện ích nào</p>
                            <p className="text-xs text-neutral-400 max-w-sm">
                              Thử thay đổi từ khóa tìm kiếm, bộ lọc danh mục hoặc bấm nút &quot;Thêm tiện ích&quot; để tạo mới.
                            </p>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ) : (
                  amenities.map((item) => (
                    <tr
                      key={item.id}
                      className="hover:bg-neutral-50/70 transition-colors group"
                    >
                      {/* 1. Tên tiện ích */}
                      <td className="py-3.5 px-4 font-bold text-neutral-900 min-w-[220px]">
                        <div className="flex items-center gap-2.5">
                          {item.cover_image_url ? (
                            <img
                              src={item.cover_image_url}
                              alt={item.amenity_name}
                              className="w-8 h-8 rounded-lg object-cover border border-neutral-200 shrink-0"
                            />
                          ) : (
                            <div className="w-8 h-8 rounded-lg bg-neutral-100 text-neutral-600 flex items-center justify-center shrink-0">
                              <Building2 className="w-4 h-4" />
                            </div>
                          )}
                          <div className="min-w-0 flex-1">
                            <div className="leading-snug text-neutral-950 font-semibold">{renderHighlightedText(item.amenity_name, debouncedSearch)}</div>
                            <div className="text-[10px] font-normal text-neutral-400 mt-0.5 whitespace-nowrap">
                              {item.active_time_slots_count} slot đang mở
                            </div>
                          </div>
                        </div>
                      </td>

                      {/* 2. Mã tiện ích */}
                      <td className="py-3.5 px-3 font-mono font-bold text-neutral-700 whitespace-nowrap">
                        <span className="px-1.5 py-0.5 rounded bg-neutral-100 border border-neutral-200/80">
                          {renderHighlightedText(item.amenity_code, debouncedSearch)}
                        </span>
                      </td>

                      {/* 3. Danh mục */}
                      <td className="py-3.5 px-3 text-neutral-700 whitespace-nowrap">
                        {item.category_name || '-'}
                      </td>

                      {/* 4. Tòa nhà */}
                      <td className="py-3.5 px-3 text-neutral-600 whitespace-nowrap">
                        {item.block_name || (
                          <span className="text-neutral-400 italic">Dùng chung</span>
                        )}
                      </td>

                      {/* 5. Vị trí */}
                      <td className="py-3.5 px-3 text-neutral-600 min-w-[160px] leading-snug" title={item.location_detail}>
                        {item.location_detail}
                      </td>

                      {/* 6. Sức chứa / slot */}
                      <td className="py-3.5 px-3 text-center whitespace-nowrap">
                        <span className="inline-flex items-center px-2.5 py-0.5 bg-neutral-100 font-mono font-semibold text-neutral-900 rounded-full text-xs">
                          {item.max_capacity_per_slot} người
                        </span>
                      </td>

                      {/* 7. Số booking thực tế / slot */}
                      <td className="py-3.5 px-3 text-center whitespace-nowrap">
                        <button
                          type="button"
                          onClick={() => setBookingModalAmenity(item)}
                          className="inline-flex items-center gap-1.5 px-2.5 py-0.5 bg-sky-50 hover:bg-sky-100 text-sky-800 border border-sky-200/80 font-mono font-semibold rounded-full text-xs transition-colors cursor-pointer"
                          title="Bấm để xem thông tin đặt chỗ của cư dân"
                        >
                          <Ticket className="w-3 h-3 text-sky-600" />
                          <span>{item.active_bookings_count ?? 0} đang đặt</span>
                        </button>
                      </td>

                      {/* 8. Giá / giờ */}
                      <td className="py-3.5 px-3 font-mono font-semibold text-neutral-900 whitespace-nowrap">
                        {formatCurrency(item.hourly_rate)}
                      </td>

                      {/* 9. Tiền cọc */}
                      <td className="py-3.5 px-3 font-mono text-neutral-600 whitespace-nowrap">
                        {formatCurrency(item.security_deposit_required)}
                      </td>

                      {/* 10. Yêu cầu duyệt */}
                      <td className="py-3.5 px-3 text-center whitespace-nowrap">
                        {item.requires_admin_approval ? (
                          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-800 border border-amber-200 whitespace-nowrap">
                            Cần duyệt
                          </span>
                        ) : (
                          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-medium bg-neutral-100 text-neutral-600 whitespace-nowrap">
                            Tự động
                          </span>
                        )}
                      </td>

                      {/* 11. Trạng thái */}
                      <td className="py-3.5 px-3 text-center whitespace-nowrap">
                        {item.is_active ? (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 whitespace-nowrap">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                            <span>Hoạt động</span>
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-neutral-100 text-neutral-600 border border-neutral-200 whitespace-nowrap">
                            <span className="w-1.5 h-1.5 rounded-full bg-neutral-400" />
                            <span>Tạm ngưng</span>
                          </span>
                        )}
                      </td>

                      {/* 12. Ngày cập nhật */}
                      <td className="py-3.5 px-3 text-[11px] text-neutral-500 whitespace-nowrap">
                        {formatDate(item.updated_at)}
                      </td>

                      {/* 13. Actions */}
                      <td className="py-3.5 px-4 text-right whitespace-nowrap sticky right-0 bg-white group-hover:bg-neutral-50 border-l border-neutral-100 shadow-[-6px_0_12px_rgba(0,0,0,0.04)] z-10">
                        <div className="flex items-center justify-end gap-1">
                          {/* Xem thông tin đặt chỗ */}
                          <button
                            type="button"
                            onClick={() => setBookingModalAmenity(item)}
                            className="p-1.5 text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 rounded-lg transition-colors cursor-pointer"
                            title="Xem thông tin đặt chỗ của cư dân"
                          >
                            <Ticket className="w-3.5 h-3.5" />
                          </button>

                          {/* Xem chi tiết */}
                          <button
                            type="button"
                            onClick={() => setDetailAmenity(item)}
                            className="p-1.5 text-neutral-500 hover:text-neutral-900 hover:bg-neutral-100 rounded-lg transition-colors cursor-pointer"
                            title="Xem chi tiết & quy chế"
                          >
                            <Eye className="w-3.5 h-3.5" />
                          </button>

                          {/* Chỉnh sửa */}
                          <button
                            type="button"
                            onClick={() => handleOpenEdit(item)}
                            className="p-1.5 text-neutral-500 hover:text-neutral-900 hover:bg-neutral-100 rounded-lg transition-colors cursor-pointer"
                            title="Chỉnh sửa cấu hình"
                          >
                            <Edit className="w-3.5 h-3.5" />
                          </button>

                          {/* Cấu hình slot */}
                          <button
                            type="button"
                            onClick={() => setSlotModalAmenity(item)}
                            className="p-1.5 text-sky-600 hover:text-sky-800 hover:bg-sky-50 rounded-lg transition-colors cursor-pointer"
                            title="Cấu hình khung giờ (Time Slots)"
                          >
                            <Clock className="w-3.5 h-3.5" />
                          </button>

                          {/* Ngày đóng cửa */}
                          <button
                            type="button"
                            onClick={() => setBlackoutModalAmenity(item)}
                            className="p-1.5 text-amber-600 hover:text-amber-800 hover:bg-amber-50 rounded-lg transition-colors cursor-pointer"
                            title="Ngày đóng cửa bảo trì"
                          >
                            <CalendarOff className="w-3.5 h-3.5" />
                          </button>

                          {/* Bật/Tắt trạng thái */}
                          <button
                            type="button"
                            onClick={() => handleToggleStatus(item)}
                            className={`p-1.5 rounded-lg transition-colors cursor-pointer ${
                              item.is_active
                                ? 'text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50'
                                : 'text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100'
                            }`}
                            title={item.is_active ? 'Đang hoạt động • Bấm để tạm ngưng' : 'Đang tạm ngưng • Bấm để kích hoạt'}
                          >
                            <Power className="w-3.5 h-3.5" />
                          </button>

                          {/* Xóa */}
                          <button
                            type="button"
                            onClick={() => handleDeleteAmenity(item)}
                            className="p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer"
                            title="Xóa tiện ích"
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

          {/* Pagination Footer */}
          <div className="p-4 border-t border-neutral-200/80 bg-neutral-50/50 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-neutral-600">
            <div>
              Hiển thị <span className="font-semibold text-neutral-900">{amenities.length}</span> trên tổng số{' '}
              <span className="font-semibold text-neutral-900">{total}</span> tiện ích
            </div>

            <div className="flex items-center gap-2">
              <button
                type="button"
                disabled={page <= 1 || loading}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                className="px-3 py-1.5 rounded-lg border border-neutral-200 bg-white hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1 transition-all shadow-2xs cursor-pointer"
              >
                <ChevronLeft className="w-3.5 h-3.5" />
                <span>Trước</span>
              </button>

              <span className="px-3 py-1.5 font-medium text-neutral-700">
                Trang {page} / {totalPages}
              </span>

              <button
                type="button"
                disabled={page >= totalPages || loading}
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                className="px-3 py-1.5 rounded-lg border border-neutral-200 bg-white hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1 transition-all shadow-2xs cursor-pointer"
              >
                <span>Sau</span>
                <ChevronRight className="w-3.5 h-3.5" />
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Amenity Detail Modal Drawer */}
      {detailAmenity && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
          <div onClick={() => setDetailAmenity(null)} className="fixed inset-0 bg-neutral-950/45 backdrop-blur-sm animate-in fade-in" />
          <div className="relative w-full max-w-lg bg-white/95 backdrop-blur-2xl border border-white/90 rounded-2xl shadow-2xl p-6 text-neutral-900 z-10 animate-in zoom-in-95">
            <div className="flex items-center justify-between pb-3 border-b border-neutral-200/80">
              <div className="flex items-center gap-2">
                <Building2 className="w-5 h-5 text-neutral-900" />
                <h3 className="font-bold text-base text-neutral-950">{detailAmenity.amenity_name}</h3>
              </div>
              <button
                type="button"
                onClick={() => setDetailAmenity(null)}
                className="p-1 text-neutral-400 hover:text-neutral-800 rounded-lg"
              >
                <XCircle className="w-5 h-5" />
              </button>
            </div>

            <div className="mt-4 space-y-3 text-xs">
              <div className="grid grid-cols-2 gap-2 p-3 bg-neutral-50 rounded-xl">
                <div>
                  <span className="text-neutral-500">Mã tiện ích:</span>{' '}
                  <span className="font-mono font-bold text-neutral-900">{detailAmenity.amenity_code}</span>
                </div>
                <div>
                  <span className="text-neutral-500">Danh mục:</span>{' '}
                  <span className="font-semibold text-neutral-900">{detailAmenity.category_name}</span>
                </div>
                <div>
                  <span className="text-neutral-500">Tòa nhà:</span>{' '}
                  <span className="font-semibold text-neutral-900">{detailAmenity.block_name || 'Toàn khu'}</span>
                </div>
                <div>
                  <span className="text-neutral-500">Vị trí:</span>{' '}
                  <span className="font-semibold text-neutral-900">{detailAmenity.location_detail}</span>
                </div>
                <div>
                  <span className="text-neutral-500">Sức chứa tối đa:</span>{' '}
                  <span className="font-semibold text-neutral-900">{detailAmenity.max_capacity_per_slot} người/lượt</span>
                </div>
                <div>
                  <span className="text-neutral-500">Giá thuê:</span>{' '}
                  <span className="font-semibold text-neutral-900">{formatCurrency(detailAmenity.hourly_rate)}</span>
                </div>
                <div>
                  <span className="text-neutral-500">Tiền đặt cọc:</span>{' '}
                  <span className="font-semibold text-neutral-900">{formatCurrency(detailAmenity.security_deposit_required)}</span>
                </div>
                <div>
                  <span className="text-neutral-500">Duyệt BQL:</span>{' '}
                  <span className="font-semibold text-neutral-900">
                    {detailAmenity.requires_admin_approval ? 'Bắt buộc duyệt' : 'Tự động duyệt'}
                  </span>
                </div>
              </div>

              {detailAmenity.cover_image_url && (
                <div className="rounded-xl overflow-hidden border border-neutral-200 aspect-video bg-neutral-100 flex items-center justify-center">
                  <img
                    src={detailAmenity.cover_image_url}
                    alt={detailAmenity.amenity_name}
                    className="w-full h-full object-cover"
                    onError={(e) => {
                      (e.target as HTMLElement).style.display = 'none';
                    }}
                  />
                </div>
              )}

              {detailAmenity.gallery_images && detailAmenity.gallery_images.length > 0 && (
                <div className="p-3 bg-neutral-50 rounded-xl space-y-2">
                  <span className="font-bold text-neutral-800 block text-xs">
                    Bộ sưu tập hình ảnh ({detailAmenity.gallery_images.length}):
                  </span>
                  <div className="grid grid-cols-3 gap-2">
                    {detailAmenity.gallery_images.map((imgUrl, idx) => (
                      <div
                        key={idx}
                        className="rounded-lg overflow-hidden border border-neutral-200 aspect-video bg-neutral-200"
                      >
                        <img
                          src={imgUrl}
                          alt={`Gallery ${idx + 1}`}
                          className="w-full h-full object-cover"
                          onError={(e) => {
                            (e.target as HTMLElement).style.display = 'none';
                          }}
                        />
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {detailAmenity.rules_and_regulations && (
                <div className="p-3 bg-neutral-50 rounded-xl">
                  <span className="font-bold text-neutral-800 block mb-1">Nội quy & Quy chế sử dụng:</span>
                  <p className="text-neutral-600 whitespace-pre-line leading-relaxed">
                    {detailAmenity.rules_and_regulations}
                  </p>
                </div>
              )}
            </div>

            <div className="mt-5 flex items-center justify-between gap-2">
              <button
                type="button"
                onClick={() => {
                  const target = detailAmenity;
                  setDetailAmenity(null);
                  setBookingModalAmenity(target);
                }}
                className="px-3.5 py-2 text-xs font-semibold text-sky-800 bg-sky-50 hover:bg-sky-100 border border-sky-200 rounded-xl transition-colors cursor-pointer flex items-center gap-1.5"
              >
                <Ticket className="w-3.5 h-3.5 text-sky-600" />
                <span>Xem {detailAmenity.active_bookings_count ?? 0} lượt đặt chỗ thực tế</span>
              </button>

              <button
                type="button"
                onClick={() => setDetailAmenity(null)}
                className="px-4 py-2 text-xs font-semibold text-neutral-700 hover:bg-neutral-100 rounded-lg transition-colors cursor-pointer"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modals */}
      <AmenityBookingsModal
        isOpen={!!bookingModalAmenity}
        amenity={bookingModalAmenity}
        onClose={() => setBookingModalAmenity(null)}
      />

      <AmenityFormModal
        isOpen={isFormOpen}
        amenity={editingAmenity}
        categories={categories}
        blocks={blocks}
        onClose={() => setIsFormOpen(false)}
        onSuccess={() => {
          showToast(editingAmenity ? 'Đã cập nhật cấu hình tiện ích!' : 'Đã tạo tiện ích mới thành công!');
          fetchAmenities(true);
        }}
      />

      <CategoryModal
        isOpen={isCategoryModalOpen}
        onClose={() => setIsCategoryModalOpen(false)}
        onChanged={() => {
          loadMetadata(true);
          fetchAmenities(true);
        }}
      />

      <TimeSlotModal
        isOpen={!!slotModalAmenity}
        amenity={slotModalAmenity}
        onClose={() => setSlotModalAmenity(null)}
        onChanged={() => fetchAmenities(true)}
      />

      <BlackoutModal
        isOpen={!!blackoutModalAmenity}
        amenity={blackoutModalAmenity}
        onClose={() => setBlackoutModalAmenity(null)}
        onChanged={() => fetchAmenities(true)}
      />

      <ConfirmDialog
        isOpen={confirmDialog.isOpen}
        title={confirmDialog.title}
        message={confirmDialog.message}
        isDangerous={confirmDialog.isDangerous}
        isLoading={isConfirmLoading}
        onConfirm={confirmDialog.action}
        onCancel={() => setConfirmDialog((prev) => ({ ...prev, isOpen: false }))}
      />

      {/* Admin Authentication Modal */}
      {isAuthModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-md">
          <div className="relative w-full max-w-md bg-white rounded-2xl shadow-2xl p-6 border border-neutral-100 animate-in zoom-in-95">
            <div className="flex items-center justify-between pb-3 border-b border-neutral-100">
              <div className="flex items-center gap-2">
                <Shield className="w-5 h-5 text-neutral-900" />
                <h3 className="font-bold text-base text-neutral-900">Xác thực Quản Trị Viên</h3>
              </div>
              {currentUser && (
                <button
                  type="button"
                  onClick={() => setIsAuthModalOpen(false)}
                  className="text-neutral-400 hover:text-neutral-700 p-1"
                >
                  <XCircle className="w-5 h-5" />
                </button>
              )}
            </div>

            <form onSubmit={handleAdminLogin} className="mt-4 space-y-3.5">
              <p className="text-xs text-neutral-500 leading-relaxed">
                Vui lòng đăng nhập bằng tài khoản Quản trị viên (hoặc Ban Quản Lý) để truy cập và cấu hình dịch vụ tiện ích của tòa nhà.
              </p>

              {authError && (
                <div className="p-3 bg-rose-50 border border-rose-200 text-rose-800 text-xs rounded-xl flex items-center gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  <span>{authError}</span>
                </div>
              )}

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Tài khoản (Email / Số điện thoại)
                </label>
                <input
                  type="text"
                  required
                  value={authEmail}
                  onChange={(e) => setAuthEmail(e.target.value)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-xl bg-neutral-50 focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
                  placeholder="admin@smartcassavas.vn"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Mật khẩu
                </label>
                <input
                  type="password"
                  required
                  value={authPassword}
                  onChange={(e) => setAuthPassword(e.target.value)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-xl bg-neutral-50 focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
                  placeholder="••••••••"
                />
              </div>

              <div className="pt-2">
                <button
                  type="submit"
                  disabled={isAuthSubmitting}
                  className="w-full py-2.5 bg-neutral-950 hover:bg-neutral-800 text-white text-xs font-semibold rounded-xl flex items-center justify-center gap-2 transition-all shadow-md cursor-pointer disabled:opacity-50"
                >
                  {isAuthSubmitting ? (
                    <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                  ) : (
                    <LogIn className="w-4 h-4" />
                  )}
                  <span>Đăng nhập hệ thống</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default AmenityManagement;
