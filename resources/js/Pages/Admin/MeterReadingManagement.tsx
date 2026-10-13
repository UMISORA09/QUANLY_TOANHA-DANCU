import React, { useState, useEffect, useMemo } from 'react';
import {
    Zap,
    Droplets,
    Activity,
    AlertTriangle,
    CheckCircle2,
    Lock,
    Unlock,
    Plus,
    Search,
    Edit2,
    Trash2,
    Camera,
    RefreshCw,
    X,
    Save,
    ChevronRight,
    Building2,
    Calendar,
    FileText,
    ArrowUpRight,
    HelpCircle,
    Eye,
    ShieldAlert,
    Clock,
    Sparkles,
    Check,
    Download,
    UploadCloud,
    FileSpreadsheet,
    FileWarning,
    CheckCheck
} from 'lucide-react';
import {
    meterReadingApi,
    MeterModel,
    MeterReadingModel,
    MeterReadingBatchModel,
    MeterSummaryData
} from '../../Services/meterReadingApi';

interface BlockOption {
    id: string;
    block_code: string;
    block_name: string;
}

export const MeterReadingManagement: React.FC = () => {
    // Thời gian hiện tại & Chu kỳ mặc định
    const now = new Date();
    const currentMonthStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

    // State bộ lọc chính
    const [selectedCycle, setSelectedCycle] = useState<string>(currentMonthStr);
    const [selectedBlock, setSelectedBlock] = useState<string>('ALL');
    const [selectedMeterType, setSelectedMeterType] = useState<string>('ALL');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [activeTab, setActiveTab] = useState<'meters' | 'readings' | 'abnormal' | 'import'>('meters');

    // Dữ liệu từ API
    const [summary, setSummary] = useState<MeterSummaryData | null>(null);
    const [meters, setMeters] = useState<MeterModel[]>([]);
    const [readings, setReadings] = useState<MeterReadingModel[]>([]);
    const [batches, setBatches] = useState<MeterReadingBatchModel[]>([]);
    const [blocks, setBlocks] = useState<BlockOption[]>([]);
    const [loading, setLoading] = useState<boolean>(true);
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });

    // State Import Excel
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importing, setImporting] = useState<boolean>(false);
    const [lastImportBatch, setLastImportBatch] = useState<MeterReadingBatchModel | null>(null);
    const [selectedBatchForDetail, setSelectedBatchForDetail] = useState<MeterReadingBatchModel | null>(null);

    // Toast thông báo
    const [toast, setToast] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

    // Modal chốt số thủ công
    const [isEntryModalOpen, setIsEntryModalOpen] = useState<boolean>(false);
    const [selectedMeterForEntry, setSelectedMeterForEntry] = useState<MeterModel | null>(null);
    const [entryForm, setEntryForm] = useState({
        meter_id: '',
        billing_cycle: currentMonthStr,
        previous_reading: 0,
        current_reading: 0,
        period_start_date: '',
        period_end_date: '',
        meter_photo_url: '',
        notes: '',
        force_reset: false,
    });
    const [photoFile, setPhotoFile] = useState<File | null>(null);
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState<boolean>(false);

    // Modal Khai báo đồng hồ mới
    const [isCreateMeterModalOpen, setIsCreateMeterModalOpen] = useState<boolean>(false);
    const [newMeterForm, setNewMeterForm] = useState({
        apartment_id: '',
        apartment_number: '',
        meter_type: 'WATER',
        meter_code: '',
        initial_reading: 0,
        multiplier_factor: 1,
        notes: '',
    });

    // Modal xem ảnh công tơ phóng to
    const [previewImageUrl, setPreviewImageUrl] = useState<string | null>(null);

    // Modal Xác nhận Khóa / Mở sổ
    const [isLockConfirmOpen, setIsLockConfirmOpen] = useState<boolean>(false);

    const showToast = (type: 'success' | 'error', message: string) => {
        setToast({ type, message });
        setTimeout(() => setToast(null), 4000);
    };

    // Tải dữ liệu ban đầu
    const fetchData = async () => {
        setLoading(true);
        try {
            const blockId = selectedBlock !== 'ALL' ? selectedBlock : undefined;
            
            // 1. Lấy Summary KPI
            const sumData = await meterReadingApi.getSummary(selectedCycle, blockId);
            setSummary(sumData);

            // 2. Lấy danh sách theo Tab
            if (activeTab === 'meters') {
                const meterRes = await meterReadingApi.getMeters({
                    cycle: selectedCycle,
                    block_id: blockId,
                    meter_type: selectedMeterType !== 'ALL' ? selectedMeterType : undefined,
                    search: searchQuery,
                    page: pagination.current_page,
                    per_page: 15,
                });
                setMeters(meterRes.data);
                setPagination(meterRes.meta);
            } else if (activeTab === 'import') {
                const batchRes = await meterReadingApi.getBatches({
                    cycle: selectedCycle,
                    block_id: blockId,
                    meter_type: selectedMeterType !== 'ALL' ? selectedMeterType : undefined,
                    page: pagination.current_page,
                    per_page: 10,
                });
                setBatches(batchRes.data);
                setPagination(batchRes.meta);
            } else {
                const readingRes = await meterReadingApi.getReadings({
                    cycle: selectedCycle,
                    block_id: blockId,
                    meter_type: selectedMeterType !== 'ALL' ? selectedMeterType : undefined,
                    is_abnormal: activeTab === 'abnormal' ? true : undefined,
                    search: searchQuery,
                    page: pagination.current_page,
                    per_page: 15,
                });
                setReadings(readingRes.data);
                setPagination(readingRes.meta);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi tải dữ liệu');
        } finally {
            setLoading(false);
        }
    };

    // Tải file mẫu CSV
    const handleDownloadTemplate = async () => {
        try {
            showToast('success', 'Đang kết xuất tệp mẫu CSV có sẵn dữ liệu căn hộ...');
            await meterReadingApi.downloadTemplate(selectedCycle, selectedBlock, selectedMeterType);
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi tải tệp mẫu');
        }
    };

    // Tiến hành import file Excel/CSV
    const handleExecuteImport = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!importFile) {
            showToast('error', 'Vui lòng chọn một tệp Excel hoặc CSV để tải lên.');
            return;
        }

        setImporting(true);
        try {
            const batch = await meterReadingApi.importReadings(
                importFile,
                selectedCycle,
                selectedBlock !== 'ALL' ? selectedBlock : undefined,
                selectedMeterType !== 'ALL' ? selectedMeterType : undefined
            );

            setLastImportBatch(batch);
            if (batch.failed_records === 0) {
                showToast('success', `Import thành công trọn vẹn ${batch.success_records}/${batch.total_records} bản ghi!`);
            } else {
                showToast('error', `Import hoàn tất có lỗi: ${batch.success_records} thành công, ${batch.failed_records} dòng lỗi.`);
            }

            setImportFile(null);
            fetchData();
        } catch (err: any) {
            showToast('error', err.message || 'Quá trình import thất bại.');
        } finally {
            setImporting(false);
        }
    };

    // Tải danh sách Block từ API
    useEffect(() => {
        const loadBlocks = async () => {
            try {
                const res = await fetch('/api/v1/blocks', {
                    headers: { 'Accept': 'application/json' }
                });
                const json = await res.json();
                if (json.success && Array.isArray(json.data)) {
                    setBlocks(json.data);
                }
            } catch (e) {
                // Ignore fallback
            }
        };
        loadBlocks();
    }, []);

    useEffect(() => {
        fetchData();
    }, [selectedCycle, selectedBlock, selectedMeterType, activeTab, pagination.current_page]);

    // Tìm kiếm với debounce
    useEffect(() => {
        const timer = setTimeout(() => {
            fetchData();
        }, 350);
        return () => clearTimeout(timer);
    }, [searchQuery]);

    // Mở form chốt số cho một đồng hồ cụ thể
    const handleOpenEntryModal = (meter: MeterModel) => {
        setSelectedMeterForEntry(meter);
        
        // Tìm xem đã có chỉ số kỳ này chưa
        const existingReading = meter.readings && meter.readings.length > 0 ? meter.readings[0] : null;
        const prev = existingReading ? existingReading.previous_reading : meter.current_reading;
        const curr = existingReading ? existingReading.current_reading : meter.current_reading;

        setEntryForm({
            meter_id: meter.id,
            billing_cycle: selectedCycle,
            previous_reading: prev,
            current_reading: curr,
            period_start_date: `${selectedCycle}-01`,
            period_end_date: `${selectedCycle}-28`,
            meter_photo_url: existingReading?.meter_photo_url || '',
            notes: existingReading?.abnormal_reason || '',
            force_reset: false,
        });
        setPhotoFile(null);
        setPhotoPreview(existingReading?.meter_photo_url || null);
        setIsEntryModalOpen(true);
    };

    // Tính toán tức thời lượng tiêu thụ trong Modal
    const calculatedConsumption = useMemo(() => {
        const mult = selectedMeterForEntry?.multiplier_factor || 1;
        if (entryForm.force_reset) {
            return Math.max(0, entryForm.current_reading * mult);
        }
        return Math.max(0, (entryForm.current_reading - entryForm.previous_reading) * mult);
    }, [entryForm.current_reading, entryForm.previous_reading, entryForm.force_reset, selectedMeterForEntry]);

    // Kiểm tra lỗi / cảnh báo tức thời
    const validationWarning = useMemo(() => {
        if (!entryForm.force_reset && entryForm.current_reading < entryForm.previous_reading) {
            return {
                type: 'error',
                text: `Chỉ số mới (${entryForm.current_reading}) không thể nhỏ hơn chỉ số cũ (${entryForm.previous_reading}). Nếu đã thay đồng hồ, vui lòng tích chọn "Xác nhận thay mới đồng hồ".`,
            };
        }
        if (selectedMeterForEntry?.meter_type === 'ELECTRICITY' && calculatedConsumption > 600) {
            return {
                type: 'warning',
                text: `Sản lượng điện tăng cao (${calculatedConsumption} kWh >= 600 kWh). Vui lòng kiểm tra lại ảnh chụp công tơ để tránh sai sót.`,
            };
        }
        if (selectedMeterForEntry?.meter_type !== 'ELECTRICITY' && calculatedConsumption > 45) {
            return {
                type: 'warning',
                text: `Sản lượng nước tiêu thụ cao (${calculatedConsumption} m³ >= 45 m³). Cần rà soát nguy cơ rò rỉ đường ống.`,
            };
        }
        return null;
    }, [entryForm.current_reading, entryForm.previous_reading, entryForm.force_reset, calculatedConsumption, selectedMeterForEntry]);

    // Xử lý nộp form chốt số
    const handleSubmitEntry = async (e: React.FormEvent, andNext = false) => {
        e.preventDefault();
        if (validationWarning?.type === 'error') {
            showToast('error', validationWarning.text);
            return;
        }

        setSubmitting(true);
        try {
            const formData = new FormData();
            formData.append('meter_id', entryForm.meter_id);
            formData.append('billing_cycle', entryForm.billing_cycle);
            formData.append('previous_reading', entryForm.previous_reading.toString());
            formData.append('current_reading', entryForm.current_reading.toString());
            formData.append('period_start_date', entryForm.period_start_date);
            formData.append('period_end_date', entryForm.period_end_date);
            formData.append('force_reset', entryForm.force_reset ? '1' : '0');
            if (entryForm.notes) formData.append('notes', entryForm.notes);
            if (photoFile) {
                formData.append('photo', photoFile);
            } else if (entryForm.meter_photo_url) {
                formData.append('meter_photo_url', entryForm.meter_photo_url);
            }

            await meterReadingApi.recordReading(formData);
            showToast('success', `Đã lưu chỉ số công tơ ${selectedMeterForEntry?.meter_code} (${calculatedConsumption} ${selectedMeterForEntry?.meter_type === 'ELECTRICITY' ? 'kWh' : 'm³'})`);
            
            fetchData();

            if (andNext) {
                // Tự động chuyển sang căn hộ kế tiếp trong danh sách chưa chốt
                const currentIndex = meters.findIndex(m => m.id === selectedMeterForEntry?.id);
                if (currentIndex >= 0 && currentIndex < meters.length - 1) {
                    handleOpenEntryModal(meters[currentIndex + 1]);
                } else {
                    setIsEntryModalOpen(false);
                }
            } else {
                setIsEntryModalOpen(false);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi lưu chỉ số');
        } finally {
            setSubmitting(false);
        }
    };

    // Khóa sổ / Mở khóa sổ kỳ này
    const handleToggleLockCycle = async () => {
        const blockId = selectedBlock !== 'ALL' ? selectedBlock : undefined;
        try {
            if (summary?.is_cycle_locked) {
                const res = await meterReadingApi.unlockCycle(selectedCycle, blockId);
                showToast('success', res.message);
            } else {
                const res = await meterReadingApi.lockCycle(selectedCycle, blockId);
                showToast('success', res.message);
            }
            setIsLockConfirmOpen(false);
            fetchData();
        } catch (err: any) {
            showToast('error', err.message || 'Thao tác khóa sổ thất bại');
        }
    };

    // Xóa bản ghi
    const handleDeleteReading = async (id: string) => {
        if (!confirm('Bạn có chắc chắn muốn xóa bản ghi chỉ số đo này?')) return;
        try {
            await meterReadingApi.deleteReading(id);
            showToast('success', 'Đã xóa bản ghi chỉ số thành công');
            fetchData();
        } catch (err: any) {
            showToast('error', err.message || 'Không thể xóa bản ghi');
        }
    };

    return (
        <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 transition-all duration-300">
            {/* Header & Title Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-slate-200/90">
                <div>
                    <div className="flex items-center gap-1.5 sm:gap-2 text-[11px] sm:text-xs font-bold uppercase tracking-wider font-mono text-sky-600">
                        <Zap className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-sky-500 shrink-0" />
                        <span>HỆ THỐNG GHI SỐ ĐIỆN NƯỚC & ĐỐI SOÁT</span>
                        <span className="text-slate-300">·</span>
                        <span className="text-slate-500">CHỨC NĂNG #4 (CHỐT CHỈ SỐ ĐIỆN NƯỚC THỦ CÔNG & KHÓA SỔ)</span>
                    </div>
                    <h1 className="text-xl sm:text-2xl lg:text-3xl font-black text-neutral-900 tracking-tight mt-1">
                        Chốt Chỉ Số Điện & Nước Thủ Công
                    </h1>
                    <p className="text-xs sm:text-sm text-slate-500 mt-1 max-w-3xl leading-relaxed">
                        Ghi số công tơ định kỳ theo căn hộ, kiểm soát biến động tiêu thụ bất thường, ảnh chụp đồng hồ và khóa sổ chu kỳ tính cước.
                    </p>
                </div>

                {/* Bộ điều khiển Chu kỳ & Thao tác Khóa sổ */}
                <div className="flex flex-wrap items-center gap-2.5 shrink-0">
                    {/* Chọn kỳ ghi số */}
                    <div className="flex items-center gap-2 bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs shadow-2xs">
                        <Calendar className="w-3.5 h-3.5 text-sky-600" />
                        <span className="text-[11px] text-slate-500 font-bold uppercase">Kỳ chốt:</span>
                        <input
                            type="month"
                            value={selectedCycle}
                            onChange={(e) => setSelectedCycle(e.target.value)}
                            className="bg-transparent border-0 text-slate-900 font-bold focus:ring-0 focus:outline-none text-xs cursor-pointer font-mono"
                        />
                    </div>

                    {/* Nút Khóa / Mở Khóa Sổ */}
                    <button
                        type="button"
                        onClick={() => setIsLockConfirmOpen(true)}
                        className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all border shadow-2xs cursor-pointer ${
                            summary?.is_cycle_locked
                                ? 'bg-rose-50 border-rose-200 text-rose-700 hover:bg-rose-100'
                                : 'bg-emerald-50 border-emerald-200 text-emerald-700 hover:bg-emerald-100'
                        }`}
                    >
                        {summary?.is_cycle_locked ? (
                            <>
                                <Lock className="w-3.5 h-3.5 text-rose-600" />
                                <span>Kỳ Đã Khóa Sổ</span>
                            </>
                        ) : (
                            <>
                                <Unlock className="w-3.5 h-3.5 text-emerald-600" />
                                <span>Sổ Đang Mở (Ghi Số)</span>
                            </>
                        )}
                    </button>

                    {/* Nút Refresh */}
                    <button
                        type="button"
                        onClick={fetchData}
                        disabled={loading}
                        title="Làm mới dữ liệu"
                        className="p-2 rounded-xl text-slate-500 hover:text-slate-800 bg-white border border-slate-200 hover:bg-slate-50 shadow-2xs transition-all active:scale-[0.98] cursor-pointer shrink-0"
                    >
                        <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin text-sky-600' : ''}`} />
                    </button>
                </div>
            </div>

            {/* KPI Overview Cards */}
            {summary && (
                <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3.5">
                    {/* KPI 1: Tiến độ ghi số */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Tiến Độ Ghi Số</span>
                            <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                                <Activity className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-neutral-900 font-mono">
                            {summary.completion_rate}% <span className="text-xs font-normal text-slate-400">({summary.recorded_meters}/{summary.total_meters})</span>
                        </div>
                        <div className="mt-2 w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                            <div
                                className="bg-gradient-to-r from-sky-500 to-indigo-600 h-1.5 rounded-full transition-all duration-500"
                                style={{ width: `${Math.min(100, summary.completion_rate)}%` }}
                            ></div>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-slate-500 flex justify-between">
                            <span>Còn lại: {summary.pending_meters} căn</span>
                            <span className="font-semibold text-sky-700">{summary.completion_rate === 100 ? 'Đã hoàn tất' : 'Đang xử lý'}</span>
                        </div>
                    </div>

                    {/* KPI 2: Tổng Điện Tiêu Thụ */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Sản Lượng Điện Kỳ Này</span>
                            <div className="kpi-icon-wrapper bg-amber-50 text-amber-600">
                                <Zap className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-amber-600 font-mono">
                            {summary.total_electricity_kwh.toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">kWh</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-amber-700 font-medium truncate">
                            EVN Biểu giá 6 bậc lũy tiến
                        </div>
                    </div>

                    {/* KPI 3: Tổng Nước Tiêu Thụ */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Sản Lượng Nước Kỳ Này</span>
                            <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                                <Droplets className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-sky-600 font-mono">
                            {summary.total_water_m3.toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">m³</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-sky-700 font-medium truncate">
                            Nước sinh hoạt định mức hộ
                        </div>
                    </div>

                    {/* KPI 4: Cảnh Báo Bất Thường */}
                    <div className="sass-db-kpi-card col-span-2 sm:col-span-1">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Cảnh Báo Bất Thường</span>
                            <div className="kpi-icon-wrapper bg-rose-50 text-rose-600">
                                <AlertTriangle className="w-4 h-4" />
                            </div>
                        </div>
                        <div className={`mt-1 sm:mt-2 text-xl sm:text-2xl font-black font-mono ${summary.abnormal_count > 0 ? 'text-rose-600' : 'text-neutral-900'}`}>
                            {summary.abnormal_count} <span className="text-xs font-normal text-slate-400">căn hộ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] truncate">
                            {summary.abnormal_count > 0 ? (
                                <span className="text-rose-600 font-bold">Cần kỹ thuật kiểm tra rò rỉ</span>
                            ) : (
                                <span className="text-emerald-700 font-medium">Chỉ số trong ngưỡng an toàn</span>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Thanh Lọc & Chuyển Tab */}
            <div className="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 bg-white p-3 rounded-2xl border border-slate-200/80 shadow-2xs">
                {/* Tabs chuyển đổi */}
                <div className="flex items-center gap-1 bg-slate-100/70 p-1 rounded-xl">
                    <button
                        type="button"
                        onClick={() => { setActiveTab('meters'); setPagination(p => ({ ...p, current_page: 1 })); }}
                        className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                            activeTab === 'meters'
                                ? 'bg-white text-sky-700 shadow-2xs'
                                : 'text-slate-600 hover:text-slate-900'
                        }`}
                    >
                        Danh Sách Đồng Hồ ({summary?.total_meters || 0})
                    </button>
                    <button
                        type="button"
                        onClick={() => { setActiveTab('readings'); setPagination(p => ({ ...p, current_page: 1 })); }}
                        className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                            activeTab === 'readings'
                                ? 'bg-white text-sky-700 shadow-2xs'
                                : 'text-slate-600 hover:text-slate-900'
                        }`}
                    >
                        Đã Chốt Trong Kỳ ({summary?.recorded_meters || 0})
                    </button>
                    <button
                        type="button"
                        onClick={() => { setActiveTab('abnormal'); setPagination(p => ({ ...p, current_page: 1 })); }}
                        className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer ${
                            activeTab === 'abnormal'
                                ? 'bg-rose-600 text-white shadow-2xs'
                                : 'text-rose-600 hover:bg-rose-50'
                        }`}
                    >
                        <ShieldAlert className="w-3.5 h-3.5" />
                        <span>Bất Thường ({summary?.abnormal_count || 0})</span>
                    </button>
                </div>

                {/* Bộ lọc Tòa nhà & Loại Đồng Hồ */}
                <div className="flex flex-wrap items-center gap-2.5">
                    {/* Tìm kiếm */}
                    <div className="relative min-w-[200px] flex-1 md:flex-initial">
                        <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Tìm mã công tơ, số căn hộ..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full pl-9 pr-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                        />
                    </div>

                    {/* Lọc Block */}
                    <select
                        value={selectedBlock}
                        onChange={(e) => { setSelectedBlock(e.target.value); setPagination(p => ({ ...p, current_page: 1 })); }}
                        className="bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-700 font-medium focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 cursor-pointer"
                    >
                        <option value="ALL">Tất cả Khối Tòa</option>
                        {blocks.map(b => (
                            <option key={b.id} value={b.id}>{b.block_name} ({b.block_code})</option>
                        ))}
                    </select>

                    {/* Lọc Loại đồng hồ */}
                    <select
                        value={selectedMeterType}
                        onChange={(e) => { setSelectedMeterType(e.target.value); setPagination(p => ({ ...p, current_page: 1 })); }}
                        className="bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-700 font-medium focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 cursor-pointer"
                    >
                        <option value="ALL">Tất cả Loại Dịch Vụ</option>
                        <option value="ELECTRICITY">⚡ Đồng Hồ Điện</option>
                        <option value="WATER">💧 Đồng Hồ Nước</option>
                    </select>
                </div>
            </div>

            {/* BẢNG DỮ LIỆU CHÍNH */}
            <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                {loading ? (
                    <div className="p-16 text-center text-slate-400">
                        <RefreshCw className="w-6 h-6 animate-spin mx-auto text-sky-600 mb-2" />
                        <p className="text-xs">Đang nạp dữ liệu đồng hồ và chỉ số đo...</p>
                    </div>
                ) : activeTab === 'meters' ? (
                    /* TAB 1: DANH SÁCH ĐỒNG HỒ & GHI SỐ NHANH */
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr className="border-b border-slate-200/80 bg-slate-100/50 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
                                    <th className="py-3 px-4">Căn Hộ</th>
                                    <th className="py-3 px-4">Mã Đồng Hồ</th>
                                    <th className="py-3 px-4">Loại Thiết Bị</th>
                                    <th className="py-3 px-4 text-right">Chỉ Số Kỳ Trước</th>
                                    <th className="py-3 px-4 text-right">Chỉ Số Kỳ Này</th>
                                    <th className="py-3 px-4 text-right">Tiêu Thụ</th>
                                    <th className="py-3 px-4 text-center">Trạng Thái</th>
                                    <th className="py-3 px-4 text-right">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {meters.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="py-12 text-center text-slate-400">
                                            Không tìm thấy đồng hồ đo nào phù hợp với bộ lọc.
                                        </td>
                                    </tr>
                                ) : (
                                    meters.map((meter) => {
                                        const reading = meter.readings && meter.readings.length > 0 ? meter.readings[0] : null;
                                        const isRecorded = Boolean(reading);
                                        const isElec = meter.meter_type === 'ELECTRICITY';

                                        return (
                                            <tr key={meter.id} className="hover:bg-slate-50/80 transition-colors">
                                                {/* Căn hộ */}
                                                <td className="py-3 px-4">
                                                    <div className="font-bold text-slate-900 flex items-center gap-1.5">
                                                        <Building2 className="w-3.5 h-3.5 text-sky-600" />
                                                        <span>Căn {meter.apartment?.apartment_number || 'N/A'}</span>
                                                    </div>
                                                    <div className="text-[11px] text-slate-400">
                                                        {meter.apartment?.block?.block_name || 'Khối'} - Tầng {meter.apartment?.floor?.floor_name || 'N/A'}
                                                    </div>
                                                </td>

                                                {/* Mã đồng hồ */}
                                                <td className="py-3 px-4 font-mono font-bold text-sky-700">
                                                    <span className="px-2 py-0.5 rounded-lg bg-sky-50 border border-sky-100">
                                                        {meter.meter_code}
                                                    </span>
                                                </td>

                                                {/* Loại thiết bị */}
                                                <td className="py-3 px-4">
                                                    {isElec ? (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                            <Zap className="w-3 h-3 text-amber-600" />
                                                            <span>Điện (kWh)</span>
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-sky-50 text-sky-700 border border-sky-200">
                                                            <Droplets className="w-3 h-3 text-sky-600" />
                                                            <span>Nước (m³)</span>
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Chỉ số cũ */}
                                                <td className="py-3 px-4 text-right font-mono text-slate-500 font-semibold">
                                                    {reading ? reading.previous_reading : meter.current_reading}
                                                </td>

                                                {/* Chỉ số mới */}
                                                <td className="py-3 px-4 text-right font-mono font-bold text-slate-900">
                                                    {reading ? (
                                                        reading.current_reading
                                                    ) : (
                                                        <span className="text-slate-400 font-normal italic">Chưa nhập</span>
                                                    )}
                                                </td>

                                                {/* Tiêu thụ */}
                                                <td className="py-3 px-4 text-right font-mono font-black">
                                                    {reading ? (
                                                        <span className={isElec ? 'text-amber-600' : 'text-sky-600'}>
                                                            +{reading.consumed_units} {isElec ? 'kWh' : 'm³'}
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400">-</span>
                                                    )}
                                                </td>

                                                {/* Trạng thái */}
                                                <td className="py-3 px-4 text-center">
                                                    {isRecorded ? (
                                                        reading?.is_abnormal_consumption ? (
                                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                                <AlertTriangle className="w-3 h-3 text-rose-600" />
                                                                <span>Bất thường</span>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                                <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                                                                <span>Đã chốt</span>
                                                            </span>
                                                        )
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 border border-slate-200">
                                                            <Clock className="w-3 h-3 text-slate-400" />
                                                            <span>Chờ chốt</span>
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Thao tác */}
                                                <td className="py-3 px-4 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenEntryModal(meter)}
                                                        className={`px-3 py-1 rounded-xl text-xs font-bold transition-all inline-flex items-center gap-1 cursor-pointer ${
                                                            isRecorded
                                                                ? 'bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-2xs'
                                                                : 'bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white shadow-sm'
                                                        }`}
                                                    >
                                                        {isRecorded ? (
                                                            <>
                                                                <Edit2 className="w-3 h-3" />
                                                                <span>Sửa Số</span>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <Plus className="w-3 h-3" />
                                                                <span>Ghi Số</span>
                                                            </>
                                                        )}
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    /* TAB 2 & 3: DANH SÁCH BẢN GHI ĐÃ CHỐT HOẶC BẤT THƯỜNG */
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr className="border-b border-slate-200/80 bg-slate-100/50 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
                                    <th className="py-3 px-4">Căn Hộ</th>
                                    <th className="py-3 px-4">Đồng Hồ</th>
                                    <th className="py-3 px-4">Loại</th>
                                    <th className="py-3 px-4 text-right">Chỉ Số Cũ</th>
                                    <th className="py-3 px-4 text-right">Chỉ Số Mới</th>
                                    <th className="py-3 px-4 text-right">Tiêu Thụ</th>
                                    <th className="py-3 px-4 text-center">Ảnh Đối Soát</th>
                                    <th className="py-3 px-4">Cảnh Báo / Ghi Chú</th>
                                    <th className="py-3 px-4 text-right">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {readings.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="py-12 text-center text-slate-400">
                                            Chưa có bản ghi chỉ số đo nào trong danh sách.
                                        </td>
                                    </tr>
                                ) : (
                                    readings.map((reading) => {
                                        const isElec = reading.meter?.meter_type === 'ELECTRICITY';

                                        return (
                                            <tr key={reading.id} className="hover:bg-slate-50/80 transition-colors">
                                                {/* Căn hộ */}
                                                <td className="py-3 px-4">
                                                    <div className="font-bold text-slate-900 flex items-center gap-1.5">
                                                        <Building2 className="w-3.5 h-3.5 text-sky-600" />
                                                        <span>Căn {reading.apartment?.apartment_number || 'N/A'}</span>
                                                    </div>
                                                    <div className="text-[11px] text-slate-400">
                                                        {reading.apartment?.block?.block_name || 'Khối'}
                                                    </div>
                                                </td>

                                                {/* Mã đồng hồ */}
                                                <td className="py-3 px-4 font-mono font-bold text-sky-700">
                                                    <span className="px-2 py-0.5 rounded-lg bg-sky-50 border border-sky-100">
                                                        {reading.meter?.meter_code || 'MTR-N/A'}
                                                    </span>
                                                </td>

                                                {/* Loại */}
                                                <td className="py-3 px-4">
                                                    {isElec ? (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                            <Zap className="w-3 h-3 text-amber-600" />
                                                            <span>Điện</span>
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-sky-50 text-sky-700 border border-sky-200">
                                                            <Droplets className="w-3 h-3 text-sky-600" />
                                                            <span>Nước</span>
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Cũ */}
                                                <td className="py-3 px-4 text-right font-mono text-slate-500 font-semibold">
                                                    {reading.previous_reading}
                                                </td>

                                                {/* Mới */}
                                                <td className="py-3 px-4 text-right font-mono font-bold text-slate-900">
                                                    {reading.current_reading}
                                                </td>

                                                {/* Tiêu thụ */}
                                                <td className="py-3 px-4 text-right font-mono font-black">
                                                    <span className={isElec ? 'text-amber-600' : 'text-sky-600'}>
                                                        {reading.consumed_units} {isElec ? 'kWh' : 'm³'}
                                                    </span>
                                                </td>

                                                {/* Ảnh công tơ */}
                                                <td className="py-3 px-4 text-center">
                                                    {reading.meter_photo_url ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => setPreviewImageUrl(reading.meter_photo_url)}
                                                            className="inline-flex items-center gap-1 text-xs text-sky-600 hover:text-sky-800 underline font-semibold cursor-pointer"
                                                        >
                                                            <Camera className="w-3.5 h-3.5" />
                                                            <span>Xem ảnh</span>
                                                        </button>
                                                    ) : (
                                                        <span className="text-slate-400">Không có</span>
                                                    )}
                                                </td>

                                                {/* Cảnh báo / Lý do */}
                                                <td className="py-3 px-4">
                                                    {reading.is_abnormal_consumption ? (
                                                        <div className="flex items-start gap-1.5 text-xs text-rose-700 bg-rose-50 border border-rose-200 p-1.5 rounded-lg">
                                                            <AlertTriangle className="w-3.5 h-3.5 text-rose-600 shrink-0 mt-0.5" />
                                                            <span>{reading.abnormal_reason || 'Đột biến bất thường'}</span>
                                                        </div>
                                                    ) : (
                                                        <span className="text-slate-500">{reading.reading_source || 'Thủ công'}</span>
                                                    )}
                                                </td>

                                                {/* Thao tác */}
                                                <td className="py-3 px-4 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {reading.meter && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleOpenEntryModal(reading.meter!)}
                                                                className="p-1.5 rounded-lg bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 shadow-2xs transition-all cursor-pointer"
                                                                title="Sửa bản ghi"
                                                            >
                                                                <Edit2 className="w-3.5 h-3.5" />
                                                            </button>
                                                        )}
                                                        <button
                                                            type="button"
                                                            onClick={() => handleDeleteReading(reading.id)}
                                                            disabled={reading.is_locked_for_billing}
                                                            className={`p-1.5 rounded-lg transition-all ${
                                                                reading.is_locked_for_billing
                                                                    ? 'text-slate-300 cursor-not-allowed'
                                                                    : 'bg-white hover:bg-rose-50 text-rose-600 border border-rose-200 shadow-2xs cursor-pointer'
                                                            }`}
                                                            title={reading.is_locked_for_billing ? 'Đã khóa sổ' : 'Xóa bản ghi'}
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
                )}

                {/* Phân trang */}
                {pagination.total > 15 && (
                    <div className="flex items-center justify-between p-4 bg-slate-50/70 border-t border-slate-100 text-xs text-slate-500">
                        <span>
                            Hiển thị trang {pagination.current_page} / {pagination.last_page} ({pagination.total} bản ghi)
                        </span>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setPagination(p => ({ ...p, current_page: Math.max(1, p.current_page - 1) }))}
                                disabled={pagination.current_page <= 1}
                                className="px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs cursor-pointer"
                            >
                                Trang trước
                            </button>
                            <button
                                type="button"
                                onClick={() => setPagination(p => ({ ...p, current_page: Math.min(pagination.last_page, p.current_page + 1) }))}
                                disabled={pagination.current_page >= pagination.last_page}
                                className="px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs cursor-pointer"
                            >
                                Trang sau
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* MODAL 1: FORM CHỐT CHỈ SỐ THỦ CÔNG THÔNG MINH */}
            {isEntryModalOpen && selectedMeterForEntry && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-xl overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between p-5 border-b border-slate-100 bg-slate-50/70">
                            <div className="flex items-center gap-3">
                                <div className={`p-2 rounded-xl border ${
                                    selectedMeterForEntry.meter_type === 'ELECTRICITY'
                                        ? 'bg-amber-50 border-amber-200 text-amber-600'
                                        : 'bg-sky-50 border-sky-200 text-sky-600'
                                }`}>
                                    {selectedMeterForEntry.meter_type === 'ELECTRICITY' ? <Zap className="w-5 h-5" /> : <Droplets className="w-5 h-5" />}
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-slate-900">
                                        Chốt Chỉ Số: Căn {selectedMeterForEntry.apartment?.apartment_number}
                                    </h3>
                                    <p className="text-xs text-slate-500 font-mono">
                                        Mã công tơ: {selectedMeterForEntry.meter_code} ({selectedMeterForEntry.meter_type})
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setIsEntryModalOpen(false)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        {/* Modal Form */}
                        <form onSubmit={(e) => handleSubmitEntry(e, false)} className="p-6 space-y-4">
                            {/* Cảnh báo Validation / Bất thường nếu có */}
                            {validationWarning && (
                                <div className={`p-3.5 rounded-xl text-xs flex items-start gap-2.5 border ${
                                    validationWarning.type === 'error'
                                        ? 'bg-rose-50 border-rose-200 text-rose-700'
                                        : 'bg-amber-50 border-amber-200 text-amber-800'
                                }`}>
                                <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                                <div>{validationWarning.text}</div>
                            </div>
                            )}

                            {/* Hàng: Chỉ số cũ vs Chỉ số mới */}
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1">
                                    <label className="text-xs font-bold text-slate-700 uppercase">
                                        Chỉ Số Cũ (Kỳ Trước)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={entryForm.previous_reading}
                                        onChange={(e) => setEntryForm({ ...entryForm, previous_reading: parseFloat(e.target.value) || 0 })}
                                        className="w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-sm font-mono font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                                    />
                                    <p className="text-[10px] text-slate-400">Chỉ số chốt kỳ liền trước</p>
                                </div>

                                <div className="space-y-1">
                                    <label className="text-xs font-bold text-sky-700 uppercase flex items-center justify-between">
                                        <span>Chỉ Số Mới (*)</span>
                                        <span className="text-[10px] font-normal lowercase text-slate-400">hôm nay</span>
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        autoFocus
                                        required
                                        value={entryForm.current_reading}
                                        onChange={(e) => setEntryForm({ ...entryForm, current_reading: parseFloat(e.target.value) || 0 })}
                                        className="w-full bg-white border-2 border-sky-500 rounded-xl px-3 py-2 text-sm font-mono font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                                    />
                                    <p className="text-[10px] text-slate-400">Mặt số công tơ hiện tại</p>
                                </div>
                            </div>

                            {/* Card Hiển thị Lượng tiêu thụ tự động */}
                            <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between">
                                <div>
                                    <span className="text-xs text-slate-500 font-medium">Sản lượng tiêu thụ tính toán:</span>
                                    <div className="text-xl font-black font-mono mt-0.5 flex items-baseline gap-1.5">
                                        <span className={selectedMeterForEntry.meter_type === 'ELECTRICITY' ? 'text-amber-600' : 'text-sky-600'}>
                                            +{calculatedConsumption.toLocaleString('vi-VN')}
                                        </span>
                                        <span className="text-xs font-bold text-slate-500">
                                            {selectedMeterForEntry.meter_type === 'ELECTRICITY' ? 'kWh' : 'm³'}
                                        </span>
                                    </div>
                                </div>
                                <div className="text-right text-xs text-slate-500">
                                    <div>Hệ số nhân: x{selectedMeterForEntry.multiplier_factor}</div>
                                    <div className="text-slate-700 font-mono mt-0.5 font-semibold">
                                        {entryForm.current_reading} - {entryForm.previous_reading}
                                    </div>
                                </div>
                            </div>

                            {/* Tùy chọn Thay mới đồng hồ (Force reset) */}
                            <div className="flex items-center gap-2 p-2.5 rounded-xl bg-slate-50 border border-slate-200">
                                <input
                                    type="checkbox"
                                    id="forceResetCheckbox"
                                    checked={entryForm.force_reset}
                                    onChange={(e) => setEntryForm({ ...entryForm, force_reset: e.target.checked })}
                                    className="w-4 h-4 rounded text-sky-600 focus:ring-sky-500 border-slate-300"
                                />
                                <label htmlFor="forceResetCheckbox" className="text-xs text-slate-700 font-medium cursor-pointer">
                                    Xác nhận vừa thay mới đồng hồ (Cho phép chỉ số mới nhỏ hơn chỉ số cũ)
                                </label>
                            </div>

                            {/* Đính kèm ảnh công tơ */}
                            <div className="space-y-1">
                                <label className="text-xs font-bold text-slate-700 uppercase flex items-center gap-1.5">
                                    <Camera className="w-3.5 h-3.5 text-sky-600" />
                                    <span>Ảnh Chụp Mặt Đồng Hồ (Đối Soát Minh Bạch)</span>
                                </label>
                                <div className="flex items-center gap-3">
                                    <input
                                        type="file"
                                        accept="image/*"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0] || null;
                                            setPhotoFile(file);
                                            if (file) {
                                                setPhotoPreview(URL.createObjectURL(file));
                                            }
                                        }}
                                        className="block w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 cursor-pointer"
                                    />
                                    {photoPreview && (
                                        <button
                                            type="button"
                                            onClick={() => setPreviewImageUrl(photoPreview)}
                                            className="px-2.5 py-1 rounded-lg bg-sky-50 border border-sky-200 text-xs text-sky-700 hover:bg-sky-100 whitespace-nowrap font-bold"
                                        >
                                            Xem trước
                                        </button>
                                    )}
                                </div>
                            </div>

                            {/* Ghi chú */}
                            <div className="space-y-1">
                                <label className="text-xs font-bold text-slate-700 uppercase">
                                    Ghi Chú Kỹ Thuật Viên
                                </label>
                                <textarea
                                    rows={2}
                                    placeholder="Lý do tiêu thụ cao, tình trạng công tơ, v.v..."
                                    value={entryForm.notes}
                                    onChange={(e) => setEntryForm({ ...entryForm, notes: e.target.value })}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                                />
                            </div>

                            {/* Nút Submit */}
                            <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100 bg-slate-50/50 -mx-6 -mb-6 p-4">
                                <button
                                    type="button"
                                    onClick={() => setIsEntryModalOpen(false)}
                                    className="px-3.5 py-2 rounded-xl text-xs font-bold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-2xs cursor-pointer"
                                >
                                    Hủy Bỏ
                                </button>
                                <button
                                    type="button"
                                    onClick={(e) => handleSubmitEntry(e, true)}
                                    disabled={submitting}
                                    className="px-3.5 py-2 rounded-xl text-xs font-bold bg-white border border-slate-200 text-sky-700 hover:bg-sky-50 shadow-2xs inline-flex items-center gap-1.5 cursor-pointer"
                                >
                                    <Save className="w-3.5 h-3.5" />
                                    <span>Lưu & Tiếp Căn Kế</span>
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="px-4 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white shadow-sm inline-flex items-center gap-1.5 cursor-pointer"
                                >
                                    {submitting ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : <Check className="w-3.5 h-3.5" />}
                                    <span>Lưu Chỉ Số</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL 2: XEM ẢNH CÔNG TƠ PHÓNG TO */}
            {previewImageUrl && (
                <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="relative max-w-2xl w-full bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-2xl">
                        <div className="flex items-center justify-between p-4 border-b border-slate-100 bg-slate-50/70">
                            <h4 className="text-xs font-bold text-slate-900 flex items-center gap-2">
                                <Camera className="w-4 h-4 text-sky-600" />
                                <span>Ảnh Chụp Bằng Chứng Công Tơ</span>
                            </h4>
                            <button
                                type="button"
                                onClick={() => setPreviewImageUrl(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>
                        <div className="p-4 flex items-center justify-center bg-slate-50 max-h-[70vh] overflow-auto">
                            <img
                                src={previewImageUrl}
                                alt="Ảnh công tơ đối soát"
                                className="max-h-[60vh] object-contain rounded-xl shadow-md border border-slate-200"
                            />
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 3: XÁC NHẬN KHÓA SỔ / MỞ KHÓA KỲ */}
            {isLockConfirmOpen && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
                        <div className="flex items-center gap-3">
                            <div className={`p-2.5 rounded-xl border ${
                                summary?.is_cycle_locked
                                    ? 'bg-emerald-50 border-emerald-200 text-emerald-600'
                                    : 'bg-rose-50 border-rose-200 text-rose-600'
                            }`}>
                                {summary?.is_cycle_locked ? <Unlock className="w-5 h-5" /> : <Lock className="w-5 h-5" />}
                            </div>
                            <div>
                                <h4 className="text-base font-bold text-slate-900">
                                    {summary?.is_cycle_locked ? 'Mở Khóa Sổ Kỳ Chốt Số' : 'Khóa Sổ Kỳ Chốt Số'}
                                </h4>
                                <p className="text-xs text-slate-500 font-mono">Chu kỳ: {selectedCycle}</p>
                            </div>
                        </div>

                        <p className="text-xs text-slate-600 leading-relaxed">
                            {summary?.is_cycle_locked ? (
                                'Mở khóa sổ sẽ cho phép ban quản lý tiếp tục chỉnh sửa hoặc nhập bổ sung chỉ số đo của các căn hộ.'
                            ) : (
                                'Khi đã khóa sổ, toàn bộ chỉ số đo của kỳ này sẽ được niêm phong để chuyển sang Module Sinh Hóa Đơn Tự Động. Nhân viên sẽ không thể tự ý sửa hoặc xóa chỉ số nữa.'
                            )}
                        </p>

                        <div className="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                onClick={() => setIsLockConfirmOpen(false)}
                                className="px-3.5 py-2 rounded-xl text-xs font-bold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 shadow-2xs cursor-pointer"
                            >
                                Hủy Bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleToggleLockCycle}
                                className={`px-4 py-2 rounded-xl text-xs font-bold text-white shadow-sm cursor-pointer ${
                                    summary?.is_cycle_locked
                                        ? 'bg-emerald-600 hover:bg-emerald-700'
                                        : 'bg-rose-600 hover:bg-rose-700'
                                }`}
                            >
                                {summary?.is_cycle_locked ? 'Xác Nhận Mở Sổ' : 'Xác Nhận Khóa Sổ'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 4: XEM CHI TIẾT ĐỢT IMPORT & LỖI */}
            {selectedBatchForDetail && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl space-y-4 p-6">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                            <div>
                                <h4 className="text-base font-bold text-white flex items-center gap-2">
                                    <FileWarning className="w-5 h-5 text-rose-400" />
                                    <span>Chi Tiết Lỗi Đợt Import: {selectedBatchForDetail.batch_code}</span>
                                </h4>
                                <p className="text-xs text-slate-400 mt-0.5">Tệp gốc: {selectedBatchForDetail.file_name}</p>
                            </div>
                            <button
                                onClick={() => setSelectedBatchForDetail(null)}
                                className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="max-h-80 overflow-y-auto border border-slate-800 rounded-xl overflow-hidden">
                            <table className="w-full text-left text-xs text-slate-300">
                                <thead className="bg-slate-950/80 uppercase font-semibold text-slate-400 sticky top-0">
                                    <tr>
                                        <th className="p-3">Dòng</th>
                                        <th className="p-3">Mã Đồng Hồ</th>
                                        <th className="p-3">Căn Hộ</th>
                                        <th className="p-3">Nguyên Nhân Lỗi Cụ Thể</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800">
                                    {selectedBatchForDetail.error_summary_json?.map((err, i) => (
                                        <tr key={i} className="hover:bg-slate-800/40">
                                            <td className="p-3 font-mono font-bold text-indigo-400">Dòng {err.line}</td>
                                            <td className="p-3 font-mono text-slate-200">{err.meter_code}</td>
                                            <td className="p-3 text-slate-300">{err.apartment || 'N/A'}</td>
                                            <td className="p-3 text-rose-400 font-medium">{err.error}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex justify-end pt-2">
                            <button
                                onClick={() => setSelectedBatchForDetail(null)}
                                className="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-200"
                            >
                                Đóng
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Toast Thông báo */}
            {toast && (
                <div
                    className={`fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-lg flex items-center gap-3 border animate-in slide-in-from-bottom duration-200 ${
                        toast.type === 'success'
                            ? 'bg-emerald-50 text-emerald-800 border-emerald-300'
                            : 'bg-rose-50 text-rose-800 border-rose-300'
                    }`}
                >
                    {toast.type === 'success' ? <CheckCircle2 className="w-4 h-4 text-emerald-600" /> : <AlertTriangle className="w-4 h-4 text-rose-600" />}
                    <span className="text-xs font-semibold">{toast.message}</span>
                </div>
            )}
        </div>
    );
};

export default MeterReadingManagement;
