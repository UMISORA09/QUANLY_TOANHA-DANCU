import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { createPortal } from 'react-dom';
import {
  Car,
  Bike,
  Plus,
  Search,
  Filter,
  RefreshCw,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Eye,
  Edit3,
  Trash2,
  Receipt,
  Calendar,
  DollarSign,
  Check,
  X,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Building2,
  User,
  Zap,
  ShieldCheck,
  Clock,
  ArrowUpDown,
  Tag,
  CreditCard,
  FileText
} from 'lucide-react';
import vehicleApi, {
  VehicleItem,
  VehicleApartmentOption,
  VehicleResidentOption,
  VehiclePricingConfig,
  VehicleDetailResponse
} from '../../Services/vehicleApi';

interface VehicleManagementProps {
  embedded?: boolean;
  portalMode?: 'manager' | 'receptionist' | 'security' | 'admin';
  initialFilterApproval?: string;
}

export const VehicleManagement: React.FC<VehicleManagementProps> = ({
  embedded = false,
  portalMode = 'manager',
  initialFilterApproval = '',
}) => {
  // State danh sách & phân trang
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);
  const [totalCount, setTotalCount] = useState<number>(0);
  const [perPage, setPerPage] = useState<number>(15);

  // State bộ lọc & tìm kiếm
  const [search, setSearch] = useState<string>('');
  const [filterCategory, setFilterCategory] = useState<string>('');
  const [filterApartmentId, setFilterApartmentId] = useState<string>('');
  const [filterStatus, setFilterStatus] = useState<string>('');
  const [filterApproval, setFilterApproval] = useState<string>(initialFilterApproval);
  const [apartments, setApartments] = useState<VehicleApartmentOption[]>([]);
  const [pricingConfigs, setPricingConfigs] = useState<Record<string, VehiclePricingConfig>>({});

  // State thông báo & feedback
  const [alert, setAlert] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // State Modals
  const [isCreateModalOpen, setIsCreateModalOpen] = useState<boolean>(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState<boolean>(false);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState<boolean>(false);
  const [vehicleToDelete, setVehicleToDelete] = useState<VehicleItem | null>(null);
  const [deletingVehicle, setDeletingVehicle] = useState<boolean>(false);
  const [selectedVehicle, setSelectedVehicle] = useState<VehicleItem | null>(null);
  const [detailData, setDetailData] = useState<VehicleDetailResponse | null>(null);
  const [loadingDetail, setLoadingDetail] = useState<boolean>(false);
  const [syncingInvoice, setSyncingInvoice] = useState<boolean>(false);

  // Form State cho Create & Edit
  const [formApartmentId, setFormApartmentId] = useState<string>('');
  const [formOwnerUserId, setFormOwnerUserId] = useState<string>('');
  const [formResidents, setFormResidents] = useState<VehicleResidentOption[]>([]);
  const [loadingResidents, setLoadingResidents] = useState<boolean>(false);
  const [formCategory, setFormCategory] = useState<string>('MOTORBIKE');
  const [formLicensePlate, setFormLicensePlate] = useState<string>('');
  const [formBrand, setFormBrand] = useState<string>('');
  const [formModel, setFormModel] = useState<string>('');
  const [formColor, setFormColor] = useState<string>('');
  const [formCertNumber, setFormCertNumber] = useState<string>('');
  const [formFee, setFormFee] = useState<number>(120000);
  const [formEvCharging, setFormEvCharging] = useState<boolean>(false);
  const [formSubmitting, setFormSubmitting] = useState<boolean>(false);

  // 1. Load danh sách phương tiện
  const fetchVehicles = useCallback(async () => {
    setLoading(true);
    try {
      const res = await vehicleApi.getVehicles({
        page: currentPage,
        per_page: perPage,
        search: search.trim() || undefined,
        vehicle_category: filterCategory || undefined,
        apartment_id: filterApartmentId || undefined,
        is_active: filterStatus !== '' ? filterStatus : undefined,
        approval_status: filterApproval || undefined,
      });

      if (res.success) {
        setVehicles(res.data);
        setTotalPages(res.meta.last_page);
        setTotalCount(res.meta.total);
      }
    } catch (err: any) {
      setAlert({ type: 'error', message: err.message || 'Lỗi khi tải danh sách phương tiện.' });
    } finally {
      setLoading(false);
    }
  }, [currentPage, perPage, search, filterCategory, filterApartmentId, filterStatus, filterApproval]);

  // 2. Load metadata (Căn hộ & Biểu phí)
  useEffect(() => {
    const fetchMetadata = async () => {
      try {
        const [aptRes, pricingRes] = await Promise.all([
          vehicleApi.getApartments(),
          vehicleApi.getPricingConfigs(),
        ]);
        if (aptRes.success) setApartments(aptRes.data);
        if (pricingRes.success) setPricingConfigs(pricingRes.data as Record<string, VehiclePricingConfig>);
      } catch {
        // ignore fallback
      }
    };
    fetchMetadata();
  }, []);

  useEffect(() => {
    fetchVehicles();
  }, [fetchVehicles]);

  // 3. Khi đổi căn hộ trong form -> tải danh sách cư dân thuộc căn hộ đó
  useEffect(() => {
    if (!formApartmentId) {
      setFormResidents([]);
      return;
    }
    const loadResidents = async () => {
      setLoadingResidents(true);
      try {
        const res = await vehicleApi.getApartmentResidents(formApartmentId);
        if (res.success) {
          setFormResidents(res.data);
          if (res.data.length > 0 && !formOwnerUserId) {
            setFormOwnerUserId(res.data[0].user_id);
          }
        }
      } catch {
        setFormResidents([]);
      } finally {
        setLoadingResidents(false);
      }
    };
    loadResidents();
  }, [formApartmentId]);

  // 4. Khi đổi loại xe trong form -> tự động cập nhật mức phí theo bảng giá
  useEffect(() => {
    if (formCategory === 'CAR') {
      const carPrice = pricingConfigs['CAR']?.monthly_parking_fee ?? 1500000;
      setFormFee(carPrice);
    } else {
      const motoPrice = pricingConfigs['MOTORBIKE']?.monthly_parking_fee ?? 120000;
      setFormFee(motoPrice);
    }
  }, [formCategory, pricingConfigs]);

  // KPIs thống kê nhanh
  const stats = useMemo(() => {
    const motorbikes = vehicles.filter((v) => v.vehicle_category === 'MOTORBIKE').length;
    const cars = vehicles.filter((v) => v.vehicle_category === 'CAR').length;
    const activeVehicles = vehicles.filter((v) => v.is_active).length;
    const estimatedMonthlyRevenue = vehicles
      .filter((v) => v.is_active)
      .reduce((sum, v) => sum + Number(v.monthly_parking_fee || 0), 0);

    return { motorbikes, cars, activeVehicles, estimatedMonthlyRevenue };
  }, [vehicles]);

  // Reset form
  const resetForm = () => {
    setFormApartmentId(apartments[0]?.id || '');
    setFormOwnerUserId('');
    setFormCategory('MOTORBIKE');
    setFormLicensePlate('');
    setFormBrand('');
    setFormModel('');
    setFormColor('');
    setFormCertNumber('');
    setFormFee(120000);
    setFormEvCharging(false);
  };

  // Mở modal tạo mới
  const handleOpenCreateModal = () => {
    resetForm();
    setIsCreateModalOpen(true);
  };

  // Mở modal sửa
  const handleOpenEditModal = (vehicle: VehicleItem) => {
    setSelectedVehicle(vehicle);
    setFormApartmentId(vehicle.apartment_id);
    setFormOwnerUserId(vehicle.owner_user_id);
    setFormCategory(vehicle.vehicle_category);
    setFormLicensePlate(vehicle.license_plate);
    setFormBrand(vehicle.brand || '');
    setFormModel(vehicle.model || '');
    setFormColor(vehicle.color || '');
    setFormCertNumber(vehicle.registration_certificate_number || '');
    setFormFee(Number(vehicle.monthly_parking_fee));
    setFormEvCharging(Boolean(vehicle.has_electric_charging_subscription));
    setIsEditModalOpen(true);
  };

  // Mở modal chi tiết
  const handleOpenDetailModal = async (vehicle: VehicleItem) => {
    setSelectedVehicle(vehicle);
    setIsDetailModalOpen(true);
    setLoadingDetail(true);
    try {
      const res = await vehicleApi.getVehicleById(vehicle.id);
      if (res.success) {
        setDetailData(res.data);
      }
    } catch (err: any) {
      setAlert({ type: 'error', message: err.message || 'Không thể tải chi tiết phương tiện.' });
    } finally {
      setLoadingDetail(false);
    }
  };

  // Submit tạo phương tiện
  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formApartmentId || !formOwnerUserId || !formLicensePlate.trim()) {
      setAlert({ type: 'error', message: 'Vui lòng điền đầy đủ các thông tin bắt buộc (*)' });
      return;
    }

    setFormSubmitting(true);
    try {
      const res = await vehicleApi.createVehicle({
        apartment_id: formApartmentId,
        owner_user_id: formOwnerUserId,
        license_plate: formLicensePlate.trim().toUpperCase(),
        vehicle_category: formCategory,
        brand: formBrand.trim() || undefined,
        model: formModel.trim() || undefined,
        color: formColor.trim() || undefined,
        registration_certificate_number: formCertNumber.trim() || undefined,
        monthly_parking_fee: Number(formFee),
        has_electric_charging_subscription: formEvCharging,
      });

      if (res.success) {
        setAlert({
          type: 'success',
          message: `Đăng ký phương tiện ${res.data.license_plate} thành công và đã tự động ghi nhận vào hóa đơn căn hộ.`,
        });
        setIsCreateModalOpen(false);
        fetchVehicles();
      }
    } catch (err: any) {
      setAlert({ type: 'error', message: err.message || 'Lỗi khi đăng ký phương tiện.' });
    } finally {
      setFormSubmitting(false);
    }
  };

  // Submit cập nhật phương tiện
  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedVehicle) return;

    setFormSubmitting(true);
    try {
      const res = await vehicleApi.updateVehicle(selectedVehicle.id, {
        apartment_id: formApartmentId,
        owner_user_id: formOwnerUserId,
        license_plate: formLicensePlate.trim().toUpperCase(),
        vehicle_category: formCategory,
        brand: formBrand.trim() || undefined,
        model: formModel.trim() || undefined,
        color: formColor.trim() || undefined,
        registration_certificate_number: formCertNumber.trim() || undefined,
        monthly_parking_fee: Number(formFee),
        has_electric_charging_subscription: formEvCharging,
      });

      if (res.success) {
        setAlert({ type: 'success', message: `Cập nhật phương tiện ${res.data.license_plate} thành công.` });
        setIsEditModalOpen(false);
        fetchVehicles();
      }
    } catch (err: any) {
      setAlert({ type: 'error', message: err.message || 'Lỗi khi cập nhật phương tiện.' });
    } finally {
      setFormSubmitting(false);
    }
  };

  // Toggle kích hoạt / tạm dừng
  const handleToggleActive = async (vehicle: VehicleItem) => {
    try {
      const res = await vehicleApi.toggleActive(vehicle.id);
      if (res.success) {
        setAlert({
          type: 'success',
          message: res.message,
        });
        fetchVehicles();
      }
    } catch (err: any) {
      setAlert({ type: 'error', message: err.message });
    }
  };

  // Duyệt phương tiện
  const handleApprove = async (vehicle: VehicleItem) => {
    try {
      const res = await vehicleApi.approveVehicle(vehicle.id);
      if (res.success) {
        setAlert({
          type: 'success',
          message: `Đã phê duyệt phương tiện ${vehicle.license_plate} và đẩy phí vào kỳ hóa đơn.`,
        });
        fetchVehicles();
      }
    } catch (err: any) {
      setAlert({ type: 'error', message: err.message });
    }
  };

  // Mở modal xác nhận xóa phương tiện
  const handleOpenDeleteModal = (vehicle: VehicleItem) => {
    setVehicleToDelete(vehicle);
    setIsDeleteModalOpen(true);
  };

  // Xác nhận xóa mềm phương tiện
  const handleConfirmDelete = async () => {
    if (!vehicleToDelete) return;

    setDeletingVehicle(true);
    try {
      const res = await vehicleApi.deleteVehicle(vehicleToDelete.id);
      if (res.success) {
        setAlert({
          type: 'success',
          message: `Đã ngừng theo dõi và xóa phương tiện [${vehicleToDelete.license_plate}]. Lịch sử hóa đơn phát sinh vẫn được bảo toàn.`,
        });
        setIsDeleteModalOpen(false);
        setVehicleToDelete(null);
        fetchVehicles();
      }
    } catch (err: any) {
      setAlert({ type: 'error', message: err.message || 'Lỗi khi xóa phương tiện.' });
    } finally {
      setDeletingVehicle(false);
    }
  };

  // Trigger đồng bộ phí vào hóa đơn từ Modal chi tiết
  const handleSyncInvoice = async (vehicleId: string) => {
    setSyncingInvoice(true);
    try {
      const res = await vehicleApi.syncInvoice(vehicleId);
      if (res.success) {
        setAlert({ type: 'success', message: res.message });
        // Tải lại chi tiết lịch sử hóa đơn
        const detailRes = await vehicleApi.getVehicleById(vehicleId);
        if (detailRes.success) setDetailData(detailRes.data);
      }
    } catch (err: any) {
      setAlert({ type: 'error', message: err.message });
    } finally {
      setSyncingInvoice(false);
    }
  };

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* 1. Header & Title */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-emerald-600">
            <Car className="w-4 h-4 text-emerald-600" />
            <span>
              {portalMode === 'receptionist' || portalMode === 'security'
                ? 'CỔNG LỄ TÂN & AN NINH · BÃI ĐỖ THÔNG MINH'
                : 'PHÂN HỆ VẬN HÀNH & BÃI ĐỖ THÔNG MINH'}
            </span>
            <span className="text-slate-300">·</span>
            <span className="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded text-[11px] font-semibold border border-emerald-200">
              {portalMode === 'receptionist' || portalMode === 'security'
                ? 'TỰ ĐỘNG ĐẨY PHÍ HÓA ĐƠN'
                : 'MODULE #5: VEHICLES & INVOICE'}
            </span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Đăng Ký Phương Tiện & Tự Động Đẩy Phí Hóa Đơn
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Quản lý xe máy, ô tô của cư dân; tự động áp biểu phí bãi đỗ vào hóa đơn căn hộ hàng tháng với cơ chế chống ghi trùng.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={fetchVehicles}
            className="flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition shadow-sm"
            title="Làm mới dữ liệu"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span>Tải lại</span>
          </button>

          <button
            onClick={handleOpenCreateModal}
            className="flex items-center gap-2 px-4 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition shadow-sm hover:shadow"
          >
            <Plus className="w-4 h-4" />
            <span>Đăng ký xe mới</span>
          </button>
        </div>
      </div>

      {/* 2. Alert Feedback */}
      {alert && (
        <div
          className={`flex items-center justify-between p-4 rounded-xl border text-sm transition-all ${
            alert.type === 'success'
              ? 'bg-emerald-50/90 border-emerald-200 text-emerald-900'
              : 'bg-rose-50/90 border-rose-200 text-rose-900'
          }`}
        >
          <div className="flex items-center gap-3">
            {alert.type === 'success' ? (
              <CheckCircle2 className="w-5 h-5 text-emerald-600 flex-shrink-0" />
            ) : (
              <AlertCircle className="w-5 h-5 text-rose-600 flex-shrink-0" />
            )}
            <span className="font-medium">{alert.message}</span>
          </div>
          <button onClick={() => setAlert(null)} className="text-slate-400 hover:text-slate-600 p-1">
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* 3. KPI Statistics Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
            <Car className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tổng phương tiện</div>
            <div className="text-2xl font-black text-slate-900">{totalCount}</div>
            <div className="text-[11px] text-blue-600 font-semibold mt-0.5">Xe đăng ký hệ thống</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <Bike className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Xe máy đang đỗ</div>
            <div className="text-2xl font-black text-slate-900">{stats.motorbikes}</div>
            <div className="text-[11px] text-amber-700 font-semibold mt-0.5">120.000 đ/tháng/xe</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0">
            <Car className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Ô tô trong bãi</div>
            <div className="text-2xl font-black text-slate-900">{stats.cars}</div>
            <div className="text-[11px] text-indigo-700 font-semibold mt-0.5">1.500.000 đ/tháng/xe</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <Receipt className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Doanh thu gửi xe dự kiến</div>
            <div className="text-xl sm:text-2xl font-black text-emerald-700">
              {new Intl.NumberFormat('vi-VN').format(stats.estimatedMonthlyRevenue)} đ
            </div>
            <div className="text-[11px] text-emerald-600 font-semibold mt-0.5">Tự động đẩy vào hóa đơn</div>
          </div>
        </div>
      </div>

      {/* 4. Filter & Search Bar */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          {/* Search Input */}
          <div className="relative lg:col-span-2">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Tìm theo biển số, hãng, cư dân, căn hộ..."
              className="w-full pl-9 pr-4 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
            />
          </div>

          {/* Loại xe filter */}
          <div>
            <select
              value={filterCategory}
              onChange={(e) => {
                setFilterCategory(e.target.value);
                setCurrentPage(1);
              }}
              className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 bg-white"
            >
              <option value="">Tất cả loại xe</option>
              <option value="MOTORBIKE">Xe máy (Motorbike)</option>
              <option value="CAR">Ô tô (Car)</option>
              <option value="E_SCOOTER">Xe điện (E-Scooter)</option>
              <option value="BICYCLE">Xe đạp (Bicycle)</option>
            </select>
          </div>

          {/* Căn hộ filter */}
          <div>
            <select
              value={filterApartmentId}
              onChange={(e) => {
                setFilterApartmentId(e.target.value);
                setCurrentPage(1);
              }}
              className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 bg-white"
            >
              <option value="">Tất cả căn hộ</option>
              {apartments.map((apt) => (
                <option key={apt.id} value={apt.id}>
                  {apt.apartment_number}
                </option>
              ))}
            </select>
          </div>

          {/* Trạng thái filter */}
          <div>
            <select
              value={filterStatus}
              onChange={(e) => {
                setFilterStatus(e.target.value);
                setCurrentPage(1);
              }}
              className="w-full px-3 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 bg-white"
            >
              <option value="">Tất cả trạng thái</option>
              <option value="1">Đang hoạt động</option>
              <option value="0">Tạm dừng</option>
            </select>
          </div>
        </div>
      </div>

      {/* 5. Vehicles Table */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider font-mono">
                <th className="py-3 px-4">Biển số & Loại xe</th>
                <th className="py-3 px-4">Căn hộ & Chủ xe</th>
                <th className="py-3 px-4">Hiệu xe / Model / Màu</th>
                <th className="py-3 px-4">Phí giữ xe / tháng</th>
                <th className="py-3 px-4">Trạng thái</th>
                <th className="py-3 px-4">Phê duyệt</th>
                <th className="py-3 px-4 text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Loader2 className="w-6 h-6 animate-spin text-emerald-600" />
                      <span>Đang tải danh sách phương tiện...</span>
                    </div>
                  </td>
                </tr>
              ) : vehicles.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Car className="w-8 h-8 text-slate-300" />
                      <span className="font-medium">Chưa có phương tiện nào phù hợp với bộ lọc.</span>
                    </div>
                  </td>
                </tr>
              ) : (
                vehicles.map((v) => {
                  const isCar = v.vehicle_category === 'CAR';
                  return (
                    <tr key={v.id} className="hover:bg-slate-50/70 transition">
                      {/* Biển số & Icon loại xe */}
                      <td className="py-3.5 px-4">
                        <div className="flex items-center gap-3">
                          <div
                            className={`w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 ${
                              isCar ? 'bg-indigo-50 text-indigo-600' : 'bg-amber-50 text-amber-600'
                            }`}
                          >
                            {isCar ? <Car className="w-5 h-5" /> : <Bike className="w-5 h-5" />}
                          </div>
                          <div>
                            <div className="font-extrabold text-slate-900 font-mono tracking-wider text-sm bg-slate-100 px-2 py-0.5 rounded border border-slate-300 inline-block">
                              {v.license_plate}
                            </div>
                            <div className="text-[10px] text-slate-500 mt-0.5">
                              {isCar ? 'Ô tô' : 'Xe máy'} {v.has_electric_charging_subscription ? '· Có sạc điện' : ''}
                            </div>
                          </div>
                        </div>
                      </td>

                      {/* Căn hộ & Chủ xe */}
                      <td className="py-3.5 px-4">
                        <div className="font-semibold text-slate-900 flex items-center gap-1.5">
                          <Building2 className="w-3.5 h-3.5 text-slate-400" />
                          <span>{v.apartment?.apartment_number || 'N/A'}</span>
                        </div>
                        <div className="text-slate-500 text-[11px] flex items-center gap-1 mt-0.5">
                          <User className="w-3 h-3 text-slate-400" />
                          <span>{v.owner?.full_name || 'Chưa gán cư dân'}</span>
                        </div>
                      </td>

                      {/* Hiệu xe, Model, Màu */}
                      <td className="py-3.5 px-4">
                        <div className="font-medium text-slate-800">
                          {v.brand || '---'} {v.model ? `· ${v.model}` : ''}
                        </div>
                        <div className="text-slate-500 text-[11px]">
                          {v.color ? `Màu: ${v.color}` : ''}
                          {v.registration_certificate_number ? ` (Cà-vẹt: ${v.registration_certificate_number})` : ''}
                        </div>
                      </td>

                      {/* Phí giữ xe */}
                      <td className="py-3.5 px-4">
                        <div className="font-black text-emerald-700 text-xs">
                          {new Intl.NumberFormat('vi-VN').format(v.monthly_parking_fee)} đ
                        </div>
                        <div className="text-[10px] text-slate-400">/ tháng (VAT 10%)</div>
                      </td>

                      {/* Trạng thái hoạt động */}
                      <td className="py-3.5 px-4">
                        <button
                          onClick={() => handleToggleActive(v)}
                          title="Nhấn để đổi trạng thái"
                          className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold border transition ${
                            v.is_active
                              ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100'
                              : 'bg-slate-100 text-slate-600 border-slate-200 hover:bg-slate-200'
                          }`}
                        >
                          <span className={`w-1.5 h-1.5 rounded-full ${v.is_active ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                          {v.is_active ? 'Đang hoạt động' : 'Tạm dừng'}
                        </button>
                      </td>

                      {/* Phê duyệt */}
                      <td className="py-3.5 px-4">
                        {v.approved_by ? (
                          <div className="inline-flex items-center gap-1 text-[11px] text-emerald-700 font-semibold">
                            <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                            <span>Đã duyệt</span>
                          </div>
                        ) : (
                          <button
                            onClick={() => handleApprove(v)}
                            className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 transition"
                          >
                            <Clock className="w-3 h-3 text-amber-600" />
                            <span>Chờ duyệt</span>
                          </button>
                        )}
                      </td>

                      {/* Thao tác */}
                      <td className="py-3.5 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            onClick={() => handleOpenDetailModal(v)}
                            className="p-1.5 text-slate-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition"
                            title="Xem chi tiết & Lịch sử hóa đơn"
                          >
                            <Eye className="w-4 h-4" />
                          </button>
                          <button
                            onClick={() => handleOpenEditModal(v)}
                            className="p-1.5 text-slate-600 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition"
                            title="Chỉnh sửa thông tin"
                          >
                            <Edit3 className="w-4 h-4" />
                          </button>
                          <button
                            onClick={() => handleOpenDeleteModal(v)}
                            className="p-1.5 text-slate-600 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                            title="Xóa phương tiện (Soft delete)"
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
            Hiển thị trang <span className="font-bold text-slate-700">{currentPage}</span> / {totalPages} (Tổng{' '}
            <span className="font-bold text-slate-700">{totalCount}</span> phương tiện)
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={() => setCurrentPage((p) => Math.max(p - 1, 1))}
              disabled={currentPage <= 1}
              className="p-1.5 border border-slate-200 rounded-lg bg-white disabled:opacity-40 hover:bg-slate-100 transition"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <button
              onClick={() => setCurrentPage((p) => Math.min(p + 1, totalPages))}
              disabled={currentPage >= totalPages}
              className="p-1.5 border border-slate-200 rounded-lg bg-white disabled:opacity-40 hover:bg-slate-100 transition"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      {/* MODAL 1: ĐĂNG KÝ PHƯƠNG TIỆN MỚI */}
      {isCreateModalOpen && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-xl w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-5 py-4 border-b border-slate-200 bg-slate-50/80 shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center">
                  <Car className="w-4 h-4" />
                </div>
                <h3 className="font-extrabold text-slate-900 text-base">Đăng Ký Phương Tiện Cư Dân</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsCreateModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateSubmit} className="flex flex-col flex-1 overflow-hidden">
              <div className="p-5 space-y-4 text-xs overflow-y-auto custom-scrollbar flex-1 max-h-[calc(90vh-130px)]">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Chọn căn hộ */}
                  <div>
                    <label className="block font-bold text-slate-700 mb-1">Căn hộ đăng ký (*)</label>
                    <select
                      value={formApartmentId}
                      onChange={(e) => setFormApartmentId(e.target.value)}
                      required
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white"
                    >
                      <option value="">-- Chọn căn hộ --</option>
                      {apartments.map((a) => (
                        <option key={a.id} value={a.id}>
                          {a.apartment_number}
                        </option>
                      ))}
                    </select>
                  </div>

                  {/* Chọn chủ xe */}
                  <div>
                    <label className="block font-bold text-slate-700 mb-1">Chủ phương tiện (*)</label>
                    <select
                      value={formOwnerUserId}
                      onChange={(e) => setFormOwnerUserId(e.target.value)}
                      required
                      disabled={loadingResidents || formResidents.length === 0}
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white disabled:bg-slate-100"
                    >
                      <option value="">
                        {loadingResidents
                          ? 'Đang tải cư dân...'
                          : formResidents.length === 0
                          ? '-- Chọn căn hộ trước --'
                          : '-- Chọn cư dân sở hữu --'}
                      </option>
                      {formResidents.map((r) => (
                        <option key={r.user_id} value={r.user_id}>
                          {r.full_name} ({r.resident_type})
                        </option>
                      ))}
                    </select>
                  </div>

                  {/* Loại xe */}
                  <div>
                    <label className="block font-bold text-slate-700 mb-1">Loại phương tiện (*)</label>
                    <select
                      value={formCategory}
                      onChange={(e) => setFormCategory(e.target.value)}
                      required
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-emerald-500 bg-white"
                    >
                      <option value="MOTORBIKE">Xe máy (Motorbike)</option>
                      <option value="CAR">Ô tô (Car)</option>
                      <option value="E_SCOOTER">Xe máy điện / Scooter</option>
                      <option value="BICYCLE">Xe đạp</option>
                    </select>
                  </div>

                  {/* Biển số xe */}
                  <div>
                    <label className="block font-bold text-slate-700 mb-1">Biển số xe (*)</label>
                    <input
                      type="text"
                      value={formLicensePlate}
                      onChange={(e) => setFormLicensePlate(e.target.value.toUpperCase())}
                      placeholder="VD: 30A-12345 hoặc 29M1-99988"
                      required
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-emerald-500 uppercase font-mono font-bold"
                    />
                  </div>

                  {/* Hãng xe */}
                  <div>
                    <label className="block font-medium text-slate-700 mb-1">Hãng xe</label>
                    <input
                      type="text"
                      value={formBrand}
                      onChange={(e) => setFormBrand(e.target.value)}
                      placeholder="VD: Honda, VinFast, Toyota..."
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-emerald-500"
                    />
                  </div>

                  {/* Model */}
                  <div>
                    <label className="block font-medium text-slate-700 mb-1">Dòng xe (Model)</label>
                    <input
                      type="text"
                      value={formModel}
                      onChange={(e) => setFormModel(e.target.value)}
                      placeholder="VD: SH 150i, VF8, Camry..."
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-emerald-500"
                    />
                  </div>

                  {/* Màu xe */}
                  <div>
                    <label className="block font-medium text-slate-700 mb-1">Màu sơn xe</label>
                    <input
                      type="text"
                      value={formColor}
                      onChange={(e) => setFormColor(e.target.value)}
                      placeholder="VD: Đen nhám, Trắng, Đỏ..."
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-emerald-500"
                    />
                  </div>

                  {/* Số cà-vẹt xe */}
                  <div>
                    <label className="block font-medium text-slate-700 mb-1">Số Cà-vẹt / Đăng ký xe</label>
                    <input
                      type="text"
                      value={formCertNumber}
                      onChange={(e) => setFormCertNumber(e.target.value)}
                      placeholder="VD: 01234567"
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-emerald-500"
                    />
                  </div>
                </div>

                {/* Thông tin biểu phí */}
                <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                  <div className="flex items-center justify-between">
                    <div className="font-bold text-slate-700 flex items-center gap-1.5">
                      <Tag className="w-3.5 h-3.5 text-emerald-600" />
                      <span>Mức phí gửi xe áp dụng (Đơn giá hệ thống):</span>
                    </div>
                    <div className="text-sm font-black text-emerald-700 font-mono">
                      {new Intl.NumberFormat('vi-VN').format(formFee)} đ / tháng
                    </div>
                  </div>
                  <div className="text-[11px] text-slate-500">
                    Khoản phí này sẽ tự động tích hợp vào hóa đơn của căn hộ chu kỳ hiện tại kèm thuế VAT 10% theo quy định.
                  </div>
                </div>

                {/* Subscription trạm sạc điện */}
                <div className="flex items-center gap-2 pt-1">
                  <input
                    type="checkbox"
                    id="ev_charging"
                    checked={formEvCharging}
                    onChange={(e) => setFormEvCharging(e.target.checked)}
                    className="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4"
                  />
                  <label htmlFor="ev_charging" className="text-slate-700 font-medium cursor-pointer">
                    Đăng ký kèm gói sạc trụ sạc thông minh tại hầm đỗ
                  </label>
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 px-5 py-3.5 bg-slate-50 border-t border-slate-200 shrink-0">
                <button
                  type="button"
                  onClick={() => setIsCreateModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={formSubmitting}
                  className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition disabled:opacity-50"
                >
                  {formSubmitting && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>Xác nhận đăng ký & Đẩy phí</span>
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 2: CHỈNH SỬA PHƯƠNG TIỆN */}
      {isEditModalOpen && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-xl w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-5 py-4 border-b border-slate-200 bg-slate-50/80 shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center">
                  <Edit3 className="w-4 h-4" />
                </div>
                <h3 className="font-extrabold text-slate-900 text-base">Cập Nhật Thông Tin Phương Tiện</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsEditModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleEditSubmit} className="flex flex-col flex-1 overflow-hidden">
              <div className="p-5 space-y-4 text-xs overflow-y-auto custom-scrollbar flex-1 max-h-[calc(90vh-130px)]">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Biển số xe */}
                  <div>
                    <label className="block font-bold text-slate-700 mb-1">Biển số xe (*)</label>
                    <input
                      type="text"
                      value={formLicensePlate}
                      onChange={(e) => setFormLicensePlate(e.target.value.toUpperCase())}
                      required
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 uppercase font-mono font-bold"
                    />
                  </div>

                  {/* Loại xe */}
                  <div>
                    <label className="block font-bold text-slate-700 mb-1">Loại phương tiện (*)</label>
                    <select
                      value={formCategory}
                      onChange={(e) => setFormCategory(e.target.value)}
                      required
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white"
                    >
                      <option value="MOTORBIKE">Xe máy (Motorbike)</option>
                      <option value="CAR">Ô tô (Car)</option>
                      <option value="E_SCOOTER">Xe máy điện / Scooter</option>
                      <option value="BICYCLE">Xe đạp</option>
                    </select>
                  </div>

                  {/* Hãng xe */}
                  <div>
                    <label className="block font-medium text-slate-700 mb-1">Hãng xe</label>
                    <input
                      type="text"
                      value={formBrand}
                      onChange={(e) => setFormBrand(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  {/* Model */}
                  <div>
                    <label className="block font-medium text-slate-700 mb-1">Dòng xe (Model)</label>
                    <input
                      type="text"
                      value={formModel}
                      onChange={(e) => setFormModel(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  {/* Màu xe */}
                  <div>
                    <label className="block font-medium text-slate-700 mb-1">Màu sơn xe</label>
                    <input
                      type="text"
                      value={formColor}
                      onChange={(e) => setFormColor(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                    />
                  </div>

                  {/* Số cà-vẹt xe */}
                  <div>
                    <label className="block font-medium text-slate-700 mb-1">Số Cà-vẹt / Đăng ký xe</label>
                    <input
                      type="text"
                      value={formCertNumber}
                      onChange={(e) => setFormCertNumber(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                </div>

                {/* Mức phí điều chỉnh */}
                <div>
                  <label className="block font-bold text-slate-700 mb-1">Mức phí gửi xe hàng tháng (VNĐ)</label>
                  <input
                    type="number"
                    min="0"
                    value={formFee}
                    onChange={(e) => setFormFee(Number(e.target.value))}
                    className="w-full px-3 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 font-mono font-bold"
                  />
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 px-5 py-3.5 bg-slate-50 border-t border-slate-200 shrink-0">
                <button
                  type="button"
                  onClick={() => setIsEditModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={formSubmitting}
                  className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition disabled:opacity-50"
                >
                  {formSubmitting && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>Lưu thay đổi</span>
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 3: XEM CHI TIẾT & LỊCH SỬ HÓA ĐƠN */}
      {isDetailModalOpen && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-2xl w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-5 py-4 border-b border-slate-200 bg-slate-50/80 shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center">
                  <Receipt className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="font-extrabold text-slate-900 text-base">Hồ Sơ Phương Tiện & Lịch Sử Hóa Đơn</h3>
                  <div className="text-slate-500 text-xs">Biển số: {selectedVehicle?.license_plate}</div>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-5 space-y-6 text-xs overflow-y-auto custom-scrollbar flex-1 max-h-[calc(90vh-130px)]">
              {loadingDetail ? (
                <div className="py-12 text-center text-slate-400 flex flex-col items-center justify-center gap-2">
                  <Loader2 className="w-6 h-6 animate-spin text-emerald-600" />
                  <span>Đang tải thông tin chi tiết và lịch sử hóa đơn...</span>
                </div>
              ) : (
                <>
                  {/* Thông tin phương tiện */}
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 p-4 bg-slate-50 rounded-xl border border-slate-200">
                    <div>
                      <span className="text-slate-400 block text-[11px]">Căn hộ</span>
                      <span className="font-bold text-slate-800 text-sm">
                        {detailData?.vehicle.apartment?.apartment_number || 'N/A'}
                      </span>
                    </div>
                    <div>
                      <span className="text-slate-400 block text-[11px]">Chủ sở hữu</span>
                      <span className="font-bold text-slate-800">{detailData?.vehicle.owner?.full_name || 'N/A'}</span>
                    </div>
                    <div>
                      <span className="text-slate-400 block text-[11px]">Loại xe</span>
                      <span className="font-bold text-slate-800">
                        {detailData?.vehicle.vehicle_category === 'CAR' ? 'Ô tô' : 'Xe máy'}
                      </span>
                    </div>
                    <div>
                      <span className="text-slate-400 block text-[11px]">Hãng & Dòng xe</span>
                      <span className="font-medium text-slate-700">
                        {detailData?.vehicle.brand || '---'} {detailData?.vehicle.model || ''}
                      </span>
                    </div>
                    <div>
                      <span className="text-slate-400 block text-[11px]">Màu sơn</span>
                      <span className="font-medium text-slate-700">{detailData?.vehicle.color || '---'}</span>
                    </div>
                    <div>
                      <span className="text-slate-400 block text-[11px]">Số Cà-vẹt</span>
                      <span className="font-mono text-slate-700">
                        {detailData?.vehicle.registration_certificate_number || '---'}
                      </span>
                    </div>
                    <div>
                      <span className="text-slate-400 block text-[11px]">Đơn giá tháng</span>
                      <span className="font-black text-emerald-700">
                        {new Intl.NumberFormat('vi-VN').format(detailData?.vehicle.monthly_parking_fee || 0)} đ
                      </span>
                    </div>
                    <div>
                      <span className="text-slate-400 block text-[11px]">Trạng thái</span>
                      <span
                        className={`font-bold ${detailData?.vehicle.is_active ? 'text-emerald-600' : 'text-slate-500'}`}
                      >
                        {detailData?.vehicle.is_active ? 'Đang hoạt động' : 'Tạm dừng'}
                      </span>
                    </div>
                    <div>
                      <span className="text-slate-400 block text-[11px]">Người duyệt</span>
                      <span className="font-medium text-slate-700">
                        {detailData?.vehicle.approver?.full_name || 'Chưa duyệt'}
                      </span>
                    </div>
                  </div>

                  {/* Lịch sử mục phí hóa đơn đã ghi nhận */}
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <h4 className="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                        <Receipt className="w-4 h-4 text-emerald-600" />
                        <span>Lịch sử Mục Phí Phát Sinh Trong Hóa Đơn ({detailData?.invoice_history.length || 0})</span>
                      </h4>
                      {selectedVehicle && (
                        <button
                          onClick={() => handleSyncInvoice(selectedVehicle.id)}
                          disabled={syncingInvoice}
                          className="flex items-center gap-1 px-3 py-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 rounded-lg text-xs font-bold transition disabled:opacity-50"
                        >
                          {syncingInvoice ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <RefreshCw className="w-3.5 h-3.5" />
                          )}
                          <span>Đồng bộ kỳ hiện tại</span>
                        </button>
                      )}
                    </div>

                    {(!detailData?.invoice_history || detailData.invoice_history.length === 0) ? (
                      <div className="p-6 text-center text-slate-400 bg-slate-50 rounded-xl border border-dashed border-slate-200">
                        Chưa phát sinh mục phí trong kỳ hóa đơn nào. Nhấn "Đồng bộ kỳ hiện tại" để tạo hóa đơn.
                      </div>
                    ) : (
                      <div className="border border-slate-200 rounded-xl overflow-hidden">
                        <table className="w-full text-left text-xs">
                          <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase font-mono text-[10px]">
                            <tr>
                              <th className="py-2.5 px-3">Mã Hóa Đơn</th>
                              <th className="py-2.5 px-3">Kỳ HĐ</th>
                              <th className="py-2.5 px-3">Mô tả mục phí</th>
                              <th className="py-2.5 px-3 text-right">Tổng tiền (gồm VAT)</th>
                              <th className="py-2.5 px-3 text-center">Trạng thái HĐ</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-slate-100">
                            {detailData.invoice_history.map((item) => (
                              <tr key={item.item_id} className="hover:bg-slate-50/50">
                                <td className="py-2.5 px-3 font-mono font-bold text-slate-800">
                                  {item.invoice_number}
                                </td>
                                <td className="py-2.5 px-3 font-semibold text-slate-700">{item.billing_period}</td>
                                <td className="py-2.5 px-3 text-slate-600">{item.item_description}</td>
                                <td className="py-2.5 px-3 text-right font-black text-emerald-700">
                                  {new Intl.NumberFormat('vi-VN').format(item.total_line_amount)} đ
                                </td>
                                <td className="py-2.5 px-3 text-center">
                                  <span
                                    className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                                      item.invoice_status === 'PAID'
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : 'bg-amber-100 text-amber-800'
                                    }`}
                                  >
                                    {item.invoice_status}
                                  </span>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                </>
              )}
            </div>

            <div className="flex justify-end px-5 py-3.5 bg-slate-50 border-t border-slate-200 shrink-0">
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-100 rounded-lg transition"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* MODAL 4: XÁC NHẬN XÓA PHƯƠNG TIỆN */}
      {isDeleteModalOpen && vehicleToDelete && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-lg w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            {/* Header */}
            <div className="flex items-center justify-between px-5 py-4 border-b border-rose-100 bg-rose-50/70 shrink-0">
              <div className="flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-lg bg-rose-100 text-rose-700 flex items-center justify-center shadow-xs">
                  <Trash2 className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="font-extrabold text-slate-900 text-base">Xác Nhận Xóa Phương Tiện</h3>
                  <div className="text-slate-500 text-xs">Ngừng theo dõi và lưu trữ thông tin</div>
                </div>
              </div>
              <button
                type="button"
                onClick={() => {
                  setIsDeleteModalOpen(false);
                  setVehicleToDelete(null);
                }}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Body */}
            <div className="p-5 space-y-4 text-xs overflow-y-auto custom-scrollbar flex-1 max-h-[calc(90vh-130px)]">
              {/* Thẻ tóm tắt thông tin xe */}
              <div className="p-4 bg-slate-50 rounded-xl border border-slate-200 space-y-3">
                <div className="flex items-center justify-between pb-2 border-b border-slate-200/80">
                  <span className="text-slate-500 font-medium">Biển số xe:</span>
                  <span className="font-mono font-extrabold text-slate-900 text-sm bg-white px-2.5 py-1 rounded border border-slate-300">
                    {vehicleToDelete.license_plate}
                  </span>
                </div>
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <span className="text-slate-400 block text-[11px]">Căn hộ</span>
                    <span className="font-bold text-slate-800">{vehicleToDelete.apartment?.apartment_number || 'N/A'}</span>
                  </div>
                  <div>
                    <span className="text-slate-400 block text-[11px]">Chủ sở hữu</span>
                    <span className="font-bold text-slate-800">{vehicleToDelete.owner?.full_name || 'N/A'}</span>
                  </div>
                  <div>
                    <span className="text-slate-400 block text-[11px]">Loại phương tiện</span>
                    <span className="font-medium text-slate-700">
                      {vehicleToDelete.vehicle_category === 'CAR' ? 'Ô tô (Car)' : 'Xe máy (Motorbike)'}
                    </span>
                  </div>
                  <div>
                    <span className="text-slate-400 block text-[11px]">Mức phí hiện tại</span>
                    <span className="font-black text-emerald-700">
                      {new Intl.NumberFormat('vi-VN').format(vehicleToDelete.monthly_parking_fee)} đ/tháng
                    </span>
                  </div>
                </div>
              </div>

              {/* Thông báo chính sách xóa mềm bảo toàn hóa đơn */}
              <div className="flex items-start gap-3 p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-900">
                <AlertCircle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div className="space-y-1">
                  <div className="font-bold text-xs">Lưu ý về quy trình kế toán & vận hành:</div>
                  <div className="text-[11px] text-amber-800 leading-relaxed">
                    Phương tiện sẽ được ngừng theo dõi và đưa vào danh sách lưu trữ (Soft Delete). Toàn bộ lịch sử mục phí và các hóa đơn tài chính căn hộ đã phát sinh trước đây vẫn được bảo toàn nguyên vẹn trên hệ thống.
                  </div>
                </div>
              </div>
            </div>

            {/* Footer */}
            <div className="flex items-center justify-end gap-2 px-5 py-3.5 bg-slate-50 border-t border-slate-200 shrink-0">
              <button
                type="button"
                onClick={() => {
                  setIsDeleteModalOpen(false);
                  setVehicleToDelete(null);
                }}
                disabled={deletingVehicle}
                className="px-4 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition disabled:opacity-50"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleConfirmDelete}
                disabled={deletingVehicle}
                className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 rounded-lg transition disabled:opacity-50 shadow-xs hover:shadow"
              >
                {deletingVehicle ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Trash2 className="w-3.5 h-3.5" />
                )}
                <span>Xác nhận xóa</span>
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}
    </div>
  );
};

export default VehicleManagement;
