import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
    Banknote,
    QrCode,
    CreditCard,
    Receipt,
    CheckCircle2,
    Clock,
    AlertCircle,
    Search,
    RefreshCw,
    Printer,
    ArrowRight,
    User,
    Building2,
    Calendar,
    Phone,
    FileText,
    Copy,
    Check,
    X,
    Filter,
    ShieldCheck
} from 'lucide-react';
import { invoiceApi, InvoiceModel } from '../../Services/invoiceApi';

interface PaymentCollectionPageProps {
    onBackToList?: () => void;
    initialInvoiceId?: string;
}

export const PaymentCollectionPage: React.FC<PaymentCollectionPageProps> = ({
    onBackToList,
    initialInvoiceId,
}) => {
    // State cho danh sách hóa đơn chờ thu
    const [invoices, setInvoices] = useState<InvoiceModel[]>([]);
    const [loadingInvoices, setLoadingInvoices] = useState<boolean>(false);
    const [searchTerm, setSearchTerm] = useState<string>('');
    const [filterBlock, setFilterBlock] = useState<string>('');

    // Hóa đơn được chọn để thu tiền
    const [selectedInvoice, setSelectedInvoice] = useState<InvoiceModel | null>(null);
    const [loadingDetail, setLoadingDetail] = useState<boolean>(false);

    // Form thu tiền
    const [amount, setAmount] = useState<number>(0);
    const [paymentMethod, setPaymentMethod] = useState<'CASH' | 'VIETQR' | 'POS'>('CASH');
    const [payerName, setPayerName] = useState<string>('');
    const [payerPhone, setPayerPhone] = useState<string>('');
    const [notes, setNotes] = useState<string>('');
    const [submitting, setSubmitting] = useState<boolean>(false);

    // VietQR Payload
    const [vietQrData, setVietQrData] = useState<any | null>(null);
    const [loadingQr, setLoadingQr] = useState<boolean>(false);
    const [copiedContent, setCopiedContent] = useState<boolean>(false);

    // Biên lai vừa lập
    const [createdReceipt, setCreatedReceipt] = useState<any | null>(null);
    const [receiptModalOpen, setReceiptModalOpen] = useState<boolean>(false);

    // Lịch sử thu tiền gần đây trong ngày
    const [recentPayments, setRecentPayments] = useState<any[]>([]);
    const [loadingRecent, setLoadingRecent] = useState<boolean>(false);

    // Toast thông báo
    const [toastMessage, setToastMessage] = useState<string | null>(null);

    const showToast = (msg: string) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 3500);
    };

    // Tải danh sách hóa đơn có dư nợ (Chờ thanh toán, Nợ một phần, Quá hạn)
    const loadPendingInvoices = useCallback(async () => {
        setLoadingInvoices(true);
        try {
            const res = await invoiceApi.getInvoices({
                per_page: 50,
                sort_by: 'issue_date',
                sort_order: 'desc',
            });
            const rawList = Array.isArray(res.data) ? res.data : (res.data?.data || []);
            if (rawList.length > 0) {
                // Lọc các hóa đơn có dư nợ > 0 và chưa bị hủy
                const pending = rawList
                    .map((inv: InvoiceModel) => {
                        const balance = Number(inv.remaining_balance ?? (Number(inv.total_amount || 0) - Number(inv.paid_amount || 0)));
                        return { ...inv, remaining_balance: balance };
                    })
                    .filter(
                        (inv: InvoiceModel) =>
                            inv.status !== 'CANCELLED' &&
                            inv.status !== 'PAID' &&
                            Number(inv.remaining_balance) > 0
                    );
                setInvoices(pending);

                // Tự động chọn hóa đơn để thu ngân có thể thu ngay
                if (pending.length > 0) {
                    if (initialInvoiceId) {
                        const found = pending.find((inv: InvoiceModel) => inv.id === initialInvoiceId);
                        handleSelectInvoice(found || pending[0]);
                    } else {
                        handleSelectInvoice(pending[0]);
                    }
                }
            }
        } catch (err) {
            console.error('Lỗi khi tải danh sách hóa đơn chờ thu:', err);
        } finally {
            setLoadingInvoices(false);
        }
    }, [initialInvoiceId]);

    // Tải lịch sử các lượt thu tiền gần nhất
    const loadRecentPayments = useCallback(async () => {
        setLoadingRecent(true);
        try {
            const res = await invoiceApi.getPaymentHistory({ per_page: 10 });
            if (res.success && res.data?.data) {
                setRecentPayments(res.data.data);
            }
        } catch (err) {
            console.error('Lỗi tải lịch sử thu tiền:', err);
        } finally {
            setLoadingRecent(false);
        }
    }, []);

    useEffect(() => {
        loadPendingInvoices();
        loadRecentPayments();
    }, [loadPendingInvoices, loadRecentPayments]);

    // Khi chọn một hóa đơn để thu tiền
    const handleSelectInvoice = async (inv: InvoiceModel) => {
        setSelectedInvoice(inv);
        const remBalance = Number(inv.remaining_balance || inv.total_amount || 0);
        setAmount(remBalance); // Mặc định thu 100% dư nợ
        setPayerName(inv.resident_user?.full_name || '');
        setPayerPhone(inv.resident_user?.phone_number || '');
        setNotes(`Thu tiền hóa đơn ${inv.invoice_number} căn hộ ${inv.apartment?.apartment_number || ''}`);

        // Nếu đang chọn VietQR, tự động lấy payload QR
        if (paymentMethod === 'VIETQR') {
            loadVietQr(inv.id);
        }
    };

    // Tải thông tin VietQR động
    const loadVietQr = async (invoiceId: string) => {
        setLoadingQr(true);
        try {
            const res = await invoiceApi.getVietQrPayload(invoiceId);
            if (res.success && res.data) {
                setVietQrData(res.data);
            }
        } catch (err: any) {
            showToast(err.message || 'Không thể tạo mã VietQR');
        } finally {
            setLoadingQr(false);
        }
    };

    // Đổi phương thức thanh toán
    const handleMethodChange = (method: 'CASH' | 'VIETQR' | 'POS') => {
        setPaymentMethod(method);
        if (method === 'VIETQR' && selectedInvoice) {
            loadVietQr(selectedInvoice.id);
        }
    };

    // Tính toán số dư nợ còn lại sau thanh toán
    const currentDebt = selectedInvoice ? Number(selectedInvoice.remaining_balance || 0) : 0;
    const remainingAfterPayment = Math.max(0, currentDebt - amount);
    const willBeFullyPaid = remainingAfterPayment === 0;

    // Xử lý nộp tiền & gạch nợ
    const handleSubmitPayment = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedInvoice) {
            showToast('Vui lòng chọn một hóa đơn cần thu tiền.');
            return;
        }

        if (amount <= 0) {
            showToast('Số tiền thu phải lớn hơn 0 đ.');
            return;
        }

        if (amount > currentDebt) {
            showToast(`Số tiền thu không được vượt quá dư nợ còn lại (${currentDebt.toLocaleString('vi-VN')} đ).`);
            return;
        }

        setSubmitting(true);
        try {
            const payload = {
                invoice_id: selectedInvoice.id,
                amount: amount,
                payment_method: paymentMethod === 'VIETQR' ? 'VIETQR_BANK_TRANSFER' : paymentMethod,
                notes: notes,
                idempotency_key: `COLLECT_${selectedInvoice.id}_${Date.now()}`,
            };

            const res = await invoiceApi.collectPayment(payload);

            if (res.success) {
                showToast(`Gạch nợ thành công! ${willBeFullyPaid ? 'Hóa đơn đã thanh toán hoàn tất.' : 'Đã cập nhật số dư nợ còn lại.'}`);
                setCreatedReceipt({
                    receipt: res.data?.receipt,
                    payment: res.data?.payment,
                    invoice: res.data?.invoice || selectedInvoice,
                });
                setReceiptModalOpen(true);

                // Cập nhật lại danh sách hóa đơn và lịch sử giao dịch
                loadPendingInvoices();
                loadRecentPayments();

                // Reset selection
                setSelectedInvoice(null);
                setAmount(0);
                setVietQrData(null);
            }
        } catch (err: any) {
            showToast(err.message || 'Lỗi khi xử lý thu tiền');
        } finally {
            setSubmitting(false);
        }
    };

    // Xem lại biên lai từ bảng lịch sử
    const handleViewReceipt = async (paymentId: string) => {
        try {
            const res = await invoiceApi.getReceiptDetail(paymentId);
            if (res.success && res.data) {
                setCreatedReceipt(res.data);
                setReceiptModalOpen(true);
            }
        } catch (err) {
            showToast('Không thể tải chi tiết biên lai');
        }
    };

    // Sao chép nội dung chuyển khoản VietQR
    const handleCopyContent = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopiedContent(true);
        showToast('Đã sao chép nội dung chuyển khoản!');
        setTimeout(() => setCopiedContent(false), 2000);
    };

    // In biên lai
    const handlePrintReceipt = () => {
        window.print();
    };

    // Hóa đơn được lọc theo từ khóa
    const filteredInvoices = useMemo(() => {
        return invoices.filter((inv) => {
            const matchSearch =
                !searchTerm ||
                inv.invoice_number?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                inv.apartment?.apartment_number?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                inv.resident_user?.full_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                inv.resident_user?.phone_number?.includes(searchTerm);

            const matchBlock =
                !filterBlock || inv.apartment?.block?.id === filterBlock;

            return matchSearch && matchBlock;
        });
    }, [invoices, searchTerm, filterBlock]);

    // Thống kê nhanh gọn gàng cho quầy thu ngân
    const totalPendingDebt = useMemo(() => {
        return invoices.reduce((sum, inv) => sum + Number(inv.remaining_balance || 0), 0);
    }, [invoices]);

    const totalCollectedToday = useMemo(() => {
        return recentPayments.reduce((sum, p) => sum + Number(p.amount_paid || p.amount || 0), 0);
    }, [recentPayments]);

    return (
        <div className="max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 animate-in fade-in duration-200">
            {/* TOAST THÔNG BÁO */}
            {toastMessage && (
                <div className="fixed top-5 right-5 z-50 bg-white border border-slate-200 text-slate-800 px-4 py-3 rounded-xl shadow-xl flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                    <span className="text-xs font-semibold">{toastMessage}</span>
                </div>
            )}

            {/* HEADER CHỨC NĂNG 8 */}
            <div className="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div className="space-y-1">
                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <Banknote className="w-3.5 h-3.5 text-emerald-600" />
                        <span>CHỨC NĂNG 8 · QUẦY THU TIỀN & GẠCH NỢ TỨC THỜI</span>
                    </div>
                    <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                        Thu Tiền & Gạch Nợ Hóa Đơn Trực Tiếp
                    </h1>
                    <p className="text-sm text-slate-500 max-w-3xl">
                        Thu ngân Ban Quản Lý ghi nhận nộp tiền mặt, quét mã VietQR động NAPAS chuẩn xác theo số dư nợ, gạch nợ tự động tức thời và xuất biên lai điện tử cho cư dân.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => {
                            loadPendingInvoices();
                            loadRecentPayments();
                            showToast('Đã làm mới dữ liệu hóa đơn và phiếu thu');
                        }}
                        disabled={loadingInvoices}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-2xs text-xs font-bold transition-colors cursor-pointer"
                    >
                        <RefreshCw className={`w-4 h-4 text-slate-500 ${loadingInvoices ? 'animate-spin text-emerald-600' : ''}`} />
                        <span>Làm Mới</span>
                    </button>

                    {onBackToList && (
                        <button
                            type="button"
                            onClick={onBackToList}
                            className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 shadow-2xs text-xs font-bold transition-colors cursor-pointer"
                        >
                            <span>Danh Sách Hóa Đơn (CN7)</span>
                        </button>
                    )}
                </div>
            </div>

            {/* 4 THẺ THỐNG KÊ QUẦY THU NGÂN GỌN GÀNG (LIGHT THEME) */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="bg-white border border-slate-200/90 p-4 rounded-xl shadow-xs">
                    <div className="flex items-center justify-between text-xs font-semibold text-slate-500">
                        <span>HÓA ĐƠN CHỜ THU</span>
                        <div className="p-1.5 rounded-lg bg-amber-50 text-amber-600 border border-amber-100">
                            <Clock className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-extrabold text-slate-900 mt-1.5">
                        {invoices.length} <span className="text-xs font-sans font-normal text-slate-400">hóa đơn</span>
                    </div>
                    <div className="text-[11px] text-amber-600 font-medium mt-1">
                        Cần đôn đốc nộp phí dịch vụ
                    </div>
                </div>

                <div className="bg-white border border-slate-200/90 p-4 rounded-xl shadow-xs">
                    <div className="flex items-center justify-between text-xs font-semibold text-slate-500">
                        <span>TỔNG DƯ NỢ CẦN THU</span>
                        <div className="p-1.5 rounded-lg bg-rose-50 text-rose-600 border border-rose-100">
                            <AlertCircle className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-extrabold text-rose-600 mt-1.5">
                        {totalPendingDebt.toLocaleString('vi-VN')} <span className="text-xs font-sans font-normal text-slate-400">đ</span>
                    </div>
                    <div className="text-[11px] text-slate-400 mt-1">
                        Dư nợ tồn đọng toàn bộ căn hộ
                    </div>
                </div>

                <div className="bg-white border border-slate-200/90 p-4 rounded-xl shadow-xs">
                    <div className="flex items-center justify-between text-xs font-semibold text-slate-500">
                        <span>ĐÃ THU TRONG CA HÔM NAY</span>
                        <div className="p-1.5 rounded-lg bg-emerald-50 text-emerald-600 border border-emerald-100">
                            <CheckCircle2 className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-extrabold text-emerald-600 mt-1.5">
                        {totalCollectedToday.toLocaleString('vi-VN')} <span className="text-xs font-sans font-normal text-slate-400">đ</span>
                    </div>
                    <div className="text-[11px] text-emerald-700 font-medium mt-1">
                        {recentPayments.length} giao dịch thành công
                    </div>
                </div>

                <div className="bg-white border border-slate-200/90 p-4 rounded-xl shadow-xs">
                    <div className="flex items-center justify-between text-xs font-semibold text-slate-500">
                        <span>HÌNH THỨC GẠCH NỢ</span>
                        <div className="p-1.5 rounded-lg bg-sky-50 text-sky-600 border border-sky-100">
                            <ShieldCheck className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-base font-bold text-slate-800 mt-1.5 flex items-center gap-1.5">
                        <span>Tiền Mặt & VietQR</span>
                    </div>
                    <div className="text-[11px] text-sky-700 font-medium mt-1">
                        Gạch nợ tức thời + sinh biên lai
                    </div>
                </div>
            </div>

            {/* BỐ CỤC CHÍNH QUẦY THU NGÂN: 2 CỘT */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                {/* CỘT TRÁI (7 CỘT): QUẦY LẬP PHIẾU THU & GẠCH NỢ TỨC THỜI */}
                <div className="lg:col-span-7 bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs space-y-6">
                    <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                        <div className="flex items-center gap-2">
                            <div className="p-2 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100">
                                <Receipt className="w-5 h-5" />
                            </div>
                            <div>
                                <h2 className="text-base font-bold text-slate-900">
                                    Quầy Lập Phiếu Thu & Gạch Nợ
                                </h2>
                                <p className="text-xs text-slate-500">
                                    Chọn hóa đơn bên phải để nạp thông tin hoặc quét mã nộp tiền
                                </p>
                            </div>
                        </div>

                        {selectedInvoice && (
                            <button
                                type="button"
                                onClick={() => setSelectedInvoice(null)}
                                className="px-3 py-1 rounded-lg text-xs font-semibold text-slate-500 hover:text-rose-600 hover:bg-rose-50 transition-colors"
                            >
                                Hủy chọn HĐ
                            </button>
                        )}
                    </div>

                    {!selectedInvoice ? (
                        /* Trạng thái chưa chọn hóa đơn */
                        <div className="py-14 text-center space-y-3 border-2 border-dashed border-slate-200 rounded-2xl bg-slate-50/50">
                            <Receipt className="w-12 h-12 mx-auto text-slate-300" />
                            <div className="space-y-1">
                                <h3 className="text-sm font-bold text-slate-700">
                                    Chưa chọn hóa đơn cần thu tiền
                                </h3>
                                <p className="text-xs text-slate-400 max-w-sm mx-auto">
                                    Vui lòng nhấn vào nút <strong>"Thu Tiền"</strong> của một hóa đơn ở danh sách bên phải để bắt đầu lập phiếu thu.
                                </p>
                            </div>
                        </div>
                    ) : (
                        /* Form thu tiền của hóa đơn đã chọn */
                        <form onSubmit={handleSubmitPayment} className="space-y-5">
                            {/* Card thông tin hóa đơn được chọn */}
                            <div className="bg-slate-50 border border-slate-200/80 rounded-xl p-4 space-y-3">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <Building2 className="w-4 h-4 text-indigo-600" />
                                        <span className="font-bold text-slate-900 text-sm">
                                            Căn hộ {selectedInvoice.apartment?.apartment_number}
                                        </span>
                                        <span className="text-xs text-slate-500 font-normal">
                                            ({selectedInvoice.apartment?.block?.block_name || 'Khối nhà'})
                                        </span>
                                    </div>
                                    <span className="font-mono text-xs font-bold text-indigo-700 bg-indigo-50 px-2.5 py-0.5 rounded-full border border-indigo-200">
                                        {selectedInvoice.invoice_number}
                                    </span>
                                </div>

                                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs pt-1 border-t border-slate-200/60">
                                    <div>
                                        <span className="text-slate-400 block text-[11px]">Chủ hộ / Cư dân:</span>
                                        <span className="font-semibold text-slate-800">
                                            {selectedInvoice.resident_user?.full_name || '---'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-slate-400 block text-[11px]">Kỳ thanh toán:</span>
                                        <span className="font-semibold text-slate-800 font-mono">
                                            {selectedInvoice.billing_period || '---'}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-slate-400 block text-[11px]">Hạn nộp:</span>
                                        <span className="font-semibold text-slate-800">
                                            {selectedInvoice.due_date ? new Date(selectedInvoice.due_date).toLocaleDateString('vi-VN') : '---'}
                                        </span>
                                    </div>
                                </div>

                                {/* Khung số dư nợ nổi bật */}
                                <div className="grid grid-cols-3 gap-2 p-3 rounded-lg bg-white border border-slate-200 text-xs">
                                    <div>
                                        <span className="text-slate-400 block text-[10px] uppercase font-bold">Tổng Phát Hành</span>
                                        <span className="font-mono font-bold text-slate-700">
                                            {Number(selectedInvoice.total_amount || 0).toLocaleString('vi-VN')} đ
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-slate-400 block text-[10px] uppercase font-bold">Đã Thanh Toán</span>
                                        <span className="font-mono font-bold text-emerald-600">
                                            {Number(selectedInvoice.paid_amount || 0).toLocaleString('vi-VN')} đ
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-rose-600 block text-[10px] uppercase font-bold">Dư Nợ Còn Lại</span>
                                        <span className="font-mono font-extrabold text-rose-600 text-sm">
                                            {currentDebt.toLocaleString('vi-VN')} đ
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Ô nhập số tiền và phím bấm nhanh */}
                            <div className="space-y-2">
                                <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider">
                                    Số Tiền Thu (VNĐ) <span className="text-rose-500">*</span>
                                </label>
                                <div className="relative">
                                    <input
                                        type="number"
                                        min={1}
                                        max={currentDebt}
                                        value={amount || ''}
                                        onChange={(e) => setAmount(Number(e.target.value))}
                                        placeholder="Nhập số tiền..."
                                        className="w-full bg-white border border-slate-300 rounded-xl pl-4 pr-14 py-3 text-base font-mono font-extrabold text-slate-900 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 shadow-2xs no-spinner [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                                        required
                                    />
                                    <span className="absolute right-4 top-1/2 -translate-y-1/2 font-bold text-xs text-slate-400 pointer-events-none select-none">
                                        VNĐ
                                    </span>
                                </div>

                                {/* Nút phím tắt chọn nhanh số tiền */}
                                <div className="flex flex-wrap gap-2 pt-1">
                                    <button
                                        type="button"
                                        onClick={() => setAmount(currentDebt)}
                                        className="px-3 py-1 rounded-lg text-xs font-bold bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 transition-colors"
                                    >
                                        Thu hết 100% ({currentDebt.toLocaleString('vi-VN')} đ)
                                    </button>
                                    {currentDebt > 100000 && (
                                        <button
                                            type="button"
                                            onClick={() => setAmount(Math.round(currentDebt / 2))}
                                            className="px-3 py-1 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 transition-colors"
                                        >
                                            50% dư nợ ({Math.round(currentDebt / 2).toLocaleString('vi-VN')} đ)
                                        </button>
                                    )}
                                    {currentDebt >= 1000000 && (
                                        <button
                                            type="button"
                                            onClick={() => setAmount(1000000)}
                                            className="px-3 py-1 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 transition-colors"
                                        >
                                            1.000.000 đ
                                        </button>
                                    )}
                                    {currentDebt >= 2000000 && (
                                        <button
                                            type="button"
                                            onClick={() => setAmount(2000000)}
                                            className="px-3 py-1 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 transition-colors"
                                        >
                                            2.000.000 đ
                                        </button>
                                    )}
                                </div>

                                {/* Xem trước số dư nợ sau khi gạch nợ */}
                                <div className="p-2.5 rounded-lg bg-emerald-50/60 border border-emerald-200/80 text-xs flex items-center justify-between text-emerald-900">
                                    <span>Dư nợ còn lại sau thanh toán:</span>
                                    <span className="font-mono font-bold">
                                        {remainingAfterPayment.toLocaleString('vi-VN')} đ{' '}
                                        <span className="font-sans font-normal text-[11px] text-emerald-700">
                                            ({willBeFullyPaid ? 'Đã hoàn tất 100%' : 'Thanh toán một phần'})
                                        </span>
                                    </span>
                                </div>
                            </div>

                            {/* Chọn phương thức thanh toán */}
                            <div className="space-y-2">
                                <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider">
                                    Phương Thức Thanh Toán
                                </label>
                                <div className="grid grid-cols-3 gap-3">
                                    <button
                                        type="button"
                                        onClick={() => handleMethodChange('CASH')}
                                        className={`p-3 rounded-xl border text-left flex flex-col gap-1 transition-all cursor-pointer ${
                                            paymentMethod === 'CASH'
                                                ? 'bg-emerald-50 border-emerald-500 text-emerald-900 ring-2 ring-emerald-500/20'
                                                : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        <div className="flex items-center gap-1.5 font-bold text-xs">
                                            <Banknote className="w-4 h-4 text-emerald-600" />
                                            <span>Tiền Mặt</span>
                                        </div>
                                        <span className="text-[10px] text-slate-500">Tại quầy BQL</span>
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => handleMethodChange('VIETQR')}
                                        className={`p-3 rounded-xl border text-left flex flex-col gap-1 transition-all cursor-pointer ${
                                            paymentMethod === 'VIETQR'
                                                ? 'bg-sky-50 border-sky-500 text-sky-900 ring-2 ring-sky-500/20'
                                                : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        <div className="flex items-center gap-1.5 font-bold text-xs">
                                            <QrCode className="w-4 h-4 text-sky-600" />
                                            <span>VietQR Động</span>
                                        </div>
                                        <span className="text-[10px] text-slate-500">Chuyển khoản NAPAS</span>
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => handleMethodChange('POS')}
                                        className={`p-3 rounded-xl border text-left flex flex-col gap-1 transition-all cursor-pointer ${
                                            paymentMethod === 'POS'
                                                ? 'bg-indigo-50 border-indigo-500 text-indigo-900 ring-2 ring-indigo-500/20'
                                                : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        <div className="flex items-center gap-1.5 font-bold text-xs">
                                            <CreditCard className="w-4 h-4 text-indigo-600" />
                                            <span>Quẹt Thẻ POS</span>
                                        </div>
                                        <span className="text-[10px] text-slate-500">Thẻ ATM / Visa / Master</span>
                                    </button>
                                </div>
                            </div>

                            {/* Khối hiển thị mã VietQR nếu chọn VietQR */}
                            {paymentMethod === 'VIETQR' && (
                                <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
                                    <div className="flex items-center justify-between text-xs font-bold text-slate-700">
                                        <span className="flex items-center gap-1.5 text-sky-700">
                                            <QrCode className="w-4 h-4 text-sky-600" />
                                            <span>Mã VietQR Chuyển Khoản Tự Động Định Danh</span>
                                        </span>
                                        {loadingQr && <span className="text-slate-400 font-normal">Đang sinh mã QR...</span>}
                                    </div>

                                    <div className="flex flex-col sm:flex-row items-center gap-4 bg-white p-3 rounded-lg border border-slate-200">
                                        <div className="w-36 h-36 bg-white p-2 border border-slate-200 rounded-lg shrink-0 flex items-center justify-center">
                                            {vietQrData?.qr_image_url ? (
                                                <img
                                                    src={vietQrData.qr_image_url}
                                                    alt="VietQR Code"
                                                    className="w-full h-full object-contain"
                                                />
                                            ) : (
                                                <div className="text-center text-slate-400 text-xs">
                                                    <QrCode className="w-8 h-8 mx-auto mb-1 text-slate-300" />
                                                    <span>Đang tải QR...</span>
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex-1 space-y-2 text-xs w-full">
                                            <div>
                                                <span className="text-slate-400 text-[11px] block">Số tài khoản thụ hưởng:</span>
                                                <span className="font-mono font-bold text-slate-800 text-sm">
                                                    {vietQrData?.bank_account_number || '19036888899999'}
                                                </span>
                                                <span className="text-slate-500 text-[11px] ml-2">
                                                    ({vietQrData?.bank_account_name || 'BQL TOA NHA CASSAVAS'})
                                                </span>
                                            </div>

                                            <div>
                                                <span className="text-slate-400 text-[11px] block">Nội dung chuyển khoản (Định danh tự động gạch nợ):</span>
                                                <div className="flex items-center gap-2 mt-0.5">
                                                    <span className="font-mono font-bold text-indigo-700 bg-indigo-50 px-2 py-1 rounded border border-indigo-200 text-xs">
                                                        {vietQrData?.transfer_content || `HD ${selectedInvoice.invoice_number}`}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleCopyContent(vietQrData?.transfer_content || `HD ${selectedInvoice.invoice_number}`)}
                                                        className="p-1 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition-colors cursor-pointer"
                                                        title="Sao chép nội dung"
                                                    >
                                                        {copiedContent ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Copy className="w-3.5 h-3.5" />}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Thông tin người nộp & Ghi chú */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <div>
                                    <label className="block font-semibold text-slate-600 mb-1">Người Nộp Tiền</label>
                                    <input
                                        type="text"
                                        value={payerName}
                                        onChange={(e) => setPayerName(e.target.value)}
                                        placeholder="Họ và tên người nộp..."
                                        className="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-slate-800 focus:outline-none focus:border-emerald-500 shadow-2xs"
                                    />
                                </div>
                                <div>
                                    <label className="block font-semibold text-slate-600 mb-1">Số Điện Thoại</label>
                                    <input
                                        type="tel"
                                        value={payerPhone}
                                        onChange={(e) => setPayerPhone(e.target.value)}
                                        placeholder="Số điện thoại liên hệ..."
                                        className="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-slate-800 focus:outline-none focus:border-emerald-500 shadow-2xs"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-600 mb-1">Ghi Chú Thu Tiền</label>
                                <input
                                    type="text"
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    placeholder="Ghi chú phiếu thu (tùy chọn)..."
                                    className="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 focus:outline-none focus:border-emerald-500 shadow-2xs"
                                />
                            </div>

                            {/* Nút Submit Gạch Nợ Tức Thời */}
                            <button
                                type="submit"
                                disabled={submitting || amount <= 0}
                                className="w-full py-3.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-bold text-sm shadow-md shadow-emerald-600/20 hover:shadow-lg transition-all flex items-center justify-center gap-2 cursor-pointer"
                            >
                                {submitting ? (
                                    <>
                                        <RefreshCw className="w-4 h-4 animate-spin" />
                                        <span>Đang ghi nhận thanh toán & gạch nợ tức thời...</span>
                                    </>
                                ) : (
                                    <>
                                        <CheckCircle2 className="w-5 h-5" />
                                        <span>Xác Nhận Thu {amount.toLocaleString('vi-VN')} đ & Gạch Nợ Ngay</span>
                                    </>
                                )}
                            </button>
                        </form>
                    )}
                </div>

                {/* CỘT PHẢI (5 CỘT): DANH SÁCH HÓA ĐƠN CHỜ THU & BIÊN LAI GẦN ĐÂY */}
                <div className="lg:col-span-5 space-y-6">
                    {/* BẢNG HÓA ĐƠN CẦN THU TIỀN */}
                    <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs space-y-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Clock className="w-4 h-4 text-amber-600" />
                                <h3 className="font-bold text-slate-900 text-sm">
                                    Hóa Đơn Chờ Thu ({filteredInvoices.length})
                                </h3>
                            </div>
                            <span className="text-[11px] text-slate-400">Bấm để nạp quầy thu</span>
                        </div>

                        {/* Ô tìm kiếm nhanh */}
                        <div className="relative">
                            <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                type="text"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                placeholder="Tìm theo căn hộ, mã HĐ, tên cư dân..."
                                className="w-full pl-8 pr-3 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-lg text-slate-800 placeholder-slate-400 focus:outline-none focus:border-emerald-500 shadow-2xs"
                            />
                        </div>

                        {/* Danh sách cuộn các hóa đơn chờ thu */}
                        <div className="max-h-[380px] overflow-y-auto space-y-2 pr-1 text-xs">
                            {loadingInvoices ? (
                                <div className="py-8 text-center text-slate-400 text-xs">
                                    <RefreshCw className="w-4 h-4 animate-spin mx-auto mb-1 text-emerald-600" />
                                    <span>Đang nạp danh sách hóa đơn...</span>
                                </div>
                            ) : filteredInvoices.length === 0 ? (
                                <div className="py-8 text-center text-slate-400 text-xs">
                                    Không có hóa đơn nào có dư nợ cần thu.
                                </div>
                            ) : (
                                filteredInvoices.map((inv) => {
                                    const isSelected = selectedInvoice?.id === inv.id;
                                    const debt = Number(inv.remaining_balance || 0);

                                    return (
                                        <div
                                            key={inv.id}
                                            onClick={() => handleSelectInvoice(inv)}
                                            className={`p-3 rounded-xl border transition-all cursor-pointer flex items-center justify-between ${
                                                isSelected
                                                    ? 'bg-emerald-50/80 border-emerald-400 shadow-2xs'
                                                    : 'bg-white hover:bg-slate-50 border-slate-200'
                                            }`}
                                        >
                                            <div className="space-y-0.5">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-bold text-slate-900 text-xs">
                                                        Căn {inv.apartment?.apartment_number}
                                                    </span>
                                                    <span className="font-mono text-[10px] text-slate-500">
                                                        {inv.invoice_number}
                                                    </span>
                                                </div>
                                                <div className="text-[11px] text-slate-500">
                                                    {inv.resident_user?.full_name || 'Chủ hộ'} · Kỳ {inv.billing_period}
                                                </div>
                                            </div>

                                            <div className="text-right">
                                                <div className="font-mono font-bold text-rose-600 text-xs">
                                                    {debt.toLocaleString('vi-VN')} đ
                                                </div>
                                                <span className="inline-block mt-0.5 text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                                    Chọn Thu Tiền
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </div>

                    {/* BẢNG LỊCH SỬ CÁC LƯỢT GẠCH NỢ GẦN NHẤT */}
                    <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs space-y-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Receipt className="w-4 h-4 text-emerald-600" />
                                <h3 className="font-bold text-slate-900 text-sm">
                                    Biên Lai Vừa Thu Gần Đây
                                </h3>
                            </div>
                            <span className="text-[11px] text-slate-400">Xem & in biên lai</span>
                        </div>

                        <div className="max-h-[260px] overflow-y-auto space-y-2 pr-1 text-xs">
                            {loadingRecent ? (
                                <div className="py-6 text-center text-slate-400 text-xs">
                                    Đang tải lịch sử...
                                </div>
                            ) : recentPayments.length === 0 ? (
                                <div className="py-6 text-center text-slate-400 text-xs">
                                    Chưa có giao dịch thu tiền nào được ghi nhận.
                                </div>
                            ) : (
                                recentPayments.slice(0, 6).map((pm) => (
                                    <div
                                        key={pm.id}
                                        className="p-2.5 rounded-lg border border-slate-200 bg-slate-50/50 hover:bg-slate-50 flex items-center justify-between transition-colors"
                                    >
                                        <div className="space-y-0.5">
                                            <div className="flex items-center gap-1.5 font-bold text-slate-800 text-xs">
                                                <span>Căn {pm.apartment?.apartment_number || pm.apartment_number || '---'}</span>
                                                <span className="font-mono text-[10px] text-emerald-600">
                                                    +{Number(pm.amount_paid || pm.amount || 0).toLocaleString('vi-VN')} đ
                                                </span>
                                            </div>
                                            <div className="text-[10px] text-slate-500">
                                                {pm.payment_gateway || pm.payment_method} · {pm.created_at ? new Date(pm.created_at).toLocaleTimeString('vi-VN') : ''}
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => handleViewReceipt(pm.id)}
                                            className="px-2.5 py-1 rounded bg-white hover:bg-slate-100 text-sky-700 border border-sky-200 text-[11px] font-semibold transition-colors cursor-pointer"
                                        >
                                            In Biên Lai
                                        </button>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* MODAL BIÊN LAI THU TIỀN ĐIỆN TỬ SAU KHI GẠCH NỢ THÀNH CÔNG */}
            {receiptModalOpen && createdReceipt && (
                <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
                    <div className="bg-white border border-slate-200 rounded-2xl w-full max-w-2xl max-h-[92vh] flex flex-col shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                        {/* Header Biên Lai */}
                        <div className="p-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between shrink-0 rounded-t-2xl">
                            <div className="flex items-center gap-2">
                                <Receipt className="w-5 h-5 text-emerald-600" />
                                <span className="font-bold text-slate-900 text-sm">
                                    Biên Lai Thu Tiền Điện Tử Phí Dịch Vụ
                                </span>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReceiptModalOpen(false)}
                                className="p-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 transition-colors"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Nội dung Biên Lai chuẩn in A4 / A5 */}
                        <div className="p-6 overflow-y-auto flex-1 space-y-4 text-xs text-slate-800 font-sans" id="printable-receipt">
                            <div className="text-center pb-3 border-b border-slate-200 space-y-1">
                                <h3 className="text-base font-extrabold text-slate-900 uppercase tracking-wider">
                                    BIÊN LAI THU TIỀN ĐIỆN TỬ
                                </h3>
                                <p className="text-[11px] text-slate-500 font-medium">
                                    BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART BUILDING
                                </p>
                                <div className="font-mono text-xs text-indigo-700 font-bold">
                                    Số Phiếu Thu: {createdReceipt.receipt?.receipt_number || createdReceipt.receipt_number || `BL-${Date.now().toString().slice(-6)}`}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3 text-xs pt-2">
                                <div>
                                    <span className="text-slate-400 text-[11px] block">Người nộp tiền:</span>
                                    <span className="font-bold text-slate-800">
                                        {createdReceipt.receipt?.payer_name || payerName || createdReceipt.invoice?.resident_user?.full_name || 'Cư dân'}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-slate-400 text-[11px] block">Căn hộ:</span>
                                    <span className="font-bold text-slate-800">
                                        Phòng {createdReceipt.invoice?.apartment?.apartment_number || '---'}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-slate-400 text-[11px] block">Mã hóa đơn:</span>
                                    <span className="font-mono font-bold text-indigo-700">
                                        {createdReceipt.invoice?.invoice_number || '---'}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-slate-400 text-[11px] block">Kỳ thanh toán:</span>
                                    <span className="font-mono font-semibold text-slate-800">
                                        Tháng {createdReceipt.invoice?.billing_period || '---'}
                                    </span>
                                </div>
                            </div>

                            <div className="p-4 bg-emerald-50/60 rounded-xl border border-emerald-200 text-center space-y-1">
                                <span className="text-xs text-emerald-800 font-semibold block">SỐ TIỀN ĐÃ THU</span>
                                <div className="text-2xl font-mono font-extrabold text-emerald-700">
                                    {Number(createdReceipt.receipt?.amount_collected || createdReceipt.payment?.amount_paid || amount || 0).toLocaleString('vi-VN')} đ
                                </div>
                                <div className="text-[11px] text-slate-500 italic">
                                    Phương thức: {createdReceipt.payment?.payment_gateway || paymentMethod} · Gạch nợ lúc {new Date().toLocaleString('vi-VN')}
                                </div>
                            </div>

                            <div className="pt-4 border-t border-slate-200 flex items-center justify-between text-xs text-slate-500">
                                <div className="text-center">
                                    <span className="block font-semibold">Người Nộp Tiền</span>
                                    <span className="text-[10px] text-slate-400">(Ký & ghi rõ họ tên)</span>
                                </div>
                                <div className="text-center">
                                    <span className="block font-semibold">Thu Ngân / Ban Quản Lý</span>
                                    <span className="text-[10px] text-emerald-600 font-bold">[ ĐÃ XÁC THỰC SỐ ]</span>
                                </div>
                            </div>
                        </div>

                        {/* Footer nút in & đóng */}
                        <div className="p-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between rounded-b-2xl">
                            <button
                                type="button"
                                onClick={() => setReceiptModalOpen(false)}
                                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:text-slate-900 bg-white border border-slate-200 transition-colors"
                            >
                                Đóng
                            </button>

                            <button
                                type="button"
                                onClick={handlePrintReceipt}
                                className="inline-flex items-center gap-1.5 px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition-colors cursor-pointer"
                            >
                                <Printer className="w-4 h-4" />
                                <span>In Phiếu Thu / Biên Lai (A4)</span>
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default PaymentCollectionPage;
