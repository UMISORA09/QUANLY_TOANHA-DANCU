import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import {
  FileText,
  Plus,
  Search,
  Filter,
  RefreshCw,
  CheckCircle2,
  XCircle,
  Clock,
  Send,
  Printer,
  Download,
  Eye,
  Building2,
  User,
  Calendar,
  Shield,
  FileCheck,
  AlertCircle,
  ExternalLink,
  ChevronRight,
  Upload,
  Image as ImageIcon,
  X,
  FileSpreadsheet,
  Check,
  Pencil,
  Trash2
} from 'lucide-react';
import { api, TemporaryRegistrationItem, ApartmentSummaryItem, ResidentItem } from '../../Services/api';
import { useRealtimeSync, useModuleCooldown, emitLocalRealtimeEvent } from '../../Hooks/useRealtimeSync';
import { CooldownBanner } from '../../Components/Realtime/CooldownBanner';
import { CustomSelect, SelectOption } from '../../Components/Common/CustomSelect';

interface TemporaryRegistrationManagementProps {
  embedded?: boolean;
}

/**
 * Định dạng ngày theo chuẩn Việt Nam dd/MM/yyyy an toàn, chống lệch múi giờ Asia/Ho_Chi_Minh
 */
const formatDateVN = (dateStr?: string | null, includeTime: boolean = false): string => {
  if (!dateStr || typeof dateStr !== 'string' || dateStr.trim() === '') return '—';
  const trimmed = dateStr.trim();

  // Xử lý chuỗi định dạng thuần YYYY-MM-DD (tránh bị lệch ngày do UTC midnight)
  if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
    const [y, m, d] = trimmed.split('-');
    return `${d}/${m}/${y}`;
  }

  try {
    const dt = new Date(trimmed);
    if (isNaN(dt.getTime())) return dateStr;

    return new Intl.DateTimeFormat('vi-VN', {
      timeZone: 'Asia/Ho_Chi_Minh',
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      ...(includeTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    }).format(dt);
  } catch {
    return dateStr;
  }
};

