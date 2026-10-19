import React from 'react';
import {
    Receipt,
    Layers,
    History,
    TrendingUp,
    FileSpreadsheet,
    ArrowRight
} from 'lucide-react';

export type InvoiceTabKey =
    | 'invoices'
    | 'invoice_generation'
    | 'payment_history'
    | 'revenue_analytics'
    | 'financial_reports';

interface InvoiceNavTabsProps {
    activeTab: InvoiceTabKey;
    onTabChange?: (tab: InvoiceTabKey) => void;
    unpaidCount?: number;
}

export const InvoiceNavTabs: React.FC<InvoiceNavTabsProps> = ({
    activeTab,
    onTabChange,
    unpaidCount,
}) => {
    const tabs: Array<{
        id: InvoiceTabKey;
        label: string;
        subLabel: string;
        icon: React.ElementType;
        badge?: string | number | null;
        color: string;
    }> = [
        {
            id: 'invoices',
            label: 'Danh Sách Hóa Đơn',
            subLabel: 'Quản lý, duyệt & gạch nợ',
            icon: Receipt,
            badge: unpaidCount ? `${unpaidCount} nợ` : null,
            color: 'indigo',
        },
        {
            id: 'invoice_generation',
            label: 'Sinh Hóa Đơn Hàng Loạt',
            subLabel: 'Chốt số & sinh tự động',
            icon: Layers,
            badge: 'Tự động',
            color: 'sky',
        },
        {
            id: 'payment_history',
            label: 'Lịch Sử Giao Dịch',
            subLabel: 'Thu tiền, VietQR & log',
            icon: History,
            badge: null,
            color: 'emerald',
        },
        {
            id: 'revenue_analytics',
            label: 'Dashboard Doanh Thu',
            subLabel: 'Biểu đồ dòng tiền & thu hồi',
            icon: TrendingUp,
            badge: 'Biểu đồ',
            color: 'purple',
        },
        {
            id: 'financial_reports',
            label: 'Báo Cáo Tài Chính',
            subLabel: 'Xuất Excel & PDF A4',
            icon: FileSpreadsheet,
            badge: 'Excel/PDF',
            color: 'amber',
        },
    ];

    const handleSwitch = (tabId: InvoiceTabKey) => {
        if (onTabChange) {
            onTabChange(tabId);
        } else {
            // Cập nhật URL và reload hoặc trigger
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('tab', tabId);
            window.history.pushState({ tab: tabId }, '', currentUrl.toString());
            window.dispatchEvent(new PopStateEvent('popstate'));
        }
    };

    return (
        <div className="w-full bg-slate-900/90 border border-slate-800 rounded-3xl p-2.5 shadow-2xl backdrop-blur-xl mb-6">
            <div className="flex items-center gap-2 overflow-x-auto pb-1 sm:pb-0 scrollbar-none">
                {tabs.map((tab) => {
                    const Icon = tab.icon;
                    const isActive = activeTab === tab.id;
                    return (
                        <button
                            key={tab.id}
                            type="button"
                            onClick={() => handleSwitch(tab.id)}
                            className={`flex items-center gap-3 px-4 py-3 rounded-2xl text-left transition-all duration-200 shrink-0 cursor-pointer ${
                                isActive
                                    ? 'bg-gradient-to-r from-indigo-600 to-indigo-700 text-white shadow-lg shadow-indigo-600/30 scale-[1.02]'
                                    : 'text-slate-400 hover:text-slate-100 hover:bg-slate-800/70'
                            }`}
                        >
                            <div
                                className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 transition-colors ${
                                    isActive
                                        ? 'bg-white/20 text-white'
                                        : 'bg-slate-800 text-slate-300'
                                }`}
                            >
                                <Icon className="w-4 h-4" />
                            </div>

                            <div className="min-w-0 pr-1">
                                <div className="flex items-center gap-2">
                                    <span className="text-xs font-bold whitespace-nowrap leading-tight">
                                        {tab.label}
                                    </span>
                                    {tab.badge && (
                                        <span
                                            className={`text-[10px] px-1.5 py-0.2 rounded-full font-extrabold uppercase ${
                                                isActive
                                                    ? 'bg-white/25 text-white'
                                                    : 'bg-indigo-500/15 text-indigo-300 border border-indigo-500/30'
                                            }`}
                                        >
                                            {tab.badge}
                                        </span>
                                    )}
                                </div>
                                <span
                                    className={`text-[11px] block truncate leading-tight mt-0.5 ${
                                        isActive ? 'text-indigo-100/80 font-medium' : 'text-slate-500'
                                    }`}
                                >
                                    {tab.subLabel}
                                </span>
                            </div>
                        </button>
                    );
                })}
            </div>
        </div>
    );
};
