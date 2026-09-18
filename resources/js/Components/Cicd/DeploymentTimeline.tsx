import React from 'react';
import {
  Tag,
  CheckCircle2,
  GitCommit,
  User,
  Clock,
  ExternalLink,
  ChevronDown,
  Layers,
} from 'lucide-react';

interface ReleaseItem {
  version: string;
  commitSha: string;
  author: string;
  environment: 'production' | 'staging';
  time: string;
  notes: string;
  stages: Array<{ name: string; status: 'success' | 'failed' | 'running' }>;
}

export const DeploymentTimeline: React.FC = () => {
  const releases: ReleaseItem[] = [
    {
      version: 'v1.0.0',
      commitSha: '61bc9af',
      author: 'DangNguyen',
      environment: 'production',
      time: '2 giờ trước',
      notes: 'Bản phát hành chính thức hệ thống Quản lý tiện ích & CI/CD tự động',
      stages: [
        { name: 'Build', status: 'success' },
        { name: 'Test (26 tests)', status: 'success' },
        { name: 'Docker Multi-stage', status: 'success' },
        { name: 'Staging Verify', status: 'success' },
        { name: 'Production Live', status: 'success' },
      ],
    },
    {
      version: 'v0.9.5-rc1',
      commitSha: '58699bc',
      author: 'Ma',
      environment: 'staging',
      time: '1 ngày trước',
      notes: 'Khởi chạy thử nghiệm dashboard tab, chuẩn hóa CSDL và mã hóa UTF-8',
      stages: [
        { name: 'Build', status: 'success' },
        { name: 'Test', status: 'success' },
        { name: 'Docker Multi-stage', status: 'success' },
        { name: 'Staging Verify', status: 'success' },
      ],
    },
    {
      version: 'v0.9.0-beta',
      commitSha: '4dd81c9',
      author: 'DangNguyen',
      environment: 'staging',
      time: '2 ngày trước',
      notes: 'Hoàn thiện port module tiện ích từ FastAPI sang Laravel 13',
      stages: [
        { name: 'Build', status: 'success' },
        { name: 'Test', status: 'success' },
        { name: 'Docker Multi-stage', status: 'success' },
      ],
    },
  ];

  return (
    <div className="rounded-2xl bg-white/85 backdrop-blur-md p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
      <div className="flex items-center justify-between pb-3 border-b border-slate-100">
        <div className="flex items-center gap-2">
          <Tag className="w-4 h-4 text-sky-600" />
          <h3 className="text-sm font-bold text-neutral-900">Deployment & Release Timeline</h3>
        </div>
        <span className="text-xs text-slate-400 font-mono">3 bản phát hành gần nhất</span>
      </div>

      <div className="relative pl-6 space-y-6 before:absolute before:left-2.5 before:top-2 before:bottom-2 before:w-0.5 before:bg-slate-200">
        {releases.map((rel, idx) => (
          <div key={idx} className="relative group">
            {/* Timeline Dot */}
            <div className="absolute -left-[27px] top-1 w-4 h-4 rounded-full bg-white border-2 border-sky-500 ring-4 ring-sky-50 group-hover:scale-110 transition-transform" />

            <div className="p-4 rounded-2xl bg-slate-50/70 hover:bg-slate-50 border border-slate-200/80 transition-all">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
                <div className="flex items-center gap-2">
                  <span className="font-mono font-black text-xs text-white bg-slate-900 px-2 py-0.5 rounded shadow-xs">
                    {rel.version}
                  </span>
                  <span
                    className={`text-[10px] font-bold px-2 py-0.5 rounded uppercase ${
                      rel.environment === 'production'
                        ? 'bg-amber-100 text-amber-800'
                        : 'bg-indigo-100 text-indigo-800'
                    }`}
                  >
                    {rel.environment}
                  </span>
                  <span className="text-xs text-neutral-700 font-semibold truncate">
                    {rel.notes}
                  </span>
                </div>

                <div className="flex items-center gap-2 text-xs text-slate-400">
                  <Clock className="w-3 h-3" />
                  <span>{rel.time}</span>
                </div>
              </div>

              {/* Commit & Author */}
              <div className="flex flex-wrap items-center gap-3 text-xs text-slate-500 mb-3">
                <span className="inline-flex items-center gap-1 font-mono font-bold bg-white px-2 py-0.5 rounded border border-slate-200 text-neutral-800">
                  <GitCommit className="w-3 h-3 text-slate-400" />
                  {rel.commitSha}
                </span>
                <span className="inline-flex items-center gap-1 font-medium text-neutral-700">
                  <User className="w-3 h-3 text-slate-400" />
                  {rel.author}
                </span>
              </div>

              {/* Stages List */}
              <div className="flex flex-wrap items-center gap-1.5 pt-2 border-t border-slate-200/60 text-[11px] font-mono">
                {rel.stages.map((stg, sIdx) => (
                  <span
                    key={sIdx}
                    className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-white border border-slate-200 text-slate-700 font-semibold"
                  >
                    <CheckCircle2 className="w-3 h-3 text-emerald-500" />
                    <span>{stg.name}</span>
                  </span>
                ))}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};
