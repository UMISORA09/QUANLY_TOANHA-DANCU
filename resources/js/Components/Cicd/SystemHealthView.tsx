import React from 'react';
import {
  HeartPulse,
  Database,
  Cpu,
  HardDrive,
  Activity,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  RefreshCw,
} from 'lucide-react';
import { SystemHealthStatus } from '../../Services/cicdApi';

interface SystemHealthViewProps {
  health: SystemHealthStatus | null;
  isLoading: boolean;
  onRefresh?: () => void;
}

export const SystemHealthView: React.FC<SystemHealthViewProps> = ({ health, isLoading, onRefresh }) => {
  if (isLoading || !health) {
    return (
      <div className="rounded-2xl bg-white/80 p-6 border border-slate-200 animate-pulse space-y-4">
        <div className="h-6 w-48 bg-slate-200 rounded" />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-20 bg-slate-100 rounded-xl" />
          ))}
        </div>
      </div>
    );
  }

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'operational':
        return <CheckCircle2 className="w-4 h-4 text-emerald-500" />;
      case 'degraded':
        return <AlertTriangle className="w-4 h-4 text-amber-500" />;
      default:
        return <XCircle className="w-4 h-4 text-rose-500" />;
    }
  };

  return (
    <div className="rounded-2xl bg-white/85 backdrop-blur-md p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-5">
      {/* Title & Refresh */}
      <div className="flex items-center justify-between pb-3 border-b border-slate-100">
        <div className="flex items-center gap-2">
          <div className="p-2 rounded-xl bg-emerald-50 text-emerald-600">
            <HeartPulse className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-neutral-900">Giám sát Sức khỏe Hệ thống (System Health)</h3>
            <span className="text-[11px] text-slate-400 font-mono">Dữ liệu kiểm tra thực tế từ máy chủ</span>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span className="w-2 h-2 rounded-full bg-emerald-500" />
            <span className="capitalize">{health.status}</span>
          </span>

          {onRefresh && (
            <button
              type="button"
              onClick={onRefresh}
              className="p-1.5 rounded-xl text-slate-400 hover:text-neutral-800 hover:bg-slate-100 transition-colors cursor-pointer"
              title="Làm mới tình trạng sức khỏe"
            >
              <RefreshCw className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>

      {/* Services Grid */}
      {(() => {
        const servicesList = health.services || (health as any).components || [];
        if (servicesList.length === 0) {
          return (
            <div className="p-4 rounded-xl bg-slate-50 text-slate-500 text-xs text-center font-medium">
              Đang phân tích các dịch vụ hệ thống...
            </div>
          );
        }

        return (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            {servicesList.map((svc: any, idx: number) => (
              <div
                key={idx}
                className="p-3.5 rounded-xl bg-slate-50/70 border border-slate-200/70 hover:bg-slate-50 transition-colors flex items-start justify-between gap-3"
              >
                <div className="space-y-1 min-w-0">
                  <span className="text-xs font-bold text-neutral-800 truncate block">{svc.name}</span>
                  <span className="text-[11px] text-slate-500 font-mono block truncate">{svc.version}</span>
                  <span className="text-[10px] text-slate-400">Độ trễ: {svc.response_time}</span>
                </div>

                <div className="shrink-0 pt-0.5">
                  {getStatusIcon(svc.status)}
                </div>
              </div>
            ))}
          </div>
        );
      })()}

      {/* Metrics Bar */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2 border-t border-slate-100 text-xs">
        <div className="p-3 rounded-xl bg-slate-100/60 flex items-center gap-2.5">
          <Cpu className="w-4 h-4 text-sky-500 shrink-0" />
          <div>
            <span className="text-[10px] uppercase font-bold text-slate-400 block">PHP Version</span>
            <span className="font-mono font-bold text-neutral-800">{health.metrics?.php_version ?? '8.4'}</span>
          </div>
        </div>

        <div className="p-3 rounded-xl bg-slate-100/60 flex items-center gap-2.5">
          <Activity className="w-4 h-4 text-emerald-500 shrink-0" />
          <div>
            <span className="text-[10px] uppercase font-bold text-slate-400 block">Bộ nhớ RAM PHP</span>
            <span className="font-mono font-bold text-neutral-800">{health.metrics?.memory_usage_mb ?? 0} MB</span>
          </div>
        </div>

        <div className="p-3 rounded-xl bg-slate-100/60 flex items-center gap-2.5">
          <Database className="w-4 h-4 text-amber-500 shrink-0" />
          <div>
            <span className="text-[10px] uppercase font-bold text-slate-400 block">MySQL Latency</span>
            <span className="font-mono font-bold text-neutral-800">{health.metrics?.db_ping_ms ?? 0} ms</span>
          </div>
        </div>

        <div className="p-3 rounded-xl bg-slate-100/60 flex items-center gap-2.5">
          <HardDrive className="w-4 h-4 text-indigo-500 shrink-0" />
          <div>
            <span className="text-[10px] uppercase font-bold text-slate-400 block">Dung lượng ổ đĩa</span>
            <span className="font-mono font-bold text-neutral-800">{health.metrics?.disk_usage_percent ?? 0}% used</span>
          </div>
        </div>
      </div>
    </div>
  );
};
