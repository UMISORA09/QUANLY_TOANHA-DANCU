import React, { useState, useEffect, useCallback, useRef } from 'react';
import { TicketImage } from './TicketImage';
import { ConfirmDialog } from './Admin/ConfirmDialog';
import {
  AlertCircle,
  AlertTriangle,
  ArrowLeft,
  Calendar,
  Camera,
  Check,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Clock,
  Eye,
  FileText,
  Filter,
  Flame,
  Home,
  Info,
  Loader2,
  MapPin,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Send,
  ShieldAlert,
  Trash2,
  User,
  Wrench,
  X,
  XCircle,
  Zap,
} from 'lucide-react';
import {
  api,
  TicketCategoryItem,
  TicketItem,
  TicketListResponse,
} from '../Services/api';

export interface ResidentTicketPanelProps {
  initialTab?: 'list' | 'create';
  onNavigateOverview?: () => void;
}

// Helpers định dạng ngày giờ
const formatDateTime = (isoStr?: string | null) => {
  if (!isoStr) return '---';
  try {
    const d = new Date(isoStr);
    if (isNaN(d.getTime())) return isoStr;
    const hours = String(d.getHours()).padStart(2, '0');
    const mins = String(d.getMinutes()).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    return `${hours}:${mins} · ${day}/${month}/${year}`;
  } catch {
    return isoStr;
  }
};

// Map nhãn và style cho Trạng thái Ticket
const STATUS_CONFIG: Record<string, { label: string; bg: string; text: string; border: string; dot: string }> = {
  NEW: {
    label: 'Mới tiếp nhận',
    bg: 'bg-sky-50',
    text: 'text-sky-700',
    border: 'border-sky-200/80',
    dot: 'bg-sky-500',
  },
  RECEIVED: {
    label: 'Đã ghi nhận',
    bg: 'bg-blue-50',
    text: 'text-blue-700',
    border: 'border-blue-200/80',
    dot: 'bg-blue-500',
  },
  IN_PROGRESS: {
    label: 'Đang xử lý',
    bg: 'bg-amber-50',
    text: 'text-amber-700',
    border: 'border-amber-200/80',
    dot: 'bg-amber-500',
  },
  RESOLVED: {
    label: 'Đã khắc phục (Chờ duyệt)',
    bg: 'bg-purple-50',
    text: 'text-purple-700',
    border: 'border-purple-200/80',
    dot: 'bg-purple-500',
  },
  CLOSED: {
    label: 'Đã hoàn tất',
    bg: 'bg-emerald-50',
    text: 'text-emerald-700',
    border: 'border-emerald-200/80',
    dot: 'bg-emerald-500',
  },
  CANCELLED: {
    label: 'Đã hủy',
    bg: 'bg-neutral-100',
    text: 'text-neutral-600',
    border: 'border-neutral-200/80',
    dot: 'bg-neutral-400',
  },
};

// Map nhãn và style cho Mức độ khẩn cấp
const PRIORITY_CONFIG: Record<string, { label: string; bg: string; text: string; border: string }> = {
  LOW: {
    label: 'Thấp',
    bg: 'bg-slate-50',
    text: 'text-slate-600',
    border: 'border-slate-200',
  },
  MEDIUM: {
    label: 'Bình thường',
    bg: 'bg-blue-50',
    text: 'text-blue-700',
    border: 'border-blue-200',
  },
  HIGH: {
    label: 'Ưu tiên cao',
    bg: 'bg-amber-50',
    text: 'text-amber-700',
    border: 'border-amber-200',
  },
  URGENT: {
    label: 'Khẩn cấp',
    bg: 'bg-rose-50',
    text: 'text-rose-700',
    border: 'border-rose-200',
  },
};

