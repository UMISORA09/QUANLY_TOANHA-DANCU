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
        <div className="min-h-screen bg-slate-950 text-slate-100 p-4 md:p-8 font-sans selection:bg-indigo-500 selection:text-white">
            {/* Header & Title Bar */}
            <div className="max-w-7xl mx-auto space-y-6">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-slate-800/80 pb-6">
                    <div>
                        <div className="flex items-center gap-3">
                            <div className="p-2.5 rounded-xl bg-gradient-to-tr from-amber-500/20 to-indigo-500/20 border border-amber-500/30 text-amber-400 shadow-lg shadow-amber-500/10">
                                <Zap className="w-7 h-7" />
                            </div>
                            <div>
                                <h1 className="text-2xl md:text-3xl font-extrabold tracking-tight bg-gradient-to-r from-white via-slate-100 to-slate-400 bg-clip-text text-transparent">
                                    Chốt Chỉ Số Điện & Nước Thủ Công
                                </h1>
                                <p className="text-sm text-slate-400 mt-1 flex items-center gap-2">
                                    <span>Phân hệ Quản lý & Kỹ thuật Tòa nhà</span>
                                    <span className="inline-block w-1 h-1 rounded-full bg-slate-600"></span>
                                    <span className="text-indigo-400 font-medium">Module 5 - Ghi số công tơ & Đối soát</span>
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Bộ điều khiển Chu kỳ & Thao tác Khóa sổ */}
                    <div className="flex flex-wrap items-center gap-3">
                        {/* Chọn kỳ ghi số */}
                        <div className="flex items-center gap-2 bg-slate-900 border border-slate-700/80 rounded-xl px-3 py-2 text-sm shadow-inner">
                            <Calendar className="w-4 h-4 text-indigo-400" />
                            <span className="text-xs text-slate-400 font-medium uppercase">Kỳ chốt:</span>
                            <input
                                type="month"
                                value={selectedCycle}
                                onChange={(e) => setSelectedCycle(e.target.value)}
                                className="bg-transparent border-0 text-slate-100 font-semibold focus:ring-0 focus:outline-none text-sm cursor-pointer"
                            />
                        </div>

                        {/* Nút Khóa / Mở Khóa Sổ */}
                        <button
                            onClick={() => setIsLockConfirmOpen(true)}
                            className={`flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold transition-all shadow-md ${
                                summary?.is_cycle_locked
                                    ? 'bg-rose-500/15 border border-rose-500/40 text-rose-300 hover:bg-rose-500/25 shadow-rose-950/40'
                                    : 'bg-emerald-500/15 border border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/25 shadow-emerald-950/40'
                            }`}
                        >
                            {summary?.is_cycle_locked ? (
                                <>
                                    <Lock className="w-4 h-4 text-rose-400" />
                                    <span>Kỳ Đã Khóa Sổ</span>
                                </>
                            ) : (
                                <>
                                    <Unlock className="w-4 h-4 text-emerald-400" />
                                    <span>Sổ Đang Mở (Ghi Số)</span>
                                </>
                            )}
                        </button>

                        {/* Nút Refresh */}
                        <button
                            onClick={fetchData}
                            disabled={loading}
                            title="Làm mới dữ liệu"
                            className="p-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-300 hover:text-white transition-colors"
                        >
                            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
                        </button>
                    </div>
                </div>

                {/* KPI Overview Cards */}
                {summary && (
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        {/* KPI 1: Tiến độ ghi số */}
                        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-b from-slate-900/90 to-slate-900/50 border border-slate-800 p-5 shadow-xl backdrop-blur-md">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Tiến Độ Ghi Số</span>
                                <div className="p-2 rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                                    <Activity className="w-4 h-4" />
                                </div>
                            </div>
                            <div className="mt-3 flex items-baseline gap-2">
                                <span className="text-3xl font-extrabold text-white">{summary.completion_rate}%</span>
                                <span className="text-xs text-slate-400">
                                    ({summary.recorded_meters}/{summary.total_meters} đồng hồ)
                                </span>
                            </div>
                            <div className="mt-3 w-full bg-slate-800 rounded-full h-2 overflow-hidden">
                                <div
                                    className="bg-gradient-to-r from-indigo-500 to-cyan-400 h-2 rounded-full transition-all duration-700"
                                    style={{ width: `${Math.min(100, summary.completion_rate)}%` }}
                                ></div>
                            </div>
                            <div className="mt-2 text-xs text-slate-500 flex justify-between">
                                <span>Còn lại: {summary.pending_meters} căn</span>
                                <span>{summary.completion_rate === 100 ? 'Đã hoàn tất' : 'Đang xử lý'}</span>
                            </div>
                        </div>

                        {/* KPI 2: Tổng Điện Tiêu Thụ */}
                        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-b from-slate-900/90 to-slate-900/50 border border-slate-800 p-5 shadow-xl backdrop-blur-md">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Sản Lượng Điện Kỳ Này</span>
                                <div className="p-2 rounded-lg bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                    <Zap className="w-4 h-4" />
                                </div>
                            </div>
                            <div className="mt-3 flex items-baseline gap-1.5">
                                <span className="text-3xl font-extrabold text-amber-400">
                                    {summary.total_electricity_kwh.toLocaleString('vi-VN')}
                                </span>
                                <span className="text-sm font-semibold text-slate-400">kWh</span>
                            </div>
                            <p className="mt-3 text-xs text-slate-400 flex items-center gap-1">
                                <span className="text-emerald-400 font-medium">EVN Biểu giá 6 bậc</span>
                                <span className="text-slate-600">•</span>
                                <span>Sẵn sàng kết xuất hóa đơn</span>
                            </p>
                        </div>

                        {/* KPI 3: Tổng Nước Tiêu Thụ */}
                        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-b from-slate-900/90 to-slate-900/50 border border-slate-800 p-5 shadow-xl backdrop-blur-md">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Sản Lượng Nước Kỳ Này</span>
                                <div className="p-2 rounded-lg bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                                    <Droplets className="w-4 h-4" />
                                </div>
                            </div>
                            <div className="mt-3 flex items-baseline gap-1.5">
                                <span className="text-3xl font-extrabold text-cyan-400">
                                    {summary.total_water_m3.toLocaleString('vi-VN')}
                                </span>
                                <span className="text-sm font-semibold text-slate-400">m³</span>
                            </div>
                            <p className="mt-3 text-xs text-slate-400 flex items-center gap-1">
                                <span className="text-cyan-300 font-medium">Nước sinh hoạt</span>
                                <span className="text-slate-600">•</span>
                                <span>Tính theo định mức hộ</span>
                            </p>
                        </div>

                        {/* KPI 4: Cảnh Báo Bất Thường */}
                        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-b from-slate-900/90 to-slate-900/50 border border-slate-800 p-5 shadow-xl backdrop-blur-md">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Cảnh Báo Bất Thường</span>
                                <div className="p-2 rounded-lg bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                    <AlertTriangle className="w-4 h-4" />
                                </div>
                            </div>
                            <div className="mt-3 flex items-baseline gap-2">
                                <span className={`text-3xl font-extrabold ${summary.abnormal_count > 0 ? 'text-rose-400' : 'text-slate-200'}`}>
                                    {summary.abnormal_count}
                                </span>
                                <span className="text-xs text-slate-400">căn hộ phát hiện đột biến</span>
                            </div>
                            <p className="mt-3 text-xs text-slate-400 flex items-center gap-1">
                                {summary.abnormal_count > 0 ? (
                                    <span className="text-rose-400 font-medium">Cần kỹ thuật kiểm tra rò rỉ/công tơ</span>
                                ) : (
                                    <span className="text-emerald-400 font-medium">Tất cả chỉ số trong ngưỡng bình thường</span>
                                )}
                            </p>
                        </div>
                    </div>
                )}

                {/* Thanh Lọc & Chuyển Tab */}
                <div className="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4 bg-slate-900/80 p-3 rounded-2xl border border-slate-800 shadow-md">
                    {/* Tabs chuyển đổi */}
                    <div className="flex items-center gap-1 bg-slate-950/80 p-1 rounded-xl border border-slate-800/80">
                        <button
                            onClick={() => { setActiveTab('meters'); setPagination(p => ({ ...p, current_page: 1 })); }}
                            className={`px-4 py-2 rounded-lg text-sm font-semibold transition-all ${
                                activeTab === 'meters'
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'
                            }`}
                        >
                            Danh Sách Đồng Hồ ({summary?.total_meters || 0})
                        </button>
                        <button
                            onClick={() => { setActiveTab('readings'); setPagination(p => ({ ...p, current_page: 1 })); }}
                            className={`px-4 py-2 rounded-lg text-sm font-semibold transition-all ${
                                activeTab === 'readings'
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'
                            }`}
                        >
                            Đã Chốt Trong Kỳ ({summary?.recorded_meters || 0})
                        </button>
                        <button
                            onClick={() => { setActiveTab('abnormal'); setPagination(p => ({ ...p, current_page: 1 })); }}
                            className={`px-4 py-2 rounded-lg text-sm font-semibold transition-all flex items-center gap-1.5 ${
                                activeTab === 'abnormal'
                                    ? 'bg-rose-600 text-white shadow-md shadow-rose-600/30'
                                    : 'text-rose-400 hover:text-rose-300 hover:bg-slate-900'
                            }`}
                        >
                            <ShieldAlert className="w-4 h-4" />
                            <span>Bất Thường ({summary?.abnormal_count || 0})</span>
                        </button>
                        <button
                            onClick={() => { setActiveTab('import'); setPagination(p => ({ ...p, current_page: 1 })); }}
                            className={`px-4 py-2 rounded-lg text-sm font-semibold transition-all flex items-center gap-1.5 ${
                                activeTab === 'import'
                                    ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/30'
                                    : 'text-emerald-400 hover:text-emerald-300 hover:bg-slate-900'
                            }`}
                        >
                            <FileSpreadsheet className="w-4 h-4" />
                            <span>Import Excel / CSV</span>
                        </button>
                    </div>

                    {/* Bộ lọc Tòa nhà & Loại Đồng Hồ & Nút Tải Mẫu */}
                    <div className="flex flex-wrap items-center gap-3">
                        {/* Nút Tải File Mẫu */}
                        <button
                            onClick={handleDownloadTemplate}
                            title="Tải tệp mẫu CSV/Excel có sẵn danh sách công tơ căn hộ"
                            className="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 hover:text-white transition-colors"
                        >
                            <Download className="w-3.5 h-3.5 text-indigo-400" />
                            <span>Tải Mẫu Excel</span>
                        </button>

                        {/* Tìm kiếm */}
                        <div className="relative min-w-[180px] flex-1 md:flex-initial">
                            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
                            <input
                                type="text"
                                placeholder="Tìm mã công tơ, căn hộ..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                className="w-full pl-9 pr-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all"
                            />
                        </div>

                        {/* Lọc Block */}
                        <select
                            value={selectedBlock}
                            onChange={(e) => { setSelectedBlock(e.target.value); setPagination(p => ({ ...p, current_page: 1 })); }}
                            className="bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-slate-300 focus:outline-none focus:border-indigo-500"
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
                            className="bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-slate-300 focus:outline-none focus:border-indigo-500"
                        >
                            <option value="ALL">Tất cả Loại Dịch Vụ</option>
                            <option value="ELECTRICITY">⚡ Đồng Hồ Điện</option>
                            <option value="WATER">💧 Đồng Hồ Nước</option>
                        </select>
                    </div>
                </div>

                {/* BẢNG DỮ LIỆU CHÍNH */}
                <div className="bg-slate-900/90 rounded-2xl border border-slate-800 shadow-2xl overflow-hidden backdrop-blur-md">
                    {loading ? (
                        <div className="p-16 text-center text-slate-400">
                            <RefreshCw className="w-8 h-8 animate-spin mx-auto text-indigo-500 mb-3" />
                            <p className="text-sm">Đang nạp dữ liệu đồng hồ và chỉ số đo...</p>
                        </div>
                    ) : activeTab === 'meters' ? (
                        /* TAB 1: DANH SÁCH ĐỒNG HỒ & GHI SỐ NHANH */
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm text-slate-300">
                                <thead className="bg-slate-950/70 border-b border-slate-800 text-xs font-semibold uppercase text-slate-400">
                                    <tr>
                                        <th className="py-3.5 px-4">Căn Hộ</th>
                                        <th className="py-3.5 px-4">Mã Đồng Hồ</th>
                                        <th className="py-3.5 px-4">Loại Thiết Bị</th>
                                        <th className="py-3.5 px-4 text-right">Chỉ Số Kỳ Trước</th>
                                        <th className="py-3.5 px-4 text-right">Chỉ Số Kỳ Này</th>
                                        <th className="py-3.5 px-4 text-right">Tiêu Thụ</th>
                                        <th className="py-3.5 px-4 text-center">Trạng Thái</th>
                                        <th className="py-3.5 px-4 text-right">Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800/60">
                                    {meters.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-12 text-center text-slate-500">
                                                Không tìm thấy đồng hồ đo nào phù hợp với bộ lọc.
                                            </td>
                                        </tr>
                                    ) : (
                                        meters.map((meter) => {
                                            const reading = meter.readings && meter.readings.length > 0 ? meter.readings[0] : null;
                                            const isRecorded = Boolean(reading);
                                            const isElec = meter.meter_type === 'ELECTRICITY';

                                            return (
                                                <tr key={meter.id} className="hover:bg-slate-800/40 transition-colors">
                                                    {/* Căn hộ */}
                                                    <td className="py-3.5 px-4">
                                                        <div className="font-bold text-white flex items-center gap-1.5">
                                                            <Building2 className="w-4 h-4 text-indigo-400" />
                                                            <span>Căn {meter.apartment?.apartment_number || 'N/A'}</span>
                                                        </div>
                                                        <div className="text-xs text-slate-400">
                                                            {meter.apartment?.block?.block_name || 'Khối'} - Tầng {meter.apartment?.floor?.floor_name || 'N/A'}
                                                        </div>
                                                    </td>

                                                    {/* Mã đồng hồ */}
                                                    <td className="py-3.5 px-4">
                                                        <span className="font-mono text-xs font-semibold px-2 py-0.5 rounded bg-slate-800 text-slate-200 border border-slate-700">
                                                            {meter.meter_code}
                                                        </span>
                                                    </td>

                                                    {/* Loại thiết bị */}
                                                    <td className="py-3.5 px-4">
                                                        {isElec ? (
                                                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                                <Zap className="w-3.5 h-3.5" />
                                                                <span>Điện (kWh)</span>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                                                                <Droplets className="w-3.5 h-3.5" />
                                                                <span>Nước (m³)</span>
                                                            </span>
                                                        )}
                                                    </td>

                                                    {/* Chỉ số cũ */}
                                                    <td className="py-3.5 px-4 text-right font-mono text-slate-400">
                                                        {reading ? reading.previous_reading : meter.current_reading}
                                                    </td>

                                                    {/* Chỉ số mới */}
                                                    <td className="py-3.5 px-4 text-right font-mono font-bold text-white">
                                                        {reading ? (
                                                            reading.current_reading
                                                        ) : (
                                                            <span className="text-slate-600 font-normal italic">Chưa nhập</span>
                                                        )}
                                                    </td>

                                                    {/* Tiêu thụ */}
                                                    <td className="py-3.5 px-4 text-right font-mono font-extrabold">
                                                        {reading ? (
                                                            <span className={isElec ? 'text-amber-400' : 'text-cyan-400'}>
                                                                +{reading.consumed_units} {isElec ? 'kWh' : 'm³'}
                                                            </span>
                                                        ) : (
                                                            <span className="text-slate-600">-</span>
                                                        )}
                                                    </td>

                                                    {/* Trạng thái */}
                                                    <td className="py-3.5 px-4 text-center">
                                                        {isRecorded ? (
                                                            reading?.is_abnormal_consumption ? (
                                                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-500/15 text-rose-300 border border-rose-500/30">
                                                                    <AlertTriangle className="w-3 h-3 text-rose-400" />
                                                                    <span>Bất thường</span>
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                                                                    <CheckCircle2 className="w-3 h-3 text-emerald-400" />
                                                                    <span>Đã chốt</span>
                                                                </span>
                                                            )
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-800 text-slate-400 border border-slate-700">
                                                                <Clock className="w-3 h-3 text-slate-500" />
                                                                <span>Chờ chốt</span>
                                                            </span>
                                                        )}
                                                    </td>

                                                    {/* Thao tác */}
                                                    <td className="py-3.5 px-4 text-right">
                                                        <button
                                                            onClick={() => handleOpenEntryModal(meter)}
                                                            className={`px-3 py-1.5 rounded-xl text-xs font-semibold transition-all inline-flex items-center gap-1.5 ${
                                                                isRecorded
                                                                    ? 'bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700'
                                                                    : 'bg-indigo-600 hover:bg-indigo-500 text-white shadow-md shadow-indigo-600/30 font-bold'
                                                            }`}
                                                        >
                                                            {isRecorded ? (
                                                                <>
                                                                    <Edit2 className="w-3.5 h-3.5" />
                                                                    <span>Sửa Số</span>
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <Plus className="w-3.5 h-3.5" />
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
                    ) : activeTab === 'readings' || activeTab === 'abnormal' ? (
                        /* TAB 2 & 3: DANH SÁCH BẢN GHI ĐÃ CHỐT HOẶC BẤT THƯỜNG */
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm text-slate-300">
                                <thead className="bg-slate-950/70 border-b border-slate-800 text-xs font-semibold uppercase text-slate-400">
                                    <tr>
                                        <th className="py-3.5 px-4">Căn Hộ</th>
                                        <th className="py-3.5 px-4">Đồng Hồ</th>
                                        <th className="py-3.5 px-4">Loại</th>
                                        <th className="py-3.5 px-4 text-right">Chỉ Số Cũ</th>
                                        <th className="py-3.5 px-4 text-right">Chỉ Số Mới</th>
                                        <th className="py-3.5 px-4 text-right">Tiêu Thụ</th>
                                        <th className="py-3.5 px-4 text-center">Ảnh Đối Soát</th>
                                        <th className="py-3.5 px-4">Cảnh Báo / Ghi Chú</th>
                                        <th className="py-3.5 px-4 text-right">Thao Tác</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800/60">
                                    {readings.length === 0 ? (
                                        <tr>
                                            <td colSpan={9} className="py-12 text-center text-slate-500">
                                                Chưa có bản ghi chỉ số đo nào trong danh sách.
                                            </td>
                                        </tr>
                                    ) : (
                                        readings.map((reading) => {
                                            const isElec = reading.meter?.meter_type === 'ELECTRICITY';

                                            return (
                                                <tr key={reading.id} className="hover:bg-slate-800/40 transition-colors">
                                                    {/* Căn hộ */}
                                                    <td className="py-3.5 px-4">
                                                        <div className="font-bold text-white flex items-center gap-1.5">
                                                            <Building2 className="w-4 h-4 text-indigo-400" />
                                                            <span>Căn {reading.apartment?.apartment_number || 'N/A'}</span>
                                                        </div>
                                                        <div className="text-xs text-slate-400">
                                                            {reading.apartment?.block?.block_name || 'Khối'}
                                                        </div>
                                                    </td>

                                                    {/* Mã đồng hồ */}
                                                    <td className="py-3.5 px-4 font-mono text-xs text-slate-300">
                                                        {reading.meter?.meter_code || 'MTR-N/A'}
                                                    </td>

                                                    {/* Loại */}
                                                    <td className="py-3.5 px-4">
                                                        {isElec ? (
                                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                                <Zap className="w-3 h-3" />
                                                                <span>Điện</span>
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                                                                <Droplets className="w-3 h-3" />
                                                                <span>Nước</span>
                                                            </span>
                                                        )}
                                                    </td>

                                                    {/* Cũ */}
                                                    <td className="py-3.5 px-4 text-right font-mono text-slate-400">
                                                        {reading.previous_reading}
                                                    </td>

                                                    {/* Mới */}
                                                    <td className="py-3.5 px-4 text-right font-mono font-bold text-white">
                                                        {reading.current_reading}
                                                    </td>

                                                    {/* Tiêu thụ */}
                                                    <td className="py-3.5 px-4 text-right font-mono font-extrabold">
                                                        <span className={isElec ? 'text-amber-400' : 'text-cyan-400'}>
                                                            {reading.consumed_units} {isElec ? 'kWh' : 'm³'}
                                                        </span>
                                                    </td>

                                                    {/* Ảnh công tơ */}
                                                    <td className="py-3.5 px-4 text-center">
                                                        {reading.meter_photo_url ? (
                                                            <button
                                                                onClick={() => setPreviewImageUrl(reading.meter_photo_url)}
                                                                className="inline-flex items-center gap-1 text-xs text-indigo-400 hover:text-indigo-300 underline font-medium"
                                                            >
                                                                <Camera className="w-3.5 h-3.5" />
                                                                <span>Xem ảnh</span>
                                                            </button>
                                                        ) : (
                                                            <span className="text-xs text-slate-600">Không có</span>
                                                        )}
                                                    </td>

                                                    {/* Cảnh báo / Lý do */}
                                                    <td className="py-3.5 px-4">
                                                        {reading.is_abnormal_consumption ? (
                                                            <div className="flex items-start gap-1.5 text-xs text-rose-300 bg-rose-500/10 border border-rose-500/20 p-1.5 rounded-lg">
                                                                <AlertTriangle className="w-4 h-4 text-rose-400 shrink-0 mt-0.5" />
                                                                <span>{reading.abnormal_reason || 'Đột biến bất thường'}</span>
                                                            </div>
                                                        ) : (
                                                            <span className="text-xs text-slate-400">{reading.reading_source || 'Thủ công'}</span>
                                                        )}
                                                    </td>

                                                    {/* Thao tác */}
                                                    <td className="py-3.5 px-4 text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            {reading.meter && (
                                                                <button
                                                                    onClick={() => handleOpenEntryModal(reading.meter!)}
                                                                    className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white"
                                                                    title="Sửa bản ghi"
                                                                >
                                                                    <Edit2 className="w-4 h-4" />
                                                                </button>
                                                            )}
                                                            <button
                                                                onClick={() => handleDeleteReading(reading.id)}
                                                                disabled={reading.is_locked_for_billing}
                                                                className={`p-1.5 rounded-lg transition-colors ${
                                                                    reading.is_locked_for_billing
                                                                        ? 'text-slate-600 cursor-not-allowed'
                                                                        : 'bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 hover:text-rose-300 border border-rose-500/20'
                                                                }`}
                                                                title={reading.is_locked_for_billing ? 'Đã khóa sổ' : 'Xóa bản ghi'}
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
                    ) : (
                        /* TAB 4: IMPORT HÀNG LOẠT TỪ EXCEL / CSV */
                        <div className="p-6 space-y-6">
                            {/* Khu vực Upload & Hướng dẫn */}
                            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                {/* Cột 1 & 2: Form Upload File */}
                                <div className="lg:col-span-2 bg-slate-950/60 border border-slate-800 rounded-2xl p-6">
                                    <div className="flex items-center justify-between pb-4 border-b border-slate-800">
                                        <div className="flex items-center gap-2.5">
                                            <div className="p-2 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                <UploadCloud className="w-5 h-5" />
                                            </div>
                                            <div>
                                                <h3 className="text-base font-bold text-white">Tải Lên Tệp Chỉ Số Đo</h3>
                                                <p className="text-xs text-slate-400">Hỗ trợ định dạng .csv, .txt tương thích Microsoft Excel</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={handleDownloadTemplate}
                                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-indigo-300 border border-slate-700 transition-colors"
                                        >
                                            <Download className="w-3.5 h-3.5" />
                                            <span>Tải Mẫu Excel</span>
                                        </button>
                                    </div>

                                    <form onSubmit={handleExecuteImport} className="mt-5 space-y-4">
                                        <div className="border-2 border-dashed border-slate-700/80 hover:border-indigo-500/60 rounded-2xl p-8 text-center bg-slate-900/40 hover:bg-slate-900/70 transition-all cursor-pointer relative">
                                            <input
                                                type="file"
                                                accept=".csv,.txt"
                                                onChange={(e) => setImportFile(e.target.files?.[0] || null)}
                                                className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                            />
                                            <div className="space-y-2 pointer-events-none">
                                                <div className="w-12 h-12 rounded-full bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 flex items-center justify-center mx-auto">
                                                    <FileSpreadsheet className="w-6 h-6" />
                                                </div>
                                                {importFile ? (
                                                    <div>
                                                        <p className="text-sm font-bold text-white">{importFile.name}</p>
                                                        <p className="text-xs text-emerald-400 font-medium mt-0.5">
                                                            {(importFile.size / 1024).toFixed(1)} KB - Đã sẵn sàng import
                                                        </p>
                                                    </div>
                                                ) : (
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-200">
                                                            Kéo thả tệp vào đây hoặc <span className="text-indigo-400 underline">chọn từ thiết bị</span>
                                                        </p>
                                                        <p className="text-xs text-slate-500 mt-1">Dung lượng tối đa 10MB</p>
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex items-center justify-between pt-2">
                                            <div className="text-xs text-slate-400">
                                                Kỳ chốt số mục tiêu: <span className="font-bold text-indigo-400 font-mono">{selectedCycle}</span>
                                            </div>
                                            <button
                                                type="submit"
                                                disabled={!importFile || importing || summary?.is_cycle_locked}
                                                className={`px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow-lg transition-all inline-flex items-center gap-2 ${
                                                    !importFile || summary?.is_cycle_locked
                                                        ? 'bg-slate-800 text-slate-500 cursor-not-allowed'
                                                        : 'bg-emerald-600 hover:bg-emerald-500 shadow-emerald-600/30'
                                                }`}
                                            >
                                                {importing ? (
                                                    <>
                                                        <RefreshCw className="w-4 h-4 animate-spin" />
                                                        <span>Đang phân tích & lưu...</span>
                                                    </>
                                                ) : (
                                                    <>
                                                        <CheckCheck className="w-4 h-4" />
                                                        <span>Bắt Đầu Import & Đối Soát</span>
                                                    </>
                                                )}
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                {/* Cột 3: Hướng Dẫn & Quy Tắc */}
                                <div className="bg-slate-950/60 border border-slate-800 rounded-2xl p-5 space-y-3">
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                                        <HelpCircle className="w-4 h-4 text-indigo-400" />
                                        <span>Quy Tắc Import & Validate</span>
                                    </h4>
                                    <ul className="text-xs text-slate-300 space-y-2.5">
                                        <li className="flex items-start gap-2">
                                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 mt-1.5 shrink-0"></span>
                                            <span>File mẫu chứa đúng mã công tơ của từng căn hộ để tránh nhầm lẫn.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 mt-1.5 shrink-0"></span>
                                            <span><strong>Chỉ số mới &ge; Chỉ số cũ:</strong> Hệ thống tự động chặn nếu chỉ số mới thấp hơn chỉ số cũ.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="w-1.5 h-1.5 rounded-full bg-amber-400 mt-1.5 shrink-0"></span>
                                            <span><strong>Thay đồng hồ:</strong> Điền giá trị <code>1</code> tại cột <i>Thay Đồng Hồ Mới</i> nếu vừa thay công tơ.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="w-1.5 h-1.5 rounded-full bg-rose-400 mt-1.5 shrink-0"></span>
                                            <span><strong>Khóa sổ:</strong> Không thể import khi chu kỳ đã được ban quản lý khóa sổ.</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            {/* Báo Cáo Kết Quả Đợt Import Mới Nhất (Nếu có) */}
                            {lastImportBatch && (
                                <div className="p-5 rounded-2xl bg-slate-950/80 border border-slate-800 space-y-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-bold text-white">Kết Quả Đợt Import:</span>
                                            <span className="font-mono text-xs px-2 py-0.5 rounded bg-slate-800 text-indigo-300">
                                                {lastImportBatch.batch_code}
                                            </span>
                                        </div>
                                        <span className={`px-2.5 py-0.5 rounded-full text-xs font-bold ${
                                            lastImportBatch.import_status === 'COMPLETED'
                                                ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                                                : 'bg-rose-500/15 text-rose-300 border border-rose-500/30'
                                        }`}>
                                            {lastImportBatch.import_status}
                                        </span>
                                    </div>

                                    {/* Chỉ số nhanh */}
                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="bg-slate-900 p-3 rounded-xl border border-slate-800 text-center">
                                            <div className="text-xs text-slate-400 font-medium">Tổng Bản Ghi</div>
                                            <div className="text-xl font-extrabold text-white mt-1">{lastImportBatch.total_records}</div>
                                        </div>
                                        <div className="bg-emerald-950/20 p-3 rounded-xl border border-emerald-500/20 text-center">
                                            <div className="text-xs text-emerald-400 font-medium">Thành Công</div>
                                            <div className="text-xl font-extrabold text-emerald-400 mt-1">{lastImportBatch.success_records}</div>
                                        </div>
                                        <div className="bg-rose-950/20 p-3 rounded-xl border border-rose-500/20 text-center">
                                            <div className="text-xs text-rose-400 font-medium">Dòng Lỗi Bị Chặn</div>
                                            <div className="text-xl font-extrabold text-rose-400 mt-1">{lastImportBatch.failed_records}</div>
                                        </div>
                                    </div>

                                    {/* Bảng Chi Tiết Lỗi (nếu có lỗi) */}
                                    {lastImportBatch.error_summary_json && lastImportBatch.error_summary_json.length > 0 && (
                                        <div className="space-y-2 pt-2">
                                            <div className="flex items-center gap-1.5 text-xs font-bold text-rose-400 uppercase tracking-wider">
                                                <FileWarning className="w-4 h-4" />
                                                <span>Chi Tiết {lastImportBatch.error_summary_json.length} Dòng Bị Lỗi Cần Sửa:</span>
                                            </div>
                                            <div className="max-h-60 overflow-y-auto border border-rose-500/20 rounded-xl overflow-hidden">
                                                <table className="w-full text-left text-xs text-slate-300">
                                                    <thead className="bg-rose-950/30 text-rose-300 uppercase font-semibold">
                                                        <tr>
                                                            <th className="p-2.5">Dòng Excel</th>
                                                            <th className="p-2.5">Mã Đồng Hồ</th>
                                                            <th className="p-2.5">Căn Hộ</th>
                                                            <th className="p-2.5">Nguyên Nhân Lỗi</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-800">
                                                        {lastImportBatch.error_summary_json.map((err, idx) => (
                                                            <tr key={idx} className="hover:bg-rose-500/5">
                                                                <td className="p-2.5 font-mono text-slate-400 font-bold">Dòng {err.line}</td>
                                                                <td className="p-2.5 font-mono text-slate-200">{err.meter_code}</td>
                                                                <td className="p-2.5 text-slate-300">{err.apartment || 'N/A'}</td>
                                                                <td className="p-2.5 text-rose-300">{err.error}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Bảng Lịch Sử Các Đợt Import (Batches) */}
                            <div className="space-y-3 pt-2">
                                <h4 className="text-sm font-bold text-white flex items-center gap-2">
                                    <Clock className="w-4 h-4 text-indigo-400" />
                                    <span>Lịch Sử Các Đợt Import Trong Kỳ</span>
                                </h4>

                                <div className="border border-slate-800 rounded-xl overflow-hidden">
                                    <table className="w-full text-left text-xs text-slate-300">
                                        <thead className="bg-slate-950/70 border-b border-slate-800 uppercase font-semibold text-slate-400">
                                            <tr>
                                                <th className="py-3 px-4">Mã Đợt (Batch Code)</th>
                                                <th className="py-3 px-4">Tên Tệp</th>
                                                <th className="py-3 px-4">Kỳ Chốt</th>
                                                <th className="py-3 px-4 text-right">Tổng Dòng</th>
                                                <th className="py-3 px-4 text-right">Thành Công</th>
                                                <th className="py-3 px-4 text-right">Thất Bại</th>
                                                <th className="py-3 px-4 text-center">Trạng Thái</th>
                                                <th className="py-3 px-4 text-right">Thao Tác</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-800/60">
                                            {batches.length === 0 ? (
                                                <tr>
                                                    <td colSpan={8} className="py-8 text-center text-slate-500">
                                                        Chưa có đợt import nào trong kỳ này.
                                                    </td>
                                                </tr>
                                            ) : (
                                                batches.map((b) => (
                                                    <tr key={b.id} className="hover:bg-slate-800/40">
                                                        <td className="py-3 px-4 font-mono font-bold text-indigo-300">{b.batch_code}</td>
                                                        <td className="py-3 px-4 text-slate-200">{b.file_name}</td>
                                                        <td className="py-3 px-4 font-mono text-slate-400">{b.billing_month_year}</td>
                                                        <td className="py-3 px-4 text-right font-mono font-bold">{b.total_records}</td>
                                                        <td className="py-3 px-4 text-right font-mono text-emerald-400 font-bold">{b.success_records}</td>
                                                        <td className="py-3 px-4 text-right font-mono text-rose-400 font-bold">{b.failed_records}</td>
                                                        <td className="py-3 px-4 text-center">
                                                            <span className={`px-2 py-0.5 rounded-full text-[11px] font-bold ${
                                                                b.import_status === 'COMPLETED'
                                                                    ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                                                                    : 'bg-rose-500/15 text-rose-300 border border-rose-500/30'
                                                            }`}>
                                                                {b.import_status}
                                                            </span>
                                                        </td>
                                                        <td className="py-3 px-4 text-right">
                                                            {b.error_summary_json && b.error_summary_json.length > 0 && (
                                                                <button
                                                                    onClick={() => setSelectedBatchForDetail(b)}
                                                                    className="px-2.5 py-1 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/20 font-semibold"
                                                                >
                                                                    Xem {b.error_summary_json.length} lỗi
                                                                </button>
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Phân trang */}
                    {pagination.total > 15 && (
                        <div className="flex items-center justify-between p-4 bg-slate-950/60 border-t border-slate-800 text-xs text-slate-400">
                            <span>
                                Hiển thị trang {pagination.current_page} / {pagination.last_page} ({pagination.total} bản ghi)
                            </span>
                            <div className="flex items-center gap-2">
                                <button
                                    onClick={() => setPagination(p => ({ ...p, current_page: Math.max(1, p.current_page - 1) }))}
                                    disabled={pagination.current_page <= 1}
                                    className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed text-slate-200"
                                >
                                    Trang trước
                                </button>
                                <button
                                    onClick={() => setPagination(p => ({ ...p, current_page: Math.min(pagination.last_page, p.current_page + 1) }))}
                                    disabled={pagination.current_page >= pagination.last_page}
                                    className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed text-slate-200"
                                >
                                    Trang sau
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* MODAL 1: FORM CHỐT CHỈ SỐ THỦ CÔNG THÔNG MINH */}
            {isEntryModalOpen && selectedMeterForEntry && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-xl overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between p-5 border-b border-slate-800 bg-slate-950/60">
                            <div className="flex items-center gap-3">
                                <div className={`p-2.5 rounded-xl border ${
                                    selectedMeterForEntry.meter_type === 'ELECTRICITY'
                                        ? 'bg-amber-500/10 border-amber-500/30 text-amber-400'
                                        : 'bg-cyan-500/10 border-cyan-500/30 text-cyan-400'
                                }`}>
                                    {selectedMeterForEntry.meter_type === 'ELECTRICITY' ? <Zap className="w-5 h-5" /> : <Droplets className="w-5 h-5" />}
                                </div>
                                <div>
                                    <h3 className="text-lg font-bold text-white">
                                        Chốt Chỉ Số: Căn {selectedMeterForEntry.apartment?.apartment_number}
                                    </h3>
                                    <p className="text-xs text-slate-400 font-mono">
                                        Mã công tơ: {selectedMeterForEntry.meter_code} ({selectedMeterForEntry.meter_type})
                                    </p>
                                </div>
                            </div>
                            <button
                                onClick={() => setIsEntryModalOpen(false)}
                                className="p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Modal Form */}
                        <form onSubmit={(e) => handleSubmitEntry(e, false)} className="p-6 space-y-5">
                            {/* Cảnh báo Validation / Bất thường nếu có */}
                            {validationWarning && (
                                <div className={`p-4 rounded-xl text-xs flex items-start gap-3 border ${
                                    validationWarning.type === 'error'
                                        ? 'bg-rose-500/15 border-rose-500/40 text-rose-300'
                                        : 'bg-amber-500/15 border-amber-500/40 text-amber-300'
                                }`}>
                                    <AlertTriangle className="w-5 h-5 shrink-0 mt-0.5" />
                                    <div>{validationWarning.text}</div>
                                </div>
                            )}

                            {/* Hàng: Chỉ số cũ vs Chỉ số mới */}
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                                        Chỉ Số Cũ (Kỳ Trước)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={entryForm.previous_reading}
                                        onChange={(e) => setEntryForm({ ...entryForm, previous_reading: parseFloat(e.target.value) || 0 })}
                                        className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-base font-mono font-bold text-slate-300 focus:outline-none focus:border-indigo-500"
                                    />
                                    <p className="text-[11px] text-slate-500">Chỉ số chốt liền trước của đồng hồ</p>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-semibold text-indigo-400 uppercase tracking-wider flex items-center justify-between">
                                        <span>Chỉ Số Mới (*)</span>
                                        <span className="text-[11px] font-normal lowercase">ghi hôm nay</span>
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        autoFocus
                                        required
                                        value={entryForm.current_reading}
                                        onChange={(e) => setEntryForm({ ...entryForm, current_reading: parseFloat(e.target.value) || 0 })}
                                        className="w-full bg-slate-950 border-2 border-indigo-500/60 rounded-xl px-3.5 py-2.5 text-base font-mono font-bold text-white focus:outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"
                                    />
                                    <p className="text-[11px] text-slate-500">Nhập chỉ số hiển thị trên mặt công tơ</p>
                                </div>
                            </div>

                            {/* Card Hiển thị Lượng tiêu thụ tự động */}
                            <div className="p-4 rounded-xl bg-gradient-to-r from-slate-950 to-slate-900 border border-slate-800 flex items-center justify-between">
                                <div>
                                    <span className="text-xs text-slate-400 font-medium">Sản lượng tiêu thụ tính toán:</span>
                                    <div className="text-2xl font-black font-mono mt-0.5 flex items-baseline gap-1.5">
                                        <span className={selectedMeterForEntry.meter_type === 'ELECTRICITY' ? 'text-amber-400' : 'text-cyan-400'}>
                                            +{calculatedConsumption.toLocaleString('vi-VN')}
                                        </span>
                                        <span className="text-xs font-semibold text-slate-400">
                                            {selectedMeterForEntry.meter_type === 'ELECTRICITY' ? 'kWh' : 'm³'}
                                        </span>
                                    </div>
                                </div>
                                <div className="text-right text-xs text-slate-500">
                                    <div>Hệ số nhân: x{selectedMeterForEntry.multiplier_factor}</div>
                                    <div className="text-slate-400 font-mono mt-0.5">
                                        {entryForm.current_reading} - {entryForm.previous_reading}
                                    </div>
                                </div>
                            </div>

                            {/* Tùy chọn Thay mới đồng hồ (Force reset) */}
                            <div className="flex items-center gap-2 p-3 rounded-xl bg-slate-950/60 border border-slate-800/80">
                                <input
                                    type="checkbox"
                                    id="forceResetCheckbox"
                                    checked={entryForm.force_reset}
                                    onChange={(e) => setEntryForm({ ...entryForm, force_reset: e.target.checked })}
                                    className="w-4 h-4 rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500"
                                />
                                <label htmlFor="forceResetCheckbox" className="text-xs text-slate-300 font-medium cursor-pointer">
                                    Xác nhận vừa thay mới đồng hồ (Cho phép chỉ số mới nhỏ hơn chỉ số cũ)
                                </label>
                            </div>

                            {/* Đính kèm ảnh công tơ */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                                    <Camera className="w-3.5 h-3.5 text-indigo-400" />
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
                                        className="block w-full text-xs text-slate-400 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-800 file:text-slate-200 hover:file:bg-slate-700 cursor-pointer"
                                    />
                                    {photoPreview && (
                                        <button
                                            type="button"
                                            onClick={() => setPreviewImageUrl(photoPreview)}
                                            className="px-2.5 py-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/20 text-xs text-indigo-300 hover:bg-indigo-500/20 whitespace-nowrap"
                                        >
                                            Xem trước
                                        </button>
                                    )}
                                </div>
                            </div>

                            {/* Ghi chú */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                                    Ghi Chú Kỹ Thuật Viên
                                </label>
                                <textarea
                                    rows={2}
                                    placeholder="Lý do tiêu thụ cao, tình trạng công tơ, v.v..."
                                    value={entryForm.notes}
                                    onChange={(e) => setEntryForm({ ...entryForm, notes: e.target.value })}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-slate-200 focus:outline-none focus:border-indigo-500"
                                />
                            </div>

                            {/* Nút Submit */}
                            <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                                <button
                                    type="button"
                                    onClick={() => setIsEntryModalOpen(false)}
                                    className="px-4 py-2.5 rounded-xl text-sm font-medium bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors"
                                >
                                    Hủy Bỏ
                                </button>
                                <button
                                    type="button"
                                    onClick={(e) => handleSubmitEntry(e, true)}
                                    disabled={submitting}
                                    className="px-4 py-2.5 rounded-xl text-sm font-semibold bg-slate-800 hover:bg-slate-700 border border-slate-700 text-indigo-300 hover:text-indigo-200 transition-colors inline-flex items-center gap-1.5"
                                >
                                    <Save className="w-4 h-4" />
                                    <span>Lưu & Tiếp Căn Kế</span>
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="px-5 py-2.5 rounded-xl text-sm font-bold bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-600/30 transition-all inline-flex items-center gap-1.5"
                                >
                                    {submitting ? <RefreshCw className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
                                    <span>Lưu Chỉ Số</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL 2: XEM ẢNH CÔNG TƠ PHÓNG TO */}
            {previewImageUrl && (
                <div className="fixed inset-0 z-50 bg-black/90 backdrop-blur-md flex items-center justify-center p-4">
                    <div className="relative max-w-2xl w-full bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl">
                        <div className="flex items-center justify-between p-4 border-b border-slate-800">
                            <h4 className="text-sm font-bold text-white flex items-center gap-2">
                                <Camera className="w-4 h-4 text-indigo-400" />
                                <span>Ảnh Chụp Bằng Chứng Công Tơ</span>
                            </h4>
                            <button
                                onClick={() => setPreviewImageUrl(null)}
                                className="p-1.5 rounded-lg bg-slate-800 text-slate-400 hover:text-white"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>
                        <div className="p-4 flex items-center justify-center bg-black/40 max-h-[70vh] overflow-auto">
                            <img
                                src={previewImageUrl}
                                alt="Ảnh công tơ đối soát"
                                className="max-h-[60vh] object-contain rounded-lg shadow-lg"
                            />
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 3: XÁC NHẬN KHÓA SỔ / MỞ KHÓA KỲ */}
            {isLockConfirmOpen && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
                        <div className="flex items-center gap-3">
                            <div className={`p-3 rounded-xl border ${
                                summary?.is_cycle_locked
                                    ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400'
                                    : 'bg-rose-500/10 border-rose-500/20 text-rose-400'
                            }`}>
                                {summary?.is_cycle_locked ? <Unlock className="w-6 h-6" /> : <Lock className="w-6 h-6" />}
                            </div>
                            <div>
                                <h4 className="text-lg font-bold text-white">
                                    {summary?.is_cycle_locked ? 'Mở Khóa Sổ Kỳ Chốt Số' : 'Khóa Sổ Kỳ Chốt Số'}
                                </h4>
                                <p className="text-xs text-slate-400">Chu kỳ: {selectedCycle}</p>
                            </div>
                        </div>

                        <p className="text-sm text-slate-300 leading-relaxed">
                            {summary?.is_cycle_locked ? (
                                'Mở khóa sổ sẽ cho phép ban quản lý tiếp tục chỉnh sửa hoặc nhập bổ sung chỉ số đo của các căn hộ.'
                            ) : (
                                'Khi đã khóa sổ, toàn bộ chỉ số đo của kỳ này sẽ được niêm phong để chuyển sang Module Sinh Hóa Đơn Tự Động. Nhân viên sẽ không thể tự ý sửa hoặc xóa chỉ số nữa.'
                            )}
                        </p>

                        <div className="flex items-center justify-end gap-3 pt-2">
                            <button
                                onClick={() => setIsLockConfirmOpen(false)}
                                className="px-4 py-2 rounded-xl text-sm font-medium bg-slate-800 text-slate-300 hover:bg-slate-700"
                            >
                                Hủy Bỏ
                            </button>
                            <button
                                onClick={handleToggleLockCycle}
                                className={`px-5 py-2 rounded-xl text-sm font-bold text-white shadow-lg ${
                                    summary?.is_cycle_locked
                                        ? 'bg-emerald-600 hover:bg-emerald-500 shadow-emerald-600/30'
                                        : 'bg-rose-600 hover:bg-rose-500 shadow-rose-600/30'
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
                    className={`fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-2xl flex items-center gap-3 border animate-in slide-in-from-bottom-5 duration-200 ${
                        toast.type === 'success'
                            ? 'bg-emerald-950/90 border-emerald-500/50 text-emerald-200 shadow-emerald-950/50'
                            : 'bg-rose-950/90 border-rose-500/50 text-rose-200 shadow-rose-950/50'
                    }`}
                >
                    {toast.type === 'success' ? <CheckCircle2 className="w-5 h-5 text-emerald-400" /> : <AlertTriangle className="w-5 h-5 text-rose-400" />}
                    <span className="text-sm font-medium">{toast.message}</span>
                </div>
            )}
        </div>
    );
};

export default MeterReadingManagement;
