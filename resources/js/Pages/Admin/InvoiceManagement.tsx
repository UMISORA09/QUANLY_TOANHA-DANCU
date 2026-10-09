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
    Check,
    History,
    Mail,
    BellRing,
    BarChart3
} from 'lucide-react';
import {
    invoiceApi,
    InvoiceModel,
    InvoiceSummaryModel
} from '../../Services/invoiceApi';
import { InvoiceBatchGeneration } from './InvoiceBatchGeneration';
import { PaymentHistoryManagement } from './PaymentHistoryManagement';
import { RevenueAnalyticsDashboard } from './RevenueAnalyticsDashboard';
import { FinancialReportExportModal } from './FinancialReportExportModal';

interface BlockOption {
    id: string;
    block_code: string;
    block_name: string;
}

export interface InvoiceManagementProps {
    onNavigateTab?: (tabKey: string) => void;
}

export const InvoiceManagement: React.FC<InvoiceManagementProps> = ({ onNavigateTab }) => {
    // Mode chuyển đổi giữa Quản lý danh sách, Lịch sử giao dịch, Dashboard Doanh thu và Sinh hóa đơn hàng loạt
    const [viewMode, setViewMode] = useState<'list' | 'batch_generate' | 'history' | 'analytics'>('list');

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

    // Nhắc nợ tự động & Queue email states (Chức năng 11)
    const [sendingReminderId, setSendingReminderId] = useState<string | null>(null);
    const [bulkSendingReminders, setBulkSendingReminders] = useState<boolean>(false);
    const [reminderLogsModalOpen, setReminderLogsModalOpen] = useState<boolean>(false);
    const [reminderLogs, setReminderLogs] = useState<any[]>([]);
    const [loadingReminderLogs, setLoadingReminderLogs] = useState<boolean>(false);

    // Xuất báo cáo tài chính & công nợ (Chức năng 13)
    const [reportModalOpen, setReportModalOpen] = useState<boolean>(false);

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
                console.error('Không thể tải danh sách khối:', err);
            }
        };
        fetchBlocks();
    }, []);

    // Tải dữ liệu hóa đơn và KPI summary
    const loadData = useCallback(async () => {
        setLoading(true);
        try {
            const filterParams = {
                status: statusFilter,
                billing_period: billingPeriodFilter || undefined,
                block_id: blockFilter || undefined,
                search: searchQuery || undefined,
                sort_by: sortBy,
                sort_order: sortOrder,
                page: pagination.current_page,
                per_page: pagination.per_page,
            };

            const [invRes, sumRes] = await Promise.all([
                invoiceApi.getInvoices(filterParams),
                invoiceApi.getSummary(filterParams),
            ]);

            if (invRes.success) {
                setInvoices(invRes.data.data);
                setPagination({
                    current_page: invRes.data.current_page,
                    last_page: invRes.data.last_page,
                    total: invRes.data.total,
                    per_page: pagination.per_page,
                });
            }

            if (sumRes.success) {
                setSummary(sumRes.data);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi tải danh sách hóa đơn');
        } finally {
            setLoading(false);
        }
    }, [statusFilter, billingPeriodFilter, blockFilter, searchQuery, sortBy, sortOrder, pagination.current_page]);

    useEffect(() => {
        if (viewMode === 'list') {
            loadData();
        }
    }, [loadData, viewMode]);

    // Xử lý hủy 1 hóa đơn
    const handleConfirmCancel = async () => {
        if (!cancelModalInvoice) return;
        setCancelling(true);
        try {
            const res = await invoiceApi.cancelInvoice(cancelModalInvoice.id, cancelReason);
            if (res.success) {
                showToast('success', res.message);
                setCancelModalInvoice(null);
                setCancelReason('');
                loadData();
            }
        } catch (err: any) {
            showToast('error', err.message || 'Không thể hủy hóa đơn');
        } finally {
            setCancelling(false);
        }
    };

    // Xử lý hủy hàng loạt
    const handleBulkCancel = async () => {
        if (selectedInvoiceIds.length === 0) return;
        if (!confirm(`Bạn có chắc chắn muốn hủy ${selectedInvoiceIds.length} hóa đơn đã chọn?`)) return;

        try {
            const res = await invoiceApi.bulkCancelInvoices(selectedInvoiceIds, 'Hủy hàng loạt bởi Quản trị viên');
            if (res.success) {
                showToast('success', res.message);
                setSelectedInvoiceIds([]);
                loadData();
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi hủy hàng loạt');
        }
    };

    // Chọn tất cả / Bỏ chọn
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            const cancellable = invoices
                .filter(i => i.status !== 'PAID' && i.status !== 'CANCELLED' && i.paid_amount === 0)
                .map(i => i.id);
            setSelectedInvoiceIds(cancellable);
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
                loadData();
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

    // Gửi email nhắc nợ đơn lẻ (Chức năng 11)
    const handleSendReminder = async (inv: InvoiceModel) => {
        try {
            setSendingReminderId(inv.id);
            const res = await invoiceApi.sendDebtReminder(inv.id);
            if (res.success) {
                showToast('success', res.message || 'Đã xếp hàng gửi email nhắc nợ thành công');
            }
        } catch (err: any) {
            showToast('error', err.message || 'Không thể gửi email nhắc nợ');
        } finally {
            setSendingReminderId(null);
        }
    };

    // Gửi email nhắc nợ hàng loạt (Chức năng 11)
    const handleBulkSendReminders = async () => {
        if (selectedInvoiceIds.length === 0) return;
        if (!confirm(`Bạn có chắc chắn muốn gửi email nhắc nợ cho ${selectedInvoiceIds.length} hóa đơn đã chọn?`)) return;

        try {
            setBulkSendingReminders(true);
            const res = await invoiceApi.bulkSendDebtReminders(selectedInvoiceIds);
            if (res.success) {
                showToast('success', res.message || 'Đã xếp hàng gửi email nhắc nợ hàng loạt thành công');
                setSelectedInvoiceIds([]);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi gửi email nhắc nợ hàng loạt');
        } finally {
            setBulkSendingReminders(false);
        }
    };

    // Xem lịch sử gửi email nhắc nợ (Chức năng 11)
    const handleOpenReminderLogs = async () => {
        setReminderLogsModalOpen(true);
        setLoadingReminderLogs(true);
        try {
            const res = await invoiceApi.getDebtReminderLogs({ per_page: 20 });
            if (res.success) {
                setReminderLogs(res.data.data || []);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Không thể tải lịch sử gửi email');
        } finally {
            setLoadingReminderLogs(false);
        }
    };

    // Helper render Pill trạng thái
    const renderStatusBadge = (status: string, dueDate: string) => {
        const isOverdue = status === 'OVERDUE' || (['ISSUED', 'PARTIAL'].includes(status) && new Date(dueDate) < new Date());

        if (status === 'PAID') {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                    <CheckCircle2 className="w-3 h-3 text-emerald-400" />
                    <span>Đã Thanh Toán</span>
                </span>
            );
        }

        if (status === 'CANCELLED') {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-800 text-slate-400 border border-slate-700">
                    <XCircle className="w-3 h-3 text-slate-500" />
                    <span>Đã Hủy</span>
                </span>
            );
        }

        if (isOverdue) {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-500/15 text-rose-300 border border-rose-500/30">
                    <AlertTriangle className="w-3 h-3 text-rose-400" />
                    <span>Quá Hạn</span>
                </span>
            );
        }

        if (status === 'PARTIAL') {
            return (
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/15 text-amber-300 border border-amber-500/30">
                    <Clock className="w-3 h-3 text-amber-400" />
                    <span>Thanh Toán 1 Phần</span>
                </span>
            );
        }

        return (
            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-500/15 text-indigo-300 border border-indigo-500/30">
                <Clock className="w-3 h-3 text-indigo-400" />
                <span>Chờ Thanh Toán</span>
            </span>
        );
    };

    if (viewMode === 'batch_generate') {
        return (
            <div className="space-y-4 min-h-screen bg-slate-950 text-slate-100 p-4 md:p-8">
                <div className="p-4 bg-slate-900/60 border border-slate-800 rounded-2xl flex items-center justify-between">
                    <button
                        type="button"
                        onClick={() => setViewMode('list')}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition-colors"
                    >
                        <ChevronLeft className="w-4 h-4" />
                        <span>Quay Lại Danh Sách Hóa Đơn</span>
                    </button>
                    <span className="text-xs text-indigo-400 font-semibold font-mono">
                        CHẾ ĐỘ: SINH HÓA ĐƠN HÀNG LOẠT THEO THÁNG
                    </span>
                </div>
                <InvoiceBatchGeneration />
            </div>
        );
    }

    if (viewMode === 'history') {
        return (
            <div className="space-y-4 min-h-screen bg-slate-950 text-slate-100 p-4 md:p-8">
                <div className="p-4 bg-slate-900/60 border border-slate-800 rounded-2xl flex items-center justify-between">
                    <button
                        type="button"
                        onClick={() => setViewMode('list')}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition-colors"
                    >
                        <ChevronLeft className="w-4 h-4" />
                        <span>Quay Lại Danh Sách Hóa Đơn</span>
                    </button>
                    <div className="flex items-center gap-2">
                        <span className="text-xs text-emerald-400 font-semibold font-mono">
                            CHỨC NĂNG: LỊCH SỬ GIAO DỊCH CÁC KỲ TRƯỚC (CHỨC NĂNG 10)
                        </span>
                    </div>
                </div>
                <PaymentHistoryManagement />
            </div>
        );
    }

    if (viewMode === 'analytics') {
        return (
            <div className="space-y-4 min-h-screen bg-slate-950 text-slate-100 p-4 md:p-8">
                <RevenueAnalyticsDashboard onBackToList={() => setViewMode('list')} />
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 p-4 md:p-8 space-y-8">
            {/* TOAST THÔNG BÁO */}
            {toast && (
                <div
                    className={`fixed top-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 rounded-2xl shadow-2xl border text-sm font-semibold animate-in fade-in slide-in-from-top-4 duration-300 ${
                        toast.type === 'success'
                            ? 'bg-emerald-950/90 border-emerald-500/30 text-emerald-300'
                            : 'bg-rose-950/90 border-rose-500/30 text-rose-300'
                    }`}
                >
                    {toast.type === 'success' ? (
                        <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
                    ) : (
                        <AlertTriangle className="w-5 h-5 text-rose-400 shrink-0" />
                    )}
                    <span>{toast.text}</span>
                </div>
            )}

            {/* HEADER TIÊU ĐỀ & NÚT HÀNH ĐỘNG */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-6 border-b border-slate-800/80">
                <div className="space-y-1">
                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                        <Receipt className="w-3.5 h-3.5" />
                        <span>QUẢN TRỊ TÀI CHÍNH & CÔNG NỢ</span>
                    </div>
                    <h1 className="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
                        Quản Lý & Lọc Trạng Thái Hóa Đơn
                    </h1>
                    <p className="text-sm text-slate-400">
                        Theo dõi công nợ, phân loại trạng thái thanh toán, lọc theo kỳ và quản lý chi tiết hóa đơn dịch vụ cư dân.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => onNavigateTab ? onNavigateTab('financial_reports') : setReportModalOpen(true)}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-teal-300 border border-teal-500/30 text-sm font-bold shadow-lg transition-all"
                        title="Xem trang báo cáo tài chính và công nợ ra Excel và PDF (Chức năng 13)"
                    >
                        <Download className="w-4 h-4 text-teal-400" />
                        <span>Xuất Báo Cáo</span>
                    </button>

                    <button
                        type="button"
                        onClick={handleOpenReminderLogs}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-amber-300 border border-amber-500/30 text-sm font-bold shadow-lg transition-all"
                        title="Xem nhật ký hàng đợi gửi email nhắc nợ tự động"
                    >
                        <Mail className="w-4 h-4 text-amber-400" />
                        <span>Nhật Ký Nhắc Nợ</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => onNavigateTab ? onNavigateTab('revenue_analytics') : setViewMode('analytics')}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-sky-300 border border-sky-500/30 text-sm font-bold shadow-lg transition-all"
                        title="Xem biểu đồ phân tích doanh thu và thu hồi công nợ (Chức năng 12)"
                    >
                        <BarChart3 className="w-4 h-4 text-sky-400" />
                        <span>Dashboard Doanh Thu</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => onNavigateTab ? onNavigateTab('payment_history') : setViewMode('history')}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-emerald-300 border border-emerald-500/30 text-sm font-bold shadow-lg transition-all"
                    >
                        <History className="w-4 h-4 text-emerald-400" />
                        <span>Lịch Sử Giao Dịch</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => onNavigateTab ? onNavigateTab('invoice_generation') : setViewMode('batch_generate')}
                        className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 transition-all"
                    >
                        <Plus className="w-4 h-4" />
                        <span>Sinh Hóa Đơn Hàng Loạt</span>
                    </button>
                </div>
            </div>

            {/* KPI METRIC CARDS TỔNG HỢP */}
            {summary && (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                    {/* Thẻ 1: Tổng số hóa đơn */}
                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>TỔNG HÓA ĐƠN</span>
                            <FileText className="w-4 h-4 text-indigo-400" />
                        </div>
                        <div className="text-2xl font-mono font-extrabold text-white mt-2">
                            {summary.total_invoices} HĐ
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1">
                            Toàn bộ trong phạm vi lọc
                        </div>
                    </div>

                    {/* Thẻ 2: Tổng số tiền phát hành */}
                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>TỔNG DOANH THU</span>
                            <CreditCard className="w-4 h-4 text-sky-400" />
                        </div>
                        <div className="text-2xl font-mono font-extrabold text-sky-400 mt-2">
                            {summary.total_amount.toLocaleString('vi-VN')} đ
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1">
                            Tổng giá trị phải thu
                        </div>
                    </div>

                    {/* Thẻ 3: Đã thu thành công */}
                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>ĐÃ THU THÀNH CÔNG</span>
                            <CheckCircle2 className="w-4 h-4 text-emerald-400" />
                        </div>
                        <div className="text-2xl font-mono font-extrabold text-emerald-400 mt-2">
                            {summary.paid_amount.toLocaleString('vi-VN')} đ
                        </div>
                        <div className="text-[11px] text-emerald-400/80 mt-1">
                            Tỷ lệ: {summary.collection_rate}% chỉ tiêu
                        </div>
                    </div>

                    {/* Thẻ 4: Còn nợ đọng */}
                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>DƯ NỢ CÒN LẠI</span>
                            <Clock className="w-4 h-4 text-amber-400" />
                        </div>
                        <div className="text-2xl font-mono font-extrabold text-amber-400 mt-2">
                            {summary.remaining_balance.toLocaleString('vi-VN')} đ
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1">
                            Chờ cư dân nộp tiền
                        </div>
                    </div>

                    {/* Thẻ 5: Hóa đơn quá hạn */}
                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>QUÁ HẠN NỘP TIỀN</span>
                            <AlertTriangle className="w-4 h-4 text-rose-400" />
                        </div>
                        <div className="text-2xl font-mono font-extrabold text-rose-400 mt-2">
                            {summary.counts_by_status.OVERDUE} HĐ
                        </div>
                        <div className="text-[11px] text-rose-300/80 mt-1">
                            Cần gửi email nhắc nợ
                        </div>
                    </div>
                </div>
            )}

            {/* THANH LỌC TRẠNG THÁI (STATUS TABS) */}
            <div className="flex flex-wrap items-center gap-1.5 p-1.5 bg-slate-900/80 border border-slate-800 rounded-2xl backdrop-blur-md">
                {[
                    { key: 'ALL', label: 'Tất Cả', count: summary?.total_invoices },
                    { key: 'ISSUED', label: 'Chờ Thanh Toán', count: summary?.counts_by_status.ISSUED },
                    { key: 'PARTIAL', label: 'Thanh Toán 1 Phần', count: summary?.counts_by_status.PARTIAL },
                    { key: 'PAID', label: 'Đã Thu Đủ', count: summary?.counts_by_status.PAID },
                    { key: 'OVERDUE', label: 'Quá Hạn', count: summary?.counts_by_status.OVERDUE, badgeColor: 'text-rose-400' },
                    { key: 'CANCELLED', label: 'Đã Hủy', count: summary?.counts_by_status.CANCELLED },
                ].map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => {
                            setStatusFilter(tab.key);
                            setPagination(p => ({ ...p, current_page: 1 }));
                        }}
                        className={`px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 ${
                            statusFilter === tab.key
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                : 'text-slate-400 hover:text-white hover:bg-slate-800/60'
                        }`}
                    >
                        <span>{tab.label}</span>
                        {tab.count !== undefined && (
                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-mono font-bold ${
                                statusFilter === tab.key
                                    ? 'bg-white/20 text-white'
                                    : 'bg-slate-800 text-slate-300'
                            }`}>
                                {tab.count}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {/* BỘ LỌC ĐA CHIỀU & TÌM KIẾM */}
            <div className="bg-slate-900/80 border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4 backdrop-blur-md">
                <div className="flex flex-wrap items-center gap-3 flex-1">
                    {/* Ô tìm kiếm */}
                    <div className="relative flex-1 min-w-[220px]">
                        <Search className="w-4 h-4 text-slate-500 absolute left-3.5 top-3" />
                        <input
                            type="text"
                            placeholder="Tìm số HĐ, căn hộ, tên cư dân, SĐT..."
                            value={searchQuery}
                            onChange={(e) => {
                                setSearchQuery(e.target.value);
                                setPagination(p => ({ ...p, current_page: 1 }));
                            }}
                            className="w-full bg-slate-950 border border-slate-800 rounded-xl pl-10 pr-4 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500"
                        />
                    </div>

                    {/* Lọc Kỳ Hóa Đơn */}
                    <div className="w-44">
                        <input
                            type="month"
                            value={billingPeriodFilter}
                            onChange={(e) => {
                                setBillingPeriodFilter(e.target.value);
                                setPagination(p => ({ ...p, current_page: 1 }));
                            }}
                            title="Lọc theo kỳ tháng/năm"
                            className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500 font-mono"
                        />
                    </div>

                    {/* Lọc Khối Tòa Nhà */}
                    <div className="w-48">
                        <select
                            value={blockFilter}
                            onChange={(e) => {
                                setBlockFilter(e.target.value);
                                setPagination(p => ({ ...p, current_page: 1 }));
                            }}
                            className="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500"
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
                            className="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-300 transition-colors"
                        >
                            Xóa Lọc
                        </button>
                    )}
                </div>

                {/* Thao tác hàng loạt (Bulk Cancel & Bulk Reminder) */}
                {selectedInvoiceIds.length > 0 && (
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={handleBulkSendReminders}
                            disabled={bulkSendingReminders}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 disabled:opacity-50 text-white text-xs font-bold shadow-md shadow-amber-600/30 transition-all"
                            title="Xếp hàng gửi email nhắc nợ kèm mã QR cho các hóa đơn đã chọn"
                        >
                            {bulkSendingReminders ? (
                                <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                            ) : (
                                <BellRing className="w-3.5 h-3.5" />
                            )}
                            <span>Nhắc Nợ {selectedInvoiceIds.length} HĐ Đã Chọn</span>
                        </button>

                        <button
                            type="button"
                            onClick={handleBulkCancel}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold shadow-md shadow-rose-600/30 transition-all"
                        >
                            <Trash2 className="w-3.5 h-3.5" />
                            <span>Hủy {selectedInvoiceIds.length} HĐ Đã Chọn</span>
                        </button>
                    </div>
                )}
            </div>

            {/* BẢNG DỮ LIỆU CHÍNH HÓA ĐƠN */}
            <div className="bg-slate-900/90 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden backdrop-blur-md">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-slate-300">
                        <thead className="bg-slate-950/80 border-b border-slate-800 text-xs font-semibold uppercase text-slate-400">
                            <tr>
                                <th className="py-3.5 px-4 w-10 text-center">
                                    <input
                                        type="checkbox"
                                        checked={
                                            invoices.length > 0 &&
                                            selectedInvoiceIds.length === invoices.filter(i => i.status !== 'PAID' && i.status !== 'CANCELLED' && i.paid_amount === 0).length
                                        }
                                        onChange={(e) => handleSelectAll(e.target.checked)}
                                        className="rounded bg-slate-900 border-slate-700 text-indigo-600 w-4 h-4 cursor-pointer"
                                    />
                                </th>
                                <th className="py-3.5 px-4">Số Hóa Đơn</th>
                                <th className="py-3.5 px-4">Căn Hộ</th>
                                <th className="py-3.5 px-4">Cư Dân Nhận HĐ</th>
                                <th className="py-3.5 px-4 text-center">Kỳ Phí</th>
                                <th className="py-3.5 px-4">Hạn Nộp</th>
                                <th className="py-3.5 px-4 text-right">Tổng Tiền</th>
                                <th className="py-3.5 px-4 text-right">Đã Trả</th>
                                <th className="py-3.5 px-4 text-right">Còn Nợ</th>
                                <th className="py-3.5 px-4 text-center">Trạng Thái</th>
                                <th className="py-3.5 px-4 text-right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800/60 text-xs">
                            {loading ? (
                                <tr>
                                    <td colSpan={11} className="py-16 text-center text-slate-400">
                                        <RefreshCw className="w-8 h-8 animate-spin mx-auto text-indigo-500 mb-2" />
                                        <span>Đang tải danh sách hóa đơn...</span>
                                    </td>
                                </tr>
                            ) : invoices.length === 0 ? (
                                <tr>
                                    <td colSpan={11} className="py-16 text-center text-slate-500">
                                        Không tìm thấy hóa đơn nào phù hợp với bộ lọc hiện tại.
                                    </td>
                                </tr>
                            ) : (
                                invoices.map((inv) => {
                                    const canCancel = inv.status !== 'PAID' && inv.status !== 'CANCELLED' && inv.paid_amount === 0;
                                    const isSelected = selectedInvoiceIds.includes(inv.id);

                                    return (
                                        <tr key={inv.id} className={`hover:bg-slate-800/40 transition-colors ${isSelected ? 'bg-indigo-950/20' : ''}`}>
                                            {/* Checkbox */}
                                            <td className="py-3 px-4 text-center">
                                                {canCancel ? (
                                                    <input
                                                        type="checkbox"
                                                        checked={isSelected}
                                                        onChange={(e) => handleSelectOne(inv.id, e.target.checked)}
                                                        className="rounded bg-slate-900 border-slate-700 text-indigo-600 w-4 h-4 cursor-pointer"
                                                    />
                                                ) : (
                                                    <span className="text-slate-700">-</span>
                                                )}
                                            </td>

                                            {/* Mã hóa đơn */}
                                            <td className="py-3 px-4 font-mono font-bold text-indigo-300">
                                                {inv.invoice_number}
                                            </td>

                                            {/* Căn hộ */}
                                            <td className="py-3 px-4">
                                                <div className="font-bold text-white flex items-center gap-1.5">
                                                    <Building2 className="w-3.5 h-3.5 text-indigo-400" />
                                                    <span>{inv.apartment?.apartment_number || 'N/A'}</span>
                                                </div>
                                                <div className="text-[11px] text-slate-500">
                                                    {inv.apartment?.block?.block_name || 'Khối N/A'}
                                                </div>
                                            </td>

                                            {/* Cư dân */}
                                            <td className="py-3 px-4">
                                                <div className="font-semibold text-slate-200">
                                                    {inv.resident_user?.full_name || 'Đại diện Căn Hộ'}
                                                </div>
                                                <div className="text-[11px] text-slate-500 font-mono">
                                                    {inv.resident_user?.phone_number || ''}
                                                </div>
                                            </td>

                                            {/* Kỳ phí */}
                                            <td className="py-3 px-4 text-center font-mono font-bold text-slate-300">
                                                {inv.billing_period}
                                            </td>

                                            {/* Hạn nộp */}
                                            <td className="py-3 px-4 font-mono text-slate-400">
                                                {inv.due_date}
                                            </td>

                                            {/* Tổng tiền */}
                                            <td className="py-3 px-4 text-right font-mono font-bold text-white">
                                                {inv.total_amount.toLocaleString('vi-VN')} đ
                                            </td>

                                            {/* Đã trả */}
                                            <td className="py-3 px-4 text-right font-mono text-emerald-400 font-semibold">
                                                {inv.paid_amount.toLocaleString('vi-VN')} đ
                                            </td>

                                            {/* Còn nợ */}
                                            <td className="py-3 px-4 text-right font-mono font-bold text-amber-400">
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
                                                            className="p-1.5 rounded-lg bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 border border-cyan-500/20 transition-colors"
                                                            title="Tạo mã VietQR thanh toán nhanh"
                                                        >
                                                            <QrCode className="w-4 h-4" />
                                                        </button>
                                                    )}

                                                    {/* Nút Gửi Email Nhắc Nợ */}
                                                    {inv.status !== 'CANCELLED' && inv.status !== 'PAID' && inv.remaining_balance > 0 && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleSendReminder(inv)}
                                                            disabled={sendingReminderId === inv.id}
                                                            className="p-1.5 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 disabled:opacity-50 text-amber-300 border border-amber-500/20 transition-colors"
                                                            title="Xếp hàng gửi email nhắc nợ tự động"
                                                        >
                                                            {sendingReminderId === inv.id ? (
                                                                <RefreshCw className="w-4 h-4 animate-spin text-amber-400" />
                                                            ) : (
                                                                <BellRing className="w-4 h-4" />
                                                            )}
                                                        </button>
                                                    )}

                                                    {/* Nút Thu tiền & Gạch nợ */}
                                                    {inv.status !== 'CANCELLED' && inv.status !== 'PAID' && inv.remaining_balance > 0 && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleOpenPayModal(inv)}
                                                            className="p-1.5 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 border border-emerald-500/20 transition-colors"
                                                            title="Thu tiền & Gạch nợ tức thời"
                                                        >
                                                            <CreditCard className="w-4 h-4" />
                                                        </button>
                                                    )}

                                                    {/* Nút Xem chi tiết */}
                                                    <button
                                                        type="button"
                                                        onClick={() => setDetailInvoice(inv)}
                                                        className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-indigo-300 hover:text-white transition-colors"
                                                        title="Xem chi tiết hóa đơn"
                                                    >
                                                        <Eye className="w-4 h-4" />
                                                    </button>

                                                    {/* Nút Hủy hóa đơn */}
                                                    {canCancel && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setCancelModalInvoice(inv)}
                                                            className="p-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/20 transition-colors"
                                                            title="Hủy hóa đơn"
                                                        >
                                                            <XCircle className="w-4 h-4" />
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
                    <div className="flex items-center justify-between p-4 bg-slate-950/70 border-t border-slate-800 text-xs text-slate-400">
                        <div>
                            Hiển thị trang <strong>{pagination.current_page}</strong> / <strong>{pagination.last_page}</strong> ({pagination.total} hóa đơn)
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setPagination(p => ({ ...p, current_page: Math.max(1, p.current_page - 1) }))}
                                disabled={pagination.current_page <= 1}
                                className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed text-slate-200 transition-colors flex items-center gap-1"
                            >
                                <ChevronLeft className="w-3.5 h-3.5" />
                                <span>Trang trước</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setPagination(p => ({ ...p, current_page: Math.min(pagination.last_page, p.current_page + 1) }))}
                                disabled={pagination.current_page >= pagination.last_page}
                                className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed text-slate-200 transition-colors flex items-center gap-1"
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
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200 max-h-[92vh] flex flex-col">
                        <div className="p-5 border-b border-slate-800 bg-slate-950/70 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 rounded-xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                                    <Receipt className="w-6 h-6" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-white flex items-center gap-2">
                                        <span>Chi Tiết Hóa Đơn {detailInvoice.invoice_number}</span>
                                        {renderStatusBadge(detailInvoice.status, detailInvoice.due_date)}
                                    </h3>
                                    <p className="text-xs text-slate-400">
                                        Kỳ tính phí: <strong className="text-indigo-400 font-mono">{detailInvoice.billing_period}</strong> • Căn {detailInvoice.apartment?.apartment_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setDetailInvoice(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-white bg-slate-800"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="p-6 overflow-y-auto space-y-5">
                            {/* Thông tin căn hộ & cư dân */}
                            <div className="grid grid-cols-2 gap-4 bg-slate-950/60 p-4 rounded-xl border border-slate-800 text-xs">
                                <div>
                                    <span className="text-slate-500">Căn hộ:</span>
                                    <div className="font-bold text-white text-sm mt-0.5">
                                        Căn {detailInvoice.apartment?.apartment_number} ({detailInvoice.apartment?.block?.block_name})
                                    </div>
                                    <div className="text-slate-400 mt-1">
                                        Diện tích: {detailInvoice.apartment?.net_usable_area_sqm || 70} m²
                                    </div>
                                </div>
                                <div>
                                    <span className="text-slate-500">Cư dân nhận hóa đơn:</span>
                                    <div className="font-bold text-white text-sm mt-0.5">
                                        {detailInvoice.resident_user?.full_name || 'Đại diện Căn Hộ'}
                                    </div>
                                    <div className="text-slate-400 mt-1 font-mono">
                                        SĐT: {detailInvoice.resident_user?.phone_number || 'N/A'}
                                    </div>
                                </div>
                            </div>

                            {/* Danh sách các khoản phí dịch vụ */}
                            <div className="space-y-2">
                                <h4 className="text-xs font-bold text-white uppercase tracking-wider">
                                    Khoản Mục Chi Phí Dịch Vụ
                                </h4>

                                {detailInvoice.items && detailInvoice.items.length > 0 ? (
                                    <div className="space-y-2.5">
                                        {detailInvoice.items.map((item) => (
                                            <div
                                                key={item.id}
                                                className="bg-slate-950/70 border border-slate-800 rounded-xl p-3.5 space-y-2 text-xs"
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
                                                    <div>Tiền trước thuế: <strong className="text-white font-mono">{item.amount_before_tax.toLocaleString('vi-VN')} đ</strong></div>
                                                    <div>Thuế VAT & BVMT: <strong className="text-white font-mono">{(item.vat_amount + item.environmental_fee_amount).toLocaleString('vi-VN')} đ</strong></div>
                                                </div>

                                                {/* Bậc thang lũy tiến nếu có */}
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
                                ) : (
                                    <div className="p-4 text-center text-slate-500 bg-slate-950/40 rounded-xl">
                                        Chưa có chi tiết dòng phí dịch vụ.
                                    </div>
                                )}
                            </div>

                            {/* Lịch sử thanh toán & gạch nợ (nếu có) */}
                            {((detailInvoice as any).payments?.length || 0) > 0 && (
                                <div className="space-y-2">
                                    <h4 className="text-xs font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-1.5">
                                        <CheckCircle2 className="w-4 h-4" />
                                        <span>Lịch Sử Các Đợt Thanh Toán & Biên Lai ({(detailInvoice as any).payments.length})</span>
                                    </h4>
                                    <div className="space-y-2">
                                        {(detailInvoice as any).payments.map((p: any) => (
                                            <div key={p.id} className="p-3 bg-slate-950 border border-slate-800 rounded-xl flex items-center justify-between text-xs font-mono">
                                                <div>
                                                    <span className="font-bold text-white">{p.payment_reference_code}</span>
                                                    <span className="text-slate-500 mx-2">•</span>
                                                    <span className="text-emerald-400">{p.payment_gateway}</span>
                                                    {p.receipt && (
                                                        <span className="ml-2 text-indigo-300">({p.receipt.receipt_number})</span>
                                                    )}
                                                </div>
                                                <div className="text-right">
                                                    <span className="font-bold text-emerald-400">+{Number(p.amount_paid).toLocaleString('vi-VN')} đ</span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Tổng kết tiền */}
                            <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2 text-xs">
                                <div className="flex justify-between text-slate-400">
                                    <span>Tổng trước thuế:</span>
                                    <span className="font-mono text-white">{detailInvoice.subtotal_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                <div className="flex justify-between text-slate-400">
                                    <span>Tổng thuế VAT & Phí bảo vệ môi trường:</span>
                                    <span className="font-mono text-white">{detailInvoice.tax_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                {detailInvoice.previous_debt_amount > 0 && (
                                    <div className="flex justify-between text-amber-400 font-semibold">
                                        <span>Công nợ tồn từ kỳ trước:</span>
                                        <span className="font-mono">+{detailInvoice.previous_debt_amount.toLocaleString('vi-VN')} đ</span>
                                    </div>
                                )}
                                <div className="flex justify-between text-sm font-extrabold text-white pt-2 border-t border-slate-800">
                                    <span>TỔNG CỘNG PHẢI THU:</span>
                                    <span className="font-mono text-base text-emerald-400">{detailInvoice.total_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                <div className="flex justify-between text-xs text-emerald-400 font-semibold">
                                    <span>Đã thanh toán:</span>
                                    <span className="font-mono">{detailInvoice.paid_amount.toLocaleString('vi-VN')} đ</span>
                                </div>
                                <div className="flex justify-between text-xs text-amber-400 font-bold">
                                    <span>Dư nợ còn lại:</span>
                                    <span className="font-mono">{detailInvoice.remaining_balance.toLocaleString('vi-VN')} đ</span>
                                </div>
                            </div>
                        </div>

                        <div className="p-4 bg-slate-950/70 border-t border-slate-800 flex items-center justify-between">
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition-colors"
                            >
                                <Printer className="w-4 h-4" />
                                <span>In Bản Sao Kê</span>
                            </button>

                            <div className="flex items-center gap-2">
                                {detailInvoice.status !== 'PAID' && detailInvoice.status !== 'CANCELLED' && (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                const inv = detailInvoice;
                                                setDetailInvoice(null);
                                                handleOpenVietQr(inv);
                                            }}
                                            className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-cyan-300 bg-cyan-500/10 hover:bg-cyan-500/20 border border-cyan-500/30 transition-all"
                                        >
                                            <QrCode className="w-4 h-4" />
                                            <span>Mã VietQR</span>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                const inv = detailInvoice;
                                                setDetailInvoice(null);
                                                handleOpenPayModal(inv);
                                            }}
                                            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-600/30 transition-all"
                                        >
                                            <CreditCard className="w-4 h-4" />
                                            <span>Thu Tiền Ngay</span>
                                        </button>
                                    </>
                                )}
                                <button
                                    type="button"
                                    onClick={() => setDetailInvoice(null)}
                                    className="px-5 py-2 rounded-xl text-xs font-semibold text-white bg-slate-800 hover:bg-slate-700 transition-colors"
                                >
                                    Đóng
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 2: XÁC NHẬN HỦY HÓA ĐƠN */}
            {cancelModalInvoice && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-md overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="p-6 space-y-4">
                            <div className="flex items-center gap-3">
                                <div className="p-3 rounded-xl bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                    <XCircle className="w-6 h-6" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-white">Xác Nhận Hủy Hóa Đơn</h3>
                                    <p className="text-xs text-slate-400 font-mono">{cancelModalInvoice.invoice_number}</p>
                                </div>
                            </div>

                            <p className="text-xs text-slate-300">
                                Bạn có chắc chắn muốn hủy hóa đơn cho căn hộ <strong className="text-white">{cancelModalInvoice.apartment?.apartment_number}</strong>?
                                Hệ thống sẽ mở khóa các chỉ số điện/nước liên quan để có thể tính toán lại.
                            </p>

                            <div className="space-y-1.5">
                                <label className="text-xs font-semibold text-slate-300">Lý do hủy hóa đơn:</label>
                                <textarea
                                    rows={3}
                                    placeholder="Nhập lý do hủy hóa đơn..."
                                    value={cancelReason}
                                    onChange={(e) => setCancelReason(e.target.value)}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-rose-500 resize-none"
                                />
                            </div>
                        </div>

                        <div className="flex items-center justify-end gap-3 p-4 bg-slate-950/70 border-t border-slate-800">
                            <button
                                type="button"
                                onClick={() => setCancelModalInvoice(null)}
                                disabled={cancelling}
                                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 transition-colors"
                            >
                                Hủy Bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirmCancel}
                                disabled={cancelling}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-rose-600 hover:bg-rose-500 shadow-md shadow-rose-600/30 transition-all flex items-center gap-2"
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
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Header Modal */}
                        <div className="p-5 bg-gradient-to-r from-emerald-950/40 to-slate-900 border-b border-slate-800 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    <CreditCard className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-white">Thu Tiền & Gạch Nợ Tức Thời</h3>
                                    <p className="text-xs text-slate-400 font-mono">
                                        {payModalInvoice.invoice_number} • Căn {payModalInvoice.apartment?.apartment_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPayModalInvoice(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Body Form */}
                        <div className="p-6 space-y-4 text-xs">
                            {/* Tóm tắt dư nợ */}
                            <div className="grid grid-cols-3 gap-2 p-3 bg-slate-950 rounded-xl border border-slate-800">
                                <div>
                                    <span className="text-slate-400">Tổng hóa đơn:</span>
                                    <p className="font-mono font-bold text-white text-sm">
                                        {payModalInvoice.total_amount.toLocaleString('vi-VN')} đ
                                    </p>
                                </div>
                                <div>
                                    <span className="text-slate-400">Đã thanh toán:</span>
                                    <p className="font-mono font-semibold text-emerald-400 text-sm">
                                        {payModalInvoice.paid_amount.toLocaleString('vi-VN')} đ
                                    </p>
                                </div>
                                <div>
                                    <span className="text-slate-400">Dư nợ còn lại:</span>
                                    <p className="font-mono font-bold text-amber-400 text-sm">
                                        {payModalInvoice.remaining_balance.toLocaleString('vi-VN')} đ
                                    </p>
                                </div>
                            </div>

                            {/* Số tiền thu */}
                            <div className="space-y-1.5">
                                <div className="flex items-center justify-between">
                                    <label className="font-semibold text-slate-300">Số tiền thu thực tế (VNĐ):</label>
                                    <button
                                        type="button"
                                        onClick={() => setPayAmount(payModalInvoice.remaining_balance)}
                                        className="text-[11px] text-emerald-400 hover:underline font-semibold"
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
                                        className="w-full bg-slate-950 border border-slate-700 rounded-xl py-2.5 px-3 font-mono font-bold text-base text-emerald-400 focus:outline-none focus:border-emerald-500"
                                        placeholder="Nhập số tiền thu..."
                                    />
                                    <span className="absolute right-3 top-3 text-slate-500 font-semibold">VND</span>
                                </div>
                            </div>

                            {/* Phương thức thanh toán */}
                            <div className="space-y-1.5">
                                <label className="font-semibold text-slate-300">Phương thức thanh toán:</label>
                                <select
                                    value={payMethod}
                                    onChange={(e) => setPayMethod(e.target.value)}
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white font-medium focus:outline-none focus:border-indigo-500"
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
                                    <label className="font-semibold text-slate-300">Mã giao dịch / Mã tham chiếu ngân hàng:</label>
                                    <input
                                        type="text"
                                        value={payTransactionId}
                                        onChange={(e) => setPayTransactionId(e.target.value)}
                                        placeholder="Ví dụ: FT261088921 hoặc MB99210"
                                        className="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white font-mono placeholder-slate-600 focus:outline-none focus:border-indigo-500"
                                    />
                                </div>
                            )}

                            {/* Ghi chú */}
                            <div className="space-y-1.5">
                                <label className="font-semibold text-slate-300">Ghi chú thu tiền:</label>
                                <input
                                    type="text"
                                    value={payNotes}
                                    onChange={(e) => setPayNotes(e.target.value)}
                                    placeholder="Ghi chú thêm nếu có..."
                                    className="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-white placeholder-slate-600 focus:outline-none focus:border-indigo-500"
                                />
                            </div>

                            {/* Dự báo trạng thái sau khi gạch nợ */}
                            <div className="p-3 bg-emerald-950/20 border border-emerald-500/20 rounded-xl flex items-center justify-between text-xs">
                                <span className="text-slate-300">Trạng thái sau gạch nợ:</span>
                                <span className="font-bold text-emerald-400">
                                    {payAmount >= payModalInvoice.remaining_balance
                                        ? 'ĐÃ THANH TOÁN TOÀN BỘ (Dư nợ = 0 đ)'
                                        : `THANH TOÁN 1 PHẦN (Còn nợ: ${(payModalInvoice.remaining_balance - payAmount).toLocaleString('vi-VN')} đ)`}
                                </span>
                            </div>
                        </div>

                        {/* Footer Actions */}
                        <div className="flex items-center justify-end gap-3 p-4 bg-slate-950/70 border-t border-slate-800">
                            <button
                                type="button"
                                onClick={() => setPayModalInvoice(null)}
                                disabled={paying}
                                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 transition-colors"
                            >
                                Hủy Bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirmPayment}
                                disabled={paying || payAmount <= 0}
                                className="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-lg shadow-emerald-600/30 transition-all flex items-center gap-2"
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
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-md overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="p-5 bg-gradient-to-r from-cyan-950/40 to-slate-900 border-b border-slate-800 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 rounded-xl bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                                    <QrCode className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-white">Mã QR Thanh Toán VietQR</h3>
                                    <p className="text-xs text-slate-400 font-mono">
                                        HĐ: {vietQrModalData.invoice_number} • Căn {vietQrModalData.apartment_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setVietQrModalData(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="p-6 text-center space-y-4">
                            {/* Ảnh QR VietQR */}
                            <div className="p-3 bg-white rounded-2xl inline-block shadow-xl border border-slate-200 mx-auto">
                                <img
                                    src={vietQrModalData.qr_image_url}
                                    alt="VietQR Chuyển Khoản"
                                    className="w-56 h-56 object-contain rounded-lg"
                                />
                            </div>

                            <p className="text-xs text-slate-400">
                                Quét mã bằng ứng dụng ngân hàng bất kỳ để tự động điền số tiền và nội dung chuyển khoản.
                            </p>

                            {/* Chi tiết tài khoản nhận */}
                            <div className="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2 text-xs text-left">
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-400">Ngân hàng:</span>
                                    <span className="font-bold text-white">MB Bank (BIN {vietQrModalData.bank_bin})</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-400">Số tài khoản:</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono font-bold text-cyan-300">{vietQrModalData.bank_account_number}</span>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(vietQrModalData.bank_account_number, 'Số tài khoản')}
                                            className="text-slate-400 hover:text-white"
                                            title="Sao chép STK"
                                        >
                                            <Copy className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-400">Chủ tài khoản:</span>
                                    <span className="font-semibold text-slate-200">{vietQrModalData.bank_account_name}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-400">Số tiền cần nộp:</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono font-bold text-emerald-400 text-sm">
                                            {Number(vietQrModalData.amount_due).toLocaleString('vi-VN')} đ
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(vietQrModalData.amount_due.toString(), 'Số tiền')}
                                            className="text-slate-400 hover:text-white"
                                            title="Sao chép số tiền"
                                        >
                                            <Copy className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-slate-400">Nội dung chuyển khoản:</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono font-bold text-indigo-300">{vietQrModalData.transfer_content}</span>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(vietQrModalData.transfer_content, 'Nội dung')}
                                            className="text-slate-400 hover:text-white"
                                            title="Sao chép nội dung"
                                        >
                                            <Copy className="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="p-4 bg-slate-950/70 border-t border-slate-800 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setVietQrModalData(null)}
                                className="px-5 py-2 rounded-xl text-xs font-semibold text-white bg-slate-800 hover:bg-slate-700 transition-colors"
                            >
                                Đóng
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 5: BIÊN LAI THU TIỀN ĐIỆN TỬ (PAYMENT RECEIPT) */}
            {receiptModalData && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Header */}
                        <div className="p-5 bg-gradient-to-r from-emerald-950/40 to-slate-900 border-b border-slate-800 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    <CheckCircle2 className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-white">Biên Lai Thu Tiền Điện Tử</h3>
                                    <p className="text-xs text-slate-400 font-mono">
                                        Mã BL: {receiptModalData.receipt?.receipt_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReceiptModalData(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Phiếu biên lai in ấn */}
                        <div className="p-6 space-y-4 text-xs bg-slate-900">
                            <div className="p-5 bg-slate-950 rounded-2xl border border-slate-800 space-y-3 font-mono">
                                <div className="text-center pb-3 border-b border-slate-800">
                                    <h4 className="font-bold text-white uppercase text-sm tracking-wider">
                                        BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART
                                    </h4>
                                    <p className="text-[11px] text-slate-400">BIÊN LAI THU TIỀN DỊCH VỤ TÒA NHÀ</p>
                                    <p className="text-[10px] text-slate-500">
                                        Số: <strong className="text-indigo-400">{receiptModalData.receipt?.receipt_number}</strong> • Ngày lập: {receiptModalData.receipt?.receipt_date}
                                    </p>
                                </div>

                                <div className="space-y-1.5 text-xs text-slate-300 font-sans">
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Người nộp tiền:</span>
                                        <strong className="text-white">{receiptModalData.receipt?.received_from_name}</strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Hóa đơn gạch nợ:</span>
                                        <strong className="text-indigo-300 font-mono">{receiptModalData.invoice?.invoice_number}</strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Căn hộ:</span>
                                        <strong className="text-white">
                                            {receiptModalData.invoice?.apartment?.apartment_number} ({receiptModalData.invoice?.apartment?.block?.block_name})
                                        </strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Phương thức:</span>
                                        <strong className="text-emerald-400">{receiptModalData.payment?.payment_gateway}</strong>
                                    </div>
                                    <div className="flex justify-between pt-2 border-t border-slate-800">
                                        <span className="text-slate-400">Số tiền đã nộp:</span>
                                        <strong className="text-base text-emerald-400 font-mono">
                                            {Number(receiptModalData.receipt?.amount).toLocaleString('vi-VN')} đ
                                        </strong>
                                    </div>
                                    <div className="bg-slate-900/80 p-2.5 rounded-lg border border-slate-800/80">
                                        <span className="text-[11px] text-slate-400">Bằng chữ: </span>
                                        <em className="text-slate-200 font-serif font-semibold">{receiptModalData.receipt?.amount_in_words}</em>
                                    </div>
                                </div>

                                {/* Chữ ký số điện tử */}
                                <div className="pt-2 border-t border-slate-800/80 text-[10px] text-slate-500 space-y-1">
                                    <div className="flex items-center gap-1.5 text-emerald-400 font-semibold">
                                        <Sparkles className="w-3 h-3" />
                                        <span>Chứng chỉ xác thực số điện tử bảo chứng</span>
                                    </div>
                                    <p className="font-mono break-all text-slate-400 bg-slate-900 p-2 rounded border border-slate-800">
                                        SHA256: {receiptModalData.receipt?.digital_signature_hash}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Footer Actions */}
                        <div className="flex items-center justify-between p-4 bg-slate-950/70 border-t border-slate-800">
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 transition-colors"
                            >
                                <Printer className="w-4 h-4" />
                                <span>In Biên Lai</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setReceiptModalData(null)}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 transition-colors"
                            >
                                Hoàn Tất
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 6: NHẬT KÝ HÀNG ĐỢI GỬI EMAIL NHẮC NỢ (CHỨC NĂNG 11) */}
            {reminderLogsModalOpen && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Header */}
                        <div className="p-5 bg-gradient-to-r from-amber-950/30 to-slate-900 border-b border-slate-800 flex items-center justify-between shrink-0">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                    <Mail className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-white">Nhật Ký Hàng Đợi Gửi Email Nhắc Nợ</h3>
                                    <p className="text-xs text-slate-400">
                                        Theo dõi trạng thái tiến trình Laravel Queue xử lý gửi email nhắc nợ tự động kèm VietQR
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReminderLogsModalOpen(false)}
                                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Body - Table logs */}
                        <div className="p-6 overflow-y-auto flex-1 text-xs">
                            {loadingReminderLogs ? (
                                <div className="py-16 text-center text-slate-400">
                                    <RefreshCw className="w-8 h-8 animate-spin mx-auto text-amber-500 mb-2" />
                                    <span>Đang tải nhật ký gửi nhắc nợ...</span>
                                </div>
                            ) : reminderLogs.length === 0 ? (
                                <div className="py-16 text-center text-slate-500">
                                    Chưa có nhật ký gửi email nhắc nợ nào được ghi nhận.
                                </div>
                            ) : (
                                <div className="overflow-x-auto border border-slate-800 rounded-xl">
                                    <table className="w-full text-left text-xs text-slate-300">
                                        <thead className="bg-slate-950/90 text-slate-400 font-semibold border-b border-slate-800 uppercase">
                                            <tr>
                                                <th className="py-3 px-3">Thời Gian</th>
                                                <th className="py-3 px-3">Mã Hóa Đơn</th>
                                                <th className="py-3 px-3">Người Nhận (Email)</th>
                                                <th className="py-3 px-3 text-right">Số Tiền Nợ</th>
                                                <th className="py-3 px-3 text-center">Kênh</th>
                                                <th className="py-3 px-3 text-center">Trạng Thái</th>
                                                <th className="py-3 px-3">Ghi Chú / Lỗi</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-800/60 font-mono">
                                            {reminderLogs.map((log: any) => (
                                                <tr key={log.id} className="hover:bg-slate-800/30">
                                                    <td className="py-2.5 px-3 text-slate-400 whitespace-nowrap">
                                                        {new Date(log.created_at).toLocaleString('vi-VN')}
                                                    </td>
                                                    <td className="py-2.5 px-3 font-bold text-indigo-300">
                                                        {log.invoice?.invoice_number || log.invoice_id}
                                                    </td>
                                                    <td className="py-2.5 px-3 font-sans text-slate-200">
                                                        {log.recipient_email || 'N/A'}
                                                    </td>
                                                    <td className="py-2.5 px-3 text-right font-bold text-amber-400">
                                                        {Number(log.debt_amount).toLocaleString('vi-VN')} đ
                                                    </td>
                                                    <td className="py-2.5 px-3 text-center">
                                                        <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-sky-500/10 text-sky-400 border border-sky-500/20">
                                                            {log.channel}
                                                        </span>
                                                    </td>
                                                    <td className="py-2.5 px-3 text-center">
                                                        {log.status === 'SENT' ? (
                                                            <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                                                                ĐÃ GỬI
                                                            </span>
                                                        ) : log.status === 'FAILED' ? (
                                                            <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-500/15 text-rose-300 border border-rose-500/30">
                                                                THẤT BÀI
                                                            </span>
                                                        ) : (
                                                            <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/15 text-amber-300 border border-amber-500/30">
                                                                CHỜ XỬ LÝ
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="py-2.5 px-3 font-sans text-slate-400 text-[11px] truncate max-w-xs" title={log.error_message || log.notes || ''}>
                                                        {log.error_message || log.notes || '-'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>

                        {/* Footer */}
                        <div className="p-4 bg-slate-950/70 border-t border-slate-800 flex justify-end shrink-0">
                            <button
                                type="button"
                                onClick={() => setReminderLogsModalOpen(false)}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-slate-800 hover:bg-slate-700 transition-colors"
                            >
                                Đóng
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 7: XUẤT BÁO CÁO TÀI CHÍNH & CÔNG NỢ (CHỨC NĂNG 13) */}
            <FinancialReportExportModal
                isOpen={reportModalOpen}
                onClose={() => setReportModalOpen(false)}
                blocks={blocks}
            />
        </div>
    );
};

export default InvoiceManagement;
