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
import { RefreshCw } from 'lucide-react';
import { HistoryPagination } from './HistoryPagination';

interface ActivityTimelineProps {
  activities: ActivityItem[];
  isLoading: boolean;
  onRefresh?: () => void;
  isRefreshing?: boolean;
}

export const ActivityTimeline: React.FC<ActivityTimelineProps> = ({
  activities,
  isLoading,
  onRefresh,
  isRefreshing = false,
}) => {
  const [filterType, setFilterType] = useState<string>('all');
  const [page, setPage] = useState(1);

  const filtered = filterType === 'all'
    ? activities
    : activities.filter((a) => a.type === filterType);
  const currentPage = Math.min(page, Math.max(1, Math.ceil(filtered.length / 15)));
  const visibleActivities = filtered.slice((currentPage - 1) * 15, currentPage * 15);

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

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'running':
        return (
          <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-600 border border-amber-200 animate-pulse">
            <span className="w-1.5 h-1.5 rounded-full bg-amber-500" />
            Đang chạy
          </span>
        );
      case 'failed':
        return (
          <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-600 border border-rose-200">
            <XCircle className="w-3 h-3 text-rose-500" />
            Thất bại
          </span>
        );
      case 'recorded':
        return <span className="text-[10px] text-slate-500 shrink-0">Đã ghi nhận commit</span>;
      case 'cancelled':
      case 'skipped':
        return <span className="text-[10px] text-slate-500 shrink-0">{status === 'cancelled' ? 'Đã hủy' : 'Bỏ qua'}</span>;
      case 'queued':
        return <span className="text-[10px] text-amber-600 shrink-0">Đang chờ</span>;
      default:
        return (
          <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-600 border border-emerald-200">
            <CheckCircle2 className="w-3 h-3 text-emerald-500" />
            Thành công
          </span>
        );
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

  if (isLoading && activities.length === 0) {
    return (
      <div className="rounded-2xl bg-white/80 p-6 border border-slate-200 animate-pulse space-y-3">
        <div className="h-5 w-40 bg-slate-200 rounded" />
        <div className="h-20 bg-slate-100 rounded-xl" />
      </div>
    );
  }

  return (
    <div className="rounded-2xl bg-white/85 backdrop-blur-md p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
      {/* Title, Live Badge & Filter buttons */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2">
            <Activity className="w-4 h-4 text-sky-600" />
            <h3 className="text-sm font-bold text-neutral-900">Nhật ký Hoạt động GitHub</h3>
          </div>
          {/* Live indicator */}
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 font-mono">
            <span className="relative flex h-1.5 w-1.5">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex rounded-full h-1.5 w-1.5 bg-emerald-500" />
            </span>
            LIVE
          </span>
        </div>

        <div className="flex items-center gap-2">
          {/* Filters */}
          <div className="flex flex-wrap items-center gap-1 text-xs">
            {['all', 'commit', 'build', 'deploy', 'test', 'security', 'rollback'].map((type) => (
              <button
                key={type}
                type="button"
                onClick={() => { setFilterType(type); setPage(1); }}
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

          {/* Manual Refresh Button */}
          {onRefresh && (
            <button
              type="button"
              onClick={onRefresh}
              className={`p-1.5 rounded-xl text-slate-400 hover:text-neutral-800 hover:bg-slate-100 transition-colors cursor-pointer ${
                isRefreshing ? 'animate-spin text-sky-600' : ''
              }`}
              title="Làm mới nhật ký ngay"
              aria-label="Làm mới nhật ký ngay"
              disabled={isRefreshing}
            >
              <RefreshCw className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>

      {/* Activity List */}
      <div role="list" aria-label="Lịch sử hoạt động GitHub" className="max-h-[70vh] overflow-y-auto divide-y divide-slate-100">
        {filtered.length === 0 ? (
          <div className="py-6 text-center text-xs text-slate-400">
            Không có hoạt động nào trong danh mục này.
          </div>
        ) : (
          visibleActivities.map((act) => (
            <div role="listitem" key={act.id} className="py-3 flex items-start gap-3 text-xs group">
              <div className="p-2 rounded-xl bg-slate-100 shrink-0 group-hover:bg-slate-200/80 transition-colors">
                {getTypeIcon(act.type)}
              </div>

              <div className="flex-1 min-w-0 space-y-0.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex flex-wrap items-center gap-2 min-w-0">
                    <span className="font-bold text-neutral-900 whitespace-pre-wrap break-words">{act.title}</span>
                    {getStatusBadge(act.status)}
                  </div>
                  <span className="text-[11px] text-slate-400 font-mono shrink-0">
                    {formatRelativeTime(act.timestamp)}
                  </span>
                </div>
                <p className="text-slate-500 break-words">{act.description}</p>
                <div className="flex items-center gap-2 pt-0.5 text-[10px] text-slate-400">
                  <User className="w-3 h-3" />
                  <span>{act.actor}</span>
                  <time dateTime={act.timestamp}>{new Date(act.timestamp).toLocaleString('vi-VN')}</time>
                  {act.url && <a href={act.url} target="_blank" rel="noopener noreferrer" className="text-sky-700 underline">GitHub</a>}
                </div>
              </div>
            </div>
          ))
        )}
      </div>
      <HistoryPagination page={currentPage} total={filtered.length} onPageChange={setPage} label="Phân trang nhật ký" itemLabel="hoạt động" />
    </div>
  );
};
