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
    BellRing
} from 'lucide-react';
import { invoiceApi } from '../../Services/invoiceApi';

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
                <div className="fixed top-5 right-5 z-50 bg-slate-900 border border-indigo-500/50 text-white px-4 py-3 rounded-xl shadow-2xl flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4 text-emerald-400" />
                    <span className="text-xs font-semibold">{toastMessage}</span>
                </div>
            )}

            {/* HEADER DASHBOARD */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-6 border-b border-slate-800">
                <div className="space-y-1">
                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                        <TrendingUp className="w-3.5 h-3.5" />
                        <span>BÁO CÁO TÀI CHÍNH & PHÂN TÍCH DOANH THU THÔNG MINH</span>
                    </div>
                    <h1 className="text-2xl md:text-3xl font-extrabold text-white tracking-tight flex items-center gap-2">
                        Dashboard Thống Kê Doanh Thu & Thu Hồi Công Nợ
                    </h1>
                    <p className="text-sm text-slate-400">
                        Theo dõi trực quan dòng tiền, tỷ lệ thu hồi nợ, cơ cấu phí dịch vụ và xu hướng 12 tháng qua biểu đồ tương tác.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    {/* Chọn Năm */}
                    <div className="flex items-center bg-slate-900 border border-slate-800 rounded-xl px-3 py-1.5 text-xs text-slate-300">
                        <Calendar className="w-3.5 h-3.5 text-indigo-400 mr-2" />
                        <span className="text-slate-400 mr-2">Năm:</span>
                        <select
                            value={selectedYear}
                            onChange={(e) => setSelectedYear(Number(e.target.value))}
                            className="bg-transparent text-white font-bold focus:outline-none cursor-pointer"
                        >
                            {[currentYear - 2, currentYear - 1, currentYear, currentYear + 1].map((y) => (
                                <option key={y} value={y} className="bg-slate-900 text-white">
                                    {y}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Chọn Kỳ Cụ Thể (Optional) */}
                    <div className="flex items-center bg-slate-900 border border-slate-800 rounded-xl px-3 py-1.5 text-xs text-slate-300">
                        <span className="text-slate-400 mr-2">Kỳ:</span>
                        <select
                            value={selectedPeriod}
                            onChange={(e) => setSelectedPeriod(e.target.value)}
                            className="bg-transparent text-white font-semibold focus:outline-none cursor-pointer"
                        >
                            <option value="" className="bg-slate-900 text-slate-300">Toàn Năm {selectedYear}</option>
                            {Array.from({ length: 12 }, (_, i) => {
                                const m = String(i + 1).padStart(2, '0');
                                const periodStr = `${selectedYear}-${m}`;
                                return (
                                    <option key={periodStr} value={periodStr} className="bg-slate-900 text-white">
                                        Tháng {m}/{selectedYear}
                                    </option>
                                );
                            })}
                        </select>
                    </div>

                    {/* Nút Làm Mới */}
                    <button
                        type="button"
                        onClick={loadData}
                        disabled={loading}
                        className="p-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-800 transition-colors"
                        title="Tải lại dữ liệu"
                    >
                        <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin text-indigo-400' : ''}`} />
                    </button>

                    {/* Nút Quay Lại */}
                    {onBackToList && (
                        <button
                            type="button"
                            onClick={onBackToList}
                            className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold transition-colors"
                        >
                            <ChevronLeft className="w-4 h-4" />
                            <span>Quay Lại Danh Sách</span>
                        </button>
                    )}
                </div>
            </div>

            {/* 4 THẺ KPI METRICS TỔNG QUAN */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* THẺ 1: TỔNG DOANH THU PHÁT HÀNH */}
                <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl relative overflow-hidden group hover:border-indigo-500/40 transition-all">
                    <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                        <span>TỔNG PHÁT HÀNH</span>
                        <div className="p-2 rounded-xl bg-indigo-500/10 text-indigo-400">
                            <FileText className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-extrabold text-white mt-2">
                        {formatCurrency(summary.total_billed)}
                    </div>
                    <div className="flex items-center gap-1.5 text-xs mt-2">
                        {summary.billed_growth_pct >= 0 ? (
                            <span className="flex items-center text-emerald-400 font-bold">
                                <ArrowUpRight className="w-3.5 h-3.5" />
                                +{summary.billed_growth_pct}%
                            </span>
                        ) : (
                            <span className="flex items-center text-rose-400 font-bold">
                                <ArrowDownRight className="w-3.5 h-3.5" />
                                {summary.billed_growth_pct}%
                            </span>
                        )}
                        <span className="text-slate-500 text-[11px]">so với kỳ trước</span>
                    </div>
                </div>

                {/* THẺ 2: TỔNG TIỀN THỰC THU */}
                <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl relative overflow-hidden group hover:border-emerald-500/40 transition-all">
                    <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                        <span>ĐÃ THỰC THU</span>
                        <div className="p-2 rounded-xl bg-emerald-500/10 text-emerald-400">
                            <CheckCircle2 className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-extrabold text-emerald-400 mt-2">
                        {formatCurrency(summary.total_collected)}
                    </div>
                    <div className="flex items-center justify-between text-xs mt-2 text-slate-400">
                        <span>{summary.paid_invoices} HĐ hoàn tất</span>
                        <span className="font-mono text-emerald-400 font-bold">{summary.collection_rate}%</span>
                    </div>
                </div>

                {/* THẺ 3: TỔNG CÔNG NỢ TỒN ĐỌNG */}
                <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl relative overflow-hidden group hover:border-amber-500/40 transition-all">
                    <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                        <span>CÔNG NỢ CÒN LẠI</span>
                        <div className="p-2 rounded-xl bg-amber-500/10 text-amber-400">
                            <AlertCircle className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-extrabold text-amber-400 mt-2">
                        {formatCurrency(summary.total_debt)}
                    </div>
                    <div className="flex items-center gap-1.5 text-xs mt-2 text-slate-400">
                        <span className="text-rose-400 font-bold">{summary.overdue_invoices} HĐ quá hạn</span>
                        <span className="text-slate-500 text-[11px]">cần gửi thông báo</span>
                    </div>
                </div>

                {/* THẺ 4: TỶ LỆ THU HỒI CÔNG NỢ */}
                <div className="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl relative overflow-hidden group hover:border-sky-500/40 transition-all">
                    <div className="flex items-center justify-between text-slate-400 text-xs font-semibold">
                        <span>TỶ LỆ THU HỒI</span>
                        <div className="p-2 rounded-xl bg-sky-500/10 text-sky-400">
                            <TrendingUp className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="text-2xl font-mono font-extrabold text-sky-400 mt-2">
                        {summary.collection_rate}%
                    </div>
                    {/* Thanh tiến độ */}
                    <div className="w-full bg-slate-800 rounded-full h-2 mt-3 overflow-hidden">
                        <div
                            className="bg-gradient-to-r from-sky-500 to-emerald-400 h-2 rounded-full transition-all duration-500"
                            style={{ width: `${Math.min(100, summary.collection_rate)}%` }}
                        />
                    </div>
                </div>
            </div>

            {/* PHẦN 2: BIỂU ĐỒ CHÍNH - XU HƯỚNG DOANH THU 12 THÁNG & CƠ CẤU DOANH THU */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* BIỂU ĐỒ 1: CỘT KÉP XU HƯỚNG DOANH THU 12 THÁNG ( chiếm 2 cột) */}
                <div className="lg:col-span-2 bg-slate-900/90 border border-slate-800 p-6 rounded-2xl shadow-xl flex flex-col justify-between">
                    <div>
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-slate-800">
                            <div className="flex items-center gap-2">
                                <BarChart3 className="w-5 h-5 text-indigo-400" />
                                <h3 className="font-bold text-white text-base">
                                    Xu Hướng Doanh Thu Phát Hành & Thực Thu 12 Tháng (Năm {selectedYear})
                                </h3>
                            </div>
                            <div className="flex items-center gap-4 text-xs font-semibold">
                                <div className="flex items-center gap-1.5 text-indigo-300">
                                    <span className="w-3 h-3 rounded bg-indigo-500" />
                                    <span>Phát Hành</span>
                                </div>
                                <div className="flex items-center gap-1.5 text-emerald-300">
                                    <span className="w-3 h-3 rounded bg-emerald-500" />
                                    <span>Thực Thu</span>
                                </div>
                                <div className="flex items-center gap-1.5 text-amber-300">
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
                                            <div className="absolute -top-24 z-30 bg-slate-950 border border-indigo-500/40 rounded-xl p-2.5 shadow-2xl text-[11px] whitespace-nowrap space-y-1 pointer-events-none animate-in fade-in zoom-in-95 duration-150">
                                                <div className="font-bold text-white border-b border-slate-800 pb-1">
                                                    Kỳ {m.month} ({m.invoice_count} HĐ)
                                                </div>
                                                <div className="text-indigo-300">Phát hành: {formatCurrency(m.billed_amount)}</div>
                                                <div className="text-emerald-300">Thực thu: {formatCurrency(m.collected_amount)}</div>
                                                <div className="text-amber-300">Còn nợ: {formatCurrency(m.debt_amount)}</div>
                                                <div className="text-sky-300 font-bold">Tỷ lệ: {m.collection_rate}%</div>
                                            </div>
                                        )}

                                        {/* Thanh Cột Kép */}
                                        <div className="w-full flex items-end justify-center gap-1 h-48">
                                            {/* Cột Phát Hành */}
                                            <div
                                                className="w-2.5 sm:w-3.5 bg-indigo-500/80 hover:bg-indigo-400 rounded-t transition-all duration-300"
                                                style={{ height: `${Math.max(4, billedHeight)}%` }}
                                            />
                                            {/* Cột Thực Thu */}
                                            <div
                                                className="w-2.5 sm:w-3.5 bg-emerald-500/80 hover:bg-emerald-400 rounded-t transition-all duration-300"
                                                style={{ height: `${Math.max(4, collectedHeight)}%` }}
                                            />
                                            {/* Cột Nợ */}
                                            <div
                                                className="w-1.5 sm:w-2 bg-amber-500/70 hover:bg-amber-400 rounded-t transition-all duration-300"
                                                style={{ height: `${Math.max(2, debtHeight)}%` }}
                                            />
                                        </div>

                                        {/* Nhãn Tháng */}
                                        <div className="text-[11px] font-mono font-bold text-slate-400 mt-2 group-hover:text-white transition-colors">
                                            {m.month_name}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="pt-4 border-t border-slate-800/80 flex items-center justify-between text-xs text-slate-400">
                        <span>Đơn vị: Đồng (VNĐ)</span>
                        <span>Đỉnh thu cao nhất năm: <strong>{formatCurrency(maxMonthlyVal)}</strong></span>
                    </div>
                </div>

                {/* BIỂU ĐỒ 2: DONUT CHART CƠ CẤU DOANH THU THEO LOẠI PHÍ (1 cột) */}
                <div className="bg-slate-900/90 border border-slate-800 p-6 rounded-2xl shadow-xl flex flex-col justify-between">
                    <div>
                        <div className="flex items-center gap-2 pb-4 border-b border-slate-800">
                            <PieChart className="w-5 h-5 text-sky-400" />
                            <h3 className="font-bold text-white text-base">Cơ Cấu Phân Bổ Nguồn Thu</h3>
                        </div>

                        {/* Donut Chart Visual */}
                        <div className="flex flex-col items-center justify-center my-4">
                            <div className="relative w-44 h-44 flex items-center justify-center">
                                <svg viewBox="0 0 200 200" className="w-full h-full transform -rotate-90">
                                    {donutSegments.length === 0 ? (
                                        <circle cx="100" cy="100" r="65" fill="none" stroke="#334155" strokeWidth="25" />
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
                                    <span className="text-sm font-bold font-mono text-white">
                                        {formatMillions(totalCategoryRevenue)}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Danh sách Chú thích (Legend) */}
                        <div className="space-y-2 text-xs">
                            {categories.map((cat) => (
                                <div key={cat.item_type} className="flex items-center justify-between p-2 rounded-xl bg-slate-950/50 hover:bg-slate-800/50 transition-colors">
                                    <div className="flex items-center gap-2">
                                        <span className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: cat.color }} />
                                        <span className="font-semibold text-slate-200">{cat.label}</span>
                                    </div>
                                    <div className="flex items-center gap-3 font-mono">
                                        <span className="text-white font-bold">{formatCurrency(cat.total_amount)}</span>
                                        <span className="text-slate-400 text-[11px] w-12 text-right">{cat.percentage}%</span>
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
                <div className="bg-slate-900/90 border border-slate-800 p-6 rounded-2xl shadow-xl flex flex-col justify-between">
                    <div>
                        <div className="flex items-center gap-2 pb-4 border-b border-slate-800">
                            <CreditCard className="w-5 h-5 text-emerald-400" />
                            <h3 className="font-bold text-white text-base">Phương Thức Thanh Toán</h3>
                        </div>

                        <div className="space-y-4 mt-4">
                            {paymentMethods.length === 0 ? (
                                <div className="py-8 text-center text-slate-500 text-xs">
                                    Chưa có giao dịch thanh toán trong năm này.
                                </div>
                            ) : (
                                paymentMethods.map((pm) => (
                                    <div key={pm.gateway} className="space-y-1.5 text-xs">
                                        <div className="flex items-center justify-between">
                                            <span className="font-semibold text-slate-300">{pm.label}</span>
                                            <span className="font-mono text-white font-bold">{formatCurrency(pm.total_amount)} ({pm.percentage}%)</span>
                                        </div>
                                        <div className="w-full bg-slate-800 rounded-full h-2 overflow-hidden">
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
                <div className="lg:col-span-2 bg-slate-900/90 border border-slate-800 p-6 rounded-2xl shadow-xl flex flex-col justify-between">
                    <div>
                        <div className="flex items-center justify-between pb-4 border-b border-slate-800">
                            <div className="flex items-center gap-2">
                                <AlertCircle className="w-5 h-5 text-amber-400" />
                                <h3 className="font-bold text-white text-base">
                                    Top 5 Căn Hộ Còn Nợ Đọng Nhiều Nhất
                                </h3>
                            </div>
                            <span className="text-xs text-slate-400">Cần ưu tiên nhắc nợ</span>
                        </div>

                        <div className="overflow-x-auto mt-4">
                            <table className="w-full text-left text-xs text-slate-300">
                                <thead className="bg-slate-950/80 text-slate-400 uppercase border-b border-slate-800">
                                    <tr>
                                        <th className="py-2.5 px-3">Hạng</th>
                                        <th className="py-2.5 px-3">Căn Hộ</th>
                                        <th className="py-2.5 px-3">Chủ Hộ / Cư Dân</th>
                                        <th className="py-2.5 px-3 text-center">Số HĐ Nợ</th>
                                        <th className="py-2.5 px-3">Hạn Chót</th>
                                        <th className="py-2.5 px-3 text-right">Tổng Nợ Còn Lại</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800/60 font-mono">
                                    {topDebtors.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="py-8 text-center text-slate-500 font-sans">
                                                Không có căn hộ nào nợ đọng! Tuyệt vời.
                                            </td>
                                        </tr>
                                    ) : (
                                        topDebtors.map((deb, index) => (
                                            <tr key={deb.apartment_id} className="hover:bg-slate-800/40">
                                                <td className="py-3 px-3">
                                                    <span className={`w-5 h-5 rounded-full inline-flex items-center justify-center font-bold text-[10px] ${
                                                        index === 0
                                                            ? 'bg-rose-500/20 text-rose-400 border border-rose-500/30'
                                                            : index === 1
                                                            ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30'
                                                            : 'bg-slate-800 text-slate-400'
                                                    }`}>
                                                        {index + 1}
                                                    </span>
                                                </td>
                                                <td className="py-3 px-3 font-bold text-white font-sans flex items-center gap-1.5">
                                                    <Building2 className="w-3.5 h-3.5 text-indigo-400" />
                                                    <span>{deb.apartment_number}</span>
                                                    <span className="text-[11px] text-slate-500 font-normal">({deb.block_name})</span>
                                                </td>
                                                <td className="py-3 px-3 font-sans">
                                                    <div className="font-semibold text-slate-200">{deb.resident_name}</div>
                                                    <div className="text-[10px] text-slate-500">{deb.resident_phone}</div>
                                                </td>
                                                <td className="py-3 px-3 text-center">
                                                    <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                                        {deb.unpaid_invoice_count} HĐ
                                                    </span>
                                                </td>
                                                <td className="py-3 px-3 text-slate-400">
                                                    {deb.latest_due_date || '-'}
                                                </td>
                                                <td className="py-3 px-3 text-right font-extrabold text-rose-400 text-sm">
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
        </div>
    );
};

export default RevenueAnalyticsDashboard;
