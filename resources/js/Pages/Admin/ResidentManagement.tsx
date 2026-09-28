import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
  Users,
  Home,
  UserPlus,
  Search,
  Filter,
  RefreshCw,
  Crown,
  Eye,
  Edit3,
  Trash2,
  CheckCircle2,
  AlertCircle,
  X,
  Phone,
  CreditCard,
  Calendar,
  Briefcase,
  ShieldCheck,
  ChevronLeft,
  ChevronRight,
  Loader2,
  ArrowUpDown,
  Building,
  HeartHandshake,
  UserCheck,
  UserX,
} from 'lucide-react';
import api, {
  ResidentItem,
  ResidentDetailResponse,
  ApartmentSummaryItem,
  UserRbac,
} from '../../Services/api';

interface ResidentManagementProps {
  embedded?: boolean;
}

export const ResidentManagement: React.FC<ResidentManagementProps> = ({ embedded = false }) => {
  // Data state
  const [residents, setResidents] = useState<ResidentItem[]>([]);
  const [apartments, setApartments] = useState<ApartmentSummaryItem[]>([]);
  const [total, setTotal] = useState<number>(0);
  const [page, setPage] = useState<number>(1);
  const [limit, setLimit] = useState<number>(15);
  const [totalPages, setTotalPages] = useState<number>(1);

  // Filters & Search
  const [search, setSearch] = useState<string>('');
  const [apartmentFilter, setApartmentFilter] = useState<string>('');
  const [typeFilter, setTypeFilter] = useState<string>('');
  const [headFilter, setHeadFilter] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [sortBy, setSortBy] = useState<string>('stay_start_date');
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');

  // Loading & Feedback
  const [loading, setLoading] = useState<boolean>(true);
  const [saving, setSaving] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'error' | 'info' } | null>(null);

  // Modals state
  const [detailModalOpen, setDetailModalOpen] = useState<boolean>(false);
  const [selectedDetail, setSelectedDetail] = useState<ResidentDetailResponse | null>(null);
  const [loadingDetail, setLoadingDetail] = useState<boolean>(false);

  const [formModalOpen, setFormModalOpen] = useState<boolean>(false);
  const [editingResident, setEditingResident] = useState<ResidentItem | null>(null);

  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState<boolean>(false);
  const [deletingResident, setDeletingResident] = useState<ResidentItem | null>(null);

  // Form Fields
  const [formApartmentId, setFormApartmentId] = useState<string>('');
  const [formUserId, setFormUserId] = useState<string>('');
  const [formResidentType, setFormResidentType] = useState<string>('FAMILY_MEMBER');
  const [formIsHead, setFormIsHead] = useState<boolean>(false);
  const [formRelation, setFormRelation] = useState<string>('SELF');
  const [formStayStart, setFormStayStart] = useState<string>('');
  const [formStayEnd, setFormStayEnd] = useState<string>('');
  const [formOccupation, setFormOccupation] = useState<string>('');
  const [formIsActive, setFormIsActive] = useState<boolean>(true);

  // User search for Form modal
  const [userSearchQuery, setUserSearchQuery] = useState<string>('');
  const [userSearchResults, setUserSearchResults] = useState<UserRbac[]>([]);
  const [searchingUsers, setSearchingUsers] = useState<boolean>(false);
  const [selectedUserObj, setSelectedUserObj] = useState<UserRbac | null>(null);

  const showToast = (text: string, type: 'success' | 'error' | 'info' = 'success') => {
    setToastMessage({ text, type });
    setTimeout(() => setToastMessage(null), 4000);
  };

  // Load Apartments Dropdown for filtering
  const fetchApartments = useCallback(async () => {
    try {
      const res = await api.getApartmentsForFilter();
      if (res && res.data) {
        setApartments(res.data);
      }
    } catch (err: any) {
      console.error('Lỗi tải danh sách căn hộ:', err);
    }
  }, []);

  // Load Residents List
  const fetchResidents = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.getResidents({
        search: search.trim() || undefined,
        apartment_id: apartmentFilter || undefined,
        resident_type: typeFilter || undefined,
        is_head_of_household: headFilter !== '' ? headFilter : undefined,
        is_active: statusFilter !== '' ? statusFilter : undefined,
        sort_by: sortBy,
        sort_order: sortOrder,
        page,
        limit,
      });

      if (res && res.data) {
        setResidents(res.data);
        setTotal(res.meta.total);
        setTotalPages(res.meta.last_page || 1);
      }
    } catch (err: any) {
      showToast(err.message || 'Không thể tải danh sách cư dân.', 'error');
    } finally {
      setLoading(false);
    }
  }, [search, apartmentFilter, typeFilter, headFilter, statusFilter, sortBy, sortOrder, page, limit]);

  useEffect(() => {
    fetchApartments();
  }, [fetchApartments]);

  useEffect(() => {
    fetchResidents();
  }, [fetchResidents]);

  // Quick stats calculation
  const stats = useMemo(() => {
    const totalCount = total;
    const headsCount = residents.filter(r => r.is_head_of_household).length;
    const activeCount = residents.filter(r => r.is_active).length;
    const ownersCount = residents.filter(r => r.resident_type === 'OWNER').length;
    const tenantsCount = residents.filter(r => r.resident_type === 'TENANT').length;
    const membersCount = residents.filter(r => r.resident_type === 'FAMILY_MEMBER').length;
    return { totalCount, headsCount, activeCount, ownersCount, tenantsCount, membersCount };
  }, [residents, total]);

  // Open Details Modal
  const handleOpenDetail = async (id: string) => {
    setDetailModalOpen(true);
    setLoadingDetail(true);
    try {
      const res = await api.getResident(id);
      if (res && res.data) {
        setSelectedDetail(res.data);
      }
    } catch (err: any) {
      showToast(err.message || 'Không thể tải thông tin chi tiết cư dân.', 'error');
      setDetailModalOpen(false);
    } finally {
      setLoadingDetail(false);
    }
  };

  // Open Add Modal
  const handleOpenAdd = () => {
    setEditingResident(null);
    setFormApartmentId(apartmentFilter || (apartments[0]?.id || ''));
    setFormUserId('');
    setSelectedUserObj(null);
    setUserSearchQuery('');
    setUserSearchResults([]);
    setFormResidentType('FAMILY_MEMBER');
    setFormIsHead(false);
    setFormRelation('CHILD');
    const todayStr = new Date().toISOString().split('T')[0];
    setFormStayStart(todayStr);
    setFormStayEnd('');
    setFormOccupation('');
    setFormIsActive(true);
    setFormModalOpen(true);
  };

  // Open Edit Modal
  const handleOpenEdit = (res: ResidentItem) => {
    setEditingResident(res);
    setFormApartmentId(res.apartment_id);
    setFormUserId(res.user_id);
    setSelectedUserObj(res.user ? (res.user as any) : null);
    setFormResidentType(res.resident_type);
    setFormIsHead(res.is_head_of_household);
    setFormRelation(res.relationship_to_head || 'SELF');
    setFormStayStart(res.stay_start_date ? res.stay_start_date.split('T')[0] : '');
    setFormStayEnd(res.stay_end_date ? res.stay_end_date.split('T')[0] : '');
    setFormOccupation(res.occupation || '');
    setFormIsActive(res.is_active);
    setFormModalOpen(true);
  };

  // Open Delete Confirm
  const handleOpenDelete = (res: ResidentItem) => {
    setDeletingResident(res);
    setDeleteConfirmOpen(true);
  };

  // Execute Soft Delete
  const handleConfirmDelete = async () => {
    if (!deletingResident) return;
    setSaving(true);
    try {
      await api.deleteResident(deletingResident.id);
      showToast('Đã xóa mềm cư dân khỏi căn hộ thành công.', 'success');
      setDeleteConfirmOpen(false);
      setDeletingResident(null);
      fetchResidents();
    } catch (err: any) {
      if (err.status === 409 || err.status === 404 || err.message?.includes('Admin khác xóa') || err.message?.includes('không còn tồn tại')) {
        showToast('Cư dân này đã được Admin khác xóa hoặc không còn tồn tại.', 'error');
      } else {
        showToast(err.message || 'Không thể xóa cư dân.', 'error');
      }
      setDeleteConfirmOpen(false);
      setDeletingResident(null);
      fetchResidents();
    } finally {
      setSaving(false);
    }
  };

  // Search users for form
  const handleSearchUsers = async (keyword: string) => {
    setUserSearchQuery(keyword);
    if (!keyword.trim()) {
      setUserSearchResults([]);
      return;
    }
    setSearchingUsers(true);
    try {
      const res = await api.getUsers({ search: keyword.trim(), limit: 8 });
      if (res && res.data) {
        setUserSearchResults(res.data);
      }
    } catch {
      setUserSearchResults([]);
    } finally {
      setSearchingUsers(false);
    }
  };

  // Save Form (Add or Edit)
  const handleSaveForm = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formApartmentId) {
      showToast('Vui lòng chọn căn hộ.', 'error');
      return;
    }
    if (!editingResident && !formUserId) {
      showToast('Vui lòng chọn người dùng / cư dân.', 'error');
      return;
    }
    if (!formStayStart) {
      showToast('Vui lòng nhập ngày bắt đầu cư trú.', 'error');
      return;
    }

    setSaving(true);
    try {
      if (editingResident) {
        await api.updateResident(editingResident.id, {
          resident_type: formResidentType,
          is_head_of_household: formIsHead,
          relationship_to_head: formIsHead ? 'SELF' : formRelation,
          stay_start_date: formStayStart,
          stay_end_date: formStayEnd || null,
          occupation: formOccupation || null,
          is_active: formIsActive,
          updated_at: editingResident.updated_at,
        });
        showToast('Cập nhật thông tin cư dân thành công.', 'success');
      } else {
        await api.createResident({
          apartment_id: formApartmentId,
          user_id: formUserId,
          resident_type: formResidentType,
          is_head_of_household: formIsHead,
          relationship_to_head: formIsHead ? 'SELF' : formRelation,
          stay_start_date: formStayStart,
          stay_end_date: formStayEnd || null,
          occupation: formOccupation || null,
          is_active: formIsActive,
        });
        showToast('Thêm cư dân vào căn hộ thành công.', 'success');
      }

      setFormModalOpen(false);
      fetchResidents();
    } catch (err: any) {
      if (err.status === 409 || err.message?.includes('Admin khác cập nhật') || err.message?.includes('không còn khả dụng') || err.message?.includes('xóa')) {
        showToast('Dữ liệu đã được Admin khác cập nhật. Vui lòng tải lại trước khi tiếp tục.', 'error');
        fetchResidents();
      } else {
        const msg = err.errors
          ? Object.values(err.errors).flat().join(', ')
          : (err.message || 'Lưu thông tin thất bại.');
        showToast(msg, 'error');
      }
    } finally {
      setSaving(false);
    }
  };

  // Map relationship label
  const getRelationBadge = (relation: string, isHead: boolean) => {
    if (isHead) {
      return (
        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-300 shadow-sm">
          <Crown className="w-3.5 h-3.5 text-amber-600 fill-amber-500" />
          Chủ Hộ
        </span>
      );
    }
    const map: Record<string, { label: string; cls: string }> = {
      SPOUSE: { label: 'Vợ / Chồng', cls: 'bg-rose-50 text-rose-700 border-rose-200' },
      CHILD: { label: 'Con', cls: 'bg-sky-50 text-sky-700 border-sky-200' },
      PARENT: { label: 'Bố / Mẹ', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
      TENANT: { label: 'Người thuê', cls: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
      MAID: { label: 'Giúp việc', cls: 'bg-neutral-100 text-neutral-700 border-neutral-200' },
      SELF: { label: 'Bản thân', cls: 'bg-slate-100 text-slate-700 border-slate-200' },
    };
    const item = map[relation] || { label: relation || 'Thành viên', cls: 'bg-slate-100 text-slate-700 border-slate-200' };
    return (
      <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border ${item.cls}`}>
        {item.label}
      </span>
    );
  };

  // Map resident type label
  const getResidentTypeBadge = (type: string) => {
    switch (type) {
      case 'OWNER':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-300">Chủ sở hữu</span>;
      case 'TENANT':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 border border-blue-300">Khách thuê</span>;
      case 'FAMILY_MEMBER':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-300">Thành viên hộ</span>;
      default:
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 border border-gray-300">{type}</span>;
    }
  };

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto'}`}>
      {/* Toast Notification */}
      {toastMessage && (
        <div className={`fixed top-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-xl border text-sm font-medium transition-all transform animate-in slide-in-from-top-4 ${
          toastMessage.type === 'success'
            ? 'bg-emerald-50 text-emerald-900 border-emerald-300'
            : toastMessage.type === 'error'
            ? 'bg-rose-50 text-rose-900 border-rose-300'
            : 'bg-sky-50 text-sky-900 border-sky-300'
        }`}>
          {toastMessage.type === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />}
          {toastMessage.type === 'error' && <AlertCircle className="w-5 h-5 text-rose-600 shrink-0" />}
          <span>{toastMessage.text}</span>
          <button onClick={() => setToastMessage(null)} className="ml-auto text-slate-400 hover:text-slate-600">
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Header & Subtitle */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-amber-700">
            <Users className="w-4 h-4 text-amber-600" />
            <span>QUẢN TRỊ TÒA NHÀ & CĂN HỘ</span>
            <span className="text-slate-300">·</span>
            <span className="bg-amber-100 text-amber-900 px-2 py-0.5 rounded text-[11px] font-bold border border-amber-300">
              NHÂN KHẨU & CHỦ HỘ
            </span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight mt-1">
            Quản Lý Chủ Hộ & Thông Tin Nhân Khẩu
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Theo dõi danh sách cư dân, xác lập chủ hộ và cơ cấu nhân khẩu gia đình theo từng căn hộ.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={fetchResidents}
            disabled={loading}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-sm font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 active:scale-95 transition-all shadow-sm"
            title="Tải lại dữ liệu"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin text-amber-600' : 'text-slate-500'}`} />
            <span className="hidden sm:inline">Làm mới</span>
          </button>

          <button
            onClick={handleOpenAdd}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 active:scale-95 transition-all shadow-md hover:shadow-lg shadow-amber-600/20"
          >
            <UserPlus className="w-4 h-4" />
            <span>Thêm Cư Dân Mới</span>
          </button>
        </div>
      </div>

      {/* KPI Stats Overview */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
        <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Tổng nhân khẩu</span>
            <Users className="w-4 h-4 text-amber-600" />
          </div>
          <p className="text-2xl font-black text-slate-900 mt-2">{stats.totalCount}</p>
          <p className="text-xs text-slate-400 mt-1">Hồ sơ đã đăng ký</p>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Chủ hộ</span>
            <Crown className="w-4 h-4 text-amber-500 fill-amber-400" />
          </div>
          <p className="text-2xl font-black text-amber-700 mt-2">{stats.headsCount}</p>
          <p className="text-xs text-slate-400 mt-1">Đại diện căn hộ</p>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Chủ sở hữu</span>
            <ShieldCheck className="w-4 h-4 text-emerald-600" />
          </div>
          <p className="text-2xl font-black text-emerald-700 mt-2">{stats.ownersCount}</p>
          <p className="text-xs text-slate-400 mt-1">Owner cư trú</p>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Khách thuê</span>
            <Home className="w-4 h-4 text-blue-600" />
          </div>
          <p className="text-2xl font-black text-blue-700 mt-2">{stats.tenantsCount}</p>
          <p className="text-xs text-slate-400 mt-1">Tenant lưu trú</p>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Thành viên hộ</span>
            <HeartHandshake className="w-4 h-4 text-purple-600" />
          </div>
          <p className="text-2xl font-black text-purple-700 mt-2">{stats.membersCount}</p>
          <p className="text-xs text-slate-400 mt-1">Cùng sinh hoạt</p>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">Đang cư trú</span>
            <UserCheck className="w-4 h-4 text-emerald-600" />
          </div>
          <p className="text-2xl font-black text-slate-900 mt-2">{stats.activeCount}</p>
          <p className="text-xs text-emerald-600 font-medium mt-1">Active status</p>
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          {/* Search Input */}
          <div className="relative lg:col-span-2">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              placeholder="Tìm theo họ tên, số điện thoại, CCCD, mã căn hộ..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              className="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all placeholder:text-slate-400"
            />
          </div>

          {/* Apartment Filter */}
          <div>
            <select
              value={apartmentFilter}
              onChange={(e) => {
                setApartmentFilter(e.target.value);
                setPage(1);
              }}
              className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all bg-white text-slate-700"
            >
              <option value="">-- Tất cả căn hộ --</option>
              {apartments.map((apt) => (
                <option key={apt.id} value={apt.id}>
                  Căn {apt.apartment_number} {apt.head_of_household?.user?.full_name ? `(${apt.head_of_household.user.full_name})` : ''}
                </option>
              ))}
            </select>
          </div>

          {/* Resident Type Filter */}
          <div>
            <select
              value={typeFilter}
              onChange={(e) => {
                setTypeFilter(e.target.value);
                setPage(1);
              }}
              className="w-full px-3 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all bg-white text-slate-700"
            >
              <option value="">-- Loại cư dân --</option>
              <option value="OWNER">Chủ sở hữu (OWNER)</option>
              <option value="TENANT">Người thuê (TENANT)</option>
              <option value="FAMILY_MEMBER">Thành viên (FAMILY_MEMBER)</option>
            </select>
          </div>

          {/* Head of Household & Status Filter */}
          <div className="flex gap-2">
            <select
              value={headFilter}
              onChange={(e) => {
                setHeadFilter(e.target.value);
                setPage(1);
              }}
              className="w-1/2 px-3 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all bg-white text-slate-700"
            >
              <option value="">-- Vai trò --</option>
              <option value="1">👑 Chủ hộ</option>
              <option value="0">Thành viên</option>
            </select>

            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              className="w-1/2 px-3 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all bg-white text-slate-700"
            >
              <option value="">-- Trạng thái --</option>
              <option value="1">Đang ở (Active)</option>
              <option value="0">Đã rời (Inactive)</option>
            </select>
          </div>
        </div>
      </div>

      {/* Residents Table */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-50/80 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                <th className="py-3.5 px-4">Căn hộ</th>
                <th className="py-3.5 px-4">Cư dân & Tài khoản</th>
                <th className="py-3.5 px-4">Liên hệ & Định danh</th>
                <th className="py-3.5 px-4">Loại cư trú</th>
                <th className="py-3.5 px-4">Quan hệ với chủ hộ</th>
                <th className="py-3.5 px-4">Ngày chuyển vào</th>
                <th className="py-3.5 px-4">Trạng thái</th>
                <th className="py-3.5 px-4 text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-sm">
              {loading ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-500">
                    <Loader2 className="w-8 h-8 animate-spin mx-auto text-amber-600 mb-2" />
                    Đang tải danh sách nhân khẩu...
                  </td>
                </tr>
              ) : residents.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-500">
                    <Users className="w-10 h-10 mx-auto text-slate-300 mb-2" />
                    <p className="font-semibold text-slate-700">Không tìm thấy cư dân nào</p>
                    <p className="text-xs text-slate-400 mt-1">Thử thay đổi bộ lọc hoặc thêm cư dân mới vào căn hộ.</p>
                  </td>
                </tr>
              ) : (
                residents.map((r) => (
                  <tr key={r.id} className="hover:bg-slate-50/70 transition-colors">
                    {/* Apartment */}
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      <div className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 text-amber-900 font-bold border border-amber-200">
                        <Building className="w-3.5 h-3.5 text-amber-600" />
                        <span>Căn {r.apartment?.apartment_number || 'N/A'}</span>
                      </div>
                    </td>

                    {/* Resident Info */}
                    <td className="py-3.5 px-4">
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-full bg-gradient-to-tr from-amber-500 to-amber-300 text-white font-bold flex items-center justify-center text-sm shadow-sm shrink-0">
                          {r.user?.full_name ? r.user.full_name.charAt(0).toUpperCase() : 'U'}
                        </div>
                        <div>
                          <div className="flex items-center gap-2">
                            <span className="font-bold text-slate-900 hover:text-amber-600 transition-colors cursor-pointer" onClick={() => handleOpenDetail(r.id)}>
                              {r.user?.full_name || 'Chưa cập nhật tên'}
                            </span>
                            {r.is_head_of_household && (
                              <span title="Chủ hộ gia đình">
                                <Crown className="w-4 h-4 text-amber-500 fill-amber-400" />
                              </span>
                            )}
                          </div>
                          <span className="text-xs text-slate-400 font-mono">@{r.user?.username || r.user_id.slice(0, 8)}</span>
                        </div>
                      </div>
                    </td>

                    {/* Contact & CCCD */}
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      <div className="space-y-0.5">
                        <div className="flex items-center gap-1.5 text-xs text-slate-700 font-mono">
                          <Phone className="w-3 h-3 text-slate-400" />
                          <span>{r.user?.phone_number || 'Chưa có SĐT'}</span>
                        </div>
                        {r.user?.national_id_number && (
                          <div className="flex items-center gap-1.5 text-xs text-slate-500 font-mono">
                            <CreditCard className="w-3 h-3 text-slate-400" />
                            <span>CCCD: {r.user.national_id_number}</span>
                          </div>
                        )}
                      </div>
                    </td>

                    {/* Resident Type */}
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      {getResidentTypeBadge(r.resident_type)}
                    </td>

                    {/* Relationship to Head */}
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      {getRelationBadge(r.relationship_to_head, r.is_head_of_household)}
                    </td>

                    {/* Stay Dates */}
                    <td className="py-3.5 px-4 whitespace-nowrap text-xs text-slate-600">
                      <div className="flex items-center gap-1">
                        <Calendar className="w-3 h-3 text-slate-400" />
                        <span>{r.stay_start_date ? r.stay_start_date.split('T')[0] : 'N/A'}</span>
                      </div>
                      {r.stay_end_date && (
                        <span className="text-[11px] text-slate-400 block mt-0.5">
                          Đến: {r.stay_end_date.split('T')[0]}
                        </span>
                      )}
                    </td>

                    {/* Active Status */}
                    <td className="py-3.5 px-4 whitespace-nowrap">
                      {r.is_active ? (
                        <span className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">
                          <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                          Đang cư trú
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md border border-slate-200">
                          <span className="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                          Đã chuyển đi
                        </span>
                      )}
                    </td>

                    {/* Actions */}
                    <td className="py-3.5 px-4 whitespace-nowrap text-right">
                      <div className="inline-flex items-center gap-1">
                        <button
                          onClick={() => handleOpenDetail(r.id)}
                          className="p-1.5 text-slate-500 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors"
                          title="Xem chi tiết & Hộ gia đình"
                        >
                          <Eye className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => handleOpenEdit(r)}
                          className="p-1.5 text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                          title="Chỉnh sửa thông tin"
                        >
                          <Edit3 className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => handleOpenDelete(r)}
                          className="p-1.5 text-slate-500 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors"
                          title="Xóa cư dân (Soft Delete)"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination Bar */}
        <div className="py-3 px-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-slate-500">
          <div>
            Hiển thị <span className="font-semibold text-slate-800">{residents.length}</span> trên tổng số <span className="font-semibold text-slate-800">{total}</span> cư dân
          </div>
          <div className="flex items-center gap-2">
            <button
              onClick={() => setPage(p => Math.max(1, p - 1))}
              disabled={page <= 1 || loading}
              className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-all"
            >
              <ChevronLeft className="w-4 h-4" />
              <span>Trước</span>
            </button>
            <span className="px-2 font-medium">
              Trang {page} / {totalPages}
            </span>
            <button
              onClick={() => setPage(p => Math.min(totalPages, p + 1))}
              disabled={page >= totalPages || loading}
              className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-all"
            >
              <span>Sau</span>
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Modal: View Details & Household Tree */}
      {detailModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl shadow-2xl border border-slate-100 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="p-6 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white z-10">
              <div>
                <h3 className="text-lg font-extrabold text-slate-900 flex items-center gap-2">
                  <span>Hồ Sơ Nhân Khẩu & Cơ Cấu Hộ Gia Đình</span>
                </h3>
                <p className="text-xs text-slate-400 mt-0.5">
                  Căn hộ {selectedDetail?.apartment?.apartment_number || '...'}
                </p>
              </div>
              <button
                onClick={() => setDetailModalOpen(false)}
                className="p-2 text-slate-400 hover:text-slate-600 rounded-full hover:bg-slate-100 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {loadingDetail || !selectedDetail ? (
              <div className="py-16 text-center text-slate-500">
                <Loader2 className="w-8 h-8 animate-spin mx-auto text-amber-600 mb-2" />
                Đang tải thông tin chi tiết...
              </div>
            ) : (
              <div className="p-6 space-y-6">
                {/* Main Resident Card */}
                <div className="flex items-start gap-4 p-4 rounded-2xl bg-gradient-to-br from-amber-50/60 to-orange-50/30 border border-amber-200/70">
                  <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-amber-600 to-amber-400 text-white font-black text-xl flex items-center justify-center shadow-md shrink-0">
                    {selectedDetail.resident.user?.full_name?.charAt(0).toUpperCase() || 'U'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h4 className="text-lg font-bold text-slate-900">{selectedDetail.resident.user?.full_name}</h4>
                      {selectedDetail.resident.is_head_of_household && (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-900 border border-amber-300">
                          <Crown className="w-3.5 h-3.5 text-amber-600 fill-amber-500" />
                          Chủ Hộ
                        </span>
                      )}
                      {getResidentTypeBadge(selectedDetail.resident.resident_type)}
                    </div>
                    <p className="text-xs text-slate-500 mt-1 font-mono">
                      CCCD: {selectedDetail.resident.user?.national_id_number || 'Chưa cung cấp'} · SĐT: {selectedDetail.resident.user?.phone_number || 'N/A'}
                    </p>
                    <div className="grid grid-cols-2 gap-2 mt-3 text-xs text-slate-600">
                      <div>
                        <span className="text-slate-400">Nghề nghiệp: </span>
                        <span className="font-medium text-slate-800">{selectedDetail.resident.occupation || 'Chưa cập nhật'}</span>
                      </div>
                      <div>
                        <span className="text-slate-400">Bắt đầu ở: </span>
                        <span className="font-medium text-slate-800">{selectedDetail.resident.stay_start_date?.split('T')[0]}</span>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Household Structure (Quan hệ Chủ hộ - Thành viên) */}
                <div>
                  <div className="flex items-center justify-between pb-2 border-b border-slate-100 mb-3">
                    <h5 className="text-sm font-bold text-slate-900 flex items-center gap-1.5">
                      <HeartHandshake className="w-4 h-4 text-amber-600" />
                      <span>Thành Viên Cùng Căn Hộ ({selectedDetail.total_members} người)</span>
                    </h5>
                    <span className="text-xs text-slate-400">Quan hệ gia đình</span>
                  </div>

                  <div className="space-y-2">
                    {selectedDetail.household_members.map((m) => (
                      <div
                        key={m.id}
                        className={`flex items-center justify-between p-3 rounded-xl border transition-all ${
                          m.id === selectedDetail.resident.id
                            ? 'bg-amber-50/50 border-amber-300 ring-1 ring-amber-300'
                            : 'bg-slate-50 border-slate-200'
                        }`}
                      >
                        <div className="flex items-center gap-3">
                          <div className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white shrink-0 ${
                            m.is_head_of_household ? 'bg-amber-500' : 'bg-slate-400'
                          }`}>
                            {m.user?.full_name?.charAt(0).toUpperCase() || 'M'}
                          </div>
                          <div>
                            <div className="flex items-center gap-2">
                              <span className="font-bold text-slate-800 text-sm">{m.user?.full_name}</span>
                              {m.id === selectedDetail.resident.id && (
                                <span className="text-[10px] px-1.5 py-0.5 rounded bg-amber-200 text-amber-900 font-extrabold">Đang xem</span>
                              )}
                            </div>
                            <span className="text-xs text-slate-400 font-mono">{m.user?.phone_number || 'N/A'}</span>
                          </div>
                        </div>

                        <div className="flex items-center gap-2">
                          {getRelationBadge(m.relationship_to_head, m.is_head_of_household)}
                          {getResidentTypeBadge(m.resident_type)}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            )}

            <div className="p-4 bg-slate-50 border-t border-slate-100 flex justify-end">
              <button
                onClick={() => setDetailModalOpen(false)}
                className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-100"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal: Add or Edit Resident */}
      {formModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl shadow-2xl border border-slate-100 w-full max-w-xl max-h-[90vh] overflow-y-auto">
            <div className="p-6 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white z-10">
              <div>
                <h3 className="text-lg font-extrabold text-slate-900">
                  {editingResident ? 'Cập Nhật Thông Tin Cư Dân' : 'Thêm Cư Dân Vào Căn Hộ'}
                </h3>
                <p className="text-xs text-slate-400 mt-0.5">
                  Thiết lập thông tin lưu trú, căn hộ và vai trò chủ hộ.
                </p>
              </div>
              <button
                onClick={() => setFormModalOpen(false)}
                className="p-2 text-slate-400 hover:text-slate-600 rounded-full hover:bg-slate-100 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveForm} className="p-6 space-y-4">
              {/* Select Apartment */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                  Căn Hộ <span className="text-rose-500">*</span>
                </label>
                <select
                  value={formApartmentId}
                  onChange={(e) => setFormApartmentId(e.target.value)}
                  disabled={!!editingResident}
                  className="w-full px-3.5 py-2.5 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white disabled:bg-slate-100 text-slate-800"
                  required
                >
                  <option value="">-- Chọn căn hộ --</option>
                  {apartments.map((apt) => (
                    <option key={apt.id} value={apt.id}>
                      Căn {apt.apartment_number} {apt.head_of_household?.user?.full_name ? `(Chủ hộ: ${apt.head_of_household.user.full_name})` : '(Chưa có chủ hộ)'}
                    </option>
                  ))}
                </select>
              </div>

              {/* Select or Search User (Only when creating) */}
              {!editingResident ? (
                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    Tài khoản Người dùng / Cư dân <span className="text-rose-500">*</span>
                  </label>
                  {selectedUserObj ? (
                    <div className="flex items-center justify-between p-3 rounded-xl border border-amber-300 bg-amber-50/50">
                      <div>
                        <p className="font-bold text-slate-900 text-sm">{selectedUserObj.full_name}</p>
                        <p className="text-xs text-slate-500 font-mono">@{selectedUserObj.username} · {selectedUserObj.phone_number}</p>
                      </div>
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedUserObj(null);
                          setFormUserId('');
                        }}
                        className="text-xs font-bold text-rose-600 hover:underline"
                      >
                        Đổi người khác
                      </button>
                    </div>
                  ) : (
                    <div className="space-y-2">
                      <div className="relative">
                        <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                          type="text"
                          placeholder="Tìm người dùng theo tên, số điện thoại hoặc email..."
                          value={userSearchQuery}
                          onChange={(e) => handleSearchUsers(e.target.value)}
                          className="w-full pl-9 pr-3.5 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                        />
                        {searchingUsers && <Loader2 className="w-4 h-4 text-amber-600 animate-spin absolute right-3 top-1/2 -translate-y-1/2" />}
                      </div>

                      {userSearchResults.length > 0 && (
                        <div className="border border-slate-200 rounded-xl divide-y divide-slate-100 max-h-48 overflow-y-auto bg-white shadow-lg">
                          {userSearchResults.map((u) => (
                            <div
                              key={u.id}
                              onClick={() => {
                                setSelectedUserObj(u);
                                setFormUserId(u.id);
                                setUserSearchResults([]);
                              }}
                              className="p-2.5 hover:bg-amber-50 cursor-pointer flex items-center justify-between text-xs"
                            >
                              <div>
                                <span className="font-bold text-slate-800">{u.full_name}</span>
                                <span className="text-slate-400 ml-2 font-mono">{u.phone_number}</span>
                              </div>
                              <span className="text-[11px] text-amber-700 bg-amber-100 px-2 py-0.5 rounded font-medium">Chọn</span>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              ) : (
                <div className="p-3 bg-slate-50 rounded-xl border border-slate-200">
                  <span className="text-xs font-semibold text-slate-400 uppercase">Cư dân:</span>
                  <p className="font-bold text-slate-900 text-sm">{editingResident.user?.full_name}</p>
                  <p className="text-xs text-slate-500 font-mono">@{editingResident.user?.username} · SĐT: {editingResident.user?.phone_number}</p>
                </div>
              )}

              {/* Resident Type & Head of Household Switch */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    Loại Cư Dân <span className="text-rose-500">*</span>
                  </label>
                  <select
                    value={formResidentType}
                    onChange={(e) => setFormResidentType(e.target.value)}
                    className="w-full px-3.5 py-2.5 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white"
                  >
                    <option value="OWNER">Chủ sở hữu (OWNER)</option>
                    <option value="TENANT">Người thuê (TENANT)</option>
                    <option value="FAMILY_MEMBER">Thành viên hộ (FAMILY_MEMBER)</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    Quan hệ với Chủ Hộ
                  </label>
                  <select
                    value={formIsHead ? 'SELF' : formRelation}
                    onChange={(e) => setFormRelation(e.target.value)}
                    disabled={formIsHead}
                    className="w-full px-3.5 py-2.5 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white disabled:bg-slate-100 text-slate-800"
                  >
                    <option value="SELF">Bản thân (Chủ hộ)</option>
                    <option value="SPOUSE">Vợ / Chồng (SPOUSE)</option>
                    <option value="CHILD">Con cái (CHILD)</option>
                    <option value="PARENT">Bố / Mẹ (PARENT)</option>
                    <option value="TENANT">Khách thuê (TENANT)</option>
                    <option value="MAID">Người giúp việc (MAID)</option>
                  </select>
                </div>
              </div>

              {/* Is Head of Household Checkbox Card */}
              <div className="p-3.5 rounded-2xl border border-amber-200 bg-amber-50/40 flex items-start gap-3">
                <input
                  type="checkbox"
                  id="is_head_cb"
                  checked={formIsHead}
                  onChange={(e) => {
                    setFormIsHead(e.target.checked);
                    if (e.target.checked) {
                      setFormRelation('SELF');
                    }
                  }}
                  className="w-4 h-4 mt-0.5 rounded border-slate-300 text-amber-600 focus:ring-amber-500 cursor-pointer"
                />
                <label htmlFor="is_head_cb" className="cursor-pointer text-xs">
                  <span className="font-bold text-slate-900 block">Xác định là Chủ Hộ (Head of Household)</span>
                  <span className="text-slate-500">Mỗi căn hộ chỉ được có tối đa 1 chủ hộ đang hoạt động. Nếu chọn, quan hệ sẽ tự động là Bản thân (SELF).</span>
                </label>
              </div>

              {/* Stay Dates */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    Ngày bắt đầu cư trú <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="date"
                    value={formStayStart}
                    onChange={(e) => setFormStayStart(e.target.value)}
                    required
                    className="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    Ngày kết thúc cư trú (Nếu có)
                  </label>
                  <input
                    type="date"
                    value={formStayEnd}
                    onChange={(e) => setFormStayEnd(e.target.value)}
                    className="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                  />
                </div>
              </div>

              {/* Occupation & Active Status */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    Nghề nghiệp
                  </label>
                  <input
                    type="text"
                    placeholder="Ví dụ: Kỹ sư phần mềm, Bác sĩ..."
                    value={formOccupation}
                    onChange={(e) => setFormOccupation(e.target.value)}
                    className="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    Trạng thái hoạt động
                  </label>
                  <select
                    value={formIsActive ? '1' : '0'}
                    onChange={(e) => setFormIsActive(e.target.value === '1')}
                    className="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-300 focus:outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white"
                  >
                    <option value="1">Đang cư trú (Active)</option>
                    <option value="0">Đã rời đi (Inactive)</option>
                  </select>
                </div>
              </div>

              {/* Form Actions */}
              <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setFormModalOpen(false)}
                  className="px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition-colors"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 active:scale-95 rounded-xl shadow-md transition-all disabled:opacity-50"
                >
                  {saving && <Loader2 className="w-4 h-4 animate-spin" />}
                  <span>{editingResident ? 'Lưu Thay Đổi' : 'Xác Nhận Thêm'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Delete Confirmation Dialog */}
      {deleteConfirmOpen && deletingResident && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl shadow-2xl border border-slate-100 w-full max-w-md p-6 space-y-4">
            <div className="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mx-auto">
              <Trash2 className="w-6 h-6" />
            </div>
            <div className="text-center">
              <h3 className="text-lg font-extrabold text-slate-900">Xác nhận xóa mềm cư dân?</h3>
              <p className="text-xs text-slate-500 mt-1">
                Cư dân <span className="font-bold text-slate-800">{deletingResident.user?.full_name}</span> khỏi căn hộ{' '}
                <span className="font-bold text-slate-800">{deletingResident.apartment?.apartment_number}</span> sẽ được chuyển sang trạng thái đã xóa mềm (Soft Delete) và ngừng hoạt động.
              </p>
            </div>
            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={() => setDeleteConfirmOpen(false)}
                className="px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition-colors"
              >
                Hủy
              </button>
              <button
                type="button"
                onClick={handleConfirmDelete}
                disabled={saving}
                className="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold text-white bg-rose-600 hover:bg-rose-700 active:scale-95 rounded-xl shadow-md transition-all disabled:opacity-50"
              >
                {saving && <Loader2 className="w-4 h-4 animate-spin" />}
                <span>Xóa mềm</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ResidentManagement;
