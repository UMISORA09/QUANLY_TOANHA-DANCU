import React, { useState, useEffect, useCallback } from 'react';
import {
  Terminal,
  Play,
  RefreshCw,
  Sliders,
  Layers,
  Server,
  Activity,
  Tag,
  AlertTriangle,
  RotateCcw,
  CheckCircle2,
  XCircle,
  ExternalLink,
  Shield,
  Clock,
  Sparkles,
  HeartPulse,
} from 'lucide-react';
import {
  cicdApi,
  CicdOverviewStats,
  PipelineItem,
  DeploymentItem,
  EnvironmentItem,
  SystemHealthStatus,
  ActivityItem,
} from '../../Services/cicdApi';
import { PipelineStatsCards } from './PipelineStatsCards';
import { PipelineFlow } from './PipelineFlow';
import { PipelineFilters, FilterState } from './PipelineFilters';
import { PipelineTable } from './PipelineTable';
import { PipelineDetailDrawer } from './PipelineDetailDrawer';
import { DeploymentOverview } from './DeploymentOverview';
import { DeploymentTimeline } from './DeploymentTimeline';
import { EnvironmentCards } from './EnvironmentCards';
import { SystemHealthView } from './SystemHealthView';
import { ActivityTimeline } from './ActivityTimeline';
import { RunPipelineModal } from './RunPipelineModal';
import { RollbackModal } from './RollbackModal';

interface CicdDashboardProps {
  userRole?: string;
}

