import React from 'react';
import {
  Server,
  ShieldCheck,
  RotateCcw,
  ArrowUpRight,
  GitCommit,
  CheckCircle2,
  Clock,
  Sparkles,
  Play,
  Layers,
} from 'lucide-react';
import { DeploymentItem } from '../../Services/cicdApi';

interface DeploymentOverviewProps {
  deployments: DeploymentItem[];
  onTriggerDeploy: (env: string) => void;
  onTriggerRollback: (env: string) => void;
  canDeploy?: boolean;
}

export const DeploymentOverview: React.FC<DeploymentOverviewProps> = ({
  deployments,
  onTriggerDeploy,
  onTriggerRollback,
  canDeploy = true,
}) => {
  const prod = deployments.find((d) => d.environment === 'production');
  const staging = deployments.find((d) => d.environment === 'staging');

  const formatTime = (dateStr: string) => {
    try {
      const d = new Date(dateStr);
      return d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) + ' ' + d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
    } catch {
      return dateStr;
    }
  };

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
      {/* Production Card */}
      <div className="rounded-2xl bg-white/85 backdrop-blur-md p-5 sm:p-6 border border-slate-200/80 shadow-xs relative overflow-hidden flex flex-col justify-between">
        <div>
          <div className="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
            <div className="flex items-center gap-2.5">
              <div className="p-2 rounded-xl bg-amber-100 text-amber-800">
                <ShieldCheck className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-sm font-bold text-neutral-900">Production Environment</h3>
                <span className="text-[11px] text-slate-400 font-mono">Máy chủ vận hành cư dân thực tế</span>
              </div>
            </div>

            {prod?.status === 'healthy' ? (
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                <span>Healthy</span>
              </span>
            ) : (
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                <span className="w-2 h-2 rounded-full bg-slate-400" />
                <span>Chưa triển khai</span>
              </span>
            )}
          </div>

          <div className="space-y-3 text-xs">
            <div className="flex items-center justify-between">
              <span className="text-slate-400 font-medium">Phiên bản Release:</span>
              <span className="font-mono font-black text-sm text-neutral-900 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                {prod?.version || 'Chưa triển khai'}
              </span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-slate-400 font-medium">Commit SHA:</span>
              <span className="inline-flex items-center gap-1 font-mono font-bold text-neutral-800 bg-neutral-100 px-2 py-0.5 rounded">
                <GitCommit className="w-3.5 h-3.5 text-slate-400" />
                {prod?.commit_sha ? prod.commit_sha.slice(0, 7) : 'Chưa có'}
              </span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-slate-400 font-medium">Thời gian triển khai:</span>
              <span className="text-slate-600 font-medium">
                {prod?.deployed_at && prod.status !== 'not_deployed' ? formatTime(prod.deployed_at) : 'Chưa kích hoạt'}
              </span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-slate-400 font-medium">Thời gian đáp ứng (Latency):</span>
              <span className="text-neutral-700 font-mono font-bold bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                {prod?.status === 'healthy' ? `${prod.response_time_ms} ms` : '--'}
              </span>
            </div>
          </div>
        </div>

        {/* Buttons */}
        <div className="mt-5 pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
          {canDeploy && (
            <button
              type="button"
              onClick={() => onTriggerRollback('production')}
              className="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200/80 text-neutral-700 text-xs font-bold transition-colors cursor-pointer"
              title="Phục hồi phiên bản trước"
            >
              <RotateCcw className="w-3.5 h-3.5 text-slate-500" />
              <span>Rollback</span>
            </button>
          )}

          {canDeploy && (
            <button
              type="button"
              onClick={() => onTriggerDeploy('production')}
              className="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white text-xs font-bold transition-all shadow-xs active:scale-95 cursor-pointer"
            >
              <Play className="w-3.5 h-3.5 text-emerald-400" />
              <span>Deploy Mới</span>
            </button>
          )}
        </div>
      </div>

      {/* Staging Card */}
      <div className="rounded-2xl bg-white/85 backdrop-blur-md p-5 sm:p-6 border border-slate-200/80 shadow-xs relative overflow-hidden flex flex-col justify-between">
        <div>
          <div className="flex items-center justify-between pb-3 mb-4 border-b border-slate-100">
            <div className="flex items-center gap-2.5">
              <div className="p-2 rounded-xl bg-indigo-100 text-indigo-800">
                <Server className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-sm font-bold text-neutral-900">Staging Environment</h3>
                <span className="text-[11px] text-slate-400 font-mono">Môi trường thử nghiệm tính năng</span>
              </div>
            </div>

            {staging?.status === 'healthy' ? (
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span className="w-2 h-2 rounded-full bg-emerald-500" />
                <span>Healthy</span>
              </span>
            ) : (
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                <span className="w-2 h-2 rounded-full bg-slate-400" />
                <span>Chưa triển khai</span>
              </span>
            )}
          </div>

          <div className="space-y-3 text-xs">
            <div className="flex items-center justify-between">
              <span className="text-slate-400 font-medium">Phiên bản Image:</span>
              <span className="font-mono font-bold text-xs text-neutral-900 bg-slate-100 px-2 py-0.5 rounded border border-slate-200 truncate max-w-[200px]">
                {staging?.version || 'Chưa triển khai'}
              </span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-slate-400 font-medium">Commit SHA:</span>
              <span className="inline-flex items-center gap-1 font-mono font-bold text-neutral-800 bg-neutral-100 px-2 py-0.5 rounded">
                <GitCommit className="w-3.5 h-3.5 text-slate-400" />
                {staging?.commit_sha ? staging.commit_sha.slice(0, 7) : 'Chưa có'}
              </span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-slate-400 font-medium">Thời gian cập nhật:</span>
              <span className="text-slate-600 font-medium">
                {staging?.deployed_at && staging.status !== 'not_deployed' ? formatTime(staging.deployed_at) : 'Chưa kích hoạt'}
              </span>
            </div>

            <div className="flex items-center justify-between">
              <span className="text-slate-400 font-medium">Thời gian đáp ứng:</span>
              <span className="text-neutral-700 font-mono font-bold bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                {staging?.status === 'healthy' ? `${staging.response_time_ms} ms` : '--'}
              </span>
            </div>
          </div>
        </div>

        {/* Buttons */}
        <div className="mt-5 pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
          {canDeploy && (
            <button
              type="button"
              onClick={() => onTriggerDeploy('staging')}
              className="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition-all shadow-xs active:scale-95 cursor-pointer"
            >
              <Sparkles className="w-3.5 h-3.5" />
              <span>Deploy Staging</span>
            </button>
          )}
        </div>
      </div>
    </div>
  );
};
