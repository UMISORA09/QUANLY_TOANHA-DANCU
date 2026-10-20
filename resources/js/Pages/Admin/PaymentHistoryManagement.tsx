import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
    History,
    Search,
    Calendar,
    Filter,
    CreditCard,
    DollarSign,
    RefreshCw,
    Download,
    Printer,
    CheckCircle2,
    Building2,
    FileText,
    Copy,
    ChevronLeft,
    ChevronRight,
    ArrowUpDown,
    QrCode,
    Sparkles,
    X,
    Wallet,
    Banknote,
    Layers,
    Receipt,
    Clock,
    User,
    Check,
    AlertTriangle,
    FileSpreadsheet,
    ShieldCheck
} from 'lucide-react';
import { invoiceApi, InvoiceModel } from '../../Services/invoiceApi';
import { printPaymentReceipt } from '../../Services/invoicePrintHelper';

interface BlockOption {
    id: string;
    block_code: string;
    block_name: string;
}

export interface PaymentHistoryManagementProps {
    onBackToList?: () => void;
    onNavigateTab?: (tabKey: string) => void;
}

export const PaymentHistoryManagement: React.FC<PaymentHistoryManagementProps> = ({
    onBackToList,
    onNavigateTab,
}) => {
    // Dữ liệu danh sách & KPI
    const [payments, setPayments] = useState<any[]>([]);
    const [summary, setSummary] = useState<any | null>(null);
    const [loading, setLoading] = useState<boolean>(true);

    // Bộ lọc
    const [gatewayFilter, setGatewayFilter] = useState<string>('ALL');
    const [billingPeriodFilter, setBillingPeriodFilter] = useState<string>('');
    const [dateFrom, setDateFrom] = useState<string>('');
    const [dateTo, setDateTo] = useState<string>('');
    const [blockFilter, setBlockFilter] = useState<string>('');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [quickDatePreset, setQuickDatePreset] = useState<'all' | 'today' | '7days' | 'this_month'>('all');

    // Phân trang
    const [pagination, setPagination] = useState({
        current_page: 1,
        last_page: 1,
        total: 0,
        per_page: 15,
    });

    // Danh sách khối
    const [blocks, setBlocks] = useState<BlockOption[]>([]);

    // Modal Biên lai
    const [activeReceiptData, setActiveReceiptData] = useState<any | null>(null);
    const [loadingReceipt, setLoadingReceipt] = useState<boolean>(false);

    // Feedback sao chép
    const [copiedRef, setCopiedRef] = useState<string | null>(null);

    // Toast thông báo
    const [toast, setToast] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    const showToast = (type: 'success' | 'error', text: string) => {
        setToast({ type, text });
        setTimeout(() => setToast(null), 3500);
    };

    // Tải danh sách khối
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
                console.error('Lỗi tải danh sách khối:', err);
            }
        };
        fetchBlocks();
    }, []);

    // Tải dữ liệu thanh toán & KPI
    const loadData = useCallback(async (page: number = 1) => {
        setLoading(true);
        try {
            const filters: any = {
                page,
                per_page: 15,
                search: searchQuery.trim() || undefined,
                payment_gateway: gatewayFilter !== 'ALL' ? gatewayFilter : undefined,
                billing_period: billingPeriodFilter.trim() || undefined,
                date_from: dateFrom.trim() || undefined,
                date_to: dateTo.trim() || undefined,
                block_id: blockFilter.trim() || undefined,
            };

            const [historyRes, summaryRes] = await Promise.all([
                invoiceApi.getPaymentHistory(filters),
                invoiceApi.getPaymentSummary({
                    payment_gateway: gatewayFilter !== 'ALL' ? gatewayFilter : undefined,
                    billing_period: billingPeriodFilter.trim() || undefined,
                    date_from: dateFrom.trim() || undefined,
                    date_to: dateTo.trim() || undefined,
                }),
            ]);

            if (historyRes.success) {
                setPayments(historyRes.data.data || []);
                setPagination({
                    current_page: historyRes.data.current_page,
                    last_page: historyRes.data.last_page,
                    total: historyRes.data.total,
                    per_page: historyRes.data.per_page,
                });
            }

            if (summaryRes.success) {
                setSummary(summaryRes.data);
            }
        } catch (err) {
            console.error('Lỗi tải lịch sử thanh toán:', err);
            showToast('error', 'Không thể kết nối đến máy chủ để tải lịch sử giao dịch.');
        } finally {
            setLoading(false);
        }
    }, [gatewayFilter, billingPeriodFilter, dateFrom, dateTo, blockFilter, searchQuery]);

    useEffect(() => {
        const timer = setTimeout(() => {
            loadData(1);
        }, 300);
        return () => clearTimeout(timer);
    }, [loadData]);

    // Xử lý Preset ngày nhanh
    const handleSelectDatePreset = (preset: 'all' | 'today' | '7days' | 'this_month') => {
        setQuickDatePreset(preset);
        const today = new Date();
        const formatDate = (d: Date) => d.toISOString().split('T')[0];

        if (preset === 'all') {
            setDateFrom('');
            setDateTo('');
        } else if (preset === 'today') {
            const todayStr = formatDate(today);
            setDateFrom(todayStr);
            setDateTo(todayStr);
        } else if (preset === '7days') {
            const past7 = new Date();
            past7.setDate(today.getDate() - 7);
            setDateFrom(formatDate(past7));
            setDateTo(formatDate(today));
        } else if (preset === 'this_month') {
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            setDateFrom(formatDate(firstDay));
            setDateTo(formatDate(today));
        }
    };

    // Đếm số lượng bộ lọc đang kích hoạt
    const activeFilterCount = useMemo(() => {
        let count = 0;
        if (searchQuery.trim()) count++;
        if (gatewayFilter !== 'ALL') count++;
        if (blockFilter) count++;
        if (billingPeriodFilter) count++;
        if (dateFrom || dateTo) count++;
        return count;
    }, [searchQuery, gatewayFilter, blockFilter, billingPeriodFilter, dateFrom, dateTo]);

    // Đặt lại toàn bộ bộ lọc
    const handleResetFilters = () => {
        setSearchQuery('');
        setGatewayFilter('ALL');
        setBlockFilter('');
        setBillingPeriodFilter('');
        setDateFrom('');
        setDateTo('');
        setQuickDatePreset('all');
    };

    // Sao chép mã giao dịch
    const handleCopyRef = (text: string, e: React.MouseEvent) => {
        e.stopPropagation();
        navigator.clipboard.writeText(text);
        setCopiedRef(text);
        showToast('success', `Đã sao chép mã ${text}`);
        setTimeout(() => setCopiedRef(null), 2500);
    };

    // Xem chi tiết biên lai
    const handleViewReceipt = async (paymentId: string) => {
        setLoadingReceipt(true);
        try {
            const res = await invoiceApi.getReceiptDetail(paymentId);
            if (res.success) {
                setActiveReceiptData(res.data);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Không thể xem chi tiết biên lai');
        } finally {
            setLoadingReceipt(false);
        }
    };

    // Xuất dữ liệu giao dịch ra Excel (CSV UTF-8 BOM)
    const handleExportCsv = () => {
        if (payments.length === 0) {
            showToast('error', 'Không có dữ liệu giao dịch để xuất báo cáo.');
            return;
        }

        const headers = [
            'Mã Giao Dịch',
            'Mã Đối Soát (Gateway Ref)',
            'Mã Hóa Đơn',
            'Kỳ Thu Phí',
            'Căn Hộ',
            'Khối Tòa',
            'Người Nộp Tiền',
            'Số Tiền Đã Thu (VND)',
            'Phương Thức',
            'Thời Gian Giao Dịch',
            'Mã Biên Lai',
        ];

        const rows = payments.map((p) => [
            `"${p.payment_reference_code || ''}"`,
            `"${p.gateway_transaction_id || ''}"`,
            `"${p.invoice?.invoice_number || ''}"`,
            `"${p.invoice?.billing_period || ''}"`,
            `"${p.apartment?.apartment_number || ''}"`,
            `"${p.apartment?.block?.block_name || ''}"`,
            `"${p.payer_user?.full_name || 'Cư dân'}"`,
            p.amount_paid || 0,
            `"${p.payment_gateway || ''}"`,
            `"${p.payment_time || p.created_at || ''}"`,
            `"${p.receipt?.receipt_number || ''}"`,
        ]);

        const csvContent = '\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\r\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        const dateStr = new Date().toISOString().slice(0, 10).replace(/-/g, '');
        link.setAttribute('href', url);
        link.setAttribute('download', `lich_su_giao_dich_${dateStr}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        showToast('success', 'Đã xuất file sao kê lịch sử giao dịch (.csv) thành công!');
    };

    // Helper render Pill phương thức thanh toán
    const renderGatewayBadge = (gateway: string) => {
        switch (gateway) {
            case 'CASH':
                return (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs">
                        <Banknote className="w-3.5 h-3.5 text-emerald-600" />
                        <span>Tiền Mặt</span>
                    </span>
                );
            case 'BANK_TRANSFER':
                return (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-sky-50 text-sky-700 border border-sky-200 shadow-2xs">
                        <CreditCard className="w-3.5 h-3.5 text-sky-600" />
                        <span>Chuyển Khoản</span>
                    </span>
                );
            case 'VIETQR':
            case 'VIETQR_BANK_TRANSFER':
                return (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-200 shadow-2xs">
                        <QrCode className="w-3.5 h-3.5 text-purple-600" />
                        <span>VietQR</span>
                    </span>
                );
            case 'VNPAY':
                return (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200 shadow-2xs">
                        <Wallet className="w-3.5 h-3.5 text-blue-600" />
                        <span>VNPay</span>
                    </span>
                );
            case 'MOMO':
                return (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-pink-50 text-pink-700 border border-pink-200 shadow-2xs">
                        <Wallet className="w-3.5 h-3.5 text-pink-600" />
                        <span>MoMo</span>
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200 shadow-2xs">
                        <Wallet className="w-3.5 h-3.5 text-amber-600" />
                        <span>{gateway}</span>
                    </span>
                );
        }
    };

    return (
        <div className="max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 animate-in fade-in duration-300">
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

            {/* HEADER TIÊU ĐỀ */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200/80">
                <div>
                    <div className="text-[11px] font-bold text-sky-600 font-mono tracking-wider uppercase flex items-center gap-1.5">
                        <History className="w-3.5 h-3.5" />
                        <span>HỆ THỐNG KẾ TOÁN & THU TIỀN · CHỨC NĂNG #10 (LỊCH SỬ GIAO DỊCH & BIÊN LAI)</span>
                    </div>
                    <h1 className="text-2xl sm:text-3xl font-black text-neutral-900 tracking-tight mt-1">
                        Lịch Sử Giao Dịch & Biên Lai Điện Tử
                    </h1>
                    <p className="text-xs sm:text-sm text-slate-500 max-w-3xl font-medium mt-1">
                        Tra cứu toàn diện các giao dịch nộp tiền, sao kê dòng tiền thực tế qua POS / Tiền mặt / VietQR và in biên lai bảo chứng số.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2.5">
                    <button
                        type="button"
                        onClick={handleExportCsv}
                        disabled={loading || payments.length === 0}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 text-xs font-bold shadow-2xs transition-all active:scale-[0.98] disabled:opacity-50 cursor-pointer"
                        title="Tải file CSV sao kê danh sách giao dịch"
                    >
                        <Download className="w-3.5 h-3.5 text-teal-600" />
                        <span>Xuất Sao Kê CSV</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => loadData(pagination.current_page)}
                        disabled={loading}
                        className="p-2 rounded-xl text-slate-500 hover:text-slate-800 bg-white border border-slate-200 hover:bg-slate-50 shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                        title="Làm mới dữ liệu"
                    >
                        <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin text-sky-600' : ''}`} />
                    </button>
                </div>
            </div>

            {/* THANH ĐIỀU HƯỚNG TÁC VỤ & CHỨC NĂNG */}
            <div className="bg-white border border-slate-200/80 rounded-2xl p-3 sm:p-3.5 shadow-2xs flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span className="text-xs font-bold text-slate-700 uppercase tracking-wider font-mono flex items-center gap-1.5">
                        <Layers className="w-3.5 h-3.5 text-sky-600" />
                        Tác Vụ & Liên Kết:
                    </span>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {onBackToList && (
                        <button
                            type="button"
                            onClick={onBackToList}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-bold shadow-2xs transition-all cursor-pointer"
                        >
                            <ChevronLeft className="w-3.5 h-3.5 text-emerald-600" />
                            <span>Quay Lại Danh Sách Hóa Đơn</span>
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={() => onNavigateTab ? onNavigateTab('thu_tien_gach_no') : (onBackToList ? onBackToList() : null)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-xs font-bold shadow-2xs transition-all cursor-pointer"
                        title="Quầy thu ngân BQL: Thu tiền mặt / POS / Chuyển khoản VietQR và gạch nợ tức thời (Chức năng 8)"
                    >
                        <Banknote className="w-3.5 h-3.5 text-blue-600" />
                        <span>Thu Tiền & Gạch Nợ (CN8)</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => onNavigateTab ? onNavigateTab('invoices') : (onBackToList ? onBackToList() : null)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 text-xs font-bold shadow-2xs transition-all cursor-pointer"
                        title="Quản lý và lọc hóa đơn (Chức năng 7 & 9)"
                    >
                        <Receipt className="w-3.5 h-3.5 text-indigo-600" />
                        <span>Quản Lý Hóa Đơn (CN7)</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => onNavigateTab ? onNavigateTab('invoice_generation') : null}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200 text-xs font-bold shadow-2xs transition-all cursor-pointer"
                        title="Sinh hóa đơn tự động hàng loạt (Chức năng 6)"
                    >
                        <Layers className="w-3.5 h-3.5 text-sky-600" />
                        <span>Sinh Hóa Đơn (CN6)</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => onNavigateTab ? onNavigateTab('financial_reports') : null}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 text-xs font-bold shadow-2xs transition-all cursor-pointer"
                        title="Báo cáo tài chính & xuất Excel/PDF (Chức năng 13)"
                    >
                        <FileSpreadsheet className="w-3.5 h-3.5 text-teal-600" />
                        <span>Báo Cáo Tài Chính (CN13)</span>
                    </button>
                </div>
            </div>

            {/* KPI METRIC CARDS TỔNG HỢP GIAO DỊCH (BẢNG THÔNG SỐ) */}
            {summary && (
                <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3.5">
                    {/* Thẻ 1: Tổng tiền thu được */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">TỔNG TIỀN THU ĐƯỢC</span>
                            <div className="kpi-icon-wrapper bg-emerald-50 text-emerald-600">
                                <CreditCard className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-emerald-600 font-mono tracking-tight">
                            {Number(summary.total_amount || 0).toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-emerald-700 font-semibold truncate">
                            Từ <strong className="text-neutral-900 font-bold">{summary.successful_transactions || 0}</strong> giao dịch thành công
                        </div>
                    </div>

                    {/* Thẻ 2: Tiền mặt tại quầy */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">TIỀN MẶT (CASH)</span>
                            <div className="kpi-icon-wrapper bg-emerald-50 text-emerald-600">
                                <Banknote className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-neutral-900 font-mono">
                            {Number(summary.by_gateway?.CASH?.total || 0).toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-slate-500 truncate">
                            <span className="text-emerald-700 font-bold font-mono">{summary.by_gateway?.CASH?.count || 0}</span> lượt thu tại quầy BQL
                        </div>
                    </div>

                    {/* Thẻ 3: Chuyển khoản ngân hàng */}
                    <div className="sass-db-kpi-card">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">CHUYỂN KHOẢN (BANK)</span>
                            <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                                <CreditCard className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-sky-600 font-mono">
                            {Number(summary.by_gateway?.BANK_TRANSFER?.total || 0).toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-slate-500 truncate">
                            <span className="text-sky-700 font-bold font-mono">{summary.by_gateway?.BANK_TRANSFER?.count || 0}</span> lượt qua tài khoản
                        </div>
                    </div>

                    {/* Thẻ 4: VietQR & Ví điện tử */}
                    <div className="sass-db-kpi-card col-span-2 sm:col-span-1">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] sm:text-xs font-semibold text-slate-500">VIETQR & VÍ ĐIỆN TỬ</span>
                            <div className="kpi-icon-wrapper bg-purple-50 text-purple-600">
                                <QrCode className="w-4 h-4" />
                            </div>
                        </div>
                        <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-purple-600 font-mono">
                            {(
                                Number(summary.by_gateway?.VIETQR?.total || 0) +
                                Number(summary.by_gateway?.VNPAY?.total || 0) +
                                Number(summary.by_gateway?.MOMO?.total || 0)
                            ).toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                        </div>
                        <div className="mt-1 text-[10px] sm:text-[11px] text-slate-500 truncate">
                            <span className="text-purple-700 font-bold font-mono">
                                {(summary.by_gateway?.VIETQR?.count || 0) +
                                    (summary.by_gateway?.VNPAY?.count || 0) +
                                    (summary.by_gateway?.MOMO?.count || 0)}
                            </span> lượt quét mã QR tự động
                        </div>
                    </div>
                </div>
            )}

            {/* BỘ LỌC TÌM KIẾM NÂNG CAO */}
            <div className="bg-white border border-slate-200/80 p-4 sm:p-5 rounded-2xl shadow-xs space-y-4">
                <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
                    {/* Ô tìm kiếm từ khóa */}
                    <div className="relative flex-1">
                        <Search className="w-4 h-4 absolute left-3.5 top-3 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Tìm kiếm theo mã giao dịch, số căn hộ, mã biên lai, người nộp..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full bg-slate-50/70 hover:bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-9 py-2 text-xs text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 font-medium transition-all"
                        />
                        {searchQuery && (
                            <button
                                type="button"
                                onClick={() => setSearchQuery('')}
                                className="absolute right-3 top-2.5 text-slate-400 hover:text-slate-600 p-0.5 rounded cursor-pointer"
                            >
                                <X className="w-3.5 h-3.5" />
                            </button>
                        )}
                    </div>

                    {/* Bộ chọn Phương thức thanh toán & Khối tòa */}
                    <div className="flex flex-wrap sm:flex-nowrap items-center gap-2">
                        <select
                            value={gatewayFilter}
                            onChange={(e) => setGatewayFilter(e.target.value)}
                            className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 text-xs text-slate-700 font-medium rounded-xl px-3 py-2 focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 cursor-pointer transition-all"
                        >
                            <option value="ALL">💳 Tất Cả Phương Thức</option>
                            <option value="CASH">💵 Tiền Mặt (CASH)</option>
                            <option value="BANK_TRANSFER">🏦 Chuyển Khoản Ngân Hàng</option>
                            <option value="VIETQR">📱 Mã VietQR</option>
                            <option value="VNPAY">🔷 Ví VNPay</option>
                            <option value="MOMO">💖 Ví MoMo</option>
                        </select>

                        <select
                            value={blockFilter}
                            onChange={(e) => setBlockFilter(e.target.value)}
                            className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 text-xs text-slate-700 font-medium rounded-xl px-3 py-2 focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 cursor-pointer transition-all"
                        >
                            <option value="">🏢 Tất Cả Khối Tòa</option>
                            {blocks.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.block_name} ({b.block_code})
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Khoảng ngày & Kỳ phí */}
                <div className="flex flex-wrap items-center justify-between gap-3 pt-3.5 border-t border-slate-100 text-xs">
                    <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                        <span className="text-slate-500 font-semibold flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-wider">
                            <Calendar className="w-3.5 h-3.5 text-sky-600" />
                            <span>Thời Gian:</span>
                        </span>

                        {/* Quick Presets */}
                        <div className="flex items-center gap-1 bg-slate-100/80 p-0.5 rounded-xl border border-slate-200/70">
                            <button
                                type="button"
                                onClick={() => handleSelectDatePreset('all')}
                                className={`px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all cursor-pointer ${
                                    quickDatePreset === 'all'
                                        ? 'bg-white text-slate-800 shadow-2xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                Tất Cả
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSelectDatePreset('today')}
                                className={`px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all cursor-pointer ${
                                    quickDatePreset === 'today'
                                        ? 'bg-white text-emerald-700 shadow-2xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                Hôm Nay
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSelectDatePreset('7days')}
                                className={`px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all cursor-pointer ${
                                    quickDatePreset === '7days'
                                        ? 'bg-white text-sky-700 shadow-2xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                7 Ngày
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSelectDatePreset('this_month')}
                                className={`px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all cursor-pointer ${
                                    quickDatePreset === 'this_month'
                                        ? 'bg-white text-purple-700 shadow-2xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                Tháng Này
                            </button>
                        </div>

                        {/* Date pickers */}
                        <div className="flex items-center gap-1.5">
                            <input
                                type="date"
                                value={dateFrom}
                                onChange={(e) => {
                                    setDateFrom(e.target.value);
                                    setQuickDatePreset('all');
                                }}
                                title="Từ ngày"
                                className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 text-slate-700 font-mono text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-sky-500"
                            />
                            <span className="text-slate-400 font-bold">-</span>
                            <input
                                type="date"
                                value={dateTo}
                                onChange={(e) => {
                                    setDateTo(e.target.value);
                                    setQuickDatePreset('all');
                                }}
                                title="Đến ngày"
                                className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 text-slate-700 font-mono text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-sky-500"
                            />
                        </div>

                        <span className="text-slate-200 hidden sm:inline">|</span>

                        {/* Kỳ hóa đơn */}
                        <div className="flex items-center gap-1.5">
                            <span className="text-slate-500 font-semibold font-mono text-[11px]">Kỳ:</span>
                            <input
                                type="month"
                                value={billingPeriodFilter}
                                onChange={(e) => setBillingPeriodFilter(e.target.value)}
                                className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 text-slate-700 font-mono text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-sky-500"
                            />
                        </div>
                    </div>

                    {/* Nút Xóa bộ lọc */}
                    {activeFilterCount > 0 && (
                        <button
                            type="button"
                            onClick={handleResetFilters}
                            className="inline-flex items-center gap-1 text-xs text-rose-600 hover:text-rose-700 font-bold bg-rose-50 hover:bg-rose-100 border border-rose-200 px-3 py-1.5 rounded-xl shadow-2xs transition-all cursor-pointer"
                        >
                            <X className="w-3.5 h-3.5" />
                            <span>Xóa Bộ Lọc ({activeFilterCount})</span>
                        </button>
                    )}
                </div>
            </div>

            {/* BẢNG DANH SÁCH LỊCH SỬ GIAO DỊCH */}
            <div className="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-xs">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-slate-50/80 border-b border-slate-200/80 text-[11px] font-bold text-slate-600 uppercase tracking-wider font-mono">
                                <th className="py-3.5 px-4">Mã Giao Dịch & Ref</th>
                                <th className="py-3.5 px-4">Hóa Đơn / Kỳ</th>
                                <th className="py-3.5 px-4">Căn Hộ</th>
                                <th className="py-3.5 px-4">Người Nộp Tiền</th>
                                <th className="py-3.5 px-4 text-right">Số Tiền Đã Thu</th>
                                <th className="py-3.5 px-4 text-center">Phương Thức</th>
                                <th className="py-3.5 px-4">Thời Gian GD</th>
                                <th className="py-3.5 px-4">Mã Biên Lai</th>
                                <th className="py-3.5 px-4 text-right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-xs">
                            {loading ? (
                                Array.from({ length: 6 }).map((_, i) => (
                                    <tr key={i} className="animate-pulse">
                                        <td className="py-4 px-4"><div className="h-4 bg-slate-200 rounded w-28"></div></td>
                                        <td className="py-4 px-4"><div className="h-4 bg-slate-200 rounded w-20"></div></td>
                                        <td className="py-4 px-4"><div className="h-4 bg-slate-200 rounded w-16"></div></td>
                                        <td className="py-4 px-4"><div className="h-4 bg-slate-200 rounded w-24"></div></td>
                                        <td className="py-4 px-4 text-right"><div className="h-4 bg-slate-200 rounded w-24 ml-auto"></div></td>
                                        <td className="py-4 px-4"><div className="h-4 bg-slate-200 rounded w-20 mx-auto"></div></td>
                                        <td className="py-4 px-4"><div className="h-4 bg-slate-200 rounded w-28"></div></td>
                                        <td className="py-4 px-4"><div className="h-4 bg-slate-200 rounded w-20"></div></td>
                                        <td className="py-4 px-4 text-right"><div className="h-4 bg-slate-200 rounded w-16 ml-auto"></div></td>
                                    </tr>
                                ))
                            ) : payments.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="py-16 text-center text-slate-500">
                                        <div className="w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
                                            <Receipt className="w-7 h-7" />
                                        </div>
                                        <h3 className="text-sm font-bold text-slate-700">Không tìm thấy giao dịch nào</h3>
                                        <p className="text-xs text-slate-400 max-w-sm mx-auto mt-1">
                                            Không có bản ghi thanh toán nào phù hợp với các tiêu chí tìm kiếm hiện tại.
                                        </p>
                                        {activeFilterCount > 0 && (
                                            <button
                                                type="button"
                                                onClick={handleResetFilters}
                                                className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-sky-50 text-sky-700 border border-sky-200 text-xs font-bold hover:bg-sky-100 transition-colors cursor-pointer"
                                            >
                                                <RefreshCw className="w-3 h-3" />
                                                <span>Đặt lại bộ lọc tìm kiếm</span>
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ) : (
                                payments.map((p) => (
                                    <tr key={p.id} className="hover:bg-slate-50/70 transition-colors group">
                                        {/* Mã giao dịch & Ref */}
                                        <td className="py-3.5 px-4 font-mono font-bold text-sky-700">
                                            <div className="flex items-center gap-1.5">
                                                <span>{p.payment_reference_code}</span>
                                                <button
                                                    type="button"
                                                    onClick={(e) => handleCopyRef(p.payment_reference_code, e)}
                                                    className="opacity-0 group-hover:opacity-100 p-1 text-slate-400 hover:text-sky-600 transition-opacity cursor-pointer rounded"
                                                    title="Sao chép mã giao dịch"
                                                >
                                                    {copiedRef === p.payment_reference_code ? (
                                                        <Check className="w-3 h-3 text-emerald-600" />
                                                    ) : (
                                                        <Copy className="w-3 h-3" />
                                                    )}
                                                </button>
                                            </div>
                                            {p.gateway_transaction_id && (
                                                <div className="text-[10px] text-slate-400 font-mono tracking-tight">
                                                    Ref: {p.gateway_transaction_id}
                                                </div>
                                            )}
                                        </td>

                                        {/* Hóa đơn & Kỳ */}
                                        <td className="py-3.5 px-4">
                                            <div className="font-mono font-semibold text-neutral-900">
                                                {p.invoice?.invoice_number || 'N/A'}
                                            </div>
                                            <div className="text-[11px] text-slate-500 font-mono">
                                                Kỳ: {p.invoice?.billing_period || 'N/A'}
                                            </div>
                                        </td>

                                        {/* Căn hộ */}
                                        <td className="py-3.5 px-4">
                                            <div className="font-bold text-neutral-900 flex items-center gap-1.5">
                                                <Building2 className="w-3.5 h-3.5 text-sky-600" />
                                                <span>{p.apartment?.apartment_number || 'N/A'}</span>
                                            </div>
                                            <div className="text-[11px] text-slate-400">
                                                {p.apartment?.block?.block_name || ''}
                                            </div>
                                        </td>

                                        {/* Người nộp */}
                                        <td className="py-3.5 px-4">
                                            <div className="font-semibold text-slate-700 flex items-center gap-1.5">
                                                <User className="w-3.5 h-3.5 text-slate-400" />
                                                <span>{p.payer_user?.full_name || 'Cư dân căn hộ'}</span>
                                            </div>
                                        </td>

                                        {/* Số tiền đã thu */}
                                        <td className="py-3.5 px-4 text-right font-mono font-bold text-emerald-600 text-sm whitespace-nowrap">
                                            +{Number(p.amount_paid).toLocaleString('vi-VN')} đ
                                        </td>

                                        {/* Phương thức */}
                                        <td className="py-3.5 px-4 text-center whitespace-nowrap">
                                            {renderGatewayBadge(p.payment_gateway)}
                                        </td>

                                        {/* Thời gian giao dịch */}
                                        <td className="py-3.5 px-4 font-mono text-slate-500 text-[11px] whitespace-nowrap">
                                            <div className="flex items-center gap-1">
                                                <Clock className="w-3 h-3 text-slate-400" />
                                                <span>
                                                    {p.payment_time
                                                        ? p.payment_time.substring(0, 19).replace('T', ' ')
                                                        : p.created_at.substring(0, 19).replace('T', ' ')}
                                                </span>
                                            </div>
                                        </td>

                                        {/* Mã biên lai */}
                                        <td className="py-3.5 px-4 font-mono text-sky-700 font-semibold whitespace-nowrap">
                                            {p.receipt?.receipt_number ? (
                                                <span className="inline-flex items-center gap-1 text-sky-700 font-mono font-bold">
                                                    <Receipt className="w-3 h-3 text-sky-600" />
                                                    {p.receipt.receipt_number}
                                                </span>
                                            ) : (
                                                <span className="text-slate-400 italic text-[11px]">Chưa cấp</span>
                                            )}
                                        </td>

                                        {/* Thao tác */}
                                        <td className="py-3.5 px-4 text-right whitespace-nowrap">
                                            {p.receipt ? (
                                                <button
                                                    type="button"
                                                    onClick={() => handleViewReceipt(p.id)}
                                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-bold shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                                                    title="Xem và in biên lai thu tiền"
                                                >
                                                    <Printer className="w-3.5 h-3.5" />
                                                    <span>In Biên Lai</span>
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => handleViewReceipt(p.id)}
                                                    className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-50 hover:bg-slate-100 text-slate-600 border border-slate-200 text-[11px] font-semibold transition-colors cursor-pointer"
                                                    title="Xem thông tin chi tiết giao dịch"
                                                >
                                                    <Receipt className="w-3 h-3" />
                                                    <span>Chi Tiết</span>
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* PHÂN TRANG */}
                {pagination.total > 0 && (
                    <div className="flex flex-col sm:flex-row items-center justify-between p-4 bg-slate-50/70 border-t border-slate-200/80 text-xs text-slate-500 gap-3">
                        <div>
                            Hiển thị từ <strong className="font-mono text-slate-700">{(pagination.current_page - 1) * pagination.per_page + 1}</strong> đến{' '}
                            <strong className="font-mono text-slate-700">
                                {Math.min(pagination.current_page * pagination.per_page, pagination.total)}
                            </strong>{' '}
                            trong tổng số <strong className="font-mono text-slate-900">{pagination.total}</strong> giao dịch
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => loadData(pagination.current_page - 1)}
                                disabled={pagination.current_page <= 1 || loading}
                                className="px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs hover:bg-slate-50 transition-colors cursor-pointer flex items-center gap-1"
                            >
                                <ChevronLeft className="w-3.5 h-3.5" />
                                <span>Trang trước</span>
                            </button>

                            <div className="px-3 py-1 bg-white border border-slate-200 rounded-xl font-mono font-bold text-slate-800 shadow-2xs">
                                {pagination.current_page} / {pagination.last_page}
                            </div>

                            <button
                                type="button"
                                onClick={() => loadData(pagination.current_page + 1)}
                                disabled={pagination.current_page >= pagination.last_page || loading}
                                className="px-3 py-1.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold disabled:opacity-40 disabled:cursor-not-allowed shadow-2xs hover:bg-slate-50 transition-colors cursor-pointer flex items-center gap-1"
                            >
                                <span>Trang sau</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* MODAL XEM & IN BIÊN LAI THU TIỀN */}
            {activeReceiptData && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200/90 rounded-3xl w-full max-w-lg overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Modal Header */}
                        <div className="p-5 bg-slate-50/80 border-b border-slate-200 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-200 shadow-2xs">
                                    <CheckCircle2 className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-bold text-neutral-900">Biên Lai Thu Tiền Điện Tử</h3>
                                    <p className="text-xs text-slate-500 font-mono">
                                        Mã BL: <strong className="text-sky-700">{activeReceiptData.receipt?.receipt_number || 'N/A'}</strong>
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setActiveReceiptData(null)}
                                className="p-1.5 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        {/* Modal Content - Phiếu Thu Formal */}
                        <div className="p-6 space-y-4 text-xs bg-white">
                            <div className="p-5 bg-slate-50/80 rounded-2xl border border-slate-200 space-y-3 font-mono shadow-2xs relative overflow-hidden">
                                {/* Watermark */}
                                <div className="absolute right-2 bottom-12 rotate-[-15deg] pointer-events-none select-none opacity-[0.06] text-5xl font-black font-sans uppercase">
                                    ĐÃ THU TIỀN
                                </div>

                                <div className="text-center pb-3 border-b border-slate-200">
                                    <h4 className="font-bold text-neutral-900 uppercase text-xs tracking-wider">
                                        BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART
                                    </h4>
                                    <p className="text-[11px] text-slate-500 font-sans mt-0.5">BIÊN LAI THU TIỀN DỊCH VỤ TÒA NHÀ</p>
                                    <p className="text-[10px] text-slate-400 mt-0.5">
                                        Số: <strong className="text-sky-700">{activeReceiptData.receipt?.receipt_number || 'N/A'}</strong> • Ngày lập: {activeReceiptData.receipt?.receipt_date || 'Hôm nay'}
                                    </p>
                                </div>

                                <div className="space-y-2 text-xs text-slate-700 font-sans">
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">Người nộp tiền:</span>
                                        <strong className="text-neutral-900">{activeReceiptData.receipt?.received_from_name || 'Cư dân'}</strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">Hóa đơn gạch nợ:</span>
                                        <strong className="text-sky-700 font-mono">{activeReceiptData.invoice?.invoice_number || 'N/A'}</strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">Căn hộ:</span>
                                        <strong className="text-neutral-900">
                                            {activeReceiptData.invoice?.apartment?.apartment_number} {activeReceiptData.invoice?.apartment?.block?.block_name ? `(${activeReceiptData.invoice.apartment.block.block_name})` : ''}
                                        </strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-500">Phương thức:</span>
                                        <strong className="text-emerald-700">{activeReceiptData.payment?.payment_gateway || 'Tiền Mặt'}</strong>
                                    </div>
                                    <div className="flex justify-between pt-2 border-t border-slate-200">
                                        <span className="text-slate-500 font-medium">Số tiền đã nộp:</span>
                                        <strong className="text-base text-emerald-700 font-mono font-bold">
                                            {Number(activeReceiptData.receipt?.amount || activeReceiptData.payment?.amount_paid || 0).toLocaleString('vi-VN')} đ
                                        </strong>
                                    </div>
                                    {activeReceiptData.receipt?.amount_in_words && (
                                        <div className="bg-white p-2.5 rounded-xl border border-slate-200/80 shadow-2xs">
                                            <span className="text-[11px] text-slate-400">Bằng chữ: </span>
                                            <em className="text-slate-700 font-serif font-semibold">{activeReceiptData.receipt?.amount_in_words}</em>
                                        </div>
                                    )}
                                </div>

                                {/* Chữ ký số điện tử */}
                                <div className="pt-2 border-t border-slate-200 text-[10px] text-slate-500 space-y-1">
                                    <div className="flex items-center gap-1.5 text-emerald-700 font-semibold">
                                        <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                                        <span>Chứng chỉ xác thực số điện tử bảo chứng</span>
                                    </div>
                                    <p className="font-mono break-all text-slate-500 bg-white p-2 rounded-xl border border-slate-200 shadow-2xs">
                                        SHA256: {activeReceiptData.receipt?.digital_signature_hash || 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Modal Footer */}
                        <div className="flex items-center justify-between p-4 bg-slate-50/70 border-t border-slate-100">
                            <button
                                type="button"
                                onClick={() => printPaymentReceipt(activeReceiptData)}
                                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 bg-white border border-slate-200 shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                            >
                                <Printer className="w-3.5 h-3.5" />
                                <span>In Biên Lai A4</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveReceiptData(null)}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
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

export default PaymentHistoryManagement;
