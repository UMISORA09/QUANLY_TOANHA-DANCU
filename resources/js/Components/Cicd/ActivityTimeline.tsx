import React, { useState } from 'react';
import {
  Activity,
  CheckCircle2,
  XCircle,
  Play,
  RotateCcw,
  ShieldCheck,
  Box,
  Clock,
  User,
  Filter,
} from 'lucide-react';
import { ActivityItem } from '../../Services/cicdApi';

interface ActivityTimelineProps {
  activities: ActivityItem[];
  isLoading: boolean;
}

export const ActivityTimeline: React.FC<ActivityTimelineProps> = ({ activities, isLoading }) => {
  const [filterType, setFilterType] = useState<string>('all');

  const filtered = filterType === 'all'
    ? activities
    : activities.filter((a) => a.type === filterType);

  const getTypeIcon = (type: string) => {
    switch (type) {
      case 'deploy':
        return <Play className="w-3.5 h-3.5 text-emerald-500" />;
      case 'build':
        return <Box className="w-3.5 h-3.5 text-indigo-500" />;
      case 'test':
        return <ShieldCheck className="w-3.5 h-3.5 text-sky-500" />;
      case 'rollback':
        return <RotateCcw className="w-3.5 h-3.5 text-rose-500" />;
      default:
        return <Activity className="w-3.5 h-3.5 text-amber-500" />;
    }
  };

  const formatRelativeTime = (iso: string) => {
    try {
      const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
      if (diff < 60) return `${diff}s trước`;
      if (diff < 3600) return `${Math.floor(diff / 60)} phút trước`;
      if (diff < 86400) return `${Math.floor(diff / 3600)} giờ trước`;
      return `${Math.floor(diff / 86400)} ngày trước`;
    } catch {
      return iso;
    }
  };

  if (isLoading) {
    return (
      <div className="rounded-2xl bg-white/80 p-6 border border-slate-200 animate-pulse space-y-3">
        <div className="h-5 w-40 bg-slate-200 rounded" />
        <div className="h-20 bg-slate-100 rounded-xl" />
      </div>
    );
  }

  return (
    <div className="rounded-2xl bg-white/85 backdrop-blur-md p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
      {/* Title & Filter buttons */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
        <div className="flex items-center gap-2">
          <Activity className="w-4 h-4 text-sky-600" />
          <h3 className="text-sm font-bold text-neutral-900">Nhật ký Hoạt động DevOps (Recent Activity)</h3>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-1 text-xs">
          {['all', 'build', 'deploy', 'test', 'security'].map((type) => (
            <button
              key={type}
              type="button"
              onClick={() => setFilterType(type)}
              className={`px-2.5 py-1 rounded-lg font-bold capitalize transition-colors cursor-pointer ${
                filterType === type
                  ? 'bg-neutral-900 text-white shadow-2xs'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              {type}
            </button>
          ))}
        </div>
      </div>

      {/* Activity List */}
      <div className="divide-y divide-slate-100">
        {filtered.length === 0 ? (
          <div className="py-6 text-center text-xs text-slate-400">
            Không có hoạt động nào trong danh mục này.
          </div>
        ) : (
          filtered.map((act) => (
            <div key={act.id} className="py-3 flex items-start gap-3 text-xs group">
              <div className="p-2 rounded-xl bg-slate-100 shrink-0 group-hover:bg-slate-200/80 transition-colors">
                {getTypeIcon(act.type)}
              </div>

              <div className="flex-1 min-w-0 space-y-0.5">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-bold text-neutral-900">{act.title}</span>
                  <span className="text-[11px] text-slate-400 font-mono shrink-0">
                    {formatRelativeTime(act.timestamp)}
                  </span>
                </div>
                <p className="text-slate-500 line-clamp-1">{act.description}</p>
                <div className="flex items-center gap-2 pt-0.5 text-[10px] text-slate-400">
                  <User className="w-3 h-3" />
                  <span>{act.actor}</span>
                </div>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
};
