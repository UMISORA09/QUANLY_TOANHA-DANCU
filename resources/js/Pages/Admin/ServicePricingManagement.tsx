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
    HelpCircle
} from 'lucide-react';
import {
    servicePricingApi,
    ServicePricingConfig,
    PricingTier,
    BillingType,
    MeterType,
    SimulationResult
} from '../../Services/servicePricingApi';

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

    // Tải danh sách biểu phí
    const fetchConfigs = async () => {
        try {
            setLoading(true);
            const res = await servicePricingApi.getPricingConfigs();
            if (res.success) {
                setConfigs(res.data);
            }
        } catch (error: any) {
            console.error('Lỗi tải danh mục đơn giá:', error);
            showToast('error', error?.response?.data?.message || 'Không thể tải danh mục biểu giá.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchConfigs();
    }, []);

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
        <div className="min-h-screen bg-slate-950 text-slate-100 p-6 space-y-6">
            {/* TOAST THÔNG BÁO */}
            {toast && (
                <div
                    className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl backdrop-blur-md border transition-all animate-in slide-in-from-bottom duration-300 ${
                        toast.type === 'success'
                            ? 'bg-emerald-950/80 border-emerald-500/40 text-emerald-200'
                            : 'bg-rose-950/80 border-rose-500/40 text-rose-200'
                    }`}
                >
                    {toast.type === 'success' ? <CheckCircle2 className="w-5 h-5 text-emerald-400" /> : <AlertCircle className="w-5 h-5 text-rose-400" />}
                    <span className="text-sm font-medium">{toast.message}</span>
                </div>
            )}

            {/* HEADER CHÍNH */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800/80 pb-6">
                <div>
                    <div className="flex items-center gap-3">
                        <div className="p-2.5 rounded-xl bg-gradient-to-tr from-amber-500/20 to-orange-500/10 border border-amber-500/30 text-amber-400 shadow-lg shadow-amber-500/5">
                            <Layers className="w-6 h-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                                Cài Đặt Đơn Giá Dịch Vụ & Biểu Giá Lũy Tiến
                                <span className="text-xs px-2.5 py-0.5 rounded-full bg-amber-500/10 text-amber-300 border border-amber-500/30 font-semibold">
                                    Module 4 • BQL
                                </span>
                            </h1>
                            <p className="text-sm text-slate-400 mt-0.5">
                                Quản trị đa tầng biểu phí Điện bậc thang EVN, Nước định mức sinh hoạt, Phí quản lý chung cư và Giữ xe
                            </p>
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        onClick={fetchConfigs}
                        disabled={loading}
                        className="px-3.5 py-2 rounded-xl bg-slate-900 border border-slate-800 hover:bg-slate-800 text-slate-300 text-sm font-medium transition flex items-center gap-2"
                        title="Tải lại dữ liệu"
                    >
                        <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin text-cyan-400' : ''}`} />
                        Làm mới
                    </button>
                    <button
                        onClick={handleOpenCreateModal}
                        className="px-4 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-400 hover:to-orange-500 text-slate-950 font-semibold text-sm shadow-lg shadow-amber-500/20 transition flex items-center gap-2"
                    >
                        <Plus className="w-4 h-4 text-slate-950" />
                        Thêm Biểu Phí Mới
                    </button>
                </div>
            </div>

            {/* KPI STATS CARDS */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                    <div className="flex items-center justify-between text-slate-400 mb-2">
                        <span className="text-xs font-medium uppercase tracking-wider">Tổng biểu phí</span>
                        <Layers className="w-4 h-4 text-cyan-400" />
                    </div>
                    <div className="text-2xl font-extrabold text-white">{stats.total}</div>
                    <div className="text-xs text-emerald-400 mt-1 flex items-center gap-1">
                        <CheckCircle2 className="w-3.5 h-3.5" />
                        {stats.activeCount} cấu hình đang áp dụng
                    </div>
                </div>

                <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                    <div className="flex items-center justify-between text-slate-400 mb-2">
                        <span className="text-xs font-medium uppercase tracking-wider">Điện bậc thang</span>
                        <Zap className="w-4 h-4 text-amber-400" />
                    </div>
                    <div className="text-2xl font-extrabold text-amber-300">
                        {configs.find(c => c.service_code === 'ELECTRICITY_RESIDENTIAL')?.tiers?.length || 6} Bậc
                    </div>
                    <div className="text-xs text-slate-400 mt-1">Biểu giá EVN lũy tiến</div>
                </div>

                <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                    <div className="flex items-center justify-between text-slate-400 mb-2">
                        <span className="text-xs font-medium uppercase tracking-wider">Nước định mức</span>
                        <Droplets className="w-4 h-4 text-sky-400" />
                    </div>
                    <div className="text-2xl font-extrabold text-sky-300">
                        {configs.find(c => c.service_code === 'WATER_RESIDENTIAL')?.tiers?.length || 4} Bậc
                    </div>
                    <div className="text-xs text-slate-400 mt-1">VAT 5% • Phí BVMT 10%</div>
                </div>

                <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                    <div className="flex items-center justify-between text-slate-400 mb-2">
                        <span className="text-xs font-medium uppercase tracking-wider">Phí Quản Lý</span>
                        <Building2 className="w-4 h-4 text-indigo-400" />
                    </div>
                    <div className="text-2xl font-extrabold text-indigo-300">
                        {formatCurrency(configs.find(c => c.service_code === 'MANAGEMENT_FEE')?.fixed_unit_price || 14000)}
                    </div>
                    <div className="text-xs text-slate-400 mt-1">Tính theo m2 căn hộ / tháng</div>
                </div>

                <div className="bg-slate-900/70 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                    <div className="flex items-center justify-between text-slate-400 mb-2">
                        <span className="text-xs font-medium uppercase tracking-wider">Phí Trông Giữ Xe</span>
                        <Car className="w-4 h-4 text-purple-400" />
                    </div>
                    <div className="text-2xl font-extrabold text-purple-300">
                        {formatCurrency(configs.find(c => c.service_code === 'PARKING_MOTORBIKE')?.fixed_unit_price || 120000)}
                    </div>
                    <div className="text-xs text-slate-400 mt-1">Ô tô: {formatCurrency(configs.find(c => c.service_code === 'PARKING_CAR')?.fixed_unit_price || 1500000)}</div>
                </div>
            </div>

            {/* BỘ LỌC VÀ TÌM KIẾM */}
            <div className="bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4 flex flex-col md:flex-row items-center gap-4 justify-between backdrop-blur-md">
                <div className="relative w-full md:w-80">
                    <Search className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        type="text"
                        placeholder="Tìm kiếm mã hoặc tên dịch vụ..."
                        value={searchTerm}
                        onChange={e => setSearchTerm(e.target.value)}
                        className="w-full pl-10 pr-4 py-2 bg-slate-950/80 border border-slate-800 rounded-xl text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-amber-500/50 transition"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
                    <select
                        value={filterBillingType}
                        onChange={e => setFilterBillingType(e.target.value)}
                        className="px-3.5 py-2 bg-slate-950/80 border border-slate-800 rounded-xl text-sm text-slate-300 focus:outline-none focus:border-amber-500/50 transition"
                    >
                        <option value="ALL">Mọi hình thức tính cước</option>
                        <option value="TIERED_USAGE">Lũy tiến bậc thang</option>
                        <option value="UNIT_PRICE_USAGE">Đơn giá theo tiêu thụ / m2</option>
                        <option value="FIXED_MONTHLY">Cố định hàng tháng</option>
                    </select>

                    <select
                        value={filterActive}
                        onChange={e => setFilterActive(e.target.value)}
                        className="px-3.5 py-2 bg-slate-950/80 border border-slate-800 rounded-xl text-sm text-slate-300 focus:outline-none focus:border-amber-500/50 transition"
                    >
                        <option value="ALL">Mọi trạng thái</option>
                        <option value="ACTIVE">Đang áp dụng</option>
                        <option value="INACTIVE">Tạm ngưng</option>
                    </select>
                </div>
            </div>

            {/* BẢNG DANH SÁCH BIỂU PHÍ */}
            <div className="bg-slate-900/60 border border-slate-800/80 rounded-2xl overflow-hidden backdrop-blur-md shadow-xl">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-slate-300">
                        <thead className="bg-slate-950/90 text-slate-400 uppercase text-[11px] tracking-wider border-b border-slate-800 font-semibold">
                            <tr>
                                <th className="py-3.5 px-4">Loại Dịch Vụ</th>
                                <th className="py-3.5 px-4">Hình Thức Tính</th>
                                <th className="py-3.5 px-4">Đơn Vị Tính</th>
                                <th className="py-3.5 px-4">Đơn Giá / Bậc Thang</th>
                                <th className="py-3.5 px-4">Thuế & Phí BVMT</th>
                                <th className="py-3.5 px-4">Ngày Hiệu Lực</th>
                                <th className="py-3.5 px-4 text-center">Trạng Thái</th>
                                <th className="py-3.5 px-4 text-right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800/60">
                            {loading ? (
                                <tr>
                                    <td colSpan={8} className="py-12 text-center text-slate-400">
                                        <div className="flex flex-col items-center justify-center gap-3">
                                            <RefreshCw className="w-6 h-6 animate-spin text-amber-500" />
                                            <span>Đang tải danh mục đơn giá dịch vụ...</span>
                                        </div>
                                    </td>
                                </tr>
                            ) : filteredConfigs.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="py-12 text-center text-slate-500">
                                        Không tìm thấy cấu hình đơn giá nào phù hợp với bộ lọc.
                                    </td>
                                </tr>
                            ) : (
                                filteredConfigs.map(item => {
                                    return (
                                        <tr key={item.id} className="hover:bg-slate-800/30 transition group">
                                            {/* Tên & Mã */}
                                            <td className="py-4 px-4">
                                                <div className="flex items-center gap-3">
                                                    <div className={`p-2 rounded-xl border ${
                                                        item.meter_type === 'ELECTRICITY'
                                                            ? 'bg-amber-500/10 border-amber-500/30 text-amber-400'
                                                            : item.meter_type === 'COLD_WATER'
                                                            ? 'bg-sky-500/10 border-sky-500/30 text-sky-400'
                                                            : item.service_code.includes('MANAGEMENT')
                                                            ? 'bg-indigo-500/10 border-indigo-500/30 text-indigo-400'
                                                            : 'bg-purple-500/10 border-purple-500/30 text-purple-400'
                                                    }`}>
                                                        {item.meter_type === 'ELECTRICITY' && <Zap className="w-4 h-4" />}
                                                        {item.meter_type === 'COLD_WATER' && <Droplets className="w-4 h-4" />}
                                                        {item.service_code.includes('MANAGEMENT') && <Building2 className="w-4 h-4" />}
                                                        {!item.meter_type && !item.service_code.includes('MANAGEMENT') && <Car className="w-4 h-4" />}
                                                    </div>
                                                    <div>
                                                        <div className="font-semibold text-white group-hover:text-amber-400 transition">
                                                            {item.service_name}
                                                        </div>
                                                        <div className="text-xs text-slate-400 font-mono">
                                                            {item.service_code}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            {/* Hình thức tính */}
                                            <td className="py-4 px-4">
                                                {item.billing_type === 'TIERED_USAGE' && (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-500/10 text-amber-300 border border-amber-500/30">
                                                        <TrendingUp className="w-3 h-3" />
                                                        Lũy tiến bậc thang
                                                    </span>
                                                )}
                                                {item.billing_type === 'UNIT_PRICE_USAGE' && (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-indigo-500/10 text-indigo-300 border border-indigo-500/30">
                                                        Theo diện tích / SL
                                                    </span>
                                                )}
                                                {item.billing_type === 'FIXED_MONTHLY' && (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-300 border border-emerald-500/30">
                                                        Cố định tháng
                                                    </span>
                                                )}
                                            </td>

                                            {/* Đơn vị tính */}
                                            <td className="py-4 px-4 font-medium text-slate-200">
                                                {item.unit_name}
                                            </td>

                                            {/* Đơn giá / Bậc thang */}
                                            <td className="py-4 px-4">
                                                {item.billing_type === 'TIERED_USAGE' ? (
                                                    <button
                                                        onClick={() => handleOpenTiersModal(item)}
                                                        className="text-xs px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-amber-300 border border-amber-500/30 flex items-center gap-1.5 font-medium transition"
                                                    >
                                                        <Layers className="w-3 h-3" />
                                                        {item.tiers?.length || 0} Bậc thang
                                                        <ChevronRight className="w-3 h-3 text-slate-400" />
                                                    </button>
                                                ) : (
                                                    <div className="font-semibold text-slate-100">
                                                        {formatCurrency(item.fixed_unit_price)}
                                                    </div>
                                                )}
                                            </td>

                                            {/* Thuế & Phí BVMT */}
                                            <td className="py-4 px-4 text-xs">
                                                <div className="text-slate-300 font-medium">VAT: {item.vat_percentage}%</div>
                                                {item.environmental_protection_fee_pct > 0 && (
                                                    <div className="text-sky-400 mt-0.5">BVMT: {item.environmental_protection_fee_pct}%</div>
                                                )}
                                            </td>

                                            {/* Ngày hiệu lực */}
                                            <td className="py-4 px-4 text-xs text-slate-400">
                                                <div>Từ: {item.effective_from_date || '2026-01-01'}</div>
                                                {item.effective_to_date && (
                                                    <div>Đến: {item.effective_to_date}</div>
                                                )}
                                            </td>

                                            {/* Trạng thái */}
                                            <td className="py-4 px-4 text-center">
                                                <button
                                                    onClick={() => handleToggleActive(item.id)}
                                                    className={`px-3 py-1 rounded-full text-xs font-semibold border transition ${
                                                        item.is_active
                                                            ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30 hover:bg-emerald-500/20'
                                                            : 'bg-rose-500/10 text-rose-300 border-rose-500/30 hover:bg-rose-500/20'
                                                    }`}
                                                >
                                                    {item.is_active ? 'Đang áp dụng' : 'Tạm dừng'}
                                                </button>
                                            </td>

                                            {/* Thao tác */}
                                            <td className="py-4 px-4 text-right">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    {/* Nút mô phỏng tính tiền */}
                                                    <button
                                                        onClick={() => handleOpenSimulateModal(item)}
                                                        className="p-1.5 rounded-lg bg-slate-800/80 hover:bg-amber-500/20 hover:text-amber-300 text-slate-400 transition"
                                                        title="Mô phỏng chiết tính hóa đơn"
                                                    >
                                                        <Calculator className="w-4 h-4" />
                                                    </button>

                                                    {/* Nút sửa bậc thang nếu là tiered */}
                                                    {item.billing_type === 'TIERED_USAGE' && (
                                                        <button
                                                            onClick={() => handleOpenTiersModal(item)}
                                                            className="p-1.5 rounded-lg bg-slate-800/80 hover:bg-indigo-500/20 hover:text-indigo-300 text-slate-400 transition"
                                                            title="Cấu hình bậc thang giá"
                                                        >
                                                            <Layers className="w-4 h-4" />
                                                        </button>
                                                    )}

                                                    {/* Nút chỉnh sửa */}
                                                    <button
                                                        onClick={() => handleOpenEditModal(item)}
                                                        className="p-1.5 rounded-lg bg-slate-800/80 hover:bg-cyan-500/20 hover:text-cyan-300 text-slate-400 transition"
                                                        title="Chỉnh sửa cấu hình"
                                                    >
                                                        <Edit2 className="w-4 h-4" />
                                                    </button>

                                                    {/* Nút xóa mềm */}
                                                    <button
                                                        onClick={() => setDeleteConfirmId(item.id)}
                                                        className="p-1.5 rounded-lg bg-slate-800/80 hover:bg-rose-500/20 hover:text-rose-400 text-slate-400 transition"
                                                        title="Xóa cấu hình"
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
            </div>

            {/* MODAL CẤU HÌNH BIỂU PHÍ (THÊM / SỬA) */}
            {isConfigModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-slate-900 border border-slate-800 rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl animate-in zoom-in-95 duration-200">
                        <div className="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                            <h3 className="text-lg font-bold text-white flex items-center gap-2">
                                <Layers className="w-5 h-5 text-amber-500" />
                                {editingConfig ? 'Chỉnh Sửa Cấu Hình Đơn Giá' : 'Thiết Lập Đơn Giá Dịch Vụ Mới'}
                            </h3>
                            <button
                                onClick={() => setIsConfigModalOpen(false)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveConfig} className="p-6 space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Mã loại dịch vụ <span className="text-rose-400">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="Ví dụ: ELECTRICITY_RESIDENTIAL"
                                        value={formData.service_code}
                                        onChange={e => setFormData({ ...formData, service_code: e.target.value.toUpperCase() })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm font-mono text-white focus:outline-none focus:border-amber-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Tên dịch vụ hiển thị <span className="text-rose-400">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="Ví dụ: Điện Sinh Hoạt Bậc Thang"
                                        value={formData.service_name}
                                        onChange={e => setFormData({ ...formData, service_name: e.target.value })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Hình thức tính cước <span className="text-rose-400">*</span>
                                    </label>
                                    <select
                                        value={formData.billing_type}
                                        onChange={e => setFormData({ ...formData, billing_type: e.target.value as BillingType })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition"
                                    >
                                        <option value="TIERED_USAGE">Lũy tiến bậc thang (Điện, Nước)</option>
                                        <option value="UNIT_PRICE_USAGE">Đơn giá theo diện tích / tiêu thụ</option>
                                        <option value="FIXED_MONTHLY">Cố định hàng tháng (Gửi xe, phí gói)</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Đơn vị tính <span className="text-rose-400">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="kWh, m3, m2/tháng, xe/tháng..."
                                        value={formData.unit_name}
                                        onChange={e => setFormData({ ...formData, unit_name: e.target.value })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition"
                                    />
                                </div>

                                {formData.billing_type !== 'TIERED_USAGE' && (
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                            Đơn giá cơ bản (VNĐ) <span className="text-rose-400">*</span>
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            step="100"
                                            value={formData.fixed_unit_price}
                                            onChange={e => setFormData({ ...formData, fixed_unit_price: parseFloat(e.target.value) || 0 })}
                                            className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition font-mono"
                                        />
                                    </div>
                                )}

                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Loại đồng hồ đo
                                    </label>
                                    <select
                                        value={formData.meter_type || ''}
                                        onChange={e => setFormData({ ...formData, meter_type: (e.target.value || null) as MeterType })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition"
                                    >
                                        <option value="">Không dùng công tơ đo</option>
                                        <option value="ELECTRICITY">Đồng hồ Điện (kWh)</option>
                                        <option value="COLD_WATER">Đồng hồ Nước Sạch (m3)</option>
                                        <option value="HOT_WATER">Đồng hồ Nước Nóng (m3)</option>
                                        <option value="GAS">Đồng hồ Khí Gas (m3)</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Thuế suất VAT (%)
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        max="100"
                                        value={formData.vat_percentage}
                                        onChange={e => setFormData({ ...formData, vat_percentage: parseFloat(e.target.value) || 0 })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Phí bảo vệ môi trường (%)
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        max="100"
                                        value={formData.environmental_protection_fee_pct}
                                        onChange={e => setFormData({ ...formData, environmental_protection_fee_pct: parseFloat(e.target.value) || 0 })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Ngày bắt đầu hiệu lực
                                    </label>
                                    <input
                                        type="date"
                                        value={formData.effective_from_date}
                                        onChange={e => setFormData({ ...formData, effective_from_date: e.target.value })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-300 uppercase mb-1.5">
                                        Ngày kết thúc (Tùy chọn)
                                    </label>
                                    <input
                                        type="date"
                                        value={formData.effective_to_date}
                                        onChange={e => setFormData({ ...formData, effective_to_date: e.target.value })}
                                        className="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:border-amber-500 transition"
                                    />
                                </div>
                            </div>

                            <div className="pt-4 border-t border-slate-800 flex items-center justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => setIsConfigModalOpen(false)}
                                    className="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-sm transition"
                                >
                                    Hủy bỏ
                                </button>
                                <button
                                    type="submit"
                                    className="px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-400 hover:to-orange-500 text-slate-950 font-semibold text-sm shadow-lg shadow-amber-500/20 transition flex items-center gap-2"
                                >
                                    <Save className="w-4 h-4" />
                                    {editingConfig ? 'Lưu Thay Đổi' : 'Tạo Cấu Hình'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL CẤU HÌNH BẬC THANG LŨY TIẾN (TIER EDITOR) */}
            {isTiersModalOpen && selectedConfigForTiers && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-slate-900 border border-slate-800 rounded-3xl w-full max-w-3xl overflow-hidden shadow-2xl animate-in zoom-in-95 duration-200">
                        <div className="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-bold text-white flex items-center gap-2">
                                    <TrendingUp className="w-5 h-5 text-amber-500" />
                                    Cấu Hình Định Mức Bậc Thang: {selectedConfigForTiers.service_name}
                                </h3>
                                <p className="text-xs text-slate-400 mt-0.5">
                                    Mã: {selectedConfigForTiers.service_code} • Đơn vị: {selectedConfigForTiers.unit_name}
                                </p>
                            </div>
                            <button
                                onClick={() => setIsTiersModalOpen(false)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="p-6 space-y-4">
                            <div className="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-xs text-amber-300 flex items-start gap-2.5">
                                <HelpCircle className="w-4 h-4 shrink-0 mt-0.5" />
                                <div>
                                    <strong>Quy tắc bậc thang liên tục:</strong> Bậc 1 bắt buộc bắt đầu từ 0. Ngưỡng bắt đầu (Min) của bậc sau phải bằng chính xác ngưỡng kết thúc (Max) của bậc trước. Bậc cuối cùng được để trống Max (không giới hạn).
                                </div>
                            </div>

                            <div className="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">
                                {tiersList.map((tier, idx) => (
                                    <div
                                        key={idx}
                                        className="p-3.5 bg-slate-950/80 border border-slate-800/80 rounded-2xl flex flex-col md:flex-row items-center gap-3 justify-between"
                                    >
                                        <div className="flex items-center gap-2 w-full md:w-36">
                                            <span className="w-6 h-6 rounded-full bg-amber-500/20 text-amber-400 text-xs font-bold flex items-center justify-center shrink-0">
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
                                                className="w-full px-2.5 py-1.5 bg-slate-900 border border-slate-800 rounded-lg text-xs font-semibold text-white focus:outline-none focus:border-amber-500 transition"
                                                placeholder="Tên bậc"
                                            />
                                        </div>

                                        <div className="flex items-center gap-2 w-full md:w-auto">
                                            <div className="flex items-center gap-1.5">
                                                <span className="text-xs text-slate-400">Từ:</span>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    value={tier.min_usage_threshold}
                                                    onChange={e => {
                                                        const updated = [...tiersList];
                                                        updated[idx].min_usage_threshold = parseFloat(e.target.value) || 0;
                                                        setTiersList(updated);
                                                    }}
                                                    className="w-20 px-2 py-1.5 bg-slate-900 border border-slate-800 rounded-lg text-xs font-mono text-white text-center focus:outline-none focus:border-amber-500 transition"
                                                />
                                            </div>

                                            <div className="flex items-center gap-1.5">
                                                <span className="text-xs text-slate-400">Đến:</span>
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
                                                    className="w-24 px-2 py-1.5 bg-slate-900 border border-slate-800 rounded-lg text-xs font-mono text-white text-center focus:outline-none focus:border-amber-500 transition"
                                                />
                                            </div>

                                            <div className="flex items-center gap-1.5">
                                                <span className="text-xs text-slate-400">Đơn giá:</span>
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
                                                    className="w-28 px-2 py-1.5 bg-slate-900 border border-slate-800 rounded-lg text-xs font-mono text-amber-300 font-bold text-right focus:outline-none focus:border-amber-500 transition"
                                                />
                                                <span className="text-xs text-slate-500">đ</span>
                                            </div>
                                        </div>

                                        <button
                                            onClick={() => handleRemoveTierRow(idx)}
                                            className="p-1.5 rounded-lg text-slate-500 hover:text-rose-400 hover:bg-slate-900 transition shrink-0"
                                            title="Xóa bậc này"
                                        >
                                            <Trash2 className="w-4 h-4" />
                                        </button>
                                    </div>
                                ))}
                            </div>

                            <div className="flex items-center justify-between pt-4 border-t border-slate-800">
                                <button
                                    onClick={handleAddTierRow}
                                    className="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-300 text-xs font-semibold border border-amber-500/30 flex items-center gap-2 transition"
                                >
                                    <Plus className="w-3.5 h-3.5" />
                                    Thêm Bậc Tiếp Theo
                                </button>

                                <div className="flex items-center gap-3">
                                    <button
                                        onClick={() => setIsTiersModalOpen(false)}
                                        className="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition"
                                    >
                                        Hủy
                                    </button>
                                    <button
                                        onClick={handleSaveTiers}
                                        disabled={tiersSaving}
                                        className="px-5 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-400 hover:to-orange-500 text-slate-950 font-semibold text-xs shadow-lg shadow-amber-500/20 transition flex items-center gap-2"
                                    >
                                        <Save className="w-4 h-4" />
                                        {tiersSaving ? 'Đang lưu...' : 'Lưu Danh Sách Bậc Thang'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL MÔ PHỎNG CHIẾT TÍNH TIỀN (SIMULATION CALCULATOR) */}
            {isSimulateModalOpen && simulateConfig && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-slate-900 border border-slate-800 rounded-3xl w-full max-w-2xl overflow-hidden shadow-2xl animate-in zoom-in-95 duration-200">
                        <div className="px-6 py-4 border-b border-slate-800 flex items-center justify-between">
                            <h3 className="text-lg font-bold text-white flex items-center gap-2">
                                <Calculator className="w-5 h-5 text-amber-500" />
                                Máy Tính Mô Phỏng Chiết Tính: {simulateConfig.service_name}
                            </h3>
                            <button
                                onClick={() => setIsSimulateModalOpen(false)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="p-6 space-y-5">
                            {/* Khung nhập sản lượng */}
                            <div className="p-4 bg-slate-950/80 border border-slate-800 rounded-2xl flex items-center gap-4 justify-between">
                                <div>
                                    <div className="text-xs text-slate-400 uppercase font-semibold">
                                        Nhập sản lượng tiêu thụ / diện tích:
                                    </div>
                                    <div className="text-sm text-slate-300 mt-0.5">
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
                                        className="w-28 px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-center text-lg font-mono font-bold text-amber-400 focus:outline-none focus:border-amber-500 transition"
                                    />
                                    <span className="text-sm font-semibold text-slate-400">{simulateConfig.unit_name}</span>
                                </div>
                            </div>

                            {/* Bảng chiết tính từng bậc */}
                            {simulationResult && (
                                <div className="space-y-4">
                                    {simulationResult.tier_breakdowns.length > 0 && (
                                        <div className="border border-slate-800 rounded-2xl overflow-hidden bg-slate-950/40">
                                            <table className="w-full text-left text-xs text-slate-300">
                                                <thead className="bg-slate-900 text-slate-400 uppercase tracking-wider font-semibold">
                                                    <tr>
                                                        <th className="py-2.5 px-3">Bậc Thang</th>
                                                        <th className="py-2.5 px-3 text-center">Định Mức</th>
                                                        <th className="py-2.5 px-3 text-right">Đơn Giá</th>
                                                        <th className="py-2.5 px-3 text-center">Sản Lượng</th>
                                                        <th className="py-2.5 px-3 text-right">Thành Tiền</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-800/60">
                                                    {simulationResult.tier_breakdowns.map(tb => (
                                                        <tr key={tb.tier_order} className={tb.tier_usage > 0 ? 'bg-amber-500/5' : 'opacity-40'}>
                                                            <td className="py-2 px-3 font-medium text-white">{tb.tier_name}</td>
                                                            <td className="py-2 px-3 text-center font-mono">
                                                                {tb.min_usage} - {tb.max_usage !== null ? tb.max_usage : '∞'}
                                                            </td>
                                                            <td className="py-2 px-3 text-right font-mono">{formatCurrency(tb.unit_price)}</td>
                                                            <td className="py-2 px-3 text-center font-mono font-bold text-amber-400">
                                                                {tb.tier_usage} {simulateConfig.unit_name}
                                                            </td>
                                                            <td className="py-2 px-3 text-right font-mono font-semibold text-white">
                                                                {formatCurrency(tb.tier_amount)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}

                                    {/* Tổng kết tiền */}
                                    <div className="p-4 bg-slate-950/80 border border-slate-800 rounded-2xl space-y-2 text-sm">
                                        <div className="flex justify-between text-slate-400">
                                            <span>Tiền trước thuế (Subtotal):</span>
                                            <span className="font-mono text-slate-200">{formatCurrency(simulationResult.subtotal)}</span>
                                        </div>
                                        <div className="flex justify-between text-slate-400">
                                            <span>Thuế VAT ({simulationResult.vat_percentage}%):</span>
                                            <span className="font-mono text-slate-200">{formatCurrency(simulationResult.vat_amount)}</span>
                                        </div>
                                        {simulationResult.environmental_fee > 0 && (
                                            <div className="flex justify-between text-sky-400">
                                                <span>Phí bảo vệ môi trường ({simulationResult.environmental_protection_fee_pct}%):</span>
                                                <span className="font-mono">{formatCurrency(simulationResult.environmental_fee)}</span>
                                            </div>
                                        )}
                                        <div className="pt-2 border-t border-slate-800 flex justify-between items-center text-base font-bold">
                                            <span className="text-white">TỔNG CỘNG THANH TOÁN:</span>
                                            <span className="text-xl font-extrabold text-amber-400 font-mono">
                                                {formatCurrency(simulationResult.total_amount)}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end pt-2">
                                <button
                                    onClick={() => setIsSimulateModalOpen(false)}
                                    className="px-5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition"
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
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-in fade-in duration-200">
                    <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 max-w-md w-full shadow-2xl space-y-4">
                        <div className="flex items-center gap-3 text-rose-400">
                            <div className="p-2.5 rounded-xl bg-rose-500/10 border border-rose-500/20">
                                <ShieldAlert className="w-6 h-6" />
                            </div>
                            <h4 className="text-lg font-bold text-white">Xác Nhận Ngừng Áp Dụng</h4>
                        </div>
                        <p className="text-sm text-slate-300">
                            Bạn có chắc chắn muốn xóa mềm cấu hình biểu phí này? Dữ liệu lịch sử tính cước của các tháng trước vẫn được bảo toàn nguyên vẹn.
                        </p>
                        <div className="flex items-center justify-end gap-3 pt-2">
                            <button
                                onClick={() => setDeleteConfirmId(null)}
                                className="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium transition"
                            >
                                Hủy bỏ
                            </button>
                            <button
                                onClick={handleDeleteConfig}
                                className="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-sm font-semibold shadow-lg shadow-rose-600/30 transition"
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
