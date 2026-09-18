import React from 'react';
import { Server, Activity, GitCommit, Clock, ExternalLink, CheckCircle2 } from 'lucide-react';
import { EnvironmentItem } from '../../Services/cicdApi';

interface EnvironmentCardsProps {
  environments: EnvironmentItem[];
  isLoading: boolean;
}

export const EnvironmentCards: React.FC<EnvironmentCardsProps> = ({ environments = [], isLoading }) => {
  const envList = Array.isArray(environments) ? environments : [];

  if (isLoading) {
    return (
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className="h-40 rounded-2xl bg-white/70 animate-pulse border border-slate-200" />
        ))}
      </div>
    );
  }

  if (envList.length === 0) {
    return (
      <div className="p-8 rounded-2xl bg-white border border-slate-200 text-center text-slate-500 text-xs font-medium">
        Đang phân tích thông tin môi trường triển khai...
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
      {envList.map((env) => (
        <div
          key={env.id}
          className="rounded-2xl bg-white/85 backdrop-blur-md p-5 border border-slate-200/80 shadow-xs hover:shadow-md transition-all space-y-3 flex flex-col justify-between"
        >
          <div>
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <Server className="w-4 h-4 text-slate-500" />
                <h4 className="text-sm font-bold text-neutral-900">{env.name}</h4>
              </div>

              {env.status === 'operational' ? (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                  <span className="w-2 h-2 rounded-full bg-emerald-500" />
                  <span>Hoạt động</span>
                </span>
              ) : (
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                  <span className="w-2 h-2 rounded-full bg-slate-400" />
                  <span>Chưa triển khai</span>
                </span>
              )}
            </div>

            <div className="space-y-2 text-xs pt-1">
              <div className="flex items-center justify-between">
                <span className="text-slate-400">Phiên bản:</span>
                <span className="font-mono font-bold text-neutral-800 bg-slate-100 px-2 py-0.5 rounded">
                  {env.version}
                </span>
              </div>

              <div className="flex items-center justify-between">
                <span className="text-slate-400">Commit:</span>
                <span className="font-mono font-bold text-neutral-800 bg-neutral-100 px-2 py-0.5 rounded flex items-center gap-1">
                  <GitCommit className="w-3 h-3 text-slate-400" />
                  {env.commit_sha}
                </span>
              </div>

              <div className="flex items-center justify-between">
                <span className="text-slate-400">Thời gian đáp ứng:</span>
                <span className="font-mono font-bold text-emerald-700">
                  {env.response_time_ms} ms
                </span>
              </div>

              <div className="flex items-center justify-between">
                <span className="text-slate-400">Độ khả dụng (Uptime):</span>
                <span className="font-bold text-neutral-800">{env.uptime_percentage}</span>
              </div>
            </div>
          </div>

          <div className="pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
            <span>Nhánh: <strong className="text-neutral-700 font-mono">{env.branch}</strong></span>
            {env.url && (
              <a
                href={env.url}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1 text-sky-600 hover:text-sky-800 font-semibold"
              >
                <span>Mở link</span>
                <ExternalLink className="w-3 h-3" />
              </a>
            )}
          </div>
        </div>
      ))}
    </div>
  );
};
