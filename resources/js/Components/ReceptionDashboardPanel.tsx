import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import {
  Package,
  Users,
  Clock,
  Calendar,
  RefreshCw,
  TrendingUp,
  AlertTriangle,
  CheckCircle2,
  Building,
  Truck,
  ArrowUpRight,
  ShieldCheck,
  Radio,
  ExternalLink,
  ChevronRight,
  Filter,
  Eye,
  Info,
  Sparkles,
} from 'lucide-react';
import { useRealtimeSync, RealtimeEventPayload } from '../Hooks/useRealtimeSync';

// =========================================================================
// INTERFACES & TYPE DEFINITIONS
// =========================================================================

export interface ParcelAging {
  under_24h: number;
  days_1_to_3: number;
  days_3_to_5: number;
  over_5_days: number;
}

export interface ParcelKpis {
  pending_total: number;
  pending_today: number;
  received_today: number;
  received_in_range: number;
  delivered_today: number;
  delivered_in_range: number;
  overdue_parcels: number;
  aging: ParcelAging;
}

export interface VisitorKpis {
  currently_inside: number;
  checkin_today: number;
  checkin_in_range: number;
  checkout_today: number;
  checkout_in_range: number;
  expected_today: number;
  expected_in_range: number;
}

export interface TrafficTrendItem {
  key: string;
  label: string;
  visitors_checkin: number;
  visitors_checkout: number;
  parcels_received: number;
  parcels_delivered: number;
}

export interface CarrierBreakdown {
  carrier: string;
  count: number;
}

export interface VisitorPurposeBreakdown {
  purpose: string;
  count: number;
}

export interface TopPendingParcel {
  id: string;
  tracking_number: string;
  carrier: string;
  recipient_name: string;
  recipient_phone_masked: string;
  apartment_number: string;
  received_at: string;
  waiting_hours: number;
  waiting_days: number;
  waiting_label: string;
  aging_group: 'under_24h' | '1_to_3_days' | '3_to_5_days' | 'over_5_days';
  status: string;
}

export interface CurrentVisitorInside {
  checkin_log_id: string;
  visitor_name: string;
  visitor_phone_masked: string;
  apartment_number: string;
  purpose: string;
  temp_card_id?: string | null;
  checkin_time: string;
  stay_duration_minutes: number;
  stay_duration_label: string;
}

export interface DashboardMetrics {
  kpis: {
    parcels: ParcelKpis;
    visitors: VisitorKpis;
  };
  traffic_trend: {
    granularity: 'hour' | 'day';
    series: TrafficTrendItem[];
  };
  breakdowns: {
    carriers: CarrierBreakdown[];
    visitor_purposes: VisitorPurposeBreakdown[];
  };
  top_pending_parcels: TopPendingParcel[];
  current_visitors_inside: CurrentVisitorInside[];
  meta: {
    range_preset: string;
    from_date: string;
    to_date: string;
    generated_at: string;
  };
}

export interface ReceptionDashboardPanelProps {
  onNavigateTab?: (tab: string) => void;
  embedded?: boolean;
}

// =========================================================================
// MAIN COMPONENT
// =========================================================================

