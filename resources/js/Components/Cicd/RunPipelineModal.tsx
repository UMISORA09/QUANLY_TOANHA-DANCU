import React, { useState, useEffect } from 'react';
import {
  Play,
  X,
  AlertTriangle,
  GitBranch,
  Layers,
  Server,
  ShieldCheck,
  Tag,
  CheckCircle2,
  Terminal,
  RotateCcw,
  Cloud,
} from 'lucide-react';

interface RunPipelineModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (payload: { workflow: string; branch: string; environment: string; inputs?: Record<string, string> }) => Promise<void>;
  availableBranches?: string[];
}

interface WorkflowDefinition {
  id: string;
  name: string;
  shortDesc: string;
  targetEnv: string;
  envBadge: { label: string; bg: string; text: string; border: string };
  refType: 'branch' | 'tag';
  refLabel: string;
  refPlaceholder: string;
  defaultRef: string;
  stages: string[];
}

const WORKFLOW_DEFINITIONS: WorkflowDefinition[] = [
  {
    id: 'ci.yml',
    name: 'CI - Kiểm thử Toàn diện & E2E (Continuous Integration)',
    shortDesc: 'Kiểm tra Pint Lint, PHPUnit (MySQL 8.0), Playwright E2E test, build Vite frontend và kiểm tra bảo mật.',
    targetEnv: 'GitHub Actions Runner (CI Container)',
    envBadge: { label: 'CI Runner / E2E Gate', bg: 'bg-sky-50', text: 'text-sky-700', border: 'border-sky-200' },
    refType: 'branch',
    refLabel: 'Nhánh Git kiểm thử (Git Branch)',
    refPlaceholder: 'Chọn nhánh cần chạy CI',
    defaultRef: 'main',
    stages: ['PHP Pint Lint', 'PHPUnit (MySQL 8.0)', 'Playwright E2E Tests', 'Vite Build', 'Security Audit', 'Docker Smoke Test'],
  },
  {
    id: 'vercel.yml',
    name: 'CD - Triển khai Vercel Platform & Serverless (Vercel CD)',
    shortDesc: 'Cổng kiểm định chất lượng, thực thi migration CSDL production và deploy ứng dụng lên Vercel Serverless.',
    targetEnv: 'Vercel Platform (Production / Preview)',
    envBadge: { label: 'Vercel Serverless', bg: 'bg-neutral-900', text: 'text-white', border: 'border-neutral-700' },
    refType: 'branch',
    refLabel: 'Nhánh Git triển khai (Git Branch)',
    refPlaceholder: 'Chọn nhánh để deploy lên Vercel',
    defaultRef: 'main',
    stages: ['CI Gate (Lint/Build/PHPUnit)', 'Production DB Migration Gate', 'Vercel CLI Pull/Build', 'Deploy Serverless', 'Freshness & Health Probing'],
  },
  {
    id: 'staging.yml',
    name: 'CD - Triển khai máy chủ Staging (Docker SSH)',
    shortDesc: 'Đóng gói Docker production image đa tầng, đẩy lên GHCR, deploy container và kiểm tra sức khỏe trên Staging.',
    targetEnv: 'Staging Server (Docker Compose)',
    envBadge: { label: 'Staging Server', bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200' },
    refType: 'branch',
    refLabel: 'Nhánh Git đóng gói & triển khai (Git Branch)',
    refPlaceholder: 'Chọn nhánh để deploy staging',
    defaultRef: 'main',
    stages: ['CI Gate', 'Build & Push GHCR (staging)', 'Deploy SSH Staging', 'Health & Smoke Verification'],
  },
  {
    id: 'production.yml',
    name: 'CD - Phát hành Production Release (Release Deployment)',
    shortDesc: 'Xác thực kiểm thử nghiêm ngặt, gắn thẻ phát hành, đóng gói GHCR, bảo vệ môi trường và deploy máy chủ Production.',
    targetEnv: 'Production Server (Cổng phê duyệt nghiêm ngặt)',
    envBadge: { label: 'Production Server', bg: 'bg-rose-50', text: 'text-rose-700', border: 'border-rose-200' },
    refType: 'branch',
    refLabel: 'Nhánh Git phát hành (Git Branch)',
    refPlaceholder: 'Chọn nhánh phát hành (mặc định main / master)',
    defaultRef: 'main',
    stages: ['Production CI Gate', 'Build & Push GHCR', 'Environment Protection Gate', 'Deploy SSH Production', 'Health, Smoke & Freshness'],
  },
  {
    id: 'security.yml',
    name: 'Security - Quét bảo mật toàn diện (Vulnerability Scanner)',
    shortDesc: 'Quét rò rỉ secret credentials (Gitleaks), lỗ hổng CVE thư viện PHP/Node và quét bảo mật container Docker (Trivy).',
    targetEnv: 'Security Scanner Runner',
    envBadge: { label: 'Security Gate', bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200' },
    refType: 'branch',
    refLabel: 'Nhánh Git cần quét (Git Branch)',
    refPlaceholder: 'Chọn nhánh kiểm tra an ninh',
    defaultRef: 'main',
    stages: ['Gitleaks Secret Scan', 'Composer Dependency Audit', 'NPM Package Audit', 'Trivy Container Scan', 'Security Gate Evaluation'],
  },
  {
    id: 'rollback.yml',
    name: 'Rollback - Phục hồi hệ thống khẩn cấp (Safe Rollback)',
    shortDesc: 'Khôi phục ngay lập tức về image ổn định trước đó (bảo toàn 100% dữ liệu CSDL) hoặc rollback Vercel deployment.',
    targetEnv: 'Production / Staging / Vercel',
    envBadge: { label: 'Rollback Recovery', bg: 'bg-rose-100', text: 'text-rose-800', border: 'border-rose-300' },
    refType: 'branch',
    refLabel: 'Nhánh Git điều phối (Dispatch Branch)',
    refPlaceholder: 'Chọn nhánh điều phối rollback',
    defaultRef: 'main',
    stages: ['Xác thực cấu hình máy chủ', 'Kiểm tra image sao lưu trước đó', 'Triển khai lại PREVIOUS_IMAGE', 'Xác minh Health Check sau phục hồi'],
  },
];

export const RunPipelineModal: React.FC<RunPipelineModalProps> = ({
  isOpen,
  onClose,
  onSubmit,
  availableBranches = ['main', 'DangNguyen/CI-CD', 'DangNguyen/amenity-management'],
}) => {
  const [selectedWorkflowId, setSelectedWorkflowId] = useState<string>('ci.yml');
  const [targetRef, setTargetRef] = useState<string>('main');
  const [isCustomRef, setIsCustomRef] = useState<boolean>(false);
  const [vercelEnv, setVercelEnv] = useState<'production' | 'preview'>('production');
  const [rollbackEnv, setRollbackEnv] = useState<'production' | 'staging' | 'vercel'>('production');
  const [releaseTag, setReleaseTag] = useState<string>('v1.0.0');
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const currentWorkflow =
    WORKFLOW_DEFINITIONS.find((w) => w.id === selectedWorkflowId) || WORKFLOW_DEFINITIONS[0];

  const isProduction =
    currentWorkflow.id === 'production.yml' || (currentWorkflow.id === 'vercel.yml' && vercelEnv === 'production');

  // Nhóm các nhánh theo thành viên trong nhóm để trực quan và dễ quản lý
  const groupedBranches = React.useMemo(() => {
    const groups: Record<string, string[]> = {
      'Nhánh chính (Core Branches)': [],
      'Đặng Nguyên (DangNguyen)': [],
      'Quốc Tín (QuocTin)': [],
      'Xuân Hòa (xuanhoa)': [],
      'Thành viên khác / Features': [],
    };

    availableBranches.forEach((b) => {
      if (b === 'main' || b === 'master') {
        groups['Nhánh chính (Core Branches)'].push(b);
      } else if (b.startsWith('DangNguyen/')) {
        groups['Đặng Nguyên (DangNguyen)'].push(b);
      } else if (b.startsWith('QuocTin/')) {
        groups['Quốc Tín (QuocTin)'].push(b);
      } else if (b.startsWith('xuanhoa/')) {
        groups['Xuân Hòa (xuanhoa)'].push(b);
      } else {
        groups['Thành viên khác / Features'].push(b);
      }
    });

    return Object.entries(groups).filter(([_, list]) => list.length > 0);
  }, [availableBranches]);

  // Đồng bộ giá trị ref mặc định khi đổi workflow
  useEffect(() => {
    const defaultBranch = availableBranches.includes('DangNguyen/CI-CD')
      ? 'DangNguyen/CI-CD'
      : availableBranches.includes('main')
      ? 'main'
      : availableBranches[0] || 'main';
    setTargetRef(defaultBranch);
    setIsCustomRef(false);
  }, [selectedWorkflowId, availableBranches]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!targetRef.trim()) {
      setError('Vui lòng nhập hoặc chọn nhánh thực thi.');
      return;
    }

    setIsLoading(true);
    setError(null);
    try {
      let environment = 'development';
      const inputs: Record<string, string> = {};

      if (currentWorkflow.id === 'production.yml') {
        environment = 'production';
        inputs.release_tag = releaseTag.trim() || 'v1.0.0';
      } else if (currentWorkflow.id === 'staging.yml') {
        environment = 'staging';
        inputs.image_tag = 'staging';
      } else if (currentWorkflow.id === 'vercel.yml') {
        environment = vercelEnv;
        inputs.target_env = vercelEnv;
      } else if (currentWorkflow.id === 'rollback.yml') {
        environment = rollbackEnv;
        inputs.environment = rollbackEnv;
        inputs.target_version = 'previous';
        inputs.reason = 'Kích hoạt từ Modal Run Pipeline';
      }

      await onSubmit({
        workflow: currentWorkflow.id,
        branch: targetRef.trim(),
        environment,
        inputs,
      });
      onClose();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop */}
      <div
        onClick={!isLoading ? onClose : undefined}
        className="fixed inset-0 bg-neutral-950/60 backdrop-blur-xs transition-opacity animate-in fade-in"
      />

      {/* Modal Dialog */}
      <div className="relative w-full max-w-xl bg-white border border-slate-200/80 rounded-3xl p-6 sm:p-7 shadow-2xl animate-in zoom-in-95 text-neutral-900 max-h-[92vh] overflow-y-auto">
        {/* Header */}
        <div className="flex items-center justify-between pb-4 border-b border-slate-100">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-2xl bg-neutral-900 text-white shadow-xs">
              <Play className="w-5 h-5 text-emerald-400" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h3 className="text-base sm:text-lg font-black text-neutral-900">
                  Kích hoạt Chạy Pipeline (Run Pipeline)
                </h3>
                <span className="px-2 py-0.5 rounded-md text-[10px] font-bold font-mono bg-slate-100 text-slate-700 border border-slate-200">
                  DevOps Automation
                </span>
              </div>
              <p className="text-xs text-slate-500">
                Khởi chạy quy trình kiểm thử, build Docker hoặc triển khai Vercel
              </p>
            </div>
          </div>

          <button
            type="button"
            disabled={isLoading}
            onClick={onClose}
            className="p-1.5 text-slate-400 hover:text-neutral-700 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {error && (
          <div className="mt-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-800 flex items-center gap-2 font-medium">
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="mt-5 space-y-4 text-xs">
          {/* Workflow Picker */}
          <div className="space-y-1.5">
            <label className="font-bold text-neutral-800 flex items-center justify-between">
              <span className="flex items-center gap-1.5">
                <Layers className="w-3.5 h-3.5 text-sky-600" />
                <span>Quy trình thực thi (GitHub Actions Workflow)</span>
              </span>
              <span className="text-[11px] text-slate-400 font-mono">.github/workflows/</span>
            </label>
            <select
              value={selectedWorkflowId}
              onChange={(e) => setSelectedWorkflowId(e.target.value)}
              className="w-full p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-neutral-900 font-semibold focus:outline-none focus:ring-2 focus:ring-sky-200 cursor-pointer"
            >
              {WORKFLOW_DEFINITIONS.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name} ({w.id})
                </option>
              ))}
            </select>
            <p className="text-[11px] text-slate-500 leading-relaxed pt-0.5">
              {currentWorkflow.shortDesc}
            </p>
          </div>

          {/* Vercel Environment Selector */}
          {currentWorkflow.id === 'vercel.yml' && (
            <div className="p-3.5 rounded-2xl bg-neutral-900 text-white space-y-2 animate-in fade-in">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2 font-bold text-xs">
                  <Cloud className="w-4 h-4 text-sky-400" />
                  <span>Chọn môi trường Vercel:</span>
                </div>
                <span className="text-[10px] text-neutral-400 font-mono">target_env</span>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={() => setVercelEnv('production')}
                  className={`p-2.5 rounded-xl border text-center transition-all cursor-pointer ${
                    vercelEnv === 'production'
                      ? 'bg-emerald-500/20 border-emerald-500 text-emerald-300 font-bold'
                      : 'bg-neutral-800 border-neutral-700 text-neutral-300 hover:bg-neutral-700'
                  }`}
                >
                  <div className="text-xs">Production</div>
                  <div className="text-[10px] opacity-75">Deploy chính thức</div>
                </button>
                <button
                  type="button"
                  onClick={() => setVercelEnv('preview')}
                  className={`p-2.5 rounded-xl border text-center transition-all cursor-pointer ${
                    vercelEnv === 'preview'
                      ? 'bg-sky-500/20 border-sky-500 text-sky-300 font-bold'
                      : 'bg-neutral-800 border-neutral-700 text-neutral-300 hover:bg-neutral-700'
                  }`}
                >
                  <div className="text-xs">Preview</div>
                  <div className="text-[10px] opacity-75">Deploy kiểm thử xem trước</div>
                </button>
              </div>
            </div>
          )}

          {/* Rollback Environment Selector */}
          {currentWorkflow.id === 'rollback.yml' && (
            <div className="p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-950 space-y-2 animate-in fade-in">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2 font-bold text-xs">
                  <RotateCcw className="w-4 h-4 text-rose-600" />
                  <span>Chọn hạ tầng khôi phục (Rollback Target):</span>
                </div>
                <span className="text-[10px] text-rose-700 font-mono">environment</span>
              </div>
              <div className="grid grid-cols-3 gap-2">
                {(['production', 'staging', 'vercel'] as const).map((env) => (
                  <button
                    key={env}
                    type="button"
                    onClick={() => setRollbackEnv(env)}
                    className={`p-2 rounded-xl border text-center transition-all cursor-pointer text-xs uppercase font-bold ${
                      rollbackEnv === env
                        ? 'bg-rose-600 text-white border-rose-600 shadow-xs'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-rose-50'
                    }`}
                  >
                    {env}
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Production Release Tag Input */}
          {currentWorkflow.id === 'production.yml' && (
            <div className="space-y-1.5 animate-in fade-in">
              <label className="font-bold text-neutral-800 flex items-center justify-between">
                <span className="flex items-center gap-1.5">
                  <Tag className="w-3.5 h-3.5 text-rose-600" />
                  <span>Release Tag (Phiên bản phát hành)</span>
                </span>
                <span className="text-[11px] text-slate-400 font-mono">release_tag</span>
              </label>
              <input
                type="text"
                value={releaseTag}
                onChange={(e) => setReleaseTag(e.target.value)}
                placeholder="Ví dụ: v1.0.0 hoặc v1.0.1"
                className="w-full p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-neutral-900 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-rose-200"
              />
            </div>
          )}

          {/* Target Environment Badge */}
          <div className="p-3 rounded-2xl bg-slate-50/80 border border-slate-200/80 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Server className="w-4 h-4 text-slate-500" />
              <div>
                <div className="text-[11px] text-slate-500 font-medium">Môi trường đích (Target Environment)</div>
                <div className="text-xs font-bold text-neutral-800">{currentWorkflow.targetEnv}</div>
              </div>
            </div>
            <span
              className={`px-2.5 py-1 rounded-lg text-[10px] font-bold border ${currentWorkflow.envBadge.bg} ${currentWorkflow.envBadge.text} ${currentWorkflow.envBadge.border}`}
            >
              {currentWorkflow.envBadge.label}
            </span>
          </div>

          {/* Git Branch Selector */}
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <label className="font-bold text-neutral-800 flex items-center gap-1.5">
                <GitBranch className="w-3.5 h-3.5 text-sky-600" />
                <span>{currentWorkflow.refLabel}</span>
              </label>

              <button
                type="button"
                onClick={() => setIsCustomRef(!isCustomRef)}
                className="text-[11px] text-sky-600 hover:text-sky-700 font-semibold cursor-pointer"
              >
                {isCustomRef ? 'Chọn từ danh sách có sẵn' : '+ Nhập tên nhánh khác'}
              </button>
            </div>

            {!isCustomRef ? (
              <select
                value={targetRef}
                onChange={(e) => setTargetRef(e.target.value)}
                className="w-full p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-neutral-900 font-mono font-semibold focus:outline-none focus:ring-2 focus:ring-sky-200 cursor-pointer"
              >
                {groupedBranches.map(([groupName, branches]) => (
                  <optgroup key={groupName} label={groupName} className="font-sans font-bold text-slate-800 bg-slate-100">
                    {branches.map((b) => (
                      <option key={b} value={b} className="font-mono font-medium text-neutral-900 bg-white">
                        {b} {b === 'DangNguyen/CI-CD' ? '★ (Nhánh hiện tại)' : b === 'main' ? '★ (Nhánh chính)' : ''}
                      </option>
                    ))}
                  </optgroup>
                ))}
              </select>
            ) : (
              <input
                type="text"
                value={targetRef}
                onChange={(e) => setTargetRef(e.target.value)}
                placeholder={currentWorkflow.refPlaceholder}
                className="w-full p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-neutral-900 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-sky-200"
              />
            )}
          </div>

          {/* Stages Preview */}
          <div className="space-y-1.5 pt-1">
            <div className="text-[11px] font-bold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
              <Terminal className="w-3 h-3 text-slate-400" />
              <span>Các giai đoạn sẽ thực thi (Stages):</span>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {currentWorkflow.stages.map((stage, idx) => (
                <span
                  key={idx}
                  className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-slate-100 border border-slate-200 text-slate-700 text-[10px] font-medium"
                >
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                  <span>{stage}</span>
                </span>
              ))}
            </div>
          </div>

          {/* Production Warning Banner */}
          {isProduction && (
            <div className="p-3.5 rounded-2xl bg-amber-50 border border-amber-300/80 text-amber-900 space-y-1 animate-in fade-in">
              <div className="flex items-center gap-2 font-bold text-xs text-amber-950">
                <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0" />
                <span>CẢNH BÁO MÔI TRƯỜNG PRODUCTION!</span>
              </div>
              <p className="text-[11px] text-amber-800 leading-relaxed pl-6">
                Bạn đang chuẩn bị kích hoạt pipeline phát hành phiên bản chính thức tới toàn bộ cư dân và ban quản lý. Lệnh này yêu cầu xác nhận phê duyệt (Approval Gate) và sẽ tự động Rollback nếu kiểm tra sức khỏe thất bại.
              </p>
            </div>
          )}

          {/* Action Buttons */}
          <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-2.5">
            <button
              type="button"
              disabled={isLoading}
              onClick={onClose}
              className="px-4 py-2 text-xs font-semibold text-neutral-700 hover:bg-slate-100 rounded-xl transition-colors cursor-pointer"
            >
              Hủy bỏ
            </button>

            <button
              type="submit"
              disabled={isLoading}
              className={`px-5 py-2 text-xs font-bold text-white rounded-xl flex items-center gap-2 transition-all cursor-pointer shadow-md active:scale-95 ${
                isProduction
                  ? 'bg-rose-600 hover:bg-rose-700'
                  : 'bg-neutral-900 hover:bg-neutral-800'
              }`}
            >
              {isLoading ? (
                <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
              ) : (
                <Play className="w-3.5 h-3.5 text-emerald-400" />
              )}
              <span>{isProduction ? 'Xác nhận Chạy Production' : 'Kích hoạt Pipeline'}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
