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
                console.error('Lỗi khi tải blocks:', err);
            }
        };
        fetchBlocks();
    }, []);

    // Tải lịch sử đợt sinh hóa đơn
    const loadBatches = async () => {
        setBatchesLoading(true);
        try {
            const res = await invoiceBatchApi.getBatches({
                billing_period: billingPeriod,
            });
            setBatches(res.data.data);
        } catch (err: any) {
            showToast('error', err.message || 'Không thể tải lịch sử các đợt');
        } finally {
            setBatchesLoading(false);
        }
    };

    useEffect(() => {
        if (activeTab === 'history') {
            loadBatches();
        }
    }, [activeTab, billingPeriod]);

    // Chạy xem trước (Dry-run preview)
    const handleRunPreview = async () => {
        if (!billingPeriod) {
            showToast('error', 'Vui lòng chọn kỳ tính phí (tháng/năm).');
            return;
        }

        setPreviewLoading(true);
        setGenerationResult(null);
        try {
            const res = await invoiceBatchApi.previewBatch({
                billing_period: billingPeriod,
                block_id: selectedBlockId || undefined,
                include_previous_debt: includePreviousDebt,
                overwrite_existing: overwriteExisting
            });
            setPreviewData(res.data);
            showToast('success', `Đã phân tích xong dữ liệu dự toán cho ${res.data.total_apartments} căn hộ.`);
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi tính toán dự toán');
        } finally {
            setPreviewLoading(false);
        }
    };

    // Xác nhận và thực hiện phát hành hóa đơn thật
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

            setIsConfirmModalOpen(false);
            setGenerationResult({
                message: res.message,
                createdCount: res.data.total_invoices_created,
                totalAmount: res.data.total_amount_calculated,
                skippedCount: res.data.skipped_count
            });

            showToast('success', `Đã phát hành thành công ${res.data.total_invoices_created} hóa đơn.`);
            // Reset preview
            setPreviewData(null);
            // Tải lại lịch sử nếu chuyển tab
            if (activeTab === 'history') {
                loadBatches();
            }
        } catch (err: any) {
            showToast('error', err.message || 'Quá trình phát hành hóa đơn gặp lỗi');
        } finally {
            setGenerating(false);
        }
    };

    // Filter preview list by search query
    const filteredPreviewItems = React.useMemo(() => {
        if (!previewData?.preview_items) return [];
        if (!previewSearch.trim()) return previewData.preview_items;

        const q = previewSearch.toLowerCase();
        return previewData.preview_items.filter(
            (item: InvoicePreviewItem) =>
                item.apartment_number.toLowerCase().includes(q) ||
                item.resident_name.toLowerCase().includes(q) ||
                item.block_name.toLowerCase().includes(q)
        );
    }, [previewData, previewSearch]);

    return (
        <div className="max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6">
            {/* TOAST THÔNG BÁO */}
            {toastMessage && (
                <div
                    className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border text-xs font-semibold animate-in slide-in-from-bottom duration-200 ${
                        toastMessage.type === 'success'
                            ? 'bg-emerald-50 text-emerald-800 border-emerald-300'
                            : 'bg-rose-50 text-rose-800 border-rose-300'
                    }`}
                >
                    {toastMessage.type === 'success' ? (
                        <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                    ) : (
                        <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                    )}
                    <span>{toastMessage.text}</span>
                </div>
            )}

            {/* HEADER & TIÊU ĐỀ TRANG */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200/80">
                <div>
                    <div className="text-[11px] font-bold text-sky-600 font-mono tracking-wider uppercase flex items-center gap-1.5">
                        <Calculator className="w-3.5 h-3.5" />
                        <span>HỆ THỐNG KẾ TOÁN & HÓA ĐƠN · CHỨC NĂNG #6 (SINH HÓA ĐƠN TỰ ĐỘNG)</span>
                    </div>
                    <h1 className="text-2xl sm:text-3xl font-black text-neutral-900 tracking-tight mt-1">
                        Sinh Hóa Đơn Tự Động Hàng Loạt
                    </h1>
                    <p className="text-xs sm:text-sm text-slate-500 max-w-3xl font-medium mt-1">
                        Hệ thống tự động tính toán lũy tiến điện, nước, phí quản lý vận hành, phí xe và công nợ kỳ trước an toàn bằng Database Transaction.
                    </p>
                </div>

                {/* Tabs Chuyển đổi & Nút Thao tác */}
                <div className="flex items-center gap-2">
                    <div className="flex items-center bg-slate-100/70 p-1 rounded-xl">
                        <button
                            type="button"
                            onClick={() => setActiveTab('generation')}
                            className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer ${
                                activeTab === 'generation'
                                    ? 'bg-white text-sky-700 shadow-2xs'
                                    : 'text-slate-600 hover:text-slate-900'
                            }`}
                        >
                            <Play className="w-3.5 h-3.5 fill-current" />
                            <span>Phát Hành Kỳ Này</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('history')}
                            className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer ${
                                activeTab === 'history'
                                    ? 'bg-white text-sky-700 shadow-2xs'
                                    : 'text-slate-600 hover:text-slate-900'
                            }`}
                        >
                            <Clock className="w-3.5 h-3.5" />
                            <span>Lịch Sử ({batches.length})</span>
                        </button>
                    </div>

                    <button
                        type="button"
                        onClick={activeTab === 'history' ? loadBatches : handleRunPreview}
                        disabled={previewLoading || batchesLoading}
                        title="Làm mới dữ liệu"
                        className="p-2 rounded-xl text-slate-500 hover:text-slate-800 bg-white border border-slate-200 hover:bg-slate-50 shadow-2xs transition-all active:scale-[0.98] cursor-pointer shrink-0"
                    >
                        <RefreshCw className={`w-3.5 h-3.5 ${previewLoading || batchesLoading ? 'animate-spin text-sky-600' : ''}`} />
                    </button>
                </div>
            </div>

            {/* THÔNG BÁO KẾT QUẢ VỪA SINH */}
            {generationResult && (
                <div className="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 shadow-2xs flex items-start gap-4">
                    <div className="p-3 rounded-xl bg-emerald-100 text-emerald-700 border border-emerald-200 shrink-0">
                        <CheckCircle2 className="w-6 h-6" />
                    </div>
                    <div className="space-y-2 flex-1">
                        <h3 className="text-base font-bold text-emerald-900">
                            Phát Hành Hóa Đơn Thành Công Hoàn Toàn!
                        </h3>
                        <p className="text-xs text-emerald-800 font-medium">{generationResult.message}</p>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2">
                            <div className="bg-white p-3 rounded-xl border border-emerald-200/80 shadow-2xs">
                                <div className="text-[11px] text-slate-500 font-medium">Số hóa đơn đã tạo</div>
                                <div className="text-lg font-mono font-black text-neutral-900 mt-0.5">
                                    {generationResult.createdCount} hóa đơn
                                </div>
                            </div>
                            <div className="bg-white p-3 rounded-xl border border-emerald-200/80 shadow-2xs">
                                <div className="text-[11px] text-slate-500 font-medium">Tổng doanh thu kỳ này</div>
                                <div className="text-lg font-mono font-black text-emerald-600 mt-0.5">
                                    {generationResult.totalAmount.toLocaleString('vi-VN')} đ
                                </div>
                            </div>
                            <div className="bg-white p-3 rounded-xl border border-emerald-200/80 shadow-2xs">
                                <div className="text-[11px] text-slate-500 font-medium">Đã bỏ qua (đã có hóa đơn)</div>
                                <div className="text-lg font-mono font-black text-amber-600 mt-0.5">
                                    {generationResult.skippedCount} căn
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* NỘI DUNG CHÍNH THEO TAB */}
            {activeTab === 'generation' ? (
                <div className="space-y-6">
                    {/* KHỐI 1: FORM CẤU HÌNH PHÁT HÀNH */}
                    <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5">
                        <div className="flex items-center gap-2.5 pb-4 border-b border-slate-100">
                            <div className="p-2 rounded-xl bg-sky-50 text-sky-600 border border-sky-100">
                                <Calendar className="w-4 h-4" />
                            </div>
                            <div>
                                <h2 className="text-sm font-bold text-neutral-900">Thiết Lập Đợt Sinh Hóa Đơn Hàng Loạt</h2>
                                <p className="text-xs text-slate-500">Cấu hình chu kỳ, ngày phát hành và phạm vi tòa nhà áp dụng</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 pt-1">
                            {/* 1. Chọn Kỳ Hóa Đơn */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold text-slate-700 uppercase tracking-wider font-mono">
                                    Kỳ Tính Phí (Tháng/Năm) *
                                </label>
                                <input
                                    type="month"
                                    value={billingPeriod}
                                    onChange={(e) => setBillingPeriod(e.target.value)}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-3.5 py-2 text-xs text-slate-800 font-medium focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 font-mono"
                                />
                                <p className="text-[11px] text-slate-400">Khớp với kỳ chỉ số điện, nước đã chốt</p>
                            </div>

                            {/* 2. Chọn Khối Tòa Nhà */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold text-slate-700 uppercase tracking-wider font-mono">
                                    Khối Tòa Nhà Áp Dụng
                                </label>
                                <select
                                    value={selectedBlockId}
                                    onChange={(e) => setSelectedBlockId(e.target.value)}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-3.5 py-2 text-xs text-slate-800 font-medium focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 cursor-pointer"
                                >
                                    <option value="">🏢 Tất Cả Các Tòa Nhà (Toàn Dự Án)</option>
                                    {blocks.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.block_code} - {b.block_name}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-[11px] text-slate-400">Có thể sinh riêng cho từng block</p>
                            </div>

                            {/* 3. Ngày Phát Hành */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold text-slate-700 uppercase tracking-wider font-mono">
                                    Ngày Phát Hành Hóa Đơn
                                </label>
                                <input
                                    type="date"
                                    value={issueDate}
                                    onChange={(e) => setIssueDate(e.target.value)}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-3.5 py-2 text-xs text-slate-800 font-medium focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 font-mono"
                                />
                                <p className="text-[11px] text-slate-400">Ngày ghi trên hóa đơn xuất cư dân</p>
                            </div>

                            {/* 4. Hạn Thanh Toán */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold text-slate-700 uppercase tracking-wider font-mono">
                                    Hạn Chót Thanh Toán (Due Date)
                                </label>
                                <input
                                    type="date"
                                    value={dueDate}
                                    onChange={(e) => setDueDate(e.target.value)}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-3.5 py-2 text-xs text-slate-800 font-medium focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 font-mono"
                                />
                                <p className="text-[11px] text-slate-400">Sau ngày này sẽ chuyển trạng thái trễ hạn</p>
                            </div>
                        </div>

                        {/* TÙY CHỌN BỔ SUNG & GHI CHÚ */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 pt-4 border-t border-slate-100">
                            {/* Checkbox 1: Cộng nợ cũ */}
                            <label className="flex items-start gap-3 p-3.5 bg-slate-50/70 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-100/70 transition-colors">
                                <input
                                    type="checkbox"
                                    checked={includePreviousDebt}
                                    onChange={(e) => setIncludePreviousDebt(e.target.checked)}
                                    className="mt-0.5 rounded border-slate-300 text-sky-600 focus:ring-sky-500/20 w-4 h-4 cursor-pointer"
                                />
                                <div className="text-xs space-y-1">
                                    <div className="font-bold text-neutral-900">Cộng Dồn Nợ Kỳ Trước</div>
                                    <div className="text-slate-500 text-[11px]">
                                        Tự động tính số dư chưa thanh toán của các kỳ trước vào hóa đơn kỳ này.
                                    </div>
                                </div>
                            </label>

                            {/* Checkbox 2: Ghi đè hóa đơn */}
                            <label className="flex items-start gap-3 p-3.5 bg-slate-50/70 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-100/70 transition-colors">
                                <input
                                    type="checkbox"
                                    checked={overwriteExisting}
                                    onChange={(e) => setOverwriteExisting(e.target.checked)}
                                    className="mt-0.5 rounded border-slate-300 text-rose-600 focus:ring-rose-500/20 w-4 h-4 cursor-pointer"
                                />
                                <div className="text-xs space-y-1">
                                    <div className="font-bold text-rose-700">Ghi Đè Hóa Đơn Đã Có</div>
                                    <div className="text-slate-500 text-[11px]">
                                        Xóa và tái tạo lại hóa đơn chưa thanh toán của kỳ này nếu vừa bổ sung số đo.
                                    </div>
                                </div>
                            </label>

                            {/* Ghi chú */}
                            <div className="space-y-1.5">
                                <label className="text-xs font-bold text-slate-700 uppercase tracking-wider font-mono">
                                    Ghi Chú Trên Hóa Đơn (Tùy chọn)
                                </label>
                                <input
                                    type="text"
                                    placeholder="Ví dụ: Đã áp dụng giảm trừ phí bảo dưỡng..."
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    className="w-full bg-white border border-slate-200 rounded-xl px-3.5 py-2 text-xs text-slate-800 font-medium focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                                />
                            </div>
                        </div>

                        {/* NÚT THAO TÁC CHÍNH */}
                        <div className="flex flex-wrap items-center justify-end gap-2.5 pt-4 border-t border-slate-100">
                            <button
                                type="button"
                                onClick={handleRunPreview}
                                disabled={previewLoading}
                                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold border border-slate-200 shadow-2xs transition-all disabled:opacity-50 cursor-pointer"
                            >
                                <RefreshCw className={`w-3.5 h-3.5 text-sky-600 ${previewLoading ? 'animate-spin' : ''}`} />
                                <span>{previewLoading ? 'Đang Tính Dự Toán...' : 'Xem Trước Dự Toán (Dry-Run)'}</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setIsConfirmModalOpen(true)}
                                disabled={previewLoading || generating}
                                className="inline-flex items-center gap-1.5 px-5 py-2 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white text-xs font-bold shadow-sm transition-all disabled:opacity-50 cursor-pointer"
                            >
                                <Play className="w-3.5 h-3.5 fill-current" />
                                <span>Tiến Hành Sinh Hóa Đơn Hàng Loạt</span>
                            </button>
                        </div>
                    </div>

                    {/* KHỐI 2: THỐNG KÊ DỰ TOÁN (KPI CARDS KHI ĐÃ CÓ PREVIEW) */}
                    {previewData && (
                        <div className="space-y-6">
                            <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3.5">
                                {/* Thẻ 1: Tổng tiền dự kiến */}
                                <div className="sass-db-kpi-card">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Dự Kiến Tổng Doanh Thu</span>
                                        <div className="kpi-icon-wrapper bg-emerald-50 text-emerald-600">
                                            <CreditCard className="w-4 h-4" />
                                        </div>
                                    </div>
                                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-emerald-600 font-mono">
                                        {previewData.total_estimated_amount.toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                                    </div>
                                    <div className="mt-1 text-[10px] sm:text-[11px] text-slate-500 flex justify-between">
                                        <span>Trước thuế: {previewData.total_estimated_subtotal.toLocaleString('vi-VN')} đ</span>
                                        <span className="font-semibold text-emerald-700">Thuế: {previewData.total_estimated_tax.toLocaleString('vi-VN')} đ</span>
                                    </div>
                                </div>

                                {/* Thẻ 2: Căn hộ đủ điều kiện */}
                                <div className="sass-db-kpi-card">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Căn Hộ Đủ Điều Kiện</span>
                                        <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                                            <Building2 className="w-4 h-4" />
                                        </div>
                                    </div>
                                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-neutral-900 font-mono">
                                        {previewData.eligible_for_generation} <span className="text-xs font-normal text-slate-400">/ {previewData.total_apartments} căn</span>
                                    </div>
                                    <div className="mt-1 text-[10px] sm:text-[11px] truncate">
                                        {previewData.existing_invoices_count > 0 ? (
                                            <span className="text-amber-700 font-medium">
                                                Đã có {previewData.existing_invoices_count} căn phát hành trước đó
                                            </span>
                                        ) : (
                                            <span className="text-emerald-700 font-medium">Chưa có căn nào phát hành trong kỳ</span>
                                        )}
                                    </div>
                                </div>

                                {/* Thẻ 3: Công nợ cũ dồn vào */}
                                <div className="sass-db-kpi-card">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Công Nợ Cũ Dồn Vào</span>
                                        <div className="kpi-icon-wrapper bg-amber-50 text-amber-600">
                                            <Clock className="w-4 h-4" />
                                        </div>
                                    </div>
                                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-amber-600 font-mono">
                                        {previewData.total_estimated_previous_debt.toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                                    </div>
                                    <div className="mt-1 text-[10px] sm:text-[11px] text-amber-700 font-medium truncate">
                                        Từ các hóa đơn tháng trước chưa tất toán
                                    </div>
                                </div>

                                {/* Thẻ 4: Cảnh báo chưa có số điện/nước */}
                                <div className="sass-db-kpi-card col-span-2 sm:col-span-1">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Chưa Có Chỉ Số Đo</span>
                                        <div className="kpi-icon-wrapper bg-rose-50 text-rose-600">
                                            <ShieldAlert className="w-4 h-4" />
                                        </div>
                                    </div>
                                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-rose-600 font-mono">
                                        ⚡{previewData.missing_electricity_readings} | 💧{previewData.missing_water_readings}
                                    </div>
                                    <div className="mt-1 text-[10px] sm:text-[11px] truncate">
                                        {previewData.missing_electricity_readings > 0 || previewData.missing_water_readings > 0 ? (
                                            <span className="text-rose-600 font-bold">Khuyến nghị chốt đủ chỉ số trước khi sinh</span>
                                        ) : (
                                            <span className="text-emerald-700 font-medium">Đầy đủ 100% chỉ số điện & nước</span>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* BẢNG CHI TIẾT PREVIEW DỰ TOÁN TỪNG CĂN HỘ */}
                            <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                                <div className="p-4 bg-slate-50/70 border-b border-slate-100 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                                    <div className="flex items-center gap-2">
                                        <Eye className="w-4 h-4 text-sky-600" />
                                        <h3 className="text-xs font-bold text-neutral-900 uppercase tracking-wider font-mono">
                                            Dự Toán Chi Tiết Từng Căn Hộ ({filteredPreviewItems.length} căn)
                                        </h3>
                                    </div>

                                    {/* Tìm kiếm */}
                                    <div className="relative w-full sm:w-64">
                                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                        <input
                                            type="text"
                                            placeholder="Tìm mã căn, tên cư dân..."
                                            value={previewSearch}
                                            onChange={(e) => setPreviewSearch(e.target.value)}
                                            className="w-full bg-white border border-slate-200 rounded-xl pl-9 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                                        />
                                    </div>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-xs border-collapse">
                                        <thead>
                                            <tr className="border-b border-slate-200/80 bg-slate-100/50 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
                                                <th className="py-3 px-4">Căn Hộ</th>
                                                <th className="py-3 px-4">Đại Diện</th>
                                                <th className="py-3 px-4 text-center">⚡ Điện</th>
                                                <th className="py-3 px-4 text-center">💧 Nước</th>
                                                <th className="py-3 px-4 text-right">Trước Thuế</th>
                                                <th className="py-3 px-4 text-right">Thuế & Phí</th>
                                                <th className="py-3 px-4 text-right">Nợ Cũ</th>
                                                <th className="py-3 px-4 text-right">Tổng Phải Thu</th>
                                                <th className="py-3 px-4 text-center">Trạng Thái</th>
                                                <th className="py-3 px-4 text-right">Thao Tác</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {filteredPreviewItems.length === 0 ? (
                                                <tr>
                                                    <td colSpan={10} className="py-12 text-center text-slate-400">
                                                        Không tìm thấy căn hộ nào phù hợp với bộ lọc.
                                                    </td>
                                                </tr>
                                            ) : (
                                                filteredPreviewItems.map((item: InvoicePreviewItem) => (
                                                    <tr key={item.apartment_id} className="hover:bg-slate-50/60 transition-colors">
                                                        {/* Căn hộ */}
                                                        <td className="py-3 px-4 font-bold text-neutral-900">
                                                            <div className="flex items-center gap-1.5">
                                                                <Building2 className="w-3.5 h-3.5 text-sky-600" />
                                                                <span>{item.apartment_number}</span>
                                                            </div>
                                                            <div className="text-[11px] text-slate-400 font-normal">
                                                                {item.block_name} • {item.area_sqm} m²
                                                            </div>
                                                        </td>

                                                        {/* Đại diện */}
                                                        <td className="py-3 px-4 text-slate-700 font-semibold">
                                                            {item.resident_name}
                                                        </td>

                                                        {/* Điện */}
                                                        <td className="py-3 px-4 text-center">
                                                            {item.has_electricity_reading ? (
                                                                <span className="inline-flex items-center gap-1 text-emerald-700 font-semibold bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full text-[10px]">
                                                                    <Check className="w-3 h-3 text-emerald-600" />
                                                                    <span>Có số</span>
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 text-rose-700 font-medium bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-full text-[10px]">
                                                                    <X className="w-3 h-3 text-rose-600" />
                                                                    <span>Chưa ghi</span>
                                                                </span>
                                                            )}
                                                        </td>

                                                        {/* Nước */}
                                                        <td className="py-3 px-4 text-center">
                                                            {item.has_water_reading ? (
                                                                <span className="inline-flex items-center gap-1 text-emerald-700 font-semibold bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full text-[10px]">
                                                                    <Check className="w-3 h-3 text-emerald-600" />
                                                                    <span>Có số</span>
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 text-rose-700 font-medium bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-full text-[10px]">
                                                                    <X className="w-3 h-3 text-rose-600" />
                                                                    <span>Chưa ghi</span>
                                                                </span>
                                                            )}
                                                        </td>

                                                        {/* Trước thuế */}
                                                        <td className="py-3 px-4 text-right font-mono font-semibold text-slate-800">
                                                            {item.subtotal_amount.toLocaleString('vi-VN')} đ
                                                        </td>

                                                        {/* Thuế & Phí */}
                                                        <td className="py-3 px-4 text-right font-mono text-slate-500">
                                                            {item.tax_amount.toLocaleString('vi-VN')} đ
                                                        </td>

                                                        {/* Nợ cũ */}
                                                        <td className="py-3 px-4 text-right font-mono">
                                                            {item.previous_debt_amount > 0 ? (
                                                                <span className="text-amber-700 font-bold bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded">
                                                                    +{item.previous_debt_amount.toLocaleString('vi-VN')} đ
                                                                </span>
                                                            ) : (
                                                                <span className="text-slate-400">0 đ</span>
                                                            )}
                                                        </td>

                                                        {/* Tổng phải thu */}
                                                        <td className="py-3 px-4 text-right font-mono font-black text-emerald-700 text-xs">
                                                            {item.total_amount.toLocaleString('vi-VN')} đ
                                                        </td>

                                                        {/* Trạng thái */}
                                                        <td className="py-3 px-4 text-center">
                                                            {item.has_existing_invoice ? (
                                                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                                    Đã có HĐ ({item.existing_invoice_number})
                                                                </span>
                                                            ) : (
                                                                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200">
                                                                    Sẵn sàng tạo
                                                                </span>
                                                            )}
                                                        </td>

                                                        {/* Thao tác xem chi tiết */}
                                                        <td className="py-3 px-4 text-right">
                                                            <button
                                                                type="button"
                                                                onClick={() => setSelectedItemForDetail(item)}
                                                                className="px-2.5 py-1 rounded-lg bg-white hover:bg-slate-50 text-sky-700 border border-slate-200 shadow-2xs font-bold transition-colors cursor-pointer"
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
                <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                    <div className="p-4 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Clock className="w-4 h-4 text-sky-600" />
                            <h3 className="text-xs font-bold text-neutral-900 uppercase tracking-wider font-mono">
                                Lịch Sử Các Đợt Phát Hành Hóa Đơn
                            </h3>
                        </div>
                        <button
                            type="button"
                            onClick={loadBatches}
                            disabled={batchesLoading}
                            className="p-1.5 rounded-lg bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                        >
                            <RefreshCw className={`w-3.5 h-3.5 ${batchesLoading ? 'animate-spin text-sky-600' : ''}`} />
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr className="border-b border-slate-200/80 bg-slate-100/50 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
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
                            <tbody className="divide-y divide-slate-100">
                                {batches.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="py-12 text-center text-slate-400">
                                            Chưa có đợt sinh hóa đơn nào được ghi nhận.
                                        </td>
                                    </tr>
                                ) : (
                                    batches.map((b) => (
                                        <tr key={b.id} className="hover:bg-slate-50/60 transition-colors">
                                            <td className="py-3 px-4 font-mono font-bold text-sky-600">
                                                {b.batch_number}
                                            </td>
                                            <td className="py-3 px-4 font-mono font-bold text-slate-800">
                                                {b.billing_period}
                                            </td>
                                            <td className="py-3 px-4 text-slate-700">
                                                {b.block ? `${b.block.block_code} - ${b.block.block_name}` : 'Toàn Bộ Tòa Nhà'}
                                            </td>
                                            <td className="py-3 px-4 text-slate-700">
                                                {b.executed_by?.full_name || 'Hệ Thống'}
                                            </td>
                                            <td className="py-3 px-4 text-right font-mono text-slate-700">
                                                {b.total_apartments_processed}
                                            </td>
                                            <td className="py-3 px-4 text-right font-mono font-bold text-neutral-900">
                                                {b.total_invoices_created}
                                            </td>
                                            <td className="py-3 px-4 text-right font-mono font-bold text-emerald-700">
                                                {b.total_amount_calculated.toLocaleString('vi-VN')} đ
                                            </td>
                                            <td className="py-3 px-4 text-center">
                                                <span
                                                    className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                                                        b.status === 'COMPLETED'
                                                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                                            : b.status === 'RUNNING'
                                                            ? 'bg-sky-50 text-sky-700 border border-sky-200'
                                                            : 'bg-rose-50 text-rose-700 border border-rose-200'
                                                    }`}
                                                >
                                                    {b.status}
                                                </span>
                                            </td>
                                            <td className="py-3 px-4 text-right text-slate-500 font-mono">
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
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="p-6 space-y-4">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 rounded-xl bg-amber-50 text-amber-600 border border-amber-200">
                                    <AlertTriangle className="w-5 h-5 text-amber-600" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-neutral-900">
                                        Xác Nhận Phát Hành Hóa Đơn Hàng Loạt
                                    </h3>
                                    <p className="text-xs text-slate-500">
                                        Kỳ hóa đơn: <strong className="text-sky-600 font-mono">{billingPeriod}</strong>
                                    </p>
                                </div>
                            </div>

                            <div className="bg-slate-50/70 border border-slate-200 rounded-xl p-4 space-y-2 text-xs text-slate-700">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Phạm vi phát hành:</span>
                                    <span className="font-bold text-neutral-900">
                                        {selectedBlockId ? 'Theo Khối được chọn' : 'Toàn Bộ Tòa Nhà'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Cộng dồn nợ cũ:</span>
                                    <span className="font-bold text-emerald-700">
                                        {includePreviousDebt ? 'Đã bật' : 'Tắt'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Ghi đè hóa đơn đã có:</span>
                                    <span className="font-bold text-rose-700">
                                        {overwriteExisting ? 'Cho phép ghi đè' : 'Bỏ qua (không ghi đè)'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Hạn chót thanh toán:</span>
                                    <span className="font-mono font-bold text-neutral-900">{dueDate}</span>
                                </div>
                            </div>

                            <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 p-3 rounded-xl font-medium">
                                ⚠️ Thao tác này sẽ khóa toàn bộ chỉ số điện và nước của kỳ này, ghi nhận doanh thu vào cơ sở dữ liệu và gửi thông báo công nợ cho cư dân.
                            </p>
                        </div>

                        <div className="flex items-center justify-end gap-2.5 p-4 bg-slate-50/70 border-t border-slate-100">
                            <button
                                type="button"
                                onClick={() => setIsConfirmModalOpen(false)}
                                disabled={generating}
                                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 bg-white border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                            >
                                Hủy Bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirmGenerate}
                                disabled={generating}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 shadow-sm transition-all flex items-center gap-1.5 cursor-pointer"
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
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200 max-h-[90vh] flex flex-col">
                        <div className="p-5 border-b border-slate-100 bg-slate-50/70 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="p-2 rounded-xl bg-sky-50 text-sky-600 border border-sky-100">
                                    <Building2 className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-bold text-neutral-900">
                                        Chi Tiết Khoản Mục Hóa Đơn Căn {selectedItemForDetail.apartment_number}
                                    </h3>
                                    <p className="text-xs text-slate-500">
                                        Đại diện: {selectedItemForDetail.resident_name} • Diện tích: {selectedItemForDetail.area_sqm} m²
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedItemForDetail(null)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-4">
                            <div className="space-y-3">
                                {selectedItemForDetail.items_preview.map((item, idx) => (
                                    <div
                                        key={idx}
                                        className="bg-white border border-slate-200/80 rounded-xl p-4 space-y-2 text-xs shadow-2xs"
                                    >
                                        <div className="flex items-center justify-between font-bold text-neutral-900">
                                            <div className="flex items-center gap-2">
                                                {item.service_code === 'ELECTRICITY' ? (
                                                    <Zap className="w-4 h-4 text-amber-600" />
                                                ) : item.service_code === 'WATER' ? (
                                                    <Droplets className="w-4 h-4 text-sky-600" />
                                                ) : item.service_code === 'PARKING_FEE' ? (
                                                    <Car className="w-4 h-4 text-indigo-600" />
                                                ) : (
                                                    <FileText className="w-4 h-4 text-slate-600" />
                                                )}
                                                <span>{item.item_description}</span>
                                            </div>
                                            <span className="font-mono text-emerald-700 font-bold text-xs">
                                                {item.total_line_amount.toLocaleString('vi-VN')} đ
                                            </span>
                                        </div>

                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-slate-500 pt-1 border-t border-slate-100 text-[11px]">
                                            <div>Số lượng: <strong className="text-slate-800 font-mono">{item.quantity} {item.unit_name}</strong></div>
                                            <div>Đơn giá: <strong className="text-slate-800 font-mono">{item.unit_price.toLocaleString('vi-VN')} đ</strong></div>
                                            <div>Trước thuế: <strong className="text-slate-800 font-mono">{item.amount_before_tax.toLocaleString('vi-VN')} đ</strong></div>
                                            <div>VAT/BVMT: <strong className="text-slate-800 font-mono">{(item.vat_amount + item.environmental_fee_amount).toLocaleString('vi-VN')} đ</strong></div>
                                        </div>

                                        {/* Chi tiết từng bậc nếu là điện hoặc nước */}
                                        {item.tier_calculation_details && item.tier_calculation_details.length > 0 && (
                                            <div className="mt-2 pt-2 border-t border-slate-100 space-y-1">
                                                <div className="text-[11px] font-bold text-slate-600">Chi tiết theo bậc thang lũy tiến:</div>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-[11px] font-mono">
                                                    {item.tier_calculation_details.map((t: any, tidx: number) => (
                                                        <div key={tidx} className="bg-slate-50 px-2 py-1 rounded border border-slate-200/80 flex justify-between">
                                                            <span className="text-slate-500">{t.tier_name}:</span>
                                                            <span className="text-slate-800">{t.tier_usage} {item.unit_name} × {t.unit_price.toLocaleString('vi-VN')} = <strong>{t.tier_amount.toLocaleString('vi-VN')} đ</strong></span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>

                            {/* Tổng kết tiền */}
                            <div className="bg-slate-50/70 p-4 rounded-xl border border-slate-200 space-y-1.5 text-xs">
                                <div className="flex justify-between text-slate-500">
                                    <span>Tổng tiền dịch vụ trước thuế:</span>
                                    <span className="font-mono text-slate-800 font-semibold">{selectedItemForDetail.subtotal_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                <div className="flex justify-between text-slate-500">
                                    <span>Tổng thuế VAT & Phí bảo vệ môi trường:</span>
                                    <span className="font-mono text-slate-800 font-semibold">{selectedItemForDetail.tax_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                {selectedItemForDetail.previous_debt_amount > 0 && (
                                    <div className="flex justify-between text-amber-700 font-bold">
                                        <span>Công nợ tồn từ kỳ trước:</span>
                                        <span className="font-mono">+{selectedItemForDetail.previous_debt_amount.toLocaleString('vi-VN')} đ</span>
                                    </div>
                                )}
                                <div className="flex justify-between text-xs font-black text-emerald-700 pt-2 border-t border-slate-200">
                                    <span>TỔNG CỘNG PHẢI THANH TOÁN:</span>
                                    <span className="font-mono text-sm">{selectedItemForDetail.total_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                            </div>
                        </div>

                        <div className="p-4 bg-slate-50/70 border-t border-slate-100 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setSelectedItemForDetail(null)}
                                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
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
