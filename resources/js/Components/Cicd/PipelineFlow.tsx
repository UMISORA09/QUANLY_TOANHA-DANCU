import React, { useEffect, useState } from 'react';
import { CheckCircle2, XCircle, Clock, Loader2, Terminal, GitCommit } from 'lucide-react';
import { cicdApi, JobItem, PipelineItem, PipelineStatus } from '../../Services/cicdApi';

interface PipelineFlowProps {
  pipeline: PipelineItem | null;
  onSelect?: (jobId?: string, stepNumber?: number) => void;
}
const statusLabels: Record<PipelineStatus, string> = {
  success: 'Success', failed: 'Failed', running: 'Running',
  queued: 'Queued', skipped: 'Skipped', cancelled: 'Cancelled',
};
function StatusIcon({ status }: { status: PipelineStatus }) {
  if (status === 'success') return <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />;
  if (status === 'failed' || status === 'cancelled') return <XCircle className="h-4 w-4 shrink-0 text-rose-600" />;
  if (status === 'running') return <Loader2 className="h-4 w-4 shrink-0 animate-spin text-sky-600" />;
  return <Clock className="h-4 w-4 shrink-0 text-slate-400" />;
}
export const PipelineFlow: React.FC<PipelineFlowProps> = ({ pipeline, onSelect }) => {
  const [jobs, setJobs] = useState<JobItem[]>([]);
  const [error, setError] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [refresh, setRefresh] = useState(0);
  useEffect(() => {
    setJobs([]);
    setError('');
    if (!pipeline) return;
    const controller = new AbortController();
    let loading = false;
    const load = async () => {
      if (loading || controller.signal.aborted) return;
      loading = true;
      setIsLoading(true);
      try {
        const data = await cicdApi.getPipelineJobs(pipeline.id, controller.signal);
        if (!controller.signal.aborted) { setJobs(data); setError(''); }
      } catch (err) {
        if (!controller.signal.aborted) setError((err as Error).message);
      } finally {
        loading = false;
        if (!controller.signal.aborted) setIsLoading(false);
      }
    };
    void load();
    const refreshVisible = () => { if (document.visibilityState === 'visible') void load(); };
    const timer = window.setInterval(refreshVisible, 15000);
    document.addEventListener('visibilitychange', refreshVisible);
    return () => {
      controller.abort(); window.clearInterval(timer);
      document.removeEventListener('visibilitychange', refreshVisible);
    };
  }, [pipeline?.id, refresh]);
  if (!pipeline) return <p className="p-6 text-center text-slate-500">Chưa có pipeline nào được thực thi.</p>;
  return (
    <section aria-label="Jobs và steps của pipeline mới nhất" className="rounded-2xl border border-slate-200/80 bg-white/85 p-5 sm:p-6 shadow-xs">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
        <div className="flex min-w-0 items-center gap-3">
          <GitCommit className="h-5 w-5 shrink-0 text-emerald-600" />
          <div className="min-w-0">
            <h3 className="break-words text-sm font-bold text-neutral-900">#{pipeline.run_number} · {pipeline.name}</h3>
            <span className="text-xs font-semibold text-slate-600">{statusLabels[pipeline.status]}</span>
            <p className="break-words text-xs text-slate-500">{pipeline.branch} · {pipeline.commit_sha} · {pipeline.duration}</p>
          </div>
        </div>
        <button type="button" onClick={() => onSelect?.()} className="cursor-pointer rounded-xl bg-slate-100 px-3 py-2 text-xs font-semibold hover:bg-slate-200">Chi tiết log</button>
      </div>
      <p className="mb-3 text-xs text-slate-500">{jobs.length} jobs · {jobs.reduce((count, job) => count + job.steps.length, 0)} steps từ GitHub. Các jobs có thể chạy song song.</p>
      {isLoading && !jobs.length && <p role="status" className="text-sm text-slate-500">Đang tải jobs/steps từ GitHub…</p>}
      {error && <p role="alert" className="mb-3 text-sm text-rose-700">{error} <button type="button" onClick={() => setRefresh(value => value + 1)} className="cursor-pointer underline">Thử lại jobs</button></p>}
      {!isLoading && !error && !jobs.length && <p className="text-sm text-slate-500">Workflow chưa có jobs để hiển thị.</p>}
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        {jobs.map(job => (
          <div key={job.id} className="min-w-0 rounded-xl border border-slate-200 bg-white">
            <button type="button" onClick={() => onSelect?.(job.id)} aria-label={`Xem log job ${job.name}`} className="flex w-full cursor-pointer items-start gap-2 rounded-t-xl bg-slate-50 p-3 text-left hover:bg-slate-100">
              <StatusIcon status={job.status} />
              <div className="min-w-0 flex-1">
                <span className="block break-words text-xs font-bold">{job.name}</span>
                <span className="text-[11px] text-slate-500">{statusLabels[job.status]} · {job.duration} · {job.steps.length} steps</span>
              </div>
              <Terminal className="h-4 w-4 shrink-0 text-slate-400" />
            </button>
            <ol className="max-h-80 overflow-y-auto p-2 scrollbar-subtle">
              {job.steps.map((step, index) => (
                <li key={step.number}>
                  <button type="button" onClick={() => onSelect?.(job.id, index)} aria-label={`Xem log ${job.name}, bước ${step.number}: ${step.name}`} className="flex w-full cursor-pointer items-start gap-2 rounded-lg p-2 text-left hover:bg-slate-50">
                    <StatusIcon status={step.status} />
                    <span className="min-w-0 text-xs"><span className="block break-words">{step.number}. {step.name}</span><span className="text-[11px] text-slate-500">{statusLabels[step.status]}</span></span>
                  </button>
                </li>
              ))}
              {!job.steps.length && <li className="p-2 text-xs text-slate-500">GitHub chưa cung cấp steps cho job này.</li>}
            </ol>
          </div>
        ))}
      </div>
    </section>
  );
};
