import React, { useState, useEffect, useCallback } from 'react';
import { invoiceApi } from '../../Services/invoiceApi';

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
    }, [searchQuery, statusFilter, blockFilter, quickDatePreset]);

    useEffect(() => {
        loadData(1);
    }, [loadData]);

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
        <div className="space-y-4 pb-12">
            {/* Toast Notification */}
            {toast && (
                <div
                    className={`fixed top-4 right-4 z-50 flex items-center gap-3 px-5 py-3 rounded-xl shadow-2xl transition-all duration-300 transform translate-y-0 ${
                        toast.type === 'success'
                            ? 'bg-emerald-600 text-white'
                            : 'bg-rose-600 text-white'
                    }`}
                >
                    {toast.type === 'success' ? (
                        <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                        </svg>
                    ) : (
                        <svg className="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    )}
                    <span className="text-sm font-semibold">{toast.text}</span>
                </div>
            )}

            {/* HEADER CHUẨN CN5-10 */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-2xl shadow-xs border border-slate-200/80">
                <div>
                    <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-sky-600 mb-1">
                        <span>Hệ thống Kế toán & Thu tiền</span>
                        <span>•</span>
                        <span className="bg-sky-50 text-sky-700 px-2 py-0.5 rounded-full text-[11px] font-bold">
                            Chức năng #11 (Laravel Queue Debt Reminders)
                        </span>
                    </div>
                    <h1 className="text-2xl sm:text-3xl font-black text-neutral-900 tracking-tight flex items-center gap-3">
                        Hàng Đợi & Email Nhắc Nợ Tự Động
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 border border-emerald-200">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse mr-1.5"></span>
                            Queue Worker Live
                        </span>
                    </h1>
                    <p className="text-sm text-slate-500 mt-1 max-w-2xl">
                        Giám sát tiến trình xử lý nền Laravel Queue, tự động gửi email nhắc nợ thông minh kèm mã VietQR gạch nợ tự động theo chu kỳ cước.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2.5">
                    <button
                        onClick={() => setAutoScanModalOpen(true)}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm text-white bg-sky-600 hover:bg-sky-700 border border-sky-600 shadow-2xs active:scale-[0.98] transition-all"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <span>Quét & Xếp Hàng Tự Động</span>
                    </button>

                    <button
                        onClick={handleExportCsv}
                        className="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl font-semibold text-sm text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 active:scale-[0.98] transition-all"
                        title="Xuất bảng nhật ký ra file CSV"
                    >
                        <svg className="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>Xuất CSV</span>
                    </button>

                    <button
                        onClick={() => loadData(pagination.current_page)}
                        disabled={loading}
                        className="p-2.5 rounded-xl font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 border border-slate-200 active:scale-[0.98] transition-all disabled:opacity-50"
                        title="Làm mới dữ liệu"
                    >
                        <svg className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </div>
            </div>

            {/* THANH ĐIỀU HƯỚNG LIÊN KẾT NHANH GIỮA CÁC CHỨC NĂNG */}
            <div className="flex flex-wrap items-center gap-1.5 p-1.5 bg-slate-100/70 rounded-2xl">
                <button
                    onClick={() => onNavigateTab ? onNavigateTab('meter_readings') : (window.location.href = '/quan-ly/meter-readings')}
                    className="flex-shrink-0 px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-white hover:text-neutral-900 transition-all flex items-center gap-1.5"
                >
                    <span className="w-2 h-2 rounded-full bg-slate-400"></span>
                    CN6: Chỉ số Điện/Nước
                </button>
                <button
                    onClick={() => onNavigateTab ? onNavigateTab('invoices') : (window.location.href = '/quan-ly/invoices')}
                    className="flex-shrink-0 px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-white hover:text-neutral-900 transition-all flex items-center gap-1.5"
                >
                    <span className="w-2 h-2 rounded-full bg-slate-400"></span>
                    CN7: Quản lý Hóa Đơn
                </button>
                <button
                    onClick={() => onNavigateTab ? onNavigateTab('thu_tien_gach_no') : (window.location.href = '/quan-ly/thu-tien-gach-no')}
                    className="flex-shrink-0 px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-white hover:text-neutral-900 transition-all flex items-center gap-1.5"
                >
                    <span className="w-2 h-2 rounded-full bg-slate-400"></span>
                    CN8: Thu Tiền & Gạch Nợ
                </button>
                <button
                    onClick={() => onNavigateTab ? onNavigateTab('payment_history') : (window.location.href = '/quan-ly/payment-history')}
                    className="flex-shrink-0 px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-white hover:text-neutral-900 transition-all flex items-center gap-1.5"
                >
                    <span className="w-2 h-2 rounded-full bg-slate-400"></span>
                    CN10: Lịch Sử Giao Dịch
                </button>
                <button
                    className="flex-shrink-0 px-3.5 py-1.5 rounded-xl text-xs font-semibold text-sky-700 bg-white border border-slate-200/80 shadow-2xs transition-all flex items-center gap-1.5"
                >
                    <span className="w-2 h-2 rounded-full bg-sky-600 animate-pulse"></span>
                    CN11: Email Nhắc Nợ & Queue (Đang xem)
                </button>
                <button
                    onClick={() => onNavigateTab ? onNavigateTab('financial_reports') : (window.location.href = '/quan-ly/bao-cao-tai-chinh')}
                    className="flex-shrink-0 px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-white hover:text-neutral-900 transition-all flex items-center gap-1.5"
                >
                    <span className="w-2 h-2 rounded-full bg-slate-400"></span>
                    CN13: Báo Cáo Tài Chính
                </button>
            </div>

            {/* LIVE QUEUE STATUS BANNER */}
            <div className="bg-white rounded-2xl p-4 sm:p-5 shadow-xs border border-slate-200/80 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div className="flex items-center gap-3.5">
                    <div className="w-10 h-10 rounded-xl bg-sky-50 border border-sky-200 flex items-center justify-center text-sky-600 flex-shrink-0">
                        <svg className="w-5 h-5 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="text-sm font-black text-neutral-900">Kiến Trúc Hàng Đợi Bất Đồng Bộ (Laravel Queue & Jobs)</span>
                            <span className="bg-emerald-100 text-emerald-700 text-[10px] font-bold px-2 py-0.5 rounded-md border border-emerald-200">
                                ACTIVE
                            </span>
                        </div>
                        <p className="text-xs text-slate-500 mt-0.5">
                            Driver: <code className="bg-slate-100 px-1 py-0.5 rounded text-sky-700">database / sync</code> • Retry attempts: 3 lần • Backoff: 30s • Tự động kích hoạt qua Artisan <code className="bg-slate-100 px-1 py-0.5 rounded text-amber-700">invoices:send-debt-reminders</code>
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-3 self-end md:self-center">
                    <div className="text-right">
                        <div className="text-[11px] text-slate-400 font-medium">HĐ nợ chưa gửi nhắc 24h</div>
                        <div className="text-base font-black font-mono text-amber-600">
                            {summary ? summary.unreminded_overdue_count : 0} hóa đơn
                        </div>
                    </div>
                    <button
                        onClick={() => setAutoScanModalOpen(true)}
                        className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-600 hover:bg-sky-700 text-white border border-sky-600 shadow-2xs transition-all"
                    >
                        Quét Ngay
                    </button>
                </div>
            </div>

            {/* 4 THẺ KPI GLASSMORPHISM */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Card 1: Tổng lượt nhắc nợ */}
                <div className="relative overflow-hidden bg-white p-5 rounded-2xl shadow-xs border border-slate-200/80">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-bold uppercase tracking-wider text-slate-500">
                            Tổng Lượt Nhắc Nợ
                        </span>
                        <div className="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center">
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-2xl font-black text-neutral-900 tracking-tight font-mono">
                            {summary ? summary.total_reminders.toLocaleString('vi-VN') : 0}
                        </span>
                        <span className="text-xs font-medium text-slate-500">lượt gửi</span>
                    </div>
                    <div className="mt-2 text-xs text-sky-600 font-medium flex items-center gap-1">
                        <span>Đã lưu vết trong hệ thống</span>
                    </div>
                </div>

                {/* Card 2: Đã gửi thành công */}
                <div className="relative overflow-hidden bg-white p-5 rounded-2xl shadow-xs border border-slate-200/80">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-bold uppercase tracking-wider text-slate-500">
                            Đã Gửi Thành Công
                        </span>
                        <div className="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-2xl font-black text-emerald-600 tracking-tight font-mono">
                            {summary ? summary.sent_count.toLocaleString('vi-VN') : 0}
                        </span>
                        <span className="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">
                            {summary ? summary.success_rate : 100}% thành công
                        </span>
                    </div>
                    <div className="mt-2 text-xs text-slate-500">
                        Cư dân đã nhận qua hộp thư
                    </div>
                </div>

                {/* Card 3: Đang trong Queue xử lý */}
                <div className="relative overflow-hidden bg-white p-5 rounded-2xl shadow-xs border border-slate-200/80">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-bold uppercase tracking-wider text-slate-500">
                            Đang Chờ Trong Queue
                        </span>
                        <div className="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                            <svg className="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </div>
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-2xl font-black text-amber-600 tracking-tight font-mono">
                            {summary ? summary.queued_count.toLocaleString('vi-VN') : 0}
                        </span>
                        <span className="text-xs font-medium text-slate-500">jobs chờ dispatch</span>
                    </div>
                    <div className="mt-2 text-xs text-amber-600 font-medium flex items-center gap-1">
                        <span className="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                        <span>Đang xử lý nền bởi worker</span>
                    </div>
                </div>

                {/* Card 4: Tổng tiền công nợ được nhắc */}
                <div className="relative overflow-hidden bg-white p-5 rounded-2xl shadow-xs border border-slate-200/80">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-bold uppercase tracking-wider text-slate-500">
                            Tổng Tiền Nợ Đã Nhắc
                        </span>
                        <div className="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <div className="mt-3 flex items-baseline gap-2">
                        <span className="text-xl sm:text-2xl font-black text-rose-600 tracking-tight font-mono">
                            {formatCurrency(summary ? summary.total_debt_reminded : 0)}
                        </span>
                    </div>
                    <div className="mt-2 text-xs text-slate-500">
                        {summary ? summary.overdue_reminders_count : 0} thông báo quá hạn
                    </div>
                </div>
            </div>

            {/* BỘ LỌC TÌM KIẾM ĐA CHIỀU */}
            <div className="bg-white p-5 rounded-2xl shadow-xs border border-slate-200/80 space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                    {/* Tìm kiếm */}
                    <div className="relative">
                        <input
                            type="text"
                            placeholder="Mã HĐ, căn hộ, email cư dân..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full pl-9 pr-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 text-slate-900"
                        />
                        <svg className="w-4 h-4 text-slate-400 absolute left-3 top-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>

                    {/* Lọc Trạng thái Queue */}
                    <div>
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 text-slate-900 font-medium"
                        >
                            <option value="ALL">Tất cả trạng thái Queue</option>
                            <option value="SENT">Đã gửi thành công (SENT)</option>
                            <option value="QUEUED">Đang chờ xử lý (QUEUED)</option>
                            <option value="FAILED">Thất bại / Lỗi (FAILED)</option>
                            <option value="CANCELLED">Đã hủy / Đã trả trước (CANCELLED)</option>
                        </select>
                    </div>

                    {/* Lọc Khối nhà */}
                    <div>
                        <select
                            value={blockFilter}
                            onChange={(e) => setBlockFilter(e.target.value)}
                            className="w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 text-slate-900 font-medium"
                        >
                            <option value="">Tất cả khối tòa nhà</option>
                            {blocks.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.block_name} ({b.block_code})
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Preset nhanh */}
                    <div>
                        <select
                            value={quickDatePreset}
                            onChange={(e: any) => setQuickDatePreset(e.target.value)}
                            className="w-full px-3 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500 text-slate-900 font-medium"
                        >
                            <option value="all">Thời gian: Tất cả</option>
                            <option value="today">Hôm nay</option>
                            <option value="7days">7 ngày gần nhất</option>
                            <option value="this_month">Trong tháng này</option>
                            <option value="overdue_30">Quá hạn trên 30 ngày</option>
                        </select>
                    </div>
                </div>

                {/* Thanh trạng thái lọc và nút Reset */}
                <div className="flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-200/80 text-xs">
                    <div className="flex items-center gap-2 text-slate-500">
                        <span>Đang hiển thị <strong>{logs.length}</strong> bản ghi nhật ký</span>
                        {selectedLogIds.length > 0 && (
                            <span className="bg-sky-50 text-sky-700 font-semibold px-2 py-0.5 rounded-full">
                                Đã chọn {selectedLogIds.length} mục
                            </span>
                        )}
                    </div>

                    {(searchQuery || statusFilter !== 'ALL' || blockFilter || quickDatePreset !== 'all') && (
                        <button
                            onClick={() => {
                                setSearchQuery('');
                                setStatusFilter('ALL');
                                setBlockFilter('');
                                setQuickDatePreset('all');
                            }}
                            className="text-xs text-rose-600 font-semibold hover:underline flex items-center gap-1"
                        >
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <span>Xóa bộ lọc</span>
                        </button>
                    )}
                </div>
            </div>

            {/* BẢNG DANH SÁCH NHẬT KÝ HÀNG ĐỢI EMAIL */}
            <div className="bg-white rounded-2xl shadow-xs border border-slate-200/80 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr className="bg-slate-100/50 border-b border-slate-200/80 text-[10px] font-bold text-slate-500 uppercase tracking-wider">
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
                                    <td colSpan={9} className="py-12 text-center text-slate-400">
                                        <div className="flex flex-col items-center justify-center gap-3">
                                            <div className="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-slate-400">
                                                <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                            <p className="text-base font-semibold text-slate-700">Không tìm thấy thông báo nhắc nợ nào</p>
                                            <p className="text-xs text-slate-500 max-w-sm">
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
                                            className={`hover:bg-slate-50 transition-colors ${
                                                isSelected ? 'bg-sky-50/40' : ''
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
                                                <div className="text-xs font-semibold text-slate-900">
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
                                                        onClick={() => handleCopy(invNumber)}
                                                        className="text-slate-400 hover:text-slate-600 p-0.5 rounded transition-colors"
                                                        title="Sao chép mã hóa đơn"
                                                    >
                                                        {copiedRef === invNumber ? (
                                                            <svg className="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        ) : (
                                                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                            </svg>
                                                        )}
                                                    </button>
                                                </div>
                                                <div className="text-[11px] text-slate-500">
                                                    Kỳ cước: <span className="font-semibold">{billingPeriod}</span>
                                                </div>
                                            </td>

                                            {/* Căn hộ */}
                                            <td className="p-4">
                                                <div className="font-bold text-slate-800 text-xs">
                                                    {aptNumber}
                                                </div>
                                                <div className="text-[11px] text-slate-400">
                                                    {blockName}
                                                </div>
                                            </td>

                                            {/* Người nhận */}
                                            <td className="p-4">
                                                <div className="text-xs font-semibold text-slate-900">
                                                    {log.recipient_name}
                                                </div>
                                                <div className="text-[11px] text-slate-500 flex items-center gap-1 font-mono">
                                                    <svg className="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                                    </svg>
                                                    <span>{log.recipient_email}</span>
                                                </div>
                                            </td>

                                            {/* Dư nợ */}
                                            <td className="p-4 text-right">
                                                <div className="font-mono text-sm font-bold text-rose-600">
                                                    {formatCurrency(log.debt_amount)}
                                                </div>
                                                <div className="text-[11px] text-slate-400">
                                                    Hạn: {log.due_date ? String(log.due_date).slice(0, 10).split('-').reverse().join('/') : 'N/A'}
                                                </div>
                                            </td>

                                            {/* Quá hạn */}
                                            <td className="p-4 text-center">
                                                {log.days_overdue > 0 ? (
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-rose-100 text-rose-700 border border-rose-200">
                                                        Quá hạn {log.days_overdue} ngày
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        Trong hạn
                                                    </span>
                                                )}
                                            </td>

                                            {/* Trạng thái Queue */}
                                            <td className="p-4 text-center">
                                                {log.channel_status === 'SENT' ? (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700 border border-emerald-200">
                                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                        Đã Gửi (SENT)
                                                    </span>
                                                ) : log.channel_status === 'QUEUED' ? (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700 border border-amber-200">
                                                        <span className="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                                        Đang Chờ (QUEUED)
                                                    </span>
                                                ) : log.channel_status === 'FAILED' ? (
                                                    <div className="group relative inline-block">
                                                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700 border border-rose-200 cursor-help">
                                                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                            </svg>
                                                            Thất bại (FAILED)
                                                        </span>
                                                        {log.error_message && (
                                                            <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-64 p-2 bg-slate-900 text-white text-[11px] rounded-lg shadow-xl opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity z-20">
                                                                {log.error_message}
                                                            </div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-700">
                                                        {log.channel_status}
                                                    </span>
                                                )}
                                            </td>

                                            {/* Thao tác */}
                                            <td className="p-4 text-right">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    <button
                                                        onClick={() => handleOpenPreview(log.id)}
                                                        className="px-2.5 py-1 rounded-lg text-xs font-semibold text-sky-700 bg-sky-50 hover:bg-sky-100 transition-colors flex items-center gap-1"
                                                        title="Xem trước nội dung email"
                                                    >
                                                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                        <span>Xem Email</span>
                                                    </button>

                                                    {(log.channel_status === 'FAILED' || log.channel_status === 'SENT') && (
                                                        <button
                                                            onClick={() => handleRetry(log.id)}
                                                            disabled={retryingLogId === log.id}
                                                            className="p-1 rounded-lg text-slate-500 hover:text-amber-600 hover:bg-amber-50 transition-colors disabled:opacity-50"
                                                            title="Gửi lại thông báo này"
                                                        >
                                                            <svg className={`w-4 h-4 ${retryingLogId === log.id ? 'animate-spin' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                            </svg>
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

                {/* Phân trang */}
                {pagination.total > 0 && (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-6 py-4 bg-slate-50/50 border-t border-slate-200/80 text-xs text-slate-500">
                        <div>
                            Hiển thị <strong>{logs.length}</strong> trên tổng số <strong>{pagination.total}</strong> lượt nhắc
                        </div>
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => loadData(pagination.current_page - 1)}
                                disabled={pagination.current_page <= 1 || loading}
                                className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white disabled:opacity-40 hover:bg-slate-50 font-medium"
                            >
                                Trước
                            </button>
                            <span className="px-3 py-1.5 font-bold text-slate-800">
                                {pagination.current_page} / {pagination.last_page}
                            </span>
                            <button
                                onClick={() => loadData(pagination.current_page + 1)}
                                disabled={pagination.current_page >= pagination.last_page || loading}
                                className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white disabled:opacity-40 hover:bg-slate-50 font-medium"
                            >
                                Sau
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* MODAL 1: XEM TRƯỚC NỘI DUNG EMAIL NHẮC NỢ (PREVIEW MODAL) */}
            {previewModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs">
                    <div className="bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-2xl w-full max-h-[92vh] flex flex-col overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                        {/* Header Modal */}
                        <div className="p-6 border-b border-slate-200 flex items-center justify-between bg-white">
                            <div>
                                <span className="text-[10px] uppercase font-bold tracking-widest text-sky-600">
                                    Bản Xem Trước Email Mẫu (HTML Preview)
                                </span>
                                <h3 className="text-lg font-black text-neutral-900 mt-0.5">
                                    {previewData ? previewData.subject : 'Đang tải nội dung email...'}
                                </h3>
                            </div>
                            <button
                                onClick={() => setPreviewModalOpen(false)}
                                className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
                            >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Body Modal Preview */}
                        <div className="p-6 overflow-y-auto flex-1 bg-slate-50">
                            {loadingPreview ? (
                                <div className="py-16 text-center text-slate-400">
                                    <svg className="w-8 h-8 animate-spin mx-auto mb-3 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    <p className="text-sm font-medium">Đang kết xuất giao diện email mẫu...</p>
                                </div>
                            ) : previewData ? (
                                <div className="space-y-4">
                                    {/* Thông tin metadata người nhận */}
                                    <div className="bg-white p-3.5 rounded-xl border border-slate-200 text-xs grid grid-cols-2 gap-2 text-slate-600">
                                        <div>Người nhận: <strong className="text-slate-900">{previewData.recipient_name}</strong></div>
                                        <div>Email: <strong className="text-sky-600 font-mono">{previewData.recipient_email}</strong></div>
                                        <div>Hóa đơn: <strong className="font-mono">{previewData.invoice_number}</strong></div>
                                        <div>Dư nợ: <strong className="text-rose-600 font-mono">{formatCurrency(previewData.debt_amount)}</strong></div>
                                    </div>

                                    {/* HTML Render */}
                                    <div className="bg-white p-4 rounded-xl shadow-inner border border-slate-200 overflow-x-auto text-slate-900">
                                        <div
                                            dangerouslySetInnerHTML={{ __html: previewData.html }}
                                            className="email-preview-container"
                                        />
                                    </div>
                                </div>
                            ) : null}
                        </div>

                        {/* Footer Modal */}
                        <div className="p-4 bg-white border-t border-slate-200 flex items-center justify-between">
                            <span className="text-xs text-slate-500">
                                Email được gửi tự động qua dịch vụ Mail facade & Queue của Laravel.
                            </span>
                            <button
                                onClick={() => setPreviewModalOpen(false)}
                                className="px-4 py-2 rounded-xl text-sm font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 transition-colors"
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
                    <div className="bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-lg w-full overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                        {/* Header Modal */}
                        <div className="p-6 border-b border-slate-200 flex items-center justify-between bg-white">
                            <div className="flex items-center gap-3">
                                <div className="w-10 h-10 rounded-xl bg-sky-50 border border-sky-200 flex items-center justify-center text-sky-600">
                                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 className="text-lg font-black text-neutral-900">Quét & Xếp Hàng Nhắc Nợ</h3>
                                    <p className="text-xs text-slate-500">Tự động đẩy hóa đơn nợ vào hàng đợi Queue xử lý nền</p>
                                </div>
                            </div>
                            <button
                                onClick={() => setAutoScanModalOpen(false)}
                                className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
                            >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Body Modal */}
                        <div className="p-6 space-y-4">
                            <div className="p-4 rounded-xl bg-sky-50 border border-sky-200 text-xs text-slate-600 space-y-1">
                                <p className="font-bold flex items-center gap-1.5 text-neutral-900">
                                    <svg className="w-4 h-4 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Quy tắc bảo vệ chống gửi trùng lặp (Anti-Spam):
                                </p>
                                <p>Hệ thống tự động bỏ qua các hóa đơn đã được gửi nhắc nợ trong vòng <strong>24 giờ qua</strong> để tránh làm phiền cư dân.</p>
                            </div>

                            <div className="space-y-3">
                                <label className="text-xs font-bold text-slate-700 uppercase tracking-wider">
                                    Phạm Vi Hóa Đơn Cần Quét
                                </label>

                                <div className="space-y-2">
                                    <label
                                        onClick={() => setAutoScanOverdueOnly(true)}
                                        className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                                            autoScanOverdueOnly
                                                ? 'border-sky-500 bg-sky-50/40'
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
                                        <div>
                                            <div className="text-sm font-bold text-slate-900">
                                                Chỉ quét các hóa đơn đã QUÁ HẠN (Khuyên dùng)
                                            </div>
                                            <div className="text-xs text-slate-500 mt-0.5">
                                                Gửi thông báo cảnh báo quá hạn cho các hóa đơn có trạng thái OVERDUE hoặc có ngày đến hạn trước ngày hôm nay.
                                            </div>
                                        </div>
                                    </label>

                                    <label
                                        onClick={() => setAutoScanOverdueOnly(false)}
                                        className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                                            !autoScanOverdueOnly
                                                ? 'border-sky-500 bg-sky-50/40'
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
                                            <div className="text-sm font-bold text-slate-900">
                                                Quét cả hóa đơn SẮP ĐẾN HẠN
                                            </div>
                                            <div className="text-xs text-slate-500 mt-0.5">
                                                Gửi thông báo nhắc nhở cho các hóa đơn sắp đến hạn trong vòng:
                                                <input
                                                    type="number"
                                                    min="1"
                                                    max="15"
                                                    value={autoScanDaysBefore}
                                                    onChange={(e) => setAutoScanDaysBefore(parseInt(e.target.value) || 3)}
                                                    onClick={(e) => e.stopPropagation()}
                                                    className="inline-block w-14 mx-1.5 px-2 py-0.5 rounded border border-slate-300 text-center font-bold text-xs"
                                                />
                                                ngày tới.
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        {/* Footer Modal */}
                        <div className="p-4 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-2.5">
                            <button
                                onClick={() => setAutoScanModalOpen(false)}
                                disabled={triggeringAutoScan}
                                className="px-4 py-2.5 rounded-xl text-sm font-semibold text-slate-700 bg-slate-200/80 hover:bg-slate-300 transition-colors"
                            >
                                Hủy bỏ
                            </button>
                            <button
                                onClick={handleTriggerAutoScan}
                                disabled={triggeringAutoScan}
                                className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-sky-600 hover:bg-sky-700 border border-sky-600 shadow-2xs active:scale-[0.98] transition-all disabled:opacity-50"
                            >
                                {triggeringAutoScan ? (
                                    <>
                                        <svg className="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        <span>Đang quét & xếp hàng...</span>
                                    </>
                                ) : (
                                    <>
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                        </svg>
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
