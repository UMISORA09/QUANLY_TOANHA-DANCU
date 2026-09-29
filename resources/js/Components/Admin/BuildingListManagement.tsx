import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
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
  ChevronLeft,
  ChevronsLeft,
  ChevronsRight,
  Eye,
  SlidersHorizontal,
  RefreshCw,
  Plus,
  Box,
  Radio,
  Clock,
  Phone,
  ArrowRight,
  ExternalLink,
  Trash2,
  Pencil,
  Download,
  Upload,
  Sparkles,
  X,
  FileSpreadsheet,
  Check,
} from 'lucide-react';
import api from '../../Services/api';
import { Building3DModel } from '../Building3DModel';

export interface BuildingInfo {
  id: string;
  code: string;
  name: string;
  floors: number;
  basements: number;
  elevators: number;
  totalUnits: number;
  occupiedUnits: number;
  vacantUnits: number;
  renovatingUnits: number;
  manager: string;
  managerPhone: string;
  fireSafetyStatus: 'safe' | 'warning';
  powerStatus: 'stable' | 'alert';
  waterStatus: 'stable' | 'alert';
  status?: string;
  address?: string;
  description?: string;
}

export interface ApartmentUnit {
  id: string;
  unitCode: string;
  buildingCode: string;
  buildingName?: string;
  floor: number;
  type: string;
  area: number;
  ownerName: string;
  ownerPhone: string;
  status: 'occupied' | 'vacant' | 'renovating';
  feeStatus: 'paid' | 'unpaid';
}

interface BuildingListManagementProps {
  onNavigateTab?: (tabId: string) => void;
}

