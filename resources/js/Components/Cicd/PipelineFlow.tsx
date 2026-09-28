import React from 'react';
import {
  GitCommit,
  ArrowRight,
  CheckCircle2,
  XCircle,
  Clock,
  Loader2,
  Box,
  Terminal,
  ShieldCheck,
  Server,
  HeartPulse,
  Wrench,
  Layers,
  Sparkles,
} from 'lucide-react';
import { PipelineItem, PipelineStatus } from '../../Services/cicdApi';

interface PipelineFlowProps {
  pipeline: PipelineItem | null;
  onSelect?: () => void;
}

interface FlowStage {
  id: string;
  name: string;
  icon: React.ComponentType<{ className?: string }>;
  status: PipelineStatus;
  duration?: string;
  detail?: string;
}

export const PipelineFlow: React.FC<PipelineFlowProps> = ({ pipeline, onSelect }) => {
  if (!pipeline) {
    return (
      <div className="rounded-2xl bg-white/80 backdrop-blur-md p-6 border border-slate-200/80 shadow-xs text-center text-slate-400">
        Chưa có pipeline nào được thực thi.
      </div>
    );
  }

  const isFailed = pipeline.status === 'failed';
  const isRunning = pipeline.status === 'running';

  const stages: FlowStage[] = [
    {
      id: 'commit',
      name: 'Commit',
      icon: GitCommit,
      status: 'success',
      duration: '0s',
      detail: pipeline.commit_sha,
    },
    {
      id: 'checkout',
      name: 'Checkout',
      icon: Layers,
      status: 'success',
      duration: '3s',
      detail: 'v4',
    },
    {
      id: 'install',
      name: 'Install Deps',
      icon: Box,
      status: 'success',
      duration: '18s',
      detail: 'Composer & npm',
    },
    {
      id: 'lint',
      name: 'Code Lint',
      icon: Terminal,
      status: 'success',
      duration: '8s',
      detail: 'Pint 0 issues',
    },
    {
      id: 'test',
      name: 'Unit & Feature',
      icon: ShieldCheck,
      status: isFailed ? 'failed' : 'success',
      duration: '30s',
      detail: isFailed ? '1 test failed' : '26/26 passed',
    },
    {
      id: 'build',
      name: 'Vite Build',
      icon: Sparkles,
      status: isFailed ? 'skipped' : 'success',
      duration: '4s',
      detail: 'React 19 bundle',
    },
    {
      id: 'docker',
      name: 'Docker & Smoke',
      icon: Box,
      status: isFailed ? 'skipped' : isRunning ? 'running' : 'success',
      duration: isRunning ? 'running...' : '54s',
      detail: 'Multi-stage prod',
    },
    {
      id: 'deploy',
      name: 'CD Deploy',
      icon: Server,
      status: isFailed ? 'skipped' : isRunning ? 'queued' : 'success',
      duration: '14s',
      detail: 'GHCR image pull',
    },
    {
      id: 'health',
      name: 'Health Check',
      icon: HeartPulse,
      status: isFailed ? 'skipped' : isRunning ? 'queued' : 'success',
      duration: '2s',
      detail: 'HTTP 200 OK',
    },
  ];

  const getStatusBadge = (status: PipelineStatus) => {
    switch (status) {
      case 'success':
        return {
          icon: CheckCircle2,
          color: 'text-emerald-500 bg-emerald-50 border-emerald-200',
          label: 'Success',
        };
      case 'failed':
        return {
          icon: XCircle,
          color: 'text-rose-500 bg-rose-50 border-rose-200',
          label: 'Failed',
        };
      case 'running':
        return {
          icon: Loader2,
          color: 'text-sky-500 bg-sky-50 border-sky-200 animate-spin',
          label: 'Running',
        };
      case 'skipped':
        return {
          icon: Clock,
          color: 'text-slate-300 bg-slate-50 border-slate-200',
          label: 'Skipped',
        };
      case 'queued':
      default:
        return {
          icon: Clock,
          color: 'text-amber-500 bg-amber-50 border-amber-200',
          label: 'Waiting',
        };
    }
  };

  return (
    <div className="rounded-2xl bg-white/85 backdrop-blur-md p-5 sm:p-6 border border-slate-200/80 shadow-xs relative overflow-hidden">
      {/* Header Info */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-100">
        <div className="flex items-center gap-3">
          <div className="p-2.5 rounded-2xl bg-slate-900 text-white shadow-xs">
            <GitCommit className="w-5 h-5 text-emerald-400" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="font-mono text-xs font-extrabold text-neutral-900 px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200">
                #{pipeline.run_number}
              </span>
              <h3 className="text-sm sm:text-base font-bold text-neutral-900">
                {pipeline.name}
              </h3>
              <span
                className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${
                  pipeline.status === 'success'
                    ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                    : pipeline.status === 'failed'
                    ? 'bg-rose-50 text-rose-700 border-rose-200'
                    : 'bg-sky-50 text-sky-700 border-sky-200 animate-pulse'
                }`}
              >
                {pipeline.status}
              </span>
            </div>
            <p className="text-xs text-slate-500 mt-1 line-clamp-1">
              {pipeline.commit_message} &middot; <span className="font-mono text-neutral-700 font-semibold">{pipeline.branch}</span> ({pipeline.commit_sha})
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 text-xs text-slate-500 self-end sm:self-center">
          <Clock className="w-3.5 h-3.5" />
          <span>Thời gian: <strong className="text-neutral-800 font-mono">{pipeline.duration}</strong></span>
          {onSelect && (
            <button
              type="button"
              onClick={onSelect}
              className="ml-2 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-neutral-800 text-xs font-semibold transition-colors cursor-pointer"
            >
              Chi tiết log
            </button>
          )}
        </div>
      </div>

      {/* Visual Pipeline Flow */}
      <div className="relative overflow-x-auto pb-2 scrollbar-thin">
        <div className="flex items-center min-w-[850px] justify-between gap-1 sm:gap-2">
          {stages.map((stage, idx) => {
            const badge = getStatusBadge(stage.status);
            const StageIcon = stage.icon;
            const StatusIcon = badge.icon;
            const isLast = idx === stages.length - 1;

            return (
              <React.Fragment key={stage.id}>
                {/* Stage Card */}
                <div
                  className={`flex flex-col items-center justify-center p-3 rounded-2xl border transition-all duration-200 min-w-[86px] text-center group cursor-pointer hover:shadow-sm ${
                    stage.status === 'running'
                      ? 'bg-sky-50/80 border-sky-300 ring-2 ring-sky-100 shadow-sm'
                      : stage.status === 'failed'
                      ? 'bg-rose-50/60 border-rose-200'
                      : stage.status === 'skipped'
                      ? 'bg-slate-50/60 border-slate-200 opacity-60'
                      : 'bg-white hover:bg-slate-50 border-slate-200/80'
                  }`}
                  onClick={onSelect}
                >
                  <div className="relative mb-2">
                    <div
                      className={`w-9 h-9 rounded-xl flex items-center justify-center border shadow-2xs transition-transform group-hover:scale-105 ${badge.color}`}
                    >
                      <StageIcon className="w-4 h-4" />
                    </div>
                    <div className="absolute -bottom-1 -right-1">
                      <StatusIcon
                        className={`w-3.5 h-3.5 rounded-full bg-white ${
                          stage.status === 'running' ? 'animate-spin text-sky-500' : ''
                        }`}
                      />
                    </div>
                  </div>

                  <span className="text-[11px] font-bold text-neutral-800 leading-tight">
                    {stage.name}
                  </span>

                  <span className="text-[10px] text-slate-400 font-mono mt-1">
                    {stage.duration || '--'}
                  </span>

                  {stage.detail && (
                    <span className="text-[9px] text-slate-500 font-medium truncate max-w-[80px] mt-0.5">
                      {stage.detail}
                    </span>
                  )}
                </div>

                {/* Connecting Arrow */}
                {!isLast && (
                  <div className="shrink-0 text-slate-300 px-0.5">
                    <ArrowRight className="w-3.5 h-3.5" />
                  </div>
                )}
              </React.Fragment>
            );
          })}
        </div>
      </div>
    </div>
  );
};
