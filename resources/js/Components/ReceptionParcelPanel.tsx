import React, { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import {
  Package,
  Search,
  Filter,
  Plus,
  RefreshCw,
  Clock,
  AlertTriangle,
  CheckCircle2,
  Calendar,
  Building,
  User,
  Phone,
  Truck,
  Camera,
  Upload,
  X,
  Eye,
  Edit2,
  QrCode,
  KeyRound,
  MapPin,
  FileText,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  ChevronUp,
  Check,
  Loader2,
  Download,
  ShieldCheck,
  Maximize2,
  Sparkles,
} from 'lucide-react';
import api, { ParcelItem } from '../Services/api';
import { useRealtimeSync } from '../Hooks/useRealtimeSync';

export interface ReceptionParcelPanelProps {
  embedded?: boolean;
}

const PARCEL_CACHE_STORAGE_KEY = 'smartcassavas_reception_parcels_cache_v1';

const getInitialParcelCache = () => {
  try {
    const raw = localStorage.getItem(PARCEL_CACHE_STORAGE_KEY);
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

export const ReceptionParcelPanel: React.FC<ReceptionParcelPanelProps> = ({ embedded = false }) => {
  const initialCache = useMemo(() => getInitialParcelCache(), []);

  // 1. Data States (Hiện liền 0ms từ cache)
  const [parcels, setParcels] = useState<ParcelItem[]>(() => initialCache?.data ?? []);
  const [kpis, setKpis] = useState(() => initialCache?.kpis ?? {
    pending_parcels: 0,
    overdue_parcels: 0,
    received_today: 0,
    total_parcels: 0,
  });
  const [pagination, setPagination] = useState(() => initialCache?.pagination ?? {
    current_page: 1,
    per_page: 15,
    total_items: 0,
    total_pages: 1,
    has_more: false,
  });
  const [loading, setLoading] = useState<boolean>(() => !initialCache);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // 2. Filter & Search States
  const [searchTerm, setSearchTerm] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'ALL' | 'PENDING' | 'OVERDUE' | 'DELIVERED'>('ALL');
  const [courierFilter, setCourierFilter] = useState<string>('ALL');
  const [apartmentFilter, setApartmentFilter] = useState<string>('');
  const [fromDateFilter, setFromDateFilter] = useState<string>('');
  const [toDateFilter, setToDateFilter] = useState<string>('');
  const [currentPage, setCurrentPage] = useState(1);

  // Custom Dropdown Open States (Chuẩn Ảnh 2)
  const [isStatusDropdownOpen, setIsStatusDropdownOpen] = useState<boolean>(false);
  const [isCourierDropdownOpen, setIsCourierDropdownOpen] = useState<boolean>(false);
  const statusDropdownRef = useRef<HTMLDivElement>(null);
  const courierDropdownRef = useRef<HTMLDivElement>(null);

  // Click outside to close dropdowns
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (statusDropdownRef.current && !statusDropdownRef.current.contains(event.target as Node)) {
        setIsStatusDropdownOpen(false);
      }
      if (courierDropdownRef.current && !courierDropdownRef.current.contains(event.target as Node)) {
        setIsCourierDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, []);

  // 3. Modal States
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isLightboxOpen, setIsLightboxOpen] = useState(false);
  const [activeParcel, setActiveParcel] = useState<ParcelItem | null>(null);
  const [lightboxImage, setLightboxImage] = useState<string | null>(null);

  // 4. Create Form States
  const [formData, setFormData] = useState({
    tracking_number: '',
    courier_company: 'Shopee Express',
    apartment_id: '',
    recipient_name: '',
    recipient_phone: '',
    recipient_user_id: '',
    stored_location_type: 'RECEPTION_DESK',
    notes: '',
  });
  const [selectedPhotoFile, setSelectedPhotoFile] = useState<File | null>(null);
  const [photoPreviewUrl, setPhotoPreviewUrl] = useState<string | null>(null);
  const [formSubmitting, setFormSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  // Autocomplete / Options States
  const [apartmentOptions, setApartmentOptions] = useState<any[]>([]);
  const [recipientSuggestions, setRecipientSuggestions] = useState<any[]>([]);
  const [loadingApartments, setLoadingApartments] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Common Courier Companies
  const courierOptions = [
    'Shopee Express',
    'SPX Express',
    'Giao Hàng Tiết Kiệm (GHTK)',
    'Giao Hàng Nhanh (GHN)',
    'Viettel Post',
    'J&T Express',
    'GrabExpress',
    'Ninja Van',
    'Bưu điện VNPost',
    'BeDelivery',
    'Khác',
  ];

  const showToast = useCallback((msg: string) => {
    setToastMessage(msg);
    setTimeout(() => {
      setToastMessage((prev) => (prev === msg ? null : prev));
    }, 4500);
  }, []);

  // Debounce search input
  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedSearch(searchTerm);
      setCurrentPage(1);
    }, 350);
    return () => clearTimeout(handler);
  }, [searchTerm]);

  // Load Apartments for selection
  const loadApartments = useCallback(async (query: string = '') => {
    setLoadingApartments(true);
    try {
      const res = await api.searchParcelApartments(query);
      if (res.success) {
        setApartmentOptions(res.data || []);
      }
    } catch {
      // fallback
    } finally {
      setLoadingApartments(false);
    }
  }, []);

  useEffect(() => {
    loadApartments();
  }, [loadApartments]);

  // Fetch Parcels
  const fetchParcels = useCallback(
    async (page: number = 1, showSpinner: boolean = true) => {
      if (showSpinner) setLoading(true);
      try {
        const res = await api.getReceptionParcels({
          page,
          limit: 15,
          search: debouncedSearch,
          status: statusFilter,
          courier_company: courierFilter !== 'ALL' ? courierFilter : undefined,
          apartment_id: apartmentFilter || undefined,
          from_date: fromDateFilter || undefined,
          to_date: toDateFilter || undefined,
        });

        if (res.success) {
          setParcels(res.data || []);
          if (res.kpis) {
            setKpis(res.kpis);
          }
          if (res.pagination) {
            setPagination(res.pagination);
          }
          if (page === 1 && !debouncedSearch && statusFilter === 'ALL' && courierFilter === 'ALL' && !apartmentFilter) {
            try {
              localStorage.setItem(PARCEL_CACHE_STORAGE_KEY, JSON.stringify({
                data: res.data || [],
                kpis: res.kpis,
                pagination: res.pagination,
              }));
            } catch {
              // ignore
            }
          }
        }
      } catch (err: any) {
        showToast(err.message || 'Không thể tải danh sách bưu phẩm');
      } finally {
        if (showSpinner) setLoading(false);
      }
    },
    [debouncedSearch, statusFilter, courierFilter, apartmentFilter, fromDateFilter, toDateFilter, showToast]
  );

  useEffect(() => {
    fetchParcels(currentPage);
  }, [fetchParcels, currentPage]);

  // Realtime Integration
  const handleRealtimeEvent = useCallback(
    (event: any) => {
      if (!event) return;
      if (event.module === 'parcels' || event.entity === 'parcel') {
        fetchParcels(currentPage, false);
        if (event.action === 'CREATED') {
          showToast(
            `⚡ Realtime: Kiện hàng [${event.tracking_number || 'mới'}] cho căn hộ ${
              event.apartment_number || ''
            } vừa được tiếp nhận tại quầy!`
          );
        } else if (event.action === 'UPDATED') {
          showToast(`⚡ Realtime: Kiện hàng [${event.tracking_number || ''}] vừa được cập nhật.`);
        }
      }
    },
    [fetchParcels, currentPage, showToast]
  );

  useRealtimeSync({
    channel: 'quoc-tin.parcels',
    onEvent: handleRealtimeEvent,
    onReconnect: () => {
      fetchParcels(currentPage, false);
    },
  });

  // Handle Apartment Change in Create Form -> fetch resident suggestions
  const handleApartmentChange = async (aptId: string) => {
    setFormData((prev) => ({ ...prev, apartment_id: aptId, recipient_name: '', recipient_phone: '', recipient_user_id: '' }));
    if (!aptId) {
      setRecipientSuggestions([]);
      return;
    }
    try {
      const res = await api.searchParcelRecipients('', aptId);
      if (res.success && res.data) {
        setRecipientSuggestions(res.data);
        if (res.data.length === 1) {
          const defaultRec = res.data[0];
          setFormData((prev) => ({
            ...prev,
            apartment_id: aptId,
            recipient_name: defaultRec.full_name || '',
            recipient_phone: defaultRec.phone_number || '',
            recipient_user_id: defaultRec.id || '',
          }));
        }
      }
    } catch {
      setRecipientSuggestions([]);
    }
  };

  // Handle Photo selection & preview
  const handlePhotoSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
      setFormError('Dung lượng ảnh bưu kiện không được vượt quá 10MB.');
      return;
    }

    const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!validTypes.includes(file.type)) {
      setFormError('Định dạng ảnh không hợp lệ. Vui lòng chọn ảnh JPG, PNG hoặc WebP.');
      return;
    }

    setFormError(null);
    setSelectedPhotoFile(file);
    const objectUrl = URL.createObjectURL(file);
    setPhotoPreviewUrl(objectUrl);
  };

  const handleClearPhoto = () => {
    if (photoPreviewUrl) {
      URL.revokeObjectURL(photoPreviewUrl);
    }
    setSelectedPhotoFile(null);
    setPhotoPreviewUrl(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  // Submit Create Parcel
  const handleCreateParcel = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);

    if (!formData.tracking_number.trim()) {
      setFormError('Vui lòng nhập Mã vận đơn của kiện hàng.');
      return;
    }
    if (!formData.apartment_id) {
      setFormError('Vui lòng chọn Căn hộ nhận bưu phẩm.');
      return;
    }
    if (!formData.recipient_name.trim()) {
      setFormError('Vui lòng nhập Tên người nhận.');
      return;
    }
    if (!formData.recipient_phone.trim()) {
      setFormError('Vui lòng nhập Số điện thoại người nhận.');
      return;
    }
    if (!selectedPhotoFile) {
      setFormError('Vui lòng chụp hoặc tải lên Ảnh thực tế của kiện hàng bưu phẩm.');
      return;
    }

    setFormSubmitting(true);
    try {
      const formPayload = new FormData();
      formPayload.append('tracking_number', formData.tracking_number.trim());
      formPayload.append('courier_company', formData.courier_company);
      formPayload.append('apartment_id', formData.apartment_id);
      formPayload.append('recipient_name', formData.recipient_name.trim());
      formPayload.append('recipient_phone', formData.recipient_phone.trim());
      formPayload.append('stored_location_type', formData.stored_location_type);
      if (formData.recipient_user_id) {
        formPayload.append('recipient_user_id', formData.recipient_user_id);
      }
      if (formData.notes) {
        formPayload.append('notes', formData.notes.trim());
      }
      formPayload.append('photo', selectedPhotoFile);

      const res = await api.receiveParcel(formPayload);
      if (res.success) {
        showToast(`✅ Đã tiếp nhận thành công kiện hàng [${res.data.tracking_number}]!`);
        setIsCreateModalOpen(false);
        // Reset form
        setFormData({
          tracking_number: '',
          courier_company: 'Shopee Express',
          apartment_id: '',
          recipient_name: '',
          recipient_phone: '',
          recipient_user_id: '',
          stored_location_type: 'RECEPTION_DESK',
          notes: '',
        });
        handleClearPhoto();
        fetchParcels(1, true);
      }
    } catch (err: any) {
      setFormError(err.message || 'Không thể tạo phiếu tiếp nhận kiện hàng.');
    } finally {
      setFormSubmitting(false);
    }
  };

  // Quick Random tracking number for testing
  const handleGenerateSampleTracking = () => {
    const prefixes = ['SPX', 'GHTK', 'VN', 'JT', 'GHN'];
    const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
    const code = `${prefix}${Math.floor(100000000 + Math.random() * 900000000)}`;
    setFormData((prev) => ({ ...prev, tracking_number: code }));
  };

  // Helper date format
  const formatDateTime = (dateStr?: string | null) => {
    if (!dateStr) return '—';
    try {
      const d = new Date(dateStr);
      return d.toLocaleString('vi-VN', {
        hour: '2-digit',
        minute: '2-digit',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
      });
    } catch {
      return dateStr;
    }
  };

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* Toast Notification (Chuẩn phong cách RFID Management) */}
      {toastMessage && (
        <div className="fixed top-5 right-5 z-[9999] flex items-center gap-2 px-4 py-3 rounded-lg shadow-xl text-sm font-medium border bg-emerald-50 text-emerald-900 border-emerald-300">
          <CheckCircle2 className="w-5 h-5 text-emerald-600" />
          <span>{toastMessage}</span>
          <button
            type="button"
            onClick={() => setToastMessage(null)}
            className="ml-2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* 1. Header & Title (Đồng bộ chuẩn RFID / Visitor Management - Bỏ nhãn MODULE #9) */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-indigo-600">
            <Package className="w-4 h-4 text-indigo-600" />
            <span>CỔNG TIẾP ĐÓN LỄ TÂN · TIẾP NHẬN BƯU KIỆN</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Tiếp Nhận Kiện Hàng & Upload Ảnh Bưu Kiện
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Ghi nhận bưu phẩm gửi đến cư dân, upload ảnh chụp thực tế và đồng bộ realtime tức thời.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={() => fetchParcels(currentPage, true)}
            disabled={loading}
            className="flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition shadow-sm cursor-pointer disabled:opacity-50"
            title="Làm mới dữ liệu từ server"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin text-indigo-600' : ''}`} />
            <span>Tải lại</span>
          </button>

          <button
            type="button"
            onClick={() => {
              setFormError(null);
              setIsCreateModalOpen(true);
            }}
            className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition shadow-sm cursor-pointer"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>Tiếp nhận kiện hàng mới</span>
          </button>
        </div>
      </div>

      {/* 2. KPI Statistics Cards (Đồng bộ 100% chuẩn RFID Management) */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {/* KPI 1: Chờ cư dân nhận */}
        <div
          onClick={() => {
            setStatusFilter('PENDING');
            setCurrentPage(1);
          }}
          className={`bg-white p-4 sm:p-5 rounded-2xl border shadow-sm flex items-center gap-4 cursor-pointer transition ${
            statusFilter === 'PENDING'
              ? 'border-amber-400 ring-2 ring-amber-100'
              : 'border-slate-200/80 hover:border-amber-300'
          }`}
        >
          <div className="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <Package className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Chờ cư dân nhận</div>
            <div className="text-2xl font-black text-slate-900">{kpis.pending_parcels}</div>
            <div className="text-[11px] text-amber-600 font-semibold mt-0.5">Đang lưu tại quầy & locker</div>
          </div>
        </div>

        {/* KPI 2: Tồn đọng > 5 ngày */}
        <div
          onClick={() => {
            setStatusFilter('OVERDUE');
            setCurrentPage(1);
          }}
          className={`bg-white p-4 sm:p-5 rounded-2xl border shadow-sm flex items-center gap-4 cursor-pointer transition ${
            statusFilter === 'OVERDUE'
              ? 'border-rose-400 ring-2 ring-rose-100'
              : 'border-slate-200/80 hover:border-rose-300'
          }`}
        >
          <div className="w-12 h-12 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center flex-shrink-0">
            <AlertTriangle className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tồn đọng &gt; 5 ngày</div>
            <div className="text-2xl font-black text-rose-600">{kpis.overdue_parcels}</div>
            <div className="text-[11px] text-rose-600 font-semibold mt-0.5">Cần gọi nhắc cư dân</div>
          </div>
        </div>

        {/* KPI 3: Tiếp nhận hôm nay */}
        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <CheckCircle2 className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tiếp nhận hôm nay</div>
            <div className="text-2xl font-black text-emerald-700">{kpis.received_today}</div>
            <div className="text-[11px] text-emerald-600 font-semibold mt-0.5">Giao dịch trong ngày</div>
          </div>
        </div>

        {/* KPI 4: Tổng lượt bưu phẩm */}
        <div
          onClick={() => {
            setStatusFilter('ALL');
            setCurrentPage(1);
          }}
          className={`bg-white p-4 sm:p-5 rounded-2xl border shadow-sm flex items-center gap-4 cursor-pointer transition ${
            statusFilter === 'ALL'
              ? 'border-purple-400 ring-2 ring-purple-100'
              : 'border-slate-200/80 hover:border-purple-300'
          }`}
        >
          <div className="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center flex-shrink-0">
            <Truck className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tổng lượt bưu phẩm</div>
            <div className="text-2xl font-black text-purple-700">{kpis.total_parcels}</div>
            <div className="text-[11px] text-purple-600 font-semibold mt-0.5">Toàn bộ lịch sử hệ thống</div>
          </div>
        </div>
      </div>

      {/* 3. Filter & Search Bar (Đồng bộ chuẩn RFID Management) */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          {/* Search Input */}
          <div className="relative lg:col-span-2">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Tìm theo Mã vận đơn, Căn hộ, Tên hoặc SĐT người nhận..."
              className="w-full pl-9 pr-8 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white text-slate-800 placeholder-slate-400"
            />
            {searchTerm && (
              <button
                type="button"
                onClick={() => setSearchTerm('')}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 cursor-pointer"
                title="Xóa tìm kiếm"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>

          {/* Đơn vị vận chuyển (Courier) Dropdown chuẩn Ảnh 2 */}
          <div className="relative" ref={courierDropdownRef}>
            <button
              type="button"
              onClick={() => {
                setIsCourierDropdownOpen((prev) => !prev);
                setIsStatusDropdownOpen(false);
              }}
              className={`w-full px-3 py-2 text-xs font-semibold rounded-xl border transition-all flex items-center justify-between cursor-pointer ${
                isCourierDropdownOpen
                  ? 'border-indigo-500 ring-2 ring-indigo-200 bg-white text-indigo-900 shadow-xs'
                  : 'border-slate-200 hover:border-slate-300 bg-white text-slate-700'
              }`}
            >
              <div className="flex items-center gap-2 truncate">
                <Truck className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                <span className="truncate">
                  {courierFilter === 'ALL' ? 'Tất cả ĐVVC' : courierFilter}
                </span>
              </div>
              {isCourierDropdownOpen ? (
                <ChevronUp className="w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5" />
              ) : (
                <ChevronDown className="w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5" />
              )}
            </button>

            {/* Courier Dropdown Menu Popup */}
            {isCourierDropdownOpen && (
              <div className="absolute z-50 left-0 right-0 mt-1.5 bg-white rounded-2xl shadow-2xl border border-slate-100 p-1.5 space-y-1 min-w-[200px] max-h-64 overflow-y-auto animate-in fade-in zoom-in-95 duration-100">
                <div
                  onClick={() => {
                    setCourierFilter('ALL');
                    setCurrentPage(1);
                    setIsCourierDropdownOpen(false);
                  }}
                  className={`rounded-xl px-3 py-2 text-xs transition-colors cursor-pointer flex items-center justify-between ${
                    courierFilter === 'ALL'
                      ? 'bg-indigo-600 text-white font-bold shadow-xs'
                      : 'hover:bg-slate-50 text-slate-700 font-medium'
                  }`}
                >
                  <div className="flex items-center gap-2 truncate">
                    <Truck className={`w-3.5 h-3.5 shrink-0 ${courierFilter === 'ALL' ? 'text-white' : 'text-slate-400'}`} />
                    <span className="truncate">Tất cả đơn vị vận chuyển</span>
                  </div>
                  {courierFilter === 'ALL' && <Check className="w-4 h-4 text-white shrink-0 ml-2" />}
                </div>

                {courierOptions.map((c) => {
                  const isSelected = courierFilter === c;
                  return (
                    <div
                      key={c}
                      onClick={() => {
                        setCourierFilter(c);
                        setCurrentPage(1);
                        setIsCourierDropdownOpen(false);
                      }}
                      className={`rounded-xl px-3 py-2 text-xs transition-colors cursor-pointer flex items-center justify-between ${
                        isSelected
                          ? 'bg-indigo-600 text-white font-bold shadow-xs'
                          : 'hover:bg-slate-50 text-slate-700 font-medium'
                      }`}
                    >
                      <span className="truncate">{c}</span>
                      {isSelected && <Check className="w-4 h-4 text-white shrink-0 ml-2" />}
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          {/* Trạng thái filter dropdown chuẩn 100% Ảnh 2 */}
          <div className="relative" ref={statusDropdownRef}>
            {(() => {
              const statusOptions: Array<{ id: 'ALL' | 'PENDING' | 'OVERDUE' | 'DELIVERED'; label: string; dotColor?: string; isAll?: boolean }> = [
                { id: 'ALL', label: '-- Tất cả trạng thái --', isAll: true },
                { id: 'PENDING', label: 'Chờ cư dân nhận (Pending)', dotColor: 'bg-amber-500' },
                { id: 'OVERDUE', label: 'Tồn đọng > 5 ngày (Overdue)', dotColor: 'bg-rose-500' },
                { id: 'DELIVERED', label: 'Đã bàn giao (Delivered)', dotColor: 'bg-emerald-500' },
              ];
              const selectedOption = statusOptions.find((opt) => opt.id === statusFilter) || statusOptions[0];

              return (
                <>
                  <button
                    type="button"
                    onClick={() => {
                      setIsStatusDropdownOpen((prev) => !prev);
                      setIsCourierDropdownOpen(false);
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
                    <div className="absolute z-50 left-0 right-0 mt-1.5 bg-white rounded-2xl shadow-2xl border border-slate-100 p-1.5 space-y-1 min-w-[220px] animate-in fade-in zoom-in-95 duration-100">
                      {statusOptions.map((opt) => {
                        const isSelected = statusFilter === opt.id;
                        return (
                          <div
                            key={opt.id}
                            onClick={() => {
                              setStatusFilter(opt.id);
                              setCurrentPage(1);
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

          {/* Nút Đặt lại bộ lọc */}
          <div>
            <button
              type="button"
              onClick={() => {
                setSearchTerm('');
                setCourierFilter('ALL');
                setStatusFilter('ALL');
                setCurrentPage(1);
              }}
              className="w-full flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition cursor-pointer"
            >
              <RefreshCw className="w-3.5 h-3.5 text-slate-500" />
              <span>Đặt lại bộ lọc</span>
            </button>
          </div>
        </div>

        {/* Clear filters row if active */}
        {(searchTerm || courierFilter !== 'ALL' || statusFilter !== 'ALL') && (
          <div className="flex items-center justify-between pt-2 border-t border-slate-100 text-xs text-slate-500">
            <span>Đang áp dụng bộ lọc tùy chỉnh</span>
            <button
              type="button"
              onClick={() => {
                setSearchTerm('');
                setCourierFilter('ALL');
                setStatusFilter('ALL');
                setCurrentPage(1);
              }}
              className="text-indigo-600 hover:text-indigo-700 font-semibold flex items-center gap-1 cursor-pointer"
            >
              <X className="w-3.5 h-3.5" />
              <span>Xóa bộ lọc</span>
            </button>
          </div>
        )}
      </div>

      {/* 4. Main Parcels Table (Đồng bộ 100% chuẩn RFID Management) */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden relative">
        {loading && (
          <div className="absolute top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-indigo-500 via-sky-500 to-indigo-500 animate-pulse z-10" />
        )}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider font-mono">
                <th className="py-3 px-4">Ảnh Bưu Kiện</th>
                <th className="py-3 px-4">Mã Vận Đơn & ĐVVC</th>
                <th className="py-3 px-4">Căn Hộ & Người Nhận</th>
                <th className="py-3 px-4">Mã Lấy Hàng</th>
                <th className="py-3 px-4">Vị Trí Lưu Giữ</th>
                <th className="py-3 px-4">Thời Gian Tiếp Nhận</th>
                <th className="py-3 px-4 text-center">Trạng Thái</th>
                <th className="py-3 px-4 text-right">Thao Tác Tại Quầy</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading && parcels.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Loader2 className="w-6 h-6 animate-spin text-indigo-600" />
                      <span className="font-medium text-slate-600">Đang tải danh sách bưu phẩm...</span>
                    </div>
                  </td>
                </tr>
              ) : parcels.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Package className="w-10 h-10 text-slate-300 mb-1" />
                      <p className="font-semibold text-slate-600">Không tìm thấy kiện hàng nào</p>
                      <p className="text-xs text-slate-400">
                        Thử thay đổi bộ lọc hoặc bấm nút "Tiếp nhận kiện hàng mới" ở trên.
                      </p>
                    </div>
                  </td>
                </tr>
              ) : (
                parcels.map((parcel) => {
                  const isPending =
                    parcel.status === 'RECEIVED_AT_RECEPTION' || parcel.status === 'RECEIVED';
                  const isDelivered = parcel.status === 'DELIVERED';
                  const isOverdue =
                    isPending &&
                    new Date().getTime() - new Date(parcel.received_at).getTime() >
                      5 * 24 * 60 * 60 * 1000;

                  return (
                    <tr
                      key={parcel.id}
                      className="hover:bg-slate-50/70 transition-colors group"
                    >
                      {/* Ảnh bưu kiện (Thumbnail) */}
                      <td className="py-3 px-4">
                        <div
                          onClick={() => {
                            setLightboxImage(parcel.parcel_photo_url);
                            setActiveParcel(parcel);
                            setIsLightboxOpen(true);
                          }}
                          className="w-12 h-12 rounded-xl overflow-hidden bg-slate-100 border border-slate-200 relative group-hover:scale-105 transition-transform cursor-pointer shadow-xs"
                          title="Bấm để xem ảnh phóng to"
                        >
                          <img
                            src={parcel.parcel_photo_url}
                            alt={parcel.tracking_number}
                            className="w-full h-full object-cover"
                            loading="lazy"
                            onError={(e: any) => {
                              e.target.src =
                                'https://images.unsplash.com/photo-1549465220-1a8b9238cd48?w=150&auto=format&fit=crop&q=60';
                            }}
                          />
                          <div className="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white">
                            <Maximize2 className="w-3.5 h-3.5 drop-shadow-md" />
                          </div>
                        </div>
                      </td>

                      {/* Mã vận đơn & ĐVVC */}
                      <td className="py-3 px-4">
                        <div className="font-bold text-slate-900 flex items-center gap-1.5 font-mono">
                          <span>{parcel.tracking_number}</span>
                        </div>
                        <div className="text-[11px] text-amber-700 font-semibold mt-0.5 flex items-center gap-1">
                          <Truck className="w-3 h-3 text-amber-500" />
                          <span>{parcel.courier_company}</span>
                        </div>
                      </td>

                      {/* Căn hộ & Người nhận */}
                      <td className="py-3 px-4">
                        <div className="font-bold text-sky-700 flex items-center gap-1">
                          <Building className="w-3.5 h-3.5 text-sky-500" />
                          <span>
                            {parcel.apartment?.apartment_number || 'Căn hộ'}
                          </span>
                        </div>
                        <div className="text-xs text-slate-800 font-medium mt-0.5 flex items-center gap-1">
                          <User className="w-3 h-3 text-slate-400" />
                          <span>{parcel.recipient_name}</span>
                        </div>
                        <div className="text-[11px] text-slate-400 mt-0.5 flex items-center gap-1">
                          <Phone className="w-2.5 h-2.5 text-slate-400" />
                          <span>{parcel.recipient_phone}</span>
                        </div>
                      </td>

                      {/* Mã lấy hàng (PIN & QR) */}
                      <td className="py-3 px-4 font-mono">
                        <div className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-slate-100 border border-slate-200 text-xs font-black text-slate-800">
                          <KeyRound className="w-3 h-3 text-amber-600" />
                          <span>{parcel.pickup_pin_code}</span>
                        </div>
                        <div className="text-[10px] text-slate-400 mt-0.5 truncate max-w-[120px]" title={parcel.pickup_qr_code}>
                          {parcel.pickup_qr_code}
                        </div>
                      </td>

                      {/* Vị trí lưu giữ */}
                      <td className="py-3 px-4 text-xs text-slate-600 font-medium">
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700">
                          <MapPin className="w-3 h-3 text-slate-400" />
                          {parcel.stored_location_type === 'RECEPTION_DESK'
                            ? 'Quầy lễ tân'
                            : parcel.stored_location_type === 'SMART_LOCKER'
                            ? 'Tủ thông minh'
                            : 'Bốt an ninh'}
                        </span>
                      </td>

                      {/* Thời gian tiếp nhận */}
                      <td className="py-3 px-4 text-xs text-slate-500">
                        <div>{formatDateTime(parcel.received_at)}</div>
                        {parcel.received_by_staff && (
                          <div className="text-[11px] text-slate-400 mt-0.5">
                            Bởi: {parcel.received_by_staff.full_name}
                          </div>
                        )}
                      </td>

                      {/* Trạng thái */}
                      <td className="py-3 px-4 text-center">
                        {isOverdue ? (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-200">
                            <AlertTriangle className="w-3 h-3 text-rose-600" />
                            <span>Quá hạn (&gt;5d)</span>
                          </span>
                        ) : isPending ? (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
                            <span className="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse" />
                            <span>Chờ cư dân nhận</span>
                          </span>
                        ) : isDelivered ? (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                            <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                            <span>Đã bàn giao</span>
                          </span>
                        ) : (
                          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700">
                            {parcel.status}
                          </span>
                        )}
                      </td>

                      {/* Thao tác */}
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            type="button"
                            onClick={() => {
                              setActiveParcel(parcel);
                              setIsDetailModalOpen(true);
                            }}
                            className="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 transition cursor-pointer"
                            title="Xem chi tiết kiện hàng"
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

        {/* Pagination Bar */}
        {pagination.total_pages > 1 && (
          <div className="bg-slate-50 border-t border-slate-200 px-4 py-3 flex items-center justify-between text-xs text-slate-500">
            <span>
              Hiển thị{' '}
              <strong className="text-slate-800">
                {(pagination.current_page - 1) * pagination.per_page + 1}
              </strong>{' '}
              đến{' '}
              <strong className="text-slate-800">
                {Math.min(
                  pagination.current_page * pagination.per_page,
                  pagination.total_items
                )}
              </strong>{' '}
              trên tổng <strong className="text-slate-800">{pagination.total_items}</strong> kiện
            </span>

            <div className="flex items-center gap-1.5">
              <button
                type="button"
                disabled={pagination.current_page <= 1}
                onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                className="p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-white disabled:opacity-40 cursor-pointer"
              >
                <ChevronLeft className="w-4 h-4" />
              </button>
              <span className="px-2.5 py-1 font-semibold text-slate-800">
                {pagination.current_page} / {pagination.total_pages}
              </span>
              <button
                type="button"
                disabled={!pagination.has_more}
                onClick={() => setCurrentPage((p) => p + 1)}
                className="p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-white disabled:opacity-40 cursor-pointer"
              >
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ================= MODAL: TIẾP NHẬN KIỆN HÀNG MỚI ================= */}
      {isCreateModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-md animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl border border-white/90 shadow-2xl max-w-2xl w-full max-h-[92vh] overflow-y-auto">
            {/* Modal Header */}
            <div className="p-6 sm:p-7 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white/95 backdrop-blur-md z-10">
              <div>
                <span className="text-xs font-bold text-amber-600 uppercase tracking-wider font-mono">
                  PHIẾU TIẾP NHẬN MỚI
                </span>
                <h3 className="text-xl font-black text-neutral-900 mt-0.5">
                  Tiếp Nhận Kiện Hàng & Chụp Ảnh Bưu Kiện
                </h3>
              </div>
              <button
                onClick={() => {
                  if (!formSubmitting) {
                    setIsCreateModalOpen(false);
                    handleClearPhoto();
                  }
                }}
                className="p-2 rounded-xl text-slate-400 hover:text-neutral-700 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Body Form */}
            <form onSubmit={handleCreateParcel} className="p-6 sm:p-7 space-y-5">
              {formError && (
                <div className="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium flex items-center gap-3">
                  <AlertTriangle className="w-5 h-5 shrink-0 text-rose-500" />
                  <span>{formError}</span>
                </div>
              )}

              {/* SECTION 1: CĂN HỘ & NGƯỜI NHẬN */}
              <div className="space-y-4">
                <h4 className="text-xs font-bold text-slate-400 uppercase tracking-wider font-mono flex items-center gap-2">
                  <Building className="w-3.5 h-3.5 text-sky-500" />
                  <span>1. THÔNG TIN CĂN HỘ & NGƯỜI NHẬN</span>
                </h4>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Căn hộ */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      Căn hộ nhận hàng <span className="text-rose-500">*</span>
                    </label>
                    <select
                      value={formData.apartment_id}
                      onChange={(e) => handleApartmentChange(e.target.value)}
                      className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-neutral-900 bg-white focus:outline-hidden focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500 cursor-pointer"
                    >
                      <option value="">-- Chọn căn hộ --</option>
                      {apartmentOptions.map((apt) => (
                        <option key={apt.id} value={apt.id}>
                          {apt.apartment_number} {apt.block_name ? `(${apt.block_name})` : ''}
                        </option>
                      ))}
                    </select>
                  </div>

                  {/* Gợi ý người nhận nếu căn hộ có cư dân */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      Tên người nhận <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="text"
                      value={formData.recipient_name}
                      onChange={(e) =>
                        setFormData((prev) => ({ ...prev, recipient_name: e.target.value }))
                      }
                      placeholder="VD: Nguyễn Văn A"
                      className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm text-neutral-900 bg-white focus:outline-hidden focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500"
                    />
                    {recipientSuggestions.length > 0 && (
                      <div className="flex flex-wrap gap-1.5 mt-2">
                        <span className="text-[11px] text-slate-400">Gợi ý cư dân:</span>
                        {recipientSuggestions.map((rec) => (
                          <button
                            key={rec.id}
                            type="button"
                            onClick={() => {
                              setFormData((prev) => ({
                                ...prev,
                                recipient_name: rec.full_name,
                                recipient_phone: rec.phone_number || prev.recipient_phone,
                                recipient_user_id: rec.id,
                              }));
                            }}
                            className="text-[11px] font-semibold text-sky-700 bg-sky-50 hover:bg-sky-100 px-2 py-0.5 rounded-md border border-sky-200 transition-colors cursor-pointer"
                          >
                            {rec.full_name}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>

                  {/* SĐT người nhận */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      Số điện thoại nhận hàng <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="tel"
                      value={formData.recipient_phone}
                      onChange={(e) =>
                        setFormData((prev) => ({ ...prev, recipient_phone: e.target.value }))
                      }
                      placeholder="VD: 0912345678"
                      className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm text-neutral-900 bg-white focus:outline-hidden focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500"
                    />
                  </div>

                  {/* Vị trí lưu giữ */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      Vị trí lưu giữ <span className="text-rose-500">*</span>
                    </label>
                    <select
                      value={formData.stored_location_type}
                      onChange={(e) =>
                        setFormData((prev) => ({
                          ...prev,
                          stored_location_type: e.target.value,
                        }))
                      }
                      className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-neutral-900 bg-white focus:outline-hidden focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500 cursor-pointer"
                    >
                      <option value="RECEPTION_DESK">Quầy Lễ tân (Reception Desk)</option>
                      <option value="SMART_LOCKER">Tủ đồ thông minh (Smart Locker)</option>
                      <option value="SECURITY_BOOTH">Bốt Bảo vệ (Security Booth)</option>
                    </select>
                  </div>
                </div>
              </div>

              {/* SECTION 2: THÔNG TIN VẬN ĐƠN */}
              <div className="space-y-4 pt-2">
                <h4 className="text-xs font-bold text-slate-400 uppercase tracking-wider font-mono flex items-center gap-2">
                  <Truck className="w-3.5 h-3.5 text-amber-500" />
                  <span>2. THÔNG TIN VẬN ĐƠN & ĐƠN VỊ VẬN CHUYỂN</span>
                </h4>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Mã vận đơn */}
                  <div>
                    <div className="flex items-center justify-between mb-1.5">
                      <label className="text-xs font-bold text-slate-700">
                        Mã vận đơn <span className="text-rose-500">*</span>
                      </label>
                      <button
                        type="button"
                        onClick={handleGenerateSampleTracking}
                        className="text-[11px] font-semibold text-amber-700 hover:text-amber-800 cursor-pointer"
                      >
                        + Tạo mã mẫu
                      </button>
                    </div>
                    <input
                      type="text"
                      value={formData.tracking_number}
                      onChange={(e) =>
                        setFormData((prev) => ({ ...prev, tracking_number: e.target.value }))
                      }
                      placeholder="VD: SPX123456789VN"
                      className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm font-mono text-neutral-900 bg-white focus:outline-hidden focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500 font-bold uppercase"
                    />
                  </div>

                  {/* Đơn vị vận chuyển */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1.5">
                      Đơn vị vận chuyển <span className="text-rose-500">*</span>
                    </label>
                    <select
                      value={formData.courier_company}
                      onChange={(e) =>
                        setFormData((prev) => ({ ...prev, courier_company: e.target.value }))
                      }
                      className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm font-medium text-neutral-900 bg-white focus:outline-hidden focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500 cursor-pointer"
                    >
                      {courierOptions.map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              </div>

              {/* SECTION 3: UPLOAD ẢNH BƯU KIỆN (CORE MODULE #9) */}
              <div className="space-y-3 pt-2">
                <h4 className="text-xs font-bold text-slate-400 uppercase tracking-wider font-mono flex items-center justify-between">
                  <span className="flex items-center gap-2">
                    <Camera className="w-3.5 h-3.5 text-emerald-500" />
                    <span>3. ẢNH CHỤP THỰC TẾ BƯU KIỆN</span>
                  </span>
                  <span className="text-rose-500 font-normal">Bắt buộc</span>
                </h4>

                {/* Ẩn input file thật */}
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  onChange={handlePhotoSelect}
                  className="hidden"
                />

                {!photoPreviewUrl ? (
                  <div
                    onClick={() => fileInputRef.current?.click()}
                    className="border-2 border-dashed border-amber-300 hover:border-amber-500 bg-amber-50/40 hover:bg-amber-50/70 rounded-2xl p-6 sm:p-8 text-center cursor-pointer transition-all group"
                  >
                    <div className="w-12 h-12 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition-transform">
                      <Camera className="w-6 h-6" />
                    </div>
                    <p className="text-sm font-bold text-neutral-800">
                      Bấm để chọn file ảnh hoặc chụp trực tiếp
                    </p>
                    <p className="text-xs text-slate-400 mt-1">
                      Hỗ trợ JPG, PNG, WebP (Dung lượng tối đa 10MB)
                    </p>
                  </div>
                ) : (
                  <div className="relative rounded-2xl overflow-hidden border border-slate-200 bg-slate-900 p-2">
                    <img
                      src={photoPreviewUrl}
                      alt="Preview kiện hàng"
                      className="w-full max-h-64 object-contain rounded-xl mx-auto"
                    />
                    <div className="absolute top-4 right-4 flex items-center gap-2">
                      <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        className="px-3 py-1.5 rounded-lg bg-black/60 hover:bg-black/80 text-white text-xs font-semibold backdrop-blur-md transition-all cursor-pointer flex items-center gap-1.5"
                      >
                        <RefreshCw className="w-3.5 h-3.5" />
                        <span>Đổi ảnh</span>
                      </button>
                      <button
                        type="button"
                        onClick={handleClearPhoto}
                        className="p-1.5 rounded-lg bg-rose-600/80 hover:bg-rose-700 text-white backdrop-blur-md transition-all cursor-pointer"
                        title="Xóa ảnh"
                      >
                        <X className="w-4 h-4" />
                      </button>
                    </div>
                    {selectedPhotoFile && (
                      <div className="text-[11px] text-slate-300 px-3 py-1 mt-1 flex items-center justify-between">
                        <span>{selectedPhotoFile.name}</span>
                        <span>{(selectedPhotoFile.size / 1024 / 1024).toFixed(2)} MB</span>
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* Ghi chú thêm */}
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1.5">
                  Ghi chú bưu phẩm (nếu có)
                </label>
                <textarea
                  value={formData.notes}
                  onChange={(e) =>
                    setFormData((prev) => ({ ...prev, notes: e.target.value }))
                  }
                  rows={2}
                  placeholder="VD: Hàng dễ vỡ, thùng to để cạnh quầy, hàng đông lạnh..."
                  className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm text-neutral-900 bg-white focus:outline-hidden focus:ring-2 focus:ring-amber-500/30 focus:border-amber-500"
                />
              </div>

              {/* Modal Footer */}
              <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <button
                  type="button"
                  disabled={formSubmitting}
                  onClick={() => {
                    setIsCreateModalOpen(false);
                    handleClearPhoto();
                  }}
                  className="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-700 text-sm font-semibold hover:bg-slate-50 transition-colors cursor-pointer"
                >
                  Hủy bỏ
                </button>

                <button
                  type="submit"
                  disabled={formSubmitting}
                  className="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold shadow-md hover:shadow-lg transition-all flex items-center gap-2 cursor-pointer disabled:opacity-50 active:scale-95"
                >
                  {formSubmitting ? (
                    <>
                      <RefreshCw className="w-4 h-4 animate-spin" />
                      <span>Đang lưu và tải ảnh...</span>
                    </>
                  ) : (
                    <>
                      <CheckCircle2 className="w-4 h-4" />
                      <span>Xác nhận tiếp nhận kiện hàng</span>
                    </>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ================= MODAL: XEM CHI TIẾT KIỆN HÀNG ================= */}
      {isDetailModalOpen && activeParcel && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/60 backdrop-blur-md animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl border border-white/90 shadow-2xl max-w-xl w-full max-h-[92vh] overflow-y-auto">
            <div className="p-6 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white/95 backdrop-blur-md z-10">
              <div>
                <span className="text-xs font-bold text-amber-600 uppercase tracking-wider font-mono">
                  CHI TIẾT BƯU PHẨM
                </span>
                <h3 className="text-lg font-black text-neutral-900 mt-0.5">
                  Mã vận đơn: {activeParcel.tracking_number}
                </h3>
              </div>
              <button
                onClick={() => setIsDetailModalOpen(false)}
                className="p-2 rounded-xl text-slate-400 hover:text-neutral-700 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-6 space-y-5">
              {/* Ảnh lớn */}
              <div
                onClick={() => {
                  setLightboxImage(activeParcel.parcel_photo_url);
                  setIsLightboxOpen(true);
                }}
                className="w-full h-56 rounded-2xl overflow-hidden bg-slate-900 relative cursor-pointer group shadow-md"
              >
                <img
                  src={activeParcel.parcel_photo_url}
                  alt={activeParcel.tracking_number}
                  className="w-full h-full object-contain"
                />
                <div className="absolute inset-0 bg-black/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white text-xs font-semibold gap-1.5">
                  <Maximize2 className="w-4 h-4" />
                  <span>Bấm để phóng to ảnh</span>
                </div>
              </div>

              {/* Thông tin mã lấy hàng */}
              <div className="p-4 rounded-2xl bg-amber-50 border border-amber-200/80 flex items-center justify-between">
                <div>
                  <span className="text-xs text-amber-700 font-semibold block">
                    Mã PIN lấy hàng (6 số):
                  </span>
                  <span className="text-2xl font-black text-amber-900 font-mono tracking-wider">
                    {activeParcel.pickup_pin_code}
                  </span>
                </div>
                <div className="text-right">
                  <span className="text-xs text-amber-700 font-semibold block">
                    Mã QR Check-out:
                  </span>
                  <span className="text-xs font-mono font-bold text-amber-900">
                    {activeParcel.pickup_qr_code}
                  </span>
                </div>
              </div>

              {/* Thông tin bảng kê */}
              <div className="grid grid-cols-2 gap-4 text-xs">
                <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                  <span className="text-slate-400 block mb-1">Căn hộ nhận:</span>
                  <span className="font-bold text-sky-700 text-sm">
                    {activeParcel.apartment?.apartment_number || '—'}
                  </span>
                </div>

                <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                  <span className="text-slate-400 block mb-1">Đơn vị vận chuyển:</span>
                  <span className="font-bold text-neutral-800 text-sm">
                    {activeParcel.courier_company}
                  </span>
                </div>

                <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                  <span className="text-slate-400 block mb-1">Người nhận:</span>
                  <span className="font-bold text-neutral-800 text-sm">
                    {activeParcel.recipient_name}
                  </span>
                  <span className="text-slate-500 block">{activeParcel.recipient_phone}</span>
                </div>

                <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                  <span className="text-slate-400 block mb-1">Vị trí lưu giữ:</span>
                  <span className="font-bold text-neutral-800 text-sm">
                    {activeParcel.stored_location_type === 'RECEPTION_DESK'
                      ? 'Quầy lễ tân'
                      : activeParcel.stored_location_type === 'SMART_LOCKER'
                      ? 'Tủ thông minh'
                      : 'Bốt bảo vệ'}
                  </span>
                </div>

                <div className="p-3 rounded-xl bg-slate-50 border border-slate-100 col-span-2">
                  <span className="text-slate-400 block mb-1">Thời gian tiếp nhận:</span>
                  <span className="font-semibold text-neutral-800">
                    {formatDateTime(activeParcel.received_at)}
                  </span>
                  {activeParcel.received_by_staff && (
                    <span className="text-slate-500 block text-[11px] mt-0.5">
                      Tiếp nhận bởi: {activeParcel.received_by_staff.full_name}
                    </span>
                  )}
                </div>

                {activeParcel.notes && (
                  <div className="p-3 rounded-xl bg-amber-50/50 border border-amber-100 col-span-2">
                    <span className="text-amber-700 font-semibold block mb-1">Ghi chú:</span>
                    <span className="text-neutral-800">{activeParcel.notes}</span>
                  </div>
                )}
              </div>
            </div>

            <div className="p-6 border-t border-slate-100 flex items-center justify-end">
              <button
                onClick={() => setIsDetailModalOpen(false)}
                className="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-700 text-sm font-semibold hover:bg-slate-50 transition-colors cursor-pointer"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL: LIGHTBOX XEM ẢNH FULL SIZE ================= */}
      {isLightboxOpen && lightboxImage && (
        <div
          onClick={() => setIsLightboxOpen(false)}
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-neutral-950/85 backdrop-blur-xl animate-in fade-in duration-200 cursor-zoom-out"
        >
          <div
            onClick={(e) => e.stopPropagation()}
            className="relative max-w-4xl max-h-[90vh] bg-black/40 rounded-3xl p-2 shadow-2xl"
          >
            <img
              src={lightboxImage}
              alt="Ảnh kiện hàng bưu phẩm"
              className="max-h-[85vh] max-w-full rounded-2xl object-contain mx-auto shadow-2xl"
            />
            <button
              onClick={() => setIsLightboxOpen(false)}
              className="absolute top-5 right-5 p-2 rounded-full bg-black/60 hover:bg-black/90 text-white backdrop-blur-md transition-colors cursor-pointer"
            >
              <X className="w-5 h-5" />
            </button>
            {activeParcel && (
              <div className="absolute bottom-5 left-5 right-5 p-3 rounded-xl bg-black/60 backdrop-blur-md text-white text-xs flex items-center justify-between">
                <span>
                  <strong>Mã vận đơn:</strong> {activeParcel.tracking_number} •{' '}
                  <strong>Căn hộ:</strong> {activeParcel.apartment?.apartment_number} •{' '}
                  <strong>Người nhận:</strong> {activeParcel.recipient_name}
                </span>
                <a
                  href={lightboxImage}
                  target="_blank"
                  rel="noreferrer"
                  download={`parcel_${activeParcel.tracking_number}.jpg`}
                  className="px-3 py-1 rounded-lg bg-white/20 hover:bg-white/30 text-white font-medium flex items-center gap-1.5 transition-colors"
                >
                  <Download className="w-3.5 h-3.5" />
                  <span>Tải ảnh</span>
                </a>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};