export const TemporaryRegistrationManagement: React.FC<TemporaryRegistrationManagementProps> = ({
  embedded = false,
}) => {
  const { isCooldownActive, remainingSeconds, message: cooldownMessage, startCooldown } = useModuleCooldown('temporary_registrations');

  // Đọc snapshot lưu trong sessionStorage để hiển thị tức thì (0ms) cho lần tải thứ 2
  const getCachedData = () => {
    try {
      const raw = sessionStorage.getItem('smartcassavas_tempreg_list_cache');
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  };
  const cachedData = getCachedData();

  // State danh sách & phân trang
  const [registrations, setRegistrations] = useState<TemporaryRegistrationItem[]>(() => (Array.isArray(cachedData?.data) ? cachedData.data : []));
  const [loading, setLoading] = useState<boolean>(() => !cachedData);
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(() => cachedData?.meta?.last_page || 1);
  const [totalCount, setTotalCount] = useState<number>(() => cachedData?.meta?.total || 0);
  const [perPage, setPerPage] = useState<number>(15);

  // State bộ lọc & tìm kiếm
  const [search, setSearch] = useState<string>('');
  const [filterType, setFilterType] = useState<string>('');
  const [filterStatus, setFilterStatus] = useState<string>('');
  const [filterApartmentId, setFilterApartmentId] = useState<string>('');
  const [apartments, setApartments] = useState<ApartmentSummaryItem[]>([]);

  // State Modals
  const [isCreateModalOpen, setIsCreateModalOpen] = useState<boolean>(false);
  const [selectedItem, setSelectedItem] = useState<TemporaryRegistrationItem | null>(null);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);
  const [isRejectModalOpen, setIsRejectModalOpen] = useState<boolean>(false);
  const [isExportModalOpen, setIsExportModalOpen] = useState<boolean>(false);
  const [exportHtmlContent, setExportHtmlContent] = useState<string>('');
  const [exportTitle, setExportTitle] = useState<string>('');

  // Form State tạo mới
  const [createForm, setCreateForm] = useState({
    apartment_id: '',
    resident_id: '',
    registration_type: 'TEMPORARY_STAY' as 'TEMPORARY_STAY' | 'TEMPORARY_ABSENCE',
    start_date: new Date().toISOString().split('T')[0],
    end_date: '',
    reason: '',
    identity_card_front_url: '',
    identity_card_back_url: '',
    notes: '',
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState<boolean>(false);

  // Edit & Delete State
  const [isEditModalOpen, setIsEditModalOpen] = useState<boolean>(false);
  const [editForm, setEditForm] = useState({
    id: '',
    registration_type: 'TEMPORARY_STAY' as 'TEMPORARY_STAY' | 'TEMPORARY_ABSENCE',
    start_date: '',
    end_date: '',
    reason: '',
    notes: '',
    identity_card_front_url: '',
    identity_card_back_url: '',
    updated_at: '',
  });
  const [editLoading, setEditLoading] = useState<boolean>(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState<boolean>(false);
  const [deleteItem, setDeleteItem] = useState<TemporaryRegistrationItem | null>(null);
  const [deleteLoading, setDeleteLoading] = useState<boolean>(false);
  const [apartmentResidents, setApartmentResidents] = useState<ResidentItem[]>([]);
  const [allResidents, setAllResidents] = useState<ResidentItem[]>([]);
  const [loadingResidents, setLoadingResidents] = useState<boolean>(false);
  const [residentSearchKeyword, setResidentSearchKeyword] = useState<string>('');
  const [uploadingFront, setUploadingFront] = useState<boolean>(false);
  const [uploadingBack, setUploadingBack] = useState<boolean>(false);

  // Form State xử lý duyệt/từ chối
  const [actionNotes, setActionNotes] = useState<string>('');
  const [actionPoliceCode, setActionPoliceCode] = useState<string>('');
  const [rejectReason, setRejectReason] = useState<string>('');
  const [actionLoading, setActionLoading] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Search Debounce State
  const [debouncedSearch, setDebouncedSearch] = useState<string>('');
  const searchAbortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search);
      setCurrentPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [search]);

  const showToast = (type: 'success' | 'error', text: string) => {
    setToastMessage({ type, text });
    setTimeout(() => setToastMessage(null), 4000);
  };

  // 1. Tải danh sách căn hộ phục vụ bộ lọc (KHÔNG tải cư dân khi mở trang để tối ưu tốc độ)
  useEffect(() => {
    api.getApartmentsForFilter()
      .then((res) => {
        if (res.data) setApartments(res.data);
      })
      .catch((err) => console.error('Lỗi tải danh sách căn hộ:', err));
  }, []);

  // Ref lưu ID request mới nhất để chống Race Condition khi chuyển trang / tìm kiếm nhanh
  const latestRequestIdRef = useRef<number>(0);

  // 2. Tải danh sách hồ sơ từ Database qua API (Source of Truth)
  const fetchRegistrations = useCallback(async (pageOverride?: number) => {
    const pageToFetch = pageOverride ?? currentPage;
    const currentRequestId = ++latestRequestIdRef.current;

    if (searchAbortRef.current) {
      searchAbortRef.current.abort();
    }
    const controller = new AbortController();
    searchAbortRef.current = controller;

    setLoading(true);

    try {
      const res = await api.getTemporaryRegistrations({
        page: pageToFetch,
        limit: perPage,
        search: debouncedSearch.trim(),
        registration_type: filterType,
        police_status: filterStatus,
        apartment_id: filterApartmentId,
      }, { signal: controller.signal });

      // Kiểm tra Race condition: Chỉ cập nhật state nếu đây là response của request mới nhất
      if (currentRequestId !== latestRequestIdRef.current) {
        return;
      }

      if (res.data) {
        setRegistrations(res.data);
        if (res.meta) {
          const lastPage = res.meta.last_page || 1;
          setTotalPages(lastPage);
          setTotalCount(res.meta.total || 0);

          // Nếu trang hiện tại vượt quá số trang thực tế còn lại (ví dụ sau khi xóa), lùi về lastPage
          if (res.meta.total > 0 && pageToFetch > lastPage) {
            setCurrentPage(lastPage);
          }

          if (pageToFetch === 1 && !debouncedSearch.trim() && !filterType && !filterStatus && !filterApartmentId) {
            try {
              sessionStorage.setItem('smartcassavas_tempreg_list_cache', JSON.stringify({ data: res.data, meta: res.meta }));
            } catch {
              // ignore
            }
          }
        }
      }
    } catch (err: any) {
      if (err?.name !== 'AbortError' && currentRequestId === latestRequestIdRef.current) {
        showToast('error', err.message || 'Không thể tải danh sách hồ sơ.');
      }
    } finally {
      if (currentRequestId === latestRequestIdRef.current) {
        setLoading(false);
      }
    }
  }, [currentPage, perPage, debouncedSearch, filterType, filterStatus, filterApartmentId]);

  useEffect(() => {
    fetchRegistrations();
  }, [fetchRegistrations]);

  // Realtime Auto-Sync Listener (Quốc Tín - Temporary Registration)
  useRealtimeSync({
    channel: 'quoc-tin.temporary-registrations',
    onEvent: (event) => {
      // 0. Cập nhật state in-memory ngay lập tức
      if (event.action === 'DELETED' && event.entity_id) {
        setRegistrations((prev) => prev.filter((item) => item.id !== event.entity_id));
        setTotalCount((prev) => Math.max(0, prev - 1));
      } else if ((event.action === 'APPROVED' || event.action === 'REJECTED') && event.entity_id) {
        setRegistrations((prev) =>
          prev.map((item) =>
            item.id === event.entity_id
              ? {
                  ...item,
                  police_status: event.police_status || (event.action === 'APPROVED' ? 'APPROVED' : 'REJECTED'),
                  approval_status: event.action,
                }
              : item
          )
        );
      }

      // 1. Invalidate cache
      try {
        sessionStorage.removeItem('smartcassavas_tempreg_list_cache');
      } catch {
        // ignore
      }

      // 2. Refetch danh sách hồ sơ (bảo toàn filter, search, page)
      fetchRegistrations();

      // 3. Nếu đang mở modal chi tiết của hồ sơ vừa cập nhật/duyệt/từ chối -> cập nhật modal ngay
      if (selectedItem && selectedItem.id === event.entity_id) {
        api.getTemporaryRegistrationById(selectedItem.id)
          .then((res: any) => {
            if (res && res.data) {
              setSelectedItem(res.data);
            }
          })
          .catch(() => {});
      }
    },
    onReconnect: () => {
      fetchRegistrations();
    },
  });

  // Khi chọn căn hộ trong modal tạo mới -> Tải danh sách cư dân của căn hộ đó
  useEffect(() => {
    if (!createForm.apartment_id) {
      setApartmentResidents([]);
      return;
    }
    setLoadingResidents(true);
    api.getResidents({ apartment_id: createForm.apartment_id, limit: 100 })
      .then((res) => {
        if (res.data) {
          setApartmentResidents(res.data);
        }
      })
      .catch((err) => {
        console.error('Lỗi tải cư dân:', err);
        setApartmentResidents([]);
      })
      .finally(() => setLoadingResidents(false));
  }, [createForm.apartment_id]);

  // Lazy load cư dân chỉ khi mở modal tạo mới mà chưa chọn căn hộ (KHÔNG tải khi mở trang)
  useEffect(() => {
    if (isCreateModalOpen && allResidents.length === 0 && !createForm.apartment_id) {
      setLoadingResidents(true);
      api.getResidents({ limit: 50 })
        .then((res) => {
          if (res.data) setAllResidents(res.data);
        })
        .catch((err) => console.error('Lỗi tải cư dân:', err))
        .finally(() => setLoadingResidents(false));
    }
  }, [isCreateModalOpen, allResidents.length, createForm.apartment_id]);

  // Danh sách cư dân khả dụng để chọn trong modal tạo mới (hỗ trợ lọc theo căn hộ và tìm kiếm nhanh)
  const availableResidents = useMemo(() => {
    let list: ResidentItem[] = createForm.apartment_id ? apartmentResidents : allResidents;
    if (residentSearchKeyword.trim()) {
      const kw = residentSearchKeyword.trim().toLowerCase();
      list = list.filter((r: ResidentItem) => {
        const name = r.user?.full_name?.toLowerCase() || '';
        const phone = r.user?.phone_number?.toLowerCase() || '';
        const cccd = r.user?.national_id_number?.toLowerCase() || '';
        const aptNo = r.apartment?.apartment_number?.toLowerCase() || '';
        return name.includes(kw) || phone.includes(kw) || cccd.includes(kw) || aptNo.includes(kw);
      });
    }
    return list;
  }, [createForm.apartment_id, apartmentResidents, allResidents, residentSearchKeyword]);

  // Xử lý khi chọn cư dân -> Tự động điền căn hộ tương ứng nếu chưa chọn
  const handleSelectResident = (residentId: string) => {
    const found = allResidents.find((r: ResidentItem) => r.id === residentId) || apartmentResidents.find((r: ResidentItem) => r.id === residentId);
    if (found) {
      setCreateForm((prev) => ({
        ...prev,
        resident_id: residentId,
        apartment_id: found.apartment_id || prev.apartment_id,
      }));
    } else {
      setCreateForm((prev) => ({ ...prev, resident_id: residentId }));
    }
  };

  // Thống kê nhanh
  const stats = useMemo(() => {
    const stayCount = registrations.filter((r) => r.registration_type === 'TEMPORARY_STAY').length;
    const absCount = registrations.filter((r) => r.registration_type === 'TEMPORARY_ABSENCE').length;
    const pendingCount = registrations.filter((r) => r.police_status === 'PENDING_POLICE_SUBMISSION' || r.police_status === 'PENDING').length;
    const approvedCount = registrations.filter((r) => r.police_status === 'APPROVED').length;
    const rejectedCount = registrations.filter((r) => r.police_status === 'REJECTED').length;
    return { stayCount, absCount, pendingCount, approvedCount, rejectedCount };
  }, [registrations]);

  // Xử lý upload ảnh CCCD
  const handleUploadCccd = async (e: React.ChangeEvent<HTMLInputElement>, side: 'front' | 'back') => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (side === 'front') setUploadingFront(true);
    else setUploadingBack(true);

    try {
      const res = await api.uploadTemporaryRegistrationCccd(file, side);
      if (res.url) {
        setCreateForm((prev) => ({
          ...prev,
          [side === 'front' ? 'identity_card_front_url' : 'identity_card_back_url']: res.url,
        }));
        showToast('success', `Đã tải ảnh CCCD mặt ${side === 'front' ? 'trước' : 'sau'} thành công!`);
      }
    } catch (err: any) {
      showToast('error', err.message || 'Lỗi khi tải ảnh lên.');
    } finally {
      if (side === 'front') setUploadingFront(false);
      else setUploadingBack(false);
    }
  };

  // Submit tạo hồ sơ
  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormErrors({});

    // Client validation sơ bộ
    const errors: Record<string, string> = {};
    if (!createForm.apartment_id) errors.apartment_id = 'Vui lòng chọn căn hộ.';
    if (!createForm.resident_id) errors.resident_id = 'Vui lòng chọn cư dân.';
    if (!createForm.start_date) errors.start_date = 'Ngày bắt đầu là bắt buộc.';
    if (!createForm.end_date) errors.end_date = 'Ngày kết thúc là bắt buộc.';
    if (createForm.start_date && createForm.end_date && createForm.end_date < createForm.start_date) {
      errors.end_date = 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.';
    }
    if (!createForm.reason.trim()) errors.reason = 'Lý do đăng ký là bắt buộc.';

    if (Object.keys(errors).length > 0) {
      setFormErrors(errors);
      return;
    }

    setSubmitting(true);
    try {
      const res = await api.createTemporaryRegistration({
        apartment_id: createForm.apartment_id,
        resident_id: createForm.resident_id,
        registration_type: createForm.registration_type,
        start_date: createForm.start_date,
        end_date: createForm.end_date,
        reason: createForm.reason,
        identity_card_front_url: createForm.identity_card_front_url || null,
        identity_card_back_url: createForm.identity_card_back_url || null,
        notes: createForm.notes || null,
      });

      showToast('success', res.message || 'Tạo hồ sơ tạm trú / tạm vắng thành công!');
      setIsCreateModalOpen(false);
      // Reset form
      setCreateForm({
        apartment_id: '',
        resident_id: '',
        registration_type: 'TEMPORARY_STAY',
        start_date: new Date().toISOString().split('T')[0],
        end_date: '',
        reason: '',
        identity_card_front_url: '',
        identity_card_back_url: '',
        notes: '',
      });

      // Nếu đang ở trang khác trang 1, chuyển về trang 1 để xem hồ sơ mới tạo ở đầu bảng
      if (currentPage !== 1) {
        setCurrentPage(1);
      } else {
        await fetchRegistrations(1);
      }
    } catch (err: any) {
      if (err.data?.errors) {
        const backendErrors: Record<string, string> = {};
        Object.entries(err.data.errors).forEach(([k, v]) => {
          backendErrors[k] = Array.isArray(v) ? v[0] : String(v);
        });
        setFormErrors(backendErrors);
      }
      showToast('error', err.message || 'Không thể tạo hồ sơ.');
    } finally {
      setSubmitting(false);
    }
  };

  // Phê duyệt hồ sơ
  const handleApprove = async (item: TemporaryRegistrationItem) => {
    setActionLoading(true);
    try {
      const res = await api.approveTemporaryRegistration(item.id, {
        notes: actionNotes,
        police_reference_code: actionPoliceCode,
      });
      showToast('success', res.message || 'Phê duyệt hồ sơ thành công!');
      setIsDetailModalOpen(false);
      setActionNotes('');
      setActionPoliceCode('');
      await fetchRegistrations(currentPage);
    } catch (err: any) {
      const msg = err.response?.data?.message || err.message || 'Lỗi khi phê duyệt hồ sơ.';
      showToast('error', msg);
      setIsDetailModalOpen(false);
      await fetchRegistrations(currentPage);
    } finally {
      setActionLoading(false);
    }
  };

  // Từ chối hồ sơ
  const handleReject = async () => {
    if (!selectedItem) return;
    if (!rejectReason.trim()) {
      showToast('error', 'Vui lòng nhập lý do từ chối hồ sơ.');
      return;
    }

    setActionLoading(true);
    try {
      const res = await api.rejectTemporaryRegistration(selectedItem.id, rejectReason);
      showToast('success', res.message || 'Đã từ chối hồ sơ.');
      setIsRejectModalOpen(false);
      setIsDetailModalOpen(false);
      setRejectReason('');
      await fetchRegistrations(currentPage);
    } catch (err: any) {
      const msg = err.response?.data?.message || err.message || 'Lỗi khi từ chối hồ sơ.';
      showToast('error', msg);
      setIsRejectModalOpen(false);
      setIsDetailModalOpen(false);
      await fetchRegistrations(currentPage);
    } finally {
      setActionLoading(false);
    }
  };

  // Nộp sang Công An
  const handleSubmitPolice = async (item: TemporaryRegistrationItem) => {
    setActionLoading(true);
    try {
      const res = await api.submitTemporaryRegistrationToPolice(item.id, {
        police_reference_code: actionPoliceCode,
        notes: actionNotes,
      });
      showToast('success', res.message || 'Đã cập nhật trạng thái gửi Công An!');
      setIsDetailModalOpen(false);
      setActionNotes('');
      setActionPoliceCode('');
      await fetchRegistrations(currentPage);
    } catch (err: any) {
      const msg = err.response?.data?.message || err.message || 'Lỗi khi cập nhật trạng thái.';
      showToast('error', msg);
      setIsDetailModalOpen(false);
      await fetchRegistrations(currentPage);
    } finally {
      setActionLoading(false);
    }
  };

  // Xuất biểu mẫu CT01
  const handleExportForm = async (item: TemporaryRegistrationItem) => {
    try {
      const res = await api.exportTemporaryRegistrationForm(item.id);
      if (res.data?.html_content) {
        setExportHtmlContent(res.data.html_content);
        setExportTitle(res.data.form_title || 'Biểu Mẫu CT01 - Gửi Công An');
        setIsExportModalOpen(true);
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể tạo biểu mẫu xuất.');
    }
  };

  // In biểu mẫu CT01
  const handlePrintExport = () => {
    const printWindow = window.open('', '_blank');
    if (printWindow) {
      printWindow.document.write(exportHtmlContent);
      printWindow.document.close();
      printWindow.focus();
      setTimeout(() => {
        printWindow.print();
      }, 500);
    }
  };

  // =========================================================================
  // XỬ LÝ CHỈNH SỬA & XÓA HỒ SƠ
  // =========================================================================
  const handleOpenEdit = (item: TemporaryRegistrationItem) => {
    if (item.police_status !== 'PENDING' && item.police_status !== 'PENDING_POLICE_SUBMISSION') {
      showToast('error', 'Hồ sơ đã được xử lý hoặc không còn ở trạng thái cho phép chỉnh sửa.');
      return;
    }
    setEditForm({
      id: item.id,
      registration_type: item.registration_type as 'TEMPORARY_STAY' | 'TEMPORARY_ABSENCE',
      start_date: item.start_date ? item.start_date.split('T')[0] : '',
      end_date: item.end_date ? item.end_date.split('T')[0] : '',
      reason: item.reason || '',
      notes: item.notes || '',
      identity_card_front_url: item.identity_card_front_url || '',
      identity_card_back_url: item.identity_card_back_url || '',
      updated_at: item.updated_at || '',
    });
    setIsEditModalOpen(true);
  };

  const handleUpdateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editForm.reason.trim()) {
      showToast('error', 'Vui lòng nhập lý do đăng ký.');
      return;
    }
    if (!editForm.start_date || !editForm.end_date) {
      showToast('error', 'Vui lòng chọn thời hạn từ ngày đến ngày.');
      return;
    }
    if (editForm.end_date < editForm.start_date) {
      showToast('error', 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.');
      return;
    }

    setEditLoading(true);
    try {
      const res = await api.updateTemporaryRegistration(editForm.id, {
        registration_type: editForm.registration_type,
        start_date: editForm.start_date,
        end_date: editForm.end_date,
        reason: editForm.reason,
        notes: editForm.notes || undefined,
        identity_card_front_url: editForm.identity_card_front_url || undefined,
        identity_card_back_url: editForm.identity_card_back_url || undefined,
        updated_at: editForm.updated_at,
      });

      if (res.success) {
        showToast('success', 'Cập nhật hồ sơ tạm trú / tạm vắng thành công!');
        setIsEditModalOpen(false);
        await fetchRegistrations(currentPage);
      } else {
        showToast('error', res.message || 'Cập nhật thất bại.');
      }
    } catch (err: any) {
      const msg = err.data?.message || err.response?.data?.message || err.message || 'Hồ sơ đã được Admin khác cập nhật/xử lý. Vui lòng tải lại dữ liệu mới nhất.';
      showToast('error', msg);
      setIsEditModalOpen(false);
      await fetchRegistrations(currentPage);
    } finally {
      setEditLoading(false);
    }
  };

  const handleOpenDelete = (item: TemporaryRegistrationItem) => {
    if (item.police_status !== 'PENDING' && item.police_status !== 'PENDING_POLICE_SUBMISSION') {
      showToast('error', 'Không thể xóa hồ sơ đã được xử lý hoặc đã gửi Công An.');
      return;
    }
    setDeleteItem(item);
    setIsDeleteModalOpen(true);
  };

  const handleDeleteConfirm = async () => {
    if (!deleteItem) return;
    setDeleteLoading(true);
    try {
      const res = await api.deleteTemporaryRegistration(deleteItem.id);
      if (res.success) {
        showToast('success', 'Xóa hồ sơ tạm trú / tạm vắng thành công!');
        setIsDeleteModalOpen(false);
        setDeleteItem(null);
        if (isDetailModalOpen) setIsDetailModalOpen(false);

        // Xử lý phân trang: Nếu xóa record duy nhất trên trang > 1, tự động quay về trang trước hợp lệ
        if (registrations.length === 1 && currentPage > 1) {
          setCurrentPage((prev) => Math.max(1, prev - 1));
        } else {
          await fetchRegistrations(currentPage);
        }
      } else {
        showToast('error', res.message || 'Xóa hồ sơ thất bại.');
      }
    } catch (err: any) {
      const msg = err.data?.message || err.response?.data?.message || err.message || 'Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.';
      showToast('error', msg);
      setIsDeleteModalOpen(false);
      setDeleteItem(null);
      if (isDetailModalOpen) setIsDetailModalOpen(false);
      await fetchRegistrations(currentPage);
    } finally {
      setDeleteLoading(false);
    }
  };

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* Toast Notification */}
      {toastMessage && (
        <div className={`fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-2xl flex items-center gap-3 text-sm font-semibold transition-all transform animate-bounce ${
          toastMessage.type === 'success' ? 'bg-emerald-600 text-white shadow-emerald-500/30' : 'bg-rose-600 text-white shadow-rose-500/30'
        }`}>
          {toastMessage.type === 'success' ? <CheckCircle2 className="w-5 h-5" /> : <AlertCircle className="w-5 h-5" />}
          <span>{toastMessage.text}</span>
        </div>
      )}

      {/* Header & Actions */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 p-6 rounded-2xl text-white shadow-xl border border-indigo-500/20">
        <div>
          <div className="flex items-center gap-2 text-indigo-400 font-semibold text-xs uppercase tracking-wider">
            <Shield className="w-4 h-4 text-indigo-400" />
            <span>Phân hệ Quản lý Hành chính & Nhân khẩu</span>
          </div>
          <h1 className="text-2xl font-extrabold tracking-tight mt-1 text-white">
            Đăng Ký & Duyệt Tạm Trú / Tạm Vắng (Công An)
          </h1>
          <p className="text-slate-300 text-sm mt-1 max-w-2xl">
            Tiếp nhận hồ sơ tạm trú, tạm vắng cư dân căn hộ; xét duyệt nội bộ ban quản lý và xuất biểu mẫu CT01 chuẩn Công An Nhân Dân.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={() => fetchRegistrations()}
            className="p-2.5 rounded-xl bg-slate-800/80 hover:bg-slate-700 text-slate-200 border border-slate-700 transition"
            title="Làm mới danh sách"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          </button>
          <button
            onClick={() => {
              setFormErrors({});
              setIsCreateModalOpen(true);
            }}
            disabled={isCooldownActive}
            className={`flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold transition shadow-lg ${
              isCooldownActive
                ? 'bg-indigo-900/60 opacity-60 cursor-not-allowed text-slate-300'
                : 'bg-gradient-to-r from-indigo-500 to-sky-500 hover:from-indigo-600 hover:to-sky-600 text-white shadow-indigo-500/25 active:scale-95'
            }`}
            title={isCooldownActive ? `Đang tạm khóa chỉnh sửa (${remainingSeconds}s)` : 'Tạo Hồ Sơ Mới'}
          >
            <Plus className="w-4 h-4" />
            <span>Tạo Hồ Sơ Mới</span>
          </button>
        </div>
      </div>

      {/* Cooldown Banner */}
      <CooldownBanner
        isCooldownActive={isCooldownActive}
        remainingSeconds={remainingSeconds}
        customMessage={cooldownMessage}
      />

      {/* Metric Stat Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Tổng Hồ Sơ</p>
            <p className="text-2xl font-black text-slate-900 mt-1">{totalCount}</p>
          </div>
          <div className="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-600">
            <FileText className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-emerald-600 uppercase tracking-wider">Tạm Trú</p>
            <p className="text-2xl font-black text-emerald-600 mt-1">{stats.stayCount}</p>
          </div>
          <div className="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-600">
            <Building2 className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-amber-600 uppercase tracking-wider">Tạm Vắng</p>
            <p className="text-2xl font-black text-amber-600 mt-1">{stats.absCount}</p>
          </div>
          <div className="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center text-amber-600">
            <Clock className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Chờ Duyệt / CA</p>
            <p className="text-2xl font-black text-indigo-600 mt-1">{stats.pendingCount}</p>
          </div>
          <div className="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center text-indigo-600">
            <Send className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-sky-600 uppercase tracking-wider">Đã Phê Duyệt</p>
            <p className="text-2xl font-black text-sky-600 mt-1">{stats.approvedCount}</p>
          </div>
          <div className="w-10 h-10 rounded-xl bg-sky-50 flex items-center justify-center text-sky-600">
            <CheckCircle2 className="w-5 h-5" />
          </div>
        </div>
      </div>

      {/* Filter & Search Bar */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-4">
        <div className="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
          {/* Search box */}
          <div className="relative flex-1">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Tìm theo Tên cư dân, CCCD/CMND, SĐT, Căn hộ, Mã tham chiếu CA..."
              className="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition"
            />
          </div>

          {/* Type Filter */}
          <div className="w-48">
            <CustomSelect
              value={filterType}
              onChange={(val) => {
                setFilterType(val);
                setCurrentPage(1);
              }}
              options={[
                { value: '', label: 'Tất cả loại đăng ký' },
                { value: 'TEMPORARY_STAY', label: 'Đăng ký Tạm trú' },
                { value: 'TEMPORARY_ABSENCE', label: 'Thông báo Tạm vắng' },
              ]}
              placeholder="Tất cả loại đăng ký"
              searchable={false}
            />
          </div>

          {/* Status Filter */}
          <div className="w-56">
            <CustomSelect
              value={filterStatus}
              onChange={(val) => {
                setFilterStatus(val);
                setCurrentPage(1);
              }}
              options={[
                { value: '', label: 'Tất cả trạng thái Công An' },
                { value: 'PENDING_POLICE_SUBMISSION', label: 'Chờ nộp Công An' },
                { value: 'SUBMITTED_TO_POLICE', label: 'Đã gửi Công An' },
                { value: 'APPROVED', label: 'Đã phê duyệt' },
                { value: 'REJECTED', label: 'Đã từ chối' },
              ]}
              placeholder="Tất cả trạng thái Công An"
              searchable={false}
            />
          </div>

          {/* Apartment Filter */}
          <div className="w-48">
            <CustomSelect
              value={filterApartmentId}
              onChange={(val) => {
                setFilterApartmentId(val);
                setCurrentPage(1);
              }}
              options={[
                { value: '', label: 'Tất cả căn hộ' },
                ...apartments.map((apt) => ({
                  value: apt.id,
                  label: `Căn hộ ${apt.apartment_number}`,
                })),
              ]}
              placeholder="Tất cả căn hộ"
              searchable={true}
            />
          </div>
        </div>
      </div>

      {/* Desktop Main Table (Không bị tràn ngang trên 1024px, 1280px, 1440px, 1920px) */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden hidden lg:block">
        <table className="w-full table-fixed text-left border-collapse min-w-0">
          <colgroup>
            <col className="w-[28%]" />
            <col className="w-[18%]" />
            <col className="w-[21%]" />
            <col className="w-[18%]" />
            <col className="w-[15%]" />
          </colgroup>
          <thead>
            <tr className="bg-slate-50/90 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
              <th className="py-3.5 px-4">Cư Dân & Căn Hộ</th>
              <th className="py-3.5 px-4">Loại & Thời Hạn</th>
              <th className="py-3.5 px-4">Lý Do Đăng Ký</th>
              <th className="py-3.5 px-4">Trạng Thái & Mã CA</th>
              <th className="py-3.5 px-4 text-right">Người Duyệt & Thao Tác</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 text-sm">
            {loading ? (
              <tr>
                <td colSpan={5} className="py-12 text-center text-slate-400">
                  <RefreshCw className="w-6 h-6 animate-spin mx-auto text-indigo-500 mb-2" />
                  <span>Đang tải dữ liệu hồ sơ tạm trú / tạm vắng...</span>
                </td>
              </tr>
            ) : registrations.length === 0 ? (
              <tr>
                <td colSpan={5} className="py-12 text-center text-slate-400">
                  <FileText className="w-10 h-10 text-slate-300 mx-auto mb-2" />
                  <p className="font-semibold text-slate-600">Không tìm thấy hồ sơ nào</p>
                  <p className="text-xs text-slate-400 mt-1">Thử thay đổi bộ lọc hoặc tạo hồ sơ mới.</p>
                </td>
              </tr>
            ) : (
              registrations.map((item) => {
                const user = item.resident?.user;
                const isStay = item.registration_type === 'TEMPORARY_STAY';
                return (
                  <tr key={item.id} className="hover:bg-slate-50/80 transition-colors">
                    {/* Cột 1: Cư Dân & Căn Hộ */}
                    <td className="py-3.5 px-4 align-top">
                      <div className="flex items-start gap-2.5 min-w-0">
                        <div className="w-8 h-8 rounded-full bg-indigo-50 border border-indigo-100 flex items-center justify-center font-bold text-xs text-indigo-600 overflow-hidden shrink-0 mt-0.5">
                          {user?.avatar_url ? (
                            <img src={user.avatar_url} alt="" className="w-full h-full object-cover" />
                          ) : (
                            user?.full_name?.charAt(0)?.toUpperCase() || 'U'
                          )}
                        </div>
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="font-bold text-slate-900 text-sm leading-tight break-words">
                              {user?.full_name || 'N/A'}
                            </span>
                            <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 shrink-0">
                              P.{item.apartment?.apartment_number || 'N/A'}
                            </span>
                          </div>
                          <div className="text-[11px] text-slate-500 mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span className="font-mono">CCCD: <strong className="text-slate-700">{user?.national_id_number || 'Chưa có'}</strong></span>
                            <span>•</span>
                            <span className="font-mono">{user?.phone_number || 'Chưa có SĐT'}</span>
                          </div>
                        </div>
                      </div>
                    </td>

                    {/* Cột 2: Loại & Thời Hạn */}
                    <td className="py-3.5 px-4 align-top">
                      <div>
                        {isStay ? (
                          <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            Tạm Trú
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                            <span className="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                            Tạm Vắng
                          </span>
                        )}
                      </div>
                      <div className="text-xs text-slate-600 mt-1.5 space-y-0.5">
                        <div className="text-[11px]">Từ: <strong className="text-slate-800 font-mono">{formatDateVN(item.start_date)}</strong></div>
                        <div className="text-[11px]">Đến: <strong className="text-slate-800 font-mono">{formatDateVN(item.end_date)}</strong></div>
                      </div>
                    </td>

                    {/* Cột 3: Lý Do Đăng Ký */}
                    <td className="py-3.5 px-4 align-top">
                      <div className="text-xs text-slate-600 break-words line-clamp-3 leading-relaxed" title={item.reason}>
                        {item.reason || '—'}
                      </div>
                    </td>

                    {/* Cột 4: Trạng Thái & Mã Công An */}
                    <td className="py-3.5 px-4 align-top">
                      <div>
                        {item.police_status === 'APPROVED' && (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-sky-50 text-sky-700 border border-sky-200">
                            <CheckCircle2 className="w-3.5 h-3.5 text-sky-600" />
                            Đã Phê Duyệt
                          </span>
                        )}
                        {item.police_status === 'REJECTED' && (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                            <XCircle className="w-3.5 h-3.5 text-rose-600" />
                            Đã Từ Chối
                          </span>
                        )}
                        {item.police_status === 'SUBMITTED_TO_POLICE' && (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                            <Send className="w-3.5 h-3.5 text-indigo-600" />
                            Đã Gửi Công An
                          </span>
                        )}
                        {(item.police_status === 'PENDING_POLICE_SUBMISSION' || item.police_status === 'PENDING') && (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-300">
                            <Clock className="w-3.5 h-3.5 text-slate-500" />
                            Chờ Nộp Công An
                          </span>
                        )}
                      </div>
                      <div className="mt-1.5">
                        {item.police_reference_code ? (
                          <span className="text-[11px] font-mono font-bold text-indigo-700 bg-indigo-50/70 border border-indigo-200/80 px-1.5 py-0.5 rounded inline-block break-all" title={item.police_reference_code}>
                            CA: {item.police_reference_code}
                          </span>
                        ) : (
                          <span className="text-[11px] text-slate-400 italic">Chưa có mã CA</span>
                        )}
                      </div>
                    </td>

                    {/* Cột 5: Người Duyệt & Thao Tác */}
                    <td className="py-3.5 px-4 align-top text-right">
                      {item.reviewer ? (
                        <div className="text-xs text-right mb-2">
                          <div className="font-semibold text-slate-800 truncate" title={item.reviewer.full_name}>{item.reviewer.full_name}</div>
                          <div className="text-[10px] text-slate-400 font-mono">{formatDateVN(item.reviewed_at)}</div>
                        </div>
                      ) : (
                        <div className="text-[11px] text-slate-400 italic text-right mb-2">Chưa duyệt</div>
                      )}

                      <div className="flex items-center justify-end gap-1">
                        <button
                          onClick={() => {
                            setSelectedItem(item);
                            setActionNotes(item.notes || '');
                            setActionPoliceCode(item.police_reference_code || '');
                            setIsDetailModalOpen(true);
                          }}
                          className="p-1.5 rounded-lg hover:bg-indigo-50 text-slate-600 hover:text-indigo-600 transition"
                          title="Xem chi tiết"
                        >
                          <Eye className="w-4 h-4" />
                        </button>
                        {(item.police_status === 'PENDING' || item.police_status === 'PENDING_POLICE_SUBMISSION') && (
                          <>
                            <button
                              onClick={() => handleOpenEdit(item)}
                              disabled={isCooldownActive}
                              className={`p-1.5 rounded-lg transition ${
                                isCooldownActive
                                  ? 'text-slate-300 opacity-50 cursor-not-allowed'
                                  : 'hover:bg-amber-50 text-slate-600 hover:text-amber-600'
                              }`}
                              title={isCooldownActive ? `Đang tạm khóa chỉnh sửa (${remainingSeconds}s)` : 'Chỉnh sửa hồ sơ'}
                            >
                              <Pencil className="w-4 h-4" />
                            </button>
                            <button
                              onClick={() => handleOpenDelete(item)}
                              disabled={isCooldownActive}
                              className={`p-1.5 rounded-lg transition ${
                                isCooldownActive
                                  ? 'text-slate-300 opacity-50 cursor-not-allowed'
                                  : 'hover:bg-rose-50 text-slate-600 hover:text-rose-600'
                              }`}
                              title={isCooldownActive ? `Đang tạm khóa chỉnh sửa (${remainingSeconds}s)` : 'Xóa hồ sơ'}
                            >
                              <Trash2 className="w-4 h-4" />
                            </button>
                          </>
                        )}
                        <button
                          onClick={() => handleExportForm(item)}
                          className="p-1.5 rounded-lg hover:bg-sky-50 text-slate-600 hover:text-sky-600 transition"
                          title="Xuất Biểu Mẫu CT01 (Công An)"
                        >
                          <Printer className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>

        {/* Pagination Bar for Desktop */}
        <div className="p-4 border-t border-slate-200 bg-slate-50 flex items-center justify-between text-xs text-slate-500">
          <span>
            Hiển thị <strong>{registrations.length}</strong> trên tổng số <strong>{totalCount}</strong> hồ sơ
          </span>
          <div className="flex items-center gap-2">
            <button
              disabled={currentPage <= 1}
              onClick={() => setCurrentPage((p) => p - 1)}
              className="px-3 py-1.5 rounded-lg bg-white border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed font-medium transition"
            >
              Trang trước
            </button>
            <span className="font-semibold text-slate-700 px-2">
              {currentPage} / {totalPages || 1}
            </span>
            <button
              disabled={currentPage >= totalPages}
              onClick={() => setCurrentPage((p) => p + 1)}
              className="px-3 py-1.5 rounded-lg bg-white border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed font-medium transition"
            >
              Trang sau
            </button>
          </div>
        </div>
      </div>

      {/* Mobile & Tablet Card Layout (Hiển thị thẻ khi màn hình < 1024px, 768px, 390px - Không giấu dữ liệu) */}
      <div className="space-y-3 block lg:hidden">
        {loading ? (
          <div className="bg-white p-8 rounded-2xl border border-slate-200 text-center text-slate-400">
            <RefreshCw className="w-6 h-6 animate-spin mx-auto text-indigo-500 mb-2" />
            <span className="text-xs">Đang tải dữ liệu hồ sơ...</span>
          </div>
        ) : registrations.length === 0 ? (
          <div className="bg-white p-8 rounded-2xl border border-slate-200 text-center text-slate-400">
            <FileText className="w-10 h-10 text-slate-300 mx-auto mb-2" />
            <p className="font-semibold text-slate-600 text-sm">Không tìm thấy hồ sơ nào</p>
            <p className="text-xs text-slate-400 mt-1">Thử thay đổi bộ lọc hoặc tạo hồ sơ mới.</p>
          </div>
        ) : (
          registrations.map((item) => {
            const user = item.resident?.user;
            const isStay = item.registration_type === 'TEMPORARY_STAY';
            return (
              <div key={item.id} className="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm space-y-3 hover:border-indigo-300 transition-all">
                {/* Header card: Cư dân, Căn hộ, Loại */}
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-2.5 min-w-0">
                    <div className="w-9 h-9 rounded-full bg-indigo-50 border border-indigo-100 flex items-center justify-center font-bold text-xs text-indigo-600 shrink-0">
                      {user?.avatar_url ? (
                        <img src={user.avatar_url} alt="" className="w-full h-full object-cover rounded-full" />
                      ) : (
                        user?.full_name?.charAt(0)?.toUpperCase() || 'U'
                      )}
                    </div>
                    <div className="min-w-0">
                      <div className="font-bold text-slate-900 text-sm leading-tight truncate">
                        {user?.full_name || 'N/A'}
                      </div>
                      <div className="text-[11px] text-slate-500 mt-0.5">
                        Căn hộ: <strong className="text-indigo-700 font-bold">P.{item.apartment?.apartment_number || 'N/A'}</strong>
                      </div>
                    </div>
                  </div>

                  <div className="shrink-0">
                    {isStay ? (
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Tạm Trú
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                        <span className="w-1.5 h-1.5 rounded-full bg-amber-500"></span>Tạm Vắng
                      </span>
                    )}
                  </div>
                </div>

                {/* Chi tiết liên hệ & Thời hạn */}
                <div className="grid grid-cols-2 gap-2 text-xs bg-slate-50 p-2.5 rounded-xl border border-slate-100">
                  <div>
                    <span className="text-[10px] text-slate-400 uppercase font-semibold">Định Danh</span>
                    <div className="text-slate-700 font-mono mt-0.5">CCCD: {user?.national_id_number || '—'}</div>
                    <div className="text-slate-700 font-mono mt-0.5">SĐT: {user?.phone_number || '—'}</div>
                  </div>
                  <div>
                    <span className="text-[10px] text-slate-400 uppercase font-semibold">Thời Hạn</span>
                    <div className="text-slate-700 font-mono mt-0.5">Từ: <strong>{formatDateVN(item.start_date)}</strong></div>
                    <div className="text-slate-700 font-mono mt-0.5">Đến: <strong>{formatDateVN(item.end_date)}</strong></div>
                  </div>
                </div>

                {/* Lý do */}
                <div className="text-xs text-slate-600 bg-slate-50/50 p-2 rounded-lg border border-slate-100">
                  <span className="font-semibold text-slate-700">Lý do: </span>
                  <span className="break-words">{item.reason || '—'}</span>
                </div>

                {/* Trạng thái Công An & Người duyệt */}
                <div className="flex items-center justify-between gap-2 pt-1 border-t border-slate-100 text-xs">
                  <div>
                    {item.police_status === 'APPROVED' && (
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200">
                        <CheckCircle2 className="w-3 h-3 text-sky-600" /> Đã Phê Duyệt
                      </span>
                    )}
                    {item.police_status === 'REJECTED' && (
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                        <XCircle className="w-3 h-3 text-rose-600" /> Đã Từ Chối
                      </span>
                    )}
                    {item.police_status === 'SUBMITTED_TO_POLICE' && (
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                        <Send className="w-3 h-3 text-indigo-600" /> Đã Gửi Công An
                      </span>
                    )}
                    {(item.police_status === 'PENDING_POLICE_SUBMISSION' || item.police_status === 'PENDING') && (
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-300">
                        <Clock className="w-3 h-3 text-slate-500" /> Chờ Nộp CA
                      </span>
                    )}
                    {item.police_reference_code && (
                      <span className="ml-1.5 font-mono text-[10px] font-bold text-indigo-700">
                        {item.police_reference_code}
                      </span>
                    )}
                  </div>

                  <div className="text-[11px] text-slate-400 text-right">
                    {item.reviewer ? (
                      <span>Duyệt: <strong className="text-slate-600">{item.reviewer.full_name}</strong> ({formatDateVN(item.reviewed_at)})</span>
                    ) : (
                      <span className="italic">Chưa duyệt</span>
                    )}
                  </div>
                </div>

                {/* Card Actions */}
                <div className="flex items-center justify-end gap-1.5 pt-2 border-t border-slate-100">
                  <button
                    onClick={() => {
                      setSelectedItem(item);
                      setActionNotes(item.notes || '');
                      setActionPoliceCode(item.police_reference_code || '');
                      setIsDetailModalOpen(true);
                    }}
                    className="flex-1 py-1.5 px-2 rounded-xl bg-indigo-50 text-indigo-700 font-bold text-xs flex items-center justify-center gap-1 hover:bg-indigo-100 transition"
                  >
                    <Eye className="w-3.5 h-3.5" />
                    <span>Chi Tiết</span>
                  </button>
                  {(item.police_status === 'PENDING' || item.police_status === 'PENDING_POLICE_SUBMISSION') && (
                    <>
                      <button
                        onClick={() => handleOpenEdit(item)}
                        disabled={isCooldownActive}
                        className={`py-1.5 px-2.5 rounded-xl border text-xs font-bold transition flex items-center gap-1 ${
                          isCooldownActive
                            ? 'border-slate-200 text-slate-300 opacity-50 cursor-not-allowed'
                            : 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100'
                        }`}
                        title="Chỉnh sửa hồ sơ"
                      >
                        <Pencil className="w-3.5 h-3.5" />
                        <span>Sửa</span>
                      </button>
                      <button
                        onClick={() => handleOpenDelete(item)}
                        disabled={isCooldownActive}
                        className={`py-1.5 px-2.5 rounded-xl border text-xs font-bold transition flex items-center gap-1 ${
                          isCooldownActive
                            ? 'border-slate-200 text-slate-300 opacity-50 cursor-not-allowed'
                            : 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100'
                        }`}
                        title="Xóa hồ sơ"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                        <span>Xóa</span>
                      </button>
                    </>
                  )}
                  <button
                    onClick={() => handleExportForm(item)}
                    className="py-1.5 px-3 rounded-xl bg-sky-50 text-sky-700 font-bold text-xs flex items-center justify-center gap-1 hover:bg-sky-100 border border-sky-200 transition"
                    title="Xuất Biểu Mẫu CT01 (Công An)"
                  >
                    <Printer className="w-3.5 h-3.5" />
                    <span>CT01</span>
                  </button>
                </div>
              </div>
            );
          })
        )}

        {/* Mobile Pagination Bar */}
        <div className="p-3 bg-white rounded-2xl border border-slate-200 flex flex-col sm:flex-row gap-2 items-center justify-between text-xs text-slate-500">
          <span>Hiển thị <strong>{registrations.length}</strong> / <strong>{totalCount}</strong> hồ sơ</span>
          <div className="flex items-center gap-2">
            <button
              disabled={currentPage <= 1}
              onClick={() => setCurrentPage((p) => p - 1)}
              className="px-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200 disabled:opacity-40 font-medium"
            >
              Trước
            </button>
            <span className="font-semibold text-slate-700">{currentPage} / {totalPages || 1}</span>
            <button
              disabled={currentPage >= totalPages}
              onClick={() => setCurrentPage((p) => p + 1)}
              className="px-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200 disabled:opacity-40 font-medium"
            >
              Sau
            </button>
          </div>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* MODAL 1: TẠO HỒ SƠ TẠM TRÚ / TẠM VẮNG MỚI                                 */}
      {/* ========================================================================= */}
      {isCreateModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-3xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto border border-slate-200 animate-in fade-in zoom-in-95 duration-200">
            <div className="p-6 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-slate-50 to-indigo-50/50">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-2xl bg-indigo-600 text-white flex items-center justify-center shadow-lg shadow-indigo-600/20">
                  <FileText className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-lg font-black text-slate-900">Tạo Hồ Sơ Tạm Trú / Tạm Vắng</h3>
                  <p className="text-xs text-slate-500">Điền thông tin và đính kèm CCCD để lập hồ sơ gửi Công An</p>
                </div>
              </div>
              <button
                onClick={() => setIsCreateModalOpen(false)}
                className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateSubmit} className="p-6 space-y-5">
              {/* Loại đăng ký (Tabs Toggle) */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-2">Loại Thủ Tục Đăng Ký *</label>
                <div className="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => setCreateForm({ ...createForm, registration_type: 'TEMPORARY_STAY' })}
                    className={`py-3 px-4 rounded-xl border font-bold text-sm flex items-center justify-center gap-2 transition ${
                      createForm.registration_type === 'TEMPORARY_STAY'
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-300 shadow-sm ring-2 ring-emerald-500/20'
                        : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    <span className="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    <span>1. Đăng ký Tạm Trú</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setCreateForm({ ...createForm, registration_type: 'TEMPORARY_ABSENCE' })}
                    className={`py-3 px-4 rounded-xl border font-bold text-sm flex items-center justify-center gap-2 transition ${
                      createForm.registration_type === 'TEMPORARY_ABSENCE'
                        ? 'bg-amber-50 text-amber-700 border-amber-300 shadow-sm ring-2 ring-amber-500/20'
                        : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'
                    }`}
                  >
                    <span className="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                    <span>2. Thông báo Tạm Vắng</span>
                  </button>
                </div>
              </div>

              {/* Chọn căn hộ & Cư dân */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="block text-xs font-bold text-slate-700 uppercase">Căn Hộ Lưu Trú *</label>
                    {createForm.apartment_id && (
                      <button
                        type="button"
                        onClick={() => {
                          setCreateForm({ ...createForm, apartment_id: '', resident_id: '' });
                          setResidentSearchKeyword('');
                        }}
                        className="text-[11px] text-indigo-600 hover:text-indigo-800 font-medium"
                      >
                        Bỏ chọn căn hộ
                      </button>
                    )}
                  </div>
                  <CustomSelect
                    value={createForm.apartment_id}
                    onChange={(val) => {
                      setCreateForm({ ...createForm, apartment_id: val, resident_id: '' });
                      setResidentSearchKeyword('');
                    }}
                    options={[
                      { value: '', label: '-- Chọn căn hộ (hoặc chọn cư dân bên cạnh trước) --' },
                      ...apartments.map((apt) => ({
                        value: apt.id,
                        label: `Căn hộ ${apt.apartment_number} ${apt.residents_count > 0 ? `(${apt.residents_count} cư dân)` : '(Chưa có cư dân)'}`,
                      })),
                    ]}
                    placeholder="-- Chọn căn hộ --"
                    overlay={true}
                    searchable={true}
                  />
                  {formErrors.apartment_id && <p className="text-xs text-rose-500 mt-1">{formErrors.apartment_id}</p>}
                </div>

                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="block text-xs font-bold text-slate-700 uppercase">
                      Cư Dân Kê Khai * {loadingResidents && <span className="text-[10px] text-indigo-500 font-normal">(Đang tải...)</span>}
                    </label>
                    {createForm.resident_id && (
                      <span className="text-[11px] text-emerald-600 font-medium flex items-center gap-1">
                        <Check className="w-3 h-3" /> Đã chọn
                      </span>
                    )}
                  </div>

                  {/* Ô tìm nhanh cư dân */}
                  <div className="relative mb-2">
                    <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                      type="text"
                      value={residentSearchKeyword}
                      onChange={(e) => setResidentSearchKeyword(e.target.value)}
                      placeholder="Tìm nhanh cư dân theo tên, CCCD, SĐT..."
                      className="w-full pl-8 pr-3 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-lg focus:bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                    {residentSearchKeyword && (
                      <button
                        type="button"
                        onClick={() => setResidentSearchKeyword('')}
                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                      >
                        <X className="w-3 h-3" />
                      </button>
                    )}
                  </div>

                  <CustomSelect
                    disabled={loadingResidents}
                    value={createForm.resident_id}
                    onChange={(val) => handleSelectResident(val)}
                    options={[
                      {
                        value: '',
                        label: availableResidents.length === 0
                          ? (createForm.apartment_id ? '-- Căn hộ này chưa có cư dân nào --' : '-- Không tìm thấy cư dân phù hợp --')
                          : (createForm.apartment_id
                            ? `-- Chọn cư dân căn hộ (${availableResidents.length} người) --`
                            : `-- Chọn cư dân (${availableResidents.length} người, tự điền căn hộ) --`),
                      },
                      ...availableResidents.map((r: ResidentItem) => ({
                        value: r.id,
                        label: `${r.user?.full_name || 'N/A'} ${r.apartment?.apartment_number ? `(Căn ${r.apartment.apartment_number})` : ''} - CCCD: ${r.user?.national_id_number || 'Chưa có'} ${r.user?.phone_number ? `- SĐT: ${r.user.phone_number}` : ''}`,
                      })),
                    ]}
                    placeholder="-- Chọn cư dân --"
                    overlay={true}
                    searchable={true}
                  />
                  {formErrors.resident_id && <p className="text-xs text-rose-500 mt-1">{formErrors.resident_id}</p>}

                  {/* Cảnh báo nếu căn hộ đã chọn có 0 cư dân */}
                  {createForm.apartment_id && !loadingResidents && apartmentResidents.length === 0 && (
                    <div className="mt-2 p-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-start gap-2">
                      <AlertCircle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                      <div>
                        <span className="font-semibold">Căn hộ này chưa có cư dân.</span>
                        <p className="text-[11px] text-amber-700 mt-0.5">
                          Vui lòng chọn căn hộ khác có cư dân, hoặc bấm "Bỏ chọn căn hộ" để chọn trực tiếp từ danh sách cư dân toàn tòa nhà.
                        </p>
                      </div>
                    </div>
                  )}
                </div>
              </div>

              {/* Ngày bắt đầu - Ngày kết thúc */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Ngày Bắt Đầu *</label>
                  <input
                    type="date"
                    value={createForm.start_date}
                    onChange={(e) => setCreateForm({ ...createForm, start_date: e.target.value })}
                    className="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                  />
                  {formErrors.start_date && <p className="text-xs text-rose-500 mt-1">{formErrors.start_date}</p>}
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Ngày Kết Thúc *</label>
                  <input
                    type="date"
                    value={createForm.end_date}
                    onChange={(e) => setCreateForm({ ...createForm, end_date: e.target.value })}
                    className="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                  />
                  {formErrors.end_date && <p className="text-xs text-rose-500 mt-1">{formErrors.end_date}</p>}
                </div>
              </div>

              {/* Lý do đăng ký */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Lý Do Đăng Ký / Kê Khai *</label>
                <textarea
                  rows={2}
                  value={createForm.reason}
                  onChange={(e) => setCreateForm({ ...createForm, reason: e.target.value })}
                  placeholder="Ví dụ: Đăng ký tạm trú làm việc dài hạn tại TP.HCM / Tạm vắng về quê nghỉ lễ..."
                  className={`w-full px-3.5 py-2 text-sm bg-slate-50 border rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 ${
                    formErrors.reason ? 'border-rose-400' : 'border-slate-200'
                  }`}
                />
                {formErrors.reason && <p className="text-xs text-rose-500 mt-1">{formErrors.reason}</p>}
              </div>

              {/* Tải lên ảnh CCCD 2 mặt */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-2">Ảnh CCCD / CMND 2 Mặt (Tùy chọn)</label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Mặt trước */}
                  <div className="border border-slate-200 rounded-2xl p-4 bg-slate-50/50 flex flex-col items-center justify-center text-center">
                    <p className="text-xs font-bold text-slate-600 mb-2">Mặt trước CCCD</p>
                    {createForm.identity_card_front_url ? (
                      <div className="relative group w-full h-32 rounded-xl overflow-hidden border border-slate-200 bg-white">
                        <img src={createForm.identity_card_front_url} alt="CCCD Mặt trước" className="w-full h-full object-cover" />
                        <button
                          type="button"
                          onClick={() => setCreateForm({ ...createForm, identity_card_front_url: '' })}
                          className="absolute top-2 right-2 p-1 rounded-full bg-rose-600 text-white opacity-90 hover:opacity-100 transition shadow"
                        >
                          <X className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    ) : (
                      <label className="w-full h-32 border-2 border-dashed border-slate-300 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/30 transition">
                        <Upload className="w-6 h-6 text-slate-400 mb-1" />
                        <span className="text-xs font-semibold text-slate-600">
                          {uploadingFront ? 'Đang tải lên...' : 'Tải ảnh mặt trước'}
                        </span>
                        <span className="text-[10px] text-slate-400">JPG, PNG, PDF tối đa 5MB</span>
                        <input
                          type="file"
                          accept="image/*,application/pdf"
                          onChange={(e) => handleUploadCccd(e, 'front')}
                          className="hidden"
                          disabled={uploadingFront}
                        />
                      </label>
                    )}
                  </div>

                  {/* Mặt sau */}
                  <div className="border border-slate-200 rounded-2xl p-4 bg-slate-50/50 flex flex-col items-center justify-center text-center">
                    <p className="text-xs font-bold text-slate-600 mb-2">Mặt sau CCCD</p>
                    {createForm.identity_card_back_url ? (
                      <div className="relative group w-full h-32 rounded-xl overflow-hidden border border-slate-200 bg-white">
                        <img src={createForm.identity_card_back_url} alt="CCCD Mặt sau" className="w-full h-full object-cover" />
                        <button
                          type="button"
                          onClick={() => setCreateForm({ ...createForm, identity_card_back_url: '' })}
                          className="absolute top-2 right-2 p-1 rounded-full bg-rose-600 text-white opacity-90 hover:opacity-100 transition shadow"
                        >
                          <X className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    ) : (
                      <label className="w-full h-32 border-2 border-dashed border-slate-300 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/30 transition">
                        <Upload className="w-6 h-6 text-slate-400 mb-1" />
                        <span className="text-xs font-semibold text-slate-600">
                          {uploadingBack ? 'Đang tải lên...' : 'Tải ảnh mặt sau'}
                        </span>
                        <span className="text-[10px] text-slate-400">JPG, PNG, PDF tối đa 5MB</span>
                        <input
                          type="file"
                          accept="image/*,application/pdf"
                          onChange={(e) => handleUploadCccd(e, 'back')}
                          className="hidden"
                          disabled={uploadingBack}
                        />
                      </label>
                    )}
                  </div>
                </div>
              </div>

              {/* Ghi chú */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Ghi Chú Tiếp Nhận</label>
                <input
                  type="text"
                  value={createForm.notes}
                  onChange={(e) => setCreateForm({ ...createForm, notes: e.target.value })}
                  placeholder="Ghi chú nội bộ cho ban quản lý..."
                  className="w-full px-3.5 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                />
              </div>

              {/* Modal Actions */}
              <div className="pt-3 border-t border-slate-100 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setIsCreateModalOpen(false)}
                  className="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-600 font-bold hover:bg-slate-50 transition"
                >
                  Hủy Bỏ
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-lg shadow-indigo-600/20 disabled:opacity-50 transition flex items-center gap-2"
                >
                  {submitting && <RefreshCw className="w-4 h-4 animate-spin" />}
                  <span>Lưu Hồ Sơ</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL 2: CHI TIẾT HỒ SƠ & THAO TÁC XÉT DUYỆT                              */}
      {/* ========================================================================= */}
      {isDetailModalOpen && selectedItem && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-3xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto border border-slate-200 animate-in fade-in zoom-in-95 duration-200">
            <div className="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-600 flex items-center justify-center font-bold">
                  <FileCheck className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-lg font-black text-slate-900">Chi Tiết Hồ Sơ Tạm Trú / Tạm Vắng</h3>
                  <p className="text-xs text-slate-500 font-mono">Mã hồ sơ: {selectedItem.id}</p>
                </div>
              </div>
              <button
                onClick={() => setIsDetailModalOpen(false)}
                className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-6 space-y-6">
              {/* Thẻ tóm tắt trạng thái */}
              <div className="flex items-center justify-between p-4 rounded-2xl bg-slate-50 border border-slate-200">
                <div>
                  <span className="text-xs text-slate-500 font-bold uppercase tracking-wider">Trạng Thái Công An</span>
                  <div className="text-base font-extrabold text-slate-900 mt-0.5">
                    {selectedItem.police_status === 'APPROVED' && <span className="text-sky-600">Đã Phê Duyệt</span>}
                    {selectedItem.police_status === 'REJECTED' && <span className="text-rose-600">Đã Từ Chối</span>}
                    {selectedItem.police_status === 'SUBMITTED_TO_POLICE' && <span className="text-indigo-600">Đã Gửi Công An</span>}
                    {(selectedItem.police_status === 'PENDING_POLICE_SUBMISSION' || selectedItem.police_status === 'PENDING') && (
                      <span className="text-amber-600">Chờ Nộp Công An</span>
                    )}
                  </div>
                </div>
                <div className="text-right">
                  <span className="text-xs text-slate-500 font-bold uppercase tracking-wider">Mã Tham Chiếu CA</span>
                  <div className="font-mono text-sm font-bold text-indigo-700 mt-0.5">
                    {selectedItem.police_reference_code || 'Chưa thiết lập'}
                  </div>
                </div>
              </div>

              {/* Thông tin cư dân & Căn hộ */}
              <div className="grid grid-cols-2 gap-4 text-xs">
                <div className="p-4 rounded-xl border border-slate-100 bg-slate-50/50 space-y-1.5">
                  <p className="font-bold text-slate-700 text-sm">{selectedItem.resident?.user?.full_name}</p>
                  <p className="text-slate-500">Số CCCD: <strong className="text-slate-700">{selectedItem.resident?.user?.national_id_number || 'N/A'}</strong></p>
                  <p className="text-slate-500">Điện thoại: <strong className="text-slate-700">{selectedItem.resident?.user?.phone_number || 'N/A'}</strong></p>
                  <p className="text-slate-500">Vai trò: <strong className="text-slate-700">{selectedItem.resident?.resident_type}</strong></p>
                </div>

                <div className="p-4 rounded-xl border border-slate-100 bg-slate-50/50 space-y-1.5">
                  <p className="font-bold text-slate-700 text-sm">Căn Hộ: {selectedItem.apartment?.apartment_number}</p>
                  <p className="text-slate-500">Loại: <strong className="text-slate-700">{selectedItem.registration_type === 'TEMPORARY_STAY' ? 'Đăng ký Tạm trú' : 'Thông báo Tạm vắng'}</strong></p>
                  <p className="text-slate-500">Thời hạn: <strong className="text-slate-700 font-mono">{formatDateVN(selectedItem.start_date)} → {formatDateVN(selectedItem.end_date)}</strong></p>
                  <p className="text-slate-500">Ngày tạo: <strong className="text-slate-700 font-mono">{formatDateVN(selectedItem.created_at, true)}</strong></p>
                </div>
              </div>

              {/* Lý do & Ghi chú */}
              <div className="space-y-3 text-xs">
                <div>
                  <span className="font-bold text-slate-700">Lý do kê khai:</span>
                  <p className="p-3 mt-1 rounded-xl bg-slate-50 border border-slate-200 text-slate-800">{selectedItem.reason}</p>
                </div>
                {selectedItem.notes && (
                  <div>
                    <span className="font-bold text-slate-700">Ghi chú xử lý / Lý do từ chối:</span>
                    <p className="p-3 mt-1 rounded-xl bg-amber-50/60 border border-amber-200 text-amber-900">{selectedItem.notes}</p>
                  </div>
                )}
              </div>

              {/* Ảnh CCCD nếu có */}
              {(selectedItem.identity_card_front_url || selectedItem.identity_card_back_url) && (
                <div>
                  <p className="text-xs font-bold text-slate-700 uppercase mb-2">Ảnh CCCD Đính Kèm</p>
                  <div className="grid grid-cols-2 gap-3">
                    {selectedItem.identity_card_front_url && (
                      <a href={selectedItem.identity_card_front_url} target="_blank" rel="noreferrer" className="block border border-slate-200 rounded-xl overflow-hidden hover:opacity-90">
                        <img src={selectedItem.identity_card_front_url} alt="CCCD Mặt trước" className="w-full h-32 object-cover" />
                        <span className="block text-center py-1 text-[11px] font-bold text-slate-600 bg-slate-50">Mặt trước</span>
                      </a>
                    )}
                    {selectedItem.identity_card_back_url && (
                      <a href={selectedItem.identity_card_back_url} target="_blank" rel="noreferrer" className="block border border-slate-200 rounded-xl overflow-hidden hover:opacity-90">
                        <img src={selectedItem.identity_card_back_url} alt="CCCD Mặt sau" className="w-full h-32 object-cover" />
                        <span className="block text-center py-1 text-[11px] font-bold text-slate-600 bg-slate-50">Mặt sau</span>
                      </a>
                    )}
                  </div>
                </div>
              )}

              {/* Nhập mã tham chiếu CA & Ghi chú trước khi duyệt */}
              {selectedItem.police_status !== 'APPROVED' && selectedItem.police_status !== 'REJECTED' && (
                <div className="p-4 rounded-2xl border border-indigo-100 bg-indigo-50/40 space-y-3">
                  <h4 className="text-xs font-bold text-indigo-900 uppercase">Thao Tác Duyệt & Cập Nhật Mã Tham Chiếu</h4>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <label className="block text-[11px] font-bold text-slate-600 mb-1">Mã tham chiếu Công An (nếu có)</label>
                      <input
                        type="text"
                        value={actionPoliceCode}
                        onChange={(e) => setActionPoliceCode(e.target.value)}
                        placeholder="VD: CA-2026-TT-089"
                        className="w-full px-3 py-2 text-xs bg-white border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500/20 font-mono"
                      />
                    </div>
                    <div>
                      <label className="block text-[11px] font-bold text-slate-600 mb-1">Ghi chú phê duyệt</label>
                      <input
                        type="text"
                        value={actionNotes}
                        onChange={(e) => setActionNotes(e.target.value)}
                        placeholder="VD: Đã đối soát CCCD hợp lệ"
                        className="w-full px-3 py-2 text-xs bg-white border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                      />
                    </div>
                  </div>
                </div>
              )}

              {/* Action Buttons */}
              <div className="pt-2 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3">
                <button
                  type="button"
                  onClick={() => handleExportForm(selectedItem)}
                  className="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs flex items-center gap-2 transition"
                >
                  <Printer className="w-4 h-4 text-slate-600" />
                  <span>Xuất Mẫu CT01 (In / Lưu PDF)</span>
                </button>

                <div className="flex items-center gap-2">
                  {selectedItem.police_status !== 'APPROVED' && selectedItem.police_status !== 'REJECTED' && (
                    <>
                      <button
                        type="button"
                        onClick={() => setIsRejectModalOpen(true)}
                        disabled={actionLoading}
                        className="px-4 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs border border-rose-200 transition"
                      >
                        Từ Chối
                      </button>
                      <button
                        type="button"
                        onClick={() => handleSubmitPolice(selectedItem)}
                        disabled={actionLoading}
                        className="px-4 py-2 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs border border-indigo-200 transition"
                      >
                        Nộp Sang Công An
                      </button>
                      <button
                        type="button"
                        onClick={() => handleApprove(selectedItem)}
                        disabled={actionLoading}
                        className="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-md shadow-emerald-600/20 transition flex items-center gap-1.5"
                      >
                        <Check className="w-4 h-4" />
                        <span>Phê Duyệt Hồ Sơ</span>
                      </button>
                    </>
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL 3: TỪ CHỐI HỒ SƠ                                                    */}
      {/* ========================================================================= */}
      {isRejectModalOpen && selectedItem && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 border border-slate-200 animate-in fade-in zoom-in-95">
            <div className="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mb-4">
              <XCircle className="w-6 h-6" />
            </div>
            <h3 className="text-lg font-black text-slate-900">Từ Chối Hồ Sơ Tạm Trú / Tạm Vắng</h3>
            <p className="text-xs text-slate-500 mt-1">
              Vui lòng cung cấp lý do từ chối để phản hồi cư dân và lưu nhật ký hệ thống.
            </p>

            <div className="mt-4">
              <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Lý do từ chối *</label>
              <textarea
                rows={3}
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                placeholder="Ví dụ: Thiếu ảnh CCCD mặt sau / Thời hạn đăng ký vượt quá hợp đồng..."
                className="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-rose-500/20"
              />
            </div>

            <div className="mt-6 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={() => setIsRejectModalOpen(false)}
                className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50 transition"
              >
                Hủy
              </button>
              <button
                type="button"
                onClick={handleReject}
                disabled={actionLoading}
                className="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md shadow-rose-600/20 transition"
              >
                Xác Nhận Từ Chối
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL 4: XUẤT BIỂU MẪU CÔNG AN CT01                                       */}
      {/* ========================================================================= */}
      {isExportModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl shadow-2xl max-w-4xl w-full max-h-[95vh] flex flex-col border border-slate-200 animate-in fade-in zoom-in-95">
            <div className="p-4 px-6 border-b border-slate-200 flex items-center justify-between bg-slate-50 rounded-t-3xl">
              <div>
                <h3 className="text-base font-black text-slate-900">{exportTitle}</h3>
                <p className="text-xs text-slate-500">Mẫu CT01 - Tờ khai thay đổi thông tin cư trú (Thông tư số 66/2023/TT-BCA)</p>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={handlePrintExport}
                  className="flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow transition"
                >
                  <Printer className="w-4 h-4" />
                  <span>In Biểu Mẫu / Lưu PDF</span>
                </button>
                <button
                  type="button"
                  onClick={() => setIsExportModalOpen(false)}
                  className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
            </div>

            {/* Iframe preview of printable HTML */}
            <div className="flex-1 p-4 bg-slate-100 overflow-hidden">
              <iframe
                srcDoc={exportHtmlContent}
                title="Biểu Mẫu CT01 Preview"
                className="w-full h-[65vh] bg-white rounded-xl shadow border border-slate-200"
              />
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL 5: CHỈNH SỬA HỒ SƠ TẠM TRÚ / TẠM VẮNG                               */}
      {/* ========================================================================= */}
      {isEditModalOpen && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-3xl shadow-2xl max-w-xl w-full max-h-[90vh] overflow-y-auto border border-slate-200 animate-in fade-in zoom-in-95">
            <div className="p-6 border-b border-slate-100 flex items-center justify-between">
              <div>
                <h3 className="text-lg font-black text-slate-900">Chỉnh Sửa Hồ Sơ Tạm Trú / Tạm Vắng</h3>
                <p className="text-xs text-slate-500">Mã hồ sơ: {editForm.id}</p>
              </div>
              <button
                type="button"
                onClick={() => setIsEditModalOpen(false)}
                className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleUpdateSubmit} className="p-6 space-y-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Loại đăng ký *</label>
                <div className="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => setEditForm((prev) => ({ ...prev, registration_type: 'TEMPORARY_STAY' }))}
                    className={`py-2 px-3 text-xs font-bold rounded-xl border text-center transition ${
                      editForm.registration_type === 'TEMPORARY_STAY'
                        ? 'bg-sky-50 border-sky-500 text-sky-700'
                        : 'bg-white border-slate-200 text-slate-600'
                    }`}
                  >
                    Đăng Ký Tạm Trú
                  </button>
                  <button
                    type="button"
                    onClick={() => setEditForm((prev) => ({ ...prev, registration_type: 'TEMPORARY_ABSENCE' }))}
                    className={`py-2 px-3 text-xs font-bold rounded-xl border text-center transition ${
                      editForm.registration_type === 'TEMPORARY_ABSENCE'
                        ? 'bg-amber-50 border-amber-500 text-amber-700'
                        : 'bg-white border-slate-200 text-slate-600'
                    }`}
                  >
                    Thông Báo Tạm Vắng
                  </button>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Từ ngày *</label>
                  <input
                    type="date"
                    value={editForm.start_date}
                    onChange={(e) => setEditForm((prev) => ({ ...prev, start_date: e.target.value }))}
                    className="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                    required
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Đến ngày *</label>
                  <input
                    type="date"
                    value={editForm.end_date}
                    onChange={(e) => setEditForm((prev) => ({ ...prev, end_date: e.target.value }))}
                    className="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                    required
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Lý do đăng ký *</label>
                <textarea
                  rows={3}
                  value={editForm.reason}
                  onChange={(e) => setEditForm((prev) => ({ ...prev, reason: e.target.value }))}
                  className="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1">Ghi chú tiếp nhận</label>
                <textarea
                  rows={2}
                  value={editForm.notes}
                  onChange={(e) => setEditForm((prev) => ({ ...prev, notes: e.target.value }))}
                  className="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                />
              </div>

              <div className="pt-2 flex items-center justify-end gap-3 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setIsEditModalOpen(false)}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50 transition"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={editLoading}
                  className="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold text-xs shadow-md shadow-sky-600/20 transition flex items-center gap-1.5"
                >
                  {editLoading ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
                  <span>Lưu Thay Đổi</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL 6: XÁC NHẬN XÓA HỒ SƠ                                               */}
      {/* ========================================================================= */}
      {isDeleteModalOpen && deleteItem && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 border border-slate-200 animate-in fade-in zoom-in-95">
            <div className="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mb-4">
              <Trash2 className="w-6 h-6" />
            </div>
            <h3 className="text-lg font-black text-slate-900">Xác Nhận Xóa Hồ Sơ</h3>
            <p className="text-xs text-slate-600 mt-2 leading-relaxed">
              Bạn có chắc chắn muốn xóa hồ sơ tạm trú / tạm vắng mã: <strong className="font-mono text-slate-900">{deleteItem.id}</strong>?
            </p>
            <p className="text-[11px] text-rose-600 bg-rose-50 p-2.5 rounded-xl border border-rose-100 mt-3">
              Thao tác này sẽ xóa hoàn toàn bản ghi khỏi hệ thống và không thể khôi phục.
            </p>

            <div className="mt-6 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={() => {
                  setIsDeleteModalOpen(false);
                  setDeleteItem(null);
                }}
                className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-bold text-xs hover:bg-slate-50 transition"
              >
                Hủy
              </button>
              <button
                type="button"
                onClick={handleDeleteConfirm}
                disabled={deleteLoading}
                className="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-md shadow-rose-600/20 transition flex items-center gap-1.5"
              >
                {deleteLoading ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Trash2 className="w-4 h-4" />}
                <span>Xóa Vĩnh Viễn</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default TemporaryRegistrationManagement;
