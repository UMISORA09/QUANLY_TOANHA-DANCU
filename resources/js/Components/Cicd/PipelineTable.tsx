import React from 'react';
import {
  CheckCircle2,
  XCircle,
  Clock,
  Loader2,
  GitBranch,
  GitCommit,
  User,
  ArrowRight,
  ExternalLink,
  ChevronRight,
  RotateCw,
} from 'lucide-react';
import { PipelineItem, PipelineStatus } from '../../Services/cicdApi';

interface PipelineTableProps {
  pipelines: PipelineItem[];
  isLoading: boolean;
  onSelectPipeline: (pipeline: PipelineItem) => void;
  onRetry?: (id: string) => void;
  canRun?: boolean;
}

export const PipelineTable: React.FC<PipelineTableProps> = ({
  pipelines,
  isLoading,
  onSelectPipeline,
  onRetry,
  canRun = true,
}) => {
  const getStatusBadge = (status: PipelineStatus) => {
    switch (status) {
      case 'success':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <CheckCircle2 className="w-3.5 h-3.5" />
            <span>Success</span>
          </span>
        );
      case 'failed':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
            <XCircle className="w-3.5 h-3.5" />
            <span>Failed</span>
          </span>
        );
      case 'running':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-sky-50 text-sky-700 border border-sky-200 animate-pulse">
            <Loader2 className="w-3.5 h-3.5 animate-spin" />
            <span>Running</span>
          </span>
        );
      case 'cancelled':
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
            <Clock className="w-3.5 h-3.5" />
            <span>Cancelled</span>
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
            <Clock className="w-3.5 h-3.5" />
            <span>Queued</span>
          </span>
        );
    }
  };

  const formatTimestamp = (dateStr: string) => {
    try {
      const d = new Date(dateStr);
      return d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) + ' ' + d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
    } catch {
      return dateStr;
    }
  };

  if (isLoading && pipelines.length === 0) {
    return (
      <div className="rounded-2xl bg-white/80 backdrop-blur-md border border-slate-200/80 shadow-xs overflow-hidden">
        <div className="p-8 text-center space-y-3">
          <Loader2 className="w-8 h-8 text-sky-500 animate-spin mx-auto" />
          <p className="text-xs text-slate-500 font-medium">Đang đồng bộ dữ liệu pipelines...</p>
        </div>
      </div>
    );
  }

  if (pipelines.length === 0) {
    return (
      <div className="rounded-2xl bg-white/80 backdrop-blur-md border border-slate-200/80 shadow-xs p-12 text-center space-y-3">
        <div className="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto text-slate-400">
          <GitBranch className="w-6 h-6" />
        </div>
        <h4 className="text-sm font-bold text-neutral-800">Chưa có lượt chạy CI/CD nào</h4>
        <p className="text-xs text-slate-500 max-w-md mx-auto leading-relaxed">
          Hiện tại chưa có lượt chạy pipeline nào trên GitHub Actions cho repository. Hệ thống sẽ tự động ghi nhận và hiển thị ngay khi bất kỳ thành viên nào trong nhóm thực hiện <strong>git push</strong> lên GitHub hoặc khi bạn bấm <strong>Run Pipeline</strong>.
        </p>
      </div>
    );
  }

  return (
    <div className="rounded-2xl bg-white/85 backdrop-blur-md border border-slate-200/80 shadow-xs overflow-hidden">
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className="bg-slate-50/80 border-b border-slate-200 text-[11px] font-bold uppercase tracking-wider text-slate-500">
              <th className="py-3.5 px-4">Trạng thái</th>
              <th className="py-3.5 px-4">Pipeline</th>
              <th className="py-3.5 px-4">Nhánh</th>
              <th className="py-3.5 px-4">Commit</th>
              <th className="py-3.5 px-4">Tác giả</th>
              <th className="py-3.5 px-4">Trigger</th>
              <th className="py-3.5 px-4">Thời lượng</th>
              <th className="py-3.5 px-4">Bắt đầu</th>
              <th className="py-3.5 px-4 text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {pipelines.map((p) => (
              <tr
                key={p.id}
                onClick={() => onSelectPipeline(p)}
                className="hover:bg-slate-50/80 transition-colors cursor-pointer group"
              >
                {/* Status */}
                <td className="py-3.5 px-4 whitespace-nowrap">
                  {getStatusBadge(p.status)}
                </td>

                {/* Pipeline */}
                <td className="py-3.5 px-4">
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-neutral-500 font-bold">#{p.run_number}</span>
                    <span className="font-bold text-neutral-900 group-hover:text-sky-600 transition-colors">
                      {p.name}
                    </span>
                  </div>
                  <div
                    className="text-[11px] text-slate-500 font-medium truncate max-w-sm mt-0.5"
                    title={p.commit_message}
                  >
                    {p.commit_message}
                  </div>
                </td>

                {/* Branch */}
                <td className="py-3.5 px-4 whitespace-nowrap">
                  <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 text-neutral-800 font-mono text-[11px] font-semibold border border-slate-200/60">
                    <GitBranch className="w-3 h-3 text-slate-500" />
                    <span className="max-w-[120px] truncate">{p.branch}</span>
                  </span>
                </td>

                {/* Commit */}
                <td className="py-3.5 px-4 whitespace-nowrap font-mono text-xs text-neutral-700">
                  <span className="inline-flex items-center gap-1 bg-neutral-100 px-2 py-0.5 rounded text-neutral-800 font-bold">
                    <GitCommit className="w-3 h-3 text-slate-400" />
                    {p.commit_sha}
                  </span>
                </td>

                {/* Author */}
                <td className="py-3.5 px-4 whitespace-nowrap">
                  <div className="flex items-center gap-2.5">
                    {p.author_avatar ? (
                      <img
                        src={p.author_avatar}
                        alt={p.author}
                        className="w-6 h-6 rounded-full object-cover ring-1 ring-slate-200 shrink-0"
                      />
                    ) : (
                      <div className="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center text-[10px] font-bold text-slate-600 shrink-0">
                        {p.author.slice(0, 1).toUpperCase()}
                      </div>
                    )}
                    <div className="flex flex-col">
                      <span className="font-medium text-neutral-800 text-xs leading-snug">{p.author}</span>
                      {p.author_login && p.author_login !== p.author && (
                        <span className="text-[10px] text-slate-400 font-mono leading-none">@{p.author_login}</span>
                      )}
                    </div>
                  </div>
                </td>

                {/* Trigger */}
                <td className="py-3.5 px-4 whitespace-nowrap">
                  {p.trigger === 'merge' ? (
                    <span className="inline-flex items-center px-2 py-0.5 rounded bg-purple-50 text-purple-700 border border-purple-200 text-[10px] font-bold uppercase tracking-wider">
                      Merge
                    </span>
                  ) : p.trigger === 'push' ? (
                    <span className="inline-flex items-center px-2 py-0.5 rounded bg-sky-50 text-sky-700 border border-sky-200 text-[10px] font-bold uppercase tracking-wider">
                      Push
                    </span>
                  ) : p.trigger === 'manual' ? (
                    <span className="inline-flex items-center px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold uppercase tracking-wider">
                      Manual
                    </span>
                  ) : (
                    <span className="capitalize px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] font-bold uppercase tracking-wider">
                      {p.trigger}
                    </span>
                  )}
                </td>

                {/* Duration */}
                <td className="py-3.5 px-4 whitespace-nowrap font-mono font-medium text-neutral-700">
                  {p.duration}
                </td>

                {/* Started */}
                <td className="py-3.5 px-4 whitespace-nowrap text-slate-500">
                  {formatTimestamp(p.created_at)}
                </td>

                {/* Action */}
                <td className="py-3.5 px-4 whitespace-nowrap text-right">
                  <div className="flex items-center justify-end gap-1" onClick={(e) => e.stopPropagation()}>
                    {p.status === 'failed' && canRun && onRetry && (
                      <button
                        type="button"
                        onClick={() => onRetry(p.id)}
                        className="p-1.5 rounded-lg text-slate-500 hover:text-sky-600 hover:bg-sky-50 transition-colors cursor-pointer"
                        title="Chạy lại pipeline"
                      >
                        <RotateCw className="w-3.5 h-3.5" />
                      </button>
                    )}

                    <button
                      type="button"
                      onClick={() => onSelectPipeline(p)}
                      className="p-1.5 rounded-lg text-slate-400 hover:text-neutral-800 hover:bg-slate-100 transition-colors cursor-pointer"
                      title="Xem chi tiết"
                    >
                      <ChevronRight className="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};