export const ResidentTicketPanel: React.FC<ResidentTicketPanelProps> = ({
  initialTab = 'list',
  onNavigateOverview,
}) => {
  const [viewMode, setViewMode] = useState<'list' | 'detail'>('list');
  const [isCreateModalOpen, setIsCreateModalOpen] = useState<boolean>(initialTab === 'create');
  const [tickets, setTickets] = useState<TicketItem[]>([]);
  const [counters, setCounters] = useState({
    all: 0,
    new: 0,
    in_progress: 0,
    resolved: 0,
    closed: 0,
    cancelled: 0,
  });
  const [categories, setCategories] = useState<TicketCategoryItem[]>([]);
  const [apartments, setApartments] = useState<any[]>([]);
  const [currentUser, setCurrentUser] = useState<any>(null);

  // Pagination & Filters State
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [categoryFilter, setCategoryFilter] = useState<string>('ALL');
  const [priorityFilter, setPriorityFilter] = useState<string>('ALL');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [debouncedSearch, setDebouncedSearch] = useState<string>('');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [lastPage, setLastPage] = useState<number>(1);
  const [totalTickets, setTotalTickets] = useState<number>(0);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  // Selected Ticket for Detail View
  const [selectedTicketId, setSelectedTicketId] = useState<string | null>(null);
  const [detailTicket, setDetailTicket] = useState<TicketItem | null>(null);
  const [isLoadingDetail, setIsLoadingDetail] = useState<boolean>(false);

  // Lightbox xem ảnh lớn
  const [previewImage, setPreviewImage] = useState<string | null>(null);
  const [removeImageId, setRemoveImageId] = useState<string | null>(null);
  const [imageBusy, setImageBusy] = useState(false);

  // Toast notification
  const [toast, setToast] = useState<{ message: string; type: 'success' | 'error' | 'info' } | null>(null);
  const showToast = (message: string, type: 'success' | 'error' | 'info' = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 4000);
  };

  // Form State: Tạo mới sự cố
  const [formData, setFormData] = useState({
    title: '',
    category_id: '',
    location: '',
    priority: 'MEDIUM',
    preferred_service_time: '',
    description: '',
    apartment_id: '',
  });
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const [filePreviews, setFilePreviews] = useState<string[]>([]);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const createRequestKey = useRef<string | null>(null);

  useEffect(() => {
    createRequestKey.current = null;
  }, [formData, selectedFiles]);

  // Action Modals State
  const [isCancelModalOpen, setIsCancelModalOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [isCancelling, setIsCancelling] = useState(false);

  const [isConfirmModalOpen, setIsConfirmModalOpen] = useState(false);
  const [confirmNote, setConfirmNote] = useState('');
  const [isConfirming, setIsConfirming] = useState(false);

  const [isReopenModalOpen, setIsReopenModalOpen] = useState(false);
  const [reopenReason, setReopenReason] = useState('');
  const [isReopening, setIsReopening] = useState(false);

  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [editFormData, setEditFormData] = useState({
    title: '',
    location: '',
    priority: 'MEDIUM',
    preferred_service_time: '',
    description: '',
    version: 1,
  });
  const [isUpdating, setIsUpdating] = useState(false);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Load Categories & Resident Profile/Apartment bootstrap
  useEffect(() => {
    const fetchBootstrapData = async () => {
      try {
        const [catRes, overviewRes, amenityRes] = await Promise.allSettled([
          api.getResidentTicketCategories(),
          api.getResidentOverview(),
          api.getResidentAmenities(),
        ]);

        if (catRes.status === 'fulfilled' && catRes.value?.data) {
          setCategories(catRes.value.data);
          if (catRes.value.data.length > 0) {
            setFormData((prev) => ({ ...prev, category_id: prev.category_id || catRes.value.data[0].id }));
          }
        }

        let resolvedApartments: any[] = [];

        if (overviewRes.status === 'fulfilled' && overviewRes.value) {
          const ov = overviewRes.value;
          setCurrentUser(ov.user || null);
          if (Array.isArray(ov.apartments) && ov.apartments.length > 0) {
            resolvedApartments = ov.apartments;
          } else if (ov.apartment) {
            resolvedApartments = [ov.apartment];
          }
        }

        if (resolvedApartments.length === 0 && amenityRes.status === 'fulfilled' && amenityRes.value?.apartments?.length) {
          resolvedApartments = amenityRes.value.apartments;
        }

        if (resolvedApartments.length > 0) {
          setApartments(resolvedApartments);
          setFormData((prev) => ({ ...prev, apartment_id: prev.apartment_id || resolvedApartments[0].id }));
        }
      } catch (e) {
        // ignore
      }
    };

    fetchBootstrapData();
  }, []);

  // Fetch tickets list with filters
  const fetchTickets = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const res = await api.getResidentTickets({
        status: statusFilter,
        category_id: categoryFilter,
        priority: priorityFilter,
        search: debouncedSearch,
        page: currentPage,
        per_page: 10,
      });

      if (res && res.success) {
        setTickets(res.data || []);
        if (res.counters) {
          setCounters(res.counters);
        }
        if (res.pagination) {
          setCurrentPage(res.pagination.current_page);
          setLastPage(res.pagination.last_page);
          setTotalTickets(res.pagination.total);
        }
      }
    } catch (err: any) {
      setLoadError(err?.message || 'Không thể tải danh sách sự cố. Vui lòng kiểm tra lại kết nối.');
    } finally {
      setIsLoading(false);
    }
  }, [statusFilter, categoryFilter, priorityFilter, debouncedSearch, currentPage]);

  useEffect(() => {
    if (viewMode === 'list') {
      fetchTickets();
    }
  }, [viewMode, fetchTickets]);

  // Fetch ticket detail
  const fetchTicketDetail = async (id: string) => {
    setIsLoadingDetail(true);
    try {
      const res = await api.getResidentTicketDetail(id);
      if (res && res.success && res.data) {
        setDetailTicket(res.data);
        setSelectedTicketId(id);
        setViewMode('detail');
      }
    } catch (err: any) {
      showToast(err?.message || 'Không thể tải thông tin chi tiết sự cố.', 'error');
    } finally {
      setIsLoadingDetail(false);
    }
  };

  // Xử lý upload ảnh minh chứng
  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []);
    if (!files.length) return;

    if (selectedFiles.length + files.length > 5) {
      showToast('Chỉ được phép đính kèm tối đa 5 hình ảnh.', 'error');
      return;
    }

    const newFiles: File[] = [];
    const newPreviews: string[] = [];

    for (const file of files) {
      // Kiểm tra dung lượng 5MB
      if (file.size > 5 * 1024 * 1024) {
        showToast(`Ảnh "${file.name}" vượt quá dung lượng tối đa 5MB.`, 'error');
        continue;
      }
      // Kiểm tra MIME type
      const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
      if (!allowed.includes(file.type)) {
        showToast(`Tệp "${file.name}" không phải định dạng ảnh được hỗ trợ (JPG, PNG, WebP).`, 'error');
        continue;
      }

      newFiles.push(file);
      newPreviews.push(URL.createObjectURL(file));
    }

    setSelectedFiles((prev) => [...prev, ...newFiles]);
    setFilePreviews((prev) => [...prev, ...newPreviews]);

    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const removeFile = (index: number) => {
    URL.revokeObjectURL(filePreviews[index]);
    setSelectedFiles((prev) => prev.filter((_, i) => i !== index));
    setFilePreviews((prev) => prev.filter((_, i) => i !== index));
  };

  // Submit tạo mới Ticket
  const handleSubmitTicket = async (e: React.FormEvent) => {
    e.preventDefault();
    const errors: Record<string, string> = {};

    if (!formData.title.trim()) {
      errors.title = 'Vui lòng nhập tiêu đề sự cố.';
    } else if (formData.title.trim().length > 200) {
      errors.title = 'Tiêu đề không được vượt quá 200 ký tự.';
    }

    if (!formData.category_id) {
      errors.category_id = 'Vui lòng chọn loại sự cố kỹ thuật.';
    }

    if (!formData.location.trim()) {
      errors.location = 'Vui lòng ghi rõ vị trí xảy ra sự cố.';
    }

    if (!formData.description.trim()) {
      errors.description = 'Vui lòng mô tả chi tiết tình trạng sự cố.';
    } else if (formData.description.trim().length < 5) {
      errors.description = 'Mô tả sự cố phải có ít nhất 5 ký tự.';
    }

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      showToast('Vui lòng kiểm tra lại các trường thông tin bắt buộc.', 'error');
      return;
    }

    setFormErrors({});
    setIsSubmitting(true);

    try {
      const dataPayload = new FormData();
      dataPayload.append('title', formData.title.trim());
      dataPayload.append('category_id', formData.category_id);
      dataPayload.append('location', formData.location.trim());
      dataPayload.append('priority', formData.priority);
      dataPayload.append('description', formData.description.trim());

      if (formData.apartment_id) {
        dataPayload.append('apartment_id', formData.apartment_id);
      }
      if (formData.preferred_service_time) {
        dataPayload.append('preferred_service_time', formData.preferred_service_time);
      }

      selectedFiles.forEach((file) => {
        dataPayload.append('attachments[]', file);
      });

      createRequestKey.current ??= crypto.randomUUID();
      const res = await api.createResidentTicket(dataPayload, createRequestKey.current);

      if (res && res.success) {
        showToast('Gửi báo cáo sự cố thành công! Mã yêu cầu: ' + (res.data?.ticket_number || ''), 'success');
        // Reset form
        setFormData({
          title: '',
          category_id: categories[0]?.id || '',
          location: '',
          priority: 'MEDIUM',
          preferred_service_time: '',
          description: '',
          apartment_id: apartments[0]?.id || '',
        });
        setSelectedFiles([]);
        setFilePreviews([]);
        // Đóng modal, quay về danh sách và refresh
        setIsCreateModalOpen(false);
        setViewMode('list');
        fetchTickets();
      }
    } catch (err: any) {
      showToast(err?.message || 'Không thể gửi yêu cầu sự cố. Vui lòng thử lại.', 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  // Reset form tạo mới
  const handleResetForm = () => {
    setFormData({
      title: '',
      category_id: categories[0]?.id || '',
      location: '',
      priority: 'MEDIUM',
      preferred_service_time: '',
      description: '',
      apartment_id: apartments[0]?.id || '',
    });
    setFormErrors({});
    filePreviews.forEach((url) => URL.revokeObjectURL(url));
    setSelectedFiles([]);
    setFilePreviews([]);
    showToast('Đã đặt lại toàn bộ biểu mẫu.', 'info');
  };

  // Xử lý Hủy Ticket
  const handleConfirmCancel = async () => {
    if (!detailTicket) return;
    setIsCancelling(true);
    try {
      const res = await api.cancelResidentTicket(detailTicket.id, cancelReason);
      if (res && res.success) {
        showToast('Hủy yêu cầu sự cố thành công.', 'success');
        setDetailTicket(res.data);
        setIsCancelModalOpen(false);
        setCancelReason('');
        fetchTickets();
      }
    } catch (err: any) {
      showToast(err?.message || 'Không thể hủy yêu cầu sự cố.', 'error');
    } finally {
      setIsCancelling(false);
    }
  };

  // Xử lý Xác nhận Đã khắc phục (Nghiệm thu đóng ticket)
  const handleConfirmResolved = async () => {
    if (!detailTicket) return;
    setIsConfirming(true);
    try {
      const res = await api.confirmResidentTicket(detailTicket.id, confirmNote);
      if (res && res.success) {
        showToast('Cảm ơn bạn đã xác nhận và nghiệm thu công tác sửa chữa.', 'success');
        setDetailTicket(res.data);
        setIsConfirmModalOpen(false);
        setConfirmNote('');
        fetchTickets();
      }
    } catch (err: any) {
      showToast(err?.message || 'Không thể xác nhận hoàn thành.', 'error');
    } finally {
      setIsConfirming(false);
    }
  };

  // Xử lý Reopen Ticket (Báo chưa khắc phục xong)
  const handleReopenTicket = async () => {
    if (!detailTicket) return;
    if (!reopenReason.trim() || reopenReason.trim().length < 5) {
      showToast('Vui lòng nêu rõ lý do sự cố chưa được xử lý xong (ít nhất 5 ký tự).', 'error');
      return;
    }
    setIsReopening(true);
    try {
      const res = await api.reopenResidentTicket(detailTicket.id, reopenReason);
      if (res && res.success) {
        showToast('Đã gửi phản hồi. Kỹ thuật viên sẽ tiếp tục kiểm tra lại sự cố.', 'success');
        setDetailTicket(res.data);
        setIsReopenModalOpen(false);
        setReopenReason('');
        fetchTickets();
      }
    } catch (err: any) {
      showToast(err?.message || 'Không thể gửi phản hồi.', 'error');
    } finally {
      setIsReopening(false);
    }
  };

  // Mở modal chỉnh sửa Ticket (chỉ khi NEW)
  const handleOpenEditModal = () => {
    if (!detailTicket) return;
    setEditFormData({
      title: detailTicket.title || '',
      location: detailTicket.location || '',
      priority: detailTicket.priority || 'MEDIUM',
      preferred_service_time: detailTicket.preferred_service_time
        ? detailTicket.preferred_service_time.substring(0, 16)
        : '',
      description: detailTicket.description || '',
      version: detailTicket.version || 1,
    });
    setIsEditModalOpen(true);
  };

  // Cập nhật Ticket qua PATCH
  const handleSaveEditTicket = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!detailTicket) return;

    if (!editFormData.title.trim() || !editFormData.description.trim() || !editFormData.location.trim()) {
      showToast('Vui lòng điền đầy đủ tiêu đề, vị trí và mô tả sự cố.', 'error');
      return;
    }

    setIsUpdating(true);
    try {
      const res = await api.updateResidentTicket(detailTicket.id, {
        title: editFormData.title.trim(),
        location: editFormData.location.trim(),
        priority: editFormData.priority,
        preferred_service_time: editFormData.preferred_service_time || null,
        description: editFormData.description.trim(),
        version: editFormData.version,
        updated_at: detailTicket.updated_at,
      });

      if (res && res.success) {
        showToast('Cập nhật thông tin sự cố thành công.', 'success');
        setDetailTicket(res.data);
        setIsEditModalOpen(false);
        fetchTickets();
      }
    } catch (err: any) {
      showToast(err?.message || 'Cập nhật thất bại. Vui lòng tải lại dữ liệu mới nhất.', 'error');
    } finally {
      setIsUpdating(false);
    }
  };

  return (
    <div className="max-w-6xl mx-auto space-y-6 animate-in fade-in duration-300">
      {/* Toast Notification */}
      {toast && (
        <div
          id="ticket-toast-banner"
          className={`fixed top-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-2xl shadow-xl text-xs font-semibold backdrop-blur-md border animate-in slide-in-from-top-3 duration-200 ${
            toast.type === 'success'
              ? 'bg-emerald-950/90 text-emerald-200 border-emerald-500/40'
              : toast.type === 'error'
              ? 'bg-rose-950/90 text-rose-200 border-rose-500/40'
              : 'bg-neutral-900/90 text-neutral-200 border-neutral-700'
          }`}
        >
          {toast.type === 'success' ? (
            <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
          ) : toast.type === 'error' ? (
            <AlertCircle className="w-4 h-4 text-rose-400 shrink-0" />
          ) : (
            <Info className="w-4 h-4 text-sky-400 shrink-0" />
          )}
          <span>{toast.message}</span>
          <button
            type="button"
            onClick={() => setToast(null)}
            className="ml-2 text-white/60 hover:text-white"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* Top Header & Breadcrumbs */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-1.5 text-xs text-neutral-400 uppercase tracking-wider font-semibold">
            <Wrench className="w-3.5 h-3.5 text-amber-500" />
            <span>HỖ TRỢ KỸ THUẬT & VẬN HÀNH</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight flex items-center gap-2.5">
            Báo cáo Sự cố & Sửa chữa
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200/60">
              Cư dân
            </span>
          </h1>
          <p className="text-xs sm:text-sm text-neutral-500">
            Tiếp nhận và theo dõi tiến độ xử lý sự cố điện, nước, tiện ích tòa nhà 24/7.
          </p>
        </div>

        {/* Tab Switcher Action Buttons */}
        <div className="flex items-center gap-2 self-start sm:self-auto">
          {viewMode !== 'list' && (
            <button
              id="ticket-back-to-list-btn"
              type="button"
              onClick={() => {
                setViewMode('list');
                setSelectedTicketId(null);
                setDetailTicket(null);
              }}
              className="inline-flex items-center gap-1.5 px-3.5 py-2.5 bg-white hover:bg-neutral-50 border border-neutral-200/80 rounded-xl text-xs font-semibold text-neutral-700 shadow-2xs hover:shadow-xs transition-all cursor-pointer"
            >
              <ArrowLeft className="w-3.5 h-3.5" />
              <span>Danh sách yêu cầu</span>
            </button>
          )}

          {viewMode === 'list' && (
            <button
              id="ticket-open-create-btn"
              type="button"
              onClick={() => setIsCreateModalOpen(true)}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-neutral-950 hover:bg-neutral-800 text-white rounded-xl text-xs font-semibold shadow-xs hover:shadow-md transition-all cursor-pointer"
            >
              <Plus className="w-3.5 h-3.5 text-amber-400" />
              <span>Gửi báo cáo sự cố mới</span>
            </button>
          )}
        </div>
      </div>

      {/* 4 Stat Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div
          onClick={() => {
            setStatusFilter('ALL');
            setViewMode('list');
          }}
          className={`p-3.5 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between ${
            statusFilter === 'ALL' && viewMode === 'list'
              ? 'bg-neutral-950 text-white border-neutral-950 shadow-md'
              : 'bg-white/80 backdrop-blur-md border-neutral-200/80 hover:border-neutral-300 shadow-2xs'
          }`}
        >
          <div className="text-[11px] font-semibold opacity-75">Tất cả yêu cầu</div>
          <div className="text-xl font-extrabold font-mono mt-1">{counters.all}</div>
        </div>

        <div
          onClick={() => {
            setStatusFilter('NEW');
            setViewMode('list');
          }}
          className={`p-3.5 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between ${
            statusFilter === 'NEW' && viewMode === 'list'
              ? 'bg-sky-600 text-white border-sky-600 shadow-md'
              : 'bg-white/80 backdrop-blur-md border-neutral-200/80 hover:border-sky-300 shadow-2xs'
          }`}
        >
          <div className="text-[11px] font-semibold opacity-75">Mới gửi</div>
          <div className="text-xl font-extrabold font-mono mt-1 text-sky-600 data-selected:text-white">
            {counters.new}
          </div>
        </div>

        <div
          onClick={() => {
            setStatusFilter('IN_PROGRESS');
            setViewMode('list');
          }}
          className={`p-3.5 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between ${
            statusFilter === 'IN_PROGRESS' && viewMode === 'list'
              ? 'bg-amber-600 text-white border-amber-600 shadow-md'
              : 'bg-white/80 backdrop-blur-md border-neutral-200/80 hover:border-amber-300 shadow-2xs'
          }`}
        >
          <div className="text-[11px] font-semibold opacity-75">Đang xử lý</div>
          <div className="text-xl font-extrabold font-mono mt-1 text-amber-600 data-selected:text-white">
            {counters.in_progress}
          </div>
        </div>

        <div
          onClick={() => {
            setStatusFilter('RESOLVED');
            setViewMode('list');
          }}
          className={`p-3.5 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between ${
            statusFilter === 'RESOLVED' && viewMode === 'list'
              ? 'bg-purple-600 text-white border-purple-600 shadow-md'
              : 'bg-white/80 backdrop-blur-md border-neutral-200/80 hover:border-purple-300 shadow-2xs'
          }`}
        >
          <div className="text-[11px] font-semibold opacity-75">Chờ nghiệm thu</div>
          <div className="text-xl font-extrabold font-mono mt-1 text-purple-600 data-selected:text-white">
            {counters.resolved}
          </div>
        </div>

        <div
          onClick={() => {
            setStatusFilter('CLOSED');
            setViewMode('list');
          }}
          className={`p-3.5 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between ${
            statusFilter === 'CLOSED' && viewMode === 'list'
              ? 'bg-emerald-600 text-white border-emerald-600 shadow-md'
              : 'bg-white/80 backdrop-blur-md border-neutral-200/80 hover:border-emerald-300 shadow-2xs'
          }`}
        >
          <div className="text-[11px] font-semibold opacity-75">Đã hoàn tất</div>
          <div className="text-xl font-extrabold font-mono mt-1 text-emerald-600 data-selected:text-white">
            {counters.closed}
          </div>
        </div>

        <div
          onClick={() => {
            setStatusFilter('CANCELLED');
            setViewMode('list');
          }}
          className={`p-3.5 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between ${
            statusFilter === 'CANCELLED' && viewMode === 'list'
              ? 'bg-neutral-600 text-white border-neutral-600 shadow-md'
              : 'bg-white/80 backdrop-blur-md border-neutral-200/80 hover:border-neutral-300 shadow-2xs'
          }`}
        >
          <div className="text-[11px] font-semibold opacity-75">Đã hủy</div>
          <div className="text-xl font-extrabold font-mono mt-1 text-neutral-500 data-selected:text-white">
            {counters.cancelled}
          </div>
        </div>
      </div>

      {/* ================= MODAL: FORM BÁO CÁO SỰ CỐ MỚI (POPUP GIỮA MÀN HÌNH) ================= */}
      {isCreateModalOpen && (
        <div
          id="ticket-create-modal"
          className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-4 overflow-y-auto animate-in fade-in duration-200"
          onClick={(e) => {
            if (e.target === e.currentTarget) {
              setIsCreateModalOpen(false);
            }
          }}
        >
          <div className="bg-white rounded-3xl border border-neutral-200/90 shadow-2xl max-w-3xl w-full max-h-[92vh] overflow-y-auto custom-scrollbar p-5 sm:p-8 animate-in zoom-in-95 duration-200 my-auto relative">
            {/* Modal Header */}
            <div className="flex items-start justify-between border-b border-neutral-100 pb-4 mb-6">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-600 flex items-center justify-center shrink-0">
                  <Wrench className="w-5 h-5" />
                </div>
                <div>
                  <h2 className="text-base sm:text-lg font-bold text-neutral-900">
                    Gửi yêu cầu Báo cáo Sự cố Kỹ thuật
                  </h2>
                  <p className="text-xs text-neutral-500 mt-0.5">
                    Vui lòng điền thông tin chính xác về tình trạng hư hỏng để kỹ thuật viên chuẩn bị dụng cụ phù hợp.
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsCreateModalOpen(false)}
                className="text-neutral-400 hover:text-neutral-700 p-1.5 rounded-xl hover:bg-neutral-100 transition-colors"
                title="Đóng cửa sổ"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSubmitTicket} className="space-y-6">
              {/* SECTION 1: THÔNG TIN CƯ DÂN & CĂN HỘ */}
              <div className="space-y-3">
                <div className="flex items-center gap-2 text-xs font-bold text-neutral-900 uppercase tracking-wider">
                  <span className="w-5 h-5 rounded-full bg-neutral-900 text-white flex items-center justify-center text-[11px]">
                    1
                  </span>
                  <span>Thông tin Cư dân & Căn hộ xảy ra sự cố</span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-neutral-50/70 p-3.5 rounded-xl border border-neutral-200/60 text-xs">
                  <div>
                    <label className="block text-neutral-500 font-medium mb-1">Họ tên cư dân</label>
                    <input
                      type="text"
                      disabled
                      value={currentUser?.full_name || 'Nguyễn Văn An'}
                      className="w-full px-3 py-2 bg-neutral-100 border border-neutral-200 rounded-lg text-neutral-700 font-semibold cursor-not-allowed"
                    />
                  </div>

                  <div>
                    <label className="block text-neutral-500 font-medium mb-1">Số điện thoại liên hệ</label>
                    <input
                      type="text"
                      disabled
                      value={currentUser?.phone_number || '0909 123 456'}
                      className="w-full px-3 py-2 bg-neutral-100 border border-neutral-200 rounded-lg text-neutral-700 font-semibold cursor-not-allowed"
                    />
                  </div>

                  <div>
                    <label htmlFor="ticket-apartment-select" className="block text-neutral-700 font-bold mb-1">
                      Căn hộ / Tòa nhà <span className="text-rose-500">*</span>
                    </label>
                    <div className="relative">
                      <select
                        id="ticket-apartment-select"
                        value={formData.apartment_id}
                        onChange={(e) => setFormData({ ...formData, apartment_id: e.target.value })}
                        className="w-full appearance-none px-3.5 py-2 pr-9 bg-white border border-neutral-200 focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900 rounded-xl text-xs text-neutral-900 font-semibold outline-none cursor-pointer shadow-2xs transition-all resident-ticket-select"
                      >
                        {apartments.length === 0 ? (
                          <option value="">Căn hộ mặc định</option>
                        ) : (
                          apartments.map((apt) => (
                            <option key={apt.id} value={apt.id}>
                              Căn {apt.apartment_number} {apt.block_name ? `(${apt.block_name})` : ''}
                            </option>
                          ))
                        )}
                      </select>
                      <ChevronDown className="w-4 h-4 text-neutral-400 pointer-events-none absolute right-3 top-1/2 -translate-y-1/2" />
                    </div>
                  </div>
                </div>
              </div>

              {/* SECTION 2: CHI TIẾT SỰ CỐ KỸ THUẬT */}
              <div className="space-y-3">
                <div className="flex items-center gap-2 text-xs font-bold text-neutral-900 uppercase tracking-wider">
                  <span className="w-5 h-5 rounded-full bg-neutral-900 text-white flex items-center justify-center text-[11px]">
                    2
                  </span>
                  <span>Nội dung chi tiết sự cố</span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                  {/* Tiêu đề sự cố */}
                  <div className="sm:col-span-2">
                    <label htmlFor="ticket-title-input" className="block text-xs font-bold text-neutral-800 mb-1.5">
                      Tiêu đề sự cố <span className="text-rose-500">*</span>
                    </label>
                    <input
                      id="ticket-title-input"
                      type="text"
                      placeholder="Ví dụ: Rò rỉ vòi rửa chén, chập công tắc đèn phòng khách..."
                      value={formData.title}
                      onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                      className={`w-full px-3.5 py-2.5 rounded-xl border text-xs text-neutral-900 bg-white placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all ${
                        formErrors.title ? 'border-rose-400 bg-rose-50/20' : 'border-neutral-200'
                      }`}
                    />
                    {formErrors.title && (
                      <p className="text-[11px] text-rose-600 font-medium mt-1 flex items-center gap-1">
                        <AlertCircle className="w-3 h-3" />
                        <span>{formErrors.title}</span>
                      </p>
                    )}
                  </div>

                  {/* Loại sự cố (Category) */}
                  <div>
                    <label htmlFor="ticket-category-select" className="block text-xs font-bold text-neutral-800 mb-1.5">
                      Phân loại sự cố <span className="text-rose-500">*</span>
                    </label>
                    <div className="relative">
                      <select
                        id="ticket-category-select"
                        value={formData.category_id}
                        onChange={(e) => setFormData({ ...formData, category_id: e.target.value })}
                        className={`w-full appearance-none px-3.5 py-2.5 pr-9 rounded-xl border text-xs text-neutral-900 font-medium bg-white focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all cursor-pointer shadow-2xs resident-ticket-select ${
                          formErrors.category_id ? 'border-rose-400' : 'border-neutral-200'
                        }`}
                      >
                        <option value="">-- Chọn danh mục sự cố --</option>
                        {categories.map((c) => (
                          <option key={c.id} value={c.id}>
                            {c.category_name}
                          </option>
                        ))}
                      </select>
                      <ChevronDown className="w-4 h-4 text-neutral-400 pointer-events-none absolute right-3 top-1/2 -translate-y-1/2" />
                    </div>
                    {formErrors.category_id && (
                      <p className="text-[11px] text-rose-600 font-medium mt-1">{formErrors.category_id}</p>
                    )}
                  </div>

                  {/* Vị trí sự cố (Location) */}
                  <div>
                    <label htmlFor="ticket-location-input" className="block text-xs font-bold text-neutral-800 mb-1.5">
                      Vị trí xảy ra sự cố <span className="text-rose-500">*</span>
                    </label>
                    <input
                      id="ticket-location-input"
                      type="text"
                      placeholder="Ví dụ: Phòng tắm lớn, Ban công, Khu bếp..."
                      value={formData.location}
                      onChange={(e) => setFormData({ ...formData, location: e.target.value })}
                      className={`w-full px-3.5 py-2.5 rounded-xl border text-xs text-neutral-900 bg-white placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all ${
                        formErrors.location ? 'border-rose-400 bg-rose-50/20' : 'border-neutral-200'
                      }`}
                    />
                    {/* Quick location chips */}
                    <div className="flex flex-wrap items-center gap-1.5 mt-2">
                      <span className="text-[10px] text-neutral-400 font-medium">Gợi ý nhanh:</span>
                      {['Phòng khách', 'Khu vực bếp', 'Phòng tắm', 'Ban công', 'Cửa ra vào', 'Hành lang'].map((loc) => (
                        <button
                          type="button"
                          key={loc}
                          onClick={() => setFormData({ ...formData, location: loc })}
                          className="text-[10px] px-2 py-0.5 rounded-md bg-neutral-100 hover:bg-neutral-200 text-neutral-600 transition-colors"
                        >
                          {loc}
                        </button>
                      ))}
                    </div>
                    {formErrors.location && (
                      <p className="text-[11px] text-rose-600 font-medium mt-1">{formErrors.location}</p>
                    )}
                  </div>

                  {/* Mức độ khẩn cấp (Priority) */}
                  <div>
                    <label htmlFor="ticket-priority-select" className="block text-xs font-bold text-neutral-800 mb-1.5">
                      Mức độ khẩn cấp
                    </label>
                    <div className="relative">
                      <select
                        id="ticket-priority-select"
                        value={formData.priority}
                        onChange={(e) => setFormData({ ...formData, priority: e.target.value })}
                        className="w-full appearance-none px-3.5 py-2.5 pr-9 rounded-xl border border-neutral-200 text-xs text-neutral-900 font-medium bg-white focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all cursor-pointer shadow-2xs resident-ticket-select"
                      >
                        <option value="LOW">Thấp (Chưa ảnh hưởng sinh hoạt ngay)</option>
                        <option value="MEDIUM">Bình thường (Cần kiểm tra trong 24h)</option>
                        <option value="HIGH">Ưu tiên cao (Ảnh hưởng trực tiếp sinh hoạt)</option>
                        <option value="URGENT">Khẩn cấp (Rò rỉ nước lớn, chập điện nguy hiểm)</option>
                      </select>
                      <ChevronDown className="w-4 h-4 text-neutral-400 pointer-events-none absolute right-3 top-1/2 -translate-y-1/2" />
                    </div>
                  </div>

                  {/* Ngày mong muốn tiếp nhận (Chỉ chọn ngày tháng năm, không chọn giờ) */}
                  <div>
                    <label htmlFor="ticket-preferred-time-input" className="block text-xs font-bold text-neutral-800 mb-1.5">
                      Ngày thuận tiện kỹ thuật đến kiểm tra
                    </label>
                    <input
                      id="ticket-preferred-time-input"
                      type="date"
                      min={new Date().toISOString().split('T')[0]}
                      value={formData.preferred_service_time}
                      onChange={(e) => setFormData({ ...formData, preferred_service_time: e.target.value })}
                      className="w-full px-3.5 py-2.5 rounded-xl border border-neutral-200 text-xs text-neutral-900 font-medium bg-white focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all cursor-pointer shadow-2xs"
                    />
                    <span className="text-[10px] text-neutral-400 mt-1 block">
                      Không bắt buộc. Kỹ thuật viên sẽ liên hệ hẹn giờ cụ thể trước khi đến.
                    </span>
                  </div>

                  {/* Mô tả chi tiết sự cố */}
                  <div className="sm:col-span-2">
                    <label htmlFor="ticket-description-textarea" className="block text-xs font-bold text-neutral-800 mb-1.5">
                      Mô tả chi tiết sự cố <span className="text-rose-500">*</span>
                    </label>
                    <textarea
                      id="ticket-description-textarea"
                      rows={4}
                      placeholder="Mô tả hiện tượng, triệu chứng hỏng hóc, thời điểm bắt đầu xảy ra..."
                      value={formData.description}
                      onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                      className={`w-full px-3.5 py-2.5 rounded-xl border text-xs text-neutral-900 bg-white placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all ${
                        formErrors.description ? 'border-rose-400 bg-rose-50/20' : 'border-neutral-200'
                      }`}
                    />
                    {formErrors.description && (
                      <p className="text-[11px] text-rose-600 font-medium mt-1">{formErrors.description}</p>
                    )}
                  </div>
                </div>
              </div>

              {/* SECTION 3: HÌNH ẢNH MINH CHỨNG */}
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2 text-xs font-bold text-neutral-900 uppercase tracking-wider">
                    <span className="w-5 h-5 rounded-full bg-neutral-900 text-white flex items-center justify-center text-[11px]">
                      3
                    </span>
                    <span>Hình ảnh minh chứng thực tế (Tối đa 5 ảnh)</span>
                  </div>
                  <span className="text-[11px] text-neutral-400 font-medium font-mono">
                    {selectedFiles.length} / 5 ảnh
                  </span>
                </div>

                {/* Upload Dropzone */}
                <div
                  onClick={() => fileInputRef.current?.click()}
                  className="border-2 border-dashed border-neutral-300 hover:border-neutral-900 rounded-2xl p-5 text-center cursor-pointer transition-all bg-neutral-50/50 hover:bg-neutral-50 group"
                >
                  <input
                    ref={fileInputRef}
                    id="ticket-photo-upload"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/jpg"
                    multiple
                    onChange={handleFileChange}
                    className="hidden"
                  />
                  <div className="w-10 h-10 rounded-full bg-white shadow-2xs border border-neutral-200 flex items-center justify-center mx-auto text-neutral-500 group-hover:scale-110 group-hover:text-neutral-900 transition-all">
                    <Camera className="w-5 h-5" />
                  </div>
                  <p className="text-xs font-bold text-neutral-800 mt-2">
                    Bấm để tải ảnh lên hoặc kéo thả vào đây
                  </p>
                  <p className="text-[11px] text-neutral-400 mt-0.5">
                    Định dạng: JPG, PNG, WebP · Dung lượng tối đa: 5MB/ảnh
                  </p>
                </div>

                {/* Previews Thumbnails */}
                {filePreviews.length > 0 && (
                  <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 pt-2">
                    {filePreviews.map((url, idx) => (
                      <div
                        key={idx}
                        className="relative group rounded-xl overflow-hidden border border-neutral-200 bg-neutral-100 aspect-square"
                      >
                        <img src={url} alt={`preview-${idx}`} className="w-full h-full object-cover" />
                        <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                          <button
                            type="button"
                            onClick={() => setPreviewImage(url)}
                            className="p-1.5 rounded-lg bg-white/90 text-neutral-800 hover:bg-white text-xs"
                            title="Xem ảnh lớn"
                          >
                            <Eye className="w-3.5 h-3.5" />
                          </button>
                          <button
                            type="button"
                            onClick={() => removeFile(idx)}
                            className="p-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700 text-xs"
                            title="Xóa ảnh này"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                        <div className="absolute bottom-1 right-1 px-1.5 py-0.5 rounded bg-black/60 text-white text-[9px] font-mono">
                          {Math.round(selectedFiles[idx]?.size / 1024)} KB
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* Action Buttons */}
              <div className="pt-5 border-t border-neutral-100 flex flex-col sm:flex-row items-center justify-end gap-2.5">
                <button
                  id="ticket-back-to-list-btn"
                  type="button"
                  onClick={() => setIsCreateModalOpen(false)}
                  className="w-full sm:w-auto px-4 py-2.5 rounded-xl border border-neutral-200 text-neutral-700 hover:bg-neutral-100 text-xs font-semibold transition-all cursor-pointer"
                >
                  Đóng
                </button>
                <button
                  type="button"
                  onClick={handleResetForm}
                  disabled={isSubmitting}
                  className="w-full sm:w-auto px-4 py-2.5 rounded-xl border border-neutral-200 text-neutral-600 hover:bg-neutral-100 text-xs font-semibold transition-all cursor-pointer disabled:opacity-50"
                >
                  Đặt lại biểu mẫu
                </button>
                <button
                  id="ticket-submit-btn"
                  type="submit"
                  disabled={isSubmitting}
                  className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white text-xs font-semibold shadow-xs hover:shadow-md transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {isSubmitting ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      <span>Đang gửi báo cáo sự cố...</span>
                    </>
                  ) : (
                    <>
                      <Send className="w-3.5 h-3.5 text-amber-400" />
                      <span>Gửi yêu cầu hỗ trợ</span>
                    </>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ================= VIEW 2: DANH SÁCH TICKET ================= */}
      {viewMode === 'list' && (
        <div className="space-y-4">
          {/* Filter Bar */}
          <div className="bg-white/80 backdrop-blur-md border border-neutral-200/80 rounded-2xl p-4 shadow-xs space-y-3">
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
              {/* Search Box */}
              <div className="sm:col-span-2 relative">
                <Search className="w-4 h-4 text-neutral-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                <input
                  id="ticket-search-input"
                  type="text"
                  placeholder="Tìm theo mã ticket, tiêu đề, vị trí sự cố..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-neutral-200 text-xs text-neutral-900 bg-white placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all"
                />
              </div>

              {/* Status Filter */}
              <div className="relative">
                <select
                  id="ticket-status-filter"
                  value={statusFilter}
                  onChange={(e) => {
                    setStatusFilter(e.target.value);
                    setCurrentPage(1);
                  }}
                  className="w-full appearance-none px-3.5 py-2.5 pr-9 rounded-xl border border-neutral-200 text-xs font-semibold text-neutral-800 bg-white focus:outline-none focus:ring-2 focus:ring-neutral-900 cursor-pointer shadow-2xs resident-ticket-select transition-all"
                >
                  <option value="ALL">-- Tất cả trạng thái --</option>
                  <option value="NEW">Mới gửi</option>
                  <option value="IN_PROGRESS">Đang xử lý</option>
                  <option value="RESOLVED">Chờ nghiệm thu</option>
                  <option value="CLOSED">Đã hoàn tất</option>
                  <option value="CANCELLED">Đã hủy</option>
                </select>
                <ChevronDown className="w-4 h-4 text-neutral-400 pointer-events-none absolute right-3 top-1/2 -translate-y-1/2" />
              </div>

              {/* Category Filter */}
              <div className="relative">
                <select
                  id="ticket-category-filter"
                  value={categoryFilter}
                  onChange={(e) => {
                    setCategoryFilter(e.target.value);
                    setCurrentPage(1);
                  }}
                  className="w-full appearance-none px-3.5 py-2.5 pr-9 rounded-xl border border-neutral-200 text-xs font-semibold text-neutral-800 bg-white focus:outline-none focus:ring-2 focus:ring-neutral-900 cursor-pointer shadow-2xs resident-ticket-select transition-all"
                >
                  <option value="ALL">-- Tất cả loại sự cố --</option>
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.category_name}
                    </option>
                  ))}
                </select>
                <ChevronDown className="w-4 h-4 text-neutral-400 pointer-events-none absolute right-3 top-1/2 -translate-y-1/2" />
              </div>
            </div>

            {/* Reset / Reload helper */}
            <div className="flex items-center justify-between text-xs text-neutral-500 pt-1">
              <span>
                Tìm thấy <strong className="text-neutral-900 font-mono">{totalTickets}</strong> yêu cầu
              </span>
              <button
                type="button"
                onClick={() => fetchTickets()}
                className="inline-flex items-center gap-1.5 text-neutral-600 hover:text-neutral-900 font-semibold cursor-pointer"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin' : ''}`} />
                <span>Làm mới</span>
              </button>
            </div>
          </div>

          {/* Table Container */}
          <div className="border border-neutral-200/80 bg-white/90 backdrop-blur-md rounded-2xl overflow-hidden shadow-xs">
            {isLoading ? (
              <div className="p-12 text-center space-y-3">
                <Loader2 className="w-8 h-8 animate-spin text-neutral-400 mx-auto" />
                <p className="text-xs text-neutral-500 font-medium">Đang tải danh sách yêu cầu sự cố...</p>
              </div>
            ) : loadError ? (
              <div className="p-12 text-center space-y-3">
                <AlertCircle className="w-8 h-8 text-rose-500 mx-auto" />
                <p className="text-xs text-neutral-700 font-medium">{loadError}</p>
                <button
                  type="button"
                  onClick={() => fetchTickets()}
                  className="px-4 py-2 bg-neutral-900 text-white rounded-xl text-xs font-semibold hover:bg-neutral-800"
                >
                  Thử lại
                </button>
              </div>
            ) : tickets.length === 0 ? (
              <div className="p-16 text-center space-y-4">
                <div className="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto border border-amber-100">
                  <Wrench className="w-6 h-6" />
                </div>
                <div className="space-y-1">
                  <h3 className="text-sm font-bold text-neutral-900">Chưa có yêu cầu báo cáo sự cố nào</h3>
                  <p className="text-xs text-neutral-400 max-w-sm mx-auto">
                    Khi xảy ra sự cố kỹ thuật về điện, nước hoặc hạ tầng căn hộ, bạn có thể gửi yêu cầu để kỹ thuật viên đến hỗ trợ.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setIsCreateModalOpen(true)}
                  className="inline-flex items-center gap-1.5 px-4 py-2 bg-neutral-950 hover:bg-neutral-800 text-white rounded-xl text-xs font-semibold shadow-xs transition-all cursor-pointer"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Tạo yêu cầu đầu tiên</span>
                </button>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="border-b border-neutral-200/70 bg-neutral-50/70 text-neutral-500 font-semibold uppercase text-[11px] tracking-wider">
                      <th className="py-3 px-4">MÃ TICKET</th>
                      <th className="py-3 px-4">TIÊU ĐỀ & VỊ TRÍ</th>
                      <th className="py-3 px-4">LOẠI SỰ CỐ</th>
                      <th className="py-3 px-4">MỨC ĐỘ</th>
                      <th className="py-3 px-4">TRẠNG THÁI</th>
                      <th className="py-3 px-4">NGÀY GỬI</th>
                      <th className="py-3 px-4 text-right">THAO TÁC</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-neutral-200/60 text-neutral-800">
                    {tickets.map((t) => {
                      const st = STATUS_CONFIG[t.status] || STATUS_CONFIG.NEW;
                      const pr = PRIORITY_CONFIG[t.priority] || PRIORITY_CONFIG.MEDIUM;
                      return (
                        <tr
                          key={t.id}
                          className="hover:bg-neutral-50/90 transition-colors group cursor-pointer"
                          onClick={() => fetchTicketDetail(t.id)}
                        >
                          <td className="py-3.5 px-4 font-mono font-bold text-neutral-900">
                            <span className="hover:underline">{t.ticket_number}</span>
                            {t.attachments && t.attachments.length > 0 && (
                              <span className="ml-1.5 inline-flex items-center gap-0.5 px-1.5 py-0.2 rounded text-[10px] bg-neutral-100 text-neutral-600 font-sans">
                                📎 {t.attachments.length}
                              </span>
                            )}
                          </td>
                          <td className="py-3.5 px-4">
                            <div className="font-semibold text-neutral-900 group-hover:text-amber-700 transition-colors">
                              {t.title}
                            </div>
                            <div className="text-[11px] text-neutral-400 flex items-center gap-1 mt-0.5">
                              <MapPin className="w-3 h-3 text-neutral-400" />
                              <span>{t.location || 'Tại căn hộ'}</span>
                            </div>
                          </td>
                          <td className="py-3.5 px-4 text-neutral-600">
                            {t.category?.category_name || 'Kỹ thuật'}
                          </td>
                          <td className="py-3.5 px-4">
                            <span
                              className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border ${pr.bg} ${pr.text} ${pr.border}`}
                            >
                              {pr.label}
                            </span>
                          </td>
                          <td className="py-3.5 px-4">
                            <span
                              className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold border ${st.bg} ${st.text} ${st.border}`}
                            >
                              <span className={`w-1.5 h-1.5 rounded-full ${st.dot}`} />
                              {st.label}
                            </span>
                          </td>
                          <td className="py-3.5 px-4 text-neutral-500 font-mono text-[11px]">
                            {formatDateTime(t.created_at)}
                          </td>
                          <td className="py-3.5 px-4 text-right">
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                fetchTicketDetail(t.id);
                              }}
                              className="px-3 py-1.5 rounded-lg bg-neutral-100 hover:bg-neutral-900 hover:text-white text-neutral-700 font-semibold text-xs transition-all cursor-pointer"
                            >
                              Chi tiết →
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}

            {/* Pagination Controls */}
            {lastPage > 1 && (
              <div className="p-4 border-t border-neutral-100 flex items-center justify-between text-xs text-neutral-500">
                <span>
                  Trang <strong className="text-neutral-900">{currentPage}</strong> / {lastPage}
                </span>
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    disabled={currentPage <= 1}
                    onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                    className="p-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed"
                  >
                    <ChevronLeft className="w-4 h-4" />
                  </button>
                  <button
                    type="button"
                    disabled={currentPage >= lastPage}
                    onClick={() => setCurrentPage((p) => Math.min(lastPage, p + 1))}
                    className="p-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed"
                  >
                    <ChevronRight className="w-4 h-4" />
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ================= VIEW 3: CHI TIẾT TICKET ================= */}
      {viewMode === 'detail' && detailTicket && (
        <div className="space-y-6 animate-in fade-in duration-200">
          {/* Header Card */}
          <div className="bg-white/90 backdrop-blur-md border border-neutral-200/80 rounded-2xl p-6 shadow-xs space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-neutral-100 pb-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="font-mono text-xs font-extrabold px-2.5 py-1 rounded-md bg-neutral-900 text-white">
                    {detailTicket.ticket_number}
                  </span>
                  {/* Status Badge */}
                  {(() => {
                    const st = STATUS_CONFIG[detailTicket.status] || STATUS_CONFIG.NEW;
                    return (
                      <span
                        className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border ${st.bg} ${st.text} ${st.border}`}
                      >
                        <span className={`w-1.5 h-1.5 rounded-full ${st.dot}`} />
                        {st.label}
                      </span>
                    );
                  })()}
                  {/* Priority Badge */}
                  {(() => {
                    const pr = PRIORITY_CONFIG[detailTicket.priority] || PRIORITY_CONFIG.MEDIUM;
                    return (
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold border ${pr.bg} ${pr.text} ${pr.border}`}
                      >
                        Ưu tiên: {pr.label}
                      </span>
                    );
                  })()}
                </div>
                <h2 className="text-xl font-extrabold text-neutral-900 pt-1">
                  {detailTicket.title}
                </h2>
              </div>

              {/* Action Buttons for Ticket Lifecycle */}
              <div className="flex items-center gap-2 flex-wrap">
                {/* Khi NEW: Cho phép sửa nội dung hoặc hủy */}
                {detailTicket.status === 'NEW' && (
                  <>
                    <button
                      type="button"
                      onClick={handleOpenEditModal}
                      className="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-neutral-200 rounded-xl text-xs font-semibold text-neutral-800 hover:bg-neutral-50 shadow-2xs"
                    >
                      <Pencil className="w-3.5 h-3.5 text-neutral-500" />
                      <span>Chỉnh sửa</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => setIsCancelModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-3 py-2 bg-rose-50 border border-rose-200 rounded-xl text-xs font-semibold text-rose-700 hover:bg-rose-100"
                    >
                      <XCircle className="w-3.5 h-3.5" />
                      <span>Hủy yêu cầu</span>
                    </button>
                  </>
                )}

                {/* Khi RECEIVED: Cho phép hủy */}
                {detailTicket.status === 'RECEIVED' && (
                  <button
                    type="button"
                    onClick={() => setIsCancelModalOpen(true)}
                    className="inline-flex items-center gap-1.5 px-3 py-2 bg-rose-50 border border-rose-200 rounded-xl text-xs font-semibold text-rose-700 hover:bg-rose-100"
                  >
                    <XCircle className="w-3.5 h-3.5" />
                    <span>Hủy yêu cầu</span>
                  </button>
                )}

                {/* Khi RESOLVED: Cho phép Nghiệm thu đóng ticket hoặc Báo chưa sửa xong */}
                {detailTicket.status === 'RESOLVED' && (
                  <>
                    <button
                      type="button"
                      onClick={() => setIsReopenModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-3.5 py-2 bg-amber-50 border border-amber-200 rounded-xl text-xs font-semibold text-amber-700 hover:bg-amber-100"
                    >
                      <AlertTriangle className="w-3.5 h-3.5" />
                      <span>Chưa khắc phục xong</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => setIsConfirmModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-semibold shadow-xs"
                    >
                      <CheckCircle2 className="w-3.5 h-3.5" />
                      <span>Xác nhận đã sửa xong</span>
                    </button>
                  </>
                )}
              </div>
            </div>

            {/* Grid 4 Overview Blocks */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4 text-xs bg-neutral-50/70 p-4 rounded-xl border border-neutral-200/60">
              <div>
                <span className="text-neutral-400 block mb-0.5">Phân loại sự cố</span>
                <span className="font-semibold text-neutral-800">
                  {detailTicket.category?.category_name || 'Chung'}
                </span>
              </div>
              <div>
                <span className="text-neutral-400 block mb-0.5">Vị trí</span>
                <span className="font-semibold text-neutral-800 flex items-center gap-1">
                  <MapPin className="w-3.5 h-3.5 text-neutral-400" />
                  {detailTicket.location || 'Tại căn hộ'}
                </span>
              </div>
              <div>
                <span className="text-neutral-400 block mb-0.5">Căn hộ liên quan</span>
                <span className="font-semibold text-neutral-800">
                  Căn {detailTicket.apartment?.apartment_number || '---'} · {detailTicket.apartment?.block?.block_name || ''}
                </span>
              </div>
              <div>
                <span className="text-neutral-400 block mb-0.5">Hạn SLA dự kiến</span>
                <span className="font-semibold text-neutral-800 font-mono">
                  {formatDateTime(detailTicket.sla_deadline)}
                </span>
              </div>
            </div>

            {/* Detailed Description */}
            <div className="space-y-1.5 text-xs">
              <span className="font-bold text-neutral-700 block">Mô tả sự cố của cư dân:</span>
              <div className="p-4 rounded-xl bg-white border border-neutral-200/80 text-neutral-800 leading-relaxed whitespace-pre-line font-normal">
                {detailTicket.description}
              </div>
            </div>

            {/* Attached Photos */}
            {(detailTicket.attachments?.length || detailTicket.status === 'NEW') && (
              <div className="space-y-2 pt-2">
                <span className="font-bold text-neutral-700 text-xs block">
                  Hình ảnh minh chứng ({detailTicket.attachments?.length || 0} ảnh):
                </span>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                  {detailTicket.attachments?.map((att) => (
                    <div key={att.id} className="space-y-1"><TicketImage url={att.file_url} name={att.file_name} onPreview={setPreviewImage} />
                      {detailTicket.status === 'NEW' && <button type="button" disabled={imageBusy} onClick={() => setRemoveImageId(att.id)} className="text-xs text-rose-600 cursor-pointer">Bỏ ảnh {att.file_name}</button>}
                    </div>
                  ))}
                </div>
                {detailTicket.status === 'NEW' && <label className="block text-xs">Thêm ảnh (tối đa 5 ảnh/Ticket)
                  <input aria-label="Thêm ảnh sự cố" type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={imageBusy} onChange={async (event) => {
                    const files = Array.from(event.target.files || []); event.target.value = ''; if (!files.length) return;
                    setImageBusy(true);
                    try { const res = await api.addTicketImages(detailTicket.id, files, detailTicket.version); setDetailTicket(res.data); showToast('Đã thêm ảnh.'); }
                    catch (error: any) { showToast(error.message, 'error'); if (error.status === 409) void fetchTicketDetail(detailTicket.id); }
                    finally { setImageBusy(false); }
                  }} className="block mt-2" />
                </label>}
              </div>
            )}
          </div>

          {/* Timeline Lịch sử tiến độ xử lý */}
          <div className="bg-white/90 backdrop-blur-md border border-neutral-200/80 rounded-2xl p-6 shadow-xs space-y-4">
            <h3 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
              <Clock className="w-4 h-4 text-neutral-500" />
              <span>Tiến độ xử lý & Lịch sử cập nhật trạng thái</span>
            </h3>

            {detailTicket.history && detailTicket.history.length > 0 ? (
              <div className="relative pl-6 space-y-6 before:absolute before:left-2.5 before:top-2 before:bottom-2 before:w-0.5 before:bg-neutral-200">
                {detailTicket.history.map((h, idx) => (
                  <div key={h.id || idx} className="relative text-xs">
                    <span className="absolute -left-6 top-1 w-3 h-3 rounded-full bg-white border-2 border-neutral-900" />
                    <div className="flex items-center gap-2 font-mono text-[11px] text-neutral-400">
                      <span>{formatDateTime(h.created_at)}</span>
                      <span>•</span>
                      <span className="font-sans font-semibold text-neutral-700">
                        {h.performer?.full_name || 'Hệ thống'}
                      </span>
                    </div>
                    <div className="font-semibold text-neutral-900 mt-0.5">
                      {h.action_type === 'CREATED' && 'Tạo mới yêu cầu báo cáo sự cố'}
                      {h.action_type === 'STATUS_CHANGED' && (
                        <span>
                          Chuyển trạng thái từ{' '}
                          <strong className="text-neutral-600">{h.old_status}</strong> sang{' '}
                          <strong className="text-neutral-950">{h.new_status}</strong>
                        </span>
                      )}
                      {h.action_type === 'UPDATED' && 'Cư dân cập nhật nội dung sự cố'}
                      {h.action_type === 'ASSIGNED' && 'Phân công kỹ thuật viên tiếp nhận'}
                    </div>
                    {h.notes && (
                      <p className="text-neutral-600 bg-neutral-50 p-2.5 rounded-lg border border-neutral-100 mt-1.5 leading-relaxed">
                        {h.notes}
                      </p>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-xs text-neutral-400 italic">Chưa có lịch sử thay đổi.</p>
            )}
          </div>
        </div>
      )}

      {/* ================= MODAL: HỦY TICKET ================= */}
      {isCancelModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-2xl border border-neutral-200 shadow-2xl max-w-md w-full p-6 space-y-4">
            <div className="flex items-start justify-between">
              <div className="flex items-center gap-2 text-rose-600">
                <AlertCircle className="w-5 h-5 shrink-0" />
                <h3 className="font-bold text-base text-neutral-950">Xác nhận hủy yêu cầu</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsCancelModalOpen(false)}
                className="text-neutral-400 hover:text-neutral-700"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <p className="text-xs text-neutral-600">
              Bạn có chắc chắn muốn hủy yêu cầu <strong>{detailTicket?.ticket_number}</strong>? Sau khi hủy, kỹ thuật viên sẽ không đến kiểm tra sự cố này.
            </p>
            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Lý do hủy yêu cầu (Không bắt buộc)
              </label>
              <textarea
                rows={3}
                placeholder="Ví dụ: Đã tự khắc phục được, gửi nhầm căn hộ..."
                value={cancelReason}
                onChange={(e) => setCancelReason(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
              />
            </div>
            <div className="flex items-center justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => setIsCancelModalOpen(false)}
                className="px-4 py-2 rounded-xl border border-neutral-200 text-xs font-semibold text-neutral-600 hover:bg-neutral-100"
              >
                Đóng
              </button>
              <button
                type="button"
                disabled={isCancelling}
                onClick={handleConfirmCancel}
                className="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold disabled:opacity-50"
              >
                {isCancelling ? 'Đang hủy...' : 'Đồng ý hủy'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL: XÁC NHẬN HOÀN TẤT ================= */}
      {isConfirmModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-2xl border border-neutral-200 shadow-2xl max-w-md w-full p-6 space-y-4">
            <div className="flex items-start justify-between">
              <div className="flex items-center gap-2 text-emerald-600">
                <CheckCircle2 className="w-5 h-5 shrink-0" />
                <h3 className="font-bold text-base text-neutral-950">Xác nhận nghiệm thu & Hoàn tất</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsConfirmModalOpen(false)}
                className="text-neutral-400 hover:text-neutral-700"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <p className="text-xs text-neutral-600">
              Bạn xác nhận kỹ thuật viên đã sửa chữa sự cố đạt yêu cầu. Yêu cầu sẽ được chuyển sang trạng thái <strong>Đã hoàn tất (CLOSED)</strong>.
            </p>
            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Ghi chú hoặc đánh giá chất lượng (Tùy chọn)
              </label>
              <textarea
                rows={3}
                placeholder="Ví dụ: Kỹ thuật viên nhiệt tình, sửa chữa nhanh và sạch sẽ..."
                value={confirmNote}
                onChange={(e) => setConfirmNote(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
              />
            </div>
            <div className="flex items-center justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => setIsConfirmModalOpen(false)}
                className="px-4 py-2 rounded-xl border border-neutral-200 text-xs font-semibold text-neutral-600 hover:bg-neutral-100"
              >
                Đóng
              </button>
              <button
                type="button"
                disabled={isConfirming}
                onClick={handleConfirmResolved}
                className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold disabled:opacity-50"
              >
                {isConfirming ? 'Đang xác nhận...' : 'Xác nhận hoàn thành'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL: CHƯA KHẮC PHỤC XONG (REOPEN) ================= */}
      {isReopenModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-2xl border border-neutral-200 shadow-2xl max-w-md w-full p-6 space-y-4">
            <div className="flex items-start justify-between">
              <div className="flex items-center gap-2 text-amber-600">
                <AlertTriangle className="w-5 h-5 shrink-0" />
                <h3 className="font-bold text-base text-neutral-950">Phản hồi sự cố chưa khắc phục</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsReopenModalOpen(false)}
                className="text-neutral-400 hover:text-neutral-700"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <p className="text-xs text-neutral-600">
              Yêu cầu sẽ được mở lại sang trạng thái <strong>Đang xử lý (IN_PROGRESS)</strong> để kỹ thuật viên tiếp tục kiểm tra.
            </p>
            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Lý do chưa khắc phục xong <span className="text-rose-500">*</span>
              </label>
              <textarea
                rows={3}
                placeholder="Mô tả hiện tượng chưa xử lý dứt điểm (ví dụ: nước vẫn rò, bật công tắc đèn vẫn nháy...)"
                value={reopenReason}
                onChange={(e) => setReopenReason(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
              />
            </div>
            <div className="flex items-center justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => setIsReopenModalOpen(false)}
                className="px-4 py-2 rounded-xl border border-neutral-200 text-xs font-semibold text-neutral-600 hover:bg-neutral-100"
              >
                Đóng
              </button>
              <button
                type="button"
                disabled={isReopening}
                onClick={handleReopenTicket}
                className="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold disabled:opacity-50"
              >
                {isReopening ? 'Đang gửi...' : 'Gửi phản hồi xử lý lại'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL: CHỈNH SỬA TICKET ================= */}
      <ConfirmDialog isOpen={!!removeImageId} title="Bỏ ảnh sự cố" message="Bạn muốn bỏ ảnh này khỏi yêu cầu?" confirmLabel="Bỏ ảnh" isDangerous isLoading={imageBusy} onCancel={() => { if (!imageBusy) setRemoveImageId(null); }} onConfirm={async () => {
        if (!detailTicket || !removeImageId || imageBusy) return;
        setImageBusy(true);
        try { const res = await api.removeTicketImage(detailTicket.id, removeImageId, detailTicket.version); setDetailTicket(res.data); setRemoveImageId(null); showToast('Đã bỏ ảnh.'); }
        catch (error: any) { showToast(error.message, 'error'); if (error.status === 409) void fetchTicketDetail(detailTicket.id); }
        finally { setImageBusy(false); }
      }} />
      {isEditModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-3xl border border-neutral-200 shadow-2xl max-w-lg w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto custom-scrollbar">
            <div className="flex items-start justify-between border-b border-neutral-100 pb-3">
              <div className="flex items-center gap-2">
                <Pencil className="w-5 h-5 text-neutral-700" />
                <h3 className="font-bold text-base text-neutral-950">Chỉnh sửa nội dung sự cố</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsEditModalOpen(false)}
                className="text-neutral-400 hover:text-neutral-700 p-1 rounded-lg hover:bg-neutral-100"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleSaveEditTicket} className="space-y-4 text-xs">
              <div>
                <label className="block font-bold text-neutral-800 mb-1">Tiêu đề sự cố *</label>
                <input
                  type="text"
                  value={editFormData.title}
                  onChange={(e) => setEditFormData({ ...editFormData, title: e.target.value })}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-neutral-200 text-xs text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-neutral-800 mb-1">Vị trí *</label>
                  <input
                    type="text"
                    value={editFormData.location}
                    onChange={(e) => setEditFormData({ ...editFormData, location: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-neutral-200 text-xs text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
                  />
                </div>
                <div>
                  <label className="block font-bold text-neutral-800 mb-1">Mức độ ưu tiên</label>
                  <div className="relative">
                    <select
                      value={editFormData.priority}
                      onChange={(e) => setEditFormData({ ...editFormData, priority: e.target.value })}
                      className="w-full appearance-none px-3.5 py-2.5 pr-8 rounded-xl border border-neutral-200 text-xs text-neutral-900 font-medium bg-white focus:outline-none focus:ring-2 focus:ring-neutral-900 cursor-pointer shadow-2xs resident-ticket-select"
                    >
                      <option value="LOW">Thấp</option>
                      <option value="MEDIUM">Bình thường</option>
                      <option value="HIGH">Ưu tiên cao</option>
                      <option value="URGENT">Khẩn cấp</option>
                    </select>
                    <ChevronDown className="w-4 h-4 text-neutral-400 pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2" />
                  </div>
                </div>
              </div>

              <div>
                <label className="block font-bold text-neutral-800 mb-1">Mô tả chi tiết *</label>
                <textarea
                  rows={4}
                  value={editFormData.description}
                  onChange={(e) => setEditFormData({ ...editFormData, description: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-xs text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-2 border-t border-neutral-100">
                <button
                  type="button"
                  onClick={() => setIsEditModalOpen(false)}
                  className="px-4 py-2 rounded-xl border border-neutral-200 text-xs font-semibold text-neutral-600 hover:bg-neutral-100"
                >
                  Đóng
                </button>
                <button
                  type="submit"
                  disabled={isUpdating}
                  className="px-4 py-2 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white text-xs font-semibold disabled:opacity-50"
                >
                  {isUpdating ? 'Đang lưu...' : 'Lưu thay đổi'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ================= LIGHTBOX PREVIEW ẢNH ================= */}
      {previewImage && (
        <div
          onClick={() => setPreviewImage(null)}
          className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4 cursor-zoom-out animate-in fade-in duration-150"
        >
          <div className="relative max-w-4xl max-h-[90vh] overflow-hidden rounded-2xl bg-black">
            <img src={previewImage} alt="Large preview" className="max-w-full max-h-[85vh] object-contain mx-auto" />
            <button
              type="button"
              onClick={() => setPreviewImage(null)}
              className="absolute top-3 right-3 p-2 rounded-full bg-black/60 text-white hover:bg-black"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default ResidentTicketPanel;
