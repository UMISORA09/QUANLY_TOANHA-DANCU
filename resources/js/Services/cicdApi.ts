/**
 * CI/CD & DevOps REST API Client
 * Kết nối các endpoint /api/admin/cicd/* bảo mật với proxy GitHub Actions
 */

export type PipelineStatus = 'success' | 'failed' | 'running' | 'cancelled' | 'queued' | 'skipped';

export interface PipelineItem {
  id: string;
  run_number: number;
  name: string;
  workflow_file: string;
  status: PipelineStatus;
  branch: string;
  commit_sha: string;
  commit_message: string;
  author: string;
  author_login?: string | null;
  author_avatar?: string | null;
  trigger: string;
  duration: string;
  created_at: string;
  url?: string | null;
}

export interface StepItem {
  name: string;
  status: PipelineStatus;
  number: number;
  started_at?: string | null;
  completed_at?: string | null;
}

export interface JobItem {
  id: string;
  name: string;
  status: PipelineStatus;
  duration: string;
  started_at: string;
  completed_at?: string | null;
  steps: StepItem[];
}

export interface DeploymentItem {
  id: string;
  environment: 'production' | 'staging' | 'development';
  version: string;
  image_tag: string;
  commit_sha: string;
  status: 'healthy' | 'degraded' | 'deploying' | 'failed' | 'stopped' | 'unknown' | 'not_deployed' | 'not_configured';
  deployed_by: string;
  deployed_at: string | null;
  response_time_ms: number;
  release_notes?: string;
}

export interface EnvironmentItem {
  id: 'production' | 'staging' | 'development';
  name: string;
  url: string;
  status: 'operational' | 'degraded' | 'down' | 'not_deployed' | 'not_configured';
  version: string;
  commit_sha: string;
  last_deployment: string;
  response_time_ms: number;
  uptime_percentage: string;
  branch: string;
  approval_required: boolean;
}

export interface HealthServiceItem {
  name: string;
  status: 'operational' | 'degraded' | 'down';
  version: string;
  response_time: string;
}

export interface SystemHealthStatus {
  status: 'operational' | 'degraded' | 'down';
  timestamp: string;
  services: HealthServiceItem[];
  metrics: {
    memory_usage_mb: number;
    php_version: string;
    disk_usage_percent: number;
    db_ping_ms: number;
  };
}

export interface SecurityAuditData {
  status: 'configured' | 'not_configured' | 'unavailable';
  message?: string;
  run_id?: string;
  run_url?: string;
  last_run_at?: string;
  gitleaks: 'success' | 'failure' | 'running' | 'queued' | 'unknown' | 'not_configured';
  dependency_audit: 'success' | 'failure' | 'running' | 'queued' | 'unknown' | 'not_configured';
  trivy_container: 'success' | 'failure' | 'running' | 'queued' | 'unknown' | 'not_configured';
  summary?: string;
}

export interface CicdOverviewStats {
  total_pipelines: number;
  success_count: number;
  failed_count: number;
  running_count: number;
  success_rate: number;
  latest_pipeline: PipelineItem | null;
  production_status: string;
  production_version: string;
  staging_status: string;
  staging_version: string;
  system_health: string;
  is_live_github: boolean;
}

export interface ActivityItem {
  id: string;
  type: 'build' | 'deploy' | 'test' | 'security' | 'rollback';
  title: string;
  description: string;
  status: 'success' | 'failed' | 'running';
  actor: string;
  timestamp: string;
}

export type FreshnessState = 'FRESH' | 'STALE' | 'CRITICAL' | 'UNKNOWN' | 'UNAVAILABLE';

export interface FreshnessSourceItem {
  source: string;
  table?: string;
  name: string;
  type: 'data' | 'monitoring';
  status: FreshnessState;
  last_update_at?: string | null;
  last_event_at?: string | null;
  last_observed_at?: string | null;
  source_timestamp?: string | null;
  age_seconds: number | null;
  warning_threshold: number;
  critical_threshold: number;
  error?: string;
  message?: string;
  reason?: string;
  workflow?: string;
  commit_sha?: string;
  environment?: string;
  version?: string;
  timestamp_field?: string;
  execution_state?: string;
  conclusion?: string;
  details?: any;
}

