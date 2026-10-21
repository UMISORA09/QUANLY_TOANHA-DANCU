import React, { useState, useEffect, useCallback } from 'react';
import {
    Mail,
    Send,
    RefreshCw,
    Download,
    Search,
    Filter,
    Layers,
    AlertTriangle,
    CheckCircle2,
    Clock,
    X,
    ChevronLeft,
    ChevronRight,
    Copy,
    Check,
    Building2,
    Sparkles,
    Eye,
    Play,
    FileText,
    ShieldCheck,
    AlertCircle,
    ArrowUpDown,
    RotateCcw,
    Zap,
    Inbox,
    Receipt,
    Banknote,
    FileSpreadsheet,
    HelpCircle,
    History,
    Radio
} from 'lucide-react';
import { invoiceApi } from '../../Services/invoiceApi';
import { useRealtimeSync } from '../../Hooks/useRealtimeSync';

interface BlockOption {
    id: string;
    block_code: string;
    block_name: string;
}

export interface DebtReminderManagementProps {
    onBackToList?: () => void;
    onNavigateTab?: (tabKey: string) => void;
}

export const DebtReminderManagement: React.FC<DebtReminderManagementProps> = ({
    onBackToList,
    onNavigateTab,
}) => {
    // Dữ liệu danh sách & KPI
    const [logs, setLogs] = useState<any[]>([]);
    const [summary, setSummary] = useState<any | null>(null);
    const [loading, setLoading] = useState<boolean>(true);

    // Bộ lọc
    const [statusFilter, setStatusFilter] = useState<string>('ALL');
    const [overdueRangeFilter, setOverdueRangeFilter] = useState<string>('ALL');
    const [blockFilter, setBlockFilter] = useState<string>('');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [quickDatePreset, setQuickDatePreset] = useState<'all' | 'today' | '7days' | 'this_month' | 'overdue_30'>('all');

    // Phân trang
    const [pagination, setPagination] = useState({
        current_page: 1,
        last_page: 1,
        total: 0,
        per_page: 15,
    });

    // Danh sách khối
    const [blocks, setBlocks] = useState<BlockOption[]>([]);

    // Checkbox chọn nhiều
    const [selectedLogIds, setSelectedLogIds] = useState<string[]>([]);

    // Modal Xem trước Email
    const [previewModalOpen, setPreviewModalOpen] = useState<boolean>(false);
    const [previewData, setPreviewData] = useState<any | null>(null);
    const [loadingPreview, setLoadingPreview] = useState<boolean>(false);

    // Modal Quét tự động
    const [autoScanModalOpen, setAutoScanModalOpen] = useState<boolean>(false);
    const [autoScanOverdueOnly, setAutoScanOverdueOnly] = useState<boolean>(true);
    const [autoScanDaysBefore, setAutoScanDaysBefore] = useState<number>(3);
    const [autoScanMinOverdueDays, setAutoScanMinOverdueDays] = useState<number>(0);
    const [triggeringAutoScan, setTriggeringAutoScan] = useState<boolean>(false);

    // Thao tác gửi lại đơn lẻ
    const [retryingLogId, setRetryingLogId] = useState<string | null>(null);

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

    // Tải dữ liệu nhật ký & KPI
    const loadData = useCallback(async (page: number = 1) => {
        setLoading(true);
        try {
            const filters: any = {
                page,
                per_page: 15,
                search: searchQuery.trim() || undefined,
                channel_status: statusFilter !== 'ALL' ? statusFilter : undefined,
            };

            const [logsRes, summaryRes] = await Promise.all([
                invoiceApi.getDebtReminderLogs(filters),
                invoiceApi.getDebtReminderSummary().catch(() => null),
            ]);

            if (logsRes.success && logsRes.data) {
                let filteredItems = logsRes.data.data || [];

                // Filter theo block ở client nếu có
                if (blockFilter) {
                    filteredItems = filteredItems.filter((item: any) => {
                        const blockId = item.apartment?.block_id || item.invoice?.apartment?.block_id;
                        return blockId === blockFilter;
                    });
                }

                // Filter theo mức độ quá hạn
                if (overdueRangeFilter === 'OVERDUE_ONLY') {
                    filteredItems = filteredItems.filter((item: any) => (item.days_overdue || 0) > 0);
                } else if (overdueRangeFilter === 'OVERDUE_7') {
                    filteredItems = filteredItems.filter((item: any) => (item.days_overdue || 0) >= 7);
                } else if (overdueRangeFilter === 'OVERDUE_30') {
                    filteredItems = filteredItems.filter((item: any) => (item.days_overdue || 0) >= 30);
                } else if (overdueRangeFilter === 'DUE_SOON') {
                    filteredItems = filteredItems.filter((item: any) => (item.days_overdue || 0) === 0);
                }

                // Filter theo quick preset
                if (quickDatePreset === 'overdue_30') {
                    filteredItems = filteredItems.filter((item: any) => (item.days_overdue || 0) >= 30);
                } else if (quickDatePreset === 'today') {
                    const todayStr = new Date().toISOString().slice(0, 10);
                    filteredItems = filteredItems.filter((item: any) => item.created_at?.startsWith(todayStr));
                } else if (quickDatePreset === '7days') {
                    const sevenDaysAgo = new Date();
                    sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
                    filteredItems = filteredItems.filter((item: any) => new Date(item.created_at) >= sevenDaysAgo);
                } else if (quickDatePreset === 'this_month') {
                    const currentMonth = new Date().toISOString().slice(0, 7);
                    filteredItems = filteredItems.filter((item: any) => item.created_at?.startsWith(currentMonth));
                }

                setLogs(filteredItems);
                setPagination({
                    current_page: logsRes.data.current_page || page,
                    last_page: logsRes.data.last_page || 1,
                    total: logsRes.data.total || filteredItems.length,
                    per_page: 15,
                });
            }

            if (summaryRes && summaryRes.success) {
                setSummary(summaryRes.data);
            }
        } catch (err: any) {
            console.error('Lỗi khi tải nhật ký nhắc nợ:', err);
            showToast('error', err.message || 'Không thể kết nối đến máy chủ.');
        } finally {
            setLoading(false);
        }
    }, [searchQuery, statusFilter, overdueRangeFilter, blockFilter, quickDatePreset]);

    useEffect(() => {
        loadData(1);
    }, [loadData]);

    // Lắng nghe sự kiện Realtime tự động cập nhật qua WebSocket/SSE
    useRealtimeSync({
        channel: ['invoices.reminders', 'xuanhoa.debt-reminders', 'invoices'],
        onEvent: () => {
            loadData(pagination.current_page);
        },
    });

    // Xử lý sao chép mã HĐ
    const handleCopy = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopiedRef(text);
        showToast('success', `Đã sao chép: ${text}`);
        setTimeout(() => setCopiedRef(null), 2000);
    };

    // Mở modal xem trước email
    const handleOpenPreview = async (logId: string) => {
        setPreviewModalOpen(true);
        setLoadingPreview(true);
        try {
            const res = await invoiceApi.previewDebtReminderEmail(logId);
            if (res.success && res.data) {
                setPreviewData(res.data);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Không thể tạo bản xem trước email');
            setPreviewModalOpen(false);
        } finally {
            setLoadingPreview(false);
        }
    };

    // Thử lại gửi email đơn lẻ
    const handleRetry = async (logId: string) => {
        setRetryingLogId(logId);
        try {
            const res = await invoiceApi.retryDebtReminder(logId);
            if (res.success) {
                showToast('success', res.message || 'Đã đưa lại email vào hàng đợi Queue.');
                loadData(pagination.current_page);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Không thể thử lại gửi email');
        } finally {
            setRetryingLogId(null);
        }
    };

    // Kích hoạt quét tự động
    const handleTriggerAutoScan = async () => {
        setTriggeringAutoScan(true);
        try {
            const res = await invoiceApi.autoDispatchDebtReminders({
                overdue_only: autoScanOverdueOnly,
                days_before_due: autoScanDaysBefore,
                min_days_overdue: autoScanMinOverdueDays,
            });
            if (res.success) {
                showToast('success', res.message || 'Đã quét và xếp hàng nhắc nợ thành công.');
                setAutoScanModalOpen(false);
                loadData(1);
            }
        } catch (err: any) {
            showToast('error', err.message || 'Lỗi khi kích hoạt quét tự động');
        } finally {
            setTriggeringAutoScan(false);
        }
    };

    // Xuất CSV
    const handleExportCsv = () => {
        if (logs.length === 0) {
            showToast('error', 'Không có dữ liệu để xuất CSV');
            return;
        }

        const headers = [
            'STT',
            'Mã Hóa Đơn',
            'Căn Hộ',
            'Khối Tòa Nhà',
            'Người Nhận',
            'Email Nhận',
            'Dư Nợ (VNĐ)',
            'Hạn Thanh Toán',
            'Số Ngày Quá Hạn',
            'Trạng Thái Queue',
            'Thời Gian Xếp Hàng',
            'Thời Gian Đã Gửi',
            'Chi Tiết Lỗi',
        ];

        const rows = logs.map((log, index) => [
            index + 1,
            log.invoice?.invoice_number || 'N/A',
            log.apartment?.apartment_number || log.invoice?.apartment?.apartment_number || 'N/A',
            log.apartment?.block?.block_name || log.invoice?.apartment?.block?.block_name || 'N/A',
            log.recipient_name || 'N/A',
            log.recipient_email || 'N/A',
            Math.round(Number(log.debt_amount || 0)),
            log.due_date || 'N/A',
            log.days_overdue || 0,
            log.channel_status || 'N/A',
            log.created_at || 'N/A',
            log.sent_at || 'N/A',
            `"${(log.error_message || '').replace(/"/g, '""')}"`,
        ]);

        const csvContent = '\uFEFF' + [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', `Nhat_Ky_Email_Nhac_No_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        showToast('success', 'Đã xuất tệp CSV nhật ký hàng đợi thành công!');
    };

    // Chọn tất cả
    const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.checked) {
            setSelectedLogIds(logs.map(l => l.id));
        } else {
            setSelectedLogIds([]);
        }
    };

    const handleToggleSelect = (id: string) => {
        setSelectedLogIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    // Format tiền tệ
    const formatCurrency = (val: number | string) => {
        return Number(val || 0).toLocaleString('vi-VN') + ' đ';
    };

    // Format thời gian tương đối
    const formatRelativeTime = (dateStr: string) => {
        if (!dateStr) return 'Chưa gửi';
        const d = new Date(dateStr);
        const now = new Date();
        const diffMs = now.getTime() - d.getTime();
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Vừa xong';
        if (diffMins < 60) return `${diffMins} phút trước`;
        if (diffHours < 24) return `${diffHours} giờ trước`;
        if (diffDays === 1) return 'Hôm qua';
        if (diffDays < 7) return `${diffDays} ngày trước`;
        return d.toLocaleDateString('vi-VN');
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
                        <Mail className="w-3.5 h-3.5" />
                        <span>HỆ THỐNG KẾ TOÁN & THU TIỀN</span>
                    </div>
                    <h1 className="text-2xl sm:text-3xl font-black text-neutral-900 tracking-tight mt-1">
                        Hàng Đợi & Email Nhắc Nợ Tự Động
                    </h1>
                    <p className="text-xs sm:text-sm text-slate-500 max-w-3xl font-medium mt-1">
                        Giám sát tiến trình xử lý nền Laravel Queue, tự động gửi email nhắc nợ thông minh kèm mã VietQR gạch nợ tự động theo chu kỳ cước.
                    </p>
                </div>

                {onBackToList && (
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={onBackToList}
                            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 text-xs font-bold shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                        >
                            <ChevronLeft className="w-3.5 h-3.5 text-emerald-600" />
                            <span>Quay Lại Danh Sách Hóa Đơn</span>
                        </button>
                    </div>
                )}
            </div>

            {/* 4 THẺ KPI METRIC CARDS CHUẨN SASS-DB-KPI-CARD */}
            <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3.5">
                {/* Thẻ 1: Tổng lượt nhắc nợ */}
                <div className="sass-db-kpi-card">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">TỔNG LƯỢT NHẮC NỢ</span>
                        <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                            <Mail className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-neutral-900 font-mono tracking-tight">
                        {summary ? Number(summary.total_reminders || 0).toLocaleString('vi-VN') : 0}
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-sky-700 font-semibold truncate">
                        Đã lưu vết trong hệ thống
                    </div>
                </div>

                {/* Thẻ 2: Đã gửi thành công */}
                <div className="sass-db-kpi-card">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">ĐÃ GỬI THÀNH CÔNG</span>
                        <div className="kpi-icon-wrapper bg-emerald-50 text-emerald-600">
                            <CheckCircle2 className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-emerald-600 font-mono tracking-tight">
                        {summary ? Number(summary.sent_count || 0).toLocaleString('vi-VN') : 0}
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-emerald-700 font-semibold truncate">
                        Tỷ lệ thành công: <strong className="text-neutral-900 font-bold">{summary ? summary.success_rate : 100}%</strong>
                    </div>
                </div>

                {/* Thẻ 3: Đang chờ trong Queue */}
                <div className="sass-db-kpi-card">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">ĐANG CHỜ TRONG QUEUE</span>
                        <div className="kpi-icon-wrapper bg-amber-50 text-amber-600">
                            <Clock className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-amber-600 font-mono tracking-tight">
                        {summary ? Number(summary.queued_count || 0).toLocaleString('vi-VN') : 0}
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-amber-700 font-semibold truncate flex items-center gap-1">
                        <span className="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                        Đang xử lý nền bởi worker
                    </div>
                </div>

                {/* Thẻ 4: Tổng tiền nợ đã nhắc */}
                <div className="sass-db-kpi-card col-span-2 sm:col-span-1">
                    <div className="flex items-center justify-between">
                        <span className="text-[11px] sm:text-xs font-semibold text-slate-500">TỔNG TIỀN NỢ ĐÃ NHẮC</span>
                        <div className="kpi-icon-wrapper bg-rose-50 text-rose-600">
                            <AlertTriangle className="w-4 h-4" />
                        </div>
                    </div>
                    <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-rose-600 font-mono tracking-tight">
                        {Number(summary ? summary.total_debt_reminded : 0).toLocaleString('vi-VN')} <span className="text-xs font-normal text-slate-400">đ</span>
                    </div>
                    <div className="mt-1 text-[10px] sm:text-[11px] text-rose-700 font-semibold truncate">
                        <strong className="text-neutral-900 font-bold">{summary ? summary.overdue_reminders_count : 0}</strong> thông báo quá hạn
                    </div>
                </div>
            </div>

            {/* BỘ LỌC TÌM KIẾM ĐA CHIỀU */}
            <div className="bg-white border border-slate-200/80 p-4 sm:p-5 rounded-2xl shadow-xs space-y-4">
                <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
                    {/* Ô tìm kiếm từ khóa */}
                    <div className="relative flex-1">
                        <Search className="w-4 h-4 absolute left-3.5 top-3 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Tìm kiếm theo mã HĐ, căn hộ, email cư dân..."
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

                    {/* Các dropdown lọc */}
                    <div className="flex flex-wrap sm:flex-nowrap items-center gap-2">
                        {/* Lọc Trạng thái Queue */}
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 font-semibold focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all cursor-pointer"
                        >
                            <option value="ALL">Tất cả trạng thái Queue</option>
                            <option value="SENT">Đã gửi thành công (SENT)</option>
                            <option value="QUEUED">Đang chờ xử lý (QUEUED)</option>
                            <option value="FAILED">Thất bại / Lỗi (FAILED)</option>
                            <option value="CANCELLED">Đã hủy / Đã trả trước (CANCELLED)</option>
                        </select>

                        {/* Lọc Mức Độ Quá Hạn */}
                        <select
                            value={overdueRangeFilter}
                            onChange={(e) => setOverdueRangeFilter(e.target.value)}
                            className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 font-semibold focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all cursor-pointer"
                        >
                            <option value="ALL">Tất cả mức nợ</option>
                            <option value="OVERDUE_ONLY">Đã quá hạn (&gt; 0 ngày)</option>
                            <option value="OVERDUE_7">Quá hạn &ge; 7 ngày</option>
                            <option value="OVERDUE_30">Quá hạn &ge; 30 ngày</option>
                            <option value="DUE_SOON">Sắp đến hạn (0 ngày)</option>
                        </select>

                        {/* Lọc Khối nhà */}
                        <select
                            value={blockFilter}
                            onChange={(e) => setBlockFilter(e.target.value)}
                            className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 font-semibold focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all cursor-pointer"
                        >
                            <option value="">Tất cả khối tòa nhà</option>
                            {blocks.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.block_name} ({b.block_code})
                                </option>
                            ))}
                        </select>

                        {/* Preset thời gian */}
                        <select
                            value={quickDatePreset}
                            onChange={(e: any) => setQuickDatePreset(e.target.value)}
                            className="bg-slate-50/70 hover:bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs text-slate-700 font-semibold focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all cursor-pointer"
                        >
                            <option value="all">Thời gian: Tất cả</option>
                            <option value="today">Hôm nay</option>
                            <option value="7days">7 ngày gần nhất</option>
                            <option value="this_month">Trong tháng này</option>
                            <option value="overdue_30">Quá hạn trên 30 ngày</option>
                        </select>
                    </div>
                </div>

                {/* Thanh trạng thái lọc và các nút hành động */}
                <div className="flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-slate-200/80 text-xs">
                    <div className="flex flex-wrap items-center gap-2.5 text-slate-500">
                        <span>Đang hiển thị <strong className="text-slate-800 font-bold">{logs.length}</strong> bản ghi nhật ký</span>
                        {selectedLogIds.length > 0 && (
                            <span className="bg-sky-50 text-sky-700 font-semibold px-2 py-0.5 rounded-full border border-sky-200 shadow-2xs">
                                Đã chọn {selectedLogIds.length} mục
                            </span>
                        )}
                        <span className="text-slate-300 hidden sm:inline">•</span>
                        <div className="flex items-center gap-1.5">
                            <span className="text-[11px] text-slate-400 font-semibold uppercase tracking-wider font-mono">HĐ nợ chưa gửi 24h:</span>
                            <span className="font-bold font-mono text-amber-600">
                                {summary ? summary.unreminded_overdue_count : 0} hóa đơn
                            </span>
                        </div>
                    </div>

                    <div className="flex items-center gap-2.5">
                        {(searchQuery || statusFilter !== 'ALL' || overdueRangeFilter !== 'ALL' || blockFilter || quickDatePreset !== 'all') && (
                            <button
                                type="button"
                                onClick={() => {
                                    setSearchQuery('');
                                    setStatusFilter('ALL');
                                    setOverdueRangeFilter('ALL');
                                    setBlockFilter('');
                                    setQuickDatePreset('all');
                                }}
                                className="text-xs text-rose-600 font-bold hover:underline flex items-center gap-1 cursor-pointer mr-1"
                            >
                                <X className="w-3.5 h-3.5" />
                                <span>Xóa bộ lọc</span>
                            </button>
                        )}

                        <button
                            type="button"
                            onClick={() => setAutoScanModalOpen(true)}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-sky-600 hover:bg-sky-700 text-white shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
                        >
                            <Zap className="w-3.5 h-3.5" />
                            <span>Quét Ngay</span>
                        </button>

                        <button
                            type="button"
                            onClick={handleExportCsv}
                            disabled={loading || logs.length === 0}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 text-xs font-bold shadow-2xs transition-all active:scale-[0.98] disabled:opacity-50 cursor-pointer"
                            title="Tải file CSV sao kê danh sách nhật ký hàng đợi"
                        >
                            <Download className="w-3.5 h-3.5 text-teal-600" />
                            <span>Xuất Nhật Ký CSV</span>
                        </button>
                    </div>
                </div>
            </div>

            {/* BẢNG DANH SÁCH NHẬT KÝ HÀNG ĐỢI EMAIL */}
            <div className="bg-white rounded-2xl shadow-xs border border-slate-200/80 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr className="bg-slate-50/80 border-b border-slate-200/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider font-mono">
                                <th className="p-4 w-10 text-center">
                                    <input
                                        type="checkbox"
                                        onChange={handleSelectAll}
                                        checked={logs.length > 0 && selectedLogIds.length === logs.length}
                                        className="rounded border-slate-300 text-sky-600 focus:ring-sky-500 cursor-pointer"
                                    />
                                </th>
                                <th className="p-4">Thời gian</th>
                                <th className="p-4">Hóa Đơn & Kỳ Cước</th>
                                <th className="p-4">Căn Hộ</th>
                                <th className="p-4">Người Nhận / Email</th>
                                <th className="p-4 text-right">Dư Nợ Nhắc</th>
                                <th className="p-4 text-center">Quá Hạn</th>
                                <th className="p-4 text-center">Trạng Thái Queue</th>
                                <th className="p-4 text-right">Thao Tác</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {loading ? (
                                Array.from({ length: 5 }).map((_, i) => (
                                    <tr key={i} className="animate-pulse">
                                        <td className="p-4 text-center"><div className="w-4 h-4 bg-slate-200 rounded mx-auto"></div></td>
                                        <td className="p-4"><div className="h-4 bg-slate-200 rounded w-24 mb-1"></div><div className="h-3 bg-slate-100 rounded w-16"></div></td>
                                        <td className="p-4"><div className="h-4 bg-slate-200 rounded w-32 mb-1"></div><div className="h-3 bg-slate-100 rounded w-20"></div></td>
                                        <td className="p-4"><div className="h-4 bg-slate-200 rounded w-16 mb-1"></div><div className="h-3 bg-slate-100 rounded w-24"></div></td>
                                        <td className="p-4"><div className="h-4 bg-slate-200 rounded w-28 mb-1"></div><div className="h-3 bg-slate-100 rounded w-36"></div></td>
                                        <td className="p-4 text-right"><div className="h-4 bg-slate-200 rounded w-20 ml-auto"></div></td>
                                        <td className="p-4 text-center"><div className="h-5 bg-slate-200 rounded w-16 mx-auto"></div></td>
                                        <td className="p-4 text-center"><div className="h-6 bg-slate-200 rounded w-20 mx-auto"></div></td>
                                        <td className="p-4 text-right"><div className="h-8 bg-slate-200 rounded w-20 ml-auto"></div></td>
                                    </tr>
                                ))
                            ) : logs.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="py-16 text-center text-slate-400">
                                        <div className="flex flex-col items-center justify-center gap-3">
                                            <div className="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-slate-400">
                                                <Mail className="w-8 h-8 stroke-[1.5]" />
                                            </div>
                                            <p className="text-base font-bold text-slate-700">Không tìm thấy thông báo nhắc nợ nào</p>
                                            <p className="text-xs text-slate-500 max-w-sm font-medium">
                                                Hãy bấm "Quét & Xếp Hàng Tự Động" để quét các hóa đơn nợ đọng và đưa vào hàng đợi Queue gửi cư dân.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                logs.map((log: any) => {
                                    const aptNumber = log.apartment?.apartment_number || log.invoice?.apartment?.apartment_number || 'N/A';
                                    const blockName = log.apartment?.block?.block_name || log.invoice?.apartment?.block?.block_name || 'N/A';
                                    const invNumber = log.invoice?.invoice_number || 'N/A';
                                    const billingPeriod = log.invoice?.billing_period || 'N/A';
                                    const isSelected = selectedLogIds.includes(log.id);

                                    return (
                                        <tr
                                            key={log.id}
                                            className={`hover:bg-slate-50/80 transition-colors ${
                                                isSelected ? 'bg-sky-50/50' : ''
                                            }`}
                                        >
                                            {/* Checkbox */}
                                            <td className="p-4 text-center">
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => handleToggleSelect(log.id)}
                                                    className="rounded border-slate-300 text-sky-600 focus:ring-sky-500 cursor-pointer"
                                                />
                                            </td>

                                            {/* Thời gian */}
                                            <td className="p-4">
                                                <div className="text-xs font-bold text-slate-900">
                                                    {formatRelativeTime(log.sent_at || log.created_at)}
                                                </div>
                                                <div className="text-[11px] text-slate-400 font-mono mt-0.5">
                                                    {new Date(log.created_at).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })}{' '}
                                                    {new Date(log.created_at).toLocaleDateString('vi-VN')}
                                                </div>
                                            </td>

                                            {/* Hóa đơn & Kỳ cước */}
                                            <td className="p-4">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="font-mono text-xs font-bold text-sky-600">
                                                        {invNumber}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleCopy(invNumber)}
                                                        className="text-slate-400 hover:text-slate-600 p-0.5 rounded transition-colors cursor-pointer"
                                                        title="Sao chép mã hóa đơn"
                                                    >
                                                        {copiedRef === invNumber ? (
                                                            <Check className="w-3.5 h-3.5 text-emerald-500" />
                                                        ) : (
                                                            <Copy className="w-3.5 h-3.5" />
                                                        )}
                                                    </button>
                                                </div>
                                                <div className="text-[11px] text-slate-500 mt-0.5">
                                                    Kỳ cước: <span className="font-semibold text-slate-700">{billingPeriod}</span>
                                                </div>
                                            </td>

                                            {/* Căn hộ */}
                                            <td className="p-4">
                                                <div className="font-bold text-slate-800 text-xs">
                                                    {aptNumber}
                                                </div>
                                                <div className="text-[11px] text-slate-400 mt-0.5">
                                                    {blockName}
                                                </div>
                                            </td>

                                            {/* Người nhận */}
                                            <td className="p-4">
                                                <div className="text-xs font-bold text-slate-900">
                                                    {log.recipient_name}
                                                </div>
                                                <div className="text-[11px] text-slate-500 flex items-center gap-1 font-mono mt-0.5">
                                                    <Mail className="w-3 h-3 text-slate-400" />
                                                    <span>{log.recipient_email}</span>
                                                </div>
                                            </td>

                                            {/* Dư nợ */}
                                            <td className="p-4 text-right">
                                                <div className="font-mono text-sm font-bold text-rose-600">
                                                    {formatCurrency(log.debt_amount)}
                                                </div>
                                                <div className="text-[11px] text-slate-400 mt-0.5 font-medium">
                                                    Hạn: {log.due_date ? String(log.due_date).slice(0, 10).split('-').reverse().join('/') : 'N/A'}
                                                </div>
                                            </td>

                                            {/* Quá hạn */}
                                            <td className="p-4 text-center">
                                                {log.days_overdue > 0 ? (
                                                    <span className="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs whitespace-nowrap">
                                                        Quá hạn {log.days_overdue} ngày
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs whitespace-nowrap">
                                                        Trong hạn
                                                    </span>
                                                )}
                                            </td>

                                            {/* Trạng thái Queue */}
                                            <td className="p-4 text-center">
                                                {log.channel_status === 'SENT' ? (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs whitespace-nowrap">
                                                        <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600 shrink-0" />
                                                        <span>Đã Gửi (SENT)</span>
                                                    </span>
                                                ) : log.channel_status === 'QUEUED' ? (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200 shadow-2xs whitespace-nowrap">
                                                        <Clock className="w-3.5 h-3.5 text-amber-600 animate-pulse shrink-0" />
                                                        <span>Đang Chờ (QUEUED)</span>
                                                    </span>
                                                ) : log.channel_status === 'FAILED' ? (
                                                    <div className="group relative inline-block">
                                                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs cursor-help whitespace-nowrap">
                                                            <AlertTriangle className="w-3.5 h-3.5 text-rose-600 shrink-0" />
                                                            <span>Thất Bại (FAILED)</span>
                                                        </span>
                                                        {log.error_message && (
                                                            <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-64 p-2.5 bg-slate-900 text-white text-[11px] rounded-xl shadow-xl opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity z-20 font-medium leading-relaxed">
                                                                {log.error_message}
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700 border border-slate-200 shadow-2xs whitespace-nowrap">
                                                        {log.channel_status}
                                                    </span>
                                                )}
                                            </td>

                                            {/* Thao tác */}
                                            <td className="p-4 text-right pr-6">
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenPreview(log.id)}
                                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold text-sky-700 bg-sky-50 hover:bg-sky-100 border border-sky-200 shadow-2xs transition-all active:scale-[0.98] cursor-pointer whitespace-nowrap"
                                                        title="Xem trước nội dung email mẫu"
                                                    >
                                                        <Eye className="w-3.5 h-3.5 text-sky-600 shrink-0" />
                                                        <span>Xem Email</span>
                                                    </button>

                                                    {(log.channel_status === 'FAILED' || log.channel_status === 'SENT') ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRetry(log.id)}
                                                            disabled={retryingLogId === log.id}
                                                            className="inline-flex items-center justify-center w-8 h-8 rounded-xl text-amber-600 hover:text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200 shadow-2xs transition-all active:scale-[0.95] disabled:opacity-50 cursor-pointer group shrink-0"
                                                            title="Gửi lại thông báo nhắc nợ này (Đẩy vào Queue)"
                                                        >
                                                            {retryingLogId === log.id ? (
                                                                <RefreshCw className="w-3.5 h-3.5 animate-spin text-amber-600" />
                                                            ) : (
                                                                <Send className="w-3.5 h-3.5 text-amber-600 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 transition-transform duration-200" />
                                                            )}
                                                        </button>
                                                    ) : (
                                                        <div className="w-8 h-8 shrink-0" aria-hidden="true" />
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

                {/* Phân trang */}
                {pagination.total > 0 && (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-6 py-4 bg-slate-50/50 border-t border-slate-200/80 text-xs text-slate-500 font-medium">
                        <div>
                            Hiển thị <strong className="text-slate-800 font-bold">{logs.length}</strong> trên tổng số <strong className="text-slate-800 font-bold">{pagination.total}</strong> lượt nhắc
                        </div>
                        <div className="flex items-center gap-1.5">
                            <button
                                type="button"
                                onClick={() => loadData(pagination.current_page - 1)}
                                disabled={pagination.current_page <= 1 || loading}
                                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold shadow-2xs transition-all active:scale-[0.98] disabled:opacity-40 cursor-pointer"
                            >
                                <ChevronLeft className="w-3.5 h-3.5" />
                                <span>Trước</span>
                            </button>
                            <span className="px-3 py-1.5 font-bold font-mono text-slate-800 bg-white border border-slate-200 rounded-xl shadow-2xs">
                                {pagination.current_page} / {pagination.last_page}
                            </span>
                            <button
                                type="button"
                                onClick={() => loadData(pagination.current_page + 1)}
                                disabled={pagination.current_page >= pagination.last_page || loading}
                                className="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold shadow-2xs transition-all active:scale-[0.98] disabled:opacity-40 cursor-pointer"
                            >
                                <span>Sau</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* MODAL 1: XEM TRƯỚC NỘI DUNG EMAIL NHẮC NỢ (PREVIEW MODAL) */}
            {previewModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs">
                    <div className="bg-white rounded-2xl shadow-2xl border border-slate-200/80 max-w-2xl w-full max-h-[92vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                        {/* Header Modal */}
                        <div className="p-5 sm:p-6 border-b border-slate-200/80 flex items-center justify-between bg-white">
                            <div>
                                <span className="text-[10px] uppercase font-bold tracking-widest text-sky-600 font-mono flex items-center gap-1.5">
                                    <Mail className="w-3.5 h-3.5" />
                                    <span>BẢN XEM TRƯỚC EMAIL MẪU (HTML PREVIEW)</span>
                                </span>
                                <h3 className="text-lg font-black text-neutral-900 tracking-tight mt-1">
                                    {previewData ? previewData.subject : 'Đang tải nội dung email...'}
                                </h3>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPreviewModalOpen(false)}
                                className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Body Modal Preview */}
                        <div className="p-5 sm:p-6 overflow-y-auto flex-1 bg-slate-50/50">
                            {loadingPreview ? (
                                <div className="py-16 text-center text-slate-400">
                                    <RefreshCw className="w-8 h-8 animate-spin mx-auto mb-3 text-sky-600" />
                                    <p className="text-sm font-semibold text-slate-600">Đang kết xuất giao diện email mẫu...</p>
                                </div>
                            ) : previewData ? (
                                <div className="space-y-4">
                                    {/* Thông tin metadata người nhận */}
                                    <div className="bg-white p-4 rounded-xl border border-slate-200/80 text-xs grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-slate-600 shadow-2xs">
                                        <div>Người nhận: <strong className="text-neutral-900 font-bold">{previewData.recipient_name}</strong></div>
                                        <div>Email: <strong className="text-sky-600 font-mono font-bold">{previewData.recipient_email}</strong></div>
                                        <div>Hóa đơn: <strong className="font-mono text-slate-800 font-bold">{previewData.invoice_number}</strong></div>
                                        <div>Dư nợ: <strong className="text-rose-600 font-mono font-bold">{formatCurrency(previewData.debt_amount)}</strong></div>
                                    </div>

                                    {/* HTML Render */}
                                    <div className="bg-white p-4 rounded-xl shadow-inner border border-slate-200/80 overflow-x-auto text-slate-900">
                                        <div
                                            dangerouslySetInnerHTML={{ __html: previewData.html }}
                                            className="email-preview-container"
                                        />
                                    </div>
                                </div>
                            ) : null}
                        </div>

                        {/* Footer Modal */}
                        <div className="p-4 bg-white border-t border-slate-200/80 flex items-center justify-between">
                            <span className="text-xs text-slate-500 font-medium">
                                Email được gửi tự động qua dịch vụ Mail facade & Queue của Laravel.
                            </span>
                            <button
                                type="button"
                                onClick={() => setPreviewModalOpen(false)}
                                className="px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 transition-colors cursor-pointer"
                            >
                                Đóng
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 2: QUÉT & XẾP HÀNG TỰ ĐỘNG (AUTO DISPATCH MODAL) */}
            {autoScanModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs">
                    <div className="bg-white rounded-2xl shadow-2xl border border-slate-200/80 max-w-lg w-full overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                        {/* Header Modal */}
                        <div className="p-5 sm:p-6 border-b border-slate-200/80 flex items-center justify-between bg-white">
                            <div className="flex items-center gap-3">
                                <div className="w-10 h-10 rounded-xl bg-sky-50 border border-sky-200 flex items-center justify-center text-sky-600 shrink-0">
                                    <Zap className="w-5 h-5" />
                                </div>
                                <div>
                                    <h3 className="text-lg font-black text-neutral-900 tracking-tight">Quét & Xếp Hàng Nhắc Nợ</h3>
                                    <p className="text-xs text-slate-500 font-medium mt-0.5">Tự động đẩy hóa đơn nợ vào hàng đợi Queue xử lý nền</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setAutoScanModalOpen(false)}
                                className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Body Modal */}
                        <div className="p-5 sm:p-6 space-y-4">
                            <div className="p-4 rounded-xl bg-sky-50 border border-sky-200 text-xs text-slate-600 space-y-1">
                                <p className="font-bold flex items-center gap-1.5 text-neutral-900">
                                    <ShieldCheck className="w-4 h-4 text-sky-600 shrink-0" />
                                    Quy tắc bảo vệ chống gửi trùng lặp (Anti-Spam):
                                </p>
                                <p className="font-medium text-slate-600">
                                    Hệ thống tự động bỏ qua các hóa đơn đã được gửi nhắc nợ trong vòng <strong className="text-slate-800">24 giờ qua</strong> để tránh làm phiền cư dân.
                                </p>
                            </div>

                            <div className="space-y-3">
                                <label className="text-xs font-bold text-slate-700 uppercase tracking-wider font-mono">
                                    Phạm Vi Hóa Đơn Cần Quét
                                </label>

                                <div className="space-y-2">
                                    <label
                                        onClick={() => setAutoScanOverdueOnly(true)}
                                        className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                                            autoScanOverdueOnly
                                                ? 'border-sky-500 bg-sky-50/50 shadow-2xs'
                                                : 'border-slate-200 hover:bg-slate-50'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="scanScope"
                                            checked={autoScanOverdueOnly}
                                            onChange={() => setAutoScanOverdueOnly(true)}
                                            className="mt-0.5 text-sky-600 focus:ring-sky-500"
                                        />
                                        <div className="flex-1">
                                            <div className="text-sm font-bold text-neutral-900">
                                                Chỉ quét các hóa đơn đã QUÁ HẠN (Khuyên dùng)
                                            </div>
                                            <div className="text-xs text-slate-500 mt-1 font-medium flex flex-wrap items-center gap-1.5">
                                                <span>Quét các hóa đơn quá hạn từ:</span>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    max="365"
                                                    value={autoScanMinOverdueDays}
                                                    onChange={(e) => setAutoScanMinOverdueDays(Math.max(0, parseInt(e.target.value) || 0))}
                                                    onClick={(e) => e.stopPropagation()}
                                                    className="inline-block w-14 px-2 py-0.5 rounded border border-slate-300 text-center font-bold text-xs bg-white text-slate-900"
                                                />
                                                <span>ngày trở lên (nhập 0 = tất cả hóa đơn quá hạn).</span>
                                            </div>
                                        </div>
                                    </label>

                                    <label
                                        onClick={() => setAutoScanOverdueOnly(false)}
                                        className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                                            !autoScanOverdueOnly
                                                ? 'border-sky-500 bg-sky-50/50 shadow-2xs'
                                                : 'border-slate-200 hover:bg-slate-50'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="scanScope"
                                            checked={!autoScanOverdueOnly}
                                            onChange={() => setAutoScanOverdueOnly(false)}
                                            className="mt-0.5 text-sky-600 focus:ring-sky-500"
                                        />
                                        <div>
                                            <div className="text-sm font-bold text-neutral-900">
                                                Quét cả hóa đơn SẮP ĐẾN HẠN
                                            </div>
                                            <div className="text-xs text-slate-500 mt-0.5 font-medium">
                                                Gửi thông báo nhắc nhở cho các hóa đơn sắp đến hạn trong vòng:
                                                <input
                                                    type="number"
                                                    min="1"
                                                    max="15"
                                                    value={autoScanDaysBefore}
                                                    onChange={(e) => setAutoScanDaysBefore(parseInt(e.target.value) || 3)}
                                                    onClick={(e) => e.stopPropagation()}
                                                    className="inline-block w-14 mx-1.5 px-2 py-0.5 rounded border border-slate-300 text-center font-bold text-xs bg-white text-slate-900"
                                                />
                                                ngày tới.
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        {/* Footer Modal */}
                        <div className="p-4 bg-slate-50 border-t border-slate-200/80 flex items-center justify-end gap-2.5">
                            <button
                                type="button"
                                onClick={() => setAutoScanModalOpen(false)}
                                disabled={triggeringAutoScan}
                                className="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 shadow-2xs transition-colors cursor-pointer disabled:opacity-50"
                            >
                                Hủy bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleTriggerAutoScan}
                                disabled={triggeringAutoScan}
                                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-sky-600 hover:bg-sky-700 border border-sky-600 shadow-2xs active:scale-[0.98] transition-all disabled:opacity-50 cursor-pointer"
                            >
                                {triggeringAutoScan ? (
                                    <>
                                        <RefreshCw className="w-4 h-4 animate-spin" />
                                        <span>Đang quét & xếp hàng...</span>
                                    </>
                                ) : (
                                    <>
                                        <Zap className="w-4 h-4" />
                                        <span>Bắt Đầu Xếp Hàng Queue</span>
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default DebtReminderManagement;
