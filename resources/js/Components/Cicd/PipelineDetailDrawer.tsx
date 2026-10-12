import React, { useState, useEffect, useRef } from 'react';
import {
  X,
  RotateCw,
  Ban,
  GitBranch,
  GitCommit,
  User,
  Clock,
  ExternalLink,
  CheckCircle2,
  XCircle,
  Loader2,
  Terminal,
  AlertTriangle,
  ChevronDown,
  Layers,
  ShieldAlert,
} from 'lucide-react';
import { PipelineItem, JobItem, cicdApi } from '../../Services/cicdApi';
import { LogViewer } from './LogViewer';
import { ConfirmDialog } from '../Admin/ConfirmDialog';

interface PipelineDetailDrawerProps {
  initialJobId?: string;
  initialStepNumber?: number;
  pipeline: PipelineItem | null;
  isOpen: boolean;
  onClose: () => void;
  onPipelineUpdated?: () => void;
  canManage?: boolean;
}

export const PipelineDetailDrawer: React.FC<PipelineDetailDrawerProps> = ({
  pipeline,
  isOpen,
  onClose,
  onPipelineUpdated,
  canManage = true,
  initialJobId,
  initialStepNumber,
}) => {
  const [jobs, setJobs] = useState<JobItem[]>([]);
  const [selectedJobId, setSelectedJobId] = useState<string | null>(null);
  const [selectedStepNumber, setSelectedStepNumber] = useState<number | undefined>();
  const [jobsError, setJobsError] = useState('');
  const [logs, setLogs] = useState<string>('');
  const [isLoadingJobs, setIsLoadingJobs] = useState<boolean>(false);
  const [isLoadingLogs, setIsLoadingLogs] = useState<boolean>(false);

  // Dialogs confirmation
  const [isRetryDialogOpen, setIsRetryDialogOpen] = useState<boolean>(false);
  const [isCancelDialogOpen, setIsCancelDialogOpen] = useState<boolean>(false);
  const [actionLoading, setActionLoading] = useState<boolean>(false);
  const drawerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!isOpen || isRetryDialogOpen || isCancelDialogOpen) return;
    const previousFocus = document.activeElement as HTMLElement | null;
    drawerRef.current?.querySelector<HTMLButtonElement>('[aria-label="Đóng chi tiết pipeline"]')?.focus();
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
      }
      if (event.key === 'Tab') {
        const controls = drawerRef.current?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), [tabindex="0"]');
        if (!controls?.length) return;
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      previousFocus?.focus();
    };
  }, [isOpen, isRetryDialogOpen, isCancelDialogOpen, onClose]);

  useEffect(() => {
    setJobs([]);
    setJobsError('');
    setSelectedJobId(null);
    setSelectedStepNumber(initialStepNumber);
    if (!isOpen || !pipeline) return;
    const controller = new AbortController();
    setIsLoadingJobs(true);
    cicdApi.getPipelineJobs(pipeline.id, controller.signal).then(data => {
      if (controller.signal.aborted) return;
      setJobs(data);
      setSelectedJobId(data.find(job => job.id === initialJobId)?.id ?? data[0]?.id ?? null);
    }).catch(err => {
      if (!controller.signal.aborted) setJobsError((err as Error).message);
    }).finally(() => {
      if (!controller.signal.aborted) setIsLoadingJobs(false);
    });
    return () => controller.abort();
  }, [isOpen, pipeline?.id, initialJobId, initialStepNumber]);

  useEffect(() => {
    setLogs('');
    setIsLoadingLogs(false);
    if (!isOpen || !pipeline || !selectedJobId) return;
    const controller = new AbortController();
    setIsLoadingLogs(true);
    cicdApi.getPipelineLogs(pipeline.id, selectedJobId, selectedStepNumber, controller.signal).then(logText => {
      if (!controller.signal.aborted) setLogs(logText);
    }).catch(() => {
      if (!controller.signal.aborted) setLogs('Không thể tải dữ liệu log từ máy chủ.');
    }).finally(() => {
      if (!controller.signal.aborted) setIsLoadingLogs(false);
    });
    return () => controller.abort();
  }, [isOpen, pipeline?.id, selectedJobId, selectedStepNumber]);

  const handleJobClick = (jobId: string) => {
    setSelectedJobId(jobId);
    setSelectedStepNumber(undefined);
  };

  const handleConfirmRetry = async () => {
    if (!pipeline) return;
    setActionLoading(true);
    try {
      await cicdApi.retryPipeline(pipeline.id);
      setIsRetryDialogOpen(false);
      onPipelineUpdated?.();
    } catch (err) {
      alert((err as Error).message);
    } finally {
      setActionLoading(false);
    }
  };

  const handleConfirmCancel = async () => {
    if (!pipeline) return;
    setActionLoading(true);
    try {
      await cicdApi.cancelPipeline(pipeline.id);
      setIsCancelDialogOpen(false);
      onPipelineUpdated?.();
    } catch (err) {
      alert((err as Error).message);
    } finally {
      setActionLoading(false);
    }
  };

  if (!isOpen || !pipeline) return null;

  const isFailed = pipeline.status === 'failed';
  const isRunning = pipeline.status === 'running';

  return (
    <>
      <div className="fixed inset-0 z-50 overflow-hidden">
        {/* Backdrop */}
        <div
          onClick={onClose}
          className="fixed inset-0 bg-neutral-950/60 backdrop-blur-xs transition-opacity animate-in fade-in"
        />

        {/* Drawer Panel */}
        <div className="fixed inset-y-0 right-0 flex w-full max-w-3xl">
          <div ref={drawerRef} role="dialog" aria-modal="true" aria-labelledby="pipeline-detail-title" className="w-full min-w-0 bg-white shadow-2xl flex flex-col border-l border-slate-200 animate-in slide-in-from-right duration-300">
            {/* Drawer Header */}
            <div className="p-4 sm:p-6 border-b border-slate-100 flex flex-wrap items-start justify-between gap-3 bg-slate-50/70">
              <div className="min-w-0 flex-1 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-xs font-black text-neutral-800 bg-white px-2 py-0.5 rounded border border-slate-200 shadow-2xs">
                    #{pipeline.run_number}
                  </span>
                  <h2 id="pipeline-detail-title" className="break-words text-base sm:text-lg font-black text-neutral-900">
                    {pipeline.name}
                  </h2>
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
                <p className="text-xs text-slate-500 font-medium line-clamp-1">
                  {pipeline.commit_message}
                </p>
              </div>

              <div className="flex shrink-0 items-center gap-2">
                {/* Action Buttons */}
                {canManage && (
                  <>
                    {isRunning && (
                      <button
                        type="button"
                        onClick={() => setIsCancelDialogOpen(true)}
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 text-xs font-bold transition-colors cursor-pointer"
                      >
                        <Ban className="w-3.5 h-3.5" />
                        <span>Hủy bỏ</span>
                      </button>
                    )}

                    {!isRunning && (
                      <button
                        type="button"
                        onClick={() => setIsRetryDialogOpen(true)}
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-neutral-900 text-white hover:bg-neutral-800 text-xs font-bold transition-colors cursor-pointer shadow-xs"
                      >
                        <RotateCw className="w-3.5 h-3.5" />
                        <span>Chạy lại</span>
                      </button>
                    )}
                  </>
                )}

                <button
                  type="button"
                  onClick={onClose}
                  aria-label="Đóng chi tiết pipeline"
                  className="p-1.5 text-slate-400 hover:text-neutral-700 rounded-xl hover:bg-slate-200/60 transition-colors"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
            </div>

            {/* Metadata Summary Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 p-4 bg-slate-50/40 border-b border-slate-100 text-xs">
              <div className="p-2.5 rounded-xl bg-white border border-slate-200/80">
                <span className="text-[10px] uppercase font-bold text-slate-400 block mb-1">Nhánh Git</span>
                <div className="flex items-center gap-1 font-mono font-bold text-neutral-800 truncate">
                  <GitBranch className="w-3 h-3 text-slate-400 shrink-0" />
                  <span className="truncate">{pipeline.branch}</span>
                </div>
              </div>

              <div className="p-2.5 rounded-xl bg-white border border-slate-200/80">
                <span className="text-[10px] uppercase font-bold text-slate-400 block mb-1">Commit SHA</span>
                <div className="flex items-center gap-1 font-mono font-bold text-neutral-800">
                  <GitCommit className="w-3 h-3 text-slate-400 shrink-0" />
                  <span>{pipeline.commit_sha}</span>
                </div>
              </div>

              <div className="p-2.5 rounded-xl bg-white border border-slate-200/80">
                <span className="text-[10px] uppercase font-bold text-slate-400 block mb-1">Người chạy</span>
                <div className="flex items-center gap-1.5 font-bold text-neutral-800 truncate">
                  {pipeline.author_avatar ? (
                    <img
                      src={pipeline.author_avatar}
                      alt={pipeline.author}
                      className="w-4 h-4 rounded-full object-cover ring-1 ring-slate-200 shrink-0"
                    />
                  ) : (
                    <User className="w-3 h-3 text-slate-400 shrink-0" />
                  )}
                  <span className="truncate text-xs">{pipeline.author}</span>
                  {pipeline.author_login && pipeline.author_login !== pipeline.author && (
                    <span className="text-[10px] text-slate-400 font-mono font-normal truncate">(@{pipeline.author_login})</span>
                  )}
                </div>
              </div>

              <div className="p-2.5 rounded-xl bg-white border border-slate-200/80">
                <span className="text-[10px] uppercase font-bold text-slate-400 block mb-1">Thời gian chạy</span>
                <div className="flex items-center gap-1 font-mono font-bold text-neutral-800">
                  <Clock className="w-3 h-3 text-slate-400 shrink-0" />
                  <span>{pipeline.duration}</span>
                </div>
              </div>
            </div>

            {/* Failed Pipeline Warning Banner */}
            {isFailed && (
              <div className="m-4 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-900 flex items-start gap-3">
                <ShieldAlert className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
                <div className="text-xs space-y-1">
                  <h4 className="font-extrabold text-rose-900">Build thất bại (Build Failed)</h4>
                  <p className="text-rose-700">
                    Phát hiện lỗi trong bước kiểm thử hoặc build. Vui lòng kiểm tra terminal log bên dưới để xác định nguyên nhân chi tiết.
                  </p>
                </div>
              </div>
            )}

            {/* Main Content Area: Jobs Tabs & Log Viewer */}
            <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6 space-y-5">
              {/* Jobs List */}
              <div>
                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 font-mono">
                  Danh sách Jobs ({jobs.length})
                </h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  {isLoadingJobs && <p className="text-xs text-slate-500" role="status">Đang tải danh sách jobs...</p>}
                  {jobsError && <p role="alert" className="text-xs text-rose-700">{jobsError}</p>}
                  {!isLoadingJobs && !jobsError && !jobs.length && <p className="text-xs text-slate-500">Workflow chưa có jobs để tải log.</p>}
                  {jobs.map((job) => {
                    const isSelected = selectedJobId === job.id;
                    return (
                      <button
                        key={job.id}
                        type="button"
                        onClick={() => handleJobClick(job.id)}
                        className={`p-3 rounded-xl border text-left transition-all cursor-pointer flex items-center justify-between ${
                          isSelected
                            ? 'bg-slate-900 text-white border-slate-900 shadow-md ring-2 ring-slate-900/10'
                            : 'bg-white hover:bg-slate-50 border-slate-200 text-neutral-800'
                        }`}
                      >
                        <div className="flex items-center gap-2.5 min-w-0">
                          {job.status === 'success' ? (
                            <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
                          ) : job.status === 'failed' ? (
                            <XCircle className="w-4 h-4 text-rose-400 shrink-0" />
                          ) : job.status === 'running' ? (
                            <Loader2 className="w-4 h-4 text-sky-400 animate-spin shrink-0" />
                          ) : (
                            <Clock className="w-4 h-4 text-slate-400 shrink-0" />
                          )}
                          <div className="min-w-0">
                            <span className="text-xs font-bold truncate block">{job.name}</span>
                            <span className={`text-[10px] font-mono ${isSelected ? 'text-slate-400' : 'text-slate-400'}`}>
                              {job.duration} &middot; {job.steps.length} steps
                            </span>
                          </div>
                        </div>

                        <span
                          className={`text-[10px] uppercase font-extrabold px-1.5 py-0.5 rounded ${
                            isSelected
                              ? 'bg-slate-800 text-slate-200'
                              : 'bg-slate-100 text-slate-600'
                          }`}
                        >
                          {job.status}
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Terminal Logs */}
              <div>
                <h3 className="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2 font-mono flex items-center justify-between">
                  <span>Terminal Console Log{selectedStepNumber !== undefined ? ` · Bước: ${jobs.find(job => job.id === selectedJobId)?.steps[selectedStepNumber]?.name ?? selectedStepNumber + 1}` : ''}</span>
                  {pipeline.url && (
                    <a
                      href={pipeline.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1 text-sky-600 hover:text-sky-800 font-semibold normal-case"
                    >
                      <span>Mở trên GitHub</span>
                      <ExternalLink className="w-3 h-3" />
                    </a>
                  )}
                </h3>

                <LogViewer logs={logs} isLoading={isLoadingLogs} title={`Pipeline #${pipeline.run_number} Log Output`} />
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Confirmation Dialogs */}
      <ConfirmDialog
        isOpen={isRetryDialogOpen}
        title="Xác nhận chạy lại Pipeline?"
        message={`Bạn có chắc chắn muốn kích hoạt lại toàn bộ các bước kiểm thử và build cho Pipeline #${pipeline.run_number} (${pipeline.name})?`}
        confirmLabel="Chạy lại ngay"
        isLoading={actionLoading}
        onConfirm={handleConfirmRetry}
        onCancel={() => setIsRetryDialogOpen(false)}
      />

      <ConfirmDialog
        isOpen={isCancelDialogOpen}
        title="Dừng khẩn cấp Pipeline đang chạy?"
        message="Hành động này sẽ gửi tín hiệu hủy ngay lập tức tới máy chủ CI/CD Runner. Bạn có chắc chắn muốn dừng?"
        confirmLabel="Dừng Pipeline"
        isDangerous={true}
        isLoading={actionLoading}
        onConfirm={handleConfirmCancel}
        onCancel={() => setIsCancelDialogOpen(false)}
      />
    </>
  );
};