export interface FreshnessIncidentItem {
  id?: number;
  source: string;
  source_type: string;
  state: string;
  age_seconds?: number;
  threshold_seconds?: number;
  source_timestamp?: string;
  detected_at: string;
  resolved_at?: string;
  details?: string;
}

export interface FreshnessWorstSource {
  source: string;
  name: string;
  status: FreshnessState;
  reason: string;
}

export interface FreshnessOverviewData {
  status: 'fresh' | 'stale' | 'critical' | 'unknown' | 'unavailable';
  overall_state: FreshnessState;
  overall_reason?: string;
  checked_at: string;
  worst_source?: FreshnessWorstSource | null;
  newest_data_age_seconds?: number | null;
  oldest_data_age_seconds?: number | null;
  metrics?: {
    newest_data_age_seconds: number | null;
    oldest_data_age_seconds: number | null;
    worst_source: FreshnessWorstSource | null;
    overall_state: FreshnessState;
    overall_reason: string;
  };
  collector: {
    status: FreshnessState;
    last_success_at: string | null;
    source_timestamp?: string | null;
    age_seconds: number | null;
    warning_threshold: number;
    critical_threshold: number;
    errors_count?: number;
    reason?: string;
  };
  github_actions: FreshnessSourceItem;
  deployment: FreshnessSourceItem;
  application_health: FreshnessSourceItem;
  database: {
    status: FreshnessState;
    last_data_update_at: string | null;
    age_seconds: number | null;
    newest_data_age_seconds?: number | null;
    oldest_data_age_seconds?: number | null;
    sources: FreshnessSourceItem[];
  };
  incidents: FreshnessIncidentItem[];
}

async function authenticatedFetch(url: string, options: RequestInit = {}): Promise<Response> {
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');
  const token = localStorage.getItem('smart_cassavas_token');
  if (token) headers.set('Authorization', `Bearer ${token}`);
  return fetch(url, { ...options, headers });
}

const API_BASE = '/api/admin/cicd';

export interface DashboardBundleData {
  overview: CicdOverviewStats;
  pipelines: PipelineItem[];
  deployments: DeploymentItem[];
  environments: EnvironmentItem[];
  health: SystemHealthStatus;
  security?: SecurityAuditData;
  activities: ActivityItem[];
  branches: string[];
  freshness?: FreshnessOverviewData;
}

