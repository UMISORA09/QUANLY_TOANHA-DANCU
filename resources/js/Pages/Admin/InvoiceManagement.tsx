import React, { useState, useEffect, useCallback } from 'react';
import {
    Receipt,
    Search,
    Filter,
    Calendar,
    Building2,
    Eye,
    XCircle,
    CheckCircle2,
    AlertTriangle,
    Clock,
    DollarSign,
    RefreshCw,
    Download,
    Layers,
    ChevronLeft,
    ChevronRight,
    ArrowUpDown,
    CreditCard,
    Zap,
    Droplets,
    Car,
    FileText,
    Sparkles,
    Trash2,
    Plus,
    X,
    QrCode,
    Printer,
    Copy,
    Check
} from 'lucide-react';
import {
    invoiceApi,
    InvoiceModel,
    InvoiceSummaryModel
} from '../../Services/invoiceApi';
import { InvoiceBatchGeneration } from './InvoiceBatchGeneration';

interface BlockOption {
    id: string;
    block_code: string;
    block_name: string;
}

export const InvoiceManagement: React.FC = () => {
    // Mode chuyển đổi giữa Quản lý danh sách và Sinh hóa đơn hàng loạt
    const [viewMode, setViewMode] = useState<'list' | 'batch_generate'>('list');

    // Dữ liệu danh sách & KPI
    const [invoices, setInvoices] = useState<InvoiceModel[]>([]);
    const [summary, setSummary] = useState<InvoiceSummaryModel | null>(null);
    const [loading, setLoading] = useState<boolean>(true);

    // Bộ lọc
    const [statusFilter, setStatusFilter] = useState<string>('ALL');
    const [billingPeriodFilter, setBillingPeriodFilter] = useState<string>('');
    const [blockFilter, setBlockFilter] = useState<string>('');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [sortBy, setSortBy] = useState<string>('created_at');
    const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');

    // Phân trang
    const [pagination, setPagination] = useState({
        current_page: 1,
        last_page: 1,
        total: 0,
        per_page: 15,
    });

    // Danh sách khối tòa
    const [blocks, setBlocks] = useState<BlockOption[]>([]);

    // Selection để hủy hàng loạt
    const [selectedInvoiceIds, setSelectedInvoiceIds] = useState<string[]>([]);

    // Modal state
    const [detailInvoice, setDetailInvoice] = useState<InvoiceModel | null>(null);
    const [cancelModalInvoice, setCancelModalInvoice] = useState<InvoiceModel | null>(null);
    const [cancelReason, setCancelReason] = useState<string>('');
    const [cancelling, setCancelling] = useState<boolean>(false);

    // Thu tiền & Gạch nợ states (Chức năng 8)
    const [payModalInvoice, setPayModalInvoice] = useState<InvoiceModel | null>(null);
    const [payAmount, setPayAmount] = useState<number>(0);
    const [payMethod, setPayMethod] = useState<string>('CASH');
    const [payTransactionId, setPayTransactionId] = useState<string>('');
    const [payNotes, setPayNotes] = useState<string>('');
    const [paying, setPaying] = useState<boolean>(false);

    // VietQR Modal states
    const [vietQrModalData, setVietQrModalData] = useState<any | null>(null);
    const [loadingVietQr, setLoadingVietQr] = useState<boolean>(false);
    const [copiedText, setCopiedText] = useState<string | null>(null);

    // Biên lai thu tiền Modal states
    const [receiptModalData, setReceiptModalData] = useState<any | null>(null);


    // Toast message
    const [toast, setToast] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    const showToast = (type: 'success' | 'error', text: string) => {
        setToast({ type, text });
        setTimeout(() => setToast(null), 4000);
    };

    // Tải danh sách Block
    useEffect(() => {
        const fetchBlocks = async () => {
            try {
                const token = localStorage.getItem('auth_token') || 'smart_token_admin_demo';
                const res = await fetch('/api/v1/blocks', {
                    headers: {
                        Accept: 'application/json',
                        Authorization: token.startsWith('Bearer ') ? token : `Bearer ${token}`,
                    },
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

    // Tải danh sách hóa đơn từ API
    const fetchInvoices = useCallback(async () => {
        setLoading(true);
        try {
            const res = await invoiceApi.getInvoices({
                status: statusFilter !== 'ALL' ? statusFilter : undefined,
                billing_period: billingPeriodFilter || undefined,
                block_id: blockFilter || undefined,
                search: searchQuery || undefined,
                sort_by: sortBy,
                sort_order: sortOrder,
                page: pagination.current_page,
                per_page: pagination.per_page,
            });

            setInvoices(res.data.data);
            setPagination({
                current_page: res.data.current_page,
                last_page: res.data.last_page,
                total: res.data.total,
                per_page: pagination.per_page,
            });
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi tải danh sách hóa đơn');
        } finally {
            setLoading(false);
        }
    }, [statusFilter, billingPeriodFilter, blockFilter, searchQuery, sortBy, sortOrder, pagination.current_page, pagination.per_page]);

    // Tải tóm tắt KPI
    const fetchSummary = useCallback(async () => {
        try {
            const res = await invoiceApi.getSummary({
                billing_period: billingPeriodFilter || undefined,
                block_id: blockFilter || undefined,
            });
            setSummary(res.data);
        } catch (err: any) {
            console.error('Lỗi khi tải KPI summary:', err);
        }
    }, [billingPeriodFilter, blockFilter]);

    useEffect(() => {
        if (viewMode === 'list') {
            fetchInvoices();
            fetchSummary();
        }
    }, [fetchInvoices, fetchSummary, viewMode]);

    // Xử lý Hủy một hóa đơn
    const handleConfirmCancel = async () => {
        if (!cancelModalInvoice) return;
        setCancelling(true);
        try {
            const res = await invoiceApi.cancelInvoice(cancelModalInvoice.id, cancelReason);
            showToast('success', res.message || 'Đã hủy hóa đơn thành công.');
            setCancelModalInvoice(null);
            setCancelReason('');
            fetchInvoices();
            fetchSummary();
        } catch (err: any) {
            showToast('error', err.message || 'Không thể hủy hóa đơn');
        } finally {
            setCancelling(false);
        }
    };

    // Xử lý Hủy hàng loạt hóa đơn đã chọn
    const handleBulkCancel = async () => {
        if (selectedInvoiceIds.length === 0) return;
        const confirmMsg = `Bạn có chắc chắn muốn hủy ${selectedInvoiceIds.length} hóa đơn đã chọn? Toàn bộ chỉ số điện nước liên quan sẽ được mở khóa.`;
        if (!window.confirm(confirmMsg)) return;

        try {
            const reason = window.prompt('Nhập lý do hủy hàng loạt:', 'Hủy hàng loạt do tính sai chu kỳ hoặc trùng lặp');
            if (reason === null) return;

            const res = await invoiceApi.bulkCancelInvoices(selectedInvoiceIds, reason || 'Hủy hàng loạt bởi quản trị viên');
            showToast('success', res.message || `Đã hủy thành công ${res.data.cancelled_count} hóa đơn.`);
            setSelectedInvoiceIds([]);
            fetchInvoices();
            fetchSummary();
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi hủy hàng loạt hóa đơn');
        }
    };

    // Checkbox toggle
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            const eligibleIds = invoices
                .filter(i => i.status !== 'PAID' && i.status !== 'CANCELLED' && i.paid_amount === 0)
                .map(i => i.id);
            setSelectedInvoiceIds(eligibleIds);
        } else {
            setSelectedInvoiceIds([]);
        }
    };

    const handleSelectOne = (id: string, checked: boolean) => {
        if (checked) {
            setSelectedInvoiceIds(prev => [...prev, id]);
        } else {
            setSelectedInvoiceIds(prev => prev.filter(item => item !== id));
        }
    };


    // Mở Modal Thu tiền
    const handleOpenPayModal = (inv: InvoiceModel) => {
        setPayModalInvoice(inv);
        setPayAmount(inv.remaining_balance);
        setPayMethod('CASH');
        setPayTransactionId('');
        setPayNotes(`Thu tiền hóa đơn ${inv.invoice_number}`);
    };

    // Xác nhận thu tiền & gạch nợ tức thời
    const handleConfirmPayment = async () => {
        if (!payModalInvoice) return;
        if (payAmount <= 0) {
            showToast('error', 'Số tiền thanh toán phải lớn hơn 0 đ');
            return;
        }
        if (payAmount > payModalInvoice.remaining_balance) {
            showToast('error', `Số tiền không được lớn hơn dư nợ còn lại (${payModalInvoice.remaining_balance.toLocaleString('vi-VN')} đ)`);
            return;
        }

        setPaying(true);
        try {
            const idempotencyKey = `PAY-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
            const res = await invoiceApi.collectPayment({
                invoice_id: payModalInvoice.id,
                amount: payAmount,
                payment_method: payMethod,
                transaction_id: payTransactionId.trim() || undefined,
                notes: payNotes.trim() || undefined,
                idempotency_key: idempotencyKey,
            });

            if (res.success) {
                showToast('success', res.message);
                setPayModalInvoice(null);
                fetchInvoices();
                fetchSummary();
                if (res.data?.receipt) {
                    setReceiptModalData({
                        receipt: res.data.receipt,
                        payment: res.data.payment,
                        invoice: res.data.invoice,
                    });
                }
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi xử lý thu tiền');
        } finally {
            setPaying(false);
        }
    };

    // Mở Modal VietQR
    const handleOpenVietQr = async (inv: InvoiceModel) => {
        setLoadingVietQr(true);
        try {
            const res = await invoiceApi.getVietQrPayload(inv.id);
            if (res.success) {
                setVietQrModalData(res.data);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Không thể tạo mã VietQR');
        } finally {
            setLoadingVietQr(false);
        }
    };

    // Sao chép clipboard
    const copyToClipboard = (text: string, label: string) => {
        navigator.clipboard.writeText(text);
        setCopiedText(label);
        setTimeout(() => setCopiedText(null), 2500);
        showToast('success', `Đã sao chép ${label}!`);
    };

    // Helper render Pill trạng thái
    const renderStatusBadge = (status: string, dueDate: string) => {
        const isOverdue = status === 'OVERDUE' || (['ISSUED', 'PARTIAL'].includes(status) && new Date(dueDate) < new Date());

        if (status === 'PAID') {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                    <span>Đã Thanh Toán</span>
                </span>
            );
        }

        if (status === 'CANCELLED') {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                    <XCircle className="w-3 h-3 text-slate-500" />
                    <span>Đã Hủy</span>
                </span>
            );
        }

        if (isOverdue) {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                    <AlertTriangle className="w-3 h-3 text-rose-600" />
                    <span>Quá Hạn</span>
                </span>
            );
        }

        if (status === 'PARTIAL') {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                    <Clock className="w-3 h-3 text-amber-600" />
                    <span>Thanh Toán 1 Phần</span>
                </span>
            );
        }

        return (
            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200">
                <Clock className="w-3 h-3 text-sky-600" />
                <span>Chờ Thanh Toán</span>
            </span>
        );
    };

    if (viewMode === 'batch_generate') {
        return (
            <div className="space-y-4">
                <div className="p-4 bg-white border border-slate-200/80 rounded-2xl flex items-center justify-between shadow-xs">
                    <button
                        type="button"
                        onClick={() => setViewMode('list')}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-slate-700 hover:text-slate-900 bg-white hover:bg-slate-50 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                    >
                        <ChevronLeft className="w-4 h-4 text-sky-600" />
                        <span>Quay Lại Danh Sách Hóa Đơn</span>
                    </button>
                    <span className="text-xs text-sky-700 font-bold font-mono">
                        CHẾ ĐỘ: SINH HÓA ĐƠN HÀNG LOẠT THEO THÁNG
                    </span>
                </div>
                <InvoiceBatchGeneration />
            </div>
        );
    }

    return (
        <div className="max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6">
            {/* TOAST THÔNG BÁO */}
            {toast && (
                <div
                    className={`fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border text-xs font-semibold animate-in slide-in-from-bottom duration-200 ${
                        toast.type === 'success'
                            ? 'bg-emerald-50 text-emerald-800 border-emerald-300'
                            : 'bg-rose-50 text-rose-800 border-rose-300'
                    }`}
                >
                    {toast.type === 'success' ? (
                        <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                    ) : (
                        <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                    )}
                    <span>{toast.text}</span>
                </div>
            )}

            {/* HEADER TIÊU ĐỀ & NÚT HÀNH ĐỘNG */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200/80">
                <div>
                    <div className="text-[11px] font-bold text-sky-600 font-mono tracking-wider uppercase flex items-center gap-1.5">
                        <Receipt className="w-3.5 h-3.5" />
                        <span>HỆ THỐNG KẾ TOÁN & HÓA ĐƠN · CHỨC NĂNG #7 (QUẢN LÝ & LỌC HÓA ĐƠN)</span>
                    </div>
                    <h1 className="text-2xl sm:text-3xl font-black text-neutral-900 tracking-tight mt-1">
                        Quản Lý & Lọc Trạng Thái Hóa Đơn
                    </h1>
                    <p className="text-xs sm:text-sm text-slate-500 max-w-3xl font-medium mt-1">
                        Theo dõi công nợ, phân loại trạng thái thanh toán, lọc theo kỳ và quản lý chi tiết hóa đơn dịch vụ cư dân.
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setViewMode('batch_generate')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white text-xs font-bold shadow-sm transition-all cursor-pointer"
                    >
                        <Plus className="w-4 h-4" />
                        <span>Sinh Hóa Đơn Hàng Loạt</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => { fetchInvoices(); fetchSummary(); }}
                        disabled={loading}
                        title="Làm mới dữ liệu"
                        className="p-2 rounded-xl text-slate-500 hover:text-slate-800 bg-white border border-slate-200 hover:bg-slate-50 shadow-2xs transition-all active:scale-[0.98] cursor-pointer shrink-0"
                    >
                        <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin text-sky-600' : ''}`} />
                    </button>
                </div>
            </div>

            {/* KPI METRIC CARDS TỔNG HỢP */}
            {summary && (
                <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-5 gap-2.5 sm:gap-3.5">
                    {/* Thẻ 1: Tổng số hóa đơn */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Tổng Hóa Đơn</span>
                            <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                                <FileText className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-neutral-900 font-mono">
                            {summary.total_invoices} <span className="text-xs font-normal text-slate-400">HĐ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-slate-500 truncate">
                            Toàn bộ trong phạm vi lọc
                        </div>
                    </div>

                    {/* Thẻ 2: Tổng số tiền phát hành */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Tổng Doanh Thu</span>
                            <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                                <CreditCard className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-sky-600 font-mono">
                            {summary.total_amount.toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-sky-700 font-medium truncate">
                            Tổng giá trị phải thu
                        </div>
                    </div>

                    {/* Thẻ 3: Đã thu thành công */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Đã Thu Thành Công</span>
                            <div className="kpi-icon-wrapper bg-emerald-50 text-emerald-600">
                                <CheckCircle2 className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-emerald-600 font-mono">
                            {summary.paid_amount.toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-emerald-700 font-semibold truncate">
                            Tỷ lệ: {summary.collection_rate}% chỉ tiêu
                        </div>
                    </div>

                    {/* Thẻ 4: Còn nợ đọng */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Dư Nợ Còn Lại</span>
                            <div className="kpi-icon-wrapper bg-amber-50 text-amber-600">
                                <Clock className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-amber-600 font-mono">
                            {summary.remaining_balance.toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-amber-700 font-medium truncate">
                            Chờ cư dân nộp tiền
                        </div>
                    </div>

                    {/* Thẻ 5: Hóa đơn quá hạn */}
                    <div className="sass-db-kpi-card col-span-2 sm:col-span-1">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Quá Hạn Nộp Tiền</span>
                            <div className="kpi-icon-wrapper bg-rose-50 text-rose-600">
                                <AlertTriangle className="w-4 h-4" />
                            </div>
                        </div>
                        <div className={`mt-1 sm:mt-2 text-xl sm:text-2xl font-black font-mono ${summary.counts_by_status.OVERDUE > 0 ? 'text-rose-600' : 'text-neutral-900'}`}>
                            {summary.counts_by_status.OVERDUE} <span className="text-xs font-normal text-slate-400">HĐ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] truncate">
                            {summary.counts_by_status.OVERDUE > 0 ? (
                                <span className="text-rose-600 font-bold">Cần gửi email nhắc nợ</span>
                            ) : (
                                <span className="text-emerald-700 font-medium">Không có nợ quá hạn</span>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* THANH LỌC TRẠNG THÁI (STATUS TABS) */}
            <div className="flex flex-wrap items-center gap-1.5 p-1.5 bg-slate-100/70 rounded-2xl">
                {[
                    { key: 'ALL', label: 'Tất Cả', count: summary?.total_invoices },
                    { key: 'ISSUED', label: 'Chờ Thanh Toán', count: summary?.counts_by_status.ISSUED },
                    { key: 'PARTIAL', label: 'Thanh Toán 1 Phần', count: summary?.counts_by_status.PARTIAL },
                    { key: 'PAID', label: 'Đã Thu Đủ', count: summary?.counts_by_status.PAID },
                    { key: 'OVERDUE', label: 'Quá Hạn', count: summary?.counts_by_status.OVERDUE, badgeColor: 'text-rose-600' },
                    { key: 'CANCELLED', label: 'Đã Hủy', count: summary?.counts_by_status.CANCELLED },
                ].map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => {
                            setStatusFilter(tab.key);
                            setPagination(p => ({ ...p, current_page: 1 }));
                        }}
                        className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 cursor-pointer ${
                            statusFilter === tab.key
                                ? 'bg-white text-sky-700 shadow-2xs'
                                : 'text-slate-600 hover:text-slate-900 hover:bg-white/60'
                        }`}
                    >
                        <span>{tab.label}</span>
                        {tab.count !== undefined && (
                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-mono font-bold ${
                                statusFilter === tab.key
                                    ? 'bg-sky-50 text-sky-700'
                                    : 'bg-slate-200/80 text-slate-600'
                            }`}>
                                {tab.count}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {/* BỘ LỌC ĐA CHIỀU & TÌM KIẾM */}
            <div className="bg-white border border-slate-200/80 p-3 rounded-2xl shadow-xs flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2.5 flex-1">
                    {/* Ô tìm kiếm */}
                    <div className="relative flex-1 min-w-[220px]">
                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            placeholder="Tìm số HĐ, căn hộ, tên cư dân, SĐT..."
                            value={searchQuery}
                            onChange={(e) => {
                                setSearchQuery(e.target.value);
                                setPagination(p => ({ ...p, current_page: 1 }));
                            }}
                            className="w-full bg-white border border-slate-200 rounded-xl pl-9 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                        />
                    </div>

                    {/* Lọc Kỳ Hóa Đơn */}
                    <div className="w-40">
                        <input
                            type="month"
                            value={billingPeriodFilter}
                            onChange={(e) => {
                                setBillingPeriodFilter(e.target.value);
                                setPagination(p => ({ ...p, current_page: 1 }));
                            }}
                            title="Lọc theo kỳ tháng/năm"
                            className="w-full bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 font-mono"
                        />
                    </div>

                    {/* Lọc Khối Tòa Nhà */}
                    <div className="w-44">
                        <select
                            value={blockFilter}
                            onChange={(e) => {
                                setBlockFilter(e.target.value);
                                setPagination(p => ({ ...p, current_page: 1 }));
                            }}
                            className="w-full bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-700 font-medium focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 cursor-pointer"
                        >
                            <option value="">🏢 Tất Cả Tòa Nhà</option>
                            {blocks.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.block_code} - {b.block_name}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Nút Xóa Lọc */}
                    {(billingPeriodFilter || blockFilter || searchQuery || statusFilter !== 'ALL') && (
                        <button
                            type="button"
                            onClick={() => {
                                setStatusFilter('ALL');
                                setBillingPeriodFilter('');
                                setBlockFilter('');
                                setSearchQuery('');
                                setPagination(p => ({ ...p, current_page: 1 }));
                            }}
                            className="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-bold text-slate-700 transition-colors cursor-pointer"
                        >
                            Xóa Lọc
                        </button>
                    )}
                </div>

                {/* Thao tác hàng loạt (Bulk Cancel) */}
                {selectedInvoiceIds.length > 0 && (
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={handleBulkCancel}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-2xs transition-all cursor-pointer"
                        >
                            <Trash2 className="w-3.5 h-3.5" />
                            <span>Hủy {selectedInvoiceIds.length} HĐ Đã Chọn</span>
                        </button>
                    </div>
                )}
            </div>

            {/* BẢNG DỮ LIỆU CHÍNH HÓA ĐƠN */}
            <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr className="border-b border-slate-200/80 bg-slate-100/50 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
                                <th className="py-3 px-4 w-10 text-center">
                                    <input
                                        type="checkbox"
                                        checked={
                                            invoices.length > 0 &&
                                            selectedInvoiceIds.length === invoices.filter(i => i.status !== 'PAID' && i.status !== 'CANCELLED' && i.paid_amount === 0).length
                                        }
                                        onChange={(e) => handleSelectAll(e.target.checked)}
                                        className="rounded border-slate-300 text-sky-600 w-4 h-4 cursor-pointer"
                                    />
                                </th>
                                <th className="py-3 px-4">Số Hóa Đơn</th>
                                <th className="py-3 px-4">Căn Hộ</th>
                                <th className="py-3 px-4">Cư Dân Nhận HĐ</th>
                                <th className="py-3 px-4 text-center">Kỳ Phí</th>
                                <th className="py-3 px-4">Hạn Nộp</th>
                                <th className="py-3 px-4 text-right">Tổng Tiền</th>
                                <th className="py-3 px-4 text-right">Đã Trả</th>
                                <th className="py-3 px-4 text-right">Còn Nợ</th>
                                <th className="py-3 px-4 text-center">Trạng Thái</th>
                                <th className="py-3 px-4 text-right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {loading ? (
                                <tr>
                                    <td colSpan={11} className="py-16 text-center text-slate-400">
                                        <RefreshCw className="w-6 h-6 animate-spin mx-auto text-sky-600 mb-2" />
                                        <span>Đang tải danh sách hóa đơn...</span>
                                    </td>
                                </tr>
                            ) : invoices.length === 0 ? (
                                <tr>
                                    <td colSpan={11} className="py-16 text-center text-slate-400">
                                        Không tìm thấy hóa đơn nào phù hợp với bộ lọc hiện tại.
                                    </td>
                                </tr>
                            ) : (
                                invoices.map((inv) => {
                                    const canCancel = inv.status !== 'PAID' && inv.status !== 'CANCELLED' && inv.paid_amount === 0;
                                    const isSelected = selectedInvoiceIds.includes(inv.id);

                                    return (
                                        <tr key={inv.id} className={`hover:bg-slate-50/60 transition-colors ${isSelected ? 'bg-sky-50/30' : ''}`}>
                                            {/* Checkbox */}
                                            <td className="py-3 px-4 text-center">
                                                {canCancel ? (
                                                    <input
                                                        type="checkbox"
                                                        checked={isSelected}
                                                        onChange={(e) => handleSelectOne(inv.id, e.target.checked)}
                                                        className="rounded border-slate-300 text-sky-600 w-4 h-4 cursor-pointer"
                                                    />
                                                ) : (
                                                    <span className="text-slate-300">-</span>
                                                )}
                                            </td>

                                            {/* Mã hóa đơn */}
                                            <td className="py-3 px-4 font-mono font-bold text-sky-600">
                                                {inv.invoice_number}
                                            </td>

                                            {/* Căn hộ */}
                                            <td className="py-3 px-4">
                                                <div className="font-bold text-neutral-900 flex items-center gap-1.5">
                                                    <Building2 className="w-3.5 h-3.5 text-sky-600" />
                                                    <span>{inv.apartment?.apartment_number || 'N/A'}</span>
                                                </div>
                                                <div className="text-[11px] text-slate-400">
                                                    {inv.apartment?.block?.block_name || 'Khối N/A'}
                                                </div>
                                            </td>

                                            {/* Cư dân */}
                                            <td className="py-3 px-4">
                                                <div className="font-semibold text-slate-800">
                                                    {inv.resident_user?.full_name || 'Đại diện Căn Hộ'}
                                                </div>
                                                <div className="text-[11px] text-slate-400 font-mono">
                                                    {inv.resident_user?.phone_number || ''}
                                                </div>
                                            </td>

                                            {/* Kỳ phí */}
                                            <td className="py-3 px-4 text-center font-mono font-bold text-slate-600">
                                                {inv.billing_period}
                                            </td>

                                            {/* Hạn nộp */}
                                            <td className="py-3 px-4 font-mono text-slate-500">
                                                {inv.due_date}
                                            </td>

                                            {/* Tổng tiền */}
                                            <td className="py-3 px-4 text-right font-mono font-bold text-neutral-900">
                                                {inv.total_amount.toLocaleString('vi-VN')} đ
                                            </td>

                                            {/* Đã trả */}
                                            <td className="py-3 px-4 text-right font-mono text-emerald-600 font-semibold">
                                                {inv.paid_amount.toLocaleString('vi-VN')} đ
                                            </td>

                                            {/* Còn nợ */}
                                            <td className="py-3 px-4 text-right font-mono font-bold text-amber-600">
                                                {inv.remaining_balance.toLocaleString('vi-VN')} đ
                                            </td>

                                            {/* Trạng thái */}
                                            <td className="py-3 px-4 text-center">
                                                {renderStatusBadge(inv.status, inv.due_date)}
                                            </td>

                                            {/* Thao tác */}
                                            <td className="py-3 px-4 text-right">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    {/* Nút VietQR */}
                                                    {inv.status !== 'CANCELLED' && inv.status !== 'PAID' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleOpenVietQr(inv)}
                                                            className="p-1.5 rounded-lg bg-white hover:bg-sky-50 text-sky-600 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                                                            title="Tạo mã VietQR thanh toán nhanh"
                                                        >
                                                            <QrCode className="w-3.5 h-3.5" />
                                                        </button>
                                                    )}

                                                    {/* Nút Thu tiền & Gạch nợ */}
                                                    {inv.status !== 'CANCELLED' && inv.status !== 'PAID' && inv.remaining_balance > 0 && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleOpenPayModal(inv)}
                                                            className="p-1.5 rounded-lg bg-white hover:bg-emerald-50 text-emerald-600 border border-emerald-200 shadow-2xs transition-colors cursor-pointer"
                                                            title="Thu tiền & Gạch nợ tức thời"
                                                        >
                                                            <CreditCard className="w-3.5 h-3.5" />
                                                        </button>
                                                    )}

                                                    {/* Nút Xem chi tiết */}
                                                    <button
                                                        type="button"
                                                        onClick={() => setDetailInvoice(inv)}
                                                        className="p-1.5 rounded-lg bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                                                        title="Xem chi tiết hóa đơn"
                                                    >
                                                        <Eye className="w-3.5 h-3.5" />
                                                    </button>

                                                    {/* Nút Hủy hóa đơn */}
                                                    {canCancel && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setCancelModalInvoice(inv)}
                                                            className="p-1.5 rounded-lg bg-white hover:bg-rose-50 text-rose-600 border border-rose-200 shadow-2xs transition-colors cursor-pointer"
                                                            title="Hủy hóa đơn"
                                                        >
                                                            <XCircle className="w-3.5 h-3.5" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* PHÂN TRANG */}
                {pagination.total > 0 && (
                    <div className="flex items-center justify-between p-4 bg-slate-50/70 border-t border-slate-100 text-xs text-slate-500">
                        <div>
                            Hiển thị trang <strong>{pagination.current_page}</strong> / <strong>{pagination.last_page}</strong> ({pagination.total} hóa đơn)
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setPagination(p => ({ ...p, current_page: Math.max(1, p.current_page - 1) }))}
                                disabled={pagination.current_page <= 1}
                                className="px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs cursor-pointer flex items-center gap-1"
                            >
                                <ChevronLeft className="w-3.5 h-3.5" />
                                <span>Trang trước</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setPagination(p => ({ ...p, current_page: Math.min(pagination.last_page, p.current_page + 1) }))}
                                disabled={pagination.current_page >= pagination.last_page}
                                className="px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs cursor-pointer flex items-center gap-1"
                            >
                                <span>Trang sau</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* MODAL 1: CHI TIẾT HÓA ĐƠN ĐIỆN TỬ */}
            {detailInvoice && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200 max-h-[92vh] flex flex-col">
                        <div className="p-5 border-b border-slate-100 bg-slate-50/70 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="p-2 rounded-xl bg-sky-50 text-sky-600 border border-sky-100">
                                    <Receipt className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
                                        <span>Chi Tiết Hóa Đơn {detailInvoice.invoice_number}</span>
                                        {renderStatusBadge(detailInvoice.status, detailInvoice.due_date)}
                                    </h3>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Kỳ tính phí: <strong className="text-sky-600 font-mono">{detailInvoice.billing_period}</strong> • Căn {detailInvoice.apartment?.apartment_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setDetailInvoice(null)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-4">
                            {/* Thông tin căn hộ & cư dân */}
                            <div className="grid grid-cols-2 gap-4 bg-slate-50/70 p-4 rounded-xl border border-slate-200 text-xs">
                                <div>
                                    <span className="text-slate-500">Căn hộ:</span>
                                    <div className="font-bold text-neutral-900 text-xs mt-0.5">
                                        Căn {detailInvoice.apartment?.apartment_number} ({detailInvoice.apartment?.block?.block_name})
                                    </div>
                                    <div className="text-slate-400 mt-0.5">
                                        Diện tích: {detailInvoice.apartment?.net_usable_area_sqm || 70} m²
                                    </div>
                                </div>
                                <div>
                                    <span className="text-slate-500">Cư dân nhận hóa đơn:</span>
                                    <div className="font-bold text-neutral-900 text-xs mt-0.5">
                                        {detailInvoice.resident_user?.full_name || 'Đại diện Căn Hộ'}
                                    </div>
                                    <div className="text-slate-400 mt-0.5 font-mono">
                                        SĐT: {detailInvoice.resident_user?.phone_number || 'N/A'}
                                    </div>
                                </div>
                            </div>

                            {/* Danh sách các khoản phí dịch vụ */}
                            <div className="space-y-2">
                                <h4 className="text-xs font-bold text-neutral-900 uppercase tracking-wider font-mono">
                                    Khoản Mục Chi Phí Dịch Vụ
                                </h4>

                                {detailInvoice.items && detailInvoice.items.length > 0 ? (
                                    <div className="space-y-2.5">
                                        {detailInvoice.items.map((item) => (
                                            <div
                                                key={item.id}
                                                className="bg-white border border-slate-200/80 rounded-xl p-3.5 space-y-2 text-xs shadow-2xs"
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
                                                    <div>Tiền trước thuế: <strong className="text-slate-800 font-mono">{item.amount_before_tax.toLocaleString('vi-VN')} đ</strong></div>
                                                    <div>Thuế VAT & BVMT: <strong className="text-slate-800 font-mono">{(item.vat_amount + item.environmental_fee_amount).toLocaleString('vi-VN')} đ</strong></div>
                                                </div>

                                                {/* Bậc thang lũy tiến nếu có */}
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
                                ) : (
                                    <div className="p-4 text-center text-slate-400 bg-slate-50 rounded-xl">
                                        Chưa có chi tiết dòng phí dịch vụ.
                                    </div>
                                )}
                            </div>

                            {/* Tổng kết tiền */}
                            <div className="bg-slate-50/70 p-4 rounded-xl border border-slate-200 space-y-1.5 text-xs">
                                <div className="flex justify-between text-slate-500">
                                    <span>Tổng trước thuế:</span>
                                    <span className="font-mono text-slate-800 font-semibold">{detailInvoice.subtotal_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                <div className="flex justify-between text-slate-500">
                                    <span>Tổng thuế VAT & Phí bảo vệ môi trường:</span>
                                    <span className="font-mono text-slate-800 font-semibold">{detailInvoice.tax_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                {detailInvoice.previous_debt_amount > 0 && (
                                    <div className="flex justify-between text-amber-700 font-bold">
                                        <span>Công nợ tồn từ kỳ trước:</span>
                                        <span className="font-mono">+{detailInvoice.previous_debt_amount.toLocaleString('vi-VN')} đ</span>
                                    </div>
                                )}
                                <div className="flex justify-between text-xs font-black text-neutral-900 pt-2 border-t border-slate-200">
                                    <span>TỔNG CỘNG PHẢI THU:</span>
                                    <span className="font-mono text-sm text-emerald-700">{detailInvoice.total_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                <div className="flex justify-between text-xs text-emerald-700 font-semibold">
                                    <span>Đã thanh toán:</span>
                                    <span className="font-mono">{detailInvoice.paid_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                <div className="flex justify-between text-xs text-amber-700 font-bold">
                                    <span>Dư nợ còn lại:</span>
                                    <span className="font-mono">{detailInvoice.remaining_balance.toLocaleString('vi-VN')} đ</span>
                                </div>
                            </div>
                        </div>

                        <div className="p-4 bg-slate-50/70 border-t border-slate-100 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setDetailInvoice(null)}
                                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                            >
                                Đóng
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 2: XÁC NHẬN HỦY HÓA ĐƠN */}
            {cancelModalInvoice && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-md overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="p-6 space-y-4">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 rounded-xl bg-rose-50 text-rose-600 border border-rose-200">
                                    <XCircle className="w-5 h-5 text-rose-600" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-neutral-900">Xác Nhận Hủy Hóa Đơn</h3>
                                    <p className="text-xs text-slate-500 font-mono">{cancelModalInvoice.invoice_number}</p>
                                </div>
                            </div>

                            <p className="text-xs text-slate-600">
                                Bạn có chắc chắn muốn hủy hóa đơn cho căn hộ <strong className="text-neutral-900">{cancelModalInvoice.apartment?.apartment_number}</strong>?
                                Hệ thống sẽ mở khóa các chỉ số điện/nước liên quan để có thể tính toán lại.
                            </p>

                            <div className="space-y-1.5">
                                <label className="text-xs font-bold text-slate-700">Lý do hủy hóa đơn:</label>
                                <textarea
                                    rows={3}
                                    placeholder="Nhập lý do hủy hóa đơn..."
                                    value={cancelReason}
                                    onChange={(e) => setCancelReason(e.target.value)}
                                    className="w-full bg-white border border-slate-200 rounded-xl p-3 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 resize-none"
                                />
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-2.5 p-4 bg-slate-50/70 border-t border-slate-100">
                            <button
                                type="button"
                                onClick={() => setCancelModalInvoice(null)}
                                disabled={cancelling}
                                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 bg-white border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                            >
                                Hủy Bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirmCancel}
                                disabled={cancelling}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 shadow-2xs transition-all flex items-center gap-1.5 cursor-pointer"
                            >
                                {cancelling && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                                <span>{cancelling ? 'Đang Hủy...' : 'Xác Nhận Hủy Hóa Đơn'}</span>
                            </button>
                        </div>
                    </div>
                </div>
            )}
            {/* MODAL 3: THU TIỀN & GẠCH NỢ TỨC THỜI (CHỨC NĂNG 8) */}
            {payModalInvoice && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Header Modal */}
                        <div className="p-5 border-b border-slate-100 bg-slate-50/70 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-200">
                                    <CreditCard className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-bold text-neutral-900">Thu Tiền & Gạch Nợ Tức Thời</h3>
                                    <p className="text-xs text-slate-500 font-mono">
                                        {payModalInvoice.invoice_number} • Căn {payModalInvoice.apartment?.apartment_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPayModalInvoice(null)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        {/* Body Form */}
                        <div className="p-6 space-y-4 text-xs">
                            {/* Tóm tắt dư nợ */}
                            <div className="grid grid-cols-3 gap-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
                                <div>
                                    <span className="text-slate-500">Tổng hóa đơn:</span>
                                    <p className="font-mono font-bold text-neutral-900 text-xs mt-0.5">
                                        {payModalInvoice.total_amount.toLocaleString('vi-VN')} đ
                                    </p>
                                </div>
                                <div>
                                    <span className="text-slate-500">Đã thanh toán:</span>
                                    <p className="font-mono font-semibold text-emerald-600 text-xs mt-0.5">
                                        {payModalInvoice.paid_amount.toLocaleString('vi-VN')} đ
                                    </p>
                                </div>
                                <div>
                                    <span className="text-slate-500">Dư nợ còn lại:</span>
                                    <p className="font-mono font-bold text-amber-600 text-xs mt-0.5">
                                        {payModalInvoice.remaining_balance.toLocaleString('vi-VN')} đ
                                    </p>
                                </div>
                            </div>

                            {/* Số tiền thu */}
                            <div className="space-y-1.5">
                                <div className="flex items-center justify-between">
                                    <label className="font-bold text-slate-700">Số tiền thu thực tế (VNĐ):</label>
                                    <button
                                        type="button"
                                        onClick={() => setPayAmount(payModalInvoice.remaining_balance)}
                                        className="text-[11px] text-emerald-600 hover:underline font-bold cursor-pointer"
                                    >
                                        Thu trọn dư nợ ({payModalInvoice.remaining_balance.toLocaleString('vi-VN')} đ)
                                    </button>
                                </div>
                                <div className="relative">
                                    <input
                                        type="number"
                                        min={1}
                                        max={payModalInvoice.remaining_balance}
                                        value={payAmount || ''}
                                        onChange={(e) => setPayAmount(Number(e.target.value))}
                                        className="w-full bg-white border border-slate-200 rounded-xl py-2 px-3 font-mono font-bold text-base text-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
                                        placeholder="Nhập số tiền thu..."
                                    />
                                    <span className="absolute right-3 top-2.5 text-slate-400 font-bold text-xs">VND</span>
                                </div>
                            </div>

                            {/* Phương thức thanh toán */}
                            <div className="space-y-1.5">
                                <label className="font-bold text-slate-700">Phương thức thanh toán:</label>
                                <select
                                    value={payMethod}
                                    onChange={(e) => setPayMethod(e.target.value)}
                                    className="w-full bg-white border border-slate-200 rounded-xl p-2.5 text-slate-800 font-medium focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 cursor-pointer"
                                >
                                    <option value="CASH">Tiền mặt tại quầy lễ tân (CASH)</option>
                                    <option value="BANK_TRANSFER">Chuyển khoản ngân hàng trực tiếp</option>
                                    <option value="VIETQR">Quét mã VietQR chuyển khoản nhanh</option>
                                    <option value="VNPAY">Cổng thanh toán điện tử VNPay</option>
                                    <option value="MOMO">Ví điện tử MoMo</option>
                                </select>
                            </div>

                            {/* Mã giao dịch ngân hàng / Ref */}
                            {payMethod !== 'CASH' && (
                                <div className="space-y-1.5">
                                    <label className="font-bold text-slate-700">Mã giao dịch / Mã tham chiếu ngân hàng:</label>
                                    <input
                                        type="text"
                                        value={payTransactionId}
                                        onChange={(e) => setPayTransactionId(e.target.value)}
                                        placeholder="Ví dụ: FT261088921 hoặc MB99210"
                                        className="w-full bg-white border border-slate-200 rounded-xl p-2.5 text-slate-800 font-mono placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                                    />
                                </div>
                            )}

                            {/* Ghi chú */}
                            <div className="space-y-1.5">
                                <label className="font-bold text-slate-700">Ghi chú thu tiền:</label>
                                <input
                                    type="text"
                                    value={payNotes}
                                    onChange={(e) => setPayNotes(e.target.value)}
                                    placeholder="Ghi chú thêm nếu có..."
                                    className="w-full bg-white border border-slate-200 rounded-xl p-2.5 text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
                                />
                            </div>

                            {/* Dự báo trạng thái sau khi gạch nợ */}
                            <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center justify-between text-xs">
                                <span className="text-slate-600">Trạng thái sau gạch nợ:</span>
                                <span className="font-bold text-emerald-700">
                                    {payAmount >= payModalInvoice.remaining_balance
                                        ? 'ĐÃ THANH TOÁN TOÀN BỘ (Dư nợ = 0 đ)'
                                        : `THANH TOÁN 1 PHẦN (Còn nợ: ${(payModalInvoice.remaining_balance - payAmount).toLocaleString('vi-VN')} đ)`}
                                </span>
                            </div>
                        </div>

                        {/* Footer Actions */}
                        <div className="flex items-center justify-end gap-2.5 p-4 bg-slate-50/70 border-t border-slate-100">
                            <button
                                type="button"
                                onClick={() => setPayModalInvoice(null)}
                                disabled={paying}
                                className="px-4 py-2 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 bg-white border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                            >
                                Hủy Bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirmPayment}
                                disabled={paying || payAmount <= 0}
                                className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-2xs transition-all flex items-center gap-1.5 cursor-pointer"
                            >
                                {paying && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                                <span>{paying ? 'Đang Gạch Nợ...' : 'Xác Nhận Thu Tiền & Gạch Nợ'}</span>
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 4: QUÉT MÃ VIETQR NHANH */}
            {vietQrModalData && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-md overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="p-5 border-b border-slate-100 bg-slate-50/70 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 rounded-xl bg-sky-50 text-sky-600 border border-sky-100">
                                    <QrCode className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-bold text-neutral-900">Mã QR Thanh Toán VietQR</h3>
                                    <p className="text-xs text-slate-500 font-mono">
                                        HĐ: {vietQrModalData.invoice_number} • Căn {vietQrModalData.apartment_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setVietQrModalData(null)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="p-6 text-center space-y-4">
                            {/* Ảnh QR VietQR */}
                            <div className="p-3 bg-white rounded-2xl inline-block shadow-md border border-slate-200 mx-auto">
                                <img
                                    src={vietQrModalData.qr_image_url}
                                    alt="VietQR Chuyển Khoản"
                                    className="w-56 h-56 object-contain rounded-lg"
                                />
                            </div>

                            <p className="text-xs text-slate-500">
                                Quét mã bằng ứng dụng ngân hàng bất kỳ để tự động điền số tiền và nội dung chuyển khoản.
                            </p>

                            {/* Chi tiết tài khoản nhận */}
                            <div className="bg-slate-50/70 p-4 rounded-xl border border-slate-200 space-y-2 text-xs text-left">
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-500">Ngân hàng:</span>
                                    <span className="font-bold text-neutral-900">MB Bank (BIN {vietQrModalData.bank_bin})</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-500">Số tài khoản:</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono font-bold text-sky-600">{vietQrModalData.bank_account_number}</span>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(vietQrModalData.bank_account_number, 'Số tài khoản')}
                                            className="text-slate-400 hover:text-sky-600 cursor-pointer"
                                            title="Sao chép STK"
                                        >
                                            <Copy className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-500">Chủ tài khoản:</span>
                                    <span className="font-semibold text-slate-800">{vietQrModalData.bank_account_name}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-500">Số tiền cần nộp:</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono font-bold text-emerald-600 text-xs">
                                            {Number(vietQrModalData.amount_due).toLocaleString('vi-VN')} đ
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(vietQrModalData.amount_due.toString(), 'Số tiền')}
                                            className="text-slate-400 hover:text-emerald-600 cursor-pointer"
                                            title="Sao chép số tiền"
                                        >
                                            <Copy className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-500">Nội dung chuyển khoản:</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono font-bold text-sky-700">{vietQrModalData.transfer_content}</span>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(vietQrModalData.transfer_content, 'Nội dung')}
                                            className="text-slate-400 hover:text-sky-600 cursor-pointer"
                                            title="Sao chép nội dung"
                                        >
                                            <Copy className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="p-4 bg-slate-50/70 border-t border-slate-100 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setVietQrModalData(null)}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                            >
                                Đóng
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 5: BIÊN LAI THU TIỀN ĐIỆN TỬ (PAYMENT RECEIPT) */}
            {receiptModalData && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Header */}
                        <div className="p-5 border-b border-slate-100 bg-slate-50/70 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-200">
                                    <CheckCircle2 className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-bold text-neutral-900">Biên Lai Thu Tiền Điện Tử</h3>
                                    <p className="text-xs text-slate-500 font-mono">
                                        Mã BL: {receiptModalData.receipt?.receipt_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReceiptModalData(null)}
                                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        {/* Phiếu biên lai in ấn */}
                        <div className="p-6 space-y-4 text-xs bg-white">
                            <div className="p-5 bg-slate-50/80 rounded-2xl border border-slate-200 space-y-3 font-mono shadow-2xs">
                                <div className="text-center pb-3 border-b border-slate-200">
                                    <h4 className="font-bold text-neutral-900 uppercase text-xs tracking-wider">
                                        BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART
                                    </h4>
                                    <p className="text-[11px] text-slate-500">BIÊN LAI THU TIỀN DỊCH VỤ TÒA NHÀ</p>
                                    <p className="text-[10px] text-slate-400">
                                        Số: <strong className="text-sky-700">{receiptModalData.receipt?.receipt_number}</strong> • Ngày lập: {receiptModalData.receipt?.receipt_date}
                                    </p>
                                </div>

                                <div className="space-y-1.5 text-xs text-slate-700 font-sans">
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">Người nộp tiền:</span>
                                        <strong className="text-neutral-900">{receiptModalData.receipt?.received_from_name}</strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">Hóa đơn gạch nợ:</span>
                                        <strong className="text-sky-700 font-mono">{receiptModalData.invoice?.invoice_number}</strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">Căn hộ:</span>
                                        <strong className="text-neutral-900">
                                            {receiptModalData.invoice?.apartment?.apartment_number} ({receiptModalData.invoice?.apartment?.block?.block_name})
                                        </strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">Phương thức:</span>
                                        <strong className="text-emerald-700">{receiptModalData.payment?.payment_gateway}</strong>
                                    </div>
                                    <div className="flex justify-between pt-2 border-t border-slate-200">
                                        <span className="text-slate-500">Số tiền đã nộp:</span>
                                        <strong className="text-sm text-emerald-700 font-mono">
                                            {Number(receiptModalData.receipt?.amount).toLocaleString('vi-VN')} đ
                                        </strong>
                                    </div>
                                    <div className="bg-white p-2.5 rounded-lg border border-slate-200/80">
                                        <span className="text-[11px] text-slate-400">Bằng chữ: </span>
                                        <em className="text-slate-700 font-serif font-semibold">{receiptModalData.receipt?.amount_in_words}</em>
                                    </div>
                                </div>

                                {/* Chữ ký số điện tử */}
                                <div className="pt-2 border-t border-slate-200 text-[10px] text-slate-500 space-y-1">
                                    <div className="flex items-center gap-1.5 text-emerald-700 font-semibold">
                                        <Sparkles className="w-3 h-3 text-emerald-600" />
                                        <span>Chứng chỉ xác thực số điện tử bảo chứng</span>
                                    </div>
                                    <p className="font-mono break-all text-slate-500 bg-white p-2 rounded border border-slate-200">
                                        SHA256: {receiptModalData.receipt?.digital_signature_hash}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Footer Actions */}
                        <div className="flex items-center justify-between p-4 bg-slate-50/70 border-t border-slate-100">
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 bg-white border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                            >
                                <Printer className="w-3.5 h-3.5" />
                                <span>In Biên Lai</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setReceiptModalData(null)}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-2xs transition-colors cursor-pointer"
                            >
                                Hoàn Tất
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default InvoiceManagement;
