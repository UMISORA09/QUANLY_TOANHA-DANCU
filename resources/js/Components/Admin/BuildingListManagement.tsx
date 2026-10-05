import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
  Building2,
  Building,
  Home,
  CheckCircle2,
  AlertTriangle,
  Wrench,
  Search,
  Filter,
  ShieldCheck,
  Zap,
  Droplet,
  Layers,
  ArrowUpRight,
  Users,
  ChevronRight,
  Eye,
  SlidersHorizontal,
  Plus,
  Sparkles,
  Trash2,
  Edit,
  RefreshCw,
  X,
  Check,
  ChevronLeft,
  KeyRound,
  FileSpreadsheet,
} from 'lucide-react';
import buildingStructureApi, {
  BlockItem,
  FloorItem,
  ApartmentItem,
  BatchGeneratePayload,
} from '../../Services/buildingStructureApi';

export const BuildingListManagement: React.FC = () => {
  // --- Data State ---
  const [blocks, setBlocks] = useState<BlockItem[]>([]);
  const [floors, setFloors] = useState<FloorItem[]>([]);
  const [apartments, setApartments] = useState<ApartmentItem[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isActionLoading, setIsActionLoading] = useState<boolean>(false);
  const [feedbackMessage, setFeedbackMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // --- Filtering & Pagination State ---
  const [selectedBlockId, setSelectedBlockId] = useState<string>('all');
  const [selectedFloorId, setSelectedFloorId] = useState<string>('all');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);
  const [totalApartmentsCount, setTotalApartmentsCount] = useState<number>(0);
  const perPage = 15;

  // --- Modal Visibility States ---
  const [showBatchModal, setShowBatchModal] = useState<boolean>(false);
  const [showApartmentModal, setShowApartmentModal] = useState<boolean>(false);
  const [showFloorModal, setShowFloorModal] = useState<boolean>(false);
  const [showDeleteModal, setShowDeleteModal] = useState<boolean>(false);

  // --- Editing / Selected Item State ---
  const [selectedApartment, setSelectedApartment] = useState<ApartmentItem | null>(null);
  const [apartmentToDelete, setApartmentToDelete] = useState<ApartmentItem | null>(null);

  // --- Batch Generate Form State ---
  const [batchBlockId, setBatchBlockId] = useState<string>('');
  const [batchFloorId, setBatchFloorId] = useState<string>('');
  const [batchCount, setBatchCount] = useState<number>(8);
  const [batchPrefix, setBatchPrefix] = useState<string>('');
  const [batchStartNum, setBatchStartNum] = useState<number>(1);
  const [batchRoomType, setBatchRoomType] = useState<string>('2_BEDROOM');
  const [batchGrossArea, setBatchGrossArea] = useState<number>(75.0);
  const [batchNetArea, setBatchNetArea] = useState<number>(68.5);
  const [batchStatus, setBatchStatus] = useState<'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE'>('VACANT');
  const [batchFurnished, setBatchFurnished] = useState<'RAW' | 'BASIC' | 'FULL'>('BASIC');
  const [batchFloorsList, setBatchFloorsList] = useState<FloorItem[]>([]);

  // --- Single Apartment Form State ---
  const [formAptBlockId, setFormAptBlockId] = useState<string>('');
  const [formAptFloorId, setFormAptFloorId] = useState<string>('');
  const [formAptNumber, setFormAptNumber] = useState<string>('');
  const [formAptRoomType, setFormAptRoomType] = useState<string>('2_BEDROOM');
  const [formAptGrossArea, setFormAptGrossArea] = useState<number>(75.0);
  const [formAptNetArea, setFormAptNetArea] = useState<number>(68.5);
  const [formAptBedrooms, setFormAptBedrooms] = useState<number>(2);
  const [formAptBathrooms, setFormAptBathrooms] = useState<number>(2);
  const [formAptStatus, setFormAptStatus] = useState<'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE'>('VACANT');
  const [formAptFurnished, setFormAptFurnished] = useState<'RAW' | 'BASIC' | 'FULL'>('BASIC');
  const [formAptFee, setFormAptFee] = useState<number>(800000);
  const [formAptFloorsList, setFormAptFloorsList] = useState<FloorItem[]>([]);

  // --- Add Floor Form State ---
  const [formFloorBlockId, setFormFloorBlockId] = useState<string>('');
  const [formFloorNumber, setFormFloorNumber] = useState<number>(1);
  const [formFloorCode, setFormFloorCode] = useState<string>('');
  const [formFloorName, setFormFloorName] = useState<string>('');
  const [formFloorType, setFormFloorType] = useState<string>('RESIDENTIAL');

  // Helper auto dismiss feedback
  const showNotification = (type: 'success' | 'error', text: string) => {
    setFeedbackMessage({ type, text });
    setTimeout(() => {
      setFeedbackMessage(null);
    }, 4500);
  };

  // --- 1. Fetch Blocks Overview ---
  const fetchBlocks = useCallback(async () => {
    try {
      const data = await buildingStructureApi.getBlocks();
      setBlocks(data);
      if (data.length > 0 && !batchBlockId) {
        setBatchBlockId(data[0].id);
        setFormAptBlockId(data[0].id);
        setFormFloorBlockId(data[0].id);
      }
    } catch (err: any) {
      console.error('Lỗi tải danh sách tòa nhà:', err);
    }
  }, [batchBlockId]);

  // --- 2. Fetch Floors for Filter ---
  const fetchFilterFloors = useCallback(async () => {
    if (selectedBlockId === 'all') {
      setFloors([]);
      setSelectedFloorId('all');
      return;
    }
    try {
      const data = await buildingStructureApi.getFloors(selectedBlockId);
      setFloors(data);
    } catch (err: any) {
      console.error('Lỗi tải tầng tòa nhà:', err);
    }
  }, [selectedBlockId]);

  // --- 3. Fetch Apartments List ---
  const fetchApartments = useCallback(async () => {
    setIsLoading(true);
    try {
      const res = await buildingStructureApi.getApartments({
        block_id: selectedBlockId,
        floor_id: selectedFloorId,
        status: statusFilter,
        search: searchQuery.trim(),
        page: currentPage,
        per_page: perPage,
        sort_by: 'apartment_number',
        sort_order: 'asc',
      });
      setApartments(res.data);
      if (res.meta) {
        setTotalPages(res.meta.last_page || 1);
        setTotalApartmentsCount(res.meta.total || 0);
      }
    } catch (err: any) {
      console.error('Lỗi tải danh sách căn hộ:', err);
      showNotification('error', err.message || 'Không thể tải danh sách căn hộ');
    } finally {
      setIsLoading(false);
    }
  }, [selectedBlockId, selectedFloorId, statusFilter, searchQuery, currentPage]);

  // Initial load
  useEffect(() => {
    fetchBlocks();
  }, [fetchBlocks]);

  useEffect(() => {
    fetchFilterFloors();
  }, [fetchFilterFloors]);

  useEffect(() => {
    fetchApartments();
  }, [fetchApartments]);

  // Update floors when modal block selectors change
  useEffect(() => {
    if (batchBlockId) {
      buildingStructureApi.getFloors(batchBlockId).then((data) => {
        setBatchFloorsList(data);
        if (data.length > 0) {
          setBatchFloorId(data[0].id);
          const blk = blocks.find((b) => b.id === batchBlockId);
          const prefix = blk ? `${blk.block_code}-${data[0].floor_number.toString().padStart(2, '0')}` : `F${data[0].floor_number}-`;
          setBatchPrefix(prefix);
        }
      });
    }
  }, [batchBlockId, blocks]);

  useEffect(() => {
    if (formAptBlockId) {
      buildingStructureApi.getFloors(formAptBlockId).then((data) => {
        setFormAptFloorsList(data);
        if (data.length > 0 && !formAptFloorId) {
          setFormAptFloorId(data[0].id);
        }
      });
    }
  }, [formAptBlockId, formAptFloorId]);

  // Update batch prefix when batch floor changes
  const handleBatchFloorChange = (newFloorId: string) => {
    setBatchFloorId(newFloorId);
    const chosenFloor = batchFloorsList.find((f) => f.id === newFloorId);
    const blk = blocks.find((b) => b.id === batchBlockId);
    if (chosenFloor && blk) {
      const flrNum = chosenFloor.floor_number.toString().padStart(2, '0');
      setBatchPrefix(`${blk.block_code}-${flrNum}`);
    }
  };

  // --- Aggregate Stats Calculations ---
  const statsOverview = useMemo(() => {
    const totalAll = blocks.reduce((sum, b) => sum + (b.total_apartments || 0), 0);
    const occupiedAll = blocks.reduce((sum, b) => sum + (b.occupied_apartments || 0), 0);
    const rentedAll = blocks.reduce((sum, b) => sum + (b.rented_apartments || 0), 0);
    const vacantAll = blocks.reduce((sum, b) => sum + (b.vacant_apartments || 0), 0);
    const maintenanceAll = blocks.reduce((sum, b) => sum + (b.maintenance_apartments || 0), 0);
    const occupancyRate = totalAll > 0 ? (((occupiedAll + rentedAll) / totalAll) * 100).toFixed(1) : '0';

    return {
      total: totalAll,
      occupied: occupiedAll,
      rented: rentedAll,
      vacant: vacantAll,
      maintenance: maintenanceAll,
      occupancyRate,
    };
  }, [blocks]);

  // --- Actions ---

  // Fast Status Change
  const handleFastStatusChange = async (apt: ApartmentItem, newStatus: 'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE') => {
    if (apt.status === newStatus) return;
    try {
      await buildingStructureApi.updateApartmentStatus(apt.id, newStatus);
      showNotification('success', `Đã cập nhật trạng thái căn hộ ${apt.apartment_number} thành: ${getStatusLabel(newStatus)}`);
      // Update local item
      setApartments((prev) =>
        prev.map((item) => (item.id === apt.id ? { ...item, status: newStatus } : item))
      );
      // Refresh block stats
      fetchBlocks();
    } catch (err: any) {
      showNotification('error', err.message || 'Lỗi cập nhật trạng thái căn hộ');
    }
  };

  // Batch Generate Submit
  const handleBatchGenerate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!batchBlockId || !batchFloorId) {
      showNotification('error', 'Vui lòng chọn Tòa nhà và Tầng cần khởi tạo');
      return;
    }
    if (batchCount < 1 || batchCount > 100) {
      showNotification('error', 'Số lượng căn khởi tạo phải từ 1 đến 100');
      return;
    }

    setIsActionLoading(true);
    try {
      const payload: BatchGeneratePayload = {
        block_id: batchBlockId,
        floor_id: batchFloorId,
        count: Number(batchCount),
        prefix: batchPrefix.trim(),
        start_number: Number(batchStartNum),
        room_type: batchRoomType,
        gross_floor_area_sqm: Number(batchGrossArea),
        net_usable_area_sqm: Number(batchNetArea),
        bedroom_count: batchRoomType === '1_BEDROOM' ? 1 : batchRoomType === '3_BEDROOM' ? 3 : 2,
        bathroom_count: batchRoomType === '1_BEDROOM' ? 1 : 2,
        status: batchStatus,
        furnished_status: batchFurnished,
      };

      const result = await buildingStructureApi.batchGenerateApartments(payload);
      showNotification(
        'success',
        `Khởi tạo thành công ${result.created_count} căn hộ theo tầng! (${result.skipped_existing} căn đã tồn tại được bỏ qua).`
      );
      setShowBatchModal(false);
      fetchApartments();
      fetchBlocks();
    } catch (err: any) {
      showNotification('error', err.message || 'Không thể tạo danh sách căn hộ hàng loạt');
    } finally {
      setIsActionLoading(false);
    }
  };

  // Open Create Apartment Modal
  const handleOpenCreateModal = () => {
    setSelectedApartment(null);
    setFormAptNumber('');
    setFormAptRoomType('2_BEDROOM');
    setFormAptGrossArea(75.0);
    setFormAptNetArea(68.5);
    setFormAptBedrooms(2);
    setFormAptBathrooms(2);
    setFormAptStatus('VACANT');
    setFormAptFurnished('BASIC');
    setFormAptFee(800000);
    setShowApartmentModal(true);
  };

  // Open Edit Apartment Modal
  const handleOpenEditModal = (apt: ApartmentItem) => {
    setSelectedApartment(apt);
    setFormAptBlockId(apt.block_id);
    setFormAptFloorId(apt.floor_id);
    setFormAptNumber(apt.apartment_number);
    setFormAptRoomType(apt.room_type || '2_BEDROOM');
    setFormAptGrossArea(Number(apt.gross_floor_area_sqm) || 75.0);
    setFormAptNetArea(Number(apt.net_usable_area_sqm) || 68.5);
    setFormAptBedrooms(apt.bedroom_count || 2);
    setFormAptBathrooms(apt.bathroom_count || 2);
    setFormAptStatus(apt.status);
    setFormAptFurnished((apt.furnished_status as any) || 'BASIC');
    setFormAptFee(Number(apt.monthly_management_fee_fixed) || 800000);
    setShowApartmentModal(true);
  };

  // Save Apartment (Create / Update)
  const handleSaveApartment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formAptBlockId || !formAptFloorId || !formAptNumber.trim()) {
      showNotification('error', 'Vui lòng điền đầy đủ Tòa, Tầng và Mã căn hộ');
      return;
    }

    setIsActionLoading(true);
    try {
      const payload: Partial<ApartmentItem> = {
        block_id: formAptBlockId,
        floor_id: formAptFloorId,
        apartment_number: formAptNumber.trim(),
        room_type: formAptRoomType,
        gross_floor_area_sqm: Number(formAptGrossArea),
        net_usable_area_sqm: Number(formAptNetArea),
        bedroom_count: Number(formAptBedrooms),
        bathroom_count: Number(formAptBathrooms),
        status: formAptStatus,
        furnished_status: formAptFurnished,
        monthly_management_fee_fixed: Number(formAptFee),
      };

      if (selectedApartment) {
        await buildingStructureApi.updateApartment(selectedApartment.id, payload);
        showNotification('success', `Đã cập nhật thông tin căn hộ ${formAptNumber}`);
      } else {
        await buildingStructureApi.createApartment(payload);
        showNotification('success', `Thêm mới thành công căn hộ ${formAptNumber}`);
      }

      setShowApartmentModal(false);
      fetchApartments();
      fetchBlocks();
    } catch (err: any) {
      showNotification('error', err.message || 'Lỗi lưu thông tin căn hộ');
    } finally {
      setIsActionLoading(false);
    }
  };

  // Handle Delete Apartment
  const handleDeleteApartment = async () => {
    if (!apartmentToDelete) return;
    setIsActionLoading(true);
    try {
      await buildingStructureApi.deleteApartment(apartmentToDelete.id);
      showNotification('success', `Đã chuyển căn hộ ${apartmentToDelete.apartment_number} vào thùng rác`);
      setShowDeleteModal(false);
      setApartmentToDelete(null);
      fetchApartments();
      fetchBlocks();
    } catch (err: any) {
      showNotification('error', err.message || 'Lỗi xóa căn hộ');
    } finally {
      setIsActionLoading(false);
    }
  };

  // Handle Add Floor
  const handleCreateFloor = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formFloorBlockId) {
      showNotification('error', 'Vui lòng chọn Tòa nhà');
      return;
    }
    setIsActionLoading(true);
    try {
      await buildingStructureApi.createFloor(formFloorBlockId, {
        floor_number: Number(formFloorNumber),
        floor_code: formFloorCode.trim() || undefined,
        floor_name: formFloorName.trim() || `Tầng ${formFloorNumber}`,
        floor_type: formFloorType,
      });
      showNotification('success', `Đã thêm Tầng ${formFloorNumber} thành công`);
      setShowFloorModal(false);
      fetchBlocks();
      if (selectedBlockId === formFloorBlockId) {
        fetchFilterFloors();
      }
    } catch (err: any) {
      showNotification('error', err.message || 'Lỗi thêm tầng');
    } finally {
      setIsActionLoading(false);
    }
  };

  // Label Helpers
  const getStatusLabel = (status: string) => {
    switch (status) {
      case 'OCCUPIED':
        return 'Đã bán / Đang ở';
      case 'RENTED':
        return 'Đang thuê';
      case 'VACANT':
        return 'Trống';
      case 'MAINTENANCE':
        return 'Đang bảo trì';
      default:
        return status;
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'OCCUPIED':
        return (
          <span className="sass-db-status sass-db-status--occupied">
            <CheckCircle2 className="w-3.5 h-3.5" />
            Đã bán
          </span>
        );
      case 'RENTED':
        return (
          <span className="sass-db-status sass-db-status--rented">
            <KeyRound className="w-3.5 h-3.5" />
            Đang thuê
          </span>
        );
      case 'VACANT':
        return (
          <span className="sass-db-status sass-db-status--vacant">
            <Home className="w-3.5 h-3.5" />
            Trống
          </span>
        );
      case 'MAINTENANCE':
        return (
          <span className="sass-db-status sass-db-status--maintenance">
            <Wrench className="w-3.5 h-3.5" />
            Bảo trì
          </span>
        );
      default:
        return <span className="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-700">{status}</span>;
    }
  };

  const getRoomTypeLabel = (type: string) => {
    switch (type) {
      case 'STUDIO':
        return 'Studio';
      case '1_BEDROOM':
        return '1 Phòng ngủ';
      case '2_BEDROOM':
        return '2 Phòng ngủ';
      case '3_BEDROOM':
        return '3 Phòng ngủ (Góc)';
      case '4_BEDROOM':
        return '4 Phòng ngủ';
      case 'PENTHOUSE':
        return 'Penthouse Duplex';
      case 'DUPLEX':
        return 'Duplex';
      case 'COMMERCIAL':
        return 'Shophouse TM';
      default:
        return type || 'Tiêu chuẩn';
    }
  };

  return (
    <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 transition-all duration-300">
      {/* Toast Notification Alert */}
      {feedbackMessage && (
        <div
          className={`fixed top-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border text-sm font-medium transition-all transform animate-bounce ${
            feedbackMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 border-emerald-300'
              : 'bg-rose-50 text-rose-800 border-rose-300'
          }`}
        >
          {feedbackMessage.type === 'success' ? (
            <CheckCircle2 className="w-5 h-5 text-emerald-600" />
          ) : (
            <AlertTriangle className="w-5 h-5 text-rose-600" />
          )}
          <span>{feedbackMessage.text}</span>
          <button
            onClick={() => setFeedbackMessage(null)}
            className="ml-2 text-slate-400 hover:text-slate-600 cursor-pointer"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200/80">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-sky-600">
            <Building2 className="w-4 h-4 text-sky-500" />
            <span>HỆ THỐNG KHỐI TÒA NHÀ & CĂN HỘ</span>
            <span className="text-slate-300">·</span>
            <span className="text-slate-500">QUẢN LÝ QUAN HỆ 1-N (BLOCK &rarr; TẦNG &rarr; CĂN HỘ)</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Quản lý Tầng & Căn hộ
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Khởi tạo danh sách căn hộ theo tầng hàng loạt, gắn trạng thái Đã bán - Đang thuê - Trống, quản lý sơ đồ và vận hành trực quan.
          </p>
        </div>

        {/* Action Buttons in Header */}
        <div className="flex items-center gap-2.5 flex-wrap">
          <button
            type="button"
            onClick={() => setShowBatchModal(true)}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white shadow-sm transition-all cursor-pointer"
          >
            <Sparkles className="w-4 h-4" />
            <span>Khởi tạo căn hộ theo tầng</span>
          </button>

          <button
            type="button"
            onClick={handleOpenCreateModal}
            className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-white text-slate-700 hover:bg-slate-50 border border-slate-200 shadow-2xs transition-all cursor-pointer"
          >
            <Plus className="w-4 h-4 text-sky-600" />
            <span>Thêm căn hộ</span>
          </button>

          <button
            type="button"
            onClick={() => setShowFloorModal(true)}
            className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-white text-slate-700 hover:bg-slate-50 border border-slate-200 shadow-2xs transition-all cursor-pointer"
          >
            <Layers className="w-4 h-4 text-indigo-600" />
            <span>Thêm tầng</span>
          </button>

          <button
            type="button"
            onClick={() => {
              fetchBlocks();
              fetchApartments();
            }}
            title="Làm mới dữ liệu"
            className="p-2 rounded-xl text-slate-500 hover:text-slate-800 bg-white border border-slate-200 hover:bg-slate-50 shadow-2xs transition-all cursor-pointer"
          >
            <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin text-sky-600' : ''}`} />
          </button>
        </div>
      </div>

      {/* 5 Realtime Summary Stats Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5">
        {/* Card 1: Tổng quy mô */}
        <div className="sass-db-kpi-card">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Tổng quy mô căn hộ</span>
            <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
              <Building className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-neutral-900 font-mono">
            {statsOverview.total} <span className="text-xs font-normal text-slate-400">căn</span>
          </div>
          <div className="mt-1.5 text-[11px] text-slate-500">
            {blocks.length} Tòa tháp cao tầng
          </div>
        </div>

        {/* Card 2: Đã bán / Đang ở */}
        <div className="sass-db-kpi-card">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Đã bán / Cư trú</span>
            <div className="kpi-icon-wrapper bg-emerald-50 text-emerald-600">
              <CheckCircle2 className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-emerald-600 font-mono">
            {statsOverview.occupied} <span className="text-xs font-normal text-slate-400">căn</span>
          </div>
          <div className="mt-1.5 text-[11px] text-emerald-700 font-medium">
            Tỷ lệ lấp đầy: {statsOverview.occupancyRate}%
          </div>
        </div>

        {/* Card 3: Đang thuê */}
        <div className="sass-db-kpi-card">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Đang cho thuê</span>
            <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
              <KeyRound className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-sky-600 font-mono">
            {statsOverview.rented} <span className="text-xs font-normal text-slate-400">căn</span>
          </div>
          <div className="mt-1.5 text-[11px] text-sky-700 font-medium">
            Hợp đồng thuê đang hiệu lực
          </div>
        </div>

        {/* Card 4: Căn hộ trống */}
        <div className="sass-db-kpi-card">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Căn hộ trống</span>
            <div className="kpi-icon-wrapper bg-amber-50 text-amber-600">
              <Home className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-amber-600 font-mono">
            {statsOverview.vacant} <span className="text-xs font-normal text-slate-400">căn</span>
          </div>
          <div className="mt-1.5 text-[11px] text-amber-700 font-medium">
            Sẵn sàng mở bán hoặc bàn giao
          </div>
        </div>

        {/* Card 5: Đang bảo trì */}
        <div className="sass-db-kpi-card">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">Đang bảo trì / Sửa</span>
            <div className="kpi-icon-wrapper bg-purple-50 text-purple-600">
              <Wrench className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-purple-600 font-mono">
            {statsOverview.maintenance} <span className="text-xs font-normal text-slate-400">căn</span>
          </div>
          <div className="mt-1.5 text-[11px] text-purple-700 font-medium">
            Cấp phép thi công sửa chữa
          </div>
        </div>
      </div>

      {/* Buildings Overview Grid Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {blocks.map((block) => {
          const occupancy =
            block.total_apartments > 0
              ? (((block.occupied_apartments + block.rented_apartments) / block.total_apartments) * 100).toFixed(1)
              : '0';
          const isSelected = selectedBlockId === block.id;

          return (
            <div
              key={block.id}
              onClick={() => {
                if (selectedBlockId === block.id) {
                  setSelectedBlockId('all');
                  setSelectedFloorId('all');
                } else {
                  setSelectedBlockId(block.id);
                  setSelectedFloorId('all');
                }
                setCurrentPage(1);
              }}
              className={`sass-db-block-card flex flex-col justify-between ${
                isSelected ? 'is-active' : ''
              }`}
            >
              <div>
                <div className="flex items-center justify-between pb-2.5 border-b border-slate-100">
                  <div className="flex items-center gap-2.5">
                    <div className="block-badge-code">
                      {block.block_code}
                    </div>
                    <div>
                      <h3 className="font-bold text-slate-900 text-sm leading-tight">{block.block_name}</h3>
                      <p className="text-[11px] text-slate-500">
                        {block.total_floors} Tầng nổi · {block.total_basements} Tầng hầm
                      </p>
                    </div>
                  </div>
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 border border-emerald-200">
                    Vận hành
                  </span>
                </div>

                {/* Progress bar */}
                <div className="mt-3.5">
                  <div className="flex justify-between text-xs text-slate-600 font-medium mb-1">
                    <span>
                      Cư trú: <strong>{block.occupied_apartments + block.rented_apartments}</strong>/{block.total_apartments}
                    </span>
                    <span className="font-bold text-neutral-900">{occupancy}%</span>
                  </div>
                  <div className="occupancy-meter">
                    <div
                      className="occupancy-meter-fill"
                      style={{ width: `${occupancy}%` }}
                    />
                  </div>
                </div>

                {/* Status Badges Row */}
                <div className="mt-3 flex items-center justify-between text-[11px] text-slate-500 px-1 font-mono">
                  <span title="Đã bán / Cư trú" className="text-emerald-600 font-semibold">
                    Ở: {block.occupied_apartments}
                  </span>
                  <span title="Đang thuê" className="text-sky-600 font-semibold">
                    Thuê: {block.rented_apartments}
                  </span>
                  <span title="Trống" className="text-amber-600 font-semibold">
                    Trống: {block.vacant_apartments}
                  </span>
                  <span title="Bảo trì" className="text-purple-600 font-semibold">
                    Sửa: {block.maintenance_apartments}
                  </span>
                </div>
              </div>

              {/* Technical Indicator */}
              <div className="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <div className="flex items-center gap-1.5">
                  <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                  <span className="text-[11px]">Hạ tầng ổn định</span>
                </div>
                <span className="text-[11px] font-bold text-sky-600 flex items-center gap-0.5">
                  {isSelected ? 'Đang lọc' : 'Xem chi tiết'}
                  <ChevronRight className="w-3.5 h-3.5" />
                </span>
              </div>
            </div>
          );
        })}
      </div>

      {/* Main Apartment Table & Hierarchy Control Section */}
      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        {/* Hierarchy Filter Toolbar */}
        <div className="p-4 border-b border-slate-100 flex flex-col lg:flex-row gap-3 items-center justify-between bg-slate-50/60">
          <div className="flex flex-wrap items-center gap-2.5 w-full lg:w-auto">
            {/* Search Input */}
            <div className="relative w-full sm:w-64">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
                placeholder="Tìm mã căn (vd: S1.2501, A-02)..."
                className="w-full pl-9 pr-3 py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
              />
            </div>

            {/* Block Filter Dropdown */}
            <div className="flex items-center gap-1 text-xs">
              <span className="text-slate-400 font-medium">Khối:</span>
              <select
                value={selectedBlockId}
                onChange={(e) => {
                  setSelectedBlockId(e.target.value);
                  setSelectedFloorId('all');
                  setCurrentPage(1);
                }}
                className="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 text-slate-700 font-medium cursor-pointer"
              >
                <option value="all">Tất cả Khối ({blocks.length})</option>
                {blocks.map((b) => (
                  <option key={b.id} value={b.id}>
                    Khối {b.block_code} - {b.block_name}
                  </option>
                ))}
              </select>
            </div>

            {/* Floor Filter Dropdown (1-N Relationship) */}
            <div className="flex items-center gap-1 text-xs">
              <span className="text-slate-400 font-medium">Tầng:</span>
              <select
                value={selectedFloorId}
                onChange={(e) => {
                  setSelectedFloorId(e.target.value);
                  setCurrentPage(1);
                }}
                disabled={selectedBlockId === 'all'}
                className={`px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 text-slate-700 font-medium cursor-pointer ${
                  selectedBlockId === 'all' ? 'opacity-50 cursor-not-allowed' : ''
                }`}
              >
                <option value="all">Tất cả Tầng ({floors.length})</option>
                {floors.map((f) => (
                  <option key={f.id} value={f.id}>
                    {f.floor_name} (Số {f.floor_number})
                  </option>
                ))}
              </select>
            </div>

            {/* Status Filter Dropdown */}
            <div className="flex items-center gap-1 text-xs">
              <span className="text-slate-400 font-medium">Trạng thái:</span>
              <select
                value={statusFilter}
                onChange={(e) => {
                  setStatusFilter(e.target.value);
                  setCurrentPage(1);
                }}
                className="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 text-slate-700 font-medium cursor-pointer"
              >
                <option value="all">Mọi trạng thái</option>
                <option value="OCCUPIED">Đã bán / Cư trú</option>
                <option value="RENTED">Đang thuê</option>
                <option value="VACANT">Trống</option>
                <option value="MAINTENANCE">Đang bảo trì</option>
              </select>
            </div>
          </div>

          <div className="flex items-center gap-3 text-xs text-slate-500 font-medium self-end lg:self-auto">
            <span>
              Tổng số: <strong className="text-neutral-900">{totalApartmentsCount}</strong> căn hộ
            </span>
          </div>
        </div>

        {/* Table of Apartments */}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase tracking-wider font-semibold">
              <tr>
                <th className="py-3 px-4">Mã Căn hộ</th>
                <th className="py-3 px-4">Khối / Tầng</th>
                <th className="py-3 px-4">Loại Căn</th>
                <th className="py-3 px-4">Diện tích (m²)</th>
                <th className="py-3 px-4">Chủ hộ / Cư dân</th>
                <th className="py-3 px-4">Trạng thái căn hộ (1-Click đổi)</th>
                <th className="py-3 px-4 text-right">Phí QL Cố định</th>
                <th className="py-3 px-4 text-center">Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-slate-700">
              {isLoading ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <RefreshCw className="w-6 h-6 animate-spin text-sky-600" />
                      <span>Đang tải dữ liệu căn hộ từ hệ thống...</span>
                    </div>
                  </td>
                </tr>
              ) : apartments.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Home className="w-8 h-8 text-slate-300" />
                      <span>Không tìm thấy căn hộ nào phù hợp với bộ lọc hiện tại.</span>
                      <button
                        onClick={() => setShowBatchModal(true)}
                        className="mt-2 text-xs text-sky-600 font-bold hover:underline cursor-pointer"
                      >
                        + Khởi tạo ngay danh sách căn hộ cho tầng này
                      </button>
                    </div>
                  </td>
                </tr>
              ) : (
                apartments.map((apt) => (
                  <tr key={apt.id} className="hover:bg-slate-50/80 transition-colors">
                    {/* Apartment Number */}
                    <td className="py-3 px-4 font-bold text-neutral-900 font-mono text-sm">
                      {apt.apartment_number}
                    </td>

                    {/* Block / Floor */}
                    <td className="py-3 px-4">
                      <span className="font-semibold text-slate-800">
                        {apt.block?.block_code || '---'}
                      </span>
                      <span className="text-slate-400 mx-1">·</span>
                      <span className="text-slate-600">
                        {apt.floor?.floor_name || `Tầng ${apt.floor?.floor_number || '---'}`}
                      </span>
                    </td>

                    {/* Room Type */}
                    <td className="py-3 px-4">
                      <div className="font-medium text-slate-700">{getRoomTypeLabel(apt.room_type)}</div>
                      <div className="text-[11px] text-slate-400">
                        {apt.bedroom_count} PN · {apt.bathroom_count} WC
                      </div>
                    </td>

                    {/* Area */}
                    <td className="py-3 px-4 font-mono">
                      <div className="font-semibold text-slate-800">{Number(apt.gross_floor_area_sqm).toFixed(1)} m²</div>
                      {apt.net_usable_area_sqm && (
                        <div className="text-[10px] text-slate-400">
                          Thông thủy: {Number(apt.net_usable_area_sqm).toFixed(1)} m²
                        </div>
                      )}
                    </td>

                    {/* Owner / Resident */}
                    <td className="py-3 px-4">
                      {apt.primary_owner ? (
                        <div>
                          <div className="font-semibold text-slate-900">{apt.primary_owner.full_name}</div>
                          <div className="text-[11px] font-mono text-slate-500">
                            {apt.primary_owner.phone_number || '---'}
                          </div>
                        </div>
                      ) : (
                        <span className="text-[11px] text-slate-400 italic">Chưa đăng ký</span>
                      )}
                    </td>

                    {/* Fast Status Change Dropdown */}
                    <td className="py-3 px-4">
                      <div className="inline-block relative">
                        <select
                          value={apt.status}
                          onChange={(e) =>
                            handleFastStatusChange(apt, e.target.value as any)
                          }
                          className={`text-xs font-semibold px-2.5 py-1 rounded-lg border cursor-pointer focus:outline-none focus:ring-2 focus:ring-sky-500/20 ${
                            apt.status === 'OCCUPIED'
                              ? 'bg-emerald-50 text-emerald-800 border-emerald-300'
                              : apt.status === 'RENTED'
                              ? 'bg-sky-50 text-sky-800 border-sky-300'
                              : apt.status === 'VACANT'
                              ? 'bg-amber-50 text-amber-800 border-amber-300'
                              : 'bg-purple-50 text-purple-800 border-purple-300'
                          }`}
                        >
                          <option value="OCCUPIED">Đã bán / Cư trú</option>
                          <option value="RENTED">Đang thuê</option>
                          <option value="VACANT">Trống</option>
                          <option value="MAINTENANCE">Đang bảo trì</option>
                        </select>
                      </div>
                    </td>

                    {/* Management Fee */}
                    <td className="py-3 px-4 text-right font-mono font-medium text-slate-700">
                      {apt.monthly_management_fee_fixed
                        ? `${Number(apt.monthly_management_fee_fixed).toLocaleString('vi-VN')} đ`
                        : 'Mặc định'}
                    </td>

                    {/* Actions */}
                    <td className="py-3 px-4 text-center">
                      <div className="flex items-center justify-center gap-1.5">
                        <button
                          type="button"
                          onClick={() => handleOpenEditModal(apt)}
                          title="Chỉnh sửa căn hộ"
                          className="p-1.5 rounded-lg text-slate-400 hover:text-sky-600 hover:bg-sky-50 transition-colors cursor-pointer"
                        >
                          <Edit className="w-4 h-4" />
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            setApartmentToDelete(apt);
                            setShowDeleteModal(true);
                          }}
                          title="Xóa căn hộ"
                          className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer"
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
        <div className="p-3.5 border-t border-slate-100 flex items-center justify-between bg-slate-50/40 text-xs">
          <div className="text-slate-500">
            Trang <strong>{currentPage}</strong> / <strong>{totalPages}</strong> (Hiển thị tối đa {perPage} căn/trang)
          </div>

          <div className="flex items-center gap-1.5">
            <button
              type="button"
              disabled={currentPage <= 1 || isLoading}
              onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              className={`p-1.5 rounded-lg border flex items-center gap-1 font-semibold ${
                currentPage <= 1 || isLoading
                  ? 'bg-slate-100 text-slate-300 border-slate-200 cursor-not-allowed'
                  : 'bg-white text-slate-700 hover:bg-slate-50 border-slate-200 cursor-pointer'
              }`}
            >
              <ChevronLeft className="w-4 h-4" />
              <span>Trước</span>
            </button>

            <button
              type="button"
              disabled={currentPage >= totalPages || isLoading}
              onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
              className={`p-1.5 rounded-lg border flex items-center gap-1 font-semibold ${
                currentPage >= totalPages || isLoading
                  ? 'bg-slate-100 text-slate-300 border-slate-200 cursor-not-allowed'
                  : 'bg-white text-slate-700 hover:bg-slate-50 border-slate-200 cursor-pointer'
              }`}
            >
              <span>Sau</span>
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      {/* ============================================================== */}
      {/* MODAL 1: Khởi tạo danh sách căn hộ theo tầng (Batch Generate)   */}
      {/* ============================================================== */}
      {showBatchModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl border border-slate-200 relative max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center">
                  <Sparkles className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="font-bold text-slate-900 text-base">Khởi tạo căn hộ theo tầng hàng loạt</h3>
                  <p className="text-xs text-slate-500">Tự động sinh mã căn hộ và gán trạng thái hàng loạt cho tầng</p>
                </div>
              </div>
              <button
                onClick={() => setShowBatchModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer p-1 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleBatchGenerate} className="mt-4 space-y-4 text-xs">
              {/* Select Block & Floor */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Khối / Tòa tháp *</label>
                  <select
                    value={batchBlockId}
                    onChange={(e) => setBatchBlockId(e.target.value)}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 font-medium"
                  >
                    {blocks.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.block_code} - {b.block_name}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Tầng cần khởi tạo *</label>
                  <select
                    value={batchFloorId}
                    onChange={(e) => handleBatchFloorChange(e.target.value)}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 font-medium"
                  >
                    {batchFloorsList.map((f) => (
                      <option key={f.id} value={f.id}>
                        {f.floor_name} (Tầng {f.floor_number})
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Prefix & Numbering Config */}
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Tiền tố mã căn *</label>
                  <input
                    type="text"
                    value={batchPrefix}
                    onChange={(e) => setBatchPrefix(e.target.value)}
                    placeholder="vd: S1-05 hoặc A-12"
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono font-bold text-sky-700"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Số bắt đầu *</label>
                  <input
                    type="number"
                    min={1}
                    max={99}
                    value={batchStartNum}
                    onChange={(e) => setBatchStartNum(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Số lượng căn *</label>
                  <input
                    type="number"
                    min={1}
                    max={100}
                    value={batchCount}
                    onChange={(e) => setBatchCount(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono font-bold text-neutral-900"
                  />
                </div>
              </div>

              {/* Preview of Generated Codes */}
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="font-semibold text-slate-500 text-[11px]">Xem trước định dạng mã căn hộ sinh ra:</span>
                <div className="flex flex-wrap gap-1.5 mt-1.5 font-mono text-xs">
                  {Array.from({ length: Math.min(batchCount, 6) }).map((_, i) => (
                    <span key={i} className="sass-db-batch-preview-chip">
                      {batchPrefix}
                      {String(batchStartNum + i).padStart(2, '0')}
                    </span>
                  ))}
                  {batchCount > 6 && <span className="text-slate-400 self-center">... ({batchCount} căn)</span>}
                </div>
              </div>

              {/* Status & Room Type */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Trạng thái gán ban đầu *</label>
                  <select
                    value={batchStatus}
                    onChange={(e) => setBatchStatus(e.target.value as any)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-semibold text-slate-800"
                  >
                    <option value="VACANT">Trống (Sẵn sàng bán / cho thuê)</option>
                    <option value="OCCUPIED">Đã bán / Cư dân nhận nhà</option>
                    <option value="RENTED">Đang thuê</option>
                    <option value="MAINTENANCE">Đang thi công hoàn thiện</option>
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Loại căn hộ chuẩn</label>
                  <select
                    value={batchRoomType}
                    onChange={(e) => setBatchRoomType(e.target.value)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    <option value="STUDIO">Studio</option>
                    <option value="1_BEDROOM">1 Phòng ngủ</option>
                    <option value="2_BEDROOM">2 Phòng ngủ</option>
                    <option value="3_BEDROOM">3 Phòng ngủ (Góc)</option>
                    <option value="PENTHOUSE">Penthouse</option>
                    <option value="COMMERCIAL">Shophouse TMDV</option>
                  </select>
                </div>
              </div>

              {/* Area Config */}
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">DT Tim tường (m²) *</label>
                  <input
                    type="number"
                    step="0.1"
                    min="1"
                    value={batchGrossArea}
                    onChange={(e) => setBatchGrossArea(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">DT Thông thủy (m²)</label>
                  <input
                    type="number"
                    step="0.1"
                    min="1"
                    value={batchNetArea}
                    onChange={(e) => setBatchNetArea(Number(e.target.value))}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Tình trạng nội thất</label>
                  <select
                    value={batchFurnished}
                    onChange={(e) => setBatchFurnished(e.target.value as any)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none"
                  >
                    <option value="RAW">Bàn giao thô</option>
                    <option value="BASIC">Cơ bản CĐT</option>
                    <option value="FULL">Đầy đủ nội thất</option>
                  </select>
                </div>
              </div>

              {/* Modal Footer Actions */}
              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowBatchModal(false)}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  disabled={isActionLoading}
                  className="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold shadow-sm transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {isActionLoading && <RefreshCw className="w-4 h-4 animate-spin" />}
                  <span>Tiến hành tạo hàng loạt</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ============================================================== */}
      {/* MODAL 2: Thêm mới hoặc Chỉnh sửa Căn hộ (Single Apartment)     */}
      {/* ============================================================== */}
      {showApartmentModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 relative max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <h3 className="font-bold text-slate-900 text-base">
                {selectedApartment ? `Chỉnh sửa căn hộ: ${selectedApartment.apartment_number}` : 'Thêm mới căn hộ'}
              </h3>
              <button
                onClick={() => setShowApartmentModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer p-1 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveApartment} className="mt-4 space-y-4 text-xs">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Khối / Tòa tháp *</label>
                  <select
                    value={formAptBlockId}
                    onChange={(e) => setFormAptBlockId(e.target.value)}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    {blocks.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.block_code} - {b.block_name}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Tầng *</label>
                  <select
                    value={formAptFloorId}
                    onChange={(e) => setFormAptFloorId(e.target.value)}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    {formAptFloorsList.map((f) => (
                      <option key={f.id} value={f.id}>
                        {f.floor_name} (Tầng {f.floor_number})
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Mã căn hộ *</label>
                  <input
                    type="text"
                    value={formAptNumber}
                    onChange={(e) => setFormAptNumber(e.target.value)}
                    placeholder="vd: S1.2501 hoặc A-0402"
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono font-bold text-sky-700"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Trạng thái cư trú *</label>
                  <select
                    value={formAptStatus}
                    onChange={(e) => setFormAptStatus(e.target.value as any)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-semibold text-slate-800"
                  >
                    <option value="VACANT">Trống (Sẵn sàng giao)</option>
                    <option value="OCCUPIED">Đã bán / Cư dân đang ở</option>
                    <option value="RENTED">Đang thuê</option>
                    <option value="MAINTENANCE">Đang bảo trì / Sửa</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Loại căn</label>
                  <select
                    value={formAptRoomType}
                    onChange={(e) => setFormAptRoomType(e.target.value)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    <option value="STUDIO">Studio</option>
                    <option value="1_BEDROOM">1 Phòng ngủ</option>
                    <option value="2_BEDROOM">2 Phòng ngủ</option>
                    <option value="3_BEDROOM">3 Phòng ngủ (Góc)</option>
                    <option value="PENTHOUSE">Penthouse</option>
                    <option value="COMMERCIAL">Shophouse</option>
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Diện tích tim tường (m²) *</label>
                  <input
                    type="number"
                    step="0.1"
                    min="1"
                    value={formAptGrossArea}
                    onChange={(e) => setFormAptGrossArea(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Phòng ngủ</label>
                  <input
                    type="number"
                    min="0"
                    max="10"
                    value={formAptBedrooms}
                    onChange={(e) => setFormAptBedrooms(Number(e.target.value))}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Phòng tắm (WC)</label>
                  <input
                    type="number"
                    min="0"
                    max="10"
                    value={formAptBathrooms}
                    onChange={(e) => setFormAptBathrooms(Number(e.target.value))}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Phí QL Cố định (đ)</label>
                  <input
                    type="number"
                    step="10000"
                    value={formAptFee}
                    onChange={(e) => setFormAptFee(Number(e.target.value))}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowApartmentModal(false)}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  disabled={isActionLoading}
                  className="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold shadow-sm transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {isActionLoading && <RefreshCw className="w-4 h-4 animate-spin" />}
                  <span>{selectedApartment ? 'Lưu thay đổi' : 'Tạo căn hộ'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ============================================================== */}
      {/* MODAL 3: Thêm Tầng mới cho Tòa nhà                            */}
      {/* ============================================================== */}
      {showFloorModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 relative">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                  <Layers className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="font-bold text-slate-900 text-base">Thêm Tầng mới</h3>
                  <p className="text-xs text-slate-500">Mở rộng cấu trúc tầng cho khối tòa nhà</p>
                </div>
              </div>
              <button
                onClick={() => setShowFloorModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer p-1 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateFloor} className="mt-4 space-y-4 text-xs">
              <div>
                <label className="block font-semibold text-slate-700 mb-1">Khối / Tòa tháp *</label>
                <select
                  value={formFloorBlockId}
                  onChange={(e) => setFormFloorBlockId(e.target.value)}
                  required
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                >
                  {blocks.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.block_code} - {b.block_name}
                    </option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Số tầng (Number) *</label>
                  <input
                    type="number"
                    min={-5}
                    max={100}
                    value={formFloorNumber}
                    onChange={(e) => setFormFloorNumber(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Phân loại tầng</label>
                  <select
                    value={formFloorType}
                    onChange={(e) => setFormFloorType(e.target.value)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    <option value="RESIDENTIAL">Căn hộ ở</option>
                    <option value="COMMERCIAL">Thương mại dịch vụ</option>
                    <option value="PARKING">Bãi đỗ xe</option>
                    <option value="TECHNICAL">Kỹ thuật</option>
                    <option value="AMENITY">Tiện ích</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block font-semibold text-slate-700 mb-1">Tên hiển thị tầng</label>
                <input
                  type="text"
                  value={formFloorName}
                  onChange={(e) => setFormFloorName(e.target.value)}
                  placeholder={`Tầng ${formFloorNumber}`}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowFloorModal(false)}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  disabled={isActionLoading}
                  className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-sm transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {isActionLoading && <RefreshCw className="w-4 h-4 animate-spin" />}
                  <span>Thêm tầng</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ============================================================== */}
      {/* MODAL 4: Xác nhận xóa Căn hộ (Soft Delete)                     */}
      {/* ============================================================== */}
      {showDeleteModal && apartmentToDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl border border-slate-200 relative text-center">
            <div className="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center mx-auto mb-3">
              <Trash2 className="w-6 h-6" />
            </div>
            <h3 className="font-bold text-slate-900 text-base">Xác nhận xóa căn hộ</h3>
            <p className="text-xs text-slate-500 mt-1">
              Bạn có chắc chắn muốn xóa căn hộ{' '}
              <strong className="text-rose-600 font-mono">{apartmentToDelete.apartment_number}</strong>? Dữ liệu sẽ
              được chuyển vào trạng thái lưu trữ an toàn (Soft Delete).
            </p>

            <div className="flex items-center justify-center gap-2 mt-6">
              <button
                type="button"
                onClick={() => setShowDeleteModal(false)}
                className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold text-xs cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleDeleteApartment}
                disabled={isActionLoading}
                className="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-sm transition-all cursor-pointer flex items-center gap-1"
              >
                {isActionLoading && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                <span>Xác nhận xóa</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default BuildingListManagement;
