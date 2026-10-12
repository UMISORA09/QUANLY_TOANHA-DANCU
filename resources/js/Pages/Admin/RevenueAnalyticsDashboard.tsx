import React, { useState, useEffect, useCallback } from 'react';
import {
    TrendingUp,
    DollarSign,
    CreditCard,
    AlertCircle,
    Calendar,
    RefreshCw,
    PieChart,
    BarChart3,
    ArrowUpRight,
    ArrowDownRight,
    ChevronLeft,
    CheckCircle2,
    Building2,
    Users,
    Zap,
    Droplets,
    Car,
    FileText,
    BellRing,
    Download
} from 'lucide-react';
import { invoiceApi } from '../../Services/invoiceApi';
import { FinancialReportExportModal } from './FinancialReportExportModal';

interface RevenueAnalyticsDashboardProps {
    onBackToList?: () => void;
}

export const RevenueAnalyticsDashboard: React.FC<RevenueAnalyticsDashboardProps> = ({ onBackToList }) => {
    const currentYear = new Date().getFullYear();
    const [selectedYear, setSelectedYear] = useState<number>(currentYear);
    const [selectedPeriod, setSelectedPeriod] = useState<string>('');
    const [loading, setLoading] = useState<boolean>(true);
    const [dashboardData, setDashboardData] = useState<any | null>(null);
    const [hoveredMonthIndex, setHoveredMonthIndex] = useState<number | null>(null);
    const [toastMessage, setToastMessage] = useState<string | null>(null);
    const [reportModalOpen, setReportModalOpen] = useState<boolean>(false);

    const showToast = (text: string) => {
        setToastMessage(text);
        setTimeout(() => setToastMessage(null), 3000);
    };

    const loadData = useCallback(async () => {
        setLoading(true);
        try {
            const res = await invoiceApi.getRevenueAnalyticsDashboard({
                year: selectedYear,
                period: selectedPeriod || undefined,
            });
            if (res.success) {
                setDashboardData(res.data);
            }
        } catch (err: any) {
            console.error('Không thể tải dữ liệu phân tích doanh thu:', err);
            showToast('Lỗi tải dữ liệu: ' + (err.message || 'Lỗi kết nối'));
        } finally {
            setLoading(false);
        }
    }, [selectedYear, selectedPeriod]);

    useEffect(() => {
        loadData();
    }, [loadData]);

    // Format tiền tệ Việt Nam
    const formatCurrency = (amount: number) => {
        return Number(amount || 0).toLocaleString('vi-VN') + ' đ';
    };

    const formatMillions = (amount: number) => {
        if (!amount) return '0 tr';
        if (amount >= 1000000000) {
            return (amount / 1000000000).toFixed(1) + ' tỷ';
        }
        return (amount / 1000000).toFixed(1) + ' tr';
    };

    const summary = dashboardData?.summary || {
        total_billed: 0,
        total_collected: 0,
        total_debt: 0,
        total_invoices: 0,
        paid_invoices: 0,
        overdue_invoices: 0,
        collection_rate: 0,
        billed_growth_pct: 0,
        collected_growth_pct: 0,
    };

    const monthlyTrend: any[] = dashboardData?.monthly_trend || [];
    const categories: any[] = dashboardData?.revenue_by_category || [];
    const paymentMethods: any[] = dashboardData?.payment_methods || [];
    const topDebtors: any[] = dashboardData?.top_debtors || [];

    // Tìm max value của monthly trend để scale biểu đồ cột
    const maxMonthlyVal = Math.max(
        ...monthlyTrend.map((m) => Math.max(m.billed_amount, m.collected_amount, m.debt_amount)),
        1000000
    );

    // Tính Donut chart SVG path
    const totalCategoryRevenue = categories.reduce((sum, c) => sum + c.total_amount, 0);
    let cumulativeAngle = 0;
    const donutSegments = categories.map((cat) => {
        const angle = totalCategoryRevenue > 0 ? (cat.total_amount / totalCategoryRevenue) * 360 : 0;
        const startAngle = cumulativeAngle;
        cumulativeAngle += angle;
        const endAngle = cumulativeAngle;

        // Tọa độ SVG cho Donut Arc (Radius R=80, Inner r=50, Center cx=100, cy=100)
        const rad = (deg: number) => ((deg - 90) * Math.PI) / 180;
        const x1 = 100 + 80 * Math.cos(rad(startAngle));
        const y1 = 100 + 80 * Math.sin(rad(startAngle));
        const x2 = 100 + 80 * Math.cos(rad(endAngle));
        const y2 = 100 + 80 * Math.sin(rad(endAngle));

        const ix1 = 100 + 52 * Math.cos(rad(endAngle));
        const iy1 = 100 + 52 * Math.sin(rad(endAngle));
        const ix2 = 100 + 52 * Math.cos(rad(startAngle));
        const iy2 = 100 + 52 * Math.sin(rad(startAngle));

        const largeArc = angle > 180 ? 1 : 0;
        const pathData = `M ${x1} ${y1} A 80 80 0 ${largeArc} 1 ${x2} ${y2} L ${ix1} iy1 A 52 52 0 ${largeArc} 0 ${ix2} ${iy2} Z`.replace('iy1', String(iy1));

        return {
            ...cat,
            pathData,
            startAngle,
            endAngle,
        };
    });

    return (
        <div className="space-y-6 pb-12 animate-in fade-in duration-300">
            {/* TOAST THÔNG BÁO */}
            {toastMessage && (
                <div className="fixed top-5 right-5 z-50 bg-white border border-slate-200 text-neutral-900 px-4 py-3 rounded-xl shadow-2xl flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                    <span className="text-xs font-semibold">{toastMessage}</span>
                </div>
            )}

            {/* HEADER DASHBOARD */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-200/80">
                <div className="space-y-1">
                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[11px] font-bold bg-sky-50 text-sky-700 border border-sky-200 font-mono tracking-wider uppercase">
                        <TrendingUp className="w-3.5 h-3.5 text-sky-600" />
                        <span>BÁO CÁO TÀI CHÍNH & PHÂN TÍCH DOANH THU THÔNG MINH</span>
                    </div>
                    <h1 className="text-2xl md:text-3xl font-black text-neutral-900 tracking-tight flex items-center gap-2">
                        Dashboard Thống Kê Doanh Thu & Thu Hồi Công Nợ
                    </h1>
                    <p className="text-xs sm:text-sm text-slate-500 font-medium">
                        Theo dõi trực quan dòng tiền, tỷ lệ thu hồi nợ, cơ cấu phí dịch vụ và xu hướng 12 tháng qua biểu đồ tương tác.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2.5">
                    {/* Chọn Năm */}
                    <div className="flex items-center bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-700 shadow-2xs">
                        <Calendar className="w-3.5 h-3.5 text-sky-600 mr-2" />
                        <span className="text-slate-500 mr-2 font-medium">Năm:</span>
                        <select
                            value={selectedYear}
                            onChange={(e) => setSelectedYear(Number(e.target.value))}
                            className="bg-transparent text-neutral-900 font-bold focus:outline-none cursor-pointer"
                        >
                            {[currentYear - 2, currentYear - 1, currentYear, currentYear + 1].map((y) => (
                                <option key={y} value={y} className="bg-white text-neutral-900">
                                    {y}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Chọn Kỳ Cụ Thể (Optional) */}
                    <div className="flex items-center bg-white border border-slate-200 rounded-xl px-3 py-1.5 text-xs text-slate-700 shadow-2xs">
                        <span className="text-slate-500 mr-2 font-medium">Kỳ:</span>
                        <select
                            value={selectedPeriod}
                            onChange={(e) => setSelectedPeriod(e.target.value)}
                            className="bg-transparent text-neutral-900 font-bold focus:outline-none cursor-pointer"
                        >
                            <option value="" className="bg-white text-slate-700">Toàn Năm {selectedYear}</option>
                            {Array.from({ length: 12 }, (_, i) => {
                                const m = String(i + 1).padStart(2, '0');
                                const periodStr = `${selectedYear}-${m}`;
                                return (
                                    <option key={periodStr} value={periodStr} className="bg-white text-neutral-900">
                                        Tháng {m}/{selectedYear}
                                    </option>
                                );
                            })}
                        </select>
                    </div>

                    {/* Nút Xuất Báo Cáo */}
                    <button
                        type="button"
                        onClick={() => setReportModalOpen(true)}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-2xs text-xs font-bold transition-all cursor-pointer"
                        title="Xuất báo cáo tài chính và công nợ ra Excel / PDF"
                    >
                        <Download className="w-3.5 h-3.5 text-teal-600" />
                        <span>Xuất Báo Cáo</span>
                    </button>

                    {/* Nút Làm Mới */}
                    <button
                        type="button"
                        onClick={loadData}
                        disabled={loading}
                        className="p-2 rounded-xl bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-2xs transition-colors cursor-pointer"
                        title="Tải lại dữ liệu"
                    >
                        <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin text-sky-600' : 'text-slate-600'}`} />
                    </button>

                    {/* Nút Quay Lại */}
                    {onBackToList && (
                        <button
                            type="button"
                            onClick={onBackToList}
                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-2xs text-xs font-bold transition-colors cursor-pointer"
                        >
                            <ChevronLeft className="w-4 h-4 text-sky-600" />
                            <span>Quay Lại Danh Sách</span>
                        </button>
                    )}
                </div>
            </div>

            {/* 4 THẺ KPI METRICS TỔNG QUAN */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* THẺ 1: TỔNG DOANH THU PHÁT HÀNH */}
                <div className="bg-white border border-slate-200/80 p-5 rounded-2xl shadow-xs relative overflow-hidden group hover:border-sky-300 transition-all">
                    <div className="flex items-center justify-between text-slate-500 text-xs font-semibold">
                        <span>TỔNG PHÁT HÀNH</span>
                        <div className="p-2 rounded-xl bg-sky-50 text-sky-600 border border-sky-200">
                            <FileText className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-black text-neutral-900 mt-2">
                        {formatCurrency(summary.total_billed)}
                    </div>
                    <div className="flex items-center gap-1.5 text-xs mt-2">
                        {summary.billed_growth_pct >= 0 ? (
                            <span className="flex items-center text-emerald-600 font-bold">
                                <ArrowUpRight className="w-3.5 h-3.5" />
                                +{summary.billed_growth_pct}%
                            </span>
                        ) : (
                            <span className="flex items-center text-rose-600 font-bold">
                                <ArrowDownRight className="w-3.5 h-3.5" />
                                {summary.billed_growth_pct}%
                            </span>
                        )}
                        <span className="text-slate-500 text-[11px]">so với kỳ trước</span>
                    </div>
                </div>

                {/* THẺ 2: TỔNG TIỀN THỰC THU */}
                <div className="bg-white border border-slate-200/80 p-5 rounded-2xl shadow-xs relative overflow-hidden group hover:border-emerald-300 transition-all">
                    <div className="flex items-center justify-between text-slate-500 text-xs font-semibold">
                        <span>ĐÃ THỰC THU</span>
                        <div className="p-2 rounded-xl bg-emerald-50 text-emerald-600 border border-emerald-200">
                            <CheckCircle2 className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-black text-emerald-600 mt-2">
                        {formatCurrency(summary.total_collected)}
                    </div>
                    <div className="flex items-center justify-between text-xs mt-2 text-slate-500">
                        <span>{summary.paid_invoices} HĐ hoàn tất</span>
                        <span className="font-mono text-emerald-600 font-bold">{summary.collection_rate}%</span>
                    </div>
                </div>

                {/* THẺ 3: TỔNG CÔNG NỢ TỒN ĐỌNG */}
                <div className="bg-white border border-slate-200/80 p-5 rounded-2xl shadow-xs relative overflow-hidden group hover:border-amber-300 transition-all">
                    <div className="flex items-center justify-between text-slate-500 text-xs font-semibold">
                        <span>CÔNG NỢ CÒN LẠI</span>
                        <div className="p-2 rounded-xl bg-amber-50 text-amber-600 border border-amber-200">
                            <AlertCircle className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-black text-amber-600 mt-2">
                        {formatCurrency(summary.total_debt)}
                    </div>
                    <div className="flex items-center gap-1.5 text-xs mt-2 text-slate-500">
                        <span className="text-rose-600 font-bold">{summary.overdue_invoices} HĐ quá hạn</span>
                        <span className="text-slate-400 text-[11px]">cần gửi nhắc nợ</span>
                    </div>
                </div>

                {/* THẺ 4: TỶ LỆ THU HỒI CÔNG NỢ */}
                <div className="bg-white border border-slate-200/80 p-5 rounded-2xl shadow-xs relative overflow-hidden group hover:border-purple-300 transition-all">
                    <div className="flex items-center justify-between text-slate-500 text-xs font-semibold">
                        <span>TỶ LỆ THU HỒI</span>
                        <div className="p-2 rounded-xl bg-purple-50 text-purple-600 border border-purple-200">
                            <TrendingUp className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-black text-purple-600 mt-2">
                        {summary.collection_rate}%
                    </div>
                    {/* Thanh tiến độ */}
                    <div className="w-full bg-slate-100 rounded-full h-2 mt-3 overflow-hidden">
                        <div
                            className="bg-gradient-to-r from-sky-500 to-emerald-500 h-2 rounded-full transition-all duration-500"
                            style={{ width: `${Math.min(100, summary.collection_rate)}%` }}
                        />
                    </div>
                </div>
            </div>

            {/* PHẦN 2: BIỂU ĐỒ CHÍNH - XU HƯỚNG DOANH THU 12 THÁNG & CƠ CẤU DOANH THU */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* BIỂU ĐỒ 1: CỘT KÉP XU HƯỚNG DOANH THU 12 THÁNG ( chiếm 2 cột) */}
                <div className="lg:col-span-2 bg-white border border-slate-200/80 p-6 rounded-2xl shadow-xs flex flex-col justify-between">
                    <div>
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <BarChart3 className="w-5 h-5 text-sky-600" />
                                <h3 className="font-bold text-neutral-900 text-base">
                                    Xu Hướng Doanh Thu Phát Hành & Thực Thu 12 Tháng (Năm {selectedYear})
                                </h3>
                            </div>
                            <div className="flex items-center gap-4 text-xs font-semibold">
                                <div className="flex items-center gap-1.5 text-sky-700">
                                    <span className="w-3 h-3 rounded bg-sky-500" />
                                    <span>Phát Hành</span>
                                </div>
                                <div className="flex items-center gap-1.5 text-emerald-700">
                                    <span className="w-3 h-3 rounded bg-emerald-500" />
                                    <span>Thực Thu</span>
                                </div>
                                <div className="flex items-center gap-1.5 text-amber-700">
                                    <span className="w-3 h-3 rounded bg-amber-500" />
                                    <span>Còn Nợ</span>
                                </div>
                            </div>
                        </div>

                        {/* Interactive SVG Bar Chart */}
                        <div className="mt-6 relative h-64 w-full flex items-end justify-between gap-2 pt-6">
                            {monthlyTrend.map((m, idx) => {
                                const billedHeight = maxMonthlyVal > 0 ? (m.billed_amount / maxMonthlyVal) * 100 : 0;
                                const collectedHeight = maxMonthlyVal > 0 ? (m.collected_amount / maxMonthlyVal) * 100 : 0;
                                const debtHeight = maxMonthlyVal > 0 ? (m.debt_amount / maxMonthlyVal) * 100 : 0;
                                const isHovered = hoveredMonthIndex === idx;

                                return (
                                    <div
                                        key={m.month}
                                        onMouseEnter={() => setHoveredMonthIndex(idx)}
                                        onMouseLeave={() => setHoveredMonthIndex(null)}
                                        className="flex-1 flex flex-col items-center h-full justify-end group cursor-pointer relative"
                                    >
                                        {/* Hover Tooltip Popup */}
                                        {isHovered && (
                                            <div className="absolute -top-24 z-30 bg-white border border-slate-200 rounded-xl p-2.5 shadow-xl text-[11px] whitespace-nowrap space-y-1 pointer-events-none animate-in fade-in zoom-in-95 duration-150">
                                                <div className="font-bold text-neutral-900 border-b border-slate-100 pb-1">
                                                    Kỳ {m.month} ({m.invoice_count} HĐ)
                                                </div>
                                                <div className="text-sky-700 font-mono">Phát hành: {formatCurrency(m.billed_amount)}</div>
                                                <div className="text-emerald-700 font-mono">Thực thu: {formatCurrency(m.collected_amount)}</div>
                                                <div className="text-amber-700 font-mono">Còn nợ: {formatCurrency(m.debt_amount)}</div>
                                                <div className="text-purple-700 font-bold font-mono">Tỷ lệ: {m.collection_rate}%</div>
                                            </div>
                                        )}

                                        {/* Thanh Cột Kép */}
                                        <div className="w-full flex items-end justify-center gap-1 h-48">
                                            {/* Cột Phát Hành */}
                                            <div
                                                className="w-2.5 sm:w-3.5 bg-sky-500 hover:bg-sky-600 rounded-t transition-all duration-300"
                                                style={{ height: `${Math.max(4, billedHeight)}%` }}
                                            />
                                            {/* Cột Thực Thu */}
                                            <div
                                                className="w-2.5 sm:w-3.5 bg-emerald-500 hover:bg-emerald-600 rounded-t transition-all duration-300"
                                                style={{ height: `${Math.max(4, collectedHeight)}%` }}
                                            />
                                            {/* Cột Nợ */}
                                            <div
                                                className="w-1.5 sm:w-2 bg-amber-500 hover:bg-amber-600 rounded-t transition-all duration-300"
                                                style={{ height: `${Math.max(2, debtHeight)}%` }}
                                            />
                                        </div>

                                        {/* Nhãn Tháng */}
                                        <div className="text-[11px] font-mono font-bold text-slate-500 mt-2 group-hover:text-neutral-900 transition-colors">
                                            {m.month_name}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                        <span>Đơn vị: Đồng (VNĐ)</span>
                        <span>Đỉnh thu cao nhất năm: <strong className="text-neutral-900 font-mono">{formatCurrency(maxMonthlyVal)}</strong></span>
                    </div>
                </div>

                {/* BIỂU ĐỒ 2: DONUT CHART CƠ CẤU DOANH THU THEO LOẠI PHÍ (1 cột) */}
                <div className="bg-white border border-slate-200/80 p-6 rounded-2xl shadow-xs flex flex-col justify-between">
                    <div>
                        <div className="flex items-center gap-2 pb-4 border-b border-slate-100">
                            <PieChart className="w-5 h-5 text-sky-600" />
                            <h3 className="font-bold text-neutral-900 text-base">Cơ Cấu Phân Bổ Nguồn Thu</h3>
                        </div>

                        {/* Donut Chart Visual */}
                        <div className="flex flex-col items-center justify-center my-4">
                            <div className="relative w-44 h-44 flex items-center justify-center">
                                <svg viewBox="0 0 200 200" className="w-full h-full transform -rotate-90">
                                    {donutSegments.length === 0 ? (
                                        <circle cx="100" cy="100" r="65" fill="none" stroke="#e2e8f0" strokeWidth="25" />
                                    ) : (
                                        donutSegments.map((seg, idx) => (
                                            <path
                                                key={idx}
                                                d={seg.pathData}
                                                fill={seg.color}
                                                className="hover:opacity-80 transition-opacity cursor-pointer"
                                            >
                                                <title>{`${seg.label}: ${formatCurrency(seg.total_amount)} (${seg.percentage}%)`}</title>
                                            </path>
                                        ))
                                    )}
                                </svg>
                                {/* Tâm vòng tròn */}
                                <div className="absolute inset-0 flex flex-col items-center justify-center text-center pointer-events-none">
                                    <span className="text-[10px] text-slate-400 font-bold uppercase">TỔNG THU</span>
                                    <span className="text-sm font-bold font-mono text-neutral-900">
                                        {formatMillions(totalCategoryRevenue)}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Danh sách Chú thích (Legend) */}
                        <div className="space-y-2 text-xs">
                            {categories.map((cat) => (
                                <div key={cat.item_type} className="flex items-center justify-between p-2 rounded-xl bg-slate-50 hover:bg-slate-100/70 transition-colors">
                                    <div className="flex items-center gap-2">
                                        <span className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: cat.color }} />
                                        <span className="font-semibold text-slate-700">{cat.label}</span>
                                    </div>
                                    <div className="flex items-center gap-3 font-mono">
                                        <span className="text-neutral-900 font-bold">{formatCurrency(cat.total_amount)}</span>
                                        <span className="text-slate-500 text-[11px] w-12 text-right">{cat.percentage}%</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* PHẦN 3: PHƯƠNG THỨC THANH TOÁN & BẢNG TOP CĂN HỘ CÒN NỢ */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* CƠ CẤU PHƯƠNG THỨC THANH TOÁN (1 cột) */}
                <div className="bg-white border border-slate-200/80 p-6 rounded-2xl shadow-xs flex flex-col justify-between">
                    <div>
                        <div className="flex items-center gap-2 pb-4 border-b border-slate-100">
                            <CreditCard className="w-5 h-5 text-emerald-600" />
                            <h3 className="font-bold text-neutral-900 text-base">Phương Thức Thanh Toán</h3>
                        </div>

                        <div className="space-y-4 mt-4">
                            {paymentMethods.length === 0 ? (
                                <div className="py-8 text-center text-slate-400 text-xs">
                                    Chưa có giao dịch thanh toán trong năm này.
                                </div>
                            ) : (
                                paymentMethods.map((pm) => (
                                    <div key={pm.gateway} className="space-y-1.5 text-xs">
                                        <div className="flex items-center justify-between">
                                            <span className="font-semibold text-slate-700">{pm.label}</span>
                                            <span className="font-mono text-neutral-900 font-bold">{formatCurrency(pm.total_amount)} ({pm.percentage}%)</span>
                                        </div>
                                        <div className="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                                            <div
                                                className="h-2 rounded-full transition-all duration-500"
                                                style={{ width: `${pm.percentage}%`, backgroundColor: pm.color }}
                                            />
                                        </div>
                                        <div className="text-[10px] text-slate-500 text-right font-mono">
                                            {pm.transaction_count} giao dịch
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>

                {/* BẢNG TOP 5 CĂN HỘ CÒN NỢ ĐỌNG LỚN NHẤT (2 cột) */}
                <div className="lg:col-span-2 bg-white border border-slate-200/80 p-6 rounded-2xl shadow-xs flex flex-col justify-between">
                    <div>
                        <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <AlertCircle className="w-5 h-5 text-amber-600" />
                                <h3 className="font-bold text-neutral-900 text-base">
                                    Top 5 Căn Hộ Còn Nợ Đọng Nhiều Nhất
                                </h3>
                            </div>
                            <span className="text-xs text-slate-500">Cần ưu tiên nhắc nợ</span>
                        </div>

                        <div className="overflow-x-auto mt-4">
                            <table className="w-full text-left text-xs text-slate-700">
                                <thead className="bg-slate-50/80 text-slate-500 uppercase border-b border-slate-200 text-[11px] font-bold">
                                    <tr>
                                        <th className="py-2.5 px-3">Hạng</th>
                                        <th className="py-2.5 px-3">Căn Hộ</th>
                                        <th className="py-2.5 px-3">Chủ Hộ / Cư Dân</th>
                                        <th className="py-2.5 px-3 text-center">Số HĐ Nợ</th>
                                        <th className="py-2.5 px-3">Hạn Chót</th>
                                        <th className="py-2.5 px-3 text-right">Tổng Nợ Còn Lại</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 font-mono">
                                    {topDebtors.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="py-8 text-center text-slate-400 font-sans">
                                                Không có căn hộ nào nợ đọng! Tuyệt vời.
                                            </td>
                                        </tr>
                                    ) : (
                                        topDebtors.map((deb, index) => (
                                            <tr key={deb.apartment_id} className="hover:bg-slate-50/70 transition-colors">
                                                <td className="py-3 px-3">
                                                    <span className={`w-5 h-5 rounded-full inline-flex items-center justify-center font-bold text-[10px] ${
                                                        index === 0
                                                            ? 'bg-rose-50 text-rose-700 border border-rose-200'
                                                            : index === 1
                                                            ? 'bg-amber-50 text-amber-700 border border-amber-200'
                                                            : 'bg-slate-100 text-slate-600'
                                                    }`}>
                                                        {index + 1}
                                                    </span>
                                                </td>
                                                <td className="py-3 px-3 font-bold text-neutral-900 font-sans flex items-center gap-1.5">
                                                    <Building2 className="w-3.5 h-3.5 text-sky-600" />
                                                    <span>{deb.apartment_number}</span>
                                                    <span className="text-[11px] text-slate-500 font-normal">({deb.block_name})</span>
                                                </td>
                                                <td className="py-3 px-3 font-sans">
                                                    <div className="font-semibold text-neutral-900">{deb.resident_name}</div>
                                                    <div className="text-[10px] text-slate-500">{deb.resident_phone}</div>
                                                </td>
                                                <td className="py-3 px-3 text-center">
                                                    <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                        {deb.unpaid_invoice_count} HĐ
                                                    </span>
                                                </td>
                                                <td className="py-3 px-3 text-slate-500">
                                                    {deb.latest_due_date || '-'}
                                                </td>
                                                <td className="py-3 px-3 text-right font-extrabold text-rose-600 text-sm">
                                                    {formatCurrency(deb.total_debt)}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {/* MODAL XUẤT BÁO CÁO TÀI CHÍNH (CHỨC NĂNG 13) */}
            <FinancialReportExportModal
                isOpen={reportModalOpen}
                onClose={() => setReportModalOpen(false)}
            />
        </div>
    );
};

export default RevenueAnalyticsDashboard;
