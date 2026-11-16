import React, { useState, useEffect } from 'react';
import {
    FileSpreadsheet,
    Download,
    Printer,
    X,
    Calendar,
    Building2,
    Filter,
    RefreshCw,
    CheckCircle2,
    FileText,
    ExternalLink
} from 'lucide-react';
import { invoiceApi } from '../../Services/invoiceApi';

interface FinancialReportExportModalProps {
    isOpen: boolean;
    onClose: () => void;
    blocks?: Array<{ id: string; block_code: string; block_name: string }>;
}

export const FinancialReportExportModal: React.FC<FinancialReportExportModalProps> = ({
    isOpen,
    onClose,
    blocks = [],
}) => {
    const currentPeriod = new Date().toISOString().slice(0, 7); // YYYY-MM
    const [period, setPeriod] = useState<string>(currentPeriod);
    const [blockId, setBlockId] = useState<string>('');
    const [status, setStatus] = useState<string>('ALL');
    const [reportType, setReportType] = useState<string>('debt_summary');

    const [loading, setLoading] = useState<boolean>(false);
    const [previewData, setPreviewData] = useState<any | null>(null);

    // Tải preview dữ liệu mỗi khi đổi bộ lọc
    useEffect(() => {
        if (!isOpen) return;

        let isMounted = true;
        const fetchPreview = async () => {
            setLoading(true);
            try {
                const res = await invoiceApi.getFinancialReportPreview({
                    period: period || undefined,
                    block_id: blockId || undefined,
                    status: status !== 'ALL' ? status : undefined,
                    report_type: reportType,
                });
                if (isMounted && res.success) {
                    setPreviewData(res.data);
                }
            } catch (err) {
                console.error('Lỗi khi tải preview báo cáo:', err);
            } finally {
                if (isMounted) setLoading(false);
            }
        };

        fetchPreview();

        return () => {
            isMounted = false;
        };
    }, [isOpen, period, blockId, status, reportType]);

    if (!isOpen) return null;

    // Xử lý tải file Excel (CSV UTF-8 BOM)
    const handleDownloadExcel = () => {
        const url = invoiceApi.getFinancialReportExportExcelUrl({
            period: period || undefined,
            block_id: blockId || undefined,
            status: status !== 'ALL' ? status : undefined,
            report_type: reportType,
        });

        // Kích hoạt download
        const a = document.createElement('a');
        a.href = url;
        a.setAttribute('download', '');
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    };

    // Xử lý xem & in PDF
    const handleOpenPdf = (autoPrint: boolean = false) => {
        const url = invoiceApi.getFinancialReportExportPdfUrl({
            period: period || undefined,
            block_id: blockId || undefined,
            status: status !== 'ALL' ? status : undefined,
            report_type: reportType,
            auto_print: autoPrint,
        });

        window.open(url, '_blank');
    };

    const summary = previewData?.summary || {
        total_invoices: 0,
        total_billed: 0,
        total_collected: 0,
        total_debt: 0,
        collection_rate: 0,
    };

    const previewRows = previewData?.rows || [];

    return (
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
            <div className="bg-white border border-slate-200/90 rounded-2xl w-full max-w-4xl max-h-[92vh] flex flex-col shadow-2xl animate-in fade-in zoom-in-95 duration-200">
                {/* Header */}
                <div className="p-5 bg-slate-50 border-b border-slate-200 flex items-center justify-between shrink-0 rounded-t-2xl">
                    <div className="flex items-center gap-2.5">
                        <div className="p-2 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs">
                            <FileSpreadsheet className="w-5 h-5" />
                        </div>
                        <div>
                            <h3 className="text-base font-bold text-slate-800">
                                Xuất Báo Cáo Tài Chính & Công Nợ Dịch Vụ
                            </h3>
                            <p className="text-xs text-slate-500">
                                Định dạng Excel (.CSV UTF-8) và PDF In ấn mẫu chuẩn A4 Ban Quản Lý Tòa Nhà
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 transition-colors cursor-pointer"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Form lọc & xem trước */}
                <div className="p-6 overflow-y-auto flex-1 space-y-6 text-xs">
                    {/* BỘ LỌC THÔNG SỐ XUẤT */}
                    <div className="bg-slate-50 border border-slate-200/90 p-4 rounded-xl space-y-4">
                        <div className="font-bold text-slate-700 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                            <Filter className="w-3.5 h-3.5 text-indigo-600" />
                            <span>Cấu Hình Tham Số Báo Cáo</span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            {/* Loại báo cáo */}
                            <div>
                                <label className="block text-slate-600 font-semibold mb-1">Loại Báo Cáo</label>
                                <select
                                    value={reportType}
                                    onChange={(e) => setReportType(e.target.value)}
                                    className="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-2xs"
                                >
                                    <option value="debt_summary">Công Nợ Tồn Đọng (Còn nợ)</option>
                                    <option value="revenue_detail">Chi Tiết Doanh Thu Thu Tiền</option>
                                    <option value="all">Toàn Bộ Hóa Đơn & Tài Chính</option>
                                </select>
                            </div>

                            {/* Kỳ thu */}
                            <div>
                                <label className="block text-slate-600 font-semibold mb-1">Kỳ Đối Soát (Tháng)</label>
                                <input
                                    type="month"
                                    value={period}
                                    onChange={(e) => setPeriod(e.target.value)}
                                    className="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-2xs"
                                />
                            </div>

                            {/* Khối tòa */}
                            <div>
                                <label className="block text-slate-600 font-semibold mb-1">Khối Tòa Nhà</label>
                                <select
                                    value={blockId}
                                    onChange={(e) => setBlockId(e.target.value)}
                                    className="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-2xs"
                                >
                                    <option value="">Toàn bộ các khối tòa</option>
                                    {blocks.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.block_name} ({b.block_code})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Trạng thái */}
                            <div>
                                <label className="block text-slate-600 font-semibold mb-1">Trạng Thái HĐ</label>
                                <select
                                    value={status}
                                    onChange={(e) => setStatus(e.target.value)}
                                    className="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-slate-800 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 shadow-2xs"
                                >
                                    <option value="ALL">Tất cả trạng thái</option>
                                    <option value="OVERDUE">Quá Hạn Nộp</option>
                                    <option value="PARTIAL">Thanh Toán Một Phần</option>
                                    <option value="ISSUED">Đã Phát Hành Chờ Thu</option>
                                    <option value="PAID">Đã Hoàn Tất Nộp</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* TÓM TẮT SỐ LIỆU PREVIEW */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div className="bg-slate-50 p-3.5 rounded-xl border border-slate-200 shadow-2xs">
                            <span className="text-[10px] text-slate-500 uppercase font-semibold">Số Lượng HĐ</span>
                            <div className="text-lg font-mono font-bold text-slate-800 mt-1">
                                {summary.total_invoices} HĐ
                            </div>
                        </div>
                        <div className="bg-indigo-50/50 p-3.5 rounded-xl border border-indigo-100 shadow-2xs">
                            <span className="text-[10px] text-indigo-600 uppercase font-semibold">Tổng Phát Hành</span>
                            <div className="text-lg font-mono font-bold text-indigo-700 mt-1">
                                {Number(summary.total_billed).toLocaleString('vi-VN')} đ
                            </div>
                        </div>
                        <div className="bg-emerald-50/50 p-3.5 rounded-xl border border-emerald-100 shadow-2xs">
                            <span className="text-[10px] text-emerald-600 uppercase font-semibold">Đã Thu</span>
                            <div className="text-lg font-mono font-bold text-emerald-700 mt-1">
                                {Number(summary.total_collected).toLocaleString('vi-VN')} đ
                            </div>
                        </div>
                        <div className="bg-amber-50/50 p-3.5 rounded-xl border border-amber-100 shadow-2xs">
                            <span className="text-[10px] text-amber-600 uppercase font-semibold">Còn Nợ</span>
                            <div className="text-lg font-mono font-bold text-amber-700 mt-1">
                                {Number(summary.total_debt).toLocaleString('vi-VN')} đ
                            </div>
                        </div>
                    </div>

                    {/* BẢNG XEM TRƯỚC 5 DÒNG */}
                    <div className="space-y-2">
                        <div className="flex items-center justify-between text-slate-600 text-xs">
                            <span className="font-semibold text-slate-700">
                                Dữ Liệu Xem Trước (Hiển thị tối đa 5 hóa đơn đầu tiên):
                            </span>
                            {loading && (
                                <span className="flex items-center gap-1 text-indigo-600">
                                    <RefreshCw className="w-3 h-3 animate-spin" />
                                    <span>Đang cập nhật...</span>
                                </span>
                            )}
                        </div>

                        <div className="overflow-x-auto border border-slate-200 rounded-xl">
                            <table className="w-full text-left text-xs text-slate-700">
                                <thead className="bg-slate-50 text-slate-600 uppercase text-[10px] border-b border-slate-200 font-bold">
                                    <tr>
                                        <th className="py-2.5 px-3">Mã HĐ</th>
                                        <th className="py-2.5 px-3">Căn Hộ</th>
                                        <th className="py-2.5 px-3">Cư Dân</th>
                                        <th className="py-2.5 px-3 text-right">Tổng Tiền</th>
                                        <th className="py-2.5 px-3 text-right">Đã Thu</th>
                                        <th className="py-2.5 px-3 text-right">Còn Nợ</th>
                                        <th className="py-2.5 px-3 text-center">Trạng Thái</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 font-mono text-[11px] bg-white">
                                    {previewRows.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-6 text-center text-slate-400 font-sans">
                                                Không có dữ liệu hóa đơn nào phù hợp với điều kiện lọc.
                                            </td>
                                        </tr>
                                    ) : (
                                        previewRows.slice(0, 5).map((row: any) => (
                                            <tr key={row.invoice_id} className="hover:bg-slate-50/80 transition-colors">
                                                <td className="py-2 px-3 font-bold text-indigo-600">{row.invoice_number}</td>
                                                <td className="py-2 px-3 font-bold text-slate-800 font-sans">{row.apartment_number}</td>
                                                <td className="py-2 px-3 font-sans text-slate-600">{row.resident_name}</td>
                                                <td className="py-2 px-3 text-right text-slate-700">
                                                    {Number(row.total_amount).toLocaleString('vi-VN')} đ
                                                </td>
                                                <td className="py-2 px-3 text-right text-emerald-600 font-semibold">
                                                    {Number(row.paid_amount).toLocaleString('vi-VN')} đ
                                                </td>
                                                <td className="py-2 px-3 text-right text-amber-600 font-bold">
                                                    {Number(row.remaining_balance).toLocaleString('vi-VN')} đ
                                                </td>
                                                <td className="py-2 px-3 text-center font-sans text-[10px]">
                                                    {row.status_label}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {/* Footer Actions */}
                <div className="p-4 bg-slate-50 border-t border-slate-200 flex flex-wrap items-center justify-between gap-3 shrink-0 rounded-b-2xl">
                    <button
                        type="button"
                        onClick={onClose}
                        className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:text-slate-900 bg-white hover:bg-slate-100 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                    >
                        Đóng
                    </button>

                    <div className="flex items-center gap-3">
                        {/* Nút Xem & In PDF */}
                        <button
                            type="button"
                            onClick={() => handleOpenPdf(true)}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white hover:bg-slate-50 text-sky-700 border border-sky-300 text-xs font-bold shadow-2xs transition-all cursor-pointer"
                        >
                            <Printer className="w-3.5 h-3.5 text-sky-600" />
                            <span>In Ấn / Mở PDF</span>
                        </button>

                        {/* Nút Xuất Excel */}
                        <button
                            type="button"
                            onClick={handleDownloadExcel}
                            className="inline-flex items-center gap-1.5 px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm transition-all cursor-pointer"
                        >
                            <Download className="w-3.5 h-3.5" />
                            <span>Tải Xuống File Excel (.CSV)</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default FinancialReportExportModal;
