import React from 'react';
import { Tag, GitCommit } from 'lucide-react';
import type { DeploymentItem } from '../../Services/cicdApi';

export const DeploymentTimeline: React.FC<{ deployments: DeploymentItem[] }> = ({ deployments }) => {
  const releases = deployments.filter(deployment => deployment.deployed_at);

  return (
    <div className="rounded-2xl bg-white/85 p-5 sm:p-6 border border-slate-200/80 shadow-xs space-y-4">
      <h3 className="flex items-center gap-2 text-sm font-bold text-neutral-900"><Tag className="w-4 h-4 text-sky-600" />Lịch sử triển khai</h3>
      {releases.length === 0 && <p className="text-xs text-slate-500">Chưa có bản triển khai hoàn tất được xác nhận từ máy chủ.</p>}
      {releases.map(release => (
        <div key={release.id} className="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-xs">
          <div className="flex flex-wrap justify-between gap-2">
            <span className="font-bold text-neutral-900">{release.version} · {release.environment}</span>
            <time dateTime={release.deployed_at!} className="text-slate-500">{new Date(release.deployed_at!).toLocaleString('vi-VN')}</time>
          </div>
          <p className="flex items-center gap-2 text-slate-600"><GitCommit className="w-3.5 h-3.5" />{release.commit_sha} · {release.deployed_by}</p>
          <p className="text-slate-500">{release.release_notes}</p>
        </div>
      ))}
    </div>
  );
};