export const cicdApi = {
  async getDashboardBundle(filters?: { status?: string; branch?: string; workflow?: string; search?: string }, force = false): Promise<DashboardBundleData> {
    const params = new URLSearchParams();
    if (filters?.status && filters.status !== 'all') params.append('status', filters.status);
    if (filters?.branch && filters.branch !== 'all') params.append('branch', filters.branch);
    if (filters?.workflow && filters.workflow !== 'all') params.append('workflow', filters.workflow);
    if (filters?.search) params.append('search', filters.search);
    if (force) params.append('force', '1');

    const res = await authenticatedFetch(`${API_BASE}/bundle?${params.toString()}`);
    if (!res.ok) throw new Error('Không thể tải dữ liệu CI/CD');
    const json = await res.json();
    return json.data;
  },

  async getOverview(): Promise<CicdOverviewStats> {
    const res = await authenticatedFetch(`${API_BASE}/overview`);
    if (!res.ok) throw new Error('Không thể tải dữ liệu tổng quan CI/CD');
    const json = await res.json();
    return json.data;
  },

  async getPipelines(filters?: { status?: string; branch?: string; workflow?: string; search?: string }): Promise<PipelineItem[]> {
    const params = new URLSearchParams();
    if (filters?.status && filters.status !== 'all') params.append('status', filters.status);
    if (filters?.branch && filters.branch !== 'all') params.append('branch', filters.branch);
    if (filters?.workflow && filters.workflow !== 'all') params.append('workflow', filters.workflow);
    if (filters?.search) params.append('search', filters.search);

    const res = await authenticatedFetch(`${API_BASE}/pipelines?${params.toString()}`);
    if (!res.ok) throw new Error('Không thể tải danh sách pipelines');
    const json = await res.json();
    return json.data;
  },

  async getPipelineDetail(id: string): Promise<PipelineItem> {
    const res = await authenticatedFetch(`${API_BASE}/pipelines/${id}`);
    if (!res.ok) throw new Error(`Không tìm thấy pipeline #${id}`);
    const json = await res.json();
    return json.data;
  },

  async getPipelineJobs(id: string): Promise<JobItem[]> {
    const res = await authenticatedFetch(`${API_BASE}/pipelines/${id}/jobs`);
    if (!res.ok) throw new Error(`Không thể tải jobs của pipeline #${id}`);
    const json = await res.json();
    return json.data;
  },

  async getPipelineLogs(id: string, jobId?: string): Promise<string> {
    const url = jobId ? `${API_BASE}/pipelines/${id}/logs?job_id=${jobId}` : `${API_BASE}/pipelines/${id}/logs`;
    const res = await authenticatedFetch(url);
    if (!res.ok) throw new Error(`Không thể tải logs của pipeline #${id}`);
    const json = await res.json();
    return json.data?.logs || 'Không có dữ liệu log.';
  },

  async getDeployments(): Promise<DeploymentItem[]> {
    const res = await authenticatedFetch(`${API_BASE}/deployments`);
    if (!res.ok) throw new Error('Không thể tải lịch sử deployments');
    const json = await res.json();
    return json.data;
  },

  async getEnvironments(): Promise<EnvironmentItem[]> {
    const res = await authenticatedFetch(`${API_BASE}/environments`);
    if (!res.ok) throw new Error('Không thể tải danh sách environments');
    const json = await res.json();
    return json.data;
  },

  async getHealth(): Promise<SystemHealthStatus> {
    const res = await authenticatedFetch(`${API_BASE}/health`);
    if (!res.ok) throw new Error('Không thể tải trạng thái sức khỏe hệ thống');
    const json = await res.json();
    return json.data;
  },

  async getSecurity(): Promise<SecurityAuditData> {
    const res = await authenticatedFetch(`${API_BASE}/security`);
    if (!res.ok) throw new Error('Không thể tải kết quả kiểm tra bảo mật');
    const json = await res.json();
    return json.data;
  },

  async getActivities(force = false): Promise<ActivityItem[]> {
    const url = force ? `${API_BASE}/activities?force=1` : `${API_BASE}/activities`;
    const res = await authenticatedFetch(url);
    if (!res.ok) throw new Error('Không thể tải hoạt động gần đây');
    const json = await res.json();
    return json.data;
  },

  async getBranches(): Promise<string[]> {
    const res = await authenticatedFetch(`${API_BASE}/branches`);
    if (!res.ok) return ['main'];
    const json = await res.json();
    return json.data || ['main'];
  },

  async runPipeline(payload: { workflow: string; branch?: string; environment?: string; inputs?: Record<string, string> }): Promise<{ success: boolean; message: string }> {
    const res = await authenticatedFetch(`${API_BASE}/pipelines/run`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.message || 'Không thể kích hoạt pipeline');
    return json;
  },

  async retryPipeline(id: string): Promise<{ success: boolean; message: string }> {
    const res = await authenticatedFetch(`${API_BASE}/pipelines/${id}/retry`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.message || 'Không thể chạy lại pipeline');
    return json;
  },

  async cancelPipeline(id: string): Promise<{ success: boolean; message: string }> {
    const res = await authenticatedFetch(`${API_BASE}/pipelines/${id}/cancel`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.message || 'Không thể hủy pipeline');
    return json;
  },

  async deploy(environment: string, imageTag?: string): Promise<{ success: boolean; message: string }> {
    const res = await authenticatedFetch(`${API_BASE}/deploy`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ environment, image_tag: imageTag }),
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.message || 'Không thể gửi lệnh deployment');
    return json;
  },

  async rollback(environment: string, targetVersion: string): Promise<{ success: boolean; message: string }> {
    const res = await authenticatedFetch(`${API_BASE}/rollback`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ environment, target_version: targetVersion }),
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.message || 'Không thể kích hoạt rollback');
    return json;
  },

  async getFreshness(force = false): Promise<FreshnessOverviewData> {
    const res = await authenticatedFetch(`/api/monitoring/freshness${force ? '?force=1' : ''}`);
    if (!res.ok) throw new Error('Không thể tải dữ liệu Freshness Observability');
    return await res.json();
  },
};