// ================= FAST MASTER DATASET ENGINE =================
export const BuildingListManagement: React.FC<BuildingListManagementProps> = ({ onNavigateTab }) => {
  // 1. Đọc snapshot toàn bộ 371+ căn hộ từ sessionStorage để render NGAY LẬP TỨC (0ms) khi mở trang
  const initialSnapshot = useMemo(() => {
    try {
      const saved = sessionStorage.getItem('smart_apartments_master_v2');
      if (!saved) return null;
      const parsed = JSON.parse(saved);
      if (parsed && Array.isArray(parsed.allData) && parsed.allData.length > 0) {
        return parsed;
      }
      return null;
    } catch {
      return null;
    }
  }, []);

  // Dữ liệu Tòa nhà & Thống kê Tổng
  const [buildings, setBuildings] = useState<BuildingInfo[]>([]);
  const [summary, setSummary] = useState({
    totalUnitsAll: 0,
    occupiedUnitsAll: 0,
    vacantUnitsAll: 0,
    renovatingUnitsAll: 0,
    overallOccupancyRate: 0,
    totalBuildings: 0,
  });
  const [isLoadingBuildings, setIsLoadingBuildings] = useState<boolean>(true);

  // TOÀN BỘ CĂN HỘ (Load hết 1 lần duy nhất cực nhanh, các thao tác sau đó 0ms)
  const [allApartments, setAllApartments] = useState<ApartmentUnit[]>(() =>
    Array.isArray(initialSnapshot?.allData) ? initialSnapshot.allData : []
  );
  const [isLoadingApartments, setIsLoadingApartments] = useState<boolean>(() => !Array.isArray(initialSnapshot?.allData) || initialSnapshot.allData.length === 0);
  const [isBackgroundSyncing, setIsBackgroundSyncing] = useState<boolean>(false);
  const [lastSyncTime, setLastSyncTime] = useState<string>('');

  // Bộ lọc & Tìm kiếm tức thì (0ms)
  const [selectedBuildingFilter, setSelectedBuildingFilter] = useState<string>('all');
  const [searchInputValue, setSearchInputValue] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('all');

  // Phân trang: perPage = -1 tương ứng "Tất cả" (hiển thị hết toàn bộ dữ liệu trên trang)
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [perPage, setPerPage] = useState<number>(10);

  // ================= 7 CHỨC NĂNG QUẢN LÝ (STATE & ACTION HANDLERS) =================
  // 1. Thao tác đặc biệt: Chọn nhiều căn hộ (Batch Select)
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [isSpecialMenuOpen, setIsSpecialMenuOpen] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'error' } | null>(null);

  const showToast = (text: string, type: 'success' | 'error' = 'success') => {
    setToastMessage({ text, type });
    setTimeout(() => setToastMessage(null), 3500);
  };

  // 2. Chức năng Thêm: Modal state
  const [isAddModalOpen, setIsAddModalOpen] = useState<boolean>(false);
  const [addForm, setAddForm] = useState({
    unitCode: '',
    buildingCode: 'BLOCK_A',
    floor: 5,
    type: '2 Phòng ngủ',
    area: 75,
    ownerName: '',
    ownerPhone: '',
    status: 'occupied',
    feeStatus: 'paid',
  });

  // 3. Chức năng Sửa: Modal state
  const [editingApartment, setEditingApartment] = useState<ApartmentUnit | null>(null);
  const [editForm, setEditForm] = useState({
    unitCode: '',
    buildingCode: 'BLOCK_A',
    floor: 5,
    type: '2 Phòng ngủ',
    area: 75,
    ownerName: '',
    ownerPhone: '',
    status: 'occupied',
    feeStatus: 'paid',
  });

  // 4. Chức năng Xem chi tiết: Modal state
  const [viewingApartment, setViewingApartment] = useState<ApartmentUnit | null>(null);
  const [viewDetailData, setViewDetailData] = useState<any>(null);
  const [isLoadingDetail, setIsLoadingDetail] = useState<boolean>(false);

  // 5. Chức năng Xóa: Modal state
  const [deletingApartment, setDeletingApartment] = useState<ApartmentUnit | null>(null);

  // 6. Chức năng Tải lên (Import): Modal state
  const [isImportModalOpen, setIsImportModalOpen] = useState<boolean>(false);
  const [importRows, setImportRows] = useState<any[]>([]);

  // Ref quản lý race-condition & hủy request cũ
  const currentRequestIdRef = useRef<number>(0);
  const abortControllerRef = useRef<AbortController | null>(null);

  // Chế độ hiển thị: 'table' (Danh sách căn hộ) | '3d' (Phối cảnh 3D)
  const [activeViewMode, setActiveViewMode] = useState<'table' | '3d'>('table');

  // Chuyển trang sang phân hệ Zone Manager
  const handleGoToZoneManager = () => {
    if (onNavigateTab) {
      onNavigateTab('zones');
    } else {
      window.location.href = '/quan-ly/zones';
    }
  };

  // ================= CALL API: LẤY THÔNG TIN TỔNG QUAN TÒA NHÀ =================
  const fetchBuildingsData = useCallback(async () => {
    setIsLoadingBuildings(true);
    try {
      const res = await api.getBuildingsOverview();
      if (res && res.success && res.data) {
        setBuildings(Array.isArray(res.data.buildings) ? res.data.buildings : []);
        if (res.data.summary) {
          setSummary(res.data.summary);
        }
      }
    } catch (err) {
      console.warn('Lỗi khi tải thông tin tòa nhà:', err);
    } finally {
      setIsLoadingBuildings(false);
    }
  }, []);

  // ================= CALL API: LOAD HẾT TẤT CẢ DỮ LIỆU NHANH NHẤT (BULK ALL IN ONE) =================
  const fetchAllApartments = useCallback(async (isSilentBackground = false) => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }
    const controller = new AbortController();
    abortControllerRef.current = controller;
    const reqId = ++currentRequestIdRef.current;

    if (allApartments.length === 0 && !isSilentBackground) {
      setIsLoadingApartments(true);
    } else {
      setIsBackgroundSyncing(true);
    }

    try {
      // Tải trọn vẹn toàn bộ 371 căn hộ chỉ trong 1 request duy nhất (server cache ~40ms)
      const res = await api.getApartments({ all: true }, { signal: controller.signal });

      if (reqId !== currentRequestIdRef.current) return;

      if (res && res.success && Array.isArray(res.data)) {
        setAllApartments(res.data);

        // Lưu snapshot vào sessionStorage để các lần tải sau hiển thị trong 0ms
        try {
          sessionStorage.setItem('smart_apartments_master_v2', JSON.stringify({
            allData: res.data,
            timestamp: Date.now(),
          }));
        } catch {}

        const now = new Date();
        setLastSyncTime(now.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
      }
    } catch (err: any) {
      if (err?.name === 'AbortError' || err?.message === 'canceled') return;
      if (reqId === currentRequestIdRef.current) {
        console.warn('Lỗi tải toàn bộ căn hộ:', err);
      }
    } finally {
      if (reqId === currentRequestIdRef.current) {
        setIsLoadingApartments(false);
        setIsBackgroundSyncing(false);
      }
    }
  }, [allApartments.length]);

  // Khởi động nạp dữ liệu ban đầu
  useEffect(() => {
    fetchBuildingsData();
    fetchAllApartments();
  }, [fetchBuildingsData, fetchAllApartments]);

  // Tự động load và đồng bộ liên tục dưới nền mỗi 12 giây mà không chặn giao diện
  useEffect(() => {
    const syncInterval = setInterval(() => {
      if (!document.hidden) {
        fetchAllApartments(true);
      }
    }, 12000);

    return () => clearInterval(syncInterval);
  }, [fetchAllApartments]);

  // Cleanup khi unmount
  useEffect(() => {
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, []);

  // ================= CLIENT-SIDE INSTANT FILTERING & SEARCH PIPELINE (0ms) =================
  const filteredApartments = useMemo(() => {
    let list = allApartments;

    // Lọc theo khối tòa nhà
    if (selectedBuildingFilter !== 'all') {
      list = list.filter((a) =>
        a.buildingCode === selectedBuildingFilter ||
        a.buildingName?.toLowerCase().includes(selectedBuildingFilter.toLowerCase())
      );
    }

    // Lọc theo trạng thái cư trú
    if (statusFilter !== 'all') {
      list = list.filter((a) => a.status === statusFilter);
    }

    // Tìm kiếm tức thì theo mã căn, chủ hộ, sđt, loại phòng, tầng
    const q = searchInputValue.trim().toLowerCase();
    if (q) {
      list = list.filter((a) =>
        a.unitCode.toLowerCase().includes(q) ||
        a.ownerName.toLowerCase().includes(q) ||
        a.ownerPhone.includes(q) ||
        a.type.toLowerCase().includes(q) ||
        String(a.floor).includes(q) ||
        (a.buildingName && a.buildingName.toLowerCase().includes(q))
      );
    }

    return list;
  }, [allApartments, selectedBuildingFilter, statusFilter, searchInputValue]);

  // ================= TÍNH TOÁN PHÂN TRANG HOẶC HIỂN THỊ HẾT TẤT CẢ (0ms) =================
  const totalFiltered = filteredApartments.length;
  const isShowAll = perPage === -1;
  const totalPages = isShowAll ? 1 : Math.max(1, Math.ceil(totalFiltered / perPage));
  const activePage = Math.min(Math.max(1, currentPage), totalPages);

  const displayedApartments = useMemo(() => {
    if (isShowAll) {
      return filteredApartments;
    }
    const start = (activePage - 1) * perPage;
    return filteredApartments.slice(start, start + perPage);
  }, [filteredApartments, isShowAll, activePage, perPage]);

  const pageFrom = totalFiltered === 0 ? 0 : (isShowAll ? 1 : (activePage - 1) * perPage + 1);
  const pageTo = isShowAll ? totalFiltered : Math.min(activePage * perPage, totalFiltered);

  // Khi thay đổi bộ lọc thì reset về trang 1
  const handleFilterChange = (type: 'building' | 'status', value: string) => {
    setCurrentPage(1);
    if (type === 'building') setSelectedBuildingFilter(value);
    if (type === 'status') setStatusFilter(value);
  };

  const handleSearchChange = (val: string) => {
    setSearchInputValue(val);
    setCurrentPage(1);
  };

  // Chuyển trang nhanh
  const goToPage = (page: number) => {
    if (page < 1 || page > totalPages || page === activePage) return;
    setCurrentPage(page);
  };

  // Tạo dải số trang hiển thị thông minh
  const pageNumbers = useMemo(() => {
    if (totalPages <= 1) return [1];
    const delta = 2;
    const range: number[] = [];
    for (let i = Math.max(2, activePage - delta); i <= Math.min(totalPages - 1, activePage + delta); i++) {
      range.push(i);
    }

    if (activePage - delta > 2) {
      range.unshift(-1); // ellipsis
    }
    if (activePage + delta < totalPages - 1) {
      range.push(-2); // ellipsis
    }

    range.unshift(1);
    if (totalPages > 1) {
      range.push(totalPages);
    }

    return range;
  }, [activePage, totalPages]);

  // ================= 7 CHỨC NĂNG THAO TÁC CĂN HỘ (HANDLERS) =================

  // 1. Thêm căn hộ mới
  const handleSaveNewApartment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addForm.unitCode.trim()) {
      showToast('Vui lòng nhập mã căn hộ!', 'error');
      return;
    }
    try {
      const res = await api.createApartment(addForm);
      if (res && res.success && res.data) {
        setAllApartments((prev) => [res.data, ...prev]);
        setIsAddModalOpen(false);
        showToast(res.message || `Đã thêm căn hộ ${res.data.unitCode} thành công!`);
        setAddForm({
          unitCode: '',
          buildingCode: 'BLOCK_A',
          floor: 5,
          type: '2 Phòng ngủ',
          area: 75,
          ownerName: '',
          ownerPhone: '',
          status: 'occupied',
          feeStatus: 'paid',
        });
      }
    } catch (err: any) {
      showToast(err?.message || 'Lỗi khi thêm căn hộ!', 'error');
    }
  };

  // 2. Mở modal chỉnh sửa
  const handleOpenEdit = (apt: ApartmentUnit) => {
    setEditingApartment(apt);
    setEditForm({
      unitCode: apt.unitCode,
      buildingCode: apt.buildingCode || 'BLOCK_A',
      floor: apt.floor || 5,
      type: apt.type || '2 Phòng ngủ',
      area: apt.area || 75,
      ownerName: apt.ownerName || '',
      ownerPhone: apt.ownerPhone === '---' ? '' : (apt.ownerPhone || ''),
      status: apt.status || 'occupied',
      feeStatus: apt.feeStatus || 'paid',
    });
  };

  // Lưu chỉnh sửa căn hộ
  const handleSaveEditedApartment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingApartment) return;
    try {
      const res = await api.updateApartment(editingApartment.id, editForm);
      if (res && res.success && res.data) {
        setAllApartments((prev) =>
          prev.map((item) => (item.id === editingApartment.id ? { ...item, ...res.data } : item))
        );
        setEditingApartment(null);
        showToast(res.message || `Đã cập nhật căn hộ ${editForm.unitCode}!`);
      }
    } catch (err: any) {
      showToast(err?.message || 'Lỗi khi cập nhật căn hộ!', 'error');
    }
  };

  // 3. Xem chi tiết căn hộ
  const handleOpenViewDetail = async (apt: ApartmentUnit) => {
    setViewingApartment(apt);
    setViewDetailData(null);
    setIsLoadingDetail(true);
    try {
      const res = await api.getApartmentDetail(apt.id);
      if (res && res.success) {
        setViewDetailData(res.data);
      }
    } catch (err) {
      console.warn('Lỗi lấy chi tiết:', err);
    } finally {
      setIsLoadingDetail(false);
    }
  };

  // 4. Xóa căn hộ
  const handleConfirmDelete = async () => {
    if (!deletingApartment) return;
    try {
      const res = await api.deleteApartment(deletingApartment.id);
      if (res && res.success) {
        setAllApartments((prev) => prev.filter((item) => item.id !== deletingApartment.id));
        setSelectedIds((prev) => prev.filter((id) => id !== deletingApartment.id));
        setDeletingApartment(null);
        showToast(res.message || `Đã xóa căn hộ khỏi hệ thống.`);
      }
    } catch (err: any) {
      showToast(err?.message || 'Lỗi khi xóa căn hộ!', 'error');
    }
  };

  // 5. Tải về (Xuất danh sách Excel/CSV UTF-8)
  const handleExportCSV = () => {
    try {
      const dataToExport = filteredApartments.length > 0 ? filteredApartments : allApartments;
      const header = "\uFEFFMã Căn,Khối Tòa,Tầng,Loại Căn,Diện Tích (m2),Chủ Hộ,Số Điện Thoại,Trạng Thái,Phí Dịch Vụ\r\n";
      const rows = dataToExport.map((apt) => {
        const statusText = apt.status === 'occupied' ? 'Đang ở' : apt.status === 'renovating' ? 'Thi công' : 'Trống';
        const feeText = apt.feeStatus === 'paid' ? 'Đã thu' : 'Chưa thu';
        return `"${apt.unitCode}","${apt.buildingName || apt.buildingCode}","${apt.floor}","${apt.type}","${apt.area}","${apt.ownerName}","${apt.ownerPhone}","${statusText}","${feeText}"`;
      }).join("\r\n");

      const blob = new Blob([header + rows], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.setAttribute('href', url);
      link.setAttribute('download', `danh_sach_can_ho_${new Date().toISOString().slice(0, 10)}.csv`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
      showToast(`Đã xuất thành công ${dataToExport.length} căn hộ ra file CSV!`);
    } catch {
      showToast('Lỗi khi xuất file danh sách!', 'error');
    }
  };

  // 6. Tải file mẫu CSV (Template)
  const downloadTemplateCSV = () => {
    const header = "\uFEFFMã Căn,Khối Tòa,Tầng,Loại Căn,Diện Tích,Chủ Hộ,Số Điện Thoại,Trạng Thái,Phí Dịch Vụ\r\n";
    const sample = `"A-1501","Ruby Tower","15","3 Phòng ngủ (Góc)","102","Nguyễn Văn An","0901234567","Đang ở","Đã thu"\r\n"B-0802","Sapphire Tower","8","2 Phòng ngủ","75","Trần Thị Mai","0912345678","Đang ở","Chưa thu"\r\n"C-0504","Khu C","5","1 Phòng ngủ","55","Chủ hộ bàn giao","---","Trống","Đã thu"`;
    const blob = new Blob([header + sample], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'mau_nhap_can_ho.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  // Đọc file CSV người dùng tải lên
  const handleCSVUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (evt) => {
      const text = (evt.target?.result as string) || '';
      const lines = text.split(/\r?\n/).filter((l) => l.trim().length > 0);
      if (lines.length <= 1) {
        showToast('File không có dữ liệu!', 'error');
        return;
      }
      const dataRows = lines.slice(1).map((line) => {
        const cols = line.split(',').map((c) => c.replace(/^"|"$/g, '').trim());
        return {
          unitCode: cols[0] || '',
          buildingName: cols[1] || 'Khối Tòa',
          floor: Number(cols[2]) || 5,
          type: cols[3] || '2 Phòng ngủ',
          area: Number(cols[4]) || 75,
          ownerName: cols[5] || 'Chủ hộ đã nhận nhà',
          ownerPhone: cols[6] || '---',
          status: cols[7]?.includes('ở') ? 'occupied' : cols[7]?.includes('công') ? 'renovating' : 'vacant',
          feeStatus: cols[8]?.includes('Chưa') ? 'unpaid' : 'paid',
        };
      }).filter((r) => r.unitCode);
      setImportRows(dataRows);
      showToast(`Đã đọc ${dataRows.length} dòng dữ liệu từ file!`);
    };
    reader.readAsText(file);
  };

  // Xác nhận nhập dữ liệu từ file
  const handleConfirmImport = async () => {
    if (importRows.length === 0) {
      showToast('Chưa có dữ liệu căn hộ để nhập!', 'error');
      return;
    }
    try {
      const res = await api.importApartments(importRows);
      if (res && res.success) {
        setIsImportModalOpen(false);
        setImportRows([]);
        showToast(res.message || `Đã nhập thành công ${res.imported} căn hộ!`);
        fetchAllApartments(true);
      }
    } catch (err: any) {
      showToast(err?.message || 'Lỗi khi nhập dữ liệu!', 'error');
    }
  };

  // 7. Thao tác đặc biệt: Chọn checkbox từng dòng & Chọn tất cả
  const toggleSelectOne = (id: string) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    );
  };

  const isAllPageSelected = displayedApartments.length > 0 && displayedApartments.every((apt) => selectedIds.includes(apt.id));

  const toggleSelectAll = () => {
    if (isAllPageSelected) {
      const pageIds = new Set(displayedApartments.map((a) => a.id));
      setSelectedIds((prev) => prev.filter((id) => !pageIds.has(id)));
    } else {
      const pageIds = displayedApartments.map((a) => a.id);
      setSelectedIds((prev) => Array.from(new Set([...prev, ...pageIds])));
    }
  };

  // Batch: Đổi trạng thái hàng loạt
  const handleBatchStatus = async (newStatus: string) => {
    if (selectedIds.length === 0) return;
    try {
      const res = await api.batchApartmentsAction('update_status', selectedIds, { status: newStatus });
      if (res && res.success) {
        const statusMap: Record<string, 'occupied' | 'vacant' | 'renovating'> = {
          occupied: 'occupied',
          vacant: 'vacant',
          renovating: 'renovating',
        };
        const mapped = statusMap[newStatus] || 'occupied';
        setAllApartments((prev) =>
          prev.map((a) => (selectedIds.includes(a.id) ? { ...a, status: mapped } : a))
        );
        setSelectedIds([]);
        showToast(res.message || `Đã đổi trạng thái cho các căn hộ đã chọn!`);
      }
    } catch (err: any) {
      showToast(err?.message || 'Lỗi khi cập nhật trạng thái hàng loạt!', 'error');
    }
  };

  // Batch: Thu phí nhanh hàng loạt
  const handleBatchFee = async (feeStatus: 'paid' | 'unpaid') => {
    if (selectedIds.length === 0) return;
    try {
      const res = await api.batchApartmentsAction('update_fee', selectedIds, { feeStatus });
      if (res && res.success) {
        setAllApartments((prev) =>
          prev.map((a) => (selectedIds.includes(a.id) ? { ...a, feeStatus } : a))
        );
        setSelectedIds([]);
        showToast(res.message || `Đã cập nhật tình trạng phí cho ${selectedIds.length} căn hộ!`);
      }
    } catch (err: any) {
      showToast(err?.message || 'Lỗi khi cập nhật phí hàng loạt!', 'error');
    }
  };

  // Batch: Xóa hàng loạt
  const handleBatchDelete = async () => {
    if (selectedIds.length === 0) return;
    if (!window.confirm(`Bạn có chắc chắn muốn xóa ${selectedIds.length} căn hộ đã chọn khỏi hệ thống?`)) return;
    try {
      const res = await api.batchApartmentsAction('delete', selectedIds);
      if (res && res.success) {
        const idSet = new Set(selectedIds);
        setAllApartments((prev) => prev.filter((a) => !idSet.has(a.id)));
        setSelectedIds([]);
        showToast(res.message || `Đã xóa các căn hộ đã chọn!`);
      }
    } catch (err: any) {
      showToast(err?.message || 'Lỗi khi xóa hàng loạt!', 'error');
    }
  };

  return (
    <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 transition-all duration-300">
      {/* ================= TOP HEADER & NÚT CHUYỂN TRANG PHÂN HỆ ================= */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-2 border-b border-slate-200/80">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-sky-500">
            <Building2 className="w-4 h-4 text-sky-500" />
            <span>HỆ THỐNG KHỐI TÒA NHÀ & CĂN HỘ</span>
            <span className="text-slate-300">·</span>
            <span className="text-emerald-600 font-extrabold">DỮ LIỆU SEED & MIGRATION REAL-TIME</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1 flex items-center gap-3">
            <span>Khối / Tòa nhà & Căn hộ</span>
            <span className="text-xs font-semibold px-2.5 py-1 rounded-full bg-sky-100 text-sky-800 border border-sky-200 font-mono">
              {summary.totalBuildings || buildings.length} Tháp · {allApartments.length || summary.totalUnitsAll || 371} Căn
            </span>
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Tổng quan hạ tầng các tòa tháp, danh sách căn hộ theo dữ liệu thực từ cơ sở dữ liệu hệ thống.
          </p>
        </div>

        {/* NÚT CHUYỂN TRANG / CHUYỂN PHÂN HỆ KHỐI TÒA NHÀ */}
        <div className="flex flex-wrap items-center gap-2.5">
          {/* Nút chuyển chế độ xem: Bảng căn hộ vs Phối cảnh 3D */}
          <div className="flex items-center p-1 bg-slate-100 rounded-xl border border-slate-200/80">
            <button
              type="button"
              onClick={() => setActiveViewMode('table')}
              className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer ${
                activeViewMode === 'table'
                  ? 'bg-white text-sky-700 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <Building className="w-3.5 h-3.5" />
              <span>Danh Sách Căn Hộ</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveViewMode('3d')}
              className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer ${
                activeViewMode === '3d'
                  ? 'bg-white text-indigo-700 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <Box className="w-3.5 h-3.5" />
              <span>Phối Cảnh 3D</span>
            </button>
          </div>

          {/* Nút Chuyển trang sang Quản lý Khối Tòa nhà (Zone Manager) */}
          <button
            type="button"
            onClick={handleGoToZoneManager}
            className="px-4 py-2 rounded-xl text-xs font-bold bg-sky-600 hover:bg-sky-700 text-white shadow-xs hover:shadow-md transition-all flex items-center gap-2 cursor-pointer"
            title="Chuyển đến trang Khai báo / Quản lý Khối Tòa nhà (Block/Zone)"
          >
            <SlidersHorizontal className="w-4 h-4" />
            <span>Khai Báo & Quản Lý Khối (Zone Manager)</span>
            <ArrowRight className="w-3.5 h-3.5" />
          </button>

          {/* Nút Làm mới dữ liệu */}
          <button
            type="button"
            disabled={isLoadingBuildings || isLoadingApartments}
            onClick={() => {
              fetchBuildingsData();
              fetchAllApartments();
            }}
            className="p-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 shadow-xs transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
            title="Tải lại dữ liệu từ máy chủ"
          >
            <RefreshCw className={`w-4 h-4 ${(isLoadingBuildings || isLoadingApartments) ? 'animate-spin text-sky-600' : ''}`} />
          </button>
        </div>
      </div>

      {/* ================= KHỐI 3D DIGITAL TWIN (KHI CHỌN TAB 3D) ================= */}
      {activeViewMode === '3d' && (
        <div className="space-y-4 animate-in fade-in duration-300">
          <div className="flex items-center justify-between p-4 rounded-xl bg-gradient-to-r from-sky-50 to-indigo-50 border border-sky-100">
            <div className="flex items-center gap-3">
              <Box className="w-5 h-5 text-indigo-600" />
              <div>
                <h4 className="text-sm font-bold text-neutral-900">Bản sao kỹ thuật số 3D của Khối Tòa Nhà</h4>
                <p className="text-xs text-slate-600">Khám phá trạng thái phân tầng, cảm biến PCCC và thang máy theo thời gian thực.</p>
              </div>
            </div>
            <button
              type="button"
              onClick={() => setActiveViewMode('table')}
              className="text-xs font-bold text-sky-700 hover:underline flex items-center gap-1 cursor-pointer"
            >
              <span>Quay lại bảng căn hộ</span>
              <ChevronRight className="w-3.5 h-3.5" />
            </button>
          </div>

          <Building3DModel nightMode={false} highAlert={false} isCompact={false} />
        </div>
      )}

      {/* ================= BỘ CHỌN KHỐI TÒA NHÀ (BUILDING SELECTOR CHIPS) ================= */}
      <div className="flex items-center gap-2.5 flex-wrap">
        <span className="text-xs font-bold text-slate-500 uppercase tracking-wider font-mono mr-1">
          Lọc Khối Tòa:
        </span>
        <button
          type="button"
          onClick={() => handleFilterChange('building', 'all')}
          className={`px-4 py-1.5 rounded-full text-xs font-bold transition-all cursor-pointer ${
            selectedBuildingFilter === 'all'
              ? 'bg-sky-600 text-white shadow-xs'
              : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200'
          }`}
        >
          Tất cả ({buildings.length} Khối Tòa)
        </button>
        {Array.isArray(buildings) && buildings.map((b) => (
          <button
            key={b.id}
            type="button"
            onClick={() => handleFilterChange('building', b.code)}
            className={`px-4 py-1.5 rounded-full text-xs font-semibold transition-all cursor-pointer flex items-center gap-1.5 ${
              selectedBuildingFilter === b.code
                ? 'bg-sky-600 text-white shadow-xs font-bold'
                : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200'
            }`}
          >
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
            <span>{b.name}</span>
            <span className="text-[10px] opacity-75 font-mono">({b.totalUnits} căn)</span>
          </button>
        ))}
      </div>

      {/* ================= 4 THẺ THỐNG KÊ TỔNG QUAN TỪ DATABASE SEED ================= */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Card 1: Tổng căn hộ */}
        <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs hover:border-sky-300 transition-colors">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Tổng quy mô căn hộ</span>
            <div className="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center font-bold">
              <Building2 className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-3 text-2xl sm:text-3xl font-bold text-neutral-900 font-mono flex items-baseline gap-1.5">
            {summary.totalUnitsAll || allApartments.length || 371} <span className="text-xs font-normal text-slate-400 font-sans">căn hộ</span>
          </div>
          <div className="mt-2 text-xs text-slate-500 flex items-center gap-1.5">
            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />
            <span>Phân bổ trên {buildings.length || 4} khối tháp cao tầng</span>
          </div>
        </div>

        {/* Card 2: Đang ở (Tỷ lệ lấp đầy) */}
        <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs hover:border-emerald-300 transition-colors">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Căn hộ đang cư trú</span>
            <div className="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
              <CheckCircle2 className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-3 text-2xl sm:text-3xl font-bold text-emerald-600 font-mono flex items-baseline gap-1.5">
            {summary.occupiedUnitsAll} <span className="text-xs font-normal text-slate-400 font-sans">({summary.overallOccupancyRate}%)</span>
          </div>
          <div className="mt-2 text-xs text-emerald-600 font-medium">
            Tỷ lệ lấp đầy đạt mục tiêu vận hành
          </div>
        </div>

        {/* Card 3: Căn hộ trống */}
        <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs hover:border-amber-300 transition-colors">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Căn hộ trống / Chờ bàn giao</span>
            <div className="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
              <Home className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-3 text-2xl sm:text-3xl font-bold text-amber-500 font-mono flex items-baseline gap-1.5">
            {summary.vacantUnitsAll} <span className="text-xs font-normal text-slate-400 font-sans">căn</span>
          </div>
          <div className="mt-2 text-xs text-amber-600 font-medium">
            Sẵn sàng bàn giao hoặc cho thuê
          </div>
        </div>

        {/* Card 4: Đang thi công nội thất */}
        <div className="bg-white rounded-2xl p-5 border border-slate-200/80 shadow-xs hover:border-purple-300 transition-colors">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Đang thi công / Bảo trì</span>
            <div className="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
              <Wrench className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-3 text-2xl sm:text-3xl font-bold text-purple-600 font-mono flex items-baseline gap-1.5">
            {summary.renovatingUnitsAll} <span className="text-xs font-normal text-slate-400 font-sans">căn</span>
          </div>
          <div className="mt-2 text-xs text-purple-600 font-medium">
            Đang nghiệm thu kỹ thuật an toàn
          </div>
        </div>
      </div>

      {/* ================= CHI TIẾT CÁC KHỐI TÒA NHÀ (BUILDINGS CARDS) ================= */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
        {Array.isArray(buildings) && buildings.map((building) => {
          const occupancyRate = building.totalUnits > 0
            ? ((building.occupiedUnits / building.totalUnits) * 100).toFixed(1)
            : '0.0';
          const isSelected = selectedBuildingFilter === building.code;

          return (
            <div
              key={building.id}
              className={`bg-white rounded-2xl border p-5 shadow-xs hover:shadow-md transition-all flex flex-col justify-between ${
                isSelected ? 'border-sky-500 ring-2 ring-sky-500/20 shadow-md' : 'border-slate-200/80'
              }`}
            >
              <div>
                <div className="flex items-center justify-between pb-3">
                  <div className="flex items-center gap-2.5">
                    <div className="w-9 h-9 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center font-bold text-sm shrink-0">
                      {building.code.replace('BLOCK_', '')}
                    </div>
                    <div>
                      <h3 className="font-bold text-slate-900 text-sm leading-tight">{building.name}</h3>
                      <p className="text-[11px] text-slate-500 mt-0.5">
                        {building.floors} Tầng nổi · {building.basements} Hầm · {building.elevators} Thang
                      </p>
                    </div>
                  </div>
                  <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full border ${
                    building.status === 'ACTIVE'
                      ? 'bg-emerald-50 text-emerald-600 border-emerald-200'
                      : 'bg-amber-50 text-amber-600 border-amber-200'
                  }`}>
                    {building.status === 'ACTIVE' ? 'Hoạt động' : 'Bảo trì'}
                  </span>
                </div>

                {/* Thanh tỷ lệ lấp đầy */}
                <div className="mt-3">
                  <div className="flex justify-between text-xs text-slate-600 font-medium mb-1.5">
                    <span>Lấp đầy: <strong>{building.occupiedUnits}</strong>/{building.totalUnits} căn</span>
                    <span className="font-bold text-neutral-900 font-mono">{occupancyRate}%</span>
                  </div>
                  <div className="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-sky-600 rounded-full transition-all duration-500"
                      style={{ width: `${Math.min(100, Number(occupancyRate))}%` }}
                    />
                  </div>
                </div>

                {/* Trạng thái Kỹ thuật vận hành */}
                <div className="mt-4 grid grid-cols-3 gap-1.5 text-center">
                  <div className="p-2 rounded-xl bg-slate-50 border border-slate-100">
                    <div className="flex items-center justify-center gap-1 text-emerald-600 font-medium text-[11px]">
                      <CheckCircle2 className="w-3 h-3 text-emerald-600" /> PCCC
                    </div>
                    <span className="text-[10px] text-slate-500 font-medium block mt-0.5">An toàn</span>
                  </div>
                  <div className="p-2 rounded-xl bg-slate-50 border border-slate-100">
                    <div className="flex items-center justify-center gap-1 text-amber-600 font-medium text-[11px]">
                      <Zap className="w-3 h-3 text-amber-500" /> Điện
                    </div>
                    <span className="text-[10px] text-slate-500 font-medium block mt-0.5">Ổn định</span>
                  </div>
                  <div className="p-2 rounded-xl bg-slate-50 border border-slate-100">
                    <div className="flex items-center justify-center gap-1 text-sky-600 font-medium text-[11px]">
                      <Droplet className="w-3 h-3 text-sky-600" /> Nước
                    </div>
                    <span className="text-[10px] text-slate-500 font-medium block mt-0.5">Áp lực tốt</span>
                  </div>
                </div>
              </div>

              {/* Action buttons on card: Lọc căn hộ & Xem chi tiết */}
              <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                <button
                  type="button"
                  onClick={() => handleFilterChange('building', isSelected ? 'all' : building.code)}
                  className={`text-[11px] font-bold px-2.5 py-1 rounded-lg border transition-all cursor-pointer ${
                    isSelected
                      ? 'bg-sky-600 text-white border-sky-600'
                      : 'bg-white hover:bg-slate-50 text-sky-700 border-sky-200'
                  }`}
                >
                  {isSelected ? 'Đang lọc' : 'Lọc căn hộ'}
                </button>

                <button
                  type="button"
                  onClick={handleGoToZoneManager}
                  className="text-[11px] text-slate-500 hover:text-sky-600 flex items-center gap-1 font-medium cursor-pointer"
                  title="Chuyển sang cấu hình khối này"
                >
                  <span>Cấu hình</span>
                  <ExternalLink className="w-3 h-3" />
                </button>
              </div>
            </div>
          );
        })}
      </div>

      {/* ================= BẢNG DANH SÁCH CĂN HỘ (CÓ 7 CHỨC NĂNG QUẢN LÝ ĐẦY ĐỦ) ================= */}
      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        {/* Toolbar: Tìm kiếm, Lọc trạng thái, Đếm số lượng & 7 Nút Chức Năng */}
        <div className="p-4 border-b border-slate-100 flex flex-col xl:flex-row gap-4 items-start xl:items-center justify-between bg-white">
          <div className="flex flex-wrap items-center gap-2.5 w-full xl:w-auto">
            {/* Input tìm kiếm */}
            <div className="relative w-full sm:w-72">
              <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={searchInputValue}
                onChange={(e) => handleSearchChange(e.target.value)}
                placeholder="Tìm mã căn, tên chủ hộ, sđt..."
                className="w-full pl-9 pr-4 py-2 text-xs bg-white border border-slate-200 rounded-full focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 text-slate-700 font-medium"
              />
            </div>

            {/* Select trạng thái */}
            <select
              value={statusFilter}
              onChange={(e) => handleFilterChange('status', e.target.value)}
              className="px-4 py-2 text-xs bg-white border border-slate-200 rounded-full focus:outline-none focus:ring-2 focus:ring-sky-500/20 text-slate-700 font-medium cursor-pointer"
            >
              <option value="all">Mọi trạng thái cư trú</option>
              <option value="occupied">Đang ở (Occupied)</option>
              <option value="vacant">Trống / Chờ bàn giao</option>
              <option value="renovating">Đang thi công / Bảo trì</option>
            </select>

            {/* Select số lượng mỗi trang */}
            <div className="flex items-center gap-1.5 text-xs text-slate-500 font-medium">
              <span>Hiển thị:</span>
              <select
                value={perPage}
                onChange={(e) => {
                  setPerPage(Number(e.target.value));
                  setCurrentPage(1);
                }}
                className="px-2.5 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-lg text-slate-700 font-bold cursor-pointer"
              >
                <option value={10}>10 căn/trang</option>
                <option value={20}>20 căn/trang</option>
                <option value={50}>50 căn/trang</option>
                <option value={100}>100 căn/trang</option>
                <option value={-1}>Tất cả ({totalFiltered} căn)</option>
              </select>
            </div>
          </div>

          {/* NHÓM 7 NÚT CHỨC NĂNG CHÍNH: THÊM, TẢI VỀ, TẢI LÊN, ĐẶC BIỆT */}
          <div className="flex flex-wrap items-center gap-2 w-full xl:w-auto justify-between xl:justify-end">
            <div className="flex items-center gap-2 flex-wrap">
              {/* Chức năng 1: THÊM CĂN HỘ */}
              <button
                type="button"
                onClick={() => setIsAddModalOpen(true)}
                className="px-3.5 py-2 rounded-xl text-xs font-bold bg-sky-600 hover:bg-sky-700 text-white shadow-xs hover:shadow-md transition-all flex items-center gap-1.5 cursor-pointer"
                title="Thêm căn hộ mới vào cơ sở dữ liệu"
              >
                <Plus className="w-4 h-4" />
                <span>Thêm Căn Hộ</span>
              </button>

              {/* Chức năng 5: TẢI VỀ (EXPORT EXCEL/CSV) */}
              <button
                type="button"
                onClick={handleExportCSV}
                className="px-3 py-2 rounded-xl text-xs font-semibold bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-xs transition-all flex items-center gap-1.5 cursor-pointer"
                title="Tải về danh sách căn hộ dạng CSV/Excel"
              >
                <Download className="w-3.5 h-3.5 text-emerald-600" />
                <span>Tải Về (Excel)</span>
              </button>

              {/* Chức năng 6: TẢI LÊN (IMPORT EXCEL/CSV) */}
              <button
                type="button"
                onClick={() => setIsImportModalOpen(true)}
                className="px-3 py-2 rounded-xl text-xs font-semibold bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-xs transition-all flex items-center gap-1.5 cursor-pointer"
                title="Tải lên danh sách căn hộ từ file CSV"
              >
                <Upload className="w-3.5 h-3.5 text-indigo-600" />
                <span>Tải Lên (Import)</span>
              </button>

              {/* Chức năng 7: ĐẶC BIỆT (SPECIAL ACTIONS MENU) */}
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setIsSpecialMenuOpen((prev) => !prev)}
                  className={`px-3 py-2 rounded-xl text-xs font-semibold transition-all flex items-center gap-1.5 cursor-pointer border ${
                    selectedIds.length > 0 || isSpecialMenuOpen
                      ? 'bg-amber-500 text-white border-amber-500 shadow-sm'
                      : 'bg-white hover:bg-slate-50 text-slate-700 border-slate-200 shadow-xs'
                  }`}
                  title="Thao tác đặc biệt & xử lý hàng loạt"
                >
                  <Sparkles className="w-3.5 h-3.5" />
                  <span>Đặc Biệt {selectedIds.length > 0 ? `(${selectedIds.length})` : ''}</span>
                </button>

                {isSpecialMenuOpen && (
                  <div className="absolute right-0 top-full mt-2 w-64 bg-white rounded-2xl shadow-xl border border-slate-200 p-2 z-50 animate-in fade-in zoom-in-95 duration-150">
                    <div className="px-3 py-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider font-mono">
                      Thao tác đặc biệt ({selectedIds.length} căn đã chọn)
                    </div>
                    {selectedIds.length === 0 ? (
                      <div className="p-3 text-center text-xs text-slate-500">
                        Vui lòng tích chọn ít nhất 1 căn hộ trong bảng để thao tác.
                      </div>
                    ) : (
                      <div className="space-y-1">
                        <button
                          type="button"
                          onClick={() => {
                            handleBatchStatus('occupied');
                            setIsSpecialMenuOpen(false);
                          }}
                          className="w-full text-left px-3 py-2 text-xs rounded-xl hover:bg-slate-50 flex items-center gap-2 text-slate-700 font-medium"
                        >
                          <Home className="w-3.5 h-3.5 text-emerald-600" />
                          <span>Chuyển sang "Đang ở"</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            handleBatchStatus('vacant');
                            setIsSpecialMenuOpen(false);
                          }}
                          className="w-full text-left px-3 py-2 text-xs rounded-xl hover:bg-slate-50 flex items-center gap-2 text-slate-700 font-medium"
                        >
                          <Home className="w-3.5 h-3.5 text-amber-500" />
                          <span>Chuyển sang "Trống / Chờ bàn giao"</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            handleBatchFee('paid');
                            setIsSpecialMenuOpen(false);
                          }}
                          className="w-full text-left px-3 py-2 text-xs rounded-xl hover:bg-slate-50 flex items-center gap-2 text-slate-700 font-medium"
                        >
                          <CheckCircle2 className="w-3.5 h-3.5 text-sky-600" />
                          <span>Đánh dấu "Đã thu phí"</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            handleBatchDelete();
                            setIsSpecialMenuOpen(false);
                          }}
                          className="w-full text-left px-3 py-2 text-xs rounded-xl hover:bg-rose-50 flex items-center gap-2 text-rose-600 font-bold"
                        >
                          <Trash2 className="w-3.5 h-3.5 text-rose-500" />
                          <span>Xóa {selectedIds.length} căn đã chọn</span>
                        </button>
                      </div>
                    )}
                  </div>
                )}
              </div>
            </div>

            {/* Trạng thái Load nền */}
            <div className="flex items-center gap-2 text-xs">
              {isBackgroundSyncing ? (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-sky-50 text-sky-700 border border-sky-200 text-[11px] font-semibold animate-pulse shadow-2xs">
                  <RefreshCw className="w-3 h-3 animate-spin text-sky-600" />
                  <span>Đang tải ngầm dữ liệu...</span>
                </span>
              ) : (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-medium" title={lastSyncTime ? `Lần đồng bộ ngầm gần nhất: ${lastSyncTime}` : 'Đồng bộ thời gian thực'}>
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping" />
                  <span>Load nền liên tục</span>
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Thanh xung nhịp nạp dữ liệu dưới nền (Background Pulse Bar) */}
        <div className="h-0.5 w-full bg-slate-100 overflow-hidden relative">
          {isBackgroundSyncing && (
            <div className="h-full bg-gradient-to-r from-sky-400 via-indigo-500 to-sky-400 animate-pulse w-full" />
          )}
        </div>

        {/* Table Content */}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50/70 border-b border-slate-100 text-slate-500 uppercase tracking-wider font-semibold text-[11px]">
              <tr>
                <th className="py-3.5 px-4 w-10 text-center">
                  <input
                    type="checkbox"
                    checked={isAllPageSelected}
                    onChange={toggleSelectAll}
                    className="w-4 h-4 rounded text-sky-600 focus:ring-sky-500 cursor-pointer"
                    title="Chọn tất cả căn hộ trên trang này"
                  />
                </th>
                <th className="py-3.5 px-4">Mã Căn hộ</th>
                <th className="py-3.5 px-4">Khối Tòa / Tầng</th>
                <th className="py-3.5 px-4">Loại Căn</th>
                <th className="py-3.5 px-4">Diện tích</th>
                <th className="py-3.5 px-4">Chủ hộ / Đại diện</th>
                <th className="py-3.5 px-4">Số điện thoại</th>
                <th className="py-3.5 px-4">Trạng thái cư trú</th>
                <th className="py-3.5 px-4">Phí dịch vụ</th>
                <th className="py-3.5 px-4 text-center">Hành động</th>
              </tr>
            </thead>
            <tbody className={`divide-y divide-slate-100 text-slate-700 transition-opacity duration-200 ${isBackgroundSyncing ? 'opacity-90' : 'opacity-100'}`}>
              {isLoadingApartments && allApartments.length === 0 ? (
                <tr>
                  <td colSpan={10} className="py-12 text-center text-slate-400">
                    <div className="inline-flex items-center gap-2 text-sm font-medium text-sky-600">
                      <RefreshCw className="w-4 h-4 animate-spin" />
                      <span>Đang nạp toàn bộ dữ liệu căn hộ từ hệ thống...</span>
                    </div>
                  </td>
                </tr>
              ) : displayedApartments.length === 0 ? (
                <tr>
                  <td colSpan={10} className="py-12 text-center text-slate-400">
                    <div className="max-w-sm mx-auto space-y-2">
                      <p className="font-semibold text-slate-700">Không tìm thấy căn hộ nào phù hợp</p>
                      <p className="text-xs text-slate-400">Thử thay đổi từ khóa tìm kiếm hoặc chọn lại khối tòa nhà.</p>
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedBuildingFilter('all');
                          setStatusFilter('all');
                          setSearchInputValue('');
                          setCurrentPage(1);
                        }}
                        className="px-3.5 py-1.5 rounded-lg bg-sky-50 text-sky-700 text-xs font-bold hover:bg-sky-100 transition-colors"
                      >
                        Đặt lại bộ lọc
                      </button>
                    </div>
                  </td>
                </tr>
              ) : (
                Array.isArray(displayedApartments) && displayedApartments.map((apt) => {
                  const isSelected = selectedIds.includes(apt.id);
                  return (
                    <tr
                      key={apt.id}
                      className={`hover:bg-slate-50/80 transition-colors ${
                        isSelected ? 'bg-sky-50/50' : ''
                      }`}
                    >
                      {/* Checkbox chọn hàng loạt */}
                      <td className="py-3.5 px-4 text-center">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => toggleSelectOne(apt.id)}
                          className="w-4 h-4 rounded text-sky-600 focus:ring-sky-500 cursor-pointer"
                        />
                      </td>

                      {/* Mã Căn */}
                      <td className="py-3.5 px-4 font-bold text-neutral-900 font-mono">
                        <span className="px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200">
                          {apt.unitCode}
                        </span>
                      </td>

                      {/* Tòa / Tầng */}
                      <td className="py-3.5 px-4 text-slate-700">
                        <div className="font-medium">{apt.buildingName || apt.buildingCode}</div>
                        <div className="text-[11px] text-slate-400 font-mono">Tầng {apt.floor}</div>
                      </td>

                      {/* Loại căn */}
                      <td className="py-3.5 px-4 text-slate-600 font-medium">
                        {apt.type}
                      </td>

                      {/* Diện tích */}
                      <td className="py-3.5 px-4 font-mono font-semibold text-slate-700">
                        {apt.area} m²
                      </td>

                      {/* Chủ hộ */}
                      <td className={`py-3.5 px-4 ${apt.ownerName.includes('bàn giao') || apt.ownerName.includes('nhận nhà') ? 'text-slate-500 font-normal' : 'font-semibold text-neutral-900'}`}>
                        {apt.ownerName}
                      </td>

                      {/* Số điện thoại */}
                      <td className="py-3.5 px-4 font-mono text-slate-500">
                        {apt.ownerPhone}
                      </td>

                      {/* Trạng thái cư trú */}
                      <td className="py-3.5 px-4">
                        {apt.status === 'occupied' && (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-300">
                            <Home className="w-3.5 h-3.5 text-emerald-600" /> Đang ở
                          </span>
                        )}
                        {apt.status === 'vacant' && (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-300">
                            <Home className="w-3.5 h-3.5 text-amber-600" /> Trống
                          </span>
                        )}
                        {apt.status === 'renovating' && (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-purple-50 text-purple-700 border border-purple-300">
                            <Wrench className="w-3.5 h-3.5 text-purple-600" /> Thi công
                          </span>
                        )}
                      </td>

                      {/* Phí dịch vụ */}
                      <td className="py-3.5 px-4">
                        {apt.feeStatus === 'paid' ? (
                          <span className="text-xs font-semibold text-emerald-600 flex items-center gap-1">
                            <CheckCircle2 className="w-3 h-3 text-emerald-500" /> Đã thu
                          </span>
                        ) : (
                          <span className="text-xs font-semibold text-rose-600 flex items-center gap-1">
                            <Clock className="w-3 h-3 text-rose-500" /> Chưa thu
                          </span>
                        )}
                      </td>

                      {/* CÁC NÚT HÀNH ĐỘNG: XEM (4), SỬA (3), XÓA (2) */}
                      <td className="py-3.5 px-4 text-center">
                        <div className="flex items-center justify-center gap-1.5">
                          {/* Chức năng 4: XEM CHI TIẾT */}
                          <button
                            type="button"
                            onClick={() => handleOpenViewDetail(apt)}
                            className="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-sky-50 text-slate-600 hover:text-sky-600 transition-colors cursor-pointer"
                            title="Xem chi tiết căn hộ"
                          >
                            <Eye className="w-3.5 h-3.5" />
                          </button>

                          {/* Chức năng 3: SỬA THÔNG TIN */}
                          <button
                            type="button"
                            onClick={() => handleOpenEdit(apt)}
                            className="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-amber-50 text-slate-600 hover:text-amber-600 transition-colors cursor-pointer"
                            title="Chỉnh sửa thông tin căn hộ"
                          >
                            <Pencil className="w-3.5 h-3.5" />
                          </button>

                          {/* Chức năng 2: XÓA CĂN HỘ */}
                          <button
                            type="button"
                            onClick={() => setDeletingApartment(apt)}
                            className="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-rose-50 text-slate-600 hover:text-rose-600 transition-colors cursor-pointer"
                            title="Xóa căn hộ này"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
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

        {/* ================= NÚT CHUYỂN TRANG (FULL PAGINATION CONTROLS) ================= */}
        {totalPages > 1 && !isShowAll ? (
          <div className="p-4 border-t border-slate-100 bg-white flex flex-col sm:flex-row items-center justify-between gap-3 select-none">
            {/* Thống kê trang hiện tại */}
            <div className="text-xs text-slate-500 font-medium flex items-center gap-2">
              <span>
                Trang <strong>{activePage}</strong> trên <strong>{totalPages}</strong> (Tổng <strong>{totalFiltered}</strong> căn hộ)
              </span>
            </div>

            {/* Các nút chuyển trang */}
            <div className="flex items-center gap-1">
              {/* Trang đầu */}
              <button
                type="button"
                onClick={() => goToPage(1)}
                disabled={activePage === 1}
                className="p-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-600 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                title="Về trang đầu"
              >
                <ChevronsLeft className="w-3.5 h-3.5" />
              </button>

              {/* Trang trước */}
              <button
                type="button"
                onClick={() => goToPage(activePage - 1)}
                disabled={activePage === 1}
                className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1 transition-all"
              >
                <ChevronLeft className="w-3.5 h-3.5" />
                <span>Trước</span>
              </button>

              {/* Dải số trang */}
              <div className="flex items-center gap-1 mx-1">
                {pageNumbers.map((num, idx) => {
                  if (num < 0) {
                    return (
                      <span key={`ellipsis-${idx}`} className="px-2 py-1 text-slate-400 text-xs">
                        ...
                      </span>
                    );
                  }

                  const isActive = num === activePage;
                  return (
                    <button
                      key={`page-${num}`}
                      type="button"
                      onClick={() => goToPage(num)}
                      className={`min-w-[32px] h-8 rounded-lg text-xs font-bold transition-all disabled:cursor-not-allowed ${
                        isActive
                          ? 'bg-sky-600 text-white shadow-xs'
                          : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 disabled:opacity-60'
                      }`}
                    >
                      {num}
                    </button>
                  );
                })}
              </div>

              {/* Trang sau */}
              <button
                type="button"
                onClick={() => goToPage(activePage + 1)}
                disabled={activePage === totalPages}
                className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1 transition-all"
              >
                <span>Sau</span>
                <ChevronRight className="w-3.5 h-3.5" />
              </button>

              {/* Trang cuối */}
              <button
                type="button"
                onClick={() => goToPage(totalPages)}
                disabled={activePage === totalPages}
                className="p-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-600 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                title="Đến trang cuối"
              >
                <ChevronsRight className="w-3.5 h-3.5" />
              </button>
            </div>
          </div>
        ) : isShowAll && totalFiltered > 0 ? (
          <div className="p-3 text-center text-xs text-slate-500 font-medium bg-slate-50/80 border-t border-slate-100 flex items-center justify-center gap-2">
            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
            <span>Đang hiển thị toàn bộ <strong>{totalFiltered}</strong> căn hộ trên một trang cuộn mượt mà.</span>
          </div>
        ) : null}
      </div>

      {/* ================= FLOATING BATCH ACTIONS BAR (CHỨC NĂNG ĐẶC BIỆT) ================= */}
      {selectedIds.length > 0 && (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-40 bg-slate-900/95 backdrop-blur-md text-white px-5 py-3 rounded-2xl shadow-2xl border border-slate-700/80 flex flex-wrap items-center gap-4 animate-in slide-in-from-bottom-5 duration-200">
          <div className="flex items-center gap-2">
            <span className="flex h-2.5 w-2.5 relative">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-sky-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-sky-500"></span>
            </span>
            <span className="text-xs font-bold font-mono">
              Đã chọn {selectedIds.length} căn hộ
            </span>
          </div>

          <div className="h-4 w-px bg-slate-700 hidden sm:block" />

          <div className="flex items-center gap-2 flex-wrap">
            <button
              type="button"
              onClick={() => handleBatchStatus('occupied')}
              className="px-3 py-1.5 rounded-xl bg-emerald-600/90 hover:bg-emerald-600 text-xs font-semibold text-white transition-all flex items-center gap-1.5 shadow-xs cursor-pointer"
            >
              <Home className="w-3.5 h-3.5" />
              <span>Gắn "Đang ở"</span>
            </button>

            <button
              type="button"
              onClick={() => handleBatchStatus('vacant')}
              className="px-3 py-1.5 rounded-xl bg-amber-600/90 hover:bg-amber-600 text-xs font-semibold text-white transition-all flex items-center gap-1.5 shadow-xs cursor-pointer"
            >
              <Home className="w-3.5 h-3.5" />
              <span>Gắn "Trống"</span>
            </button>

            <button
              type="button"
              onClick={() => handleBatchFee('paid')}
              className="px-3 py-1.5 rounded-xl bg-sky-600/90 hover:bg-sky-600 text-xs font-semibold text-white transition-all flex items-center gap-1.5 shadow-xs cursor-pointer"
            >
              <CheckCircle2 className="w-3.5 h-3.5" />
              <span>Đã thu phí</span>
            </button>

            <button
              type="button"
              onClick={handleBatchDelete}
              className="px-3 py-1.5 rounded-xl bg-rose-600/90 hover:bg-rose-600 text-xs font-semibold text-white transition-all flex items-center gap-1.5 shadow-xs cursor-pointer"
            >
              <Trash2 className="w-3.5 h-3.5" />
              <span>Xóa ({selectedIds.length})</span>
            </button>

            <button
              type="button"
              onClick={() => setSelectedIds([])}
              className="p-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-all cursor-pointer ml-1"
              title="Bỏ chọn tất cả"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        </div>
      )}

      {/* ================= MODAL 1: THÊM CĂN HỘ MỚI ================= */}
      {isAddModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <div className="p-2 rounded-xl bg-sky-100 text-sky-700">
                  <Plus className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-slate-900">Thêm Căn Hộ Mới</h3>
                  <p className="text-xs text-slate-500">Khai báo căn hộ mới vào cơ sở dữ liệu hệ thống</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setIsAddModalOpen(false)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveNewApartment} className="p-6 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Mã Căn Hộ <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="VD: A-1505"
                    value={addForm.unitCode}
                    onChange={(e) => setAddForm({ ...addForm, unitCode: e.target.value })}
                    className="w-full px-3 py-2 text-xs font-mono font-bold rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Khối Tòa Nhà</label>
                  <select
                    value={addForm.buildingCode}
                    onChange={(e) => setAddForm({ ...addForm, buildingCode: e.target.value })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 bg-white"
                  >
                    <option value="BLOCK_A">Ruby Tower (Khối A)</option>
                    <option value="BLOCK_B">Sapphire Tower (Khối B)</option>
                    <option value="BLOCK_C">Emerald Tower (Khối C)</option>
                    <option value="BLOCK_D">Diamond Tower (Khối D)</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Tầng</label>
                  <input
                    type="number"
                    min={1}
                    max={50}
                    value={addForm.floor}
                    onChange={(e) => setAddForm({ ...addForm, floor: Number(e.target.value) || 1 })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Diện tích (m²)</label>
                  <input
                    type="number"
                    step="0.5"
                    value={addForm.area}
                    onChange={(e) => setAddForm({ ...addForm, area: Number(e.target.value) || 50 })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Loại Căn</label>
                  <select
                    value={addForm.type}
                    onChange={(e) => setAddForm({ ...addForm, type: e.target.value })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 bg-white"
                  >
                    <option value="1 Phòng ngủ">1 Phòng ngủ</option>
                    <option value="2 Phòng ngủ">2 Phòng ngủ</option>
                    <option value="3 Phòng ngủ">3 Phòng ngủ</option>
                    <option value="3 Phòng ngủ (Góc)">3 Phòng ngủ (Góc)</option>
                    <option value="Duplex">Duplex</option>
                    <option value="Penthouse">Penthouse</option>
                    <option value="Shophouse">Shophouse</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Chủ Hộ / Đại diện</label>
                  <input
                    type="text"
                    placeholder="VD: Nguyễn Văn A"
                    value={addForm.ownerName}
                    onChange={(e) => setAddForm({ ...addForm, ownerName: e.target.value })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Số Điện Thoại</label>
                  <input
                    type="tel"
                    placeholder="090xxxxxxx"
                    value={addForm.ownerPhone}
                    onChange={(e) => setAddForm({ ...addForm, ownerPhone: e.target.value })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Trạng Thái Cư Trú</label>
                  <select
                    value={addForm.status}
                    onChange={(e) => setAddForm({ ...addForm, status: e.target.value as any })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 bg-white"
                  >
                    <option value="occupied">Đang ở</option>
                    <option value="vacant">Trống / Đang bán</option>
                    <option value="renovating">Đang thi công hoàn thiện</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Tình Trạng Phí</label>
                  <select
                    value={addForm.feeStatus}
                    onChange={(e) => setAddForm({ ...addForm, feeStatus: e.target.value as any })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 bg-white"
                  >
                    <option value="paid">Đã thanh toán đủ</option>
                    <option value="unpaid">Chưa thanh toán</option>
                  </select>
                </div>
              </div>

              <div className="pt-4 border-t border-slate-200 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setIsAddModalOpen(false)}
                  className="px-4 py-2 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold shadow-xs hover:shadow-md transition-all flex items-center gap-1.5 cursor-pointer"
                >
                  <Check className="w-4 h-4" />
                  <span>Lưu Căn Hộ</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ================= MODAL 2: SỬA CĂN HỘ ================= */}
      {editingApartment && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-lg overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <div className="p-2 rounded-xl bg-amber-100 text-amber-700">
                  <Pencil className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-slate-900">
                    Chỉnh Sửa Căn Hộ {editingApartment.unitCode}
                  </h3>
                  <p className="text-xs text-slate-500">Cập nhật thông tin chủ hộ, diện tích và trạng thái cư trú</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setEditingApartment(null)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveEditedApartment} className="p-6 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Mã Căn</label>
                  <input
                    type="text"
                    required
                    value={editForm.unitCode}
                    onChange={(e) => setEditForm({ ...editForm, unitCode: e.target.value })}
                    className="w-full px-3 py-2 text-xs font-mono font-bold rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Khối Tòa Nhà</label>
                  <select
                    value={editForm.buildingCode}
                    onChange={(e) => setEditForm({ ...editForm, buildingCode: e.target.value })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white"
                  >
                    <option value="BLOCK_A">Ruby Tower (Khối A)</option>
                    <option value="BLOCK_B">Sapphire Tower (Khối B)</option>
                    <option value="BLOCK_C">Emerald Tower (Khối C)</option>
                    <option value="BLOCK_D">Diamond Tower (Khối D)</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Tầng</label>
                  <input
                    type="number"
                    min={1}
                    max={50}
                    value={editForm.floor}
                    onChange={(e) => setEditForm({ ...editForm, floor: Number(e.target.value) || 1 })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Diện tích (m²)</label>
                  <input
                    type="number"
                    step="0.5"
                    value={editForm.area}
                    onChange={(e) => setEditForm({ ...editForm, area: Number(e.target.value) || 50 })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Loại Căn</label>
                  <select
                    value={editForm.type}
                    onChange={(e) => setEditForm({ ...editForm, type: e.target.value })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white"
                  >
                    <option value="1 Phòng ngủ">1 Phòng ngủ</option>
                    <option value="2 Phòng ngủ">2 Phòng ngủ</option>
                    <option value="3 Phòng ngủ">3 Phòng ngủ</option>
                    <option value="3 Phòng ngủ (Góc)">3 Phòng ngủ (Góc)</option>
                    <option value="Duplex">Duplex</option>
                    <option value="Penthouse">Penthouse</option>
                    <option value="Shophouse">Shophouse</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Chủ Hộ / Đại diện</label>
                  <input
                    type="text"
                    value={editForm.ownerName}
                    onChange={(e) => setEditForm({ ...editForm, ownerName: e.target.value })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Số Điện Thoại</label>
                  <input
                    type="tel"
                    value={editForm.ownerPhone}
                    onChange={(e) => setEditForm({ ...editForm, ownerPhone: e.target.value })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Trạng Thái Cư Trú</label>
                  <select
                    value={editForm.status}
                    onChange={(e) => setEditForm({ ...editForm, status: e.target.value as any })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white"
                  >
                    <option value="occupied">Đang ở</option>
                    <option value="vacant">Trống / Đang bán</option>
                    <option value="renovating">Đang thi công hoàn thiện</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Tình Trạng Phí</label>
                  <select
                    value={editForm.feeStatus}
                    onChange={(e) => setEditForm({ ...editForm, feeStatus: e.target.value as any })}
                    className="w-full px-3 py-2 text-xs rounded-xl border border-slate-300 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 bg-white"
                  >
                    <option value="paid">Đã thanh toán đủ</option>
                    <option value="unpaid">Chưa thanh toán</option>
                  </select>
                </div>
              </div>

              <div className="pt-4 border-t border-slate-200 flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setEditingApartment(null)}
                  className="px-4 py-2 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold shadow-xs hover:shadow-md transition-all flex items-center gap-1.5 cursor-pointer"
                >
                  <Check className="w-4 h-4" />
                  <span>Lưu Thay Đổi</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ================= MODAL 3: XEM CHI TIẾT CĂN HỘ ================= */}
      {viewingApartment && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl max-h-[90vh] overflow-y-auto animate-in zoom-in-95 duration-200">
            <div className="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between sticky top-0 bg-white/95 backdrop-blur-sm z-10">
              <div className="flex items-center gap-3">
                <div className="p-2.5 rounded-xl bg-sky-100 text-sky-700 font-mono font-black text-sm">
                  {viewingApartment.unitCode}
                </div>
                <div>
                  <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                    <span>Chi Tiết Kỹ Thuật & Cư Trú</span>
                    <span className={`text-[11px] font-semibold px-2 py-0.5 rounded-full ${
                      viewingApartment.status === 'occupied'
                        ? 'bg-emerald-100 text-emerald-800'
                        : viewingApartment.status === 'renovating'
                        ? 'bg-amber-100 text-amber-800'
                        : 'bg-slate-100 text-slate-700'
                    }`}>
                      {viewingApartment.status === 'occupied' ? 'Đang ở' : viewingApartment.status === 'renovating' ? 'Thi công' : 'Trống'}
                    </span>
                  </h3>
                  <p className="text-xs text-slate-500">
                    {viewingApartment.buildingName || viewingApartment.buildingCode} · Tầng {viewingApartment.floor} · {viewingApartment.type}
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setViewingApartment(null)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-6 space-y-6">
              {isLoadingDetail ? (
                <div className="py-12 text-center text-slate-400">
                  <RefreshCw className="w-6 h-6 animate-spin mx-auto text-sky-600 mb-2" />
                  <p className="text-xs font-medium">Đang tải toàn bộ thông số kỹ thuật căn hộ...</p>
                </div>
              ) : (
                <>
                  {/* Grid thông tin chính */}
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                      <div className="text-[11px] text-slate-400 font-medium">Diện tích tim tường</div>
                      <div className="text-sm font-bold text-slate-900 mt-0.5">{viewingApartment.area} m²</div>
                    </div>
                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                      <div className="text-[11px] text-slate-400 font-medium">Chủ hộ hiện tại</div>
                      <div className="text-sm font-bold text-slate-900 mt-0.5 truncate">{viewingApartment.ownerName}</div>
                    </div>
                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                      <div className="text-[11px] text-slate-400 font-medium">Điện thoại liên hệ</div>
                      <div className="text-sm font-bold text-slate-900 mt-0.5">{viewingApartment.ownerPhone || '---'}</div>
                    </div>
                    <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                      <div className="text-[11px] text-slate-400 font-medium">Tình trạng phí dịch vụ</div>
                      <div className={`text-sm font-bold mt-0.5 ${viewingApartment.feeStatus === 'paid' ? 'text-emerald-600' : 'text-rose-600'}`}>
                        {viewingApartment.feeStatus === 'paid' ? 'Đã hoàn tất' : 'Chưa đóng'}
                      </div>
                    </div>
                  </div>

                  {/* Hạ tầng cảm biến IoT & Kỹ thuật */}
                  <div>
                    <h4 className="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                      <ShieldCheck className="w-4 h-4 text-emerald-600" />
                      <span>Hạ Tầng Kỹ Thuật & Cảm Biến An Toàn</span>
                    </h4>
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                      <div className="p-3.5 rounded-xl border border-slate-200/80 bg-slate-50/50 flex items-center gap-3">
                        <div className="p-2 rounded-lg bg-emerald-100 text-emerald-700">
                          <ShieldCheck className="w-4 h-4" />
                        </div>
                        <div>
                          <div className="text-[11px] text-slate-500 font-medium">Hệ Thống PCCC</div>
                          <div className="text-xs font-bold text-emerald-700">Đạt chuẩn 100%</div>
                        </div>
                      </div>

                      <div className="p-3.5 rounded-xl border border-slate-200/80 bg-slate-50/50 flex items-center gap-3">
                        <div className="p-2 rounded-lg bg-sky-100 text-sky-700">
                          <Zap className="w-4 h-4" />
                        </div>
                        <div>
                          <div className="text-[11px] text-slate-500 font-medium">Điện Lưới Căn Hộ</div>
                          <div className="text-xs font-bold text-sky-700">Ổn định · 224V</div>
                        </div>
                      </div>

                      <div className="p-3.5 rounded-xl border border-slate-200/80 bg-slate-50/50 flex items-center gap-3">
                        <div className="p-2 rounded-lg bg-cyan-100 text-cyan-700">
                          <Droplet className="w-4 h-4" />
                        </div>
                        <div>
                          <div className="text-[11px] text-slate-500 font-medium">Áp Lực Cấp Nước</div>
                          <div className="text-xs font-bold text-cyan-700">Chuẩn · 2.4 Bar</div>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Danh sách cư dân nếu có từ backend */}
                  {viewDetailData?.residents && viewDetailData.residents.length > 0 && (
                    <div>
                      <h4 className="text-xs font-bold text-slate-900 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                        <Users className="w-4 h-4 text-sky-600" />
                        <span>Danh Sách Cư Dân Đăng Ký ({viewDetailData.residents.length})</span>
                      </h4>
                      <div className="divide-y divide-slate-100 border border-slate-200 rounded-xl overflow-hidden text-xs">
                        {viewDetailData.residents.map((res: any, idx: number) => (
                          <div key={idx} className="p-3 flex items-center justify-between bg-white hover:bg-slate-50">
                            <div>
                              <div className="font-bold text-slate-900">{res.name}</div>
                              <div className="text-[11px] text-slate-400">{res.phone} · Quan hệ: {res.relationship || 'Chủ hộ'}</div>
                            </div>
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-sky-50 text-sky-700 border border-sky-100">
                              {res.type || 'Thường trú'}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </>
              )}
            </div>

            <div className="px-6 py-3.5 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
              <span className="text-xs text-slate-500">ID định danh: {viewingApartment.id}</span>
              <button
                type="button"
                onClick={() => setViewingApartment(null)}
                className="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold transition-colors cursor-pointer"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL 4: XÓA CĂN HỘ (CONFIRMATION) ================= */}
      {deletingApartment && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-md overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="p-6 text-center space-y-3">
              <div className="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mx-auto">
                <Trash2 className="w-6 h-6" />
              </div>
              <h3 className="text-base font-bold text-slate-900">Xác Nhận Xóa Căn Hộ</h3>
              <p className="text-xs text-slate-500">
                Bạn có chắc chắn muốn xóa căn hộ <strong className="font-mono text-slate-900">{deletingApartment.unitCode}</strong> thuộc {deletingApartment.buildingName || deletingApartment.buildingCode}? Dữ liệu sẽ được lưu trữ an toàn trong lịch sử kiểm toán.
              </p>
            </div>

            <div className="px-6 py-4 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={() => setDeletingApartment(null)}
                className="px-4 py-2 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleConfirmDelete}
                className="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-xs hover:shadow-md transition-all flex items-center gap-1.5 cursor-pointer"
              >
                <Trash2 className="w-4 h-4" />
                <span>Xác Nhận Xóa</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= MODAL 5: TẢI LÊN / NHẬP DANH SÁCH (IMPORT CSV) ================= */}
      {isImportModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs animate-in fade-in duration-200">
          <div className="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-xl overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <div className="p-2 rounded-xl bg-indigo-100 text-indigo-700">
                  <Upload className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-slate-900">Tải Lên / Nhập Căn Hộ Từ Excel (CSV)</h3>
                  <p className="text-xs text-slate-500">Đồng bộ danh sách căn hộ số lượng lớn chỉ trong vài giây</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => {
                  setIsImportModalOpen(false);
                  setImportRows([]);
                }}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-6 space-y-4">
              {/* Nút tải file mẫu */}
              <div className="flex items-center justify-between p-3.5 rounded-xl bg-sky-50 border border-sky-200">
                <div className="flex items-center gap-2.5">
                  <FileSpreadsheet className="w-5 h-5 text-sky-700" />
                  <div>
                    <div className="text-xs font-bold text-sky-900">Chưa có file chuẩn mẫu?</div>
                    <div className="text-[11px] text-sky-700">Tải file CSV mẫu có sẵn định dạng UTF-8 chuẩn Excel.</div>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={downloadTemplateCSV}
                  className="px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold transition-all shadow-xs flex items-center gap-1.5 cursor-pointer"
                >
                  <Download className="w-3.5 h-3.5" />
                  <span>Tải File Mẫu</span>
                </button>
              </div>

              {/* Input chọn file */}
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                  Chọn file dữ liệu (.csv)
                </label>
                <input
                  type="file"
                  accept=".csv,text/csv"
                  onChange={handleCSVUpload}
                  className="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer border border-slate-300 rounded-xl"
                />
              </div>

              {/* Preview bảng nạp nếu có */}
              {importRows.length > 0 && (
                <div className="space-y-2">
                  <div className="flex items-center justify-between text-xs font-semibold text-slate-700">
                    <span>Xem trước dữ liệu ({importRows.length} căn hộ)</span>
                    <span className="text-emerald-600 font-bold">Hợp lệ</span>
                  </div>
                  <div className="max-h-40 overflow-y-auto border border-slate-200 rounded-xl divide-y divide-slate-100 text-xs">
                    {importRows.slice(0, 5).map((row, i) => (
                      <div key={i} className="p-2.5 flex items-center justify-between bg-slate-50/50">
                        <span className="font-mono font-bold text-slate-900">{row.unitCode}</span>
                        <span className="text-slate-600">{row.buildingName} - T{row.floor}</span>
                        <span className="text-slate-600">{row.ownerName}</span>
                        <span className="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-emerald-800">
                          {row.status === 'occupied' ? 'Đang ở' : 'Trống'}
                        </span>
                      </div>
                    ))}
                    {importRows.length > 5 && (
                      <div className="p-2 text-center text-slate-400 text-[11px]">
                        ... và {importRows.length - 5} căn hộ khác
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>

            <div className="px-6 py-4 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={() => {
                  setIsImportModalOpen(false);
                  setImportRows([]);
                }}
                className="px-4 py-2 rounded-xl border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                disabled={importRows.length === 0}
                onClick={handleConfirmImport}
                className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shadow-xs hover:shadow-md transition-all flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
              >
                <Upload className="w-4 h-4" />
                <span>Xác Nhận Nhập ({importRows.length})</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ================= TOAST NOTIFICATION THỜI GIAN THỰC ================= */}
      {toastMessage && (
        <div className="fixed top-6 right-6 z-50 animate-in slide-in-from-top-4 duration-300">
          <div className={`px-4 py-3 rounded-2xl shadow-xl border flex items-center gap-3 backdrop-blur-md ${
            toastMessage.type === 'error'
              ? 'bg-rose-50/95 border-rose-200 text-rose-900'
              : 'bg-emerald-50/95 border-emerald-200 text-emerald-900'
          }`}>
            {toastMessage.type === 'error' ? (
              <AlertTriangle className="w-5 h-5 text-rose-600 shrink-0" />
            ) : (
              <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
            )}
            <span className="text-xs font-bold">{toastMessage.text}</span>
            <button
              type="button"
              onClick={() => setToastMessage(null)}
              className="text-slate-400 hover:text-slate-600 p-1 cursor-pointer"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default BuildingListManagement;
