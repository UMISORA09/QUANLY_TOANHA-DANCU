import React, { useState, useEffect, useMemo } from 'react';
import {
    Zap,
    Droplets,
    Building2,
    Car,
    Plus,
    Search,
    Edit2,
    Trash2,
    Calculator,
    Layers,
    CheckCircle2,
    XCircle,
    AlertCircle,
    ArrowUpDown,
    RefreshCw,
    X,
    Save,
    ChevronRight,
    TrendingUp,
    ShieldAlert,
    HelpCircle,
    Ban,
    Flame
} from 'lucide-react';
import {
    servicePricingApi,
    ServicePricingConfig,
    PricingTier,
    BillingType,
    MeterType,
    SimulationResult
} from '../../Services/servicePricingApi';
import { CustomSelect, SelectOption } from '../../Components/Common/CustomSelect';
import { CustomDatePicker } from '../../Components/Common/CustomDatePicker';

export const ServicePricingManagement: React.FC = () => {
    // Dữ liệu chính
    const [configs, setConfigs] = useState<ServicePricingConfig[]>([]);
    const [loading, setLoading] = useState<boolean>(true);
    const [searchTerm, setSearchTerm] = useState<string>('');
    const [filterBillingType, setFilterBillingType] = useState<string>('ALL');
    const [filterActive, setFilterActive] = useState<string>('ALL');

    // Thông báo Toast
    const [toast, setToast] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

    // Modal Cấu hình (Thêm mới / Sửa)
    const [isConfigModalOpen, setIsConfigModalOpen] = useState<boolean>(false);
    const [editingConfig, setEditingConfig] = useState<ServicePricingConfig | null>(null);
    const [formData, setFormData] = useState({
        service_code: '',
        service_name: '',
        meter_type: '' as MeterType,
        billing_type: 'TIERED_USAGE' as BillingType,
        unit_name: 'kWh',
        fixed_unit_price: 0,
        vat_percentage: 10,
        environmental_protection_fee_pct: 0,
        effective_from_date: new Date().toISOString().split('T')[0],
        effective_to_date: '',
        is_active: true,
    });

    // Modal Chỉnh sửa Bậc thang Lũy tiến (Tier Editor)
    const [selectedConfigForTiers, setSelectedConfigForTiers] = useState<ServicePricingConfig | null>(null);
    const [tiersList, setTiersList] = useState<PricingTier[]>([]);
    const [isTiersModalOpen, setIsTiersModalOpen] = useState<boolean>(false);
    const [tiersSaving, setTiersSaving] = useState<boolean>(false);

    // Modal Mô phỏng Chiết tính (Simulation Calculator)
    const [isSimulateModalOpen, setIsSimulateModalOpen] = useState<boolean>(false);
    const [simulateConfig, setSimulateConfig] = useState<ServicePricingConfig | null>(null);
    const [simulateUsage, setSimulateUsage] = useState<number>(120);
    const [simulationResult, setSimulationResult] = useState<SimulationResult | null>(null);
    const [simulating, setSimulating] = useState<boolean>(false);

    // Modal Xác nhận xóa
    const [deleteConfirmId, setDeleteConfirmId] = useState<string | null>(null);

    const showToast = (type: 'success' | 'error', message: string) => {
        setToast({ type, message });
        setTimeout(() => setToast(null), 4000);
    };

    // Tải danh sách biểu phí (Hỗ trợ silent background polling mượt mà không giật lag)
    const fetchConfigs = async (isSilent = false) => {
        try {
            if (!isSilent && configs.length === 0) {
                setLoading(true);
            }
            const res = await servicePricingApi.getPricingConfigs();
            if (res.success && Array.isArray(res.data)) {
                setConfigs(prev => {
                    const prevJson = JSON.stringify(prev);
                    const nextJson = JSON.stringify(res.data);
                    return prevJson === nextJson ? prev : res.data;
                });
            }
        } catch (error: any) {
            if (!isSilent) {
                console.error('Lỗi tải danh mục đơn giá:', error);
                showToast('error', error?.response?.data?.message || 'Không thể tải danh mục biểu giá.');
            }
        } finally {
            if (!isSilent) {
                setLoading(false);
            }
        }
    };

    // Auto-sync polling liên tục ngầm định mượt mà (zero jitter, zero lag)
    useEffect(() => {
        fetchConfigs(false);

        // Chu kỳ 4 giây một lần (nhẹ nhàng, tạm dừng khi đang gõ modal hoặc rời tab)
        const timer = setInterval(() => {
            if (!document.hidden && !isConfigModalOpen && !isTiersModalOpen && !isSimulateModalOpen) {
                fetchConfigs(true);
            }
        }, 4000);

        const handleVisibilityChange = () => {
            if (!document.hidden && !isConfigModalOpen && !isTiersModalOpen && !isSimulateModalOpen) {
                fetchConfigs(true);
            }
        };

        document.addEventListener('visibilitychange', handleVisibilityChange);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', handleVisibilityChange);
        };
    }, [isConfigModalOpen, isTiersModalOpen, isSimulateModalOpen]);

    // Lọc danh sách
    const filteredConfigs = useMemo(() => {
        return configs.filter(item => {
            const matchSearch =
                item.service_code.toLowerCase().includes(searchTerm.toLowerCase()) ||
                item.service_name.toLowerCase().includes(searchTerm.toLowerCase());
            const matchType = filterBillingType === 'ALL' || item.billing_type === filterBillingType;
            const matchActive =
                filterActive === 'ALL' ||
                (filterActive === 'ACTIVE' && item.is_active) ||
                (filterActive === 'INACTIVE' && !item.is_active);
            return matchSearch && matchType && matchActive;
        });
    }, [configs, searchTerm, filterBillingType, filterActive]);

    // Thống kê thẻ KPI
    const stats = useMemo(() => {
        const total = configs.length;
        const tiered = configs.filter(c => c.billing_type === 'TIERED_USAGE').length;
        const fixed = configs.filter(c => c.billing_type === 'FIXED_MONTHLY').length;
        const unitArea = configs.filter(c => c.billing_type === 'UNIT_PRICE_USAGE').length;
        const activeCount = configs.filter(c => c.is_active).length;
        return { total, tiered, fixed, unitArea, activeCount };
    }, [configs]);

    // Format tiền tệ VNĐ
    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
    };

    // Mở modal thêm mới
    const handleOpenCreateModal = () => {
        setEditingConfig(null);
        setFormData({
            service_code: '',
            service_name: '',
            meter_type: 'ELECTRICITY',
            billing_type: 'TIERED_USAGE',
            unit_name: 'kWh',
            fixed_unit_price: 0,
            vat_percentage: 10,
            environmental_protection_fee_pct: 0,
            effective_from_date: new Date().toISOString().split('T')[0],
            effective_to_date: '',
            is_active: true,
        });
        setIsConfigModalOpen(true);
    };

    // Mở modal sửa
    const handleOpenEditModal = (config: ServicePricingConfig) => {
        setEditingConfig(config);
        setFormData({
            service_code: config.service_code,
            service_name: config.service_name,
            meter_type: config.meter_type,
            billing_type: config.billing_type,
            unit_name: config.unit_name,
            fixed_unit_price: config.fixed_unit_price,
            vat_percentage: config.vat_percentage,
            environmental_protection_fee_pct: config.environmental_protection_fee_pct,
            effective_from_date: config.effective_from_date || '',
            effective_to_date: config.effective_to_date || '',
            is_active: config.is_active,
        });
        setIsConfigModalOpen(true);
    };

    // Lưu cấu hình
    const handleSaveConfig = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            if (editingConfig) {
                const res = await servicePricingApi.updatePricingConfig(editingConfig.id, {
                    ...formData,
                    meter_type: formData.meter_type || undefined,
                    effective_to_date: formData.effective_to_date || null,
                });
                if (res.success) {
                    showToast('success', res.message || 'Cập nhật cấu hình đơn giá thành công.');
                    setIsConfigModalOpen(false);
                    fetchConfigs();
                }
            } else {
                const res = await servicePricingApi.createPricingConfig({
                    ...formData,
                    meter_type: formData.meter_type || undefined,
                    effective_to_date: formData.effective_to_date || null,
                });
                if (res.success) {
                    showToast('success', res.message || 'Thiết lập cấu hình đơn giá mới thành công.');
                    setIsConfigModalOpen(false);
                    fetchConfigs();
                }
            }
        } catch (error: any) {
            console.error('Lỗi lưu cấu hình:', error);
            showToast('error', error?.response?.data?.message || 'Có lỗi xảy ra khi lưu cấu hình.');
        }
    };

    // Bật / tắt kích hoạt
    const handleToggleActive = async (id: string) => {
        try {
            const res = await servicePricingApi.toggleActivePricingConfig(id);
            if (res.success) {
                showToast('success', res.message);
                fetchConfigs();
            }
        } catch (error: any) {
            showToast('error', error?.response?.data?.message || 'Không thể đổi trạng thái cấu hình.');
        }
    };

    // Xóa mềm cấu hình
    const handleDeleteConfig = async () => {
        if (!deleteConfirmId) return;
        try {
            const res = await servicePricingApi.deletePricingConfig(deleteConfirmId);
            if (res.success) {
                showToast('success', res.message);
                setDeleteConfirmId(null);
                fetchConfigs();
            }
        } catch (error: any) {
            showToast('error', error?.response?.data?.message || 'Không thể xóa cấu hình biểu giá.');
        }
    };

    // Mở Drawer/Modal Chỉnh sửa Bậc thang
    const handleOpenTiersModal = (config: ServicePricingConfig) => {
        setSelectedConfigForTiers(config);
        const currentTiers = config.tiers && config.tiers.length > 0 ? [...config.tiers] : [
            { tier_order: 1, tier_name: 'Bậc 1', min_usage_threshold: 0, max_usage_threshold: 50, unit_price: 1800 }
        ];
        setTiersList(currentTiers);
        setIsTiersModalOpen(true);
    };

    // Thêm bậc thang mới
    const handleAddTierRow = () => {
        const lastTier = tiersList[tiersList.length - 1];
        const nextOrder = (lastTier ? lastTier.tier_order : 0) + 1;
        const nextMin = lastTier && lastTier.max_usage_threshold !== null ? lastTier.max_usage_threshold : 0;
        const newTier: PricingTier = {
            tier_order: nextOrder,
            tier_name: `Bậc ${nextOrder}`,
            min_usage_threshold: nextMin,
            max_usage_threshold: null,
            unit_price: lastTier ? lastTier.unit_price + 300 : 2000,
        };

        // Cập nhật bậc trước nếu nó đang null
        let updatedList = [...tiersList];
        if (lastTier && lastTier.max_usage_threshold === null) {
            updatedList[updatedList.length - 1].max_usage_threshold = nextMin + 50;
            newTier.min_usage_threshold = nextMin + 50;
        }

        setTiersList([...updatedList, newTier]);
    };

    // Xóa bậc thang
    const handleRemoveTierRow = (index: number) => {
        if (tiersList.length <= 1) {
            showToast('error', 'Biểu giá lũy tiến phải có tối thiểu 1 bậc thang.');
            return;
        }
        const filtered = tiersList.filter((_, i) => i !== index);
        // Cập nhật lại số thứ tự tier_order
        const reordered = filtered.map((item, idx) => ({
            ...item,
            tier_order: idx + 1,
            tier_name: item.tier_name.startsWith('Bậc ') ? `Bậc ${idx + 1}` : item.tier_name
        }));
        setTiersList(reordered);
    };

    // Lưu danh sách bậc thang
    const handleSaveTiers = async () => {
        if (!selectedConfigForTiers) return;
        try {
            setTiersSaving(true);
            const res = await servicePricingApi.updatePricingTiers(selectedConfigForTiers.id, tiersList);
            if (res.success) {
                showToast('success', res.message || 'Cập nhật định mức bậc thang thành công.');
                setIsTiersModalOpen(false);
                fetchConfigs();
            }
        } catch (error: any) {
            console.error('Lỗi cập nhật bậc thang:', error);
            showToast('error', error?.response?.data?.message || 'Bậc thang không hợp lệ. Vui lòng kiểm tra các khoảng định mức.');
        } finally {
            setTiersSaving(false);
        }
    };

    // Mở Modal Mô phỏng Tính Tiền
    const handleOpenSimulateModal = async (config: ServicePricingConfig) => {
        setSimulateConfig(config);
        const defaultUsage = config.meter_type === 'ELECTRICITY' ? 120 : (config.meter_type === 'COLD_WATER' ? 22 : 75);
        setSimulateUsage(defaultUsage);
        setIsSimulateModalOpen(true);
        executeSimulation(config.id, defaultUsage);
    };

    const executeSimulation = async (configId: string, usage: number) => {
        try {
            setSimulating(true);
            const res = await servicePricingApi.simulateCalculation(configId, usage);
            if (res.success) {
                setSimulationResult(res.data);
            }
        } catch (error: any) {
            showToast('error', error?.response?.data?.message || 'Không thể mô phỏng tính tiền.');
        } finally {
            setSimulating(false);
        }
    };

    return (
        <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 transition-all duration-300">
            {/* TOAST THÔNG BÁO */}
            {toast && (
                <div
                    className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border text-xs font-semibold transition-all animate-in slide-in-from-bottom duration-200 ${
                        toast.type === 'success'
                            ? 'bg-emerald-50 text-emerald-800 border-emerald-300'
                            : 'bg-rose-50 text-rose-800 border-rose-300'
                    }`}
                >
                    {toast.type === 'success' ? <CheckCircle2 className="w-4 h-4 text-emerald-600" /> : <AlertCircle className="w-4 h-4 text-rose-600" />}
                    <span>{toast.message}</span>
                </div>
            )}

            {/* Header Info & Actions */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-slate-200/90">
                <div>
                    <div className="flex items-center gap-1.5 sm:gap-2 text-[11px] sm:text-xs font-bold uppercase tracking-wider font-mono text-sky-600">
                        <Zap className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-sky-500 shrink-0" />
                        <span>HỆ THỐNG BIỂU PHÍ & ĐƠN GIÁ DỊCH VỤ</span>
                    </div>
                    <h1 className="text-xl sm:text-2xl lg:text-3xl font-black text-neutral-900 tracking-tight mt-1">
                        Cài đặt Đơn giá Dịch vụ & Biểu giá Lũy tiến
                    </h1>
                </div>
            </div>

            {/* 5 Realtime Summary Stats Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2.5 sm:gap-3.5">
                <div className="sass-db-kpi-card">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Tổng biểu phí</span>
                        <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                            <Layers className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-neutral-900 font-mono">
                        {stats.total} <span className="text-xs font-normal text-slate-400">cấu hình</span>
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-emerald-700 font-medium truncate flex items-center gap-1">
                        <CheckCircle2 className="w-3 h-3 text-emerald-600 shrink-0" />
                        <span>{stats.activeCount} đang áp dụng</span>
                    </div>
                </div>

                <div className="sass-db-kpi-card">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Điện bậc thang</span>
                        <div className="kpi-icon-wrapper bg-amber-50 text-amber-600">
                            <Zap className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-amber-600 font-mono">
                        {configs.find(c => c.service_code === 'ELECTRICITY_RESIDENTIAL')?.tiers?.length || 6} <span className="text-xs font-normal text-slate-400">Bậc</span>
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-amber-700 font-medium truncate">
                        Biểu giá EVN lũy tiến
                    </div>
                </div>

                <div className="sass-db-kpi-card">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Nước định mức</span>
                        <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                            <Droplets className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-sky-600 font-mono">
                        {configs.find(c => c.service_code === 'WATER_RESIDENTIAL')?.tiers?.length || 4} <span className="text-xs font-normal text-slate-400">Bậc</span>
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-sky-700 font-medium truncate">
                        VAT 5% · BVMT 10%
                    </div>
                </div>

                <div className="sass-db-kpi-card">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Phí Quản Lý</span>
                        <div className="kpi-icon-wrapper bg-indigo-50 text-indigo-600">
                            <Building2 className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-lg sm:text-xl font-black text-indigo-700 font-mono truncate">
                        {formatCurrency(configs.find(c => c.service_code === 'MANAGEMENT_FEE')?.fixed_unit_price || 14000)}
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-indigo-600 font-medium truncate">
                        Theo m² thông thủy / tháng
                    </div>
                </div>

                <div className="sass-db-kpi-card col-span-2 sm:col-span-1">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Phí Giữ Xe</span>
                        <div className="kpi-icon-wrapper bg-purple-50 text-purple-600">
                            <Car className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-lg sm:text-xl font-black text-purple-700 font-mono truncate">
                        {formatCurrency(configs.find(c => c.service_code === 'PARKING_MOTORBIKE')?.fixed_unit_price || 120000)}
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-purple-700 font-medium truncate">
                        Ô tô: {formatCurrency(configs.find(c => c.service_code === 'PARKING_CAR')?.fixed_unit_price || 1500000)}
                    </div>
                </div>
            </div>

            {/* Filter and Search Bar */}
            <div className="bg-white rounded-2xl border border-slate-200/80 p-3.5 shadow-2xs flex flex-col md:flex-row items-center gap-3 justify-between">
                <div className="relative w-full md:w-80">
                    <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        type="text"
                        placeholder="Tìm kiếm mã hoặc tên dịch vụ..."
                        value={searchTerm}
                        onChange={e => setSearchTerm(e.target.value)}
                        className="w-full pl-10 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-2.5 w-full md:w-auto">
                    <div className="w-full sm:w-56">
                        <CustomSelect<string>
                            value={filterBillingType}
                            onChange={val => setFilterBillingType(val)}
                            options={[
                                { value: 'ALL', label: 'Mọi hình thức tính cước' },
                                { value: 'TIERED_USAGE', label: 'Lũy tiến bậc thang', icon: <Layers className="w-3.5 h-3.5 text-amber-500" /> },
                                { value: 'UNIT_PRICE_USAGE', label: 'Đơn giá theo tiêu thụ / m²', icon: <Calculator className="w-3.5 h-3.5 text-indigo-500" /> },
                                { value: 'FIXED_MONTHLY', label: 'Cố định hàng tháng', icon: <Car className="w-3.5 h-3.5 text-emerald-500" /> },
                            ]}
                        />
                    </div>

                    <div className="w-full sm:w-44">
                        <CustomSelect<string>
                            value={filterActive}
                            onChange={val => setFilterActive(val)}
                            options={[
                                { value: 'ALL', label: 'Mọi trạng thái' },
                                { value: 'ACTIVE', label: 'Đang áp dụng', icon: <CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" />, badge: 'Áp dụng', badgeColor: 'bg-emerald-50 text-emerald-700 border border-emerald-200' },
                                { value: 'INACTIVE', label: 'Tạm ngưng', icon: <XCircle className="w-3.5 h-3.5 text-rose-500" />, badge: 'Tạm ngưng', badgeColor: 'bg-rose-50 text-rose-700 border border-rose-200' },
                            ]}
                        />
                    </div>
                </div>
            </div>

            {/* Detailed Table Card */}
            <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                <div className="p-4 border-b border-slate-100 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-slate-50/70">
                    <div>
                        <h3 className="font-bold text-slate-900 text-sm flex items-center gap-2">
                            <Layers className="w-4 h-4 text-sky-600" />
                            <span>Danh sách Biểu giá & Định mức Dịch vụ</span>
                        </h3>
                        <p className="text-xs text-slate-500 mt-0.5">
                            Chi tiết thuế suất VAT, phí bảo vệ môi trường và cấu hình bậc thang áp dụng
                        </p>
                    </div>

                    <div className="flex items-center gap-2.5 shrink-0">
                        <button
                            type="button"
                            onClick={handleOpenCreateModal}
                            className="inline-flex items-center justify-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white shadow-sm transition-all active:scale-[0.98] cursor-pointer"
                        >
                            <Plus className="w-3.5 h-3.5 shrink-0" />
                            <span>Thêm Biểu Phí Mới</span>
                        </button>

                        <span className="text-xs font-semibold px-2.5 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-mono shadow-2xs">
                            Tổng cộng: {filteredConfigs.length} Biểu phí
                        </span>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr className="border-b border-slate-200/80 bg-slate-100/50 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
                                <th className="py-3 px-4">Loại Dịch Vụ</th>
                                <th className="py-3 px-4">Hình Thức Tính</th>
                                <th className="py-3 px-4">Đơn Vị Tính</th>
                                <th className="py-3 px-4">Đơn Giá / Bậc Thang</th>
                                <th className="py-3 px-4">Thuế & Phí BVMT</th>
                                <th className="py-3 px-4">Ngày Hiệu Lực</th>
                                <th className="py-3 px-4 text-center">Trạng Thái</th>
                                <th className="py-3 px-4 text-right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {loading ? (
                                <tr>
                                    <td colSpan={8} className="py-12 text-center text-slate-400">
                                        <div className="flex flex-col items-center justify-center gap-3">
                                            <RefreshCw className="w-5 h-5 animate-spin text-sky-600" />
                                            <span>Đang tải danh mục đơn giá dịch vụ...</span>
                                        </div>
                                    </td>
                                </tr>
                            ) : filteredConfigs.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="py-12 text-center text-slate-400">
                                        Không tìm thấy cấu hình đơn giá nào phù hợp với bộ lọc.
                                    </td>
                                </tr>
                            ) : (
                                filteredConfigs.map(item => {
                                    return (
                                        <tr key={item.id} className="hover:bg-slate-50/80 transition-colors group">
                                            {/* Tên & Mã */}
                                            <td className="py-3 px-4">
                                                <div className="flex items-center gap-3">
                                                    <div className={`p-2 rounded-xl border ${
                                                        item.meter_type === 'ELECTRICITY'
                                                            ? 'bg-amber-50 border-amber-200 text-amber-600'
                                                            : item.meter_type === 'COLD_WATER'
                                                            ? 'bg-sky-50 border-sky-200 text-sky-600'
                                                            : item.service_code.includes('MANAGEMENT')
                                                            ? 'bg-indigo-50 border-indigo-200 text-indigo-600'
                                                            : 'bg-purple-50 border-purple-200 text-purple-600'
                                                    }`}>
                                                        {item.meter_type === 'ELECTRICITY' && <Zap className="w-4 h-4" />}
                                                        {item.meter_type === 'COLD_WATER' && <Droplets className="w-4 h-4" />}
                                                        {item.service_code.includes('MANAGEMENT') && <Building2 className="w-4 h-4" />}
                                                        {!item.meter_type && !item.service_code.includes('MANAGEMENT') && <Car className="w-4 h-4" />}
                                                    </div>
                                                    <div>
                                                        <div className="font-bold text-slate-900 group-hover:text-sky-600 transition-colors">
                                                            {item.service_name}
                                                        </div>
                                                        <div className="text-[11px] text-slate-400 font-mono">
                                                            {item.service_code}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            {/* Hình thức tính */}
                                            <td className="py-3 px-4">
                                                {item.billing_type === 'TIERED_USAGE' && (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                        <TrendingUp className="w-3 h-3" />
                                                        Lũy tiến bậc thang
                                                    </span>
                                                )}
                                                {item.billing_type === 'UNIT_PRICE_USAGE' && (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                                        Theo diện tích / SL
                                                    </span>
                                                )}
                                                {item.billing_type === 'FIXED_MONTHLY' && (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        Cố định tháng
                                                    </span>
                                                )}
                                            </td>

                                            {/* Đơn vị tính */}
                                            <td className="py-3 px-4 font-semibold text-slate-700">
                                                {item.unit_name}
                                            </td>

                                            {/* Đơn giá / Bậc thang */}
                                            <td className="py-3 px-4">
                                                {item.billing_type === 'TIERED_USAGE' ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenTiersModal(item)}
                                                        className="text-xs px-2.5 py-1 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 flex items-center gap-1.5 font-bold transition-all cursor-pointer"
                                                    >
                                                        <Layers className="w-3.5 h-3.5 text-amber-600" />
                                                        <span>{item.tiers?.length || 0} Bậc thang</span>
                                                        <ChevronRight className="w-3.5 h-3.5 text-amber-500" />
                                                    </button>
                                                ) : (
                                                    <div className="font-bold text-slate-900 font-mono">
                                                        {formatCurrency(item.fixed_unit_price)}
                                                    </div>
                                                )}
                                            </td>

                                            {/* Thuế & Phí BVMT */}
                                            <td className="py-3 px-4 text-xs">
                                                <div className="text-slate-700 font-medium">VAT: {item.vat_percentage}%</div>
                                                {item.environmental_protection_fee_pct > 0 && (
                                                    <div className="text-sky-700 font-medium mt-0.5">BVMT: {item.environmental_protection_fee_pct}%</div>
                                                )}
                                            </td>

                                            {/* Ngày hiệu lực */}
                                            <td className="py-3 px-4 text-[11px] text-slate-500">
                                                <div>Từ: {item.effective_from_date || '2026-01-01'}</div>
                                                {item.effective_to_date && (
                                                    <div>Đến: {item.effective_to_date}</div>
                                                )}
                                            </td>

                                            {/* Trạng thái */}
                                            <td className="py-3 px-4 text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => handleToggleActive(item.id)}
                                                    className={`px-2.5 py-0.5 rounded-full text-[11px] font-bold border transition-all cursor-pointer ${
                                                        item.is_active
                                                            ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100'
                                                            : 'bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-100'
                                                    }`}
                                                >
                                                    {item.is_active ? 'Đang áp dụng' : 'Tạm dừng'}
                                                </button>
                                            </td>

                                            {/* Thao tác */}
                                            <td className="py-3 px-4 text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    {/* Nút mô phỏng tính tiền */}
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenSimulateModal(item)}
                                                        className="p-1.5 rounded-lg bg-white hover:bg-amber-50 hover:text-amber-700 text-slate-500 border border-slate-200 shadow-2xs transition-all cursor-pointer"
                                                        title="Mô phỏng chiết tính hóa đơn"
                                                    >
                                                        <Calculator className="w-3.5 h-3.5" />
                                                    </button>

                                                    {/* Nút sửa bậc thang nếu là tiered */}
                                                    {item.billing_type === 'TIERED_USAGE' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleOpenTiersModal(item)}
                                                            className="p-1.5 rounded-lg bg-white hover:bg-indigo-50 hover:text-indigo-700 text-slate-500 border border-slate-200 shadow-2xs transition-all cursor-pointer"
                                                            title="Cấu hình bậc thang giá"
                                                        >
                                                            <Layers className="w-3.5 h-3.5" />
                                                        </button>
                                                    )}

                                                    {/* Nút chỉnh sửa */}
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenEditModal(item)}
                                                        className="p-1.5 rounded-lg bg-white hover:bg-sky-50 hover:text-sky-700 text-slate-500 border border-slate-200 shadow-2xs transition-all cursor-pointer"
                                                        title="Chỉnh sửa cấu hình"
                                                    >
                                                        <Edit2 className="w-3.5 h-3.5" />
                                                    </button>

                                                    {/* Nút xóa mềm */}
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeleteConfirmId(item.id)}
                                                        className="p-1.5 rounded-lg bg-white hover:bg-rose-50 hover:text-rose-700 text-slate-500 border border-slate-200 shadow-2xs transition-all cursor-pointer"
                                                        title="Xóa cấu hình"
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
            </div>

            {/* MODAL CẤU HÌNH BIỂU PHÍ (THÊM / SỬA) */}
            {isConfigModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs animate-in fade-in duration-200">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-2xl shadow-2xl animate-in zoom-in-95 duration-200 relative">
                        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/70 rounded-t-2xl">
                            <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                                <Layers className="w-4 h-4 text-sky-600" />
                                {editingConfig ? 'Chỉnh Sửa Cấu Hình Đơn Giá' : 'Thiết Lập Đơn Giá Dịch Vụ Mới'}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setIsConfigModalOpen(false)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveConfig} className="p-6 space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Mã loại dịch vụ <span className="text-rose-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="Ví dụ: ELECTRICITY_RESIDENTIAL"
                                        value={formData.service_code}
                                        onChange={e => setFormData({ ...formData, service_code: e.target.value.toUpperCase() })}
                                        className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Tên dịch vụ hiển thị <span className="text-rose-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="Ví dụ: Điện Sinh Hoạt Bậc Thang"
                                        value={formData.service_name}
                                        onChange={e => setFormData({ ...formData, service_name: e.target.value })}
                                        className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Hình thức tính cước <span className="text-rose-500">*</span>
                                    </label>
                                    <CustomSelect<BillingType>
                                        value={formData.billing_type}
                                        onChange={val => setFormData({ ...formData, billing_type: val })}
                                        options={[
                                            {
                                                value: 'TIERED_USAGE',
                                                label: 'Lũy tiến bậc thang (Điện, Nước)',
                                                description: 'Tính giá chia theo các bậc tiêu thụ tăng dần',
                                                icon: <Layers className="w-3.5 h-3.5 text-amber-500" />,
                                            },
                                            {
                                                value: 'UNIT_PRICE_USAGE',
                                                label: 'Đơn giá theo diện tích / tiêu thụ',
                                                description: 'Đơn giá cố định nhân số m² thông thủy hoặc số lượng',
                                                icon: <Calculator className="w-3.5 h-3.5 text-indigo-500" />,
                                            },
                                            {
                                                value: 'FIXED_MONTHLY',
                                                label: 'Cố định hàng tháng (Gửi xe, phí gói)',
                                                description: 'Mức thu cố định định kỳ hàng tháng cho căn hộ hoặc xe',
                                                icon: <Car className="w-3.5 h-3.5 text-emerald-500" />,
                                            },
                                        ]}
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Đơn vị tính <span className="text-rose-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="kWh, m3, m2/tháng, xe/tháng..."
                                        value={formData.unit_name}
                                        onChange={e => setFormData({ ...formData, unit_name: e.target.value })}
                                        className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                    />
                                </div>

                                {formData.billing_type !== 'TIERED_USAGE' && (
                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                            Đơn giá cơ bản (VNĐ) <span className="text-rose-500">*</span>
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            step="100"
                                            value={formData.fixed_unit_price}
                                            onChange={e => setFormData({ ...formData, fixed_unit_price: parseFloat(e.target.value) || 0 })}
                                            className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition font-mono"
                                        />
                                    </div>
                                )}

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Loại đồng hồ đo
                                    </label>
                                    <CustomSelect<MeterType | ''>
                                        value={formData.meter_type || ''}
                                        onChange={val => setFormData({ ...formData, meter_type: (val || null) as MeterType })}
                                        options={[
                                            {
                                                value: '',
                                                label: 'Không dùng công tơ đo',
                                                description: 'Phí dịch vụ thu theo diện tích, cố định hoặc gói',
                                                icon: <Ban className="w-3.5 h-3.5 text-slate-400" />,
                                            },
                                            {
                                                value: 'ELECTRICITY',
                                                label: 'Đồng hồ Điện (kWh)',
                                                description: 'Công tơ điện sinh hoạt hoặc trạm sạc xe điện',
                                                icon: <Zap className="w-3.5 h-3.5 text-amber-500" />,
                                            },
                                            {
                                                value: 'COLD_WATER',
                                                label: 'Đồng hồ Nước Sạch (m3)',
                                                description: 'Đồng hồ đo khối lượng nước sạch sinh hoạt',
                                                icon: <Droplets className="w-3.5 h-3.5 text-sky-500" />,
                                            },
                                            {
                                                value: 'HOT_WATER',
                                                label: 'Đồng hồ Nước Nóng (m3)',
                                                description: 'Hệ thống nước nóng trung tâm / heatpump',
                                                icon: <Flame className="w-3.5 h-3.5 text-orange-500" />,
                                            },
                                            {
                                                value: 'GAS',
                                                label: 'Đồng hồ Khí Gas (m3)',
                                                description: 'Hệ thống đường ống dẫn khí gas trung tâm căn hộ',
                                                icon: <Flame className="w-3.5 h-3.5 text-rose-500" />,
                                            },
                                        ]}
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Thuế suất VAT (%)
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        max="100"
                                        value={formData.vat_percentage}
                                        onChange={e => setFormData({ ...formData, vat_percentage: parseFloat(e.target.value) || 0 })}
                                        className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Phí bảo vệ môi trường (%)
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        max="100"
                                        value={formData.environmental_protection_fee_pct}
                                        onChange={e => setFormData({ ...formData, environmental_protection_fee_pct: parseFloat(e.target.value) || 0 })}
                                        className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Ngày bắt đầu hiệu lực <span className="text-rose-500">*</span>
                                    </label>
                                    <CustomDatePicker
                                        value={formData.effective_from_date}
                                        onChange={val => setFormData({ ...formData, effective_from_date: val })}
                                        placeholder="dd/mm/yyyy"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                                        Ngày kết thúc (Tùy chọn)
                                    </label>
                                    <CustomDatePicker
                                        value={formData.effective_to_date}
                                        onChange={val => setFormData({ ...formData, effective_to_date: val })}
                                        placeholder="dd/mm/yyyy (Không bắt buộc)"
                                    />
                                </div>
                            </div>

                            <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-2 bg-slate-50/50 -mx-6 -mb-6 p-4 rounded-b-2xl">
                                <button
                                    type="button"
                                    onClick={() => setIsConfigModalOpen(false)}
                                    className="px-3.5 py-2 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                                >
                                    Hủy bỏ
                                </button>
                                <button
                                    type="submit"
                                    className="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white shadow-sm transition-all active:scale-[0.98] cursor-pointer"
                                >
                                    <Save className="w-3.5 h-3.5" />
                                    <span>{editingConfig ? 'Lưu Thay Đổi' : 'Tạo Cấu Hình'}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL CẤU HÌNH BẬC THANG LŨY TIẾN (TIER EDITOR) */}
            {isTiersModalOpen && selectedConfigForTiers && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs animate-in fade-in duration-200">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-3xl overflow-hidden shadow-2xl animate-in zoom-in-95 duration-200">
                        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                            <div>
                                <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                                    <TrendingUp className="w-4 h-4 text-sky-600" />
                                    Cấu Hình Định Mức Bậc Thang: {selectedConfigForTiers.service_name}
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Mã: {selectedConfigForTiers.service_code} • Đơn vị: {selectedConfigForTiers.unit_name}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setIsTiersModalOpen(false)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="p-6 space-y-4">
                            <div className="p-3 bg-sky-50 border border-sky-200 rounded-xl text-xs text-sky-800 flex items-start gap-2.5">
                                <HelpCircle className="w-4 h-4 shrink-0 mt-0.5 text-sky-600" />
                                <div>
                                    <strong>Quy tắc bậc thang liên tục:</strong> Bậc 1 bắt buộc bắt đầu từ 0. Ngưỡng bắt đầu (Min) của bậc sau phải bằng chính xác ngưỡng kết thúc (Max) của bậc trước. Bậc cuối cùng được để trống Max (không giới hạn).
                                </div>
                            </div>

                            <div className="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">
                                {tiersList.map((tier, idx) => (
                                    <div
                                        key={idx}
                                        className="p-3 bg-slate-50/80 border border-slate-200/80 rounded-xl flex flex-col md:flex-row items-center gap-3 justify-between"
                                    >
                                        <div className="flex items-center gap-2 w-full md:w-36">
                                            <span className="w-6 h-6 rounded-full bg-sky-100 text-sky-700 text-xs font-bold flex items-center justify-center shrink-0">
                                                {tier.tier_order}
                                            </span>
                                            <input
                                                type="text"
                                                value={tier.tier_name}
                                                onChange={e => {
                                                    const updated = [...tiersList];
                                                    updated[idx].tier_name = e.target.value;
                                                    setTiersList(updated);
                                                }}
                                                className="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                                placeholder="Tên bậc"
                                            />
                                        </div>

                                        <div className="flex items-center gap-2 w-full md:w-auto">
                                            <div className="flex items-center gap-1.5">
                                                <span className="text-xs text-slate-500">Từ:</span>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    value={tier.min_usage_threshold}
                                                    onChange={e => {
                                                        const updated = [...tiersList];
                                                        updated[idx].min_usage_threshold = parseFloat(e.target.value) || 0;
                                                        setTiersList(updated);
                                                    }}
                                                    className="w-20 px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-mono text-slate-800 text-center focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                                />
                                            </div>

                                            <div className="flex items-center gap-1.5">
                                                <span className="text-xs text-slate-500">Đến:</span>
                                                <input
                                                    type="number"
                                                    placeholder="Vô hạn"
                                                    value={tier.max_usage_threshold !== null ? tier.max_usage_threshold : ''}
                                                    onChange={e => {
                                                        const updated = [...tiersList];
                                                        const val = e.target.value === '' ? null : parseFloat(e.target.value);
                                                        updated[idx].max_usage_threshold = val;
                                                        setTiersList(updated);
                                                    }}
                                                    className="w-24 px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-mono text-slate-800 text-center focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                                />
                                            </div>

                                            <div className="flex items-center gap-1.5">
                                                <span className="text-xs text-slate-500">Đơn giá:</span>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="10"
                                                    value={tier.unit_price}
                                                    onChange={e => {
                                                        const updated = [...tiersList];
                                                        updated[idx].unit_price = parseFloat(e.target.value) || 0;
                                                        setTiersList(updated);
                                                    }}
                                                    className="w-28 px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-mono text-amber-700 font-bold text-right focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                                />
                                                <span className="text-xs text-slate-500">đ</span>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => handleRemoveTierRow(idx)}
                                            className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-all shrink-0 cursor-pointer"
                                            title="Xóa bậc này"
                                        >
                                            <Trash2 className="w-4 h-4" />
                                        </button>
                                    </div>
                                ))}
                            </div>

                            <div className="flex items-center justify-between pt-4 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={handleAddTierRow}
                                    className="px-3.5 py-2 rounded-xl bg-sky-50 hover:bg-sky-100 text-sky-700 text-xs font-bold border border-sky-200 flex items-center gap-2 transition-all cursor-pointer"
                                >
                                    <Plus className="w-3.5 h-3.5" />
                                    <span>Thêm Bậc Tiếp Theo</span>
                                </button>

                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setIsTiersModalOpen(false)}
                                        className="px-3.5 py-2 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                                    >
                                        Hủy
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleSaveTiers}
                                        disabled={tiersSaving}
                                        className="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white shadow-sm transition-all active:scale-[0.98] cursor-pointer"
                                    >
                                        <Save className="w-3.5 h-3.5" />
                                        <span>{tiersSaving ? 'Đang lưu...' : 'Lưu Danh Sách Bậc Thang'}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL MÔ PHỎNG CHIẾT TÍNH TIỀN (SIMULATION CALCULATOR) */}
            {isSimulateModalOpen && simulateConfig && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs animate-in fade-in duration-200">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl animate-in zoom-in-95 duration-200">
                        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                            <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                                <Calculator className="w-4 h-4 text-sky-600" />
                                Máy Tính Mô Phỏng Chiết Tính: {simulateConfig.service_name}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setIsSimulateModalOpen(false)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-all cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="p-6 space-y-5">
                            {/* Khung nhập sản lượng */}
                            <div className="p-4 bg-slate-50/80 border border-slate-200 rounded-2xl flex items-center gap-4 justify-between">
                                <div>
                                    <div className="text-xs text-slate-500 uppercase font-bold">
                                        Nhập sản lượng tiêu thụ / diện tích:
                                    </div>
                                    <div className="text-sm text-slate-700 mt-0.5">
                                        Đơn vị tính: <strong>{simulateConfig.unit_name}</strong>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        value={simulateUsage}
                                        onChange={e => {
                                            const val = parseFloat(e.target.value) || 0;
                                            setSimulateUsage(val);
                                            executeSimulation(simulateConfig.id, val);
                                        }}
                                        className="w-28 px-3 py-2 bg-white border border-slate-200 rounded-xl text-center text-lg font-mono font-bold text-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition"
                                    />
                                    <span className="text-sm font-semibold text-slate-500">{simulateConfig.unit_name}</span>
                                </div>
                            </div>

                            {/* Bảng chiết tính từng bậc */}
                            {simulationResult && (
                                <div className="space-y-4">
                                    {simulationResult.tier_breakdowns.length > 0 && (
                                        <div className="border border-slate-200 rounded-2xl overflow-hidden bg-white shadow-2xs">
                                            <table className="w-full text-left text-xs border-collapse">
                                                <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 uppercase tracking-wider font-bold">
                                                    <tr>
                                                        <th className="py-2.5 px-3">Bậc Thang</th>
                                                        <th className="py-2.5 px-3 text-center">Định Mức</th>
                                                        <th className="py-2.5 px-3 text-right">Đơn Giá</th>
                                                        <th className="py-2.5 px-3 text-center">Sản Lượng</th>
                                                        <th className="py-2.5 px-3 text-right">Thành Tiền</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {simulationResult.tier_breakdowns.map(tb => (
                                                        <tr key={tb.tier_order} className={tb.tier_usage > 0 ? 'bg-amber-50/40' : 'opacity-50'}>
                                                            <td className="py-2 px-3 font-semibold text-slate-900">{tb.tier_name}</td>
                                                            <td className="py-2 px-3 text-center font-mono text-slate-600">
                                                                {tb.min_usage} - {tb.max_usage !== null ? tb.max_usage : '∞'}
                                                            </td>
                                                            <td className="py-2 px-3 text-right font-mono text-slate-700">{formatCurrency(tb.unit_price)}</td>
                                                            <td className="py-2 px-3 text-center font-mono font-bold text-amber-700">
                                                                {tb.tier_usage} {simulateConfig.unit_name}
                                                            </td>
                                                            <td className="py-2 px-3 text-right font-mono font-bold text-slate-900">
                                                                {formatCurrency(tb.tier_amount)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}

                                    {/* Tổng kết tiền */}
                                    <div className="p-4 bg-slate-50/80 border border-slate-200 rounded-2xl space-y-2 text-xs">
                                        <div className="flex justify-between text-slate-600">
                                            <span>Tiền trước thuế (Subtotal):</span>
                                            <span className="font-mono font-semibold text-slate-900">{formatCurrency(simulationResult.subtotal)}</span>
                                        </div>
                                        <div className="flex justify-between text-slate-600">
                                            <span>Thuế VAT ({simulationResult.vat_percentage}%):</span>
                                            <span className="font-mono font-semibold text-slate-900">{formatCurrency(simulationResult.vat_amount)}</span>
                                        </div>
                                        {simulationResult.environmental_fee > 0 && (
                                            <div className="flex justify-between text-sky-700">
                                                <span>Phí bảo vệ môi trường ({simulationResult.environmental_protection_fee_pct}%):</span>
                                                <span className="font-mono font-semibold">{formatCurrency(simulationResult.environmental_fee)}</span>
                                            </div>
                                        )}
                                        <div className="pt-2 border-t border-slate-200 flex justify-between items-center text-sm font-bold">
                                            <span className="text-slate-900">TỔNG CỘNG THANH TOÁN:</span>
                                            <span className="text-lg font-black text-amber-600 font-mono">
                                                {formatCurrency(simulationResult.total_amount)}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end pt-2">
                                <button
                                    type="button"
                                    onClick={() => setIsSimulateModalOpen(false)}
                                    className="px-4 py-2 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                                >
                                    Đóng cửa sổ
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL XÁC NHẬN XÓA */}
            {deleteConfirmId && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs animate-in fade-in duration-200">
                    <div className="bg-white border border-slate-200 rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-4">
                        <div className="flex items-center gap-3 text-rose-600">
                            <div className="p-2.5 rounded-xl bg-rose-50 border border-rose-200">
                                <ShieldAlert className="w-5 h-5 text-rose-600" />
                            </div>
                            <h4 className="text-base font-bold text-slate-900">Xác Nhận Ngừng Áp Dụng</h4>
                        </div>
                        <p className="text-xs text-slate-600 leading-relaxed">
                            Bạn có chắc chắn muốn xóa mềm cấu hình biểu phí này? Dữ liệu lịch sử tính cước của các tháng trước vẫn được bảo toàn nguyên vẹn.
                        </p>
                        <div className="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                onClick={() => setDeleteConfirmId(null)}
                                className="px-3.5 py-2 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold text-xs shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                            >
                                Hủy bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleDeleteConfig}
                                className="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-sm transition-all active:scale-[0.98] cursor-pointer"
                            >
                                Đồng ý xóa
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ServicePricingManagement;
