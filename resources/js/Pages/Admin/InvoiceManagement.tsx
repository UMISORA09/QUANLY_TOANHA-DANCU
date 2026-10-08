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
    X
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
            <div className="space-y-4">
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
                        onClick={() => setViewMode('batch_generate')}
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

                {/* Thao tác hàng loạt (Bulk Cancel) */}
                {selectedInvoiceIds.length > 0 && (
                    <div className="flex items-center gap-2">
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
                                                    <button
                                                        type="button"
                                                        onClick={() => setDetailInvoice(inv)}
                                                        className="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-indigo-300 hover:text-white transition-colors"
                                                        title="Xem chi tiết hóa đơn"
                                                    >
                                                        <Eye className="w-4 h-4" />
                                                    </button>

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

                        <div className="p-4 bg-slate-950/70 border-t border-slate-800 flex justify-end">
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
        </div>
    );
};

export default InvoiceManagement;
