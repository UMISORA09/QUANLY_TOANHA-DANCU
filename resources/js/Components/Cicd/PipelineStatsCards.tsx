import React from 'react';
import {
  CheckCircle2,
  XCircle,
  Play,
  Server,
  ShieldCheck,
  TrendingUp,
} from 'lucide-react';
import { CicdOverviewStats } from '../../Services/cicdApi';

interface PipelineStatsCardsProps {
  stats: CicdOverviewStats | null;
  isLoading: boolean;
}

export const PipelineStatsCards: React.FC<PipelineStatsCardsProps> = ({ stats, isLoading }) => {
  if (isLoading || !stats) {
    return (
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
        {Array.from({ length: 6 }).map((_, i) => (
          <div
            key={i}
            className="rounded-2xl bg-white/70 backdrop-blur-md p-5 border border-slate-200/80 shadow-xs animate-pulse space-y-3 min-h-[135px] flex flex-col justify-between"
          >
            <div className="flex items-center justify-between">
              <div className="h-4 w-32 bg-slate-200 rounded" />
              <div className="h-7 w-7 bg-slate-200 rounded-xl" />
            </div>
            <div className="h-8 w-24 bg-slate-300 rounded-lg" />
            <div className="flex items-center justify-between pt-2 border-t border-slate-100">
              <div className="h-3 w-36 bg-slate-100 rounded" />
              <div className="h-4 w-16 bg-slate-200 rounded" />
            </div>
          </div>
        ))}
      </div>
    );
  }

  const cards = [
    {
      title: 'Tỷ lệ thành công',
      value: `${stats.success_rate}%`,
      subtext: `${stats.total_pipelines} lượt pipeline gần đây`,
      icon: TrendingUp,
      color: 'text-emerald-600',
      bgColor: 'bg-emerald-50/60 border-emerald-200/80',
      badge: 'Tháng này',
      badgeColor: 'bg-emerald-100 text-emerald-800 border border-emerald-200',
    },
    {
      title: 'CI Passing',
      value: stats.success_count,
      subtext: 'Bản build kiểm thử thành công',
      icon: CheckCircle2,
      color: 'text-emerald-600',
      bgColor: 'bg-white/85 border-slate-200/80',
      badge: 'Thành công',
      badgeColor: 'bg-emerald-100 text-emerald-800 border border-emerald-200',
    },
    {
      title: 'Failed / Lỗi cần xử lý',
      value: stats.failed_count,
      subtext: stats.failed_count === 0 ? 'Toàn bộ kiểm thử ổn định' : 'Cần kiểm tra log chi tiết',
      icon: XCircle,
      color: stats.failed_count > 0 ? 'text-rose-600' : 'text-slate-400',
      bgColor: stats.failed_count > 0 ? 'bg-rose-50/60 border-rose-200/80' : 'bg-white/85 border-slate-200/80',
      badge: stats.failed_count > 0 ? 'Cảnh báo lỗi' : 'Hệ thống tốt',
      badgeColor: stats.failed_count > 0 ? 'bg-rose-100 text-rose-800 border border-rose-200' : 'bg-slate-100 text-slate-700 border border-slate-200',
    },
    {
      title: 'Đang chạy / Hàng đợi',
      value: stats.running_count,
      subtext: stats.running_count > 0 ? 'Đang thực thi trên runner' : 'Hệ thống sẵn sàng nhận lệnh',
      icon: Play,
      color: stats.running_count > 0 ? 'text-sky-600 animate-pulse' : 'text-slate-500',
      bgColor: stats.running_count > 0 ? 'bg-sky-50/70 border-sky-200/80' : 'bg-white/85 border-slate-200/80',
      badge: stats.running_count > 0 ? 'Đang chạy' : 'Sẵn sàng',
      badgeColor: stats.running_count > 0 ? 'bg-sky-100 text-sky-800 border border-sky-200' : 'bg-slate-100 text-slate-600 border border-slate-200',
    },
    {
      title: 'Môi trường Production',
      value: stats.production_version || 'Chưa thiết lập',
      subtext: 'Máy chủ vận hành chính thức',
      icon: ShieldCheck,
      color: stats.production_status === 'healthy' || stats.production_status === 'operational' ? 'text-emerald-600' : 'text-slate-500',
      bgColor: 'bg-white/85 border-slate-200/80',
      badge: stats.production_status === 'not_configured'
        ? 'Not configured'
        : (stats.production_status === 'healthy' || stats.production_status === 'operational'
          ? 'Operational'
          : (stats.production_status === 'degraded'
            ? 'Degraded'
            : (stats.production_status === 'unhealthy' || stats.production_status === 'down' ? 'Down' : 'Chưa triển khai'))),
      badgeColor: stats.production_status === 'healthy' || stats.production_status === 'operational'
        ? 'bg-emerald-100 text-emerald-800 border border-emerald-200'
        : (stats.production_status === 'degraded'
          ? 'bg-amber-100 text-amber-800 border border-amber-200'
          : (stats.production_status === 'unhealthy' || stats.production_status === 'down'
            ? 'bg-rose-100 text-rose-800 border border-rose-200'
            : 'bg-slate-100 text-slate-600 border border-slate-200')),
    },
    {
      title: 'Môi trường Staging CD',
      value: stats.staging_version || 'Chưa thiết lập',
      subtext: 'Môi trường kiểm thử & Staging',
      icon: Server,
      color: stats.staging_status === 'healthy' || stats.staging_status === 'operational' ? 'text-indigo-600' : 'text-slate-500',
      bgColor: 'bg-white/85 border-slate-200/80',
      badge: stats.staging_status === 'not_configured'
        ? 'Not configured'
        : (stats.staging_status === 'healthy' || stats.staging_status === 'operational'
          ? 'Operational'
          : (stats.staging_status === 'degraded'
            ? 'Degraded'
            : (stats.staging_status === 'unhealthy' || stats.staging_status === 'down' ? 'Down' : 'Chưa triển khai'))),
      badgeColor: stats.staging_status === 'healthy' || stats.staging_status === 'operational'
        ? 'bg-indigo-100 text-indigo-800 border border-indigo-200'
        : (stats.staging_status === 'degraded'
          ? 'bg-amber-100 text-amber-800 border border-amber-200'
          : (stats.staging_status === 'unhealthy' || stats.staging_status === 'down'
            ? 'bg-rose-100 text-rose-800 border border-rose-200'
            : 'bg-slate-100 text-slate-600 border border-slate-200')),
    },
  ];

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
      {cards.map((card, idx) => {
        const Icon = card.icon;
        const isNumeric = typeof card.value === 'number' || (typeof card.value === 'string' && /^[0-9%]+$/.test(card.value.trim()));

        return (
          <div
            key={idx}
            className={`rounded-2xl backdrop-blur-md p-4 sm:p-5 border transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 relative overflow-hidden flex flex-col justify-between min-h-[135px] group ${card.bgColor}`}
          >
            {/* Top row: Title and Icon */}
            <div>
              <div className="flex items-center justify-between mb-2 gap-2">
                <span className="text-xs font-bold text-slate-600 uppercase tracking-wider font-sans">
                  {card.title}
                </span>
                <div className={`p-2 rounded-xl bg-slate-100/90 shadow-2xs shrink-0 ${card.color}`}>
                  <Icon className="w-4 h-4" />
                </div>
              </div>

              {/* Middle row: Value Display */}
              <div className="flex items-center min-h-[38px] my-1">
                {isNumeric ? (
                  <span className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight font-sans">
                    {card.value}
                  </span>
                ) : (
                  <span
                    className="text-xs sm:text-sm font-bold text-neutral-800 font-mono break-words leading-relaxed py-0.5"
                    title={String(card.value)}
                  >
                    {card.value}
                  </span>
                )}
              </div>
            </div>

            {/* Bottom row: Subtext and Badge */}
            <div className="flex items-center justify-between mt-3 pt-2.5 border-t border-slate-200/60 text-xs text-slate-500 gap-2">
              <span className="text-slate-500 leading-tight font-medium" title={card.subtext}>
                {card.subtext}
              </span>
              <span className={`px-2 py-0.5 rounded-md text-[10px] font-bold shrink-0 whitespace-nowrap shadow-2xs ${card.badgeColor}`}>
                {card.badge}
              </span>
            </div>
          </div>
        );
      })}
    </div>
  );
};
