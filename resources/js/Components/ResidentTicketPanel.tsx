import React, { useState, useEffect, useCallback, useRef } from 'react';
import { TicketImage } from './TicketImage';
import { TicketDialog } from './TicketDialog';
import { useTicketNotificationRefresh } from './TicketNotifications';
import { CustomSelect } from './Common/CustomSelect';
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
    label: 'Mới gửi',
    bg: 'bg-sky-50',
    text: 'text-sky-700',
    border: 'border-sky-200/80',
    dot: 'bg-sky-500',
  },
  RECEIVED: {
    label: 'Đã tiếp nhận',
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
    label: 'Chờ nghiệm thu',
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
    received: 0,
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
  const listRequest = useRef<AbortController | null>(null);
  useEffect(() => () => listRequest.current?.abort(), []);

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
  const modalBusy = useRef(false);
  modalBusy.current = isSubmitting || isCancelling || isConfirming || isReopening || isUpdating || imageBusy;
  useEffect(() => {
    if (!isCancelModalOpen && !isConfirmModalOpen && !isReopenModalOpen && !isEditModalOpen) return;
    const previous = document.activeElement as HTMLElement | null;
    const bodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const dialog = document.querySelector<HTMLElement>('[data-ticket-modal]');
    const controls = () => Array.from(dialog?.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled), textarea:not(:disabled), [tabindex="0"]') || []).filter((node) => node.offsetParent !== null);
    const frame = requestAnimationFrame(() => controls()[0]?.focus());
    const keyboard = (event: KeyboardEvent) => {
      if (document.querySelector('dialog[open]')) return;
      if (event.key === 'Escape' && !modalBusy.current) { setIsCreateModalOpen(false); setIsCancelModalOpen(false); setIsConfirmModalOpen(false); setIsReopenModalOpen(false); setIsEditModalOpen(false); setRemoveImageId(null); }
      if (event.key === 'Tab') { const nodes = controls(); const first = nodes[0], last = nodes[nodes.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); } }
    };
    document.addEventListener('keydown', keyboard);
    return () => { cancelAnimationFrame(frame); document.removeEventListener('keydown', keyboard); document.body.style.overflow = bodyOverflow; previous?.focus(); };
  }, [isCreateModalOpen, isCancelModalOpen, isConfirmModalOpen, isReopenModalOpen, isEditModalOpen, removeImageId]);

  // Debounce search input
  useEffect(() => {
    if (searchQuery === debouncedSearch) return;
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [searchQuery, debouncedSearch]);

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
    listRequest.current?.abort();
    const controller = new AbortController();
    listRequest.current = controller;
    setIsLoading(true);
    setLoadError(null);
    try {
      const res = await api.getResidentTickets({
        status: statusFilter,
        category_id: categoryFilter,
        priority: priorityFilter,
        search: debouncedSearch,
        page: currentPage,
        per_page: 15,
      }, controller.signal);

      if (!controller.signal.aborted && res && res.success) {
        if (res.pagination && currentPage > Math.max(1, res.pagination.last_page)) {
          setCurrentPage(Math.max(1, res.pagination.last_page));
          return;
        }
        setTickets(res.data || []);
        if (res.counters) {
          setCounters((prev) => ({ ...prev, ...res.counters, received: res.counters.received ?? prev.received ?? 0 }));
        }
        if (res.pagination) {
          setCurrentPage(res.pagination.current_page);
          setLastPage(res.pagination.last_page);
          setTotalTickets(res.pagination.total);
        }
      }
    } catch (err: any) {
      if (!controller.signal.aborted) setLoadError(err?.message || 'Không thể tải danh sách sự cố. Vui lòng kiểm tra lại kết nối.');
    } finally {
      if (!controller.signal.aborted) setIsLoading(false);
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
  useEffect(() => { const id = new URLSearchParams(location.search).get('ticket_id'); if (id) void fetchTicketDetail(id); }, []);

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
      if (cancelReason.trim().length < 5) { showToast('Vui lòng nhập lý do hủy ít nhất 5 ký tự.', 'error'); return; }
      const res = await api.cancelResidentTicket(detailTicket.id, detailTicket.version, cancelReason);
      if (res && res.success) {
        showToast('Hủy yêu cầu sự cố thành công.', 'success');
        setDetailTicket(res.data);
        setIsCancelModalOpen(false);
        setCancelReason('');
        fetchTickets();
      }
    } catch (err: any) {
      showToast(err?.message || 'Không thể hủy yêu cầu sự cố.', 'error');
      if (err.status === 409) void fetchTicketDetail(detailTicket.id);
    } finally {
      setIsCancelling(false);
    }
  };

  // Xử lý Xác nhận Đã khắc phục (Nghiệm thu đóng ticket)
  const handleConfirmResolved = async () => {
    if (!detailTicket) return;
    setIsConfirming(true);
    try {
      const res = await api.confirmResidentTicket(detailTicket.id, detailTicket.version, confirmNote);
      if (res && res.success) {
        showToast('Cảm ơn bạn đã xác nhận và nghiệm thu công tác sửa chữa.', 'success');
        setDetailTicket(res.data);
        setIsConfirmModalOpen(false);
        setConfirmNote('');
        fetchTickets();
      }
    } catch (err: any) {
      showToast(err?.message || 'Không thể xác nhận hoàn thành.', 'error');
      if (err.status === 409) void fetchTicketDetail(detailTicket.id);
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
      const res = await api.reopenResidentTicket(detailTicket.id, detailTicket.version, reopenReason);
      if (res && res.success) {
        showToast('Đã gửi phản hồi. Kỹ thuật viên sẽ tiếp tục kiểm tra lại sự cố.', 'success');
        setDetailTicket(res.data);
        setIsReopenModalOpen(false);
        setReopenReason('');
        fetchTickets();
      }
    } catch (err: any) {
      showToast(err?.message || 'Không thể gửi phản hồi.', 'error');
      if (err.status === 409) void fetchTicketDetail(detailTicket.id);
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
      if (err.status === 409) void fetchTicketDetail(detailTicket.id);
    } finally {
      setIsUpdating(false);
    }
  };

  useTicketNotificationRefresh(() => { void fetchTickets(); if (selectedTicketId && viewMode === 'detail' && !isCreateModalOpen && !isCancelModalOpen && !isConfirmModalOpen && !isReopenModalOpen && !isEditModalOpen && !removeImageId) void fetchTicketDetail(selectedTicketId); });

  return (
    <div className="max-w-6xl mx-auto space-y-8">
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
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="space-y-1">
          <div className="flex items-center gap-1.5 text-xs text-neutral-400 uppercase tracking-wider font-semibold mb-2">
            <Wrench className="w-4 h-4 text-emerald-600" />
            <span>HỖ TRỢ KỸ THUẬT & VẬN HÀNH</span>
          </div>
          <h1 className="text-2xl font-extrabold tracking-tight text-neutral-950 sm:text-3xl">
            Báo cáo Sự cố & Sửa chữa
          </h1>
          <p className="mt-2 max-w-2xl text-sm leading-6 text-neutral-500">
            Gửi yêu cầu hỗ trợ, theo dõi kỹ thuật xử lý và xác nhận khi sự cố đã được khắc phục.
          </p>
        </div>

        {/* Tab Switcher Action Buttons */}
        <div className="flex shrink-0 items-center gap-2 self-start sm:self-auto">
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
              className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-neutral-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 cursor-pointer"
            >
              <Plus className="w-4 h-4" />
              <span>Gửi báo cáo sự cố mới</span>
            </button>
          )}
        </div>
      </div>



      {/* ================= MODAL: FORM BÁO CÁO SỰ CỐ MỚI (POPUP GIỮA MÀN HÌNH) ================= */}
      {isCreateModalOpen && (
        <TicketDialog open={isCreateModalOpen} title="Gửi yêu cầu báo cáo sự cố" busy={isSubmitting} onClose={() => setIsCreateModalOpen(false)} footer={
<div className="shrink-0 flex flex-wrap items-center justify-end gap-2 border-t border-neutral-200 bg-white px-5 pb-4 pt-3">
                <button
                  id="ticket-close-create-btn"
                  type="button"
                  disabled={isSubmitting} onClick={() => setIsCreateModalOpen(false)}
                  className="order-1 min-h-11 rounded-xl border border-neutral-200 px-4 py-2.5 text-sm font-semibold text-neutral-700 hover:bg-neutral-100 cursor-pointer"
                >
                  Đóng
                </button>
                <button
                  type="button"
                  onClick={handleResetForm}
                  disabled={isSubmitting}
                  className="order-3 min-h-8 w-full rounded-lg px-2 text-xs font-medium text-neutral-500 underline underline-offset-4 hover:text-neutral-900 cursor-pointer disabled:opacity-50 sm:order-none sm:mr-auto sm:w-auto"
                >
                  Đặt lại biểu mẫu
                </button>
                <button
                  id="ticket-submit-btn" form="ticket-create-modal"
                  type="submit"
                  disabled={isSubmitting}
                  className="order-2 inline-flex min-h-11 min-w-0 flex-1 items-center justify-center gap-2 rounded-xl bg-neutral-950 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-neutral-800 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed sm:flex-none"
                >
                  {isSubmitting ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      <span>Đang gửi báo cáo sự cố...</span>
                    </>
                  ) : (
                    <>
                      <Send className="w-4 h-4" />
                      <span>Gửi yêu cầu hỗ trợ</span>
                    </>
                  )}
                </button>
              </div>
        }>
          {toast?.type === 'error' && <p role="alert" className="mb-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{toast.message}</p>}
          <p className="mb-5 text-sm leading-6 text-neutral-500">Mô tả sự cố và vị trí để ban quản lý sắp xếp hỗ trợ. Các trường có dấu * là bắt buộc.</p>
            <form id="ticket-create-modal" onSubmit={handleSubmitTicket} className="space-y-6">
              {/* SECTION 1: THÔNG TIN CƯ DÂN & CĂN HỘ */}
              <div className="space-y-3">
                <div className="flex items-center gap-2 text-sm font-semibold text-neutral-900">
                  <span>Thông tin liên hệ</span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-neutral-50/70 p-3.5 rounded-xl border border-neutral-200/60 text-xs">
                  <div>
                    <p className="mb-1 text-neutral-500">Họ tên cư dân</p>
                    <p className="break-words py-2 text-sm font-medium text-neutral-900">{currentUser?.full_name || 'Chưa tải được thông tin'}</p>
                  </div>

                  <div>
                    <p className="mb-1 text-neutral-500">Số điện thoại liên hệ</p>
                    <p className="py-2 text-sm font-medium text-neutral-900">{currentUser?.phone_number || 'Chưa cập nhật'}</p>
                  </div>

                  <div>
                    <label htmlFor="ticket-apartment-select" className="block text-neutral-700 font-bold mb-1">
                      Căn hộ / Tòa nhà <span className="text-rose-500">*</span>
                    </label>
                    <div className="relative">
                      <CustomSelect overlay buttonClassName="min-h-11 text-sm" id="ticket-apartment-select" value={formData.apartment_id} onChange={(value) => setFormData({ ...formData, apartment_id: value })} placeholder="Chọn căn hộ đang cư trú" options={apartments.map((apt) => ({ value: apt.id, label: `Căn ${apt.apartment_number} ${apt.block_name || ''}` }))} />
                    </div>
                  </div>
                </div>
              </div>

              {/* SECTION 2: CHI TIẾT SỰ CỐ KỸ THUẬT */}
              <div className="space-y-3">
                <div className="flex items-center gap-2 text-sm font-semibold text-neutral-900">
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
                      className={`w-full px-3.5 py-2.5 rounded-xl border text-sm text-neutral-900 bg-white placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all ${
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
                      <CustomSelect overlay buttonClassName="min-h-11 text-sm" id="ticket-category-select" value={formData.category_id} onChange={(value) => setFormData({ ...formData, category_id: value })} options={categories.map((c) => ({ value: c.id, label: c.category_name }))} placeholder="Chọn danh mục sự cố" />
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
                      className={`w-full px-3.5 py-2.5 rounded-xl border text-sm text-neutral-900 bg-white placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all ${
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
                      <CustomSelect overlay buttonClassName="min-h-11 text-sm" id="ticket-priority-select" value={formData.priority} onChange={(value) => setFormData({ ...formData, priority: value })} options={Object.entries(PRIORITY_CONFIG).map(([value, item]) => ({ value, label: item.label }))} />
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
                      className="w-full px-3.5 py-2.5 rounded-xl border border-neutral-200 text-sm text-neutral-900 font-medium bg-white focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all cursor-pointer shadow-2xs"
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
                      className={`w-full px-3.5 py-2.5 rounded-xl border text-sm text-neutral-900 bg-white placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all ${
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
                  <div className="flex items-center gap-2 text-sm font-semibold text-neutral-900">
                    <span>Ảnh hiện trường</span>
                  </div>
                  <span className="text-[11px] text-neutral-400 font-medium font-mono">
                    {selectedFiles.length} / 5 ảnh
                  </span>
                </div>

                {/* Upload Dropzone */}
                <label
                  htmlFor="ticket-photo-upload"
                  className="block rounded-xl border border-dashed border-neutral-300 bg-neutral-50 p-5 text-center cursor-pointer hover:border-neutral-500 focus-within:ring-2 focus-within:ring-emerald-500 group"
                >
                  <input
                    ref={fileInputRef}
                    id="ticket-photo-upload"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/jpg"
                    multiple
                    onChange={handleFileChange}
                    className="sr-only" aria-label="Chọn ảnh hiện trường" disabled={isSubmitting}
                  />
                  <div className="w-10 h-10 rounded-full bg-white shadow-2xs border border-neutral-200 flex items-center justify-center mx-auto text-neutral-500 group-hover:scale-110 group-hover:text-neutral-900 transition-all">
                    <Camera className="w-5 h-5" />
                  </div>
                  <p className="text-xs font-bold text-neutral-800 mt-2">
                    Chọn ảnh hiện trường (không bắt buộc)
                  </p>
                  <p className="text-[11px] text-neutral-400 mt-0.5">
                    Định dạng: JPG, PNG, WebP · Dung lượng tối đa: 5MB/ảnh
                  </p>
                </label>

                {/* Previews Thumbnails */}
                {filePreviews.length > 0 && (
                  <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 pt-2">
                    {filePreviews.map((url, idx) => (
                      <div
                        key={idx}
                        className="relative group rounded-xl overflow-hidden border border-neutral-200 bg-neutral-100 aspect-square"
                      >
                        <img src={url} alt={`preview-${idx}`} className="w-full h-full object-cover" />
                        <div className="absolute inset-0 bg-black/40 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100 transition-opacity flex items-center justify-center gap-2">
                          <button
                            type="button"
                            onClick={() => setPreviewImage(url)}
                            className="p-1.5 rounded-lg bg-white/90 text-neutral-800 hover:bg-white text-xs"
                            aria-label={`Xem ảnh đã chọn ${idx + 1}`} title="Xem ảnh lớn"
                          >
                            <Eye className="w-3.5 h-3.5" />
                          </button>
                          <button
                            type="button"
                            onClick={() => removeFile(idx)}
                            className="p-1.5 rounded-lg bg-rose-600 text-white hover:bg-rose-700 text-xs"
                            aria-label={`Bỏ ảnh đã chọn ${idx + 1}`} title="Xóa ảnh này"
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

            </form>
        </TicketDialog>
      )}

      {/* ================= VIEW 2: DANH SÁCH TICKET ================= */}
      {viewMode === 'list' && (
        <div className="rounded-2xl border border-neutral-200 bg-white shadow-xs">
          {/* Filter Bar */}
          <div className="space-y-4 p-4 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="font-semibold text-neutral-900">Yêu cầu của tôi</h2><p className="text-xs text-neutral-500">Theo dõi tiến độ và phản hồi sau xử lý</p></div>
            <div className="flex max-w-full gap-2 overflow-x-auto pb-1" aria-label="Lọc nhanh yêu cầu">
              {[
                { status: 'ALL', label: 'Tất cả yêu cầu', count: counters.all },
                { status: 'NEW', label: 'Mới gửi', count: counters.new },
                { status: 'IN_PROGRESS', label: `Đang xử lý${(counters.received ?? 0) > 0 ? ` (${counters.received} đã ghi nhận)` : ''}`, count: counters.in_progress, title: (counters.received ?? 0) > 0 ? `Gồm ${counters.received} yêu cầu Đã ghi nhận` : undefined },
                { status: 'RESOLVED', label: 'Chờ nghiệm thu', count: counters.resolved },
                { status: 'CLOSED', label: 'Đã hoàn tất', count: counters.closed },
                { status: 'CANCELLED', label: 'Đã hủy', count: counters.cancelled },
              ].map((item) => <button key={item.status} type="button" aria-pressed={statusFilter === item.status} title={item.title} onClick={() => { setStatusFilter(item.status); setCurrentPage(1); }} className={`inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium cursor-pointer focus-visible:outline-2 focus-visible:outline-emerald-600 ${statusFilter === item.status ? 'bg-neutral-950 text-white' : 'text-neutral-600 hover:bg-neutral-100'}`}>
                <span className={statusFilter === item.status ? 'text-white' : 'text-neutral-600'}>{item.label}</span><span className={`rounded-md px-1.5 py-0.5 text-xs tabular-nums ${statusFilter === item.status ? 'bg-white/15 text-white' : 'bg-neutral-100 text-neutral-600'}`}>{item.count}</span>
              </button>)}
            </div>

            <div className="grid grid-cols-1 gap-3 border-t border-neutral-100 pt-4 sm:grid-cols-2 xl:grid-cols-5">
              {/* Search Box */}
              <div className="relative sm:col-span-2">
                <label htmlFor="ticket-search-input" className="mb-1.5 block text-xs font-bold uppercase text-slate-700">Tìm yêu cầu</label>
                <Search aria-hidden="true" className="pointer-events-none absolute bottom-3.5 left-3.5 h-4 w-4 text-neutral-400" />
                <input
                  id="ticket-search-input"
                  type="text"
                  placeholder="Tìm theo mã ticket, tiêu đề, vị trí sự cố..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="min-h-11 w-full pl-10 pr-12 py-2.5 rounded-xl border border-neutral-200 text-sm text-neutral-900 bg-white placeholder:text-neutral-400 focus:outline-none focus:ring-2 focus:ring-neutral-900 transition-all"
                />
                {searchQuery && <button type="button" aria-label="Xóa từ khóa tìm yêu cầu" className="absolute bottom-0 right-0 min-h-11 min-w-11 cursor-pointer rounded-xl text-neutral-500 hover:bg-neutral-100" onClick={() => setSearchQuery('')}><X aria-hidden="true" className="mx-auto h-4 w-4" /></button>}
              </div>

              {/* Status Filter */}
              <div className="relative">
                <CustomSelect overlay buttonClassName="min-h-11 text-sm" id="ticket-status-filter" label="Trạng thái yêu cầu" value={statusFilter} onChange={(value) => { setStatusFilter(value); setCurrentPage(1); }} options={[{ value: 'ALL', label: 'Tất cả trạng thái' }, ...Object.entries(STATUS_CONFIG).map(([value, item]) => ({ value, label: item.label }))]} />
              </div>

              {/* Category Filter */}
              <div className="relative">
                <CustomSelect overlay buttonClassName="min-h-11 text-sm" id="ticket-category-filter" label="Loại sự cố" value={categoryFilter} onChange={(value) => { setCategoryFilter(value); setCurrentPage(1); }} options={[{ value: 'ALL', label: 'Tất cả loại sự cố' }, ...categories.map((c) => ({ value: c.id, label: c.category_name }))]} />
              </div>
              <CustomSelect overlay buttonClassName="min-h-11 text-sm" id="ticket-priority-filter" label="Mức độ ưu tiên" value={priorityFilter} onChange={(value) => { setPriorityFilter(value); setCurrentPage(1); }} options={[{ value: 'ALL', label: 'Tất cả mức độ' }, ...Object.entries(PRIORITY_CONFIG).map(([value, item]) => ({ value, label: item.label }))]} />
            </div>

            {/* Reset / Reload helper */}
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-neutral-500 pt-1">
              <span>
                Tìm thấy <strong className="text-neutral-900 font-mono">{totalTickets}</strong> yêu cầu
              </span>
              <div className="flex flex-wrap items-center gap-2">
              {(searchQuery || statusFilter !== 'ALL' || categoryFilter !== 'ALL' || priorityFilter !== 'ALL') && <button type="button" className="min-h-11 cursor-pointer rounded-xl px-3 font-medium text-sky-700 underline" onClick={() => { setSearchQuery(''); setDebouncedSearch(''); setStatusFilter('ALL'); setCategoryFilter('ALL'); setPriorityFilter('ALL'); setCurrentPage(1); }}>Xóa bộ lọc</button>}
              <button
                type="button"
                disabled={isLoading}
                onClick={() => fetchTickets()}
                className="inline-flex min-h-11 items-center gap-1.5 rounded-xl px-3 text-neutral-600 hover:text-neutral-900 font-semibold cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin' : ''}`} />
                <span>Làm mới</span>
              </button>
              </div>
            </div>
          </div>

          {/* Table Container */}
          <div className="overflow-hidden rounded-b-2xl border-t border-neutral-200 bg-white">
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
                  <h3 className="text-sm font-bold text-neutral-900">{debouncedSearch || statusFilter !== 'ALL' || categoryFilter !== 'ALL' || priorityFilter !== 'ALL' ? 'Không có yêu cầu phù hợp' : 'Chưa có yêu cầu báo cáo sự cố nào'}</h3>
                  <p className="text-xs text-neutral-400 max-w-sm mx-auto">
                    {debouncedSearch || statusFilter !== 'ALL' || categoryFilter !== 'ALL' || priorityFilter !== 'ALL' ? 'Thử đổi từ khóa hoặc xóa bộ lọc để xem các yêu cầu khác.' : 'Khi xảy ra sự cố kỹ thuật về điện, nước hoặc hạ tầng căn hộ, bạn có thể gửi yêu cầu để kỹ thuật viên đến hỗ trợ.'}
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
              <><ul aria-label="Danh sách yêu cầu của tôi" className="divide-y divide-neutral-100 lg:hidden">{tickets.map((ticket) => {
                const status = STATUS_CONFIG[ticket.status] || STATUS_CONFIG.NEW;
                return <li key={ticket.id} className="space-y-3 p-4">
                  <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-mono text-xs text-neutral-500">{ticket.ticket_number}</span><span className={`rounded-full px-2.5 py-1 text-xs font-medium ${status.bg} ${status.text}`}>{status.label}</span></div>
                  <h3 className="break-words text-sm font-semibold text-neutral-900">{ticket.title}</h3>
                  <p className="text-sm text-neutral-600">{ticket.location || 'Tại căn hộ'} · {ticket.category?.category_name || 'Kỹ thuật'}</p>
                  <div className="flex flex-wrap items-center justify-between gap-2"><time dateTime={ticket.created_at} className="text-xs text-neutral-500">{formatDateTime(ticket.created_at)}</time><button type="button" className="min-h-11 cursor-pointer rounded-xl border border-neutral-200 px-4 text-sm font-medium hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-sky-600" onClick={() => fetchTicketDetail(ticket.id)}>Xem chi tiết</button></div>
                </li>;
              })}</ul><div className="hidden overflow-x-auto lg:block">
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
                              Xem chi tiết
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div></>
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
                    aria-label="Trang yêu cầu trước" disabled={isLoading || currentPage <= 1}
                    onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                    className="min-h-11 min-w-11 cursor-pointer p-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed"
                  >
                    <ChevronLeft className="w-4 h-4" />
                  </button>
                  <button
                    type="button"
                    aria-label="Trang yêu cầu tiếp theo" disabled={isLoading || currentPage >= lastPage}
                    onClick={() => setCurrentPage((p) => Math.min(lastPage, p + 1))}
                    className="min-h-11 min-w-11 cursor-pointer p-1.5 rounded-lg border border-neutral-200 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed"
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
        <div data-ticket-modal role="dialog" aria-modal="true" aria-label="Hủy yêu cầu" className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
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
                Lý do hủy yêu cầu (Bắt buộc, ít nhất 5 ký tự)
              </label>
              <textarea
                rows={3}
                placeholder="Ví dụ: Đã tự khắc phục được, gửi nhầm căn hộ..."
                value={cancelReason}
                onChange={(e) => setCancelReason(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
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
        <div data-ticket-modal role="dialog" aria-modal="true" aria-label="Xác nhận nghiệm thu" className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
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
                className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
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
        <div data-ticket-modal role="dialog" aria-modal="true" aria-label="Phản hồi chưa đạt" className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
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
                className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
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
      <TicketDialog open={!!removeImageId} title="Bỏ ảnh sự cố" busy={imageBusy} onClose={() => setRemoveImageId(null)}><p className="text-sm">Bạn muốn bỏ ảnh này khỏi yêu cầu?</p><div className="mt-4 flex justify-end gap-2"><button type="button" disabled={imageBusy} className="rounded-xl border px-4 py-2 cursor-pointer" onClick={() => setRemoveImageId(null)}>Đóng</button><button type="button" disabled={imageBusy} className="rounded-xl bg-rose-600 px-4 py-2 text-white cursor-pointer" onClick={async () => {
        if (!detailTicket || !removeImageId || imageBusy) return;
        setImageBusy(true);
        try { const res = await api.removeTicketImage(detailTicket.id, removeImageId, detailTicket.version); setDetailTicket(res.data); setRemoveImageId(null); showToast('Đã bỏ ảnh.'); }
        catch (error: any) { showToast(error.message, 'error'); if (error.status === 409) void fetchTicketDetail(detailTicket.id); }
        finally { setImageBusy(false); }
      }}>Bỏ ảnh</button></div></TicketDialog>
      {isEditModalOpen && (
        <div data-ticket-modal role="dialog" aria-modal="true" aria-label="Sửa yêu cầu" className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
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
                  className="w-full px-3.5 py-2.5 rounded-xl border border-neutral-200 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-neutral-800 mb-1">Vị trí *</label>
                  <input
                    type="text"
                    value={editFormData.location}
                    onChange={(e) => setEditFormData({ ...editFormData, location: e.target.value })}
                    className="w-full px-3.5 py-2.5 rounded-xl border border-neutral-200 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
                  />
                </div>
                <div>
                  <label className="block font-bold text-neutral-800 mb-1">Mức độ ưu tiên</label>
                  <div className="relative">
                    <CustomSelect overlay buttonClassName="min-h-11 text-sm" label="Ưu tiên yêu cầu" value={editFormData.priority} onChange={(value) => setEditFormData({ ...editFormData, priority: value })} options={Object.entries(PRIORITY_CONFIG).map(([value, item]) => ({ value, label: item.label }))} />
                  </div>
                </div>
              </div>

              <div>
                <label className="block font-bold text-neutral-800 mb-1">Mô tả chi tiết *</label>
                <textarea
                  rows={4}
                  value={editFormData.description}
                  onChange={(e) => setEditFormData({ ...editFormData, description: e.target.value })}
                  className="w-full px-3 py-2 rounded-xl border border-neutral-200 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
                />
              </div>

              <div>
                <label className="block font-bold text-neutral-800 mb-1">Ngày thuận tiện kỹ thuật đến kiểm tra</label>
                <input
                  type="date"
                  min={new Date().toISOString().split('T')[0]}
                  value={editFormData.preferred_service_time ? editFormData.preferred_service_time.substring(0, 10) : ''}
                  onChange={(e) => setEditFormData({ ...editFormData, preferred_service_time: e.target.value })}
                  className="w-full px-3.5 py-2.5 rounded-xl border border-neutral-200 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-900"
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

      <TicketDialog open={!!previewImage} title="Xem ảnh sự cố" onClose={() => setPreviewImage(null)}>
        {previewImage && <img src={previewImage} alt="Ảnh sự cố phóng to" className="max-h-[70dvh] w-full rounded-xl object-contain" />}
      </TicketDialog>
    </div>
  );
};

export default ResidentTicketPanel;