export const CicdDashboard: React.FC<CicdDashboardProps> = ({ userRole = 'admin' }) => {
  // Navigation tabs within CI/CD Dashboard
  const [activeTab, setActiveTab] = useState<'pipelines' | 'deployments' | 'environments' | 'activity'>('pipelines');

  // Data states
  const [overview, setOverview] = useState<CicdOverviewStats | null>(null);
  const [pipelines, setPipelines] = useState<PipelineItem[]>([]);
  const [deployments, setDeployments] = useState<DeploymentItem[]>([]);
  const [environments, setEnvironments] = useState<EnvironmentItem[]>([]);
  const [health, setHealth] = useState<SystemHealthStatus | null>(null);
  const [activities, setActivities] = useState<ActivityItem[]>([]);

  // Loading & Error states
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isRefreshing, setIsRefreshing] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  // Filter state for pipelines
  const [filters, setFilters] = useState<FilterState>({
    search: '',
    status: 'all',
    branch: 'all',
    workflow: 'all',
    dateRange: 'all',
  });

  // Interactive drawer & modal states
  const [selectedPipeline, setSelectedPipeline] = useState<PipelineItem | null>(null);
  const [isDetailOpen, setIsDetailOpen] = useState<boolean>(false);
  const [isRunModalOpen, setIsRunModalOpen] = useState<boolean>(false);
  const [isRollbackModalOpen, setIsRollbackModalOpen] = useState<boolean>(false);
  const [rollbackEnv, setRollbackEnv] = useState<string>('production');
  const [gitBranches, setGitBranches] = useState<string[]>(['main', 'DangNguyen/CI-CD', 'DangNguyen/amenity-management']);
  const [toastMessage, setToastMessage] = useState<{ text: string; type: 'success' | 'error' } | null>(null);

  // Permissions based on user role
  const isAdmin = userRole === 'admin' || userRole === 'super_admin';
  const isDevOps = isAdmin || userRole === 'devops';
  const isDeveloper = isDevOps || userRole === 'developer';
  const canRunPipeline = isDeveloper;
  const canDeploy = isDevOps;
  const canRollback = isDevOps;

  const showToast = (text: string, type: 'success' | 'error' = 'success') => {
    setToastMessage({ text, type });
    setTimeout(() => setToastMessage(null), 3500);
  };

  // Fetch all dashboard data
  const fetchData = useCallback(async (silent = false) => {
    if (!silent) setIsLoading(true);
    setError(null);
    try {
      const bundle = await cicdApi.getDashboardBundle(filters);

      setOverview(bundle.overview);
      setPipelines(bundle.pipelines);
      setDeployments(bundle.deployments);
      setEnvironments(bundle.environments);
      setHealth(bundle.health);
      setActivities(bundle.activities);
      if (bundle.branches && bundle.branches.length > 0) {
        setGitBranches(bundle.branches);
      }
    } catch (err) {
      console.error(err);
      setError((err as Error).message || 'Không thể tải dữ liệu CI/CD.');
    } finally {
      if (!silent) setIsLoading(false);
      setIsRefreshing(false);
    }
  }, [filters]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  // Smart Polling: Poll every 6 seconds ONLY if a pipeline is currently running, and pause if document is hidden
  useEffect(() => {
    const hasRunningPipeline = overview?.running_count && overview.running_count > 0;
    if (!hasRunningPipeline) return;

    const interval = setInterval(() => {
      if (document.visibilityState === 'visible') {
        fetchData(true);
      }
    }, 6000);

    return () => clearInterval(interval);
  }, [overview?.running_count, fetchData]);

  const handleRefresh = () => {
    setIsRefreshing(true);
    fetchData(true);
    showToast('Đã làm mới dữ liệu CI/CD');
  };

  const handleSelectPipeline = (pipeline: PipelineItem) => {
    setSelectedPipeline(pipeline);
    setIsDetailOpen(true);
  };

  const handleRunPipelineSubmit = async (payload: { workflow: string; branch: string; environment: string }) => {
    const res = await cicdApi.runPipeline(payload);
    showToast(res.message, 'success');
    fetchData(true);
  };

  const handleRollbackSubmit = async (targetVersion: string) => {
    const res = await cicdApi.rollback(rollbackEnv, targetVersion);
    showToast(res.message, 'success');
    fetchData(true);
  };

  const handleTriggerDeploy = async (env: string) => {
    try {
      const res = await cicdApi.deploy(env);
      showToast(res.message, 'success');
      fetchData(true);
    } catch (err) {
      showToast((err as Error).message, 'error');
    }
  };

  const handleTriggerRollback = (env: string) => {
    setRollbackEnv(env);
    setIsRollbackModalOpen(true);
  };

  return (
    <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 transition-all">
      {/* Toast Notification */}
      {toastMessage && (
        <div
          className={`fixed top-5 right-5 z-50 px-4 py-2.5 rounded-2xl shadow-xl flex items-center gap-2.5 text-xs font-bold animate-in fade-in slide-in-from-top-3 ${
            toastMessage.type === 'success'
              ? 'bg-emerald-950 text-emerald-100 border border-emerald-800'
              : 'bg-rose-950 text-rose-100 border border-rose-800'
          }`}
        >
          {toastMessage.type === 'success' ? (
            <CheckCircle2 className="w-4 h-4 text-emerald-400" />
          ) : (
            <XCircle className="w-4 h-4 text-rose-400" />
          )}
          <span>{toastMessage.text}</span>
        </div>
      )}

      {/* HEADER SECTION */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-2 border-b border-slate-200/60">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-slate-500">
            <Terminal className="w-4 h-4 text-sky-600" />
            <span className="text-sky-700 font-extrabold">DEVOPS & PIPELINES</span>
            <span className="text-slate-300">&middot;</span>
            <span className="px-2 py-0.5 rounded-md font-bold text-[10px] bg-slate-100 border border-slate-200 text-neutral-800">
              SMART CASSAVAS
            </span>
          </div>

          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            CI/CD Dashboard
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Monitor builds, tests, deployments and application health.
          </p>
        </div>

        {/* Header Right Actions */}
        <div className="flex flex-wrap items-center gap-2 self-start lg:self-center">
          {/* Refresh Button */}
          <button
            type="button"
            onClick={handleRefresh}
            disabled={isRefreshing}
            className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white hover:bg-slate-50 text-neutral-700 border border-slate-200 text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
            title="Đồng bộ dữ liệu mới nhất"
          >
            <RefreshCw className={`w-3.5 h-3.5 text-slate-500 ${isRefreshing ? 'animate-spin' : ''}`} />
            <span>{isRefreshing ? 'Đang đồng bộ...' : 'Làm mới'}</span>
          </button>

          {/* GitHub Status Link */}
          {overview?.is_live_github ? (
            <a
              href="https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions"
              target="_blank"
              rel="noopener noreferrer"
              className="hidden sm:flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white hover:bg-slate-50 text-neutral-700 border border-slate-200 text-xs font-semibold shadow-2xs transition-colors"
            >
              <ExternalLink className="w-3.5 h-3.5 text-slate-500" />
              <span>GitHub Actions</span>
            </a>
          ) : (
            <span className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 text-slate-600 text-[11px] font-mono border border-slate-200/80">
              <span className="w-2 h-2 rounded-full bg-emerald-500" />
              <span>Local Runner</span>
            </span>
          )}

          {/* Run Pipeline Button */}
          {canRunPipeline ? (
            <button
              type="button"
              onClick={() => setIsRunModalOpen(true)}
              className="flex items-center gap-2 px-4 py-2 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white text-xs font-bold shadow-md hover:shadow-lg transition-all active:scale-95 cursor-pointer"
            >
              <Play className="w-3.5 h-3.5 text-emerald-400" />
              <span>Run Pipeline</span>
            </button>
          ) : (
            <div
              className="px-3.5 py-2 rounded-xl bg-slate-100 text-slate-400 text-xs font-semibold cursor-not-allowed"
              title="Bạn không có quyền kích hoạt pipeline"
            >
              Run Pipeline (Khóa)
            </div>
          )}
        </div>
      </div>

      {/* ERROR BANNER */}
      {error && (
        <div className="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 flex items-center justify-between text-xs font-medium animate-in fade-in">
          <div className="flex items-center gap-2.5">
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{error}</span>
          </div>
          <button
            type="button"
            onClick={() => fetchData()}
            className="px-3 py-1 rounded-lg bg-rose-600 text-white text-xs font-bold hover:bg-rose-700 transition-colors"
          >
            Thử lại
          </button>
        </div>
      )}

      {/* 5-6 STATS CARDS */}
      <PipelineStatsCards stats={overview} isLoading={isLoading} />

      {/* LATEST PIPELINE FLOW VISUALIZATION */}
      {overview?.latest_pipeline && (
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <h2 className="text-xs font-extrabold uppercase tracking-wider text-slate-400 font-mono">
              Pipeline Mới Nhất (Latest Pipeline Flow)
            </h2>
            <span className="text-[11px] text-slate-400">
              Nhấp vào giai đoạn để xem terminal logs
            </span>
          </div>
          <PipelineFlow
            pipeline={overview.latest_pipeline}
            onSelect={() => handleSelectPipeline(overview.latest_pipeline!)}
          />
        </div>
      )}

      {/* SUB-VIEW TABS */}
      <div className="flex items-center gap-2 border-b border-slate-200/80 pb-2 overflow-x-auto">
        {[
          { id: 'pipelines', label: 'Danh sách Pipelines', icon: Layers, badge: String(pipelines.length) },
          { id: 'deployments', label: 'Triển khai & Bản phát hành', icon: Server, badge: 'Active' },
          { id: 'environments', label: 'Môi trường & System Health', icon: HeartPulse },
          { id: 'activity', label: 'Nhật ký Hoạt động', icon: Activity },
        ].map((tab) => {
          const TabIcon = tab.icon;
          const isActive = activeTab === tab.id;
          return (
            <button
              key={tab.id}
              type="button"
              onClick={() => setActiveTab(tab.id as any)}
              className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap ${
                isActive
                  ? 'bg-neutral-900 text-white shadow-xs'
                  : 'bg-white hover:bg-slate-100/80 text-slate-600 border border-slate-200/80'
              }`}
            >
              <TabIcon className="w-3.5 h-3.5" />
              <span>{tab.label}</span>
              {tab.badge && (
                <span
                  className={`px-1.5 py-0.2 rounded text-[10px] font-mono ${
                    isActive ? 'bg-neutral-800 text-emerald-400' : 'bg-slate-100 text-slate-600'
                  }`}
                >
                  {tab.badge}
                </span>
              )}
            </button>
          );
        })}
      </div>

      {/* TAB CONTENT: PIPELINES */}
      {activeTab === 'pipelines' && (
        <div className="space-y-4">
          <PipelineFilters
            onFilterChange={setFilters}
            availableBranches={['all', ...gitBranches]}
            totalResults={pipelines.length}
          />
          <PipelineTable
            pipelines={pipelines}
            isLoading={isLoading}
            onSelectPipeline={handleSelectPipeline}
            canRun={canRunPipeline}
          />
        </div>
      )}

      {/* TAB CONTENT: DEPLOYMENTS */}
      {activeTab === 'deployments' && (
        <div className="space-y-6">
          <DeploymentOverview
            deployments={deployments}
            onTriggerDeploy={handleTriggerDeploy}
            onTriggerRollback={handleTriggerRollback}
            canDeploy={canDeploy}
          />
          <DeploymentTimeline />
        </div>
      )}

      {/* TAB CONTENT: ENVIRONMENTS & SYSTEM HEALTH */}
      {activeTab === 'environments' && (
        <div className="space-y-6">
          <div>
            <h3 className="text-xs font-extrabold uppercase tracking-wider text-slate-400 font-mono mb-3">
              Trạng thái các Môi trường (Environments)
            </h3>
            <EnvironmentCards environments={environments} isLoading={isLoading} />
          </div>

          <SystemHealthView health={health} isLoading={isLoading} onRefresh={() => fetchData(true)} />
        </div>
      )}

      {/* TAB CONTENT: ACTIVITY */}
      {activeTab === 'activity' && (
        <ActivityTimeline activities={activities} isLoading={isLoading} />
      )}

      {/* PIPELINE DETAIL DRAWER */}
      <PipelineDetailDrawer
        pipeline={selectedPipeline}
        isOpen={isDetailOpen}
        onClose={() => setIsDetailOpen(false)}
        onPipelineUpdated={() => fetchData(true)}
        canManage={canRunPipeline}
      />

      {/* RUN PIPELINE MODAL */}
      <RunPipelineModal
        isOpen={isRunModalOpen}
        onClose={() => setIsRunModalOpen(false)}
        onSubmit={handleRunPipelineSubmit}
        availableBranches={gitBranches}
      />

      {/* ROLLBACK MODAL */}
      <RollbackModal
        isOpen={isRollbackModalOpen}
        onClose={() => setIsRollbackModalOpen(false)}
        onSubmit={handleRollbackSubmit}
        environment={rollbackEnv}
        currentVersion={overview?.production_version}
      />
    </div>
  );
};