export const ReceptionDashboardPanel: React.FC<ReceptionDashboardPanelProps> = ({
  onNavigateTab,
  embedded = false,
}) => {
  // 1. Trạng thái Bộ lọc Thời gian
  const [preset, setPreset] = useState<'today' | 'last_7_days' | 'this_month' | 'custom'>('today');
  const [fromDate, setFromDate] = useState<string>(() => {
    const d = new Date();
    return d.toISOString().slice(0, 10);
  });
  const [toDate, setToDate] = useState<string>(() => {
    const d = new Date();
    return d.toISOString().slice(0, 10);
  });
  const [filterError, setFilterError] = useState<string | null>(null);

  // 2. Trạng thái Dữ liệu & Tải
  const [data, setData] = useState<DashboardMetrics | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isFetching, setIsFetching] = useState<boolean>(false);
  const [lastSyncTime, setLastSyncTime] = useState<string>('');
  const [realtimeNotice, setRealtimeNotice] = useState<string | null>(null);

  // 3. Hàm gọi API Dashboard
  const fetchDashboardData = useCallback(
    async (isSilent = false) => {
      if (!isSilent) {
        setIsLoading(true);
      }
      setIsFetching(true);
      setFilterError(null);

      try {
        const params = new URLSearchParams();
        params.set('preset', preset);
        if (preset === 'custom') {
          if (fromDate) params.set('from_date', fromDate);
          if (toDate) params.set('to_date', toDate);
        }
        params.set('top_limit', '8');

        const token =
          localStorage.getItem('smart_cassavas_token') ||
          sessionStorage.getItem('smart_cassavas_token') ||
          localStorage.getItem('token') ||
          '';

        const headers: Record<string, string> = {
          Accept: 'application/json',
        };
        if (token) {
          headers.Authorization = `Bearer ${token}`;
        }

        const res = await fetch(`/api/v1/reception/dashboard?${params.toString()}`, {
          method: 'GET',
          headers,
        });

        if (!res.ok) {
          const errData = await res.json().catch(() => ({}));
          throw new Error(errData.message || `Lỗi tải dữ liệu (${res.status})`);
        }

        const result = await res.json();
        if (result.success && result.data) {
          setData(result.data);
          const now = new Date();
          setLastSyncTime(
            `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(
              now.getSeconds()
            ).padStart(2, '0')}`
          );
        }
      } catch (err: any) {
        setFilterError(err.message || 'Không thể kết nối đến máy chủ.');
      } finally {
        setIsLoading(false);
        setIsFetching(false);
      }
    },
    [preset, fromDate, toDate]
  );

  // Gọi lần đầu & khi thay đổi bộ lọc
  useEffect(() => {
    if (preset === 'custom') {
      if (fromDate && toDate && fromDate > toDate) {
        setFilterError('Ngày bắt đầu không được lớn hơn ngày kết thúc.');
        return;
      }
    }
    fetchDashboardData(false);
  }, [preset, fromDate, toDate, fetchDashboardData]);

  // 4. Lắng nghe Realtime Event trên 2 kênh: quoc-tin.parcels và quoc-tin.visitors
  const handleRealtimeEvent = useCallback(
    (event: RealtimeEventPayload) => {
      // Khi có sự kiện tiếp nhận/bàn giao bưu phẩm hoặc check-in/check-out khách
      const isParcelEvent =
        event.module === 'parcels' ||
        event.entity === 'parcel' ||
        event.channel === 'quoc-tin.parcels';
      const isVisitorEvent =
        event.module === 'visitors' ||
        event.entity === 'visitor_checkin' ||
        event.entity === 'visitor_registration' ||
        event.channel === 'quoc-tin.visitors';

      if (isParcelEvent || isVisitorEvent) {
        // Tự động refetch snapshot mới nhất từ server ngay lập tức (không F5, không delay)
        fetchDashboardData(true);

        // Hiển thị thông báo realtime vi mô
        let msg = '⚡ Cập nhật chỉ số Dashboard theo thời gian thực!';
        if (event.action === 'RECEIVED') {
          msg = '⚡ Realtime: Kiện bưu phẩm mới vừa được tiếp nhận tại quầy!';
        } else if (event.action === 'CHECKED_OUT' || event.action === 'DELIVERED') {
          msg = '⚡ Realtime: Bưu phẩm đã được bàn giao thành công cho cư dân!';
        } else if (event.action === 'CHECKED_IN') {
          msg = '⚡ Realtime: Khách mới vừa hoàn tất Check-in vào tòa nhà!';
        } else if (event.action === 'VISITOR_CHECKED_OUT') {
          msg = '⚡ Realtime: Khách vừa Check-out và rời khỏi tòa nhà!';
        }

        setRealtimeNotice(msg);
        setTimeout(() => setRealtimeNotice(null), 4000);
      }
    },
    [fetchDashboardData]
  );

  useRealtimeSync({
    channel: ['quoc-tin.parcels', 'quoc-tin.visitors'],
    onEvent: handleRealtimeEvent,
    onReconnect: () => {
      fetchDashboardData(true);
    },
    enabled: true,
  });

  // 5. Chuẩn bị dữ liệu hiển thị KPIs
  const parcelKpis = data?.kpis?.parcels;
  const visitorKpis = data?.kpis?.visitors;
  const aging = parcelKpis?.aging;
  const pendingTotal = parcelKpis?.pending_total ?? 0;

  // Tính tỷ lệ phần trăm aging
  const agingPct = useMemo(() => {
    if (!aging || pendingTotal === 0) {
      return { under_24h: 0, days_1_to_3: 0, days_3_to_5: 0, over_5_days: 0 };
    }
    return {
      under_24h: Math.round((aging.under_24h / pendingTotal) * 100),
      days_1_to_3: Math.round((aging.days_1_to_3 / pendingTotal) * 100),
      days_3_to_5: Math.round((aging.days_3_to_5 / pendingTotal) * 100),
      over_5_days: Math.round((aging.over_5_days / pendingTotal) * 100),
    };
  }, [aging, pendingTotal]);

  // 6. Tính toán kích thước biểu đồ Trend
  const trafficSeries = data?.traffic_trend?.series ?? [];
  const maxTrafficVal = useMemo(() => {
    let max = 5;
    for (const item of trafficSeries) {
      max = Math.max(max, item.visitors_checkin, item.parcels_received, item.visitors_checkout);
    }
    return max;
  }, [trafficSeries]);

  return (
    <div className={`space-y-6 ${embedded ? '' : 'w-full max-w-7xl mx-auto'}`}>
      {/* ========================================================================= */}
      {/* 1. TOP HEADER & FILTER BAR */}
      {/* ========================================================================= */}
      <div className="bg-white/95 backdrop-blur-md border border-slate-200/80 rounded-2xl p-5 shadow-xs">
        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          {/* Title & Live Status */}
          <div>
            <div className="flex items-center gap-2 text-xs font-bold text-sky-600 uppercase tracking-wider font-mono">
              <Building className="w-3.5 h-3.5 text-sky-500" />
              <span>DASHBOARD LỄ TÂN & AN NINH</span>
              <span className="text-slate-300">/</span>
              <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                <span>LIVE REALTIME SYNC</span>
              </span>
            </div>

            <h1 className="text-xl sm:text-2xl font-black text-neutral-900 tracking-tight mt-1.5 flex items-center gap-2">
              Giám sát Bưu phẩm tồn đọng & Khách trong tòa nhà
              {isFetching && (
                <RefreshCw className="w-4 h-4 text-sky-500 animate-spin shrink-0" />
              )}
            </h1>

            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Dữ liệu tổng hợp từ Database theo thời gian thực. Tự động đồng bộ ngay khi có kiện hàng hoặc khách mới.
              {lastSyncTime && (
                <span className="ml-2 font-mono text-[11px] text-slate-400">
                  (Cập nhật lúc: {lastSyncTime})
                </span>
              )}
            </p>
          </div>

          {/* Quick Refresh Button */}
          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={() => fetchDashboardData(false)}
              disabled={isFetching}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200/80 text-slate-700 text-xs font-semibold transition-all cursor-pointer disabled:opacity-50"
              title="Tải lại số liệu từ máy chủ"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${isFetching ? 'animate-spin text-sky-600' : ''}`} />
              <span>Làm mới</span>
            </button>
          </div>
        </div>

        {/* Realtime Alert Banner */}
        {realtimeNotice && (
          <div className="mt-3 p-2.5 rounded-xl bg-sky-50 border border-sky-200 text-sky-800 text-xs font-medium flex items-center justify-between animate-in fade-in slide-in-from-top-2 duration-300">
            <div className="flex items-center gap-2">
              <Sparkles className="w-4 h-4 text-sky-600 shrink-0" />
              <span>{realtimeNotice}</span>
            </div>
            <span className="text-[10px] text-sky-500 font-mono">0ms delay</span>
          </div>
        )}

        {/* Date Filter Controls */}
        <div className="mt-4 pt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3">
          {/* Preset Buttons */}
          <div className="inline-flex items-center bg-slate-100 p-1 rounded-xl text-xs font-semibold">
            <button
              type="button"
              onClick={() => setPreset('today')}
              className={`px-3 py-1.5 rounded-lg transition-all cursor-pointer ${
                preset === 'today'
                  ? 'bg-white text-neutral-900 shadow-xs'
                  : 'text-slate-600 hover:text-neutral-900'
              }`}
            >
              Hôm nay
            </button>
            <button
              type="button"
              onClick={() => setPreset('last_7_days')}
              className={`px-3 py-1.5 rounded-lg transition-all cursor-pointer ${
                preset === 'last_7_days'
                  ? 'bg-white text-neutral-900 shadow-xs'
                  : 'text-slate-600 hover:text-neutral-900'
              }`}
            >
              7 ngày qua
            </button>
            <button
              type="button"
              onClick={() => setPreset('this_month')}
              className={`px-3 py-1.5 rounded-lg transition-all cursor-pointer ${
                preset === 'this_month'
                  ? 'bg-white text-neutral-900 shadow-xs'
                  : 'text-slate-600 hover:text-neutral-900'
              }`}
            >
              Tháng này
            </button>
            <button
              type="button"
              onClick={() => setPreset('custom')}
              className={`px-3 py-1.5 rounded-lg transition-all cursor-pointer ${
                preset === 'custom'
                  ? 'bg-white text-neutral-900 shadow-xs'
                  : 'text-slate-600 hover:text-neutral-900'
              }`}
            >
              Tùy chọn ngày
            </button>
          </div>

          {/* Custom Date Pickers */}
          {preset === 'custom' && (
            <div className="flex items-center gap-2 text-xs">
              <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5">
                <span className="text-slate-400">Từ:</span>
                <input
                  type="date"
                  value={fromDate}
                  onChange={(e) => setFromDate(e.target.value)}
                  className="bg-transparent text-slate-800 text-xs font-medium focus:outline-hidden"
                />
              </div>
              <span className="text-slate-400">-</span>
              <div className="flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5">
                <span className="text-slate-400">Đến:</span>
                <input
                  type="date"
                  value={toDate}
                  onChange={(e) => setToDate(e.target.value)}
                  className="bg-transparent text-slate-800 text-xs font-medium focus:outline-hidden"
                />
              </div>
            </div>
          )}

          {/* Date Label Summary */}
          {data?.meta && (
            <div className="text-[11px] text-slate-400 font-mono">
              Khoảng xem xét: <strong className="text-slate-600">{data.meta.from_date}</strong> → <strong className="text-slate-600">{data.meta.to_date}</strong>
            </div>
          )}
        </div>

        {filterError && (
          <div className="mt-3 p-2.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 text-rose-500 shrink-0" />
            <span>{filterError}</span>
          </div>
        )}
      </div>

      {/* ========================================================================= */}
      {/* 2. SUMMARY KPI CARDS (2 LỚN: BƯU PHẨM & KHÁCH VIẾNG THĂM) */}
      {/* ========================================================================= */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Card 1: Bưu phẩm Tồn đọng & Lưu thông */}
        <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs hover:shadow-md transition-all flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                  <Package className="w-5 h-5" />
                </div>
                <div>
                  <h2 className="text-sm font-bold text-neutral-900">Giám sát Bưu phẩm</h2>
                  <p className="text-[11px] text-slate-400">Kiện hàng lưu kho tại quầy lễ tân</p>
                </div>
              </div>

              {onNavigateTab && (
                <button
                  type="button"
                  onClick={() => onNavigateTab('parcels')}
                  className="inline-flex items-center gap-1 text-xs font-semibold text-amber-600 hover:text-amber-700 cursor-pointer"
                >
                  <span>Chi tiết</span>
                  <ChevronRight className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            {/* Chỉ số lớn: Tổng chờ giao */}
            <div className="mt-4 flex items-baseline justify-between">
              <div>
                <span className="text-xs text-slate-500 font-medium">Tổng bưu phẩm đang chờ nhận</span>
                <div className="text-3xl sm:text-4xl font-black text-neutral-900 tracking-tight mt-0.5">
                  {isLoading ? '...' : pendingTotal}
                  <span className="text-sm font-normal text-slate-400 ml-1.5">kiện hàng</span>
                </div>
              </div>

              {parcelKpis?.overdue_parcels ? (
                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                  <AlertTriangle className="w-3.5 h-3.5 text-rose-600" />
                  <span>{parcelKpis.overdue_parcels} quá hạn</span>
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                  <span>Kho ổn định</span>
                </span>
              )}
            </div>

            {/* 3 Thẻ thống kê con */}
            <div className="grid grid-cols-3 gap-2.5 mt-4 pt-3 border-t border-slate-100">
              <div className="bg-slate-50/80 rounded-xl p-2.5">
                <span className="text-[11px] text-slate-500 font-medium block truncate">Tiếp nhận hôm nay</span>
                <span className="text-base sm:text-lg font-bold text-neutral-800">
                  {isLoading ? '...' : (parcelKpis?.received_today ?? 0)}
                </span>
              </div>
              <div className="bg-slate-50/80 rounded-xl p-2.5">
                <span className="text-[11px] text-slate-500 font-medium block truncate">Đã giao hôm nay</span>
                <span className="text-base sm:text-lg font-bold text-emerald-600">
                  {isLoading ? '...' : (parcelKpis?.delivered_today ?? 0)}
                </span>
              </div>
              <div className="bg-slate-50/80 rounded-xl p-2.5">
                <span className="text-[11px] text-slate-500 font-medium block truncate">Nhận trong kỳ lọc</span>
                <span className="text-base sm:text-lg font-bold text-sky-600">
                  {isLoading ? '...' : (parcelKpis?.received_in_range ?? 0)}
                </span>
              </div>
            </div>
          </div>

          {/* Mini Aging Bar */}
          <div className="mt-4 pt-3 border-t border-slate-100">
            <div className="flex items-center justify-between text-[11px] text-slate-500 mb-1.5">
              <span>Phân bố tồn kho theo thời gian:</span>
              <span className="font-semibold text-slate-700">{pendingTotal} kiện đang giữ</span>
            </div>
            <div className="h-2.5 w-full bg-slate-100 rounded-full overflow-hidden flex">
              <div
                style={{ width: `${agingPct.under_24h}%` }}
                className="bg-emerald-500 h-full transition-all"
                title={`< 24h: ${aging?.under_24h ?? 0} kiện (${agingPct.under_24h}%)`}
              />
              <div
                style={{ width: `${agingPct.days_1_to_3}%` }}
                className="bg-sky-500 h-full transition-all"
                title={`1-3 ngày: ${aging?.days_1_to_3 ?? 0} kiện (${agingPct.days_1_to_3}%)`}
              />
              <div
                style={{ width: `${agingPct.days_3_to_5}%` }}
                className="bg-amber-500 h-full transition-all"
                title={`3-5 ngày: ${aging?.days_3_to_5 ?? 0} kiện (${agingPct.days_3_to_5}%)`}
              />
              <div
                style={{ width: `${agingPct.over_5_days}%` }}
                className="bg-rose-500 h-full transition-all"
                title={`> 5 ngày: ${aging?.over_5_days ?? 0} kiện (${agingPct.over_5_days}%)`}
              />
            </div>
            <div className="grid grid-cols-4 gap-1 text-[10px] text-slate-500 mt-2 font-medium">
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-emerald-500" /> &lt;24h ({aging?.under_24h ?? 0})
              </span>
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-sky-500" /> 1-3d ({aging?.days_1_to_3 ?? 0})
              </span>
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-amber-500" /> 3-5d ({aging?.days_3_to_5 ?? 0})
              </span>
              <span className="flex items-center gap-1 text-rose-600 font-bold">
                <span className="w-2 h-2 rounded-full bg-rose-500" /> &gt;5d ({aging?.over_5_days ?? 0})
              </span>
            </div>
          </div>
        </div>

        {/* Card 2: Khách Hiện Diện & Lưu Lượng Trong Tòa Nhà */}
        <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs hover:shadow-md transition-all flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center font-bold">
                  <Users className="w-5 h-5" />
                </div>
                <div>
                  <h2 className="text-sm font-bold text-neutral-900">Giám sát Khách viếng thăm</h2>
                  <p className="text-[11px] text-slate-400">Kiểm soát an ninh ra/vào sảnh</p>
                </div>
              </div>

              {onNavigateTab && (
                <button
                  type="button"
                  onClick={() => onNavigateTab('visitors')}
                  className="inline-flex items-center gap-1 text-xs font-semibold text-sky-600 hover:text-sky-700 cursor-pointer"
                >
                  <span>Chi tiết</span>
                  <ChevronRight className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            {/* Chỉ số lớn: Khách hiện đang trong tòa nhà */}
            <div className="mt-4 flex items-baseline justify-between">
              <div>
                <span className="text-xs text-slate-500 font-medium">Khách hiện đang ở trong tòa nhà</span>
                <div className="text-3xl sm:text-4xl font-black text-neutral-900 tracking-tight mt-0.5">
                  {isLoading ? '...' : (visitorKpis?.currently_inside ?? 0)}
                  <span className="text-sm font-normal text-slate-400 ml-1.5">người</span>
                </div>
              </div>

              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-sky-50 text-sky-700 border border-sky-200/60">
                <span className="w-2 h-2 rounded-full bg-sky-500 animate-ping" />
                <span>Đã check-in thực tế</span>
              </span>
            </div>

            {/* 3 Thẻ thống kê con */}
            <div className="grid grid-cols-3 gap-2.5 mt-4 pt-3 border-t border-slate-100">
              <div className="bg-slate-50/80 rounded-xl p-2.5">
                <span className="text-[11px] text-slate-500 font-medium block truncate">Check-in hôm nay</span>
                <span className="text-base sm:text-lg font-bold text-sky-600">
                  {isLoading ? '...' : (visitorKpis?.checkin_today ?? 0)}
                </span>
              </div>
              <div className="bg-slate-50/80 rounded-xl p-2.5">
                <span className="text-[11px] text-slate-500 font-medium block truncate">Check-out hôm nay</span>
                <span className="text-base sm:text-lg font-bold text-emerald-600">
                  {isLoading ? '...' : (visitorKpis?.checkout_today ?? 0)}
                </span>
              </div>
              <div className="bg-slate-50/80 rounded-xl p-2.5">
                <span className="text-[11px] text-slate-500 font-medium block truncate">Dự kiến hôm nay</span>
                <span className="text-base sm:text-lg font-bold text-neutral-800">
                  {isLoading ? '...' : (visitorKpis?.expected_today ?? 0)}
                </span>
              </div>
            </div>
          </div>

          {/* Ghi chú an ninh */}
          <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
            <span className="flex items-center gap-1.5 text-emerald-600 font-medium">
              <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
              <span>Chỉ tính khách đã Check-in & Chưa Check-out</span>
            </span>
            <span>Tổng lượt lọc: <strong className="text-slate-700 font-semibold">{visitorKpis?.checkin_in_range ?? 0}</strong> lượt</span>
          </div>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 3. BIỂU ĐỒ XU HƯỚNG LƯU LƯỢNG (TRAFFIC TREND CHART) */}
      {/* ========================================================================= */}
      <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-4 border-b border-slate-100">
          <div>
            <h3 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
              <TrendingUp className="w-4 h-4 text-sky-600" />
              <span>Biểu đồ Xu hướng Lưu lượng theo {data?.traffic_trend?.granularity === 'hour' ? 'Giờ trong ngày' : 'Ngày trong kỳ'}</span>
            </h3>
            <p className="text-xs text-slate-400 mt-0.5">
              So sánh lượng khách check-in và bưu phẩm tiếp nhận vào sảnh lễ tân
            </p>
          </div>

          {/* Legend */}
          <div className="flex items-center gap-4 text-xs font-medium">
            <span className="flex items-center gap-1.5 text-sky-700">
              <span className="w-3 h-3 rounded-sm bg-sky-500" />
              <span>Khách Check-in</span>
            </span>
            <span className="flex items-center gap-1.5 text-amber-700">
              <span className="w-3 h-3 rounded-sm bg-amber-500" />
              <span>Bưu phẩm Tiếp nhận</span>
            </span>
            <span className="flex items-center gap-1.5 text-emerald-700">
              <span className="w-3 h-3 rounded-sm bg-emerald-500" />
              <span>Khách Check-out</span>
            </span>
          </div>
        </div>

        {/* Visual Bar Chart (Responsive Pure CSS/SVG) */}
        <div className="mt-6">
          {trafficSeries.length === 0 ? (
            <div className="h-44 flex items-center justify-center text-slate-400 text-xs">
              Chưa có dữ liệu phát sinh trong khoảng thời gian này.
            </div>
          ) : (
            <div className="h-48 flex items-end gap-2 sm:gap-3 overflow-x-auto pb-2 pt-6">
              {trafficSeries.map((item, idx) => {
                const guestHeight = maxTrafficVal > 0 ? (item.visitors_checkin / maxTrafficVal) * 100 : 0;
                const parcelHeight = maxTrafficVal > 0 ? (item.parcels_received / maxTrafficVal) * 100 : 0;
                const checkoutHeight = maxTrafficVal > 0 ? (item.visitors_checkout / maxTrafficVal) * 100 : 0;

                return (
                  <div key={item.key || idx} className="flex-1 min-w-[36px] flex flex-col items-center h-full justify-end group">
                    {/* Tooltip on Hover */}
                    <div className="opacity-0 group-hover:opacity-100 transition-opacity absolute -translate-y-24 bg-neutral-900 text-white text-[10px] rounded-lg py-1 px-2 pointer-events-none shadow-lg z-10 whitespace-nowrap">
                      <div className="font-bold">{item.label}</div>
                      <div>Khách vào: {item.visitors_checkin}</div>
                      <div>Khách ra: {item.visitors_checkout}</div>
                      <div>Bưu phẩm: {item.parcels_received}</div>
                    </div>

                    {/* Bars Container */}
                    <div className="w-full flex items-end justify-center gap-0.5 sm:gap-1 h-36">
                      {/* Guest Check-in Bar */}
                      <div
                        style={{ height: `${Math.max(guestHeight, 4)}%` }}
                        className="w-2 sm:w-2.5 bg-sky-500 hover:bg-sky-600 rounded-t-sm transition-all"
                        title={`Khách check-in: ${item.visitors_checkin}`}
                      />
                      {/* Parcel Received Bar */}
                      <div
                        style={{ height: `${Math.max(parcelHeight, 4)}%` }}
                        className="w-2 sm:w-2.5 bg-amber-500 hover:bg-amber-600 rounded-t-sm transition-all"
                        title={`Bưu phẩm tiếp nhận: ${item.parcels_received}`}
                      />
                      {/* Guest Check-out Bar */}
                      <div
                        style={{ height: `${Math.max(checkoutHeight, 4)}%` }}
                        className="w-2 sm:w-2.5 bg-emerald-400 hover:bg-emerald-500 rounded-t-sm transition-all"
                        title={`Khách check-out: ${item.visitors_checkout}`}
                      />
                    </div>

                    {/* X-axis Label */}
                    <span className="text-[10px] font-mono text-slate-500 mt-2 truncate max-w-full">
                      {item.label}
                    </span>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 4. CƠ CẤU ĐƠN VỊ VẬN CHUYỂN & MỤC ĐÍCH VIẾNG THĂM */}
      {/* ========================================================================= */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Breakdown 1: Đơn vị vận chuyển */}
        <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs">
          <div className="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
              <Truck className="w-4 h-4 text-amber-500" />
              <span>Cơ cấu Đơn vị Vận chuyển</span>
            </h3>
            <span className="text-xs text-slate-400">Tồn kho hiện tại</span>
          </div>

          <div className="mt-4 space-y-3">
            {(data?.breakdowns?.carriers ?? []).length === 0 ? (
              <p className="text-xs text-slate-400 py-4 text-center">Không có bưu phẩm nào đang chờ giao.</p>
            ) : (
              (data?.breakdowns?.carriers ?? []).map((item) => {
                const totalCarriers = (data?.breakdowns?.carriers ?? []).reduce((acc, c) => acc + c.count, 0);
                const pct = totalCarriers > 0 ? Math.round((item.count / totalCarriers) * 100) : 0;
                return (
                  <div key={item.carrier}>
                    <div className="flex items-center justify-between text-xs mb-1">
                      <span className="font-semibold text-neutral-800">{item.carrier}</span>
                      <span className="text-slate-500 font-mono">
                        {item.count} kiện ({pct}%)
                      </span>
                    </div>
                    <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                      <div
                        style={{ width: `${pct}%` }}
                        className="h-full bg-amber-500 rounded-full transition-all"
                      />
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>

        {/* Breakdown 2: Mục đích khách vào */}
        <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs">
          <div className="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
              <Users className="w-4 h-4 text-sky-500" />
              <span>Mục đích Viếng thăm của Khách</span>
            </h3>
            <span className="text-xs text-slate-400">Theo kỳ lọc</span>
          </div>

          <div className="mt-4 space-y-3">
            {(data?.breakdowns?.visitor_purposes ?? []).length === 0 ? (
              <p className="text-xs text-slate-400 py-4 text-center">Chưa có lượt khách nào trong kỳ lọc.</p>
            ) : (
              (data?.breakdowns?.visitor_purposes ?? []).map((item) => {
                const totalPurposes = (data?.breakdowns?.visitor_purposes ?? []).reduce((acc, p) => acc + p.count, 0);
                const pct = totalPurposes > 0 ? Math.round((item.count / totalPurposes) * 100) : 0;
                return (
                  <div key={item.purpose}>
                    <div className="flex items-center justify-between text-xs mb-1">
                      <span className="font-semibold text-neutral-800">{item.purpose}</span>
                      <span className="text-slate-500 font-mono">
                        {item.count} lượt ({pct}%)
                      </span>
                    </div>
                    <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                      <div
                        style={{ width: `${pct}%` }}
                        className="h-full bg-sky-500 rounded-full transition-all"
                      />
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 5. HAI BẢNG DANH SÁCH TÓM TẮT THỜI GIAN THỰC */}
      {/* ========================================================================= */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Table 1: Top bưu phẩm tồn đọng lâu nhất */}
        <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div>
                <h3 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
                  <Clock className="w-4 h-4 text-rose-500" />
                  <span>Bưu phẩm Tồn đọng Lâu nhất</span>
                </h3>
                <p className="text-xs text-slate-400 mt-0.5">Ưu tiên gọi điện hoặc gửi nhắc nhở cư dân</p>
              </div>

              {onNavigateTab && (
                <button
                  type="button"
                  onClick={() => onNavigateTab('parcels')}
                  className="text-xs font-semibold text-amber-600 hover:text-amber-700 cursor-pointer"
                >
                  Xem tất cả
                </button>
              )}
            </div>

            <div className="mt-3 divide-y divide-slate-100">
              {(data?.top_pending_parcels ?? []).length === 0 ? (
                <p className="text-xs text-slate-400 py-6 text-center">Không có bưu phẩm nào đang tồn đọng.</p>
              ) : (
                (data?.top_pending_parcels ?? []).map((parcel) => {
                  const isOverdue = parcel.aging_group === 'over_5_days';
                  const isWarning = parcel.aging_group === '3_to_5_days';

                  return (
                    <div key={parcel.id} className="py-2.5 flex items-center justify-between gap-2">
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="font-mono text-xs font-bold text-neutral-800 truncate">
                            {parcel.tracking_number}
                          </span>
                          <span className="text-[10px] px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-600 font-medium">
                            {parcel.carrier}
                          </span>
                        </div>
                        <div className="text-[11px] text-slate-500 mt-0.5 truncate">
                          Người nhận: <strong className="text-neutral-700">{parcel.recipient_name}</strong> • Căn: <strong className="text-neutral-700">{parcel.apartment_number}</strong>
                        </div>
                      </div>

                      <div className="shrink-0 text-right">
                        <span
                          className={`inline-block px-2 py-0.5 rounded-md text-[10px] font-bold ${
                            isOverdue
                              ? 'bg-rose-50 text-rose-700 border border-rose-200'
                              : isWarning
                              ? 'bg-amber-50 text-amber-700 border border-amber-200'
                              : 'bg-slate-100 text-slate-700'
                          }`}
                        >
                          Tồn {parcel.waiting_label}
                        </span>
                        <div className="text-[10px] text-slate-400 font-mono mt-0.5">
                          {parcel.received_at ? parcel.received_at.slice(5, 16) : ''}
                        </div>
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          </div>
        </div>

        {/* Table 2: Khách hiện đang trong tòa nhà */}
        <div className="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div>
                <h3 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
                  <ShieldCheck className="w-4 h-4 text-sky-500" />
                  <span>Khách Hiện Đang Trong Tòa Nhà</span>
                </h3>
                <p className="text-xs text-slate-400 mt-0.5">Danh sách khách đã check-in và chưa check-out</p>
              </div>

              {onNavigateTab && (
                <button
                  type="button"
                  onClick={() => onNavigateTab('visitors')}
                  className="text-xs font-semibold text-sky-600 hover:text-sky-700 cursor-pointer"
                >
                  Xem tất cả
                </button>
              )}
            </div>

            <div className="mt-3 divide-y divide-slate-100">
              {(data?.current_visitors_inside ?? []).length === 0 ? (
                <p className="text-xs text-slate-400 py-6 text-center">Hiện tại không có khách nào trong tòa nhà.</p>
              ) : (
                (data?.current_visitors_inside ?? []).map((visitor) => (
                  <div key={visitor.checkin_log_id} className="py-2.5 flex items-center justify-between gap-2">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-bold text-neutral-900 truncate">
                          {visitor.visitor_name}
                        </span>
                        {visitor.temp_card_id && (
                          <span className="text-[10px] px-1.5 py-0.5 rounded-md bg-sky-50 text-sky-700 font-mono font-medium">
                            Thẻ: {visitor.temp_card_id}
                          </span>
                        )}
                      </div>
                      <div className="text-[11px] text-slate-500 mt-0.5 truncate">
                        SĐT: <span className="font-mono">{visitor.visitor_phone_masked}</span> • Đến căn: <strong className="text-neutral-700">{visitor.apartment_number}</strong>
                      </div>
                    </div>

                    <div className="shrink-0 text-right">
                      <span className="inline-block px-2 py-0.5 rounded-md text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200">
                        {visitor.stay_duration_label}
                      </span>
                      <div className="text-[10px] text-slate-400 font-mono mt-0.5">
                        Vào: {visitor.checkin_time ? visitor.checkin_time.slice(11, 16) : ''}
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ReceptionDashboardPanel;
