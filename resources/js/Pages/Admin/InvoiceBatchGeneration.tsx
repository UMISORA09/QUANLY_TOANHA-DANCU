import React, { useState, useEffect } from 'react';
import {
    FileText,
    Calculator,
    Zap,
    Droplets,
    ShieldAlert,
    CheckCircle2,
    Clock,
    AlertTriangle,
    Building2,
    RefreshCw,
    Play,
    Eye,
    Car,
    CreditCard,
    Calendar,
    Check,
    X,
    ChevronRight,
    ArrowUpRight,
    Search
} from 'lucide-react';
import {
    invoiceBatchApi,
    InvoicePreviewResponse,
    InvoicePreviewItem,
    InvoiceBatchModel
} from '../../Services/invoiceBatchApi';

interface BlockOption {
    id: string;
    block_code: string;
    block_name: string;
}

export const InvoiceBatchGeneration: React.FC = () => {
    // State form cấu hình
    const [billingPeriod, setBillingPeriod] = useState<string>(() => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        return `${year}-${month}`;
    });
    const [selectedBlockId, setSelectedBlockId] = useState<string>('');
    const [issueDate, setIssueDate] = useState<string>(() => new Date().toISOString().split('T')[0]);
    const [dueDate, setDueDate] = useState<string>(() => {
        const d = new Date();
        d.setDate(d.getDate() + 15);
        return d.toISOString().split('T')[0];
    });
    const [includePreviousDebt, setIncludePreviousDebt] = useState<boolean>(true);
    const [overwriteExisting, setOverwriteExisting] = useState<boolean>(false);
    const [notes, setNotes] = useState<string>('');

    // Danh sách tòa nhà
    const [blocks, setBlocks] = useState<BlockOption[]>([]);

    // Preview state
    const [previewLoading, setPreviewLoading] = useState<boolean>(false);
    const [previewData, setPreviewData] = useState<InvoicePreviewResponse | null>(null);
    const [previewSearch, setPreviewSearch] = useState<string>('');
    const [selectedItemForDetail, setSelectedItemForDetail] = useState<InvoicePreviewItem | null>(null);

    // Generation state
    const [isConfirmModalOpen, setIsConfirmModalOpen] = useState<boolean>(false);
    const [generating, setGenerating] = useState<boolean>(false);
    const [generationResult, setGenerationResult] = useState<{
        message: string;
        createdCount: number;
        totalAmount: number;
        skippedCount: number;
    } | null>(null);

    // Batches history state
    const [activeTab, setActiveTab] = useState<'generation' | 'history'>('generation');
    const [batches, setBatches] = useState<InvoiceBatchModel[]>([]);
    const [batchesLoading, setBatchesLoading] = useState<boolean>(false);
    const [selectedBatchForDetail, setSelectedBatchForDetail] = useState<InvoiceBatchModel | null>(null);

    // Notification toast
    const [toastMessage, setToastMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    const showToast = (type: 'success' | 'error', text: string) => {
        setToastMessage({ type, text });
        setTimeout(() => setToastMessage(null), 4000);
    };

    // Tải danh sách Block
    useEffect(() => {
        const fetchBlocks = async () => {
            try {
                const token = localStorage.getItem('auth_token') || 'smart_token_admin_demo';
                const res = await fetch('/api/v1/blocks', {
                    headers: {
                        Accept: 'application/json',
                        Authorization: token.startsWith('Bearer ') ? token : `Bearer ${token}`
                    }
                });
                if (res.ok) {
                    const data = await res.json();
                    setBlocks(data.data || []);
                }
            } catch (err) {
                console.error('Không thể tải danh sách khối tòa:', err);
            }
        };
        fetchBlocks();
    }, []);

    // Tải danh sách Batch khi chuyển tab
    useEffect(() => {
        if (activeTab === 'history') {
            loadBatches();
        }
    }, [activeTab]);

    const loadBatches = async () => {
        setBatchesLoading(true);
        try {
            const res = await invoiceBatchApi.getBatches({ billing_period: billingPeriod });
            if (res.success) {
                setBatches(res.data.data);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi tải lịch sử sinh hóa đơn');
        } finally {
            setBatchesLoading(false);
        }
    };

    // Chạy mô phỏng (Dry-run preview)
    const handleRunPreview = async () => {
        setPreviewLoading(true);
        setGenerationResult(null);
        try {
            const res = await invoiceBatchApi.previewBatch({
                billing_period: billingPeriod,
                block_id: selectedBlockId || undefined,
                include_previous_debt: includePreviousDebt,
                overwrite_existing: overwriteExisting
            });
            if (res.success) {
                setPreviewData(res.data);
                showToast('success', 'Mô phỏng dự toán thành công! Vui lòng kiểm tra số liệu trước khi phát hành.');
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi mô phỏng dự toán');
        } finally {
            setPreviewLoading(false);
        }
    };

    // Thực thi sinh hóa đơn thật
    const handleConfirmGenerate = async () => {
        setGenerating(true);
        try {
            const res = await invoiceBatchApi.generateBatch({
                billing_period: billingPeriod,
                block_id: selectedBlockId || undefined,
                issue_date: issueDate,
                due_date: dueDate,
                include_previous_debt: includePreviousDebt,
                overwrite_existing: overwriteExisting,
                notes: notes || undefined
            });

            if (res.success) {
                setIsConfirmModalOpen(false);
                setGenerationResult({
                    message: res.message,
                    createdCount: res.data.total_invoices_created,
                    totalAmount: res.data.total_amount_calculated,
                    skippedCount: res.data.skipped_count
                });
                showToast('success', res.message);
                // Làm mới preview và tải lại lịch sử
                handleRunPreview();
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi trong quá trình sinh hóa đơn hàng loạt');
        } finally {
            setGenerating(false);
        }
    };

    // Lọc danh sách preview theo tìm kiếm
    const filteredPreviewItems = previewData?.preview_items.filter((item) => {
        if (!previewSearch) return true;
        const q = previewSearch.toLowerCase();
        return (
            item.apartment_number.toLowerCase().includes(q) ||
            item.block_name.toLowerCase().includes(q) ||
            item.resident_name.toLowerCase().includes(q)
        );
    }) || [];

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 p-4 md:p-8 space-y-8">
            {/* TOAST THÔNG BÁO */}
            {toastMessage && (
                <div
                    className={`fixed top-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 rounded-2xl shadow-2xl border text-sm font-semibold animate-in fade-in slide-in-from-top-4 duration-300 ${
                        toastMessage.type === 'success'
                            ? 'bg-emerald-950/90 border-emerald-500/30 text-emerald-300'
                            : 'bg-rose-950/90 border-rose-500/30 text-rose-300'
                    }`}
                >
                    {toastMessage.type === 'success' ? (
                        <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
                    ) : (
                        <AlertTriangle className="w-5 h-5 text-rose-400 shrink-0" />
                    )}
                    <span>{toastMessage.text}</span>
                </div>
            )}

            {/* HEADER & TIÊU ĐỀ TRANG */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-6 border-b border-slate-800/80">
                <div className="space-y-1">
                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                        <Calculator className="w-3.5 h-3.5" />
                        <span>QUẢN TRỊ KẾ TOÁN & TÀI CHÍNH</span>
                    </div>
                    <h1 className="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
                        Sinh Hóa Đơn Tự Động Hàng Loạt
                    </h1>
                    <p className="text-sm text-slate-400">
                        Hệ thống tự động tính toán lũy tiến điện, nước, phí quản lý vận hành, phí xe và công nợ kỳ trước bằng Database Transaction an toàn.
                    </p>
                </div>

                {/* Tabs Chuyển đổi */}
                <div className="flex items-center bg-slate-900 border border-slate-800 p-1 rounded-xl">
                    <button
                        type="button"
                        onClick={() => setActiveTab('generation')}
                        className={`px-4 py-2 rounded-lg text-sm font-semibold transition-all flex items-center gap-2 ${
                            activeTab === 'generation'
                                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                                : 'text-slate-400 hover:text-slate-200'
                        }`}
                    >
                        <Play className="w-4 h-4" />
                        <span>Phát Hành Kỳ Này</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('history')}
                        className={`px-4 py-2 rounded-lg text-sm font-semibold transition-all flex items-center gap-2 ${
                            activeTab === 'history'
                                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                                : 'text-slate-400 hover:text-slate-200'
                        }`}
                    >
                        <Clock className="w-4 h-4" />
                        <span>Lịch Sử Các Đợt ({batches.length})</span>
                    </button>
                </div>
            </div>

            {/* THÔNG BÁO KẾT QUẢ VỪA SINH */}
            {generationResult && (
                <div className="bg-emerald-950/40 border border-emerald-500/40 rounded-2xl p-6 shadow-xl flex items-start gap-4">
                    <div className="p-3 rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 shrink-0">
                        <CheckCircle2 className="w-6 h-6" />
                    </div>
                    <div className="space-y-2 flex-1">
                        <h3 className="text-lg font-bold text-emerald-300">
                            Phát Hành Hóa Đơn Thành Công Hoàn Toàn!
                        </h3>
                        <p className="text-sm text-slate-300">{generationResult.message}</p>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 pt-2">
                            <div className="bg-slate-900/80 p-3 rounded-xl border border-slate-800">
                                <div className="text-xs text-slate-400">Số hóa đơn đã tạo</div>
                                <div className="text-xl font-mono font-bold text-white mt-0.5">
                                    {generationResult.createdCount} hóa đơn
                                </div>
                            </div>
                            <div className="bg-slate-900/80 p-3 rounded-xl border border-slate-800">
                                <div className="text-xs text-slate-400">Tổng doanh thu kỳ này</div>
                                <div className="text-xl font-mono font-bold text-emerald-400 mt-0.5">
                                    {generationResult.totalAmount.toLocaleString('vi-VN')} đ
                                </div>
                            </div>
                            <div className="bg-slate-900/80 p-3 rounded-xl border border-slate-800">
                                <div className="text-xs text-slate-400">Đã bỏ qua (đã có hóa đơn)</div>
                                <div className="text-xl font-mono font-bold text-amber-400 mt-0.5">
                                    {generationResult.skippedCount} căn
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* NỘI DUNG CHÍNH THEO TAB */}
            {activeTab === 'generation' ? (
                <div className="space-y-8">
                    {/* KHỐI 1: FORM CẤU HÌNH PHÁT HÀNH */}
                    <div className="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 shadow-2xl backdrop-blur-md">
                        <div className="flex items-center gap-2.5 pb-4 border-b border-slate-800">
                            <Calendar className="w-5 h-5 text-indigo-400" />
                            <h2 className="text-base font-bold text-white">Thiết Lập Đợt Sinh Hóa Đơn Hàng Loạt</h2>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 pt-6">
                            {/* 1. Chọn Kỳ Hóa Đơn */}
                            <div className="space-y-2">
                                <label className="text-xs font-semibold text-slate-300 uppercase tracking-wider">
                                    Kỳ Tính Phí (Tháng/Năm) *
                                </label>
                                <input
                                    type="month"
                                    value={billingPeriod}
                                    onChange={(e) => setBillingPeriod(e.target.value)}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 font-mono"
                                />
                                <p className="text-[11px] text-slate-500">Khớp với kỳ chỉ số điện, nước đã chốt</p>
                            </div>

                            {/* 2. Chọn Khối Tòa Nhà */}
                            <div className="space-y-2">
                                <label className="text-xs font-semibold text-slate-300 uppercase tracking-wider">
                                    Khối Tòa Nhà Áp Dụng
                                </label>
                                <select
                                    value={selectedBlockId}
                                    onChange={(e) => setSelectedBlockId(e.target.value)}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500"
                                >
                                    <option value="">🏢 Tất Cả Các Tòa Nhà (Toàn Dự Án)</option>
                                    {blocks.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.block_code} - {b.block_name}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-[11px] text-slate-500">Có thể sinh riêng cho từng block</p>
                            </div>

                            {/* 3. Ngày Phát Hành */}
                            <div className="space-y-2">
                                <label className="text-xs font-semibold text-slate-300 uppercase tracking-wider">
                                    Ngày Phát Hành Hóa Đơn
                                </label>
                                <input
                                    type="date"
                                    value={issueDate}
                                    onChange={(e) => setIssueDate(e.target.value)}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 font-mono"
                                />
                                <p className="text-[11px] text-slate-500">Ngày ghi trên hóa đơn xuất cư dân</p>
                            </div>

                            {/* 4. Hạn Thanh Toán */}
                            <div className="space-y-2">
                                <label className="text-xs font-semibold text-slate-300 uppercase tracking-wider">
                                    Hạn Chót Thanh Toán (Due Date)
                                </label>
                                <input
                                    type="date"
                                    value={dueDate}
                                    onChange={(e) => setDueDate(e.target.value)}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 font-mono"
                                />
                                <p className="text-[11px] text-slate-500">Sau ngày này sẽ chuyển trạng thái trễ hạn</p>
                            </div>
                        </div>

                        {/* TÙY CHỌN BỔ SUNG & GHI CHÚ */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-6 border-t border-slate-800/80 mt-6">
                            {/* Checkbox 1: Cộng nợ cũ */}
                            <label className="flex items-start gap-3 p-3.5 bg-slate-950/60 border border-slate-800 rounded-xl cursor-pointer hover:border-slate-700 transition-colors">
                                <input
                                    type="checkbox"
                                    checked={includePreviousDebt}
                                    onChange={(e) => setIncludePreviousDebt(e.target.checked)}
                                    className="mt-1 rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-0 w-4 h-4"
                                />
                                <div className="text-xs space-y-1">
                                    <div className="font-bold text-white">Cộng Dồn Nợ Kỳ Trước</div>
                                    <div className="text-slate-400">
                                        Tự động tính số dư chưa thanh toán của các kỳ trước vào hóa đơn kỳ này.
                                    </div>
                                </div>
                            </label>

                            {/* Checkbox 2: Ghi đè hóa đơn */}
                            <label className="flex items-start gap-3 p-3.5 bg-slate-950/60 border border-slate-800 rounded-xl cursor-pointer hover:border-slate-700 transition-colors">
                                <input
                                    type="checkbox"
                                    checked={overwriteExisting}
                                    onChange={(e) => setOverwriteExisting(e.target.checked)}
                                    className="mt-1 rounded bg-slate-900 border-slate-700 text-rose-600 focus:ring-0 w-4 h-4"
                                />
                                <div className="text-xs space-y-1">
                                    <div className="font-bold text-rose-300">Ghi Đè Hóa Đơn Đã Có</div>
                                    <div className="text-slate-400">
                                        Xóa và tái tạo lại hóa đơn chưa thanh toán của kỳ này (dùng khi vừa chốt bổ sung số điện/nước).
                                    </div>
                                </div>
                            </label>

                            {/* Ghi chú */}
                            <div className="space-y-1">
                                <label className="text-xs font-semibold text-slate-300">
                                    Ghi Chú Trên Hóa Đơn (Tùy chọn)
                                </label>
                                <input
                                    type="text"
                                    placeholder="Ví dụ: Đã áp dụng giảm trừ phí lễ tết..."
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500"
                                />
                            </div>
                        </div>

                        {/* NÚT THAO TÁC CHÍNH */}
                        <div className="flex flex-wrap items-center justify-end gap-3 pt-6 border-t border-slate-800/80 mt-6">
                            <button
                                type="button"
                                onClick={handleRunPreview}
                                disabled={previewLoading}
                                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-100 text-sm font-semibold border border-slate-700 transition-all disabled:opacity-50"
                            >
                                <RefreshCw className={`w-4 h-4 ${previewLoading ? 'animate-spin text-indigo-400' : ''}`} />
                                <span>{previewLoading ? 'Đang Tính Dự Toán...' : 'Xem Trước Dự Toán (Dry-Run)'}</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setIsConfirmModalOpen(true)}
                                disabled={previewLoading || generating}
                                className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 transition-all disabled:opacity-50"
                            >
                                <Play className="w-4 h-4 fill-current" />
                                <span>Tiến Hành Sinh Hóa Đơn Hàng Loạt</span>
                            </button>
                        </div>
                    </div>

                    {/* KHỐI 2: THỐNG KÊ DỰ TOÁN (KPI CARDS KHI ĐÃ CÓ PREVIEW) */}
                    {previewData && (
                        <div className="space-y-6">
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                {/* Thẻ 1: Tổng tiền dự kiến */}
                                <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                                    <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                                        <span>DỰ KIẾN TỔNG DOANH THU</span>
                                        <CreditCard className="w-4 h-4 text-emerald-400" />
                                    </div>
                                    <div className="text-2xl font-mono font-extrabold text-emerald-400 mt-2">
                                        {previewData.total_estimated_amount.toLocaleString('vi-VN')} đ
                                    </div>
                                    <div className="text-[11px] text-slate-500 mt-1 flex justify-between">
                                        <span>Trước thuế: {previewData.total_estimated_subtotal.toLocaleString('vi-VN')} đ</span>
                                        <span>Thuế: {previewData.total_estimated_tax.toLocaleString('vi-VN')} đ</span>
                                    </div>
                                </div>

                                {/* Thẻ 2: Căn hộ đủ điều kiện */}
                                <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                                    <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                                        <span>CĂN HỘ ĐỦ ĐIỀU KIỆN</span>
                                        <Building2 className="w-4 h-4 text-indigo-400" />
                                    </div>
                                    <div className="text-2xl font-mono font-extrabold text-white mt-2">
                                        {previewData.eligible_for_generation} / {previewData.total_apartments}
                                    </div>
                                    <div className="text-[11px] text-slate-500 mt-1">
                                        {previewData.existing_invoices_count > 0 ? (
                                            <span className="text-amber-400">
                                                Đã có {previewData.existing_invoices_count} căn phát hành trước đó
                                            </span>
                                        ) : (
                                            <span className="text-emerald-400">Chưa có căn nào phát hành trong kỳ</span>
                                        )}
                                    </div>
                                </div>

                                {/* Thẻ 3: Công nợ cũ dồn vào */}
                                <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                                    <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                                        <span>CÔNG NỢ CŨ DỒN VÀO</span>
                                        <Clock className="w-4 h-4 text-amber-400" />
                                    </div>
                                    <div className="text-2xl font-mono font-extrabold text-amber-400 mt-2">
                                        {previewData.total_estimated_previous_debt.toLocaleString('vi-VN')} đ
                                    </div>
                                    <div className="text-[11px] text-slate-500 mt-1">
                                        Từ các hóa đơn tháng trước chưa tất toán
                                    </div>
                                </div>

                                {/* Thẻ 4: Cảnh báo chưa có số điện/nước */}
                                <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                                    <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                                        <span>CHƯA CÓ CHỈ SỐ ĐO</span>
                                        <ShieldAlert className="w-4 h-4 text-rose-400" />
                                    </div>
                                    <div className="text-2xl font-mono font-extrabold text-rose-400 mt-2">
                                        ⚡{previewData.missing_electricity_readings} | 💧{previewData.missing_water_readings}
                                    </div>
                                    <div className="text-[11px] text-rose-300/80 mt-1">
                                        {previewData.missing_electricity_readings > 0 || previewData.missing_water_readings > 0 ? (
                                            <span>Khuyến nghị chốt đủ chỉ số trước khi sinh HĐ</span>
                                        ) : (
                                            <span className="text-emerald-400">Đầy đủ 100% chỉ số điện và nước</span>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* BẢNG CHI TIẾT PREVIEW DỰ TOÁN TỪNG CĂN HỘ */}
                            <div className="bg-slate-900/90 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-md">
                                <div className="p-4 bg-slate-950/70 border-b border-slate-800 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                                    <div className="flex items-center gap-2">
                                        <Eye className="w-5 h-5 text-indigo-400" />
                                        <h3 className="text-sm font-bold text-white">
                                            Dự Toán Chi Tiết Từng Căn Hộ ({filteredPreviewItems.length} căn)
                                        </h3>
                                    </div>

                                    {/* Tìm kiếm */}
                                    <div className="relative w-full sm:w-64">
                                        <Search className="w-4 h-4 text-slate-500 absolute left-3 top-2.5" />
                                        <input
                                            type="text"
                                            placeholder="Tìm mã căn, tên cư dân..."
                                            value={previewSearch}
                                            onChange={(e) => setPreviewSearch(e.target.value)}
                                            className="w-full bg-slate-900 border border-slate-800 rounded-xl pl-9 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500"
                                        />
                                    </div>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm text-slate-300">
                                        <thead className="bg-slate-950/80 border-b border-slate-800 text-xs font-semibold uppercase text-slate-400">
                                            <tr>
                                                <th className="py-3 px-4">Căn Hộ</th>
                                                <th className="py-3 px-4">Đại Diện</th>
                                                <th className="py-3 px-4 text-center">⚡ Điện</th>
                                                <th className="py-3 px-4 text-center">💧 Nước</th>
                                                <th className="py-3 px-4 text-right">Trước Thuế</th>
                                                <th className="py-3 px-4 text-right">Thuế & Phí</th>
                                                <th className="py-3 px-4 text-right">Nợ Cũ</th>
                                                <th className="py-3 px-4 text-right">Tổng Phải Thu</th>
                                                <th className="py-3 px-4 text-center">Trạng Thái</th>
                                                <th className="py-3 px-4 text-right">Chi Tiết</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-800/60 text-xs">
                                            {filteredPreviewItems.length === 0 ? (
                                                <tr>
                                                    <td colSpan={10} className="py-12 text-center text-slate-500">
                                                        Không tìm thấy căn hộ nào phù hợp với bộ lọc.
                                                    </td>
                                                </tr>
                                            ) : (
                                                filteredPreviewItems.map((item) => (
                                                    <tr key={item.apartment_id} className="hover:bg-slate-800/40 transition-colors">
                                                        {/* Căn hộ */}
                                                        <td className="py-3 px-4 font-bold text-white">
                                                            <div className="flex items-center gap-1.5">
                                                                <Building2 className="w-4 h-4 text-indigo-400" />
                                                                <span>{item.apartment_number}</span>
                                                            </div>
                                                            <div className="text-[11px] text-slate-500 font-normal">
                                                                {item.block_name} • {item.area_sqm} m²
                                                            </div>
                                                        </td>

                                                        {/* Đại diện */}
                                                        <td className="py-3 px-4 text-slate-300 font-medium">
                                                            {item.resident_name}
                                                        </td>

                                                        {/* Điện */}
                                                        <td className="py-3 px-4 text-center">
                                                            {item.has_electricity_reading ? (
                                                                <span className="inline-flex items-center gap-1 text-emerald-400 font-semibold">
                                                                    <Check className="w-3.5 h-3.5" />
                                                                    <span>Có số</span>
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 text-rose-400 font-medium">
                                                                    <X className="w-3.5 h-3.5" />
                                                                    <span>Chưa ghi</span>
                                                                </span>
                                                            )}
                                                        </td>

                                                        {/* Nước */}
                                                        <td className="py-3 px-4 text-center">
                                                            {item.has_water_reading ? (
                                                                <span className="inline-flex items-center gap-1 text-emerald-400 font-semibold">
                                                                    <Check className="w-3.5 h-3.5" />
                                                                    <span>Có số</span>
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 text-rose-400 font-medium">
                                                                    <X className="w-3.5 h-3.5" />
                                                                    <span>Chưa ghi</span>
                                                                </span>
                                                            )}
                                                        </td>

                                                        {/* Trước thuế */}
                                                        <td className="py-3 px-4 text-right font-mono">
                                                            {item.subtotal_amount.toLocaleString('vi-VN')} đ
                                                        </td>

                                                        {/* Thuế & Phí */}
                                                        <td className="py-3 px-4 text-right font-mono text-slate-400">
                                                            {item.tax_amount.toLocaleString('vi-VN')} đ
                                                        </td>

                                                        {/* Nợ cũ */}
                                                        <td className="py-3 px-4 text-right font-mono">
                                                            {item.previous_debt_amount > 0 ? (
                                                                <span className="text-amber-400 font-bold">
                                                                    +{item.previous_debt_amount.toLocaleString('vi-VN')} đ
                                                                </span>
                                                            ) : (
                                                                <span className="text-slate-600">0 đ</span>
                                                            )}
                                                        </td>

                                                        {/* Tổng phải thu */}
                                                        <td className="py-3 px-4 text-right font-mono font-bold text-emerald-400 text-sm">
                                                            {item.total_amount.toLocaleString('vi-VN')} đ
                                                        </td>

                                                        {/* Trạng thái */}
                                                        <td className="py-3 px-4 text-center">
                                                            {item.has_existing_invoice ? (
                                                                <span className="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-500/15 text-amber-300 border border-amber-500/30">
                                                                    Đã có HĐ ({item.existing_invoice_number})
                                                                </span>
                                                            ) : (
                                                                <span className="px-2 py-0.5 rounded-full text-[11px] font-bold bg-indigo-500/15 text-indigo-300 border border-indigo-500/30">
                                                                    Sẵn sàng tạo
                                                                </span>
                                                            )}
                                                        </td>

                                                        {/* Thao tác xem chi tiết */}
                                                        <td className="py-3 px-4 text-right">
                                                            <button
                                                                type="button"
                                                                onClick={() => setSelectedItemForDetail(item)}
                                                                className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-indigo-300 hover:text-white transition-colors"
                                                            >
                                                                Xem {item.items_count} mục
                                                            </button>
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
                </div>
            ) : (
                /* TAB 2: LỊCH SỬ CÁC ĐỢT SINH HÓA ĐƠN */
                <div className="bg-slate-900/90 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-md">
                    <div className="p-4 bg-slate-950/70 border-b border-slate-800 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Clock className="w-5 h-5 text-indigo-400" />
                            <h3 className="text-sm font-bold text-white">Lịch Sử Các Đợt Phát Hành Hóa Đơn</h3>
                        </div>
                        <button
                            type="button"
                            onClick={loadBatches}
                            disabled={batchesLoading}
                            className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition-colors"
                        >
                            <RefreshCw className={`w-4 h-4 ${batchesLoading ? 'animate-spin' : ''}`} />
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-slate-300">
                            <thead className="bg-slate-950/80 border-b border-slate-800 text-xs font-semibold uppercase text-slate-400">
                                <tr>
                                    <th className="py-3 px-4">Mã Đợt (Batch Number)</th>
                                    <th className="py-3 px-4">Kỳ Hóa Đơn</th>
                                    <th className="py-3 px-4">Phạm Vi Khối</th>
                                    <th className="py-3 px-4">Người Thực Hiện</th>
                                    <th className="py-3 px-4 text-right">Số Căn</th>
                                    <th className="py-3 px-4 text-right">Số Hóa Đơn</th>
                                    <th className="py-3 px-4 text-right">Tổng Doanh Thu</th>
                                    <th className="py-3 px-4 text-center">Trạng Thái</th>
                                    <th className="py-3 px-4 text-right">Thời Gian</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800/60 text-xs">
                                {batches.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="py-12 text-center text-slate-500">
                                            Chưa có đợt sinh hóa đơn nào được ghi nhận.
                                        </td>
                                    </tr>
                                ) : (
                                    batches.map((b) => (
                                        <tr key={b.id} className="hover:bg-slate-800/40">
                                            <td className="py-3 px-4 font-mono font-bold text-indigo-300">
                                                {b.batch_number}
                                            </td>
                                            <td className="py-3 px-4 font-mono text-white">
                                                {b.billing_period}
                                            </td>
                                            <td className="py-3 px-4 text-slate-300">
                                                {b.block ? `${b.block.block_code} - ${b.block.block_name}` : 'Toàn Bộ Tòa Nhà'}
                                            </td>
                                            <td className="py-3 px-4 text-slate-300">
                                                {b.executed_by?.full_name || 'Hệ Thống'}
                                            </td>
                                            <td className="py-3 px-4 text-right font-mono">
                                                {b.total_apartments_processed}
                                            </td>
                                            <td className="py-3 px-4 text-right font-mono font-bold text-white">
                                                {b.total_invoices_created}
                                            </td>
                                            <td className="py-3 px-4 text-right font-mono font-bold text-emerald-400">
                                                {b.total_amount_calculated.toLocaleString('vi-VN')} đ
                                            </td>
                                            <td className="py-3 px-4 text-center">
                                                <span
                                                    className={`px-2 py-0.5 rounded-full text-[11px] font-bold ${
                                                        b.status === 'COMPLETED'
                                                            ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30'
                                                            : b.status === 'RUNNING'
                                                            ? 'bg-indigo-500/15 text-indigo-300 border border-indigo-500/30'
                                                            : 'bg-rose-500/15 text-rose-300 border border-rose-500/30'
                                                    }`}
                                                >
                                                    {b.status}
                                                </span>
                                            </td>
                                            <td className="py-3 px-4 text-right text-slate-400 font-mono">
                                                {new Date(b.started_at).toLocaleString('vi-VN')}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* MODAL 1: XÁC NHẬN PHÁT HÀNH HÀNG LOẠT */}
            {isConfirmModalOpen && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="p-6 space-y-4">
                            <div className="flex items-center gap-3">
                                <div className="p-3 rounded-xl bg-indigo-500/20 text-indigo-400 border border-indigo-500/30">
                                    <AlertTriangle className="w-6 h-6 text-amber-400" />
                                </div>
                                <div>
                                    <h3 className="text-lg font-bold text-white">
                                        Xác Nhận Phát Hành Hóa Đơn Hàng Loạt
                                    </h3>
                                    <p className="text-xs text-slate-400">
                                        Kỳ hóa đơn: <strong className="text-indigo-400 font-mono">{billingPeriod}</strong>
                                    </p>
                                </div>
                            </div>

                            <div className="bg-slate-950/70 border border-slate-800 rounded-xl p-4 space-y-2 text-xs text-slate-300">
                                <div className="flex justify-between">
                                    <span className="text-slate-400">Phạm vi phát hành:</span>
                                    <span className="font-semibold text-white">
                                        {selectedBlockId ? 'Theo Khối được chọn' : 'Toàn Bộ Tòa Nhà'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-400">Cộng dồn nợ cũ:</span>
                                    <span className="font-semibold text-emerald-400">
                                        {includePreviousDebt ? 'Đã bật' : 'Tắt'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-400">Ghi đè hóa đơn đã có:</span>
                                    <span className="font-semibold text-rose-400">
                                        {overwriteExisting ? 'Cho phép ghi đè' : 'Bỏ qua (không ghi đè)'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-400">Hạn chót thanh toán:</span>
                                    <span className="font-mono text-white">{dueDate}</span>
                                </div>
                            </div>

                            <p className="text-xs text-amber-300/90 bg-amber-500/10 border border-amber-500/20 p-3 rounded-xl">
                                ⚠️ Thao tác này sẽ khóa toàn bộ chỉ số điện và nước của kỳ này, ghi nhận doanh thu vào cơ sở dữ liệu và gửi thông báo công nợ cho cư dân.
                            </p>
                        </div>

                        <div className="flex items-center justify-end gap-3 p-4 bg-slate-950/60 border-t border-slate-800">
                            <button
                                type="button"
                                onClick={() => setIsConfirmModalOpen(false)}
                                disabled={generating}
                                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition-colors"
                            >
                                Hủy Bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirmGenerate}
                                disabled={generating}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-500 shadow-md shadow-indigo-600/30 transition-all flex items-center gap-2"
                            >
                                {generating && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                                <span>{generating ? 'Đang Tạo Hóa Đơn...' : 'Xác Nhận Phát Hành'}</span>
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 2: XEM CHI TIẾT DỰ TOÁN CỦA CĂN HỘ */}
            {selectedItemForDetail && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200 max-h-[90vh] flex flex-col">
                        <div className="p-5 border-b border-slate-800 bg-slate-950/60 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 rounded-xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                                    <Building2 className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-white">
                                        Chi Tiết Khoản Mục Hóa Đơn Căn {selectedItemForDetail.apartment_number}
                                    </h3>
                                    <p className="text-xs text-slate-400">
                                        Đại diện: {selectedItemForDetail.resident_name} • Diện tích: {selectedItemForDetail.area_sqm} m²
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedItemForDetail(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-white bg-slate-800"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-4">
                            <div className="space-y-3">
                                {selectedItemForDetail.items_preview.map((item, idx) => (
                                    <div
                                        key={idx}
                                        className="bg-slate-950/70 border border-slate-800 rounded-xl p-4 space-y-2 text-xs"
                                    >
                                        <div className="flex items-center justify-between font-bold text-white">
                                            <div className="flex items-center gap-2">
                                                {item.service_code === 'ELECTRICITY' ? (
                                                    <Zap className="w-4 h-4 text-amber-400" />
                                                ) : item.service_code === 'WATER' ? (
                                                    <Droplets className="w-4 h-4 text-cyan-400" />
                                                ) : item.service_code === 'PARKING_FEE' ? (
                                                    <Car className="w-4 h-4 text-purple-400" />
                                                ) : (
                                                    <FileText className="w-4 h-4 text-indigo-400" />
                                                )}
                                                <span>{item.item_description}</span>
                                            </div>
                                            <span className="font-mono text-emerald-400 text-sm">
                                                {item.total_line_amount.toLocaleString('vi-VN')} đ
                                            </span>
                                        </div>

                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-slate-400 pt-1 border-t border-slate-900">
                                            <div>Số lượng: <strong className="text-white font-mono">{item.quantity} {item.unit_name}</strong></div>
                                            <div>Đơn giá: <strong className="text-white font-mono">{item.unit_price.toLocaleString('vi-VN')} đ</strong></div>
                                            <div>Trước thuế: <strong className="text-white font-mono">{item.amount_before_tax.toLocaleString('vi-VN')} đ</strong></div>
                                            <div>VAT/BVMT: <strong className="text-white font-mono">{(item.vat_amount + item.environmental_fee_amount).toLocaleString('vi-VN')} đ</strong></div>
                                        </div>

                                        {/* Chi tiết từng bậc nếu là điện hoặc nước */}
                                        {item.tier_calculation_details && item.tier_calculation_details.length > 0 && (
                                            <div className="mt-2 pt-2 border-t border-slate-900 space-y-1">
                                                <div className="text-[11px] font-semibold text-slate-400">Chi tiết theo bậc thang lũy tiến:</div>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-[11px] font-mono">
                                                    {item.tier_calculation_details.map((t: any, tidx: number) => (
                                                        <div key={tidx} className="bg-slate-900/60 px-2 py-1 rounded border border-slate-800/80 flex justify-between">
                                                            <span className="text-slate-400">{t.tier_name}:</span>
                                                            <span className="text-white">{t.tier_usage} {item.unit_name} × {t.unit_price.toLocaleString('vi-VN')} = <strong>{t.tier_amount.toLocaleString('vi-VN')} đ</strong></span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>

                            {/* Tổng kết tiền */}
                            <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-1.5 text-xs">
                                <div className="flex justify-between text-slate-400">
                                    <span>Tổng tiền dịch vụ trước thuế:</span>
                                    <span className="font-mono text-white">{selectedItemForDetail.subtotal_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                <div className="flex justify-between text-slate-400">
                                    <span>Tổng thuế VAT & Phí bảo vệ môi trường:</span>
                                    <span className="font-mono text-white">{selectedItemForDetail.tax_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                {selectedItemForDetail.previous_debt_amount > 0 && (
                                    <div className="flex justify-between text-amber-400 font-semibold">
                                        <span>Công nợ tồn từ kỳ trước:</span>
                                        <span className="font-mono">+{selectedItemForDetail.previous_debt_amount.toLocaleString('vi-VN')} đ</span>
                                    </div>
                                )}
                                <div className="flex justify-between text-sm font-extrabold text-emerald-400 pt-2 border-t border-slate-800">
                                    <span>TỔNG CỘNG PHẢI THANH TOÁN:</span>
                                    <span className="font-mono text-base">{selectedItemForDetail.total_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                            </div>
                        </div>

                        <div className="p-4 bg-slate-950/60 border-t border-slate-800 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setSelectedItemForDetail(null)}
                                className="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-slate-800 hover:bg-slate-700 transition-colors"
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

export default InvoiceBatchGeneration;
