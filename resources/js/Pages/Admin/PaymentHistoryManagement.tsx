import React, { useState, useEffect, useCallback } from 'react';
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
    Wallet
} from 'lucide-react';
import { invoiceApi, InvoiceModel } from '../../Services/invoiceApi';

interface BlockOption {
    id: string;
    block_code: string;
    block_name: string;
}

export const PaymentHistoryManagement: React.FC = () => {
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

    // Xem chi tiết biên lai
    const handleViewReceipt = async (paymentId: string) => {
        setLoadingReceipt(true);
        try {
            const res = await invoiceApi.getReceiptDetail(paymentId);
            if (res.success) {
                setActiveReceiptData(res.data);
            }
        } catch (err: any) {
            alert(err.message || 'Không thể xem chi tiết biên lai');
        } finally {
            setLoadingReceipt(false);
        }
    };

    const renderGatewayBadge = (gateway: string) => {
        switch (gateway) {
            case 'CASH':
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                        <DollarSign className="w-3 h-3 text-emerald-400" />
                        <span>Tiền Mặt</span>
                    </span>
                );
            case 'BANK_TRANSFER':
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-cyan-500/15 text-cyan-300 border border-cyan-500/30">
                        <CreditCard className="w-3 h-3 text-cyan-400" />
                        <span>Chuyển Khoản</span>
                    </span>
                );
            case 'VIETQR':
            case 'VIETQR_BANK_TRANSFER':
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-purple-500/15 text-purple-300 border border-purple-500/30">
                        <QrCode className="w-3 h-3 text-purple-400" />
                        <span>VietQR</span>
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/15 text-amber-300 border border-amber-500/30">
                        <Wallet className="w-3 h-3 text-amber-400" />
                        <span>{gateway}</span>
                    </span>
                );
        }
    };

    return (
        <div className="space-y-6 animate-in fade-in duration-300">
            {/* KPI CARDS TỔNG HỢP GIAO DỊCH */}
            {summary && (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>TỔNG TIỀN THU ĐƯỢC</span>
                            <DollarSign className="w-4 h-4 text-emerald-400" />
                        </div>
                        <div className="mt-2 text-2xl font-black text-emerald-400 font-mono tracking-tight">
                            {Number(summary.total_amount || 0).toLocaleString('vi-VN')} đ
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            Từ {summary.successful_transactions || 0} giao dịch thành công
                        </p>
                    </div>

                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>TIỀN MẶT (CASH)</span>
                            <span className="text-emerald-400 font-bold">{summary.by_gateway?.CASH?.count || 0} lượt</span>
                        </div>
                        <div className="mt-2 text-xl font-bold text-white font-mono">
                            {Number(summary.by_gateway?.CASH?.total || 0).toLocaleString('vi-VN')} đ
                        </div>
                        <p className="mt-1 text-xs text-slate-500">Tại quầy ban quản lý</p>
                    </div>

                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>CHUYỂN KHOẢN (BANK)</span>
                            <span className="text-cyan-400 font-bold">{summary.by_gateway?.BANK_TRANSFER?.count || 0} lượt</span>
                        </div>
                        <div className="mt-2 text-xl font-bold text-cyan-300 font-mono">
                            {Number(summary.by_gateway?.BANK_TRANSFER?.total || 0).toLocaleString('vi-VN')} đ
                        </div>
                        <p className="mt-1 text-xs text-slate-500">Qua số tài khoản ngân hàng</p>
                    </div>

                    <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
                        <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                            <span>VIETQR / VÍ ĐIỆN TỬ</span>
                            <span className="text-purple-400 font-bold">
                                {((summary.by_gateway?.VIETQR?.count || 0) + (summary.by_gateway?.VNPAY?.count || 0))} lượt
                            </span>
                        </div>
                        <div className="mt-2 text-xl font-bold text-purple-300 font-mono">
                            {(Number(summary.by_gateway?.VIETQR?.total || 0) + Number(summary.by_gateway?.VNPAY?.total || 0)).toLocaleString('vi-VN')} đ
                        </div>
                        <p className="mt-1 text-xs text-slate-500">Quét mã QR tự động</p>
                    </div>
                </div>
            )}

            {/* BỘ LỌC TÌM KIẾM NÂNG CAO */}
            <div className="bg-slate-900/80 border border-slate-800/80 p-5 rounded-2xl shadow-xl space-y-4">
                <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-4">
                    {/* Ô tìm kiếm */}
                    <div className="relative flex-1">
                        <Search className="w-4 h-4 absolute left-3.5 top-3.5 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Tìm theo mã giao dịch, số căn hộ, mã biên lai..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full bg-slate-950 border border-slate-800 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 font-medium"
                        />
                    </div>

                    {/* Phương thức thanh toán */}
                    <div className="flex items-center gap-2">
                        <select
                            value={gatewayFilter}
                            onChange={(e) => setGatewayFilter(e.target.value)}
                            className="bg-slate-950 border border-slate-800 text-xs text-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:border-indigo-500"
                        >
                            <option value="ALL">Tất Cả Phương Thức</option>
                            <option value="CASH">Tiền Mặt (CASH)</option>
                            <option value="BANK_TRANSFER">Chuyển Khoản Ngân Hàng</option>
                            <option value="VIETQR">Mã VietQR</option>
                            <option value="VNPAY">Ví VNPay</option>
                            <option value="MOMO">Ví MoMo</option>
                        </select>

                        {/* Lọc Khối */}
                        <select
                            value={blockFilter}
                            onChange={(e) => setBlockFilter(e.target.value)}
                            className="bg-slate-950 border border-slate-800 text-xs text-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:border-indigo-500"
                        >
                            <option value="">Tất Cả Khối Tòa</option>
                            {blocks.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.block_name} ({b.block_code})
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Khoảng ngày & Kỳ phí */}
                <div className="flex flex-wrap items-center gap-3 pt-3 border-t border-slate-800/60 text-xs">
                    <span className="text-slate-400 font-semibold flex items-center gap-1.5">
                        <Calendar className="w-3.5 h-3.5 text-indigo-400" />
                        <span>Thời gian giao dịch:</span>
                    </span>

                    <input
                        type="date"
                        value={dateFrom}
                        onChange={(e) => setDateFrom(e.target.value)}
                        placeholder="Từ ngày"
                        className="bg-slate-950 border border-slate-800 rounded-xl px-3 py-1.5 text-slate-300 focus:outline-none focus:border-indigo-500"
                    />
                    <span className="text-slate-500">-</span>
                    <input
                        type="date"
                        value={dateTo}
                        onChange={(e) => setDateTo(e.target.value)}
                        placeholder="Đến ngày"
                        className="bg-slate-950 border border-slate-800 rounded-xl px-3 py-1.5 text-slate-300 focus:outline-none focus:border-indigo-500"
                    />

                    <span className="text-slate-500 mx-2">|</span>

                    <span className="text-slate-400 font-semibold">Kỳ hóa đơn:</span>
                    <input
                        type="month"
                        value={billingPeriodFilter}
                        onChange={(e) => setBillingPeriodFilter(e.target.value)}
                        className="bg-slate-950 border border-slate-800 rounded-xl px-3 py-1.5 text-slate-300 focus:outline-none focus:border-indigo-500"
                    />

                    {(dateFrom || dateTo || billingPeriodFilter || searchQuery || gatewayFilter !== 'ALL' || blockFilter) && (
                        <button
                            type="button"
                            onClick={() => {
                                setDateFrom('');
                                setDateTo('');
                                setBillingPeriodFilter('');
                                setSearchQuery('');
                                setGatewayFilter('ALL');
                                setBlockFilter('');
                            }}
                            className="ml-auto text-indigo-400 hover:text-indigo-300 font-semibold hover:underline"
                        >
                            Xóa bộ lọc
                        </button>
                    )}
                </div>
            </div>

            {/* BẢNG DANH SÁCH LỊCH SỬ GIAO DỊCH */}
            <div className="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-slate-950/80 border-b border-slate-800 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                <th className="py-3.5 px-4">Mã Giao Dịch</th>
                                <th className="py-3.5 px-4">Hóa Đơn / Kỳ</th>
                                <th className="py-3.5 px-4">Căn Hộ</th>
                                <th className="py-3.5 px-4">Người Nộp Tiền</th>
                                <th className="py-3.5 px-4 text-right">Số Tiền Đã Thu</th>
                                <th className="py-3.5 px-4 text-center">Phương Thức</th>
                                <th className="py-3.5 px-4">Thời Gian Giao Dịch</th>
                                <th className="py-3.5 px-4">Mã Biên Lai</th>
                                <th className="py-3.5 px-4 text-right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-800/60 text-xs">
                            {loading ? (
                                <tr>
                                    <td colSpan={9} className="py-16 text-center text-slate-400">
                                        <RefreshCw className="w-8 h-8 animate-spin mx-auto text-indigo-500 mb-2" />
                                        <span>Đang tải lịch sử giao dịch...</span>
                                    </td>
                                </tr>
                            ) : payments.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="py-16 text-center text-slate-500">
                                        Không tìm thấy giao dịch nào phù hợp với bộ lọc.
                                    </td>
                                </tr>
                            ) : (
                                payments.map((p) => (
                                    <tr key={p.id} className="hover:bg-slate-800/40 transition-colors">
                                        {/* Mã giao dịch */}
                                        <td className="py-3 px-4 font-mono font-bold text-indigo-300">
                                            {p.payment_reference_code}
                                            {p.gateway_transaction_id && (
                                                <div className="text-[10px] text-slate-500 font-mono">
                                                    Ref: {p.gateway_transaction_id}
                                                </div>
                                            )}
                                        </td>

                                        {/* Hóa đơn & Kỳ */}
                                        <td className="py-3 px-4">
                                            <div className="font-mono font-semibold text-white">
                                                {p.invoice?.invoice_number || 'N/A'}
                                            </div>
                                            <div className="text-[11px] text-indigo-400 font-mono">
                                                Kỳ: {p.invoice?.billing_period || 'N/A'}
                                            </div>
                                        </td>

                                        {/* Căn hộ */}
                                        <td className="py-3 px-4">
                                            <div className="font-bold text-white flex items-center gap-1.5">
                                                <Building2 className="w-3.5 h-3.5 text-indigo-400" />
                                                <span>{p.apartment?.apartment_number || 'N/A'}</span>
                                            </div>
                                            <div className="text-[11px] text-slate-500">
                                                {p.apartment?.block?.block_name || ''}
                                            </div>
                                        </td>

                                        {/* Người nộp */}
                                        <td className="py-3 px-4">
                                            <div className="font-semibold text-slate-200">
                                                {p.payer_user?.full_name || 'Cư dân căn hộ'}
                                            </div>
                                        </td>

                                        {/* Số tiền */}
                                        <td className="py-3 px-4 text-right font-mono font-bold text-emerald-400 text-sm">
                                            +{Number(p.amount_paid).toLocaleString('vi-VN')} đ
                                        </td>

                                        {/* Phương thức */}
                                        <td className="py-3 px-4 text-center">
                                            {renderGatewayBadge(p.payment_gateway)}
                                        </td>

                                        {/* Thời gian */}
                                        <td className="py-3 px-4 font-mono text-slate-400 text-[11px]">
                                            {p.payment_time ? p.payment_time.substring(0, 19).replace('T', ' ') : p.created_at.substring(0, 19).replace('T', ' ')}
                                        </td>

                                        {/* Mã biên lai */}
                                        <td className="py-3 px-4 font-mono text-indigo-300">
                                            {p.receipt?.receipt_number || (
                                                <span className="text-slate-600 italic">Chưa cấp</span>
                                            )}
                                        </td>

                                        {/* Thao tác */}
                                        <td className="py-3 px-4 text-right">
                                            {p.receipt ? (
                                                <button
                                                    type="button"
                                                    onClick={() => handleViewReceipt(p.id)}
                                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 border border-emerald-500/20 text-xs font-semibold transition-colors"
                                                    title="Xem và in biên lai thu tiền"
                                                >
                                                    <Printer className="w-3.5 h-3.5" />
                                                    <span>Biên Lai</span>
                                                </button>
                                            ) : (
                                                <span className="text-slate-600">-</span>
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
                    <div className="flex items-center justify-between p-4 bg-slate-950/70 border-t border-slate-800 text-xs text-slate-400">
                        <div>
                            Hiển thị trang <strong>{pagination.current_page}</strong> / <strong>{pagination.last_page}</strong> ({pagination.total} giao dịch)
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => loadData(pagination.current_page - 1)}
                                disabled={pagination.current_page <= 1}
                                className="p-2 rounded-lg bg-slate-900 border border-slate-800 hover:bg-slate-800 disabled:opacity-30 disabled:pointer-events-none transition-colors"
                            >
                                <ChevronLeft className="w-4 h-4" />
                            </button>
                            <span className="px-2 font-mono">{pagination.current_page}</span>
                            <button
                                type="button"
                                onClick={() => loadData(pagination.current_page + 1)}
                                disabled={pagination.current_page >= 1}
                                className="p-2 rounded-lg bg-slate-900 border border-slate-800 hover:bg-slate-800 disabled:opacity-30 disabled:pointer-events-none transition-colors"
                            >
                                <ChevronRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* MODAL XEM & IN BIÊN LAI THU TIỀN */}
            {activeReceiptData && (
                <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        <div className="p-5 bg-gradient-to-r from-emerald-950/40 to-slate-900 border-b border-slate-800 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    <CheckCircle2 className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-white">Biên Lai Thu Tiền Điện Tử</h3>
                                    <p className="text-xs text-slate-400 font-mono">
                                        Mã BL: {activeReceiptData.receipt?.receipt_number}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setActiveReceiptData(null)}
                                className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="p-6 space-y-4 text-xs bg-slate-900">
                            <div className="p-5 bg-slate-950 rounded-2xl border border-slate-800 space-y-3 font-mono">
                                <div className="text-center pb-3 border-b border-slate-800">
                                    <h4 className="font-bold text-white uppercase text-sm tracking-wider">
                                        BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART
                                    </h4>
                                    <p className="text-[11px] text-slate-400">BIÊN LAI THU TIỀN DỊCH VỤ TÒA NHÀ</p>
                                    <p className="text-[10px] text-slate-500">
                                        Số: <strong className="text-indigo-400">{activeReceiptData.receipt?.receipt_number}</strong> • Ngày lập: {activeReceiptData.receipt?.receipt_date}
                                    </p>
                                </div>

                                <div className="space-y-1.5 text-xs text-slate-300 font-sans">
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Người nộp tiền:</span>
                                        <strong className="text-white">{activeReceiptData.receipt?.received_from_name}</strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Hóa đơn gạch nợ:</span>
                                        <strong className="text-indigo-300 font-mono">{activeReceiptData.invoice?.invoice_number}</strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Căn hộ:</span>
                                        <strong className="text-white">
                                            {activeReceiptData.invoice?.apartment?.apartment_number} ({activeReceiptData.invoice?.apartment?.block?.block_name})
                                        </strong>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Phương thức:</span>
                                        <strong className="text-emerald-400">{activeReceiptData.payment?.payment_gateway}</strong>
                                    </div>
                                    <div className="flex justify-between pt-2 border-t border-slate-800">
                                        <span className="text-slate-400">Số tiền đã nộp:</span>
                                        <strong className="text-base text-emerald-400 font-mono">
                                            {Number(activeReceiptData.receipt?.amount).toLocaleString('vi-VN')} đ
                                        </strong>
                                    </div>
                                    <div className="bg-slate-900/80 p-2.5 rounded-lg border border-slate-800/80">
                                        <span className="text-[11px] text-slate-400">Bằng chữ: </span>
                                        <em className="text-slate-200 font-serif font-semibold">{activeReceiptData.receipt?.amount_in_words}</em>
                                    </div>
                                </div>

                                {/* Chữ ký số điện tử */}
                                <div className="pt-2 border-t border-slate-800/80 text-[10px] text-slate-500 space-y-1">
                                    <div className="flex items-center gap-1.5 text-emerald-400 font-semibold">
                                        <Sparkles className="w-3 h-3" />
                                        <span>Chứng chỉ xác thực số điện tử bảo chứng</span>
                                    </div>
                                    <p className="font-mono break-all text-slate-400 bg-slate-900 p-2 rounded border border-slate-800">
                                        SHA256: {activeReceiptData.receipt?.digital_signature_hash}
                                    </p>
                                </div>
                            </div>
                        </div>

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
                                onClick={() => setActiveReceiptData(null)}
                                className="px-5 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-500 transition-colors"
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
