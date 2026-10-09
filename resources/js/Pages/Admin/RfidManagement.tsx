import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import {
  CreditCard,
  Plus,
  Search,
  Filter,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
  Lock,
  Unlock,
  Trash2,
  Edit,
  Eye,
  AlertCircle,
  CheckCircle2,
  Clock,
  Radio,
  Building,
  User,
  Calendar,
  X,
  FileText,
  DollarSign,
  ChevronLeft,
  ChevronRight,
  Loader2,
} from 'lucide-react';
import {
  rfidApi,
  RfidCardItem,
  RfidApartmentOption,
  RfidResidentOption,
  RfidCardDetailResponse,
} from '../../Services/rfidApi';
import { useRealtimeSync, useModuleCooldown, RealtimeEventPayload } from '../../Hooks/useRealtimeSync';

interface RfidManagementProps {
  embedded?: boolean;
  portalMode?: 'admin' | 'manager' | 'receptionist';
}

export const RfidManagement: React.FC<RfidManagementProps> = ({
  embedded = false,
  portalMode = 'admin',
}) => {
  // 1. Data States
  const [cards, setCards] = useState<RfidCardItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [pagination, setPagination] = useState({
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: 0,
  });

  // 2. Filter & Search States
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [cardTypeFilter, setCardTypeFilter] = useState<string>('ALL');
  const [apartmentFilter, setApartmentFilter] = useState<string>('');
  const [toastMessage, setToastMessage] = useState<{ type: 'success' | 'error' | 'info'; text: string } | null>(null);

  // 3. Metadata Dropdowns
  const [apartments, setApartments] = useState<RfidApartmentOption[]>([]);
  const [residents, setResidents] = useState<RfidResidentOption[]>([]);

  // 4. Modal States
  const [isCreateModalOpen, setIsCreateModalOpen] = useState<boolean>(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState<boolean>(false);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState<boolean>(false);

  const [selectedCard, setSelectedCard] = useState<RfidCardItem | null>(null);
  const [cardDetail, setCardDetail] = useState<RfidCardDetailResponse | null>(null);
  const [detailLoading, setDetailLoading] = useState<boolean>(false);
  const [actionLoading, setActionLoading] = useState<boolean>(false);

  // 5. Form States
  const [formData, setFormData] = useState({
    card_uid: '',
    card_number: '',
    card_type: 'RESIDENT_ALL_ACCESS',
    assigned_apartment_id: '',
    assigned_user_id: '',
    issued_date: new Date().toISOString().split('T')[0],
    expiry_date: '',
    status: 'ACTIVE',
    deposit_fee: 50000,
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // 6. Server-synchronized Cooldown Hook (120s)
  const { isCooldownActive, remainingSeconds, message: cooldownMessage, checkServerCooldown } = useModuleCooldown('rfid_cards');

  const showToast = useCallback((type: 'success' | 'error' | 'info', text: string) => {
    setToastMessage({ type, text });
    setTimeout(() => setToastMessage(null), 4000);
  }, []);

  // 7. Load Data
  const loadCards = useCallback(async (page: number = 1, showSpinner: boolean = true) => {
    if (showSpinner) setLoading(true);
    try {
      const res = await rfidApi.listCards({
        page,
        per_page: pagination.perPage,
        search: searchTerm.trim() || undefined,
        status: statusFilter !== 'ALL' ? statusFilter : undefined,
        card_type: cardTypeFilter !== 'ALL' ? cardTypeFilter : undefined,
        apartment_id: apartmentFilter || undefined,
      });

      if (res.success) {
        setCards(res.data);
        setPagination({
          currentPage: res.meta.current_page,
          lastPage: res.meta.last_page,
          perPage: res.meta.per_page,
          total: res.meta.total,
        });
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể tải danh sách thẻ RFID.');
    } finally {
      if (showSpinner) setLoading(false);
    }
  }, [pagination.perPage, searchTerm, statusFilter, cardTypeFilter, apartmentFilter, showToast]);

  // Load Metadata
  useEffect(() => {
    const fetchMetadata = async () => {
      try {
        const aptRes = await rfidApi.getApartments();
        if (aptRes.success) setApartments(aptRes.data);
        const resRes = await rfidApi.getResidents();
        if (resRes.success) setResidents(resRes.data);
      } catch {
        // ignore
      }
    };
    fetchMetadata();
  }, []);

  // Filter apartment residents when apartment changes in form
  const filteredFormResidents = useMemo(() => {
    if (!formData.assigned_apartment_id) return residents;
    return residents.filter(r => !r.apartment_id || r.apartment_id === formData.assigned_apartment_id);
  }, [residents, formData.assigned_apartment_id]);

  // Debounced search
  const searchTimeoutRef = useRef<any>(null);
  useEffect(() => {
    if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);
    searchTimeoutRef.current = setTimeout(() => {
      loadCards(1, false);
    }, 300);
    return () => clearTimeout(searchTimeoutRef.current);
  }, [searchTerm, statusFilter, cardTypeFilter, apartmentFilter]);

  // Initial load
  useEffect(() => {
    loadCards(1, true);
  }, []);

  // 8. Realtime Event Listener via SSE
  const handleRealtimeEvent = useCallback((event: RealtimeEventPayload) => {
    if (event.module !== 'rfid_cards' && event.module !== 'rfid') {
      return;
    }

    const action = (event.action || '').toUpperCase();
    const entityId = event.entity_id;

    if (action === 'CREATED') {
      showToast('info', `Hệ thống vừa cấp mới thẻ RFID.`);
      loadCards(pagination.currentPage, false);
    } else if (action === 'STATUS_CHANGED' || action === 'UPDATED') {
      const newStatus = event.status;
      setCards(prev =>
        prev.map(c => {
          if (c.id === entityId) {
            return {
              ...c,
              ...(event.status ? { status: event.status as any } : {}),
              ...(event.card_type ? { card_type: event.card_type as any } : {}),
            };
          }
          return c;
        })
      );
      showToast('info', `Thẻ RFID ${event.card_number || ''} vừa được cập nhật trạng thái.`);
    } else if (action === 'DELETED') {
      if (event.is_revoked) {
        setCards(prev =>
          prev.map(c => (c.id === entityId ? { ...c, status: 'REVOKED' } : c))
        );
      } else {
        setCards(prev => prev.filter(c => c.id !== entityId));
      }
      showToast('info', `Thẻ RFID đã được cập nhật/thu hồi.`);
    }

    // Refresh server cooldown
    checkServerCooldown();
  }, [pagination.currentPage, loadCards, showToast, checkServerCooldown]);

  useRealtimeSync({
    channel: 'quoc-tin.rfid-cards',
    onEvent: handleRealtimeEvent,
    onReconnect: () => {
      loadCards(pagination.currentPage, false);
      checkServerCooldown();
    },
  });

  // 9. Actions
  const handleToggleStatus = async (card: RfidCardItem) => {
    if (isCooldownActive) {
      showToast('error', `Chức năng đang tạm khóa trong ${remainingSeconds}s.`);
      return;
    }

    try {
      setActionLoading(true);
      const res = await rfidApi.toggleStatus(card.id);
      if (res.success) {
        setCards(prev => prev.map(c => (c.id === card.id ? res.data : c)));
        showToast('success', res.message);
        checkServerCooldown();
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể đổi trạng thái thẻ.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleOpenDetail = async (card: RfidCardItem) => {
    setSelectedCard(card);
    setIsDetailModalOpen(true);
    setDetailLoading(true);
    try {
      const res = await rfidApi.getCard(card.id);
      if (res.success) {
        setCardDetail(res.data);
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể tải chi tiết thẻ.');
    } finally {
      setDetailLoading(false);
    }
  };

  const handleOpenEdit = (card: RfidCardItem) => {
    setSelectedCard(card);
    setFormData({
      card_uid: card.card_uid,
      card_number: card.card_number,
      card_type: card.card_type,
      assigned_apartment_id: card.assigned_apartment_id || '',
      assigned_user_id: card.assigned_user_id || '',
      issued_date: card.issued_date,
      expiry_date: card.expiry_date || '',
      status: card.status,
      deposit_fee: card.deposit_fee || 50000,
    });
    setFormErrors({});
    setIsEditModalOpen(true);
  };

  const handleOpenDelete = (card: RfidCardItem) => {
    if (isCooldownActive) {
      showToast('error', `Chức năng đang tạm khóa trong ${remainingSeconds}s.`);
      return;
    }
    setSelectedCard(card);
    setIsDeleteModalOpen(true);
  };

  const handleDeleteConfirm = async () => {
    if (!selectedCard) return;
    try {
      setActionLoading(true);
      const res = await rfidApi.deleteCard(selectedCard.id);
      if (res.success) {
        showToast('success', res.message);
        setIsDeleteModalOpen(false);
        loadCards(pagination.currentPage, false);
        checkServerCooldown();
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể xóa thẻ.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (isCooldownActive) {
      showToast('error', `Chức năng đang tạm khóa trong ${remainingSeconds}s.`);
      return;
    }

    setFormErrors({});
    try {
      setActionLoading(true);
      const res = await rfidApi.createCard({
        card_uid: formData.card_uid,
        card_number: formData.card_number,
        card_type: formData.card_type,
        assigned_apartment_id: formData.assigned_apartment_id || null,
        assigned_user_id: formData.assigned_user_id || null,
        issued_date: formData.issued_date,
        expiry_date: formData.expiry_date || null,
        status: formData.status,
        deposit_fee: Number(formData.deposit_fee) || 0,
      });

      if (res.success) {
        showToast('success', res.message);
        setIsCreateModalOpen(false);
        setFormData({
          card_uid: '',
          card_number: '',
          card_type: 'RESIDENT_ALL_ACCESS',
          assigned_apartment_id: '',
          assigned_user_id: '',
          issued_date: new Date().toISOString().split('T')[0],
          expiry_date: '',
          status: 'ACTIVE',
          deposit_fee: 50000,
        });
        loadCards(1, false);
        checkServerCooldown();
      }
    } catch (err: any) {
      if (err.data?.errors) {
        const errs: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.data.errors)) {
          errs[k] = Array.isArray(v) ? (v[0] as string) : (v as string);
        }
        setFormErrors(errs);
      } else {
        showToast('error', err.message || 'Lỗi cấp thẻ.');
      }
    } finally {
      setActionLoading(false);
    }
  };

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedCard) return;
    if (isCooldownActive) {
      showToast('error', `Chức năng đang tạm khóa trong ${remainingSeconds}s.`);
      return;
    }

    setFormErrors({});
    try {
      setActionLoading(true);
      const res = await rfidApi.updateCard(selectedCard.id, {
        card_uid: formData.card_uid,
        card_number: formData.card_number,
        card_type: formData.card_type as any,
        assigned_apartment_id: formData.assigned_apartment_id || null,
        assigned_user_id: formData.assigned_user_id || null,
        issued_date: formData.issued_date,
        expiry_date: formData.expiry_date || null,
        status: formData.status as any,
        deposit_fee: Number(formData.deposit_fee) || 0,
      });

      if (res.success) {
        showToast('success', res.message);
        setIsEditModalOpen(false);
        setCards(prev => prev.map(c => (c.id === selectedCard.id ? res.data : c)));
        checkServerCooldown();
      }
    } catch (err: any) {
      if (err.data?.errors) {
        const errs: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.data.errors)) {
          errs[k] = Array.isArray(v) ? (v[0] as string) : (v as string);
        }
        setFormErrors(errs);
      } else {
        showToast('error', err.message || 'Lỗi cập nhật thẻ.');
      }
    } finally {
      setActionLoading(false);
    }
  };

  // Helper Card Type Label
  const getCardTypeBadge = (type: string) => {
    switch (type) {
      case 'RESIDENT_ALL_ACCESS':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200">Toàn Quyền</span>;
      case 'ELEVATOR_ONLY':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-sky-100 text-sky-800 border border-sky-200">Thang Máy</span>;
      case 'PARKING_ONLY':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800 border border-indigo-200">Gửi Xe</span>;
      case 'VISITOR_PASS':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200">Khách Thăm</span>;
      default:
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">{type}</span>;
    }
  };

  // Helper Status Badge
  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'ACTIVE':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800 border border-green-200">
            <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
            ACTIVE
          </span>
        );
      case 'LOCKED_TEMPORARY':
      case 'LOCKED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
            <Lock className="w-3 h-3 text-amber-600" />
            LOCKED
          </span>
        );
      case 'LOST_REPORTED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-200">
            <AlertCircle className="w-3 h-3 text-red-600" />
            BÁO MẤT
          </span>
        );
      case 'REVOKED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-200 text-slate-700 border border-slate-300">
            THU HỒI
          </span>
        );
      default:
        return <span className="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-700">{status}</span>;
    }
  };

  // 9.5. Statistics Memo for KPI Cards
  const stats = useMemo(() => {
    let activeCount = 0;
    let lockedCount = 0;
    let totalDeposit = 0;

    cards.forEach(card => {
      if (card.status === 'ACTIVE') {
        activeCount++;
      }
      if (card.status === 'LOCKED' || card.status === 'LOCKED_TEMPORARY') {
        lockedCount++;
      }
      totalDeposit += Number(card.deposit_fee || 0);
    });

    return {
      totalCount: pagination.total || cards.length,
      activeCount,
      lockedCount,
      totalDeposit,
    };
  }, [cards, pagination.total]);

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* Toast Notification */}
      {toastMessage && (
        <div
          className={`fixed top-5 right-5 z-50 flex items-center gap-2 px-4 py-3 rounded-lg shadow-xl text-sm font-medium border transition-all duration-300 ${
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
            <Radio className="w-5 h-5 text-sky-600 animate-pulse" />
          )}
          <span>{toastMessage.text}</span>
        </div>
      )}

      {/* 1. Header & Title */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-indigo-600">
            <CreditCard className="w-4 h-4 text-indigo-600" />
            <span>
              {portalMode === 'receptionist'
                ? 'CỔNG LỄ TÂN · THẺ TỪ THÔNG MINH'
                : 'PHÂN HỆ AN NINH · KIỂM SOÁT RA VÀO'}
            </span>
            <span className="text-slate-300">·</span>
            <span className="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded text-[11px] font-semibold border border-indigo-200">
              MODULE #6: RFID CARDS
            </span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Quản Lý Mã Thẻ RFID
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Kiểm soát mã thẻ từ ra vào thang máy, barie cổng tòa nhà & bãi đỗ xe (Chuyển trạng thái ACTIVE / LOCKED tức thì).
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => loadCards(pagination.currentPage, true)}
            className="flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition shadow-sm"
            title="Làm mới dữ liệu"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span>Tải lại</span>
          </button>

          <button
            onClick={() => {
              if (isCooldownActive) {
                showToast('error', `Chức năng đang tạm khóa trong ${remainingSeconds}s.`);
                return;
              }
              setFormData({
                card_uid: '',
                card_number: '',
                card_type: 'RESIDENT_ALL_ACCESS',
                assigned_apartment_id: '',
                assigned_user_id: '',
                issued_date: new Date().toISOString().split('T')[0],
                expiry_date: '',
                status: 'ACTIVE',
                deposit_fee: 50000,
              });
              setFormErrors({});
              setIsCreateModalOpen(true);
            }}
            disabled={isCooldownActive}
            className={`flex items-center gap-2 px-4 py-2 text-xs font-bold text-white rounded-lg transition shadow-sm ${
              isCooldownActive
                ? 'bg-indigo-400 opacity-60 cursor-not-allowed'
                : 'bg-indigo-600 hover:bg-indigo-700 hover:shadow'
            }`}
            title={isCooldownActive ? `Đang tạm khóa chỉnh sửa (${remainingSeconds}s)` : 'Cấp Thẻ Mới'}
          >
            <Plus className="w-4 h-4" />
            <span>Cấp Thẻ Mới</span>
          </button>
        </div>
      </div>

      {/* 2. KPI Statistics Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
            <CreditCard className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tổng số thẻ</div>
            <div className="text-2xl font-black text-slate-900">{stats.totalCount}</div>
            <div className="text-[11px] text-blue-600 font-semibold mt-0.5">Thẻ lưu trên hệ thống</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <ShieldCheck className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Đang hoạt động</div>
            <div className="text-2xl font-black text-emerald-700">{stats.activeCount}</div>
            <div className="text-[11px] text-emerald-600 font-semibold mt-0.5">Quyền ra vào hợp lệ</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <Lock className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Đang tạm khóa</div>
            <div className="text-2xl font-black text-amber-700">{stats.lockedCount}</div>
            <div className="text-[11px] text-amber-600 font-semibold mt-0.5">Chặn quẹt thẻ tức thì</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center flex-shrink-0">
            <DollarSign className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tiền đặt cọc thẻ</div>
            <div className="text-xl sm:text-2xl font-black text-purple-700">
              {new Intl.NumberFormat('vi-VN').format(stats.totalDeposit)} đ
            </div>
            <div className="text-[11px] text-purple-600 font-semibold mt-0.5">Tiền cọc giữ thẻ cư dân</div>
          </div>
        </div>
      </div>

      {/* 3. Filter & Search Bar */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          {/* Search Input */}
          <div className="relative lg:col-span-2">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Tìm theo UID, số thẻ, cư dân, căn hộ..."
              className="w-full pl-9 pr-8 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
            />
            {searchTerm && (
              <button
                type="button"
                onClick={() => setSearchTerm('')}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                title="Xóa tìm kiếm"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>

          {/* Trạng thái filter */}
          <div>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white text-slate-700"
            >
              <option value="ALL">-- Tất cả trạng thái --</option>
              <option value="ACTIVE">ACTIVE (Đang hoạt động)</option>
              <option value="LOCKED">LOCKED (Tạm khóa)</option>
              <option value="LOST_REPORTED">LOST (Báo mất)</option>
              <option value="REVOKED">REVOKED (Thu hồi)</option>
            </select>
          </div>

          {/* Loại thẻ filter */}
          <div>
            <select
              value={cardTypeFilter}
              onChange={(e) => setCardTypeFilter(e.target.value)}
              className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white text-slate-700"
            >
              <option value="ALL">-- Tất cả loại thẻ --</option>
              <option value="RESIDENT_ALL_ACCESS">Thẻ Toàn Quyền</option>
              <option value="ELEVATOR_ONLY">Thẻ Thang Máy</option>
              <option value="PARKING_ONLY">Thẻ Gửi Xe</option>
              <option value="VISITOR_PASS">Thẻ Khách Thăm</option>
            </select>
          </div>

          {/* Căn hộ filter */}
          <div>
            <select
              value={apartmentFilter}
              onChange={(e) => setApartmentFilter(e.target.value)}
              className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white text-slate-700"
            >
              <option value="">-- Lọc theo căn hộ --</option>
              {apartments.map((apt) => (
                <option key={apt.id} value={apt.id}>
                  Căn {apt.apartment_number}
                </option>
              ))}
            </select>
          </div>
        </div>

        {/* Clear filters row if active */}
        {(searchTerm || statusFilter !== 'ALL' || cardTypeFilter !== 'ALL' || apartmentFilter) && (
          <div className="flex items-center justify-between pt-2 border-t border-slate-100 text-xs text-slate-500">
            <span>Đang áp dụng bộ lọc tùy chỉnh</span>
            <button
              onClick={() => {
                setSearchTerm('');
                setStatusFilter('ALL');
                setCardTypeFilter('ALL');
                setApartmentFilter('');
              }}
              className="text-indigo-600 hover:text-indigo-700 font-semibold flex items-center gap-1"
            >
              <X className="w-3.5 h-3.5" />
              <span>Xóa bộ lọc</span>
            </button>
          </div>
        )}
      </div>

      {/* 4. RFID Cards Table */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider font-mono">
                <th className="py-3 px-4">Mã Thẻ (UID & Mặt Thẻ)</th>
                <th className="py-3 px-4">Loại Thẻ</th>
                <th className="py-3 px-4">Cư Dân Sở Hữu</th>
                <th className="py-3 px-4">Căn Hộ</th>
                <th className="py-3 px-4">Ngày Cấp & Hạn Dùng</th>
                <th className="py-3 px-4">Đặt Cọc</th>
                <th className="py-3 px-4 text-center">Trạng Thái</th>
                <th className="py-3 px-4 text-right">Thao Tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading && cards.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Loader2 className="w-6 h-6 animate-spin text-indigo-600" />
                      <span>Đang tải danh sách thẻ RFID...</span>
                    </div>
                  </td>
                </tr>
              ) : cards.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <CreditCard className="w-8 h-8 text-slate-300" />
                      <span className="font-medium">Không tìm thấy thẻ RFID nào phù hợp với bộ lọc.</span>
                    </div>
                  </td>
                </tr>
              ) : (
                cards.map((card) => {
                  return (
                    <tr key={card.id} className="hover:bg-slate-50/70 transition">
                      {/* UID & Card Number */}
                      <td className="py-3 px-4 font-mono">
                        <div className="font-extrabold text-slate-900 tracking-wider text-xs bg-slate-100 px-2 py-0.5 rounded border border-slate-300 inline-block">
                          {card.card_number}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1 flex items-center gap-1 font-sans">
                          <CreditCard className="w-3 h-3 text-slate-400" />
                          <span>UID:</span>
                          <span className="font-mono text-slate-700 font-semibold">{card.card_uid}</span>
                        </div>
                      </td>

                      {/* Loại Thẻ */}
                      <td className="py-3 px-4">
                        {getCardTypeBadge(card.card_type)}
                      </td>

                      {/* Cư Dân */}
                      <td className="py-3 px-4">
                        {card.assigned_user ? (
                          <div>
                            <div className="font-semibold text-slate-900 flex items-center gap-1">
                              <User className="w-3.5 h-3.5 text-slate-400" />
                              <span>{card.assigned_user.full_name}</span>
                            </div>
                            {card.assigned_user.phone_number && (
                              <div className="text-[11px] text-slate-400 mt-0.5">
                                {card.assigned_user.phone_number}
                              </div>
                            )}
                          </div>
                        ) : (
                          <span className="text-slate-400 italic">Chưa gán cư dân</span>
                        )}
                      </td>

                      {/* Căn Hộ */}
                      <td className="py-3 px-4">
                        {card.assigned_apartment ? (
                          <div className="flex items-center gap-1.5 font-semibold text-slate-800">
                            <Building className="w-3.5 h-3.5 text-slate-400" />
                            <span>Căn {card.assigned_apartment.apartment_number}</span>
                          </div>
                        ) : (
                          <span className="text-slate-400 italic">—</span>
                        )}
                      </td>

                      {/* Ngày Cấp & Hạn */}
                      <td className="py-3 px-4 text-slate-600">
                        <div>Cấp: <span className="font-medium text-slate-700">{card.issued_date}</span></div>
                        <div className="text-slate-400 text-[11px] mt-0.5">
                          {card.expiry_date ? `Hạn: ${card.expiry_date}` : 'Vô thời hạn'}
                        </div>
                      </td>

                      {/* Tiền Cọc */}
                      <td className="py-3 px-4 font-semibold text-slate-900">
                        {new Intl.NumberFormat('vi-VN').format(Number(card.deposit_fee || 0))} đ
                      </td>

                      {/* Trạng Thái */}
                      <td className="py-3 px-4 text-center">
                        {getStatusBadge(card.status)}
                      </td>

                      {/* Thao Tác */}
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          {/* Toggle Active / Lock Button */}
                          <button
                            onClick={() => handleToggleStatus(card)}
                            disabled={isCooldownActive || actionLoading || card.status === 'REVOKED'}
                            className={`p-1.5 rounded-lg transition ${
                              card.status === 'ACTIVE'
                                ? 'text-slate-600 hover:text-amber-600 hover:bg-amber-50'
                                : 'text-slate-600 hover:text-emerald-600 hover:bg-emerald-50'
                            } ${isCooldownActive ? 'opacity-40 cursor-not-allowed' : ''}`}
                            title={card.status === 'ACTIVE' ? 'Tạm khóa thẻ tức thì' : 'Mở kích hoạt thẻ'}
                          >
                            {card.status === 'ACTIVE' ? (
                              <Lock className="w-4 h-4" />
                            ) : (
                              <Unlock className="w-4 h-4" />
                            )}
                          </button>

                          {/* Detail Button */}
                          <button
                            onClick={() => handleOpenDetail(card)}
                            className="p-1.5 text-slate-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition"
                            title="Xem chi tiết & lịch sử quẹt"
                          >
                            <Eye className="w-4 h-4" />
                          </button>

                          {/* Edit Button with cooldown badge */}
                          <button
                            onClick={() => handleOpenEdit(card)}
                            className={`p-1.5 rounded-lg transition flex items-center gap-1 ${
                              isCooldownActive
                                ? 'text-amber-700 bg-amber-50 hover:bg-amber-100'
                                : 'text-slate-600 hover:text-emerald-600 hover:bg-emerald-50'
                            }`}
                            title={
                              isCooldownActive
                                ? `Đang tạm khóa chỉnh sửa (${remainingSeconds}s) - Bấm để xem thông tin`
                                : 'Chỉnh sửa thông tin thẻ'
                            }
                          >
                            <Edit className="w-4 h-4" />
                            {isCooldownActive && (
                              <span className="text-[10px] font-mono font-bold text-amber-700 px-1 py-0.2 bg-amber-100/90 rounded border border-amber-300/60">
                                {remainingSeconds}s
                              </span>
                            )}
                          </button>

                          {/* Delete Button */}
                          <button
                            onClick={() => handleOpenDelete(card)}
                            disabled={isCooldownActive}
                            className={`p-1.5 rounded-lg transition ${
                              isCooldownActive
                                ? 'text-slate-300 opacity-40 cursor-not-allowed'
                                : 'text-slate-600 hover:text-rose-600 hover:bg-rose-50'
                            }`}
                            title="Xóa / Thu hồi thẻ"
                          >
                            <Trash2 className="w-4 h-4" />
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

        {/* Phân trang */}
        <div className="flex items-center justify-between px-4 py-3 bg-slate-50 border-t border-slate-200 text-xs text-slate-500">
          <div>
            Hiển thị trang <span className="font-bold text-slate-700">{pagination.currentPage}</span> / {pagination.lastPage} (Tổng{' '}
            <span className="font-bold text-slate-700">{pagination.total}</span> thẻ)
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={() => loadCards(pagination.currentPage - 1)}
              disabled={pagination.currentPage <= 1 || loading}
              className="p-1.5 border border-slate-200 rounded-lg bg-white disabled:opacity-40 hover:bg-slate-100 transition"
              title="Trang trước"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <button
              onClick={() => loadCards(pagination.currentPage + 1)}
              disabled={pagination.currentPage >= pagination.lastPage || loading}
              className="p-1.5 border border-slate-200 rounded-lg bg-white disabled:opacity-40 hover:bg-slate-100 transition"
              title="Trang tiếp"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Modal: Create Card */}
      {isCreateModalOpen && (
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-xl w-full shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-slate-200 bg-slate-50/80 shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center">
                  <CreditCard className="w-4 h-4" />
                </div>
                <h3 className="font-extrabold text-slate-900 text-base">Cấp Mã Thẻ RFID Mới</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsCreateModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateSubmit} className="p-5 sm:p-6 space-y-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {/* UID */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Mã Chip RFID (UID) <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="VD: RFID-ELEV-9901"
                    value={formData.card_uid}
                    onChange={e => setFormData({ ...formData, card_uid: e.target.value.toUpperCase() })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 uppercase"
                  />
                  {formErrors.card_uid && <p className="text-xs text-rose-500 mt-1">{formErrors.card_uid}</p>}
                </div>

                {/* Card Number */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Số In Trên Mặt Thẻ <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="VD: CARD-9901"
                    value={formData.card_number}
                    onChange={e => setFormData({ ...formData, card_number: e.target.value.toUpperCase() })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 uppercase"
                  />
                  {formErrors.card_number && <p className="text-xs text-rose-500 mt-1">{formErrors.card_number}</p>}
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {/* Card Type */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Loại Thẻ
                  </label>
                  <select
                    value={formData.card_type}
                    onChange={e => setFormData({ ...formData, card_type: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 font-medium"
                  >
                    <option value="RESIDENT_ALL_ACCESS">Thẻ Toàn Quyền (Thang máy + Gửi xe)</option>
                    <option value="ELEVATOR_ONLY">Thẻ Thang Máy Tòa Nhà</option>
                    <option value="PARKING_ONLY">Thẻ Bãi Gửi Xe</option>
                    <option value="VISITOR_PASS">Thẻ Khách Thăm Tạm Thời</option>
                  </select>
                </div>

                {/* Initial Status */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Trạng Thái Ban Đầu
                  </label>
                  <select
                    value={formData.status}
                    onChange={e => setFormData({ ...formData, status: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 font-medium"
                  >
                    <option value="ACTIVE">ACTIVE (Kích hoạt ngay)</option>
                    <option value="LOCKED_TEMPORARY">LOCKED (Tạm khóa)</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {/* Apartment */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Gán Căn Hộ
                  </label>
                  <select
                    value={formData.assigned_apartment_id}
                    onChange={e => setFormData({ ...formData, assigned_apartment_id: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  >
                    <option value="">-- Chưa gán căn hộ --</option>
                    {apartments.map(apt => (
                      <option key={apt.id} value={apt.id}>
                        Căn hộ {apt.apartment_number}
                      </option>
                    ))}
                  </select>
                </div>

                {/* Resident */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Gán Cư Dân
                  </label>
                  <select
                    value={formData.assigned_user_id}
                    onChange={e => setFormData({ ...formData, assigned_user_id: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  >
                    <option value="">-- Chưa gán cư dân --</option>
                    {filteredFormResidents.map(res => (
                      <option key={res.user_id} value={res.user_id}>
                        {res.full_name} {res.phone_number ? `(${res.phone_number})` : ''}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {/* Issued Date */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Ngày Cấp
                  </label>
                  <input
                    type="date"
                    value={formData.issued_date}
                    onChange={e => setFormData({ ...formData, issued_date: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  />
                </div>

                {/* Expiry Date */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Hạn Sử Dụng
                  </label>
                  <input
                    type="date"
                    value={formData.expiry_date}
                    onChange={e => setFormData({ ...formData, expiry_date: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  />
                </div>

                {/* Deposit Fee */}
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Tiền Cọc (VND)
                  </label>
                  <input
                    type="number"
                    step="1000"
                    value={formData.deposit_fee}
                    onChange={e => setFormData({ ...formData, deposit_fee: Number(e.target.value) })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  />
                </div>
              </div>

              <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-2.5">
                <button
                  type="button"
                  onClick={() => setIsCreateModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Hủy Bỏ
                </button>
                <button
                  type="submit"
                  disabled={actionLoading || isCooldownActive}
                  className="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition shadow-sm flex items-center gap-1.5 disabled:opacity-50"
                >
                  {actionLoading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
                  <span>Xác Nhận Cấp Thẻ</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Edit Card */}
      {isEditModalOpen && selectedCard && (
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-xl w-full shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-slate-200 bg-slate-50/80 shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center">
                  <Edit className="w-4 h-4" />
                </div>
                <h3 className="font-extrabold text-slate-900 text-base">Cập Nhật Thẻ RFID: {selectedCard.card_number}</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsEditModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleEditSubmit} className="p-5 sm:p-6 space-y-4">
              {/* Cooldown Alert: Chỉ hiển thị khi đang chỉnh sửa */}
              {isCooldownActive && (
                <div className="p-3.5 bg-gradient-to-r from-amber-500/15 via-orange-500/10 to-amber-500/15 border border-amber-300 rounded-xl flex items-center justify-between gap-3 text-amber-900 animate-fade-in shadow-sm">
                  <div className="flex items-center gap-2.5">
                    <Clock className="w-5 h-5 text-amber-600 animate-spin shrink-0" />
                    <div>
                      <p className="text-xs sm:text-sm font-bold text-amber-950">
                        Chức năng đang tạm khóa chỉnh sửa
                      </p>
                      <p className="text-xs text-amber-800">
                        Hệ thống tạm khóa thay đổi trong 1 phút. Nút lưu sẽ mở lại sau:
                      </p>
                    </div>
                  </div>
                  <div className="px-3 py-1 bg-amber-500 text-white rounded-lg font-mono font-bold text-sm shrink-0 shadow-sm flex items-center gap-1.5">
                    <Clock className="w-3.5 h-3.5" />
                    <span>{remainingSeconds}s</span>
                  </div>
                </div>
              )}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Mã UID</label>
                  <input
                    type="text"
                    required
                    value={formData.card_uid}
                    onChange={e => setFormData({ ...formData, card_uid: e.target.value.toUpperCase() })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 uppercase"
                  />
                  {formErrors.card_uid && <p className="text-xs text-rose-500 mt-1">{formErrors.card_uid}</p>}
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Số Thẻ</label>
                  <input
                    type="text"
                    required
                    value={formData.card_number}
                    onChange={e => setFormData({ ...formData, card_number: e.target.value.toUpperCase() })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 uppercase"
                  />
                  {formErrors.card_number && <p className="text-xs text-rose-500 mt-1">{formErrors.card_number}</p>}
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Loại Thẻ</label>
                  <select
                    value={formData.card_type}
                    onChange={e => setFormData({ ...formData, card_type: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 font-medium"
                  >
                    <option value="RESIDENT_ALL_ACCESS">Thẻ Toàn Quyền</option>
                    <option value="ELEVATOR_ONLY">Thẻ Thang Máy</option>
                    <option value="PARKING_ONLY">Thẻ Gửi Xe</option>
                    <option value="VISITOR_PASS">Thẻ Khách Thăm</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Trạng Thái</label>
                  <select
                    value={formData.status}
                    onChange={e => setFormData({ ...formData, status: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 font-medium"
                  >
                    <option value="ACTIVE">ACTIVE (Hoạt động)</option>
                    <option value="LOCKED_TEMPORARY">LOCKED (Tạm khóa)</option>
                    <option value="LOST_REPORTED">BÁO MẤT</option>
                    <option value="REVOKED">THU HỒI</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Căn Hộ</label>
                  <select
                    value={formData.assigned_apartment_id}
                    onChange={e => setFormData({ ...formData, assigned_apartment_id: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  >
                    <option value="">-- Chưa gán căn hộ --</option>
                    {apartments.map(apt => (
                      <option key={apt.id} value={apt.id}>Căn hộ {apt.apartment_number}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Cư Dân</label>
                  <select
                    value={formData.assigned_user_id}
                    onChange={e => setFormData({ ...formData, assigned_user_id: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  >
                    <option value="">-- Chưa gán cư dân --</option>
                    {filteredFormResidents.map(res => (
                      <option key={res.user_id} value={res.user_id}>
                        {res.full_name} {res.phone_number ? `(${res.phone_number})` : ''}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Hạn Sử Dụng</label>
                  <input
                    type="date"
                    value={formData.expiry_date}
                    onChange={e => setFormData({ ...formData, expiry_date: e.target.value })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Tiền Cọc (VND)</label>
                  <input
                    type="number"
                    step="1000"
                    value={formData.deposit_fee}
                    onChange={e => setFormData({ ...formData, deposit_fee: Number(e.target.value) })}
                    className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs focus:ring-2 focus:ring-indigo-500 bg-slate-50/50"
                  />
                </div>
              </div>

              <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-2.5">
                <button
                  type="button"
                  onClick={() => setIsEditModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Hủy Bỏ
                </button>
                <button
                  type="submit"
                  disabled={actionLoading || isCooldownActive}
                  className={`px-4 py-2 text-xs font-bold text-white rounded-lg transition shadow-sm flex items-center gap-1.5 ${
                    isCooldownActive
                      ? 'bg-amber-600/80 cursor-not-allowed opacity-80'
                      : 'bg-indigo-600 hover:bg-indigo-700'
                  }`}
                >
                  {actionLoading ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : isCooldownActive ? (
                    <Clock className="w-4 h-4 animate-spin" />
                  ) : (
                    <Edit className="w-4 h-4" />
                  )}
                  <span>{isCooldownActive ? `Đang khóa (${remainingSeconds}s)` : 'Lưu Thay Đổi'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal: View Detail & Recent Access Logs */}
      {isDetailModalOpen && selectedCard && (
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-xl w-full shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-slate-200 bg-slate-50/80 shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center">
                  <FileText className="w-4 h-4" />
                </div>
                <h3 className="font-extrabold text-slate-900 text-base">Chi Tiết Thẻ & Lịch Sử Quẹt</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-5 sm:p-6 space-y-5">
              {detailLoading ? (
                <div className="py-8 text-center text-slate-400">
                  <Loader2 className="w-6 h-6 animate-spin mx-auto mb-2 text-indigo-600" />
                  <span>Đang tải thông tin...</span>
                </div>
              ) : (
                <>
                  <div className="p-4 bg-slate-50/80 rounded-xl border border-slate-200 space-y-2.5 text-xs">
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Số in trên thẻ:</span>
                      <strong className="text-slate-900 font-mono text-sm">{selectedCard.card_number}</strong>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Mã chip RFID (UID):</span>
                      <strong className="text-slate-900 font-mono">{selectedCard.card_uid}</strong>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Loại thẻ:</span>
                      <span>{getCardTypeBadge(selectedCard.card_type)}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Trạng thái:</span>
                      <span>{getStatusBadge(selectedCard.status)}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Căn hộ:</span>
                      <span className="font-semibold text-slate-900">{selectedCard.assigned_apartment?.apartment_number || 'Chưa gán'}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Cư dân sở hữu:</span>
                      <span className="font-semibold text-slate-900">{selectedCard.assigned_user?.full_name || 'Chưa gán'}</span>
                    </div>
                  </div>

                  <div>
                    <h4 className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 font-mono">
                      Nhật Ký Quẹt Thẻ Gần Nhất
                    </h4>
                    {cardDetail?.recent_logs && cardDetail.recent_logs.length > 0 ? (
                      <div className="space-y-1.5 text-xs max-h-48 overflow-y-auto">
                        {cardDetail.recent_logs.map(log => (
                          <div key={log.id} className="p-2.5 rounded-lg bg-slate-50 border border-slate-200 flex justify-between items-center">
                            <span className="font-medium text-slate-700">Hướng: {log.access_direction}</span>
                            <span className="text-slate-500 font-mono text-[11px]">{log.log_timestamp}</span>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <p className="text-xs text-slate-400 italic">Chưa có bản ghi quẹt thẻ nào.</p>
                    )}
                  </div>
                </>
              )}

              <div className="pt-3 border-t border-slate-100 text-right">
                <button
                  type="button"
                  onClick={() => setIsDetailModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Đóng
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Modal: Delete Confirmation */}
      {isDeleteModalOpen && selectedCard && (
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="p-6 text-center space-y-4">
              <div className="w-14 h-14 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mx-auto border-4 border-rose-100">
                <Trash2 className="w-7 h-7" />
              </div>
              <div>
                <h3 className="text-lg font-black text-slate-900">Xác Nhận Xóa / Thu Hồi Thẻ?</h3>
                <p className="text-xs sm:text-sm text-slate-500 mt-1">
                  Bạn có chắc chắn muốn xóa hoặc thu hồi thẻ <strong>{selectedCard.card_number}</strong> (UID: {selectedCard.card_uid}) không?
                </p>
                <p className="text-xs text-amber-700 bg-amber-50 p-2.5 rounded-lg border border-amber-200 mt-3 text-left">
                  💡 <strong>Lưu ý:</strong> Nếu thẻ này đã từng quẹt ra vào trong quá khứ, hệ thống sẽ tự động chuyển trạng thái sang <strong>THU HỒI (REVOKED)</strong> để bảo toàn tính toàn vẹn dữ liệu.
                </p>
              </div>

              <div className="flex items-center justify-center gap-2.5 pt-2">
                <button
                  type="button"
                  onClick={() => setIsDeleteModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Hủy Bỏ
                </button>
                <button
                  type="button"
                  onClick={handleDeleteConfirm}
                  disabled={actionLoading || isCooldownActive}
                  className="px-4 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 rounded-lg transition shadow-sm disabled:opacity-50 flex items-center gap-1.5"
                >
                  {actionLoading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Trash2 className="w-4 h-4" />}
                  <span>Đồng Ý Xóa</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default RfidManagement;
