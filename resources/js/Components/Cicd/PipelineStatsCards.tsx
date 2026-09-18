import React from 'react';
import {
  CheckCircle2,
  XCircle,
  Clock,
  Play,
  Server,
  ShieldCheck,
  TrendingUp,
  Activity,
  Layers,
  ArrowUpRight,
} from 'lucide-react';
import { CicdOverviewStats } from '../../Services/cicdApi';

interface PipelineStatsCardsProps {
  stats: CicdOverviewStats | null;
  isLoading: boolean;
}

export const PipelineStatsCards: React.FC<PipelineStatsCardsProps> = ({ stats, isLoading }) => {
  if (isLoading || !stats) {
    return (
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
        {Array.from({ length: 6 }).map((_, i) => (
          <div
            key={i}
            className="rounded-2xl bg-white/70 backdrop-blur-md p-4 border border-slate-200/80 shadow-xs animate-pulse space-y-3"
          >
            <div className="h-4 w-20 bg-slate-200 rounded" />
            <div className="h-7 w-14 bg-slate-300 rounded-lg" />
            <div className="h-3 w-28 bg-slate-100 rounded" />
          </div>
        ))}
      </div>
    );
  }

  const cards = [
    {
      title: 'Tỷ lệ Thành công',
      value: `${stats.success_rate}%`,
      subtext: `${stats.total_pipelines} lượt chạy gần đây`,
      icon: TrendingUp,
      color: 'text-emerald-600',
      bgColor: 'bg-emerald-50/80 border-emerald-200/80',
      badge: 'Tháng này',
      badgeColor: 'bg-emerald-100/80 text-emerald-800',
    },
    {
      title: 'CI Passing',
      value: stats.success_count,
      subtext: 'Bản build đạt chuẩn',
      icon: CheckCircle2,
      color: 'text-emerald-600',
      bgColor: 'bg-white/80 border-slate-200/80',
      badge: 'Thành công',
      badgeColor: 'bg-emerald-100/80 text-emerald-800',
    },
    {
      title: 'Failed / Lỗi',
      value: stats.failed_count,
      subtext: stats.failed_count === 0 ? 'Không có lỗi' : 'Cần kiểm tra lại',
      icon: XCircle,
      color: stats.failed_count > 0 ? 'text-rose-600' : 'text-slate-400',
      bgColor: stats.failed_count > 0 ? 'bg-rose-50/60 border-rose-200/80' : 'bg-white/80 border-slate-200/80',
      badge: stats.failed_count > 0 ? 'Cảnh báo' : 'Tốt',
      badgeColor: stats.failed_count > 0 ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700',
    },
    {
      title: 'Đang chạy / Hàng đợi',
      value: stats.running_count,
      subtext: stats.running_count > 0 ? 'Đang thực thi...' : 'Hệ thống sẵn sàng',
      icon: Play,
      color: stats.running_count > 0 ? 'text-sky-600 animate-pulse' : 'text-slate-500',
      bgColor: stats.running_count > 0 ? 'bg-sky-50/70 border-sky-200/80' : 'bg-white/80 border-slate-200/80',
      badge: stats.running_count > 0 ? 'Active' : 'Idle',
      badgeColor: stats.running_count > 0 ? 'bg-sky-100 text-sky-800' : 'bg-slate-100 text-slate-600',
    },
    {
      title: 'Production',
      value: stats.production_version,
      subtext: 'Cụm máy chủ trực tiếp',
      icon: ShieldCheck,
      color: 'text-amber-600',
      bgColor: 'bg-white/80 border-slate-200/80',
      badge: 'Healthy 99.9%',
      badgeColor: 'bg-emerald-100 text-emerald-800',
    },
    {
      title: 'Staging CD',
      value: stats.staging_version,
      subtext: 'Môi trường kiểm thử',
      icon: Server,
      color: 'text-indigo-600',
      bgColor: 'bg-white/80 border-slate-200/80',
      badge: 'Up to date',
      badgeColor: 'bg-indigo-100 text-indigo-800',
    },
  ];

  return (
    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
      {cards.map((card, idx) => {
        const Icon = card.icon;
        return (
          <div
            key={idx}
            className={`rounded-2xl backdrop-blur-md p-4 border transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 relative overflow-hidden group ${card.bgColor}`}
          >
            <div className="flex items-center justify-between mb-2">
              <span className="text-[11px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider truncate">
                {card.title}
              </span>
              <div className={`p-1.5 rounded-xl bg-slate-100/80 ${card.color}`}>
                <Icon className="w-4 h-4" />
              </div>
            </div>

            <div className="flex items-baseline gap-2">
              <span className="text-xl sm:text-2xl font-black text-neutral-900 tracking-tight">
                {card.value}
              </span>
            </div>

            <div className="flex items-center justify-between mt-2 pt-2 border-t border-slate-100 text-[11px] text-slate-500">
              <span className="truncate pr-1">{card.subtext}</span>
              <span className={`px-1.5 py-0.5 rounded-md text-[10px] font-bold shrink-0 ${card.badgeColor}`}>
                {card.badge}
              </span>
            </div>
          </div>
        );
      })}
    </div>
  );
};
