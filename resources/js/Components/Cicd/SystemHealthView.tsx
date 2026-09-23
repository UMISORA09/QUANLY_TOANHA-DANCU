import React, { useState, useEffect } from 'react';
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
  Radio,
} from 'lucide-react';
import { SystemHealthStatus, cicdApi } from '../../Services/cicdApi';

interface SystemHealthViewProps {
  health: SystemHealthStatus | null;
  isLoading: boolean;
  onRefresh?: () => void;
  onHealthUpdate?: (health: SystemHealthStatus) => void;
  autoRefreshInterval?: number; // seconds, default 1
}

export const SystemHealthView: React.FC<SystemHealthViewProps> = ({
  health,
  isLoading,
  onRefresh,
  onHealthUpdate,
  autoRefreshInterval = 1,
}) => {
  const [currentHealth, setCurrentHealth] = useState<SystemHealthStatus | null>(health);
  const [isLive, setIsLive] = useState<boolean>(true);
  const [intervalSec, setIntervalSec] = useState<number>(autoRefreshInterval);
  const [isPolling, setIsPolling] = useState<boolean>(false);
  const [lastUpdated, setLastUpdated] = useState<Date>(new Date());

  // Đồng bộ khi prop health ban đầu hoặc refresh từ bên ngoài thay đổi
  useEffect(() => {
    if (health) {
      setCurrentHealth(health);
      setLastUpdated(new Date());
    }
  }, [health]);

  // Vòng lặp chạy liên tục theo thời gian thực (Non-overlapping Self-scheduling Polling)
  useEffect(() => {
    if (!isLive) return;

    let isMounted = true;
    let timerId: ReturnType<typeof setTimeout> | null = null;

    const tick = async () => {
      // Tự động tạm ngưng khi chuyển tab trình duyệt để tiết kiệm tài nguyên
      if (document.visibilityState === 'visible') {
        try {
          setIsPolling(true);
          const data = await cicdApi.getHealth();
          if (isMounted && data) {
            setCurrentHealth(data);
            setLastUpdated(new Date());
            onHealthUpdate?.(data);
          }
        } catch (err) {
          console.warn('Real-time health check tick failed:', err);
        } finally {
          if (isMounted) {
            setIsPolling(false);
          }
        }
      }

      // Lên lịch nhịp kế tiếp sau khi nhịp hiện tại đã hoàn tất (tránh nghẽn hàng đợi mạng)
      if (isMounted && isLive) {
        timerId = setTimeout(tick, intervalSec * 1000);
      }
    };

    timerId = setTimeout(tick, intervalSec * 1000);

    return () => {
      isMounted = false;
      if (timerId) clearTimeout(timerId);
    };
  }, [isLive, intervalSec, onHealthUpdate]);

  const handleManualRefresh = async () => {
    try {
      setIsPolling(true);
      const data = await cicdApi.getHealth();
      if (data) {
        setCurrentHealth(data);
        setLastUpdated(new Date());
        onHealthUpdate?.(data);
      }
      if (onRefresh) onRefresh();
    } catch (err) {
      console.error(err);
    } finally {
      setIsPolling(false);
    }
  };

  const activeHealth = currentHealth || health;

  if (isLoading && !activeHealth) {
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

  if (!activeHealth) {
    return null;
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
    <div className="rounded-2xl bg-white/85 backdrop-blur-md p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-5 transition-all">
      {/* Title & Real-time Live Status Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
        <div className="flex items-center gap-2.5">
          <div className="p-2 rounded-xl bg-emerald-50 text-emerald-600">
            <HeartPulse className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-neutral-900">Giám sát Sức khỏe Hệ thống (System Health)</h3>
            <div className="flex items-center gap-2 mt-0.5">
              <span className="text-[11px] text-slate-400 font-mono">Dữ liệu kiểm tra thực tế từ máy chủ</span>
              <span className="text-[10px] text-slate-300">•</span>
              <span className="text-[11px] text-slate-500 font-mono">
                Cập nhật lúc: {lastUpdated.toLocaleTimeString('vi-VN')}
              </span>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2.5 flex-wrap">
          {/* Speed Selector: 1s, 3s, 5s */}
          <div className="flex items-center bg-slate-100/90 p-0.5 rounded-xl border border-slate-200/80 text-[11px] font-mono">
            {[1, 3, 5].map((sec) => (
              <button
                key={sec}
                type="button"
                onClick={() => setIntervalSec(sec)}
                className={`px-2 py-0.5 rounded-lg font-bold transition-all cursor-pointer ${
                  intervalSec === sec
                    ? 'bg-white text-emerald-700 shadow-xs border border-slate-200/60'
                    : 'text-slate-500 hover:text-neutral-800'
                }`}
                title={`Tốc độ cập nhật: ${sec} giây / lần`}
              >
                {sec}s
              </button>
            ))}
          </div>

          {/* Live Continuous Toggle */}
          <button
            type="button"
            onClick={() => setIsLive(!isLive)}
            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold transition-all cursor-pointer border ${
              isLive
                ? 'bg-emerald-50 text-emerald-700 border-emerald-300/90 shadow-xs hover:bg-emerald-100'
                : 'bg-slate-100 text-slate-600 border-slate-200 hover:bg-slate-200'
            }`}
            title={isLive ? 'Đang chạy liên tục theo thời gian thực (Nhấp để tạm dừng)' : 'Đã tạm dừng (Nhấp để bật chạy liên tục)'}
          >
            {isLive ? (
              <span className="relative flex h-2 w-2">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
              </span>
            ) : (
              <span className="w-2 h-2 rounded-full bg-slate-400" />
            )}
            <span>{isLive ? 'LIVE' : 'PAUSED'}</span>
          </button>

          {/* System Overall Status */}
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span className="w-2 h-2 rounded-full bg-emerald-500" />
            <span className="capitalize">{activeHealth.status}</span>
          </span>

          {/* Refresh Action */}
          <button
            type="button"
            onClick={handleManualRefresh}
            className={`p-1.5 rounded-xl text-slate-400 hover:text-neutral-800 hover:bg-slate-100 transition-colors cursor-pointer ${
              isPolling ? 'animate-spin text-emerald-600' : ''
            }`}
            title="Làm mới ngay"
          >
            <RefreshCw className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* Services Grid */}
      {(() => {
        const servicesList = activeHealth.services || (activeHealth as any).components || [];
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
        <div className="p-3 rounded-xl bg-slate-100/60 flex items-center gap-2.5 transition-all">
          <Cpu className="w-4 h-4 text-sky-500 shrink-0" />
          <div>
            <span className="text-[10px] uppercase font-bold text-slate-400 block">PHP Version</span>
            <span className="font-mono font-bold text-neutral-800">{activeHealth.metrics?.php_version ?? '8.4'}</span>
          </div>
        </div>

        <div className="p-3 rounded-xl bg-slate-100/60 flex items-center gap-2.5 transition-all">
          <Activity className="w-4 h-4 text-emerald-500 shrink-0" />
          <div>
            <span className="text-[10px] uppercase font-bold text-slate-400 block">Bộ nhớ RAM PHP</span>
            <span className="font-mono font-bold text-neutral-800">{activeHealth.metrics?.memory_usage_mb ?? 0} MB</span>
          </div>
        </div>

        <div className="p-3 rounded-xl bg-slate-100/60 flex items-center gap-2.5 transition-all">
          <Database className="w-4 h-4 text-amber-500 shrink-0" />
          <div>
            <span className="text-[10px] uppercase font-bold text-slate-400 block">MySQL Latency</span>
            <span className="font-mono font-bold text-neutral-800">{activeHealth.metrics?.db_ping_ms ?? 0} ms</span>
          </div>
        </div>

        <div className="p-3 rounded-xl bg-slate-100/60 flex items-center gap-2.5 transition-all">
          <HardDrive className="w-4 h-4 text-indigo-500 shrink-0" />
          <div>
            <span className="text-[10px] uppercase font-bold text-slate-400 block">Dung lượng ổ đĩa</span>
            <span className="font-mono font-bold text-neutral-800">{activeHealth.metrics?.disk_usage_percent ?? 0}% used</span>
          </div>
        </div>
      </div>
    </div>
  );
};
