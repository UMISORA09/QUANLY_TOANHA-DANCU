import React, { useState, useEffect, useCallback } from 'react';
import {
    FileSpreadsheet,
    Download,
    Printer,
    Calendar,
    Building2,
    Filter,
    RefreshCw,
    CheckCircle2,
    FileText,
    ExternalLink,
    DollarSign,
    AlertCircle,
    TrendingUp,
    Clock,
    PieChart,
    ChevronLeft
} from 'lucide-react';
import { invoiceApi } from '../../Services/invoiceApi';

interface BlockOption {
    id: string;
    block_code: string;
    block_name: string;
}

interface FinancialReportManagementProps {
    onNavigateTab?: (tabId: string) => void;
}

export const FinancialReportManagement: React.FC<FinancialReportManagementProps> = ({ onNavigateTab }) => {
    const currentPeriod = new Date().toISOString().slice(0, 7); // YYYY-MM
    const [period, setPeriod] = useState<string>(currentPeriod);
    const [blockId, setBlockId] = useState<string>('');
    const [status, setStatus] = useState<string>('ALL');
    const [reportType, setReportType] = useState<string>('debt_summary');

    const [blocks, setBlocks] = useState<BlockOption[]>([]);
    const [loading, setLoading] = useState<boolean>(false);
    const [previewData, setPreviewData] = useState<any | null>(null);
    const [toastMessage, setToastMessage] = useState<string | null>(null);

    const showToast = (text: string) => {
        setToastMessage(text);
        setTimeout(() => setToastMessage(null), 3500);
    };

    // Tải danh sách Block tòa nhà
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
                console.error('Lỗi khi tải danh sách khối tòa:', err);
            }
        };
        fetchBlocks();
    }, []);

    // Tải preview dữ liệu báo cáo
    const fetchPreview = useCallback(async () => {
        setLoading(true);
        try {
            const res = await invoiceApi.getFinancialReportPreview({
                period: period || undefined,
                block_id: blockId || undefined,
                status: status !== 'ALL' ? status : undefined,
                report_type: reportType,
            });
            if (res.success) {
                setPreviewData(res.data);
            } else {
                showToast((res as any).message || 'Không thể tải bản xem trước báo cáo');
            }
        } catch (err: any) {
            console.error('Lỗi khi tải preview báo cáo:', err);
            showToast('Lỗi kết nối khi tải bản xem trước');
        } finally {
            setLoading(false);
        }
    }, [period, blockId, status, reportType]);

    useEffect(() => {
        fetchPreview();
    }, [fetchPreview]);

    const formatVND = (val: number) => {
        return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val || 0);
    };

    // Xử lý xuất Excel
    const handleExportExcel = () => {
        try {
            showToast('Đang tạo và tải xuống file Excel báo cáo tài chính...');
            const url = invoiceApi.getFinancialReportExportExcelUrl({
                period: period || undefined,
                block_id: blockId || undefined,
                status: status !== 'ALL' ? status : undefined,
                report_type: reportType,
            });
            window.open(url, '_blank');
        } catch (err) {
            console.error(err);
            showToast('Lỗi khi xuất file Excel');
        }
    };

    // Xử lý xuất / In PDF
    const handleExportPdf = () => {
        try {
            showToast('Đang xuất bản in báo cáo PDF chuẩn A4...');
            const url = invoiceApi.getFinancialReportExportPdfUrl({
                period: period || undefined,
                block_id: blockId || undefined,
                status: status !== 'ALL' ? status : undefined,
                report_type: reportType,
            });
            window.open(url, '_blank');
        } catch (err) {
            console.error(err);
            showToast('Lỗi khi mở bản in PDF');
        }
    };

    const summary = previewData?.summary || {
        total_invoices: 0,
        total_billed: 0,
        total_collected: 0,
        total_debt: 0,
        collection_rate: 0,
    };
    const reportRows = previewData?.rows || previewData?.items || [];

    return (
        <div className="space-y-6">
            {/* Toast message */}
            {toastMessage && (
                <div className="fixed top-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 rounded-2xl shadow-xl border text-sm font-semibold bg-white text-emerald-800 border-emerald-200 animate-in fade-in slide-in-from-top-4 duration-300">
                    <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                    <span>{toastMessage}</span>
                </div>
            )}

            {/* Header Chức Năng */}
            <div className="bg-white border border-slate-200/90 rounded-3xl p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <div className="flex items-center gap-2.5 text-xs font-bold uppercase tracking-wider font-mono text-emerald-700 mb-1">
                        <FileSpreadsheet className="w-4 h-4 text-emerald-600" />
                        <span>CHỨC NĂNG 13 · KẾT XUẤT BÁO CÁO TÀI CHÍNH & CÔNG NỢ</span>
                        <span className="px-2 py-0.5 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-700 text-[10px]">
                            EXCEL & PDF A4
                        </span>
                    </div>
                    <h2 className="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                        Báo Cáo Tài Chính & Tổng Hợp Công Nợ
                    </h2>
                    <p className="text-sm text-slate-500 mt-1 max-w-3xl">
                        Tổng hợp doanh thu, tình hình thanh toán và phân tích chi tiết công nợ cư dân theo từng kỳ hóa đơn. Hỗ trợ kết xuất file Excel chuẩn kế toán và in ấn phiếu PDF khổ A4.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        onClick={handleExportExcel}
                        className="inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold shadow-xs transition-all duration-200 cursor-pointer"
                    >
                        <FileSpreadsheet className="w-4 h-4" />
                        <span>Xuất File Excel (.xlsx)</span>
                    </button>
                    <button
                        type="button"
                        onClick={handleExportPdf}
                        className="inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold shadow-xs transition-all duration-200 cursor-pointer"
                    >
                        <Printer className="w-4 h-4" />
                        <span>In / Xuất PDF (.pdf)</span>
                    </button>
                </div>
            </div>

            {/* BỘ LỌC THÔNG SỐ BÁO CÁO */}
            <div className="bg-white border border-slate-200/90 rounded-3xl p-5 shadow-xs">
                <div className="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
                    <div className="flex items-center gap-2 text-xs font-bold text-slate-700 uppercase tracking-wider">
                        <Filter className="w-4 h-4 text-sky-600" />
                        <span>Bộ Lọc Tham Số Báo Cáo</span>
                    </div>
                    <button
                        type="button"
                        onClick={fetchPreview}
                        disabled={loading}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-50 hover:bg-slate-100 border border-slate-200 text-xs font-semibold text-slate-700 transition-colors cursor-pointer"
                    >
                        <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin text-indigo-600' : ''}`} />
                        <span>Tải Lại Xem Trước</span>
                    </button>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {/* Kỳ Hóa Đơn */}
                    <div>
                        <label className="block text-xs font-semibold text-slate-600 mb-1.5">
                            Kỳ Thanh Toán (Tháng/Năm)
                        </label>
                        <div className="relative">
                            <input
                                type="month"
                                value={period}
                                onChange={(e) => setPeriod(e.target.value)}
                                className="w-full bg-white border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-medium text-slate-800 shadow-2xs focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                            />
                        </div>
                    </div>

                    {/* Tòa Nhà */}
                    <div>
                        <label className="block text-xs font-semibold text-slate-600 mb-1.5">
                            Tòa Nhà / Khối Block
                        </label>
                        <select
                            value={blockId}
                            onChange={(e) => setBlockId(e.target.value)}
                            className="w-full bg-white border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-medium text-slate-800 shadow-2xs focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                        >
                            <option value="">Tất cả các tòa nhà</option>
                            {blocks.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.block_name} ({b.block_code})
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Trạng Thái Hóa Đơn */}
                    <div>
                        <label className="block text-xs font-semibold text-slate-600 mb-1.5">
                            Trạng Thái Thanh Toán
                        </label>
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="w-full bg-white border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-medium text-slate-800 shadow-2xs focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                        >
                            <option value="ALL">Tất cả trạng thái</option>
                            <option value="PAID">Đã thanh toán đủ (PAID)</option>
                            <option value="PARTIAL">Thanh toán một phần (PARTIAL)</option>
                            <option value="PENDING">Chờ thanh toán (PENDING)</option>
                            <option value="OVERDUE">Quá hạn nợ (OVERDUE)</option>
                        </select>
                    </div>

                    {/* Biểu Mẫu Báo Cáo */}
                    <div>
                        <label className="block text-xs font-semibold text-slate-600 mb-1.5">
                            Loại Biểu Mẫu Báo Cáo
                        </label>
                        <select
                            value={reportType}
                            onChange={(e) => setReportType(e.target.value)}
                            className="w-full bg-white border border-slate-300 rounded-xl px-3.5 py-2.5 text-xs font-medium text-slate-800 shadow-2xs focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors"
                        >
                            <option value="debt_summary">Báo cáo Tổng hợp Công nợ & Thu tiền</option>
                            <option value="revenue_breakdown">Báo cáo Doanh thu chi tiết theo Dịch vụ</option>
                            <option value="collection_rate">Báo cáo Tỷ lệ Thu hồi & Tuân thủ Nợ</option>
                        </select>
                    </div>
                </div>
            </div>

            {/* KPI TỔNG HỢP NHANH */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs relative overflow-hidden">
                    <div className="flex items-center justify-between text-xs text-slate-500 font-semibold mb-2">
                        <span>TỔNG DOANH THU KỲ</span>
                        <div className="p-1.5 rounded-lg bg-sky-50 text-sky-600 border border-sky-100">
                            <DollarSign className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-black text-slate-900 font-mono">
                        {formatVND(summary.total_billed || (summary as any).total_amount || 0)}
                    </div>
                    <div className="mt-2 text-[11px] text-slate-500 flex items-center gap-1">
                        <span>Quy mô:</span>
                        <strong className="text-slate-800">{summary.total_invoices || reportRows.length} hóa đơn</strong>
                    </div>
                </div>

                <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs relative overflow-hidden">
                    <div className="flex items-center justify-between text-xs text-slate-500 font-semibold mb-2">
                        <span>ĐÃ THU THÀNH CÔNG</span>
                        <div className="p-1.5 rounded-lg bg-emerald-50 text-emerald-600 border border-emerald-100">
                            <CheckCircle2 className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-black text-emerald-600 font-mono">
                        {formatVND(summary.total_collected || (summary as any).paid_amount || 0)}
                    </div>
                    <div className="mt-2 text-[11px] text-slate-500 flex items-center gap-1">
                        <span>Hoàn tất thanh toán</span>
                    </div>
                </div>

                <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs relative overflow-hidden">
                    <div className="flex items-center justify-between text-xs text-slate-500 font-semibold mb-2">
                        <span>CÔNG NỢ CÒN LẠI</span>
                        <div className="p-1.5 rounded-lg bg-rose-50 text-rose-600 border border-rose-100">
                            <AlertCircle className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-black text-rose-600 font-mono">
                        {formatVND(summary.total_debt || (summary as any).debt_amount || 0)}
                    </div>
                    <div className="mt-2 text-[11px] text-slate-500 flex items-center gap-1">
                        <span>Tồn đọng / Chưa thanh toán</span>
                    </div>
                </div>

                <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs relative overflow-hidden">
                    <div className="flex items-center justify-between text-xs text-slate-500 font-semibold mb-2">
                        <span>TỶ LỆ THU HỒI NỢ</span>
                        <div className="p-1.5 rounded-lg bg-indigo-50 text-indigo-600 border border-indigo-100">
                            <TrendingUp className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-black text-indigo-600 font-mono">
                        {Number(summary.collection_rate || 0).toFixed(1)}%
                    </div>
                    <div className="w-full bg-slate-100 h-1.5 rounded-full mt-3 overflow-hidden">
                        <div
                            className="bg-indigo-600 h-full rounded-full transition-all duration-500"
                            style={{ width: `${Math.min(100, Number(summary.collection_rate || 0))}%` }}
                        />
                    </div>
                </div>
            </div>

            {/* BẢNG XEM TRƯỚC DỮ LIỆU BÁO CÁO */}
            <div className="bg-white border border-slate-200/90 rounded-3xl p-6 shadow-xs space-y-4">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <FileText className="w-5 h-5 text-indigo-600" />
                        <h3 className="text-base font-bold text-slate-900">
                            Bản Xem Trước Dữ Liệu Báo Cáo
                        </h3>
                        <span className="text-xs px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700 font-mono font-bold">
                            {reportRows.length} dòng ghi nhận
                        </span>
                    </div>

                    <div className="text-xs text-slate-400 italic">
                        * Dữ liệu được tính toán thời gian thực từ cơ sở dữ liệu hóa đơn và gạch nợ.
                    </div>
                </div>

                {loading ? (
                    <div className="py-20 flex flex-col items-center justify-center gap-3 text-slate-500">
                        <RefreshCw className="w-8 h-8 animate-spin text-indigo-600" />
                        <span className="text-xs font-semibold">Đang tổng hợp dữ liệu báo cáo tài chính...</span>
                    </div>
                ) : !reportRows || reportRows.length === 0 ? (
                    <div className="py-16 text-center text-slate-500 border border-dashed border-slate-200 rounded-2xl">
                        <FileSpreadsheet className="w-10 h-10 mx-auto text-slate-400 mb-2" />
                        <p className="text-sm font-semibold">Không tìm thấy dữ liệu hóa đơn phù hợp với bộ lọc</p>
                        <p className="text-xs text-slate-400 mt-1">Hãy thử chọn lại kỳ thanh toán khác hoặc chọn tất cả tòa nhà.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-2xl border border-slate-200">
                        <table className="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr className="bg-slate-50 text-slate-600 border-b border-slate-200 uppercase tracking-wider font-mono font-bold">
                                    <th className="py-3.5 px-4">Mã HĐ</th>
                                    <th className="py-3.5 px-4">Căn Hộ</th>
                                    <th className="py-3.5 px-4">Tòa Nhà</th>
                                    <th className="py-3.5 px-4">Chủ Hộ</th>
                                    <th className="py-3.5 px-4 text-right">Phí Quản Lý</th>
                                    <th className="py-3.5 px-4 text-right">Tiền Điện</th>
                                    <th className="py-3.5 px-4 text-right">Tiền Nước</th>
                                    <th className="py-3.5 px-4 text-right">Gửi Xe</th>
                                    <th className="py-3.5 px-4 text-right">Tổng Tiền</th>
                                    <th className="py-3.5 px-4 text-right">Đã Thu</th>
                                    <th className="py-3.5 px-4 text-right">Còn Nợ</th>
                                    <th className="py-3.5 px-4 text-center">Trạng Thái</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 font-medium bg-white text-slate-700">
                                {reportRows.map((row: any, idx: number) => (
                                    <tr key={row.invoice_id || row.id || idx} className="hover:bg-slate-50/80 transition-colors">
                                        <td className="py-3 px-4 font-mono font-bold text-indigo-600">
                                            {row.invoice_code}
                                        </td>
                                        <td className="py-3 px-4 text-slate-900 font-bold">
                                            {row.apartment_code || row.apartment_number}
                                        </td>
                                        <td className="py-3 px-4 text-slate-600">
                                            {row.block_code || row.block_name}
                                        </td>
                                        <td className="py-3 px-4 text-slate-600">
                                            {row.resident_name || '---'}
                                        </td>
                                        <td className="py-3 px-4 text-right font-mono text-slate-700">
                                            {formatVND(row.fee_breakdown?.management_fee || 0)}
                                        </td>
                                        <td className="py-3 px-4 text-right font-mono text-amber-600 font-semibold">
                                            {formatVND(row.fee_breakdown?.electricity_fee || 0)}
                                        </td>
                                        <td className="py-3 px-4 text-right font-mono text-sky-600 font-semibold">
                                            {formatVND(row.fee_breakdown?.water_fee || 0)}
                                        </td>
                                        <td className="py-3 px-4 text-right font-mono text-purple-600 font-semibold">
                                            {formatVND(row.fee_breakdown?.parking_fee || 0)}
                                        </td>
                                        <td className="py-3 px-4 text-right font-mono font-bold text-slate-900">
                                            {formatVND(row.total_amount)}
                                        </td>
                                        <td className="py-3 px-4 text-right font-mono text-emerald-600 font-semibold">
                                            {formatVND(row.paid_amount)}
                                        </td>
                                        <td className="py-3 px-4 text-right font-mono text-rose-600 font-bold">
                                            {formatVND(row.debt_amount)}
                                        </td>
                                        <td className="py-3 px-4 text-center">
                                            <span className={`inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold border ${
                                                row.status === 'PAID'
                                                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                                    : row.status === 'PARTIAL'
                                                    ? 'bg-amber-50 text-amber-700 border-amber-200'
                                                    : row.status === 'OVERDUE'
                                                    ? 'bg-rose-50 text-rose-700 border-rose-200'
                                                    : 'bg-sky-50 text-sky-700 border-sky-200'
                                            }`}>
                                                {row.status}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
};
