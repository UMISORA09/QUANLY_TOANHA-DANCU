import React, { useState } from 'react';
import {
  Clock,
  Database,
  Activity,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  RefreshCw,
  GitBranch,
  Server,
  Layers,
  ShieldAlert,
  Info,
  ExternalLink,
  Flame,
} from 'lucide-react';
import { FreshnessOverviewData, FreshnessSourceItem, FreshnessState } from '@/Services/cicdApi';

interface FreshnessObservabilityViewProps {
  freshness: FreshnessOverviewData | null;
  isLoading?: boolean;
  onRefresh?: () => void;
}

export const FreshnessObservabilityView: React.FC<FreshnessObservabilityViewProps> = ({
  freshness,
  isLoading = false,
  onRefresh,
}) => {
  const [filterType, setFilterType] = useState<'all' | 'monitoring' | 'data'>('all');

  const formatSeconds = (sec: number | null | undefined): string => {
    if (sec === null || sec === undefined) return 'N/A';
    if (sec < 60) return `${sec}s`;
    if (sec < 3600) return `${Math.floor(sec / 60)}m ${sec % 60}s`;
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    return `${h}h ${m}m`;
  };

  const formatTimestamp = (ts: string | null | undefined): string => {
    if (!ts) return 'Chưa có bản ghi (No record)';
    try {
      const d = new Date(ts);
      return d.toLocaleString('vi-VN', {
        timeZone: 'Asia/Ho_Chi_Minh',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      }) + ' (GMT+7)';
    } catch {
      return ts;
    }
  };

  const getStatusBadge = (status: FreshnessState) => {
    switch (status) {
      case 'FRESH':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
            <CheckCircle2 className="w-3 h-3 text-emerald-600" />
            FRESH
          </span>
        );
      case 'STALE':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-300">
            <AlertTriangle className="w-3 h-3 text-amber-600" />
            STALE
          </span>
        );
      case 'CRITICAL':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800 border border-rose-300">
            <XCircle className="w-3 h-3 text-rose-600" />
            CRITICAL
          </span>
        );
      case 'UNAVAILABLE':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-purple-100 text-purple-800 border border-purple-300">
            <ShieldAlert className="w-3 h-3 text-purple-600" />
            UNAVAILABLE
          </span>
        );
      case 'UNKNOWN':
      default:
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-300">
            <Info className="w-3 h-3 text-slate-500" />
            UNKNOWN
          </span>
        );
    }
  };

  if (!freshness) {
    return (
      <div className="p-8 rounded-2xl bg-white border border-slate-200 text-center space-y-3">
        <Clock className="w-10 h-10 text-slate-400 mx-auto animate-pulse" />
        <h3 className="text-base font-bold text-slate-800">Đang khởi tạo Freshness Observability...</h3>
        <p className="text-xs text-slate-500 max-w-md mx-auto">
          Thu thập độ tươi mới của CSDL tòa nhà và các luồng quan sát monitoring thực tế.
        </p>
      </div>
    );
  }

  const monitoringSources: FreshnessSourceItem[] = [
    {
      source: 'collector',
      name: 'Freshness Collector (Scheduler)',
      type: 'monitoring',
      status: freshness.collector.status,
      age_seconds: freshness.collector.age_seconds,
      warning_threshold: freshness.collector.warning_threshold,
      critical_threshold: freshness.collector.critical_threshold,
      last_observed_at: freshness.collector.last_success_at,
      source_timestamp: freshness.collector.source_timestamp || freshness.collector.last_success_at,
      reason: freshness.collector.reason,
    },
    freshness.github_actions,
    freshness.deployment,
    freshness.application_health,
  ];

  const dbSources: FreshnessSourceItem[] = freshness.database?.sources || [];
  const worstSource = freshness.worst_source || freshness.metrics?.worst_source;

  return (
    <div className="space-y-6">
      {/* BANNER TỔNG QUAN FRESHNESS */}
      <div className="p-5 rounded-2xl bg-gradient-to-r from-slate-900 via-neutral-900 to-slate-950 text-white shadow-md border border-slate-800">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <Clock className="w-5 h-5 text-sky-400" />
              <h2 className="text-lg font-extrabold tracking-tight">Data Freshness & Observability Monitor</h2>
              <span className="text-slate-500">&middot;</span>
              {getStatusBadge(freshness.overall_state)}
            </div>
            <p className="text-xs text-slate-300">
              Công thức chuẩn: <code className="px-1.5 py-0.5 bg-slate-800 rounded font-mono text-amber-300">freshness_age = current_time - source_timestamp</code>
            </p>
            {freshness.overall_reason && (
              <div className="text-xs text-sky-200 mt-1 flex items-center gap-1.5 font-medium">
                <Info className="w-3.5 h-3.5 text-sky-400 shrink-0" />
                <span>{freshness.overall_reason}</span>
              </div>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <div className="px-3 py-1.5 rounded-xl bg-slate-800/80 border border-slate-700/80 text-[11px] font-mono text-slate-300">
              <span className="text-slate-400">Thu thập lúc: </span>
              <span className="font-bold text-white">{formatTimestamp(freshness.checked_at)}</span>
            </div>

            {onRefresh && (
              <button
                type="button"
                onClick={onRefresh}
                disabled={isLoading}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold transition-colors cursor-pointer"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin' : ''}`} />
                <span>Kiểm tra lại</span>
              </button>
            )}
          </div>
        </div>

        {/* 4 THẺ CHỈ SỐ BẮT BUỘC: Newest Data Age, Oldest Data Age, Worst Source, Overall Freshness */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-4 border-t border-slate-800">
          <div className="p-3 rounded-xl bg-slate-800/50 border border-slate-700/60">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Newest Data Age</span>
            <div className="text-lg font-black text-white mt-0.5">
              {formatSeconds(freshness.newest_data_age_seconds ?? freshness.database?.newest_data_age_seconds ?? freshness.database?.age_seconds)}
            </div>
            <div className="text-[10px] text-slate-400">Tuổi bản ghi mới nhất trong CSDL</div>
          </div>

          <div className="p-3 rounded-xl bg-slate-800/50 border border-slate-700/60">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Oldest Data Age</span>
            <div className="text-lg font-black text-white mt-0.5">
              {formatSeconds(freshness.oldest_data_age_seconds ?? freshness.database?.oldest_data_age_seconds)}
            </div>
            <div className="text-[10px] text-slate-400">Tuổi bản ghi cũ nhất các bảng</div>
          </div>

          <div className="p-3 rounded-xl bg-slate-800/50 border border-slate-700/60">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Worst Source</span>
            <div className="text-sm font-black text-amber-300 mt-1 truncate">
              {worstSource?.name || worstSource?.source || 'Không xác định'}
            </div>
            <div className="text-[10px] text-slate-400 mt-0.5">
              Trạng thái: <span className="font-bold text-white">{worstSource?.status || 'UNKNOWN'}</span>
            </div>
          </div>

          <div className="p-3 rounded-xl bg-slate-800/50 border border-slate-700/60">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Overall Freshness</span>
            <div className="mt-1">
              {getStatusBadge(freshness.overall_state)}
            </div>
            <div className="text-[10px] text-slate-400 mt-1 truncate" title={freshness.overall_reason}>
              {freshness.overall_reason || 'Độ tươi mới hệ thống'}
            </div>
          </div>
        </div>
      </div>

      {/* FILTER BUTTONS */}
      <div className="flex items-center gap-2 border-b border-slate-200 pb-2">
        <button
          type="button"
          onClick={() => setFilterType('all')}
          className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-colors cursor-pointer ${
            filterType === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          }`}
        >
          Tất cả nguồn ({monitoringSources.length + dbSources.length})
        </button>
        <button
          type="button"
          onClick={() => setFilterType('monitoring')}
          className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-colors cursor-pointer ${
            filterType === 'monitoring' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          }`}
        >
          <Activity className="w-3 h-3" />
          <span>Monitoring Freshness ({monitoringSources.length})</span>
        </button>
        <button
          type="button"
          onClick={() => setFilterType('data')}
          className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-colors cursor-pointer ${
            filterType === 'data' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
          }`}
        >
          <Database className="w-3 h-3" />
          <span>Business Data Freshness ({dbSources.length})</span>
        </button>
      </div>

      {/* SECTION 1: MONITORING FRESHNESS */}
      {(filterType === 'all' || filterType === 'monitoring') && (
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold uppercase tracking-wider text-slate-600 font-mono flex items-center gap-2">
              <Activity className="w-4 h-4 text-sky-600" />
              <span>Monitoring Signals Freshness (Độ tươi mới của tín hiệu giám sát)</span>
            </h3>
            <span className="text-[11px] text-slate-600">Đo lường thời điểm collector & probe thu thập</span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {monitoringSources.map((item) => {
              const sourceTime = item.source_timestamp || item.last_update_at || item.last_event_at || item.last_observed_at;
              return (
                <div
                  key={item.source}
                  className="p-4 rounded-xl bg-white border border-slate-200 shadow-2xs hover:border-slate-300 transition-colors space-y-3"
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <h4 className="text-sm font-bold text-neutral-900">{item.name || item.source}</h4>
                      <span className="text-[11px] font-mono text-slate-600">Nguồn: {item.source} (Loại: {item.type})</span>
                    </div>
                    {getStatusBadge(item.status)}
                  </div>

                  <div className="grid grid-cols-2 gap-2 text-xs bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                    <div>
                      <span className="text-slate-600 block text-[10px]">Thời điểm nguồn (Source time):</span>
                      <span className="font-mono font-medium text-neutral-800">{formatTimestamp(sourceTime)}</span>
                    </div>
                    <div>
                      <span className="text-slate-600 block text-[10px]">Độ trễ hiện tại (Age):</span>
                      <span className="font-mono font-bold text-neutral-900 text-sm">
                        {formatSeconds(item.age_seconds)}
                      </span>
                    </div>
                  </div>

                  <div className="flex items-center justify-between text-[11px] text-slate-600 pt-1 border-t border-slate-100">
                    <span>Ngưỡng Warning: &lt;{formatSeconds(item.warning_threshold)}</span>
                    <span>Ngưỡng Critical: &gt;{formatSeconds(item.critical_threshold)}</span>
                  </div>

                  {/* BẰNG CHỨNG QUAN SÁT THỰC TẾ (PROVENANCE & EVIDENCE) */}
                  {item.reason && (
                    <div className="text-[11px] text-slate-700 bg-sky-50/60 p-2 rounded border border-sky-100">
                      <span className="font-bold text-sky-800">Lý do: </span>
                      {item.reason}
                    </div>
                  )}

                  {item.workflow && (
                    <div className="text-[11px] text-slate-600 font-mono bg-slate-50 p-2 rounded border border-slate-200">
                      Workflow: <span className="font-bold text-slate-800">{item.workflow}</span>
                      {item.commit_sha && <span> &middot; Commit: {item.commit_sha}</span>}
                      {item.conclusion && <span> &middot; Conclusion: {item.conclusion}</span>}
                    </div>
                  )}

                  {item.environment && (
                    <div className="text-[11px] text-slate-600 font-mono bg-slate-50 p-2 rounded border border-slate-200">
                      Environment: <span className="font-bold text-slate-800">{item.environment}</span>
                      {item.version && <span> &middot; Phiên bản: {item.version}</span>}
                    </div>
                  )}

                  {item.message && !item.reason && (
                    <div className="text-[11px] text-slate-600 italic bg-amber-50/50 p-2 rounded border border-amber-100">
                      {item.message}
                    </div>
                  )}
                  {item.error && (
                    <div className="text-[11px] text-rose-600 bg-rose-50 p-2 rounded border border-rose-200 font-mono">
                      Lỗi: {item.error}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* SECTION 2: BUSINESS DATA FRESHNESS */}
      {(filterType === 'all' || filterType === 'data') && (
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold uppercase tracking-wider text-slate-600 font-mono flex items-center gap-2">
              <Database className="w-4 h-4 text-emerald-600" />
              <span>Business Data Freshness (Độ tươi mới của dữ liệu nghiệp vụ tòa nhà)</span>
            </h3>
            <span className="text-[11px] text-slate-600">Truy vấn MAX(updated_at / created_at) thực tế từ CSDL</span>
          </div>

          {freshness.database?.status === 'UNAVAILABLE' ? (
            <div className="p-4 rounded-xl bg-purple-50 border border-purple-200 text-purple-900 text-xs font-medium space-y-1">
              <div className="flex items-center gap-2 font-bold">
                <ShieldAlert className="w-4 h-4 text-purple-600" />
                <span>CSDL MySQL Hiện Đang Mất Kết Nối (DATABASE UNAVAILABLE)</span>
              </div>
              <p className="text-purple-700">
                Không thể truy vấn timestamp các bảng nghiệp vụ. Trạng thái được bảo toàn trung thực là UNAVAILABLE thay vì hiển thị dữ liệu giả mạo.
              </p>
            </div>
          ) : null}

          <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-2xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase text-[10px] tracking-wider font-mono">
                  <tr>
                    <th className="px-4 py-3">Bảng nghiệp vụ</th>
                    <th className="px-4 py-3">Trường thời gian</th>
                    <th className="px-4 py-3">Cập nhật gần nhất (Source Timestamp)</th>
                    <th className="px-4 py-3">Độ tuổi dữ liệu (Age)</th>
                    <th className="px-4 py-3">Ngưỡng cảnh báo</th>
                    <th className="px-4 py-3">Trạng thái</th>
                    <th className="px-4 py-3">Bằng chứng & Lý do</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {dbSources.map((src) => (
                    <tr key={src.source} className="hover:bg-slate-50/80 transition-colors">
                      <td className="px-4 py-3 font-medium text-neutral-900">
                        <div className="flex items-center gap-2">
                          <Layers className="w-3.5 h-3.5 text-slate-400" />
                          <div>
                            <div className="font-bold">{src.name || src.source}</div>
                            <code className="text-[10px] text-slate-400 font-mono">{src.table}</code>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3 font-mono text-[11px] text-slate-600">
                        {src.timestamp_field || 'updated_at'}
                      </td>
                      <td className="px-4 py-3 font-mono text-[11px] text-slate-700">
                        {formatTimestamp(src.source_timestamp || src.last_update_at)}
                      </td>
                      <td className="px-4 py-3 font-mono font-bold text-slate-900">
                        {formatSeconds(src.age_seconds)}
                      </td>
                      <td className="px-4 py-3 text-[11px] text-slate-500 font-mono">
                        Warn: {formatSeconds(src.warning_threshold)} | Crit: {formatSeconds(src.critical_threshold)}
                      </td>
                      <td className="px-4 py-3">
                        {getStatusBadge(src.status)}
                      </td>
                      <td className="px-4 py-3 text-[11px] text-slate-600 max-w-xs truncate" title={src.reason || src.message || src.error}>
                        {src.reason || src.message || src.error || 'N/A'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* SECTION 3: DURABLE INCIDENTS HISTORY */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-xs font-bold uppercase tracking-wider text-slate-600 font-mono flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 text-amber-600" />
            <span>Nhật Ký Sự Cố Freshness Bền Vững (Durable Incident Tracker)</span>
          </h3>
          <span className="text-[11px] text-slate-600">Lưu vết chuyển đổi NORMAL &rarr; STALE &rarr; CRITICAL &rarr; RECOVERED (DB Single Source of Truth)</span>
        </div>

        {freshness.incidents && freshness.incidents.length > 0 ? (
          <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-2xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase text-[10px] tracking-wider font-mono">
                  <tr>
                    <th className="px-4 py-2.5">Thời điểm phát hiện</th>
                    <th className="px-4 py-2.5">Nguồn</th>
                    <th className="px-4 py-2.5">Trạng thái sự cố</th>
                    <th className="px-4 py-2.5">Độ trễ tại thời điểm</th>
                    <th className="px-4 py-2.5">Thời điểm giải quyết</th>
                    <th className="px-4 py-2.5">Chi tiết</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {freshness.incidents.map((inc, i) => (
                    <tr key={inc.id || i} className="hover:bg-slate-50/80 transition-colors">
                      <td className="px-4 py-2.5 font-mono text-[11px] text-slate-700">
                        {formatTimestamp(inc.detected_at)}
                      </td>
                      <td className="px-4 py-2.5 font-bold text-neutral-800">
                        {inc.source}
                      </td>
                      <td className="px-4 py-2.5">
                        {getStatusBadge(inc.state as FreshnessState)}
                      </td>
                      <td className="px-4 py-2.5 font-mono text-slate-700">
                        {formatSeconds(inc.age_seconds)}
                      </td>
                      <td className="px-4 py-2.5 font-mono text-[11px] text-slate-600">
                        {inc.resolved_at ? formatTimestamp(inc.resolved_at) : (
                          <span className="inline-flex items-center gap-1 font-bold text-rose-600">
                            <Flame className="w-3 h-3 text-rose-500 animate-pulse" />
                            Đang active
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-slate-600 text-[11px]">
                        {inc.details || 'N/A'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        ) : (
          <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 text-center text-xs text-slate-500">
            Không có sự cố độ trễ dữ liệu nào được ghi nhận. Hệ thống giám sát hoạt động ổn định.
          </div>
        )}
      </div>
    </div>
  );
};
