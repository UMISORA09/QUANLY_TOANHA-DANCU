import React, { useState, useEffect, useCallback, useMemo } from 'react';
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
  Eye,
  Calendar,
  AlertCircle,
  Upload,
  Image as ImageIcon,
  X,
  Pencil,
  Trash2,
  Download,
  Printer,
  ChevronRight,
  ShieldCheck,
  Check,
  Building2,
  FileCheck,
} from 'lucide-react';
import { api, TemporaryRegistrationItem } from '../Services/api';
import { useRealtimeSync, useModuleCooldown } from '../Hooks/useRealtimeSync';
import { CooldownBanner } from './Realtime/CooldownBanner';

/**
 * Định dạng ngày theo chuẩn Việt Nam dd/MM/yyyy
 */
const formatDateVN = (dateStr?: string | null): string => {
  if (!dateStr || typeof dateStr !== 'string' || dateStr.trim() === '') return '—';
  const trimmed = dateStr.trim();
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
    }).format(dt);
  } catch {
    return dateStr;
  }
};

export const ResidentTemporaryRegistrationPanel: React.FC = () => {
  const { isCooldownActive, remainingSeconds, message: cooldownMessage, startCooldown } =
    useModuleCooldown('temporary_registrations');

  const [registrations, setRegistrations] = useState<TemporaryRegistrationItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);
  const [totalCount, setTotalCount] = useState<number>(0);
  const perPage = 15;

  // Filter state
  const [search, setSearch] = useState<string>('');
  const [filterType, setFilterType] = useState<string>('');
  const [filterStatus, setFilterStatus] = useState<string>('');

  // Modals state
  const [isCreateModalOpen, setIsCreateModalOpen] = useState<boolean>(false);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);
  const [selectedItem, setSelectedItem] = useState<TemporaryRegistrationItem | null>(null);
  const [isEditMode, setIsEditMode] = useState<boolean>(false);

  // Form State
  const [formData, setFormData] = useState({
    registration_type: 'TEMPORARY_STAY' as 'TEMPORARY_STAY' | 'TEMPORARY_ABSENCE',
    start_date: '',
    end_date: '',
    reason: '',
    notes: '',
    identity_card_front_url: '',
    identity_card_back_url: '',
    updated_at: '',
  });

  const [uploadingFront, setUploadingFront] = useState<boolean>(false);
  const [uploadingBack, setUploadingBack] = useState<boolean>(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const showToast = (type: 'success' | 'error', text: string) => {
    setToastMessage({ type, text });
    setTimeout(() => setToastMessage(null), 4000);
  };

  // Fetch data
  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, any> = {
        page: currentPage,
        limit: perPage,
      };
      if (search.trim()) params.search = search.trim();
      if (filterType) params.registration_type = filterType;
      if (filterStatus) params.police_status = filterStatus;

      const res = await api.getTemporaryRegistrations(params);
      if (res && res.data) {
        setRegistrations(res.data);
        if (res.meta) {
          setTotalPages(res.meta.last_page);
          setTotalCount(res.meta.total);
        }
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể tải danh sách hồ sơ.');
    } finally {
      setLoading(false);
    }
  }, [currentPage, search, filterType, filterStatus]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  // Realtime SSE sync
  useRealtimeSync({
    channel: 'temporary_registrations',
    onEvent: () => {
      fetchData();
    },
  });

  // Calculate statistics
  const stats = useMemo(() => {
    const pending = registrations.filter((r) =>
      ['PENDING', 'PENDING_POLICE_SUBMISSION'].includes(r.police_status)
    ).length;
    const submitted = registrations.filter((r) => r.police_status === 'SUBMITTED_TO_POLICE').length;
    const approved = registrations.filter((r) => r.police_status === 'APPROVED').length;
    const rejected = registrations.filter((r) => r.police_status === 'REJECTED').length;
    return { pending, submitted, approved, rejected, total: totalCount || registrations.length };
  }, [registrations, totalCount]);

  // Handle open create modal
  const openCreateModal = () => {
    setIsEditMode(false);
    setSelectedItem(null);
    setFormData({
      registration_type: 'TEMPORARY_STAY',
      start_date: new Date().toISOString().slice(0, 10),
      end_date: '',
      reason: '',
      notes: '',
      identity_card_front_url: '',
      identity_card_back_url: '',
      updated_at: '',
    });
    setFormError(null);
    setIsCreateModalOpen(true);
  };

  // Handle open edit modal
  const openEditModal = (item: TemporaryRegistrationItem) => {
    if (!['PENDING', 'PENDING_POLICE_SUBMISSION'].includes(item.police_status)) {
      showToast('error', 'Chỉ có thể chỉnh sửa hồ sơ đang ở trạng thái chờ tiếp nhận.');
      return;
    }
    setIsEditMode(true);
    setSelectedItem(item);
    setFormData({
      registration_type: item.registration_type,
      start_date: item.start_date || '',
      end_date: item.end_date || '',
      reason: item.reason || '',
      notes: (item as any).notes || '',
      identity_card_front_url: (item as any).identity_card_front_url || '',
      identity_card_back_url: (item as any).identity_card_back_url || '',
      updated_at: (item as any).updated_at || '',
    });
    setFormError(null);
    setIsCreateModalOpen(true);
  };

  // Handle upload CCCD
  const handleUploadCccd = async (e: React.ChangeEvent<HTMLInputElement>, side: 'front' | 'back') => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (side === 'front') setUploadingFront(true);
    else setUploadingBack(true);

    try {
      const res = await api.uploadTemporaryRegistrationCccd(file, side);
      if (res && res.url) {
        setFormData((prev) => ({
          ...prev,
          [side === 'front' ? 'identity_card_front_url' : 'identity_card_back_url']: res.url,
        }));
        showToast('success', `Đã tải ảnh CCCD mặt ${side === 'front' ? 'trước' : 'sau'} thành công.`);
      }
    } catch (err: any) {
      showToast('error', err.message || 'Lỗi khi tải ảnh lên.');
    } finally {
      if (side === 'front') setUploadingFront(false);
      else setUploadingBack(false);
    }
  };

  // Handle submit form
  const handleSubmitForm = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);

    if (!formData.start_date) {
      setFormError('Vui lòng chọn ngày bắt đầu.');
      return;
    }
    if (!formData.end_date) {
      setFormError('Vui lòng chọn ngày kết thúc.');
      return;
    }
    if (new Date(formData.end_date) < new Date(formData.start_date)) {
      setFormError('Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.');
      return;
    }
    if (!formData.reason.trim()) {
      setFormError('Vui lòng nhập lý do đăng ký.');
      return;
    }

    setSubmitting(true);
    try {
      if (isEditMode && selectedItem) {
        await api.updateTemporaryRegistration(selectedItem.id, {
          registration_type: formData.registration_type,
          start_date: formData.start_date,
          end_date: formData.end_date,
          reason: formData.reason,
          identity_card_front_url: formData.identity_card_front_url || null,
          identity_card_back_url: formData.identity_card_back_url || null,
          updated_at: formData.updated_at,
        });
        showToast('success', 'Cập nhật hồ sơ thành công.');
      } else {
        await api.createTemporaryRegistration({
          resident_id: '',
          apartment_id: '',
          registration_type: formData.registration_type,
          start_date: formData.start_date,
          end_date: formData.end_date,
          reason: formData.reason,
          identity_card_front_url: formData.identity_card_front_url || null,
          identity_card_back_url: formData.identity_card_back_url || null,
          notes: formData.notes || null,
        } as any);
        showToast('success', 'Tạo hồ sơ đăng ký tạm trú / tạm vắng thành công.');
      }

      setIsCreateModalOpen(false);
      fetchData();
    } catch (err: any) {
      setFormError(err.message || 'Có lỗi xảy ra khi lưu hồ sơ.');
    } finally {
      setSubmitting(false);
    }
  };

  // Handle delete
  const handleDelete = async (item: TemporaryRegistrationItem) => {
    if (!['PENDING', 'PENDING_POLICE_SUBMISSION'].includes(item.police_status)) {
      showToast('error', 'Chỉ có thể xóa hồ sơ đang ở trạng thái chờ tiếp nhận.');
      return;
    }
    if (!window.confirm('Bạn có chắc chắn muốn xóa hồ sơ đăng ký này?')) {
      return;
    }

    try {
      await api.deleteTemporaryRegistration(item.id);
      showToast('success', 'Đã xóa hồ sơ thành công.');
      fetchData();
    } catch (err: any) {
      showToast('error', err.message || 'Không thể xóa hồ sơ.');
    }
  };

  // Status badge renderer
  const renderStatusBadge = (status: string) => {
    switch (status) {
      case 'APPROVED':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <CheckCircle2 className="w-3.5 h-3.5" />
            Đã duyệt
          </span>
        );
      case 'SUBMITTED_TO_POLICE':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200">
            <Send className="w-3.5 h-3.5" />
            Đã gửi Công An
          </span>
        );
      case 'REJECTED':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">
            <XCircle className="w-3.5 h-3.5" />
            Bị từ chối
          </span>
        );
      case 'PENDING':
      case 'PENDING_POLICE_SUBMISSION':
      default:
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
            <Clock className="w-3.5 h-3.5" />
            Chờ xử lý
          </span>
        );
    }
  };

  return (
    <div className="max-w-6xl mx-auto space-y-6 animate-in fade-in duration-200">
      {/* Toast Notification */}
      {toastMessage && (
        <div
          className={`fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-lg border text-sm font-medium flex items-center gap-2.5 animate-in slide-in-from-bottom-2 ${
            toastMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
              : 'bg-rose-50 text-rose-800 border-rose-200'
          }`}
        >
          {toastMessage.type === 'success' ? (
            <CheckCircle2 className="w-4 h-4 text-emerald-600" />
          ) : (
            <AlertCircle className="w-4 h-4 text-rose-600" />
          )}
          <span>{toastMessage.text}</span>
        </div>
      )}

      {/* Cooldown Banner */}
      {isCooldownActive && (
        <CooldownBanner
          isCooldownActive={isCooldownActive}
          remainingSeconds={remainingSeconds}
          customMessage={cooldownMessage || 'Vui lòng chờ giây lát trước khi thực hiện tiếp thao tác.'}
        />
      )}

      {/* Header Banner */}
      <div className="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-5">
        <div className="space-y-1.5">
          <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-emerald-700">
            <ShieldCheck className="w-4 h-4 text-emerald-600" />
            <span>Thủ Tục Cư Trú & Công An Phường</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2.5">
            Đăng ký Tạm trú / Tạm vắng
          </h1>
          <p className="text-sm text-slate-500 max-w-2xl">
            Khai báo thông tin lưu trú tạm thời hoặc tạm vắng cho bản thân và thành viên trong căn hộ theo quy định của
            Ban Quản Lý và Công An khu vực.
          </p>
        </div>

        <button
          onClick={openCreateModal}
          className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm shadow-sm transition active:scale-95"
        >
          <Plus className="w-4 h-4" />
          <span>Khai báo hồ sơ mới</span>
        </button>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div className="bg-white p-4 sm:p-5 rounded-xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
          <span className="text-xs font-medium text-slate-500">Chờ xử lý</span>
          <div className="flex items-baseline justify-between mt-2">
            <span className="text-2xl font-bold text-amber-600">{stats.pending}</span>
            <Clock className="w-5 h-5 text-amber-500 opacity-60" />
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
          <span className="text-xs font-medium text-slate-500">Đã gửi Công An</span>
          <div className="flex items-baseline justify-between mt-2">
            <span className="text-2xl font-bold text-blue-600">{stats.submitted}</span>
            <Send className="w-5 h-5 text-blue-500 opacity-60" />
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
          <span className="text-xs font-medium text-slate-500">Đã phê duyệt</span>
          <div className="flex items-baseline justify-between mt-2">
            <span className="text-2xl font-bold text-emerald-600">{stats.approved}</span>
            <CheckCircle2 className="w-5 h-5 text-emerald-500 opacity-60" />
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-xl border border-slate-200/80 shadow-sm flex flex-col justify-between">
          <span className="text-xs font-medium text-slate-500">Từ chối / Cần chỉnh</span>
          <div className="flex items-baseline justify-between mt-2">
            <span className="text-2xl font-bold text-rose-600">{stats.rejected}</span>
            <XCircle className="w-5 h-5 text-rose-500 opacity-60" />
          </div>
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div className="bg-white p-4 rounded-xl border border-slate-200/80 shadow-sm flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between">
        <div className="relative flex-1">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder="Tìm theo lý do, mã Công An..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-9 pr-4 py-2 text-sm rounded-lg border border-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
          />
        </div>

        <div className="flex items-center gap-2.5 overflow-x-auto pb-1 sm:pb-0">
          <select
            value={filterType}
            onChange={(e) => setFilterType(e.target.value)}
            className="text-xs font-medium py-2 px-3 rounded-lg border border-slate-200 bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
          >
            <option value="">Tất cả loại đăng ký</option>
            <option value="TEMPORARY_STAY">Tạm trú</option>
            <option value="TEMPORARY_ABSENCE">Tạm vắng</option>
          </select>

          <select
            value={filterStatus}
            onChange={(e) => setFilterStatus(e.target.value)}
            className="text-xs font-medium py-2 px-3 rounded-lg border border-slate-200 bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
          >
            <option value="">Tất cả trạng thái</option>
            <option value="PENDING_POLICE_SUBMISSION">Chờ xử lý</option>
            <option value="SUBMITTED_TO_POLICE">Đã gửi Công An</option>
            <option value="APPROVED">Đã phê duyệt</option>
            <option value="REJECTED">Bị từ chối</option>
          </select>

          <button
            onClick={() => fetchData()}
            disabled={loading}
            className="p-2 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 transition"
            title="Làm mới"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin text-emerald-600' : ''}`} />
          </button>
        </div>
      </div>

      {/* Registrations List / Cards */}
      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-12 text-center text-slate-400 space-y-3">
            <RefreshCw className="w-8 h-8 animate-spin mx-auto text-emerald-600" />
            <p className="text-sm">Đang tải danh sách hồ sơ tạm trú / tạm vắng...</p>
          </div>
        ) : registrations.length === 0 ? (
          <div className="p-12 text-center text-slate-400 space-y-3">
            <FileText className="w-12 h-12 mx-auto text-slate-300 stroke-[1.5]" />
            <h3 className="text-base font-semibold text-slate-700">Chưa có hồ sơ đăng ký nào</h3>
            <p className="text-sm text-slate-400 max-w-sm mx-auto">
              Bạn chưa có hồ sơ đăng ký tạm trú hoặc tạm vắng nào. Nhấn vào nút bên dưới để tạo hồ sơ đầu tiên.
            </p>
            <button
              onClick={openCreateModal}
              className="mt-2 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-sm transition"
            >
              <Plus className="w-4 h-4" />
              <span>Tạo hồ sơ mới</span>
            </button>
          </div>
        ) : (
          <div className="divide-y divide-slate-100">
            {registrations.map((item) => {
              const isPending = ['PENDING', 'PENDING_POLICE_SUBMISSION'].includes(item.police_status);
              const isStay = item.registration_type === 'TEMPORARY_STAY';

              return (
                <div
                  key={item.id}
                  className="p-5 sm:p-6 hover:bg-slate-50/60 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4"
                >
                  <div className="space-y-2 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span
                        className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold ${
                          isStay ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-amber-50 text-amber-700 border border-amber-200'
                        }`}
                      >
                        {isStay ? 'TẠM TRÚ' : 'TẠM VẮNG'}
                      </span>
                      {renderStatusBadge(item.police_status)}
                      {item.police_reference_code && (
                        <span className="text-[11px] font-mono px-2 py-0.5 rounded bg-slate-100 text-slate-600">
                          Mã CA: {item.police_reference_code}
                        </span>
                      )}
                    </div>

                    <div className="text-sm font-semibold text-slate-800">
                      Lý do: <span className="font-normal text-slate-600">{item.reason}</span>
                    </div>

                    <div className="flex flex-wrap items-center gap-4 text-xs text-slate-400">
                      <span className="flex items-center gap-1">
                        <Calendar className="w-3.5 h-3.5 text-slate-400" />
                        Từ {formatDateVN(item.start_date)} đến {formatDateVN(item.end_date)}
                      </span>
                      <span>•</span>
                      <span>Ngày tạo: {formatDateVN((item as any).created_at)}</span>
                    </div>
                  </div>

                  {/* Actions */}
                  <div className="flex items-center gap-2 shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-slate-100">
                    <button
                      onClick={() => {
                        setSelectedItem(item);
                        setIsDetailModalOpen(true);
                      }}
                      className="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition flex items-center gap-1.5"
                    >
                      <Eye className="w-3.5 h-3.5" />
                      <span>Chi tiết</span>
                    </button>

                    {isPending && (
                      <>
                        <button
                          onClick={() => openEditModal(item)}
                          className="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition flex items-center gap-1.5"
                          title="Sửa hồ sơ"
                        >
                          <Pencil className="w-3.5 h-3.5 text-amber-600" />
                          <span>Sửa</span>
                        </button>
                        <button
                          onClick={() => handleDelete(item)}
                          className="px-3 py-1.5 rounded-lg border border-rose-200 text-xs font-semibold text-rose-600 hover:bg-rose-50 transition flex items-center gap-1.5"
                          title="Xóa hồ sơ"
                        >
                          <Trash2 className="w-3.5 h-3.5 text-rose-500" />
                          <span>Xóa</span>
                        </button>
                      </>
                    )}

                    {item.police_status === 'APPROVED' && (
                      <a
                        href={api.getTemporaryRegistrationDownloadUrl(item.id)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="px-3 py-1.5 rounded-lg border border-emerald-200 bg-emerald-50 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 transition flex items-center gap-1.5"
                      >
                        <Printer className="w-3.5 h-3.5" />
                        <span>Mẫu CT01</span>
                      </a>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="p-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>
              Trang {currentPage} / {totalPages} (Tổng số {totalCount} hồ sơ)
            </span>
            <div className="flex items-center gap-2">
              <button
                disabled={currentPage <= 1}
                onClick={() => setCurrentPage((p) => p - 1)}
                className="px-3 py-1.5 rounded-lg border border-slate-200 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-50"
              >
                Trước
              </button>
              <button
                disabled={currentPage >= totalPages}
                onClick={() => setCurrentPage((p) => p + 1)}
                className="px-3 py-1.5 rounded-lg border border-slate-200 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-slate-50"
              >
                Sau
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Modal Tạo mới / Cập nhật hồ sơ */}
      {isCreateModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl max-w-xl w-full p-6 sm:p-8 space-y-6 shadow-2xl relative max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-slate-100 pb-4">
              <div className="space-y-0.5">
                <h3 className="text-lg font-bold text-slate-900">
                  {isEditMode ? 'Chỉnh sửa hồ sơ khai báo' : 'Khai báo hồ sơ Tạm trú / Tạm vắng'}
                </h3>
                <p className="text-xs text-slate-400">
                  Hồ sơ sẽ được chuyển tới Ban Quản Lý và Công An Phường xử lý.
                </p>
              </div>
              <button
                onClick={() => setIsCreateModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {formError && (
              <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-xs font-medium text-rose-700 flex items-center gap-2">
                <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
                <span>{formError}</span>
              </div>
            )}

            <form onSubmit={handleSubmitForm} className="space-y-4">
              {/* Loại đăng ký */}
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1.5">Loại thủ tục</label>
                <div className="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => setFormData((p) => ({ ...p, registration_type: 'TEMPORARY_STAY' }))}
                    className={`py-3 px-4 rounded-xl border text-xs font-bold transition flex items-center justify-center gap-2 ${
                      formData.registration_type === 'TEMPORARY_STAY'
                        ? 'border-emerald-600 bg-emerald-50/50 text-emerald-800'
                        : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                    }`}
                  >
                    <CheckCircle2
                      className={`w-4 h-4 ${
                        formData.registration_type === 'TEMPORARY_STAY' ? 'text-emerald-600' : 'text-slate-300'
                      }`}
                    />
                    <span>Đăng ký Tạm Trú</span>
                  </button>

                  <button
                    type="button"
                    onClick={() => setFormData((p) => ({ ...p, registration_type: 'TEMPORARY_ABSENCE' }))}
                    className={`py-3 px-4 rounded-xl border text-xs font-bold transition flex items-center justify-center gap-2 ${
                      formData.registration_type === 'TEMPORARY_ABSENCE'
                        ? 'border-amber-600 bg-amber-50/50 text-amber-800'
                        : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                    }`}
                  >
                    <Clock
                      className={`w-4 h-4 ${
                        formData.registration_type === 'TEMPORARY_ABSENCE' ? 'text-amber-600' : 'text-slate-300'
                      }`}
                    />
                    <span>Khai báo Tạm Vắng</span>
                  </button>
                </div>
              </div>

              {/* Thời gian */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1.5">Ngày bắt đầu *</label>
                  <input
                    type="date"
                    required
                    value={formData.start_date}
                    onChange={(e) => setFormData((p) => ({ ...p, start_date: e.target.value }))}
                    className="w-full px-3.5 py-2.5 text-sm rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1.5">Ngày kết thúc *</label>
                  <input
                    type="date"
                    required
                    value={formData.end_date}
                    onChange={(e) => setFormData((p) => ({ ...p, end_date: e.target.value }))}
                    className="w-full px-3.5 py-2.5 text-sm rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
                  />
                </div>
              </div>

              {/* Lý do */}
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1.5">Lý do đăng ký *</label>
                <textarea
                  required
                  rows={3}
                  placeholder="Ví dụ: Lưu trú làm việc tại công ty, Đi công tác ngoại tỉnh, Nghỉ dưỡng dài ngày..."
                  value={formData.reason}
                  onChange={(e) => setFormData((p) => ({ ...p, reason: e.target.value }))}
                  className="w-full px-3.5 py-2.5 text-sm rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
                />
              </div>

              {/* Tải ảnh CCCD */}
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                  Ảnh chụp CCCD / Hộ chiếu (Tùy chọn)
                </label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  {/* Mặt trước */}
                  <div className="border border-dashed border-slate-300 rounded-xl p-3 text-center">
                    {formData.identity_card_front_url ? (
                      <div className="space-y-2">
                        <img
                          src={formData.identity_card_front_url}
                          alt="CCCD mặt trước"
                          className="h-24 w-full object-cover rounded-lg border border-slate-200"
                        />
                        <button
                          type="button"
                          onClick={() => setFormData((p) => ({ ...p, identity_card_front_url: '' }))}
                          className="text-[11px] text-rose-600 font-semibold hover:underline"
                        >
                          Xóa ảnh mặt trước
                        </button>
                      </div>
                    ) : (
                      <label className="cursor-pointer block py-3">
                        <Upload className="w-5 h-5 mx-auto text-slate-400 mb-1" />
                        <span className="text-xs font-semibold text-slate-600 block">
                          {uploadingFront ? 'Đang tải...' : 'Tải ảnh mặt trước'}
                        </span>
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
                  <div className="border border-dashed border-slate-300 rounded-xl p-3 text-center">
                    {formData.identity_card_back_url ? (
                      <div className="space-y-2">
                        <img
                          src={formData.identity_card_back_url}
                          alt="CCCD mặt sau"
                          className="h-24 w-full object-cover rounded-lg border border-slate-200"
                        />
                        <button
                          type="button"
                          onClick={() => setFormData((p) => ({ ...p, identity_card_back_url: '' }))}
                          className="text-[11px] text-rose-600 font-semibold hover:underline"
                        >
                          Xóa ảnh mặt sau
                        </button>
                      </div>
                    ) : (
                      <label className="cursor-pointer block py-3">
                        <Upload className="w-5 h-5 mx-auto text-slate-400 mb-1" />
                        <span className="text-xs font-semibold text-slate-600 block">
                          {uploadingBack ? 'Đang tải...' : 'Tải ảnh mặt sau'}
                        </span>
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

              {/* Submit Buttons */}
              <div className="pt-4 flex items-center justify-end gap-3 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setIsCreateModalOpen(false)}
                  className="px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-sm transition flex items-center gap-2 disabled:opacity-60"
                >
                  {submitting && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                  <span>{isEditMode ? 'Lưu thay đổi' : 'Gửi hồ sơ khai báo'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Xem chi tiết hồ sơ */}
      {isDetailModalOpen && selectedItem && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-lg w-full p-6 sm:p-8 space-y-6 shadow-2xl relative max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-slate-100 pb-4">
              <div className="space-y-0.5">
                <h3 className="text-lg font-bold text-slate-900">Chi tiết hồ sơ khai báo</h3>
                <p className="text-xs text-slate-400">Mã định danh: {selectedItem.id}</p>
              </div>
              <button
                onClick={() => setIsDetailModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-4 text-xs">
              <div className="flex justify-between items-center p-3 rounded-xl bg-slate-50">
                <span className="font-medium text-slate-500">Trạng thái xử lý:</span>
                <div>{renderStatusBadge(selectedItem.police_status)}</div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="p-3 rounded-xl border border-slate-100">
                  <span className="text-slate-400 block mb-1">Loại thủ tục:</span>
                  <span className="font-semibold text-slate-800">
                    {selectedItem.registration_type === 'TEMPORARY_STAY' ? 'Đăng ký Tạm Trú' : 'Khai báo Tạm Vắng'}
                  </span>
                </div>
                <div className="p-3 rounded-xl border border-slate-100">
                  <span className="text-slate-400 block mb-1">Mã hồ sơ Công An:</span>
                  <span className="font-semibold font-mono text-slate-800">
                    {selectedItem.police_reference_code || 'Chưa cấp'}
                  </span>
                </div>
              </div>

              <div className="p-3 rounded-xl border border-slate-100">
                <span className="text-slate-400 block mb-1">Thời hạn đăng ký:</span>
                <span className="font-semibold text-slate-800">
                  Từ ngày {formatDateVN(selectedItem.start_date)} đến ngày {formatDateVN(selectedItem.end_date)}
                </span>
              </div>

              <div className="p-3 rounded-xl border border-slate-100">
                <span className="text-slate-400 block mb-1">Lý do khai báo:</span>
                <p className="text-slate-700 whitespace-pre-wrap">{selectedItem.reason}</p>
              </div>

              {(selectedItem as any).notes && (
                <div className="p-3 rounded-xl bg-amber-50/50 border border-amber-200/60">
                  <span className="text-amber-800 font-semibold block mb-1">Ghi chú / Phản hồi từ BQL:</span>
                  <p className="text-amber-900 whitespace-pre-wrap">{(selectedItem as any).notes}</p>
                </div>
              )}

              {/* Ảnh CCCD nếu có */}
              {((selectedItem as any).identity_card_front_url || (selectedItem as any).identity_card_back_url) && (
                <div className="space-y-2">
                  <span className="text-slate-500 font-semibold block">Ảnh giấy tờ kèm theo:</span>
                  <div className="grid grid-cols-2 gap-2">
                    {(selectedItem as any).identity_card_front_url && (
                      <a
                        href={(selectedItem as any).identity_card_front_url}
                        target="_blank"
                        rel="noreferrer"
                        className="block border rounded-lg overflow-hidden group"
                      >
                        <img
                          src={(selectedItem as any).identity_card_front_url}
                          alt="CCCD trước"
                          className="h-20 w-full object-cover group-hover:scale-105 transition"
                        />
                      </a>
                    )}
                    {(selectedItem as any).identity_card_back_url && (
                      <a
                        href={(selectedItem as any).identity_card_back_url}
                        target="_blank"
                        rel="noreferrer"
                        className="block border rounded-lg overflow-hidden group"
                      >
                        <img
                          src={(selectedItem as any).identity_card_back_url}
                          alt="CCCD sau"
                          className="h-20 w-full object-cover group-hover:scale-105 transition"
                        />
                      </a>
                    )}
                  </div>
                </div>
              )}
            </div>

            <div className="pt-4 flex items-center justify-between border-t border-slate-100">
              {selectedItem.police_status === 'APPROVED' ? (
                <a
                  href={api.getTemporaryRegistrationDownloadUrl(selectedItem.id)}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold hover:bg-emerald-100 transition flex items-center gap-1.5"
                >
                  <Download className="w-4 h-4" />
                  <span>Tải Biểu Mẫu CT01</span>
                </a>
              ) : (
                <span className="text-[11px] text-slate-400">
                  Biểu mẫu CT01 sẽ khả dụng sau khi hồ sơ được phê duyệt.
                </span>
              )}

              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="px-4 py-2 rounded-xl bg-slate-100 text-slate-700 text-xs font-semibold hover:bg-slate-200 transition"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
