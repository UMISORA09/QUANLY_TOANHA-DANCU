import React, { useState, useEffect, useCallback, useMemo } from 'react';
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
  SecurityAuditData,
  FreshnessOverviewData,
} from '../../Services/cicdApi';
import { FreshnessObservabilityView } from './FreshnessObservabilityView';
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
  const [activeTab, setActiveTab] = useState<'pipelines' | 'deployments' | 'environments' | 'freshness' | 'security' | 'activity'>('pipelines');

  // Data states
  const [overview, setOverview] = useState<CicdOverviewStats | null>(null);
  const [pipelines, setPipelines] = useState<PipelineItem[]>([]);
  const [deployments, setDeployments] = useState<DeploymentItem[]>([]);
  const [environments, setEnvironments] = useState<EnvironmentItem[]>([]);
  const [health, setHealth] = useState<SystemHealthStatus | null>(null);
  const [security, setSecurity] = useState<SecurityAuditData | null>(null);
  const [activities, setActivities] = useState<ActivityItem[]>([]);
  const [freshness, setFreshness] = useState<FreshnessOverviewData | null>(null);

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
  const [pipelinePage, setPipelinePage] = useState(1);

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
  const fetchData = useCallback(async (silent = false, force = false) => {
    if (!silent) setIsLoading(true);
    setError(null);
    try {
      const [bundle, freshnessData] = await Promise.all([
        cicdApi.getDashboardBundle(undefined, force),
        cicdApi.getFreshness(force).catch(() => null),
      ]);

      setOverview(bundle.overview);
      setPipelines(bundle.pipelines);
      setDeployments(bundle.deployments);
      setEnvironments(bundle.environments);
      setHealth(bundle.health);
      if (bundle.security) {
        setSecurity(bundle.security);
      }
      setActivities(bundle.activities);
      if (bundle.branches && bundle.branches.length > 0) {
        setGitBranches(bundle.branches);
      }
      if (freshnessData) {
        setFreshness(freshnessData);
      }
    } catch (err) {
      console.error(err);
      setError((err as Error).message || 'Không thể tải dữ liệu CI/CD.');
    } finally {
      if (!silent) setIsLoading(false);
      setIsRefreshing(false);
    }
  }, []);

  // Lọc pipelines tức thì trên client (0ms - Instantaneous Client Filtering)
  const filteredPipelines = useMemo(() => {
    return pipelines.filter((p) => {
      // 1. Lọc theo trạng thái
      if (filters.status && filters.status !== 'all' && p.status !== filters.status) {
        return false;
      }
      // 2. Lọc theo nhánh
      if (filters.branch && filters.branch !== 'all' && !p.branch.toLowerCase().includes(filters.branch.toLowerCase())) {
        return false;
      }
      // 3. Lọc theo workflow
      if (filters.workflow && filters.workflow !== 'all' && !p.workflow_file.toLowerCase().includes(filters.workflow.toLowerCase())) {
        return false;
      }
      // 4. Tìm kiếm từ khóa tức thì
      if (filters.search && filters.search.trim()) {
        const q = filters.search.trim().toLowerCase();
        const match =
          (p.name && p.name.toLowerCase().includes(q)) ||
          (p.branch && p.branch.toLowerCase().includes(q)) ||
          (p.commit_sha && p.commit_sha.toLowerCase().includes(q)) ||
          (p.commit_message && p.commit_message.toLowerCase().includes(q)) ||
          (p.author && p.author.toLowerCase().includes(q)) ||
          (p.author_login && p.author_login.toLowerCase().includes(q));
        if (!match) return false;
      }
      return true;
    });
  }, [pipelines, filters]);

  const pipelinePageSize = 15;
  const pipelineTotalPages = Math.max(1, Math.ceil(filteredPipelines.length / pipelinePageSize));
  const currentPipelinePage = Math.min(pipelinePage, pipelineTotalPages);
  const pipelinePageStart = (currentPipelinePage - 1) * pipelinePageSize;
  const paginatedPipelines = filteredPipelines.slice(pipelinePageStart, pipelinePageStart + pipelinePageSize);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  // Smart Polling: Poll every 6 seconds ONLY if a pipeline is currently running, and pause if document is hidden
  useEffect(() => {
    const hasRunningPipeline = overview?.running_count && overview.running_count > 0;
    if (!hasRunningPipeline) return;

    const interval = setInterval(() => {
      if (document.visibilityState === 'visible') {
        fetchData(true, true);
      }
    }, 6000);

    return () => clearInterval(interval);
  }, [overview?.running_count, fetchData]);

  // Tự động tải hoạt động mới theo thời gian thực (Real-time Activity Auto-refresh)
  const [isRefreshingActivities, setIsRefreshingActivities] = useState<boolean>(false);
  const fetchActivities = useCallback(async (force = false) => {
    try {
      setIsRefreshingActivities(true);
      const newActs = await cicdApi.getActivities(force);
      setActivities(newActs);
    } catch (e) {
      console.warn('Failed to refresh activities:', e);
    } finally {
      setIsRefreshingActivities(false);
    }
  }, []);

  useEffect(() => {
    if (activeTab !== 'activity') return;

    // Khi chuyển qua tab activity, tải mới ngay lập tức
    fetchActivities(true);

    const interval = setInterval(() => {
      if (document.visibilityState === 'visible') {
        fetchActivities(true);
      }
    }, 8000);

    return () => clearInterval(interval);
  }, [activeTab, fetchActivities]);

  const handleRefresh = () => {
    setIsRefreshing(true);
    fetchData(true, true);
    showToast('Đã làm mới dữ liệu CI/CD từ GitHub');
  };

  const handleSelectPipeline = (pipeline: PipelineItem) => {
    setSelectedPipeline(pipeline);
    setIsDetailOpen(true);
  };

  const handleRunPipelineSubmit = async (payload: { workflow: string; branch: string; environment: string; inputs?: Record<string, string> }) => {
    const res = await cicdApi.runPipeline(payload);
    showToast(res.message, 'success');
    fetchData(true);
    // Tự động tải lại sau 2.5s để hiển thị lượt chạy mới nhất từ GitHub Actions Runner
    setTimeout(() => {
      fetchData(true);
    }, 2500);
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
            <span
              className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 text-slate-600 text-[11px] font-mono border border-slate-200/80"
              title="Cần cấu hình GITHUB_TOKEN trong .env để kết nối trực tiếp với GitHub Actions API"
            >
              <span className="w-2 h-2 rounded-full bg-slate-400" />
              <span>GitHub API: Not configured</span>
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
      <div className="flex items-center gap-2 border-b border-slate-200/80 pb-2.5 overflow-x-auto scrollbar-subtle">
        {[
          { id: 'pipelines', label: 'Danh sách Pipelines', icon: Layers, badge: String(pipelines.length) },
          { id: 'deployments', label: 'Triển khai & Bản phát hành', icon: Server, badge: overview?.production_status === 'healthy' ? 'Active' : 'Unconfigured' },
          { id: 'environments', label: 'Môi trường & System Health', icon: HeartPulse },
          { id: 'freshness', label: 'Freshness Observability', icon: Clock, badge: freshness?.overall_state || 'Check' },
          { id: 'security', label: 'Bảo mật & Quét lỗ hổng', icon: Shield, badge: security?.status === 'configured' ? (security.summary || 'Checked') : 'Not configured' },
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
            onFilterChange={(nextFilters) => {
              setFilters(nextFilters);
              setPipelinePage(1);
            }}
            availableBranches={['all', ...gitBranches]}
            totalResults={filteredPipelines.length}
          />
          <PipelineTable
            pipelines={paginatedPipelines}
            isLoading={isLoading && pipelines.length === 0}
            onSelectPipeline={handleSelectPipeline}
            canRun={canRunPipeline}
          />
          {filteredPipelines.length > 0 && (
            <nav aria-label="Phân trang pipelines" className="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
              <span role="status">
                Hiển thị {pipelinePageStart + 1}–{pipelinePageStart + paginatedPipelines.length} / {filteredPipelines.length} pipelines · Trang {currentPipelinePage}/{pipelineTotalPages}
              </span>
              <div className="flex gap-2">
                <button type="button" disabled={currentPipelinePage <= 1} onClick={() => setPipelinePage(currentPipelinePage - 1)} className="rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 disabled:cursor-not-allowed disabled:opacity-40">Trước</button>
                <button type="button" disabled={currentPipelinePage >= pipelineTotalPages} onClick={() => setPipelinePage(currentPipelinePage + 1)} className="rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 disabled:cursor-not-allowed disabled:opacity-40">Sau</button>
              </div>
            </nav>
          )}
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
          <DeploymentTimeline deployments={deployments} />
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

          <SystemHealthView
            health={health}
            isLoading={isLoading}
            onRefresh={() => fetchData(true)}
            onHealthUpdate={(newHealth) => setHealth(newHealth)}
          />
        </div>
      )}

      {/* TAB CONTENT: SECURITY */}
      {activeTab === 'security' && (
        <div className="space-y-6">
          <div className="rounded-2xl bg-white/85 backdrop-blur-md p-6 border border-slate-200/80 shadow-xs space-y-5">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-purple-50 text-purple-600">
                  <Shield className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-neutral-900">
                    Bảo mật & Quét Lỗ Hổng Tự Động (Security Audit)
                  </h3>
                  <p className="text-xs text-slate-500 mt-0.5">
                    Quét rò rỉ mã khóa bí mật, kiểm tra CVE gói phụ thuộc và lỗ hổng container Docker
                  </p>
                </div>
              </div>

              {security?.status === 'configured' && security.run_url && (
                <a
                  href={security.run_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-neutral-800 text-xs font-semibold transition-colors"
                >
                  <span>Xem báo cáo GitHub</span>
                  <ExternalLink className="w-3.5 h-3.5 text-slate-500" />
                </a>
              )}
            </div>

            {security?.status !== 'configured' && (
              <div className="p-4 rounded-xl bg-amber-50/70 border border-amber-200 text-amber-900 text-xs space-y-1">
                <div className="font-bold flex items-center gap-1.5">
                  <AlertTriangle className="w-4 h-4 text-amber-600" />
                  <span>Trạng thái kết nối API: Not configured</span>
                </div>
                <p className="text-amber-800">
                  Quy trình quét bảo mật được thiết lập tại <code className="font-mono bg-amber-100 px-1 py-0.5 rounded">.github/workflows/security.yml</code>. Để đồng bộ dữ liệu thời gian thực từ GitHub Runner lên Dashboard này, vui lòng cấu hình biến môi trường <code className="font-mono bg-amber-100 px-1 py-0.5 rounded">GITHUB_TOKEN</code> trong tệp <code className="font-mono bg-amber-100 px-1 py-0.5 rounded">.env</code>.
                </p>
              </div>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {/* Gitleaks Secret Scanner */}
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-neutral-800">Gitleaks Secret Scan</span>
                  {security?.gitleaks === 'success' ? (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Passed</span>
                  ) : security?.gitleaks === 'failure' ? (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">Leak Detected</span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">Not configured</span>
                  )}
                </div>
                <p className="text-[11px] text-slate-500">
                  Rà soát toàn bộ lịch sử commit để phát hiện API key, mật khẩu, JWT token bị rò rỉ.
                </p>
                <div className="text-[10px] text-slate-400 font-mono">
                  Công cụ: Gitleaks Action v2
                </div>
              </div>

              {/* Composer Audit */}
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-neutral-800">Composer PHP Audit</span>
                  {security?.dependency_audit === 'success' ? (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Passed</span>
                  ) : security?.dependency_audit === 'failure' ? (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">CVE Found</span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">Not configured</span>
                  )}
                </div>
                <p className="text-[11px] text-slate-500">
                  Kiểm tra cơ sở dữ liệu lỗ hổng bảo mật FriendsOfPHP & Packagist Security Advisories.
                </p>
                <div className="text-[10px] text-slate-400 font-mono">
                  Lệnh: composer audit --locked
                </div>
              </div>

              {/* NPM Audit */}
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-neutral-800">NPM Package Audit</span>
                  {security?.dependency_audit === 'success' ? (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Passed</span>
                  ) : security?.dependency_audit === 'failure' ? (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">CVE Found</span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">Not configured</span>
                  )}
                </div>
                <p className="text-[11px] text-slate-500">
                  Rà soát các gói Javascript/Node.js mức độ nghiêm trọng High/Critical.
                </p>
                <div className="text-[10px] text-slate-400 font-mono">
                  Lệnh: npm audit --audit-level=high
                </div>
              </div>

              {/* Trivy Container Scan */}
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200/80 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-neutral-800">Trivy Container Scan</span>
                  {security?.trivy_container === 'success' ? (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">Passed</span>
                  ) : security?.trivy_container === 'failure' ? (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">Vulnerabilities</span>
                  ) : (
                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">Not configured</span>
                  )}
                </div>
                <p className="text-[11px] text-slate-500">
                  Quét sâu toàn bộ hệ điều hành Bookworm và thư viện trong Docker Production Image.
                </p>
                <div className="text-[10px] text-slate-400 font-mono">
                  Công cụ: Aqua Trivy Action
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* TAB CONTENT: FRESHNESS OBSERVABILITY */}
      {activeTab === 'freshness' && (
        <FreshnessObservabilityView
          freshness={freshness}
          isLoading={isLoading}
          onRefresh={() => {
            cicdApi.getFreshness(true).then(setFreshness).catch(console.error);
          }}
        />
      )}

      {/* TAB CONTENT: ACTIVITY */}
      {activeTab === 'activity' && (
        <ActivityTimeline
          activities={activities}
          isLoading={isLoading}
          onRefresh={() => fetchActivities(true)}
          isRefreshing={isRefreshingActivities}
        />
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
