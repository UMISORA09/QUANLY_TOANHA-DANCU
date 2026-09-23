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
  Box,
  Terminal,
} from 'lucide-react';

interface RunPipelineModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (payload: { workflow: string; branch: string; environment: string }) => Promise<void>;
  availableBranches?: string[];
}

interface WorkflowDefinition {
  id: string;
  name: string;
  shortDesc: string;
  targetEnv: string;
  envBadge: { label: string; bg: string; text: string; border: string };
  refType: 'branch' | 'tag' | 'image_tag';
  refLabel: string;
  refPlaceholder: string;
  defaultRef: string;
  stages: string[];
}

const WORKFLOW_DEFINITIONS: WorkflowDefinition[] = [
  {
    id: 'ci.yml',
    name: 'CI - Kiểm thử & Kiểm tra toàn diện (Continuous Integration)',
    shortDesc: 'Kiểm tra code style, Unit & Feature test trên MySQL 8.0, build Vite frontend và quét bảo mật.',
    targetEnv: 'GitHub Actions Runner (Môi trường test độc lập)',
    envBadge: { label: 'CI Runner / Test Container', bg: 'bg-sky-50', text: 'text-sky-700', border: 'border-sky-200' },
    refType: 'branch',
    refLabel: 'Nhánh Git thực thi (Git Branch)',
    refPlaceholder: 'Chọn nhánh cần chạy kiểm thử',
    defaultRef: 'main',
    stages: ['PHP Pint Lint', 'PHPUnit (MySQL 8.0)', 'Vite Frontend Build', 'Security Audit', 'Docker Smoke Test'],
  },
  {
    id: 'docker.yml',
    name: 'Docker Build & GHCR Publish (Đóng gói Image đa tầng)',
    shortDesc: 'Build Docker production image đa tầng bằng Buildx, tự kiểm tra /health và đẩy lên GitHub Container Registry.',
    targetEnv: 'GitHub Container Registry (ghcr.io)',
    envBadge: { label: 'GHCR Registry / Package', bg: 'bg-indigo-50', text: 'text-indigo-700', border: 'border-indigo-200' },
    refType: 'branch',
    refLabel: 'Nhánh Git đóng gói (Git Branch)',
    refPlaceholder: 'Chọn nhánh để đóng gói image',
    defaultRef: 'main',
    stages: ['Buildx Multi-Stage', 'Local Smoke Test /health', 'Tag Commit SHA & Latest', 'Push GHCR'],
  },
  {
    id: 'staging.yml',
    name: 'CD - Triển khai máy chủ Staging (Staging Deployment)',
    shortDesc: 'Kéo Docker image từ GHCR, khởi động container và chạy health check tự động trên máy chủ Staging.',
    targetEnv: 'Staging Server (staging.cassavas.vn)',
    envBadge: { label: 'Staging Server', bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200' },
    refType: 'image_tag',
    refLabel: 'Phiên bản Docker Image (Image Tag)',
    refPlaceholder: 'Ví dụ: latest hoặc sha-d1bc9af',
    defaultRef: 'latest',
    stages: ['Pull Image GHCR', 'Backup Version Hiện Tại', 'Deploy Container', 'Thăm dò /health'],
  },
  {
    id: 'production.yml',
    name: 'CD - Phát hành Production (Release Deployment - Yêu cầu phê duyệt)',
    shortDesc: 'Triển khai phiên bản phát hành chính thức lên máy chủ tòa nhà, có cổng phê duyệt và tự động rollback nếu lỗi.',
    targetEnv: 'Production Server (cassavas.vn - Hệ thống vận hành thực tế)',
    envBadge: { label: 'Production Server', bg: 'bg-rose-50', text: 'text-rose-700', border: 'border-rose-200' },
    refType: 'tag',
    refLabel: 'Thẻ phiên bản phát hành (Git Release Tag)',
    refPlaceholder: 'Ví dụ: v1.0.0, v1.0.1, v1.1.0',
    defaultRef: 'v1.0.0',
    stages: ['Phê duyệt Environment Gate', 'Deploy Release Image', 'Migrate CSDL An toàn', 'Kiểm tra Sức khỏe /health', 'Tự động Rollback nếu lỗi'],
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
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const currentWorkflow =
    WORKFLOW_DEFINITIONS.find((w) => w.id === selectedWorkflowId) || WORKFLOW_DEFINITIONS[0];

  const isProduction =
    currentWorkflow.id === 'production.yml' || currentWorkflow.id === 'cd-production.yml';

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
    if (currentWorkflow.refType === 'branch') {
      const defaultBranch = availableBranches.includes('main')
        ? 'main'
        : availableBranches[0] || 'main';
      setTargetRef(defaultBranch);
      setIsCustomRef(false);
    } else {
      setTargetRef(currentWorkflow.defaultRef);
    }
  }, [selectedWorkflowId, availableBranches]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!targetRef.trim()) {
      setError('Vui lòng nhập hoặc chọn nhánh/phiên bản thực thi.');
      return;
    }

    setIsLoading(true);
    setError(null);
    try {
      const environment =
        currentWorkflow.id === 'production.yml' || currentWorkflow.id === 'cd-production.yml'
          ? 'production'
          : currentWorkflow.id === 'staging.yml' || currentWorkflow.id === 'cd-staging.yml'
          ? 'staging'
          : 'development';

      await onSubmit({
        workflow: currentWorkflow.id,
        branch: targetRef.trim(),
        environment,
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
                Khởi chạy quy trình kiểm thử, build Docker hoặc triển khai máy chủ
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

          {/* Ref / Branch / Tag Input */}
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <label className="font-bold text-neutral-800 flex items-center gap-1.5">
                {currentWorkflow.refType === 'branch' ? (
                  <GitBranch className="w-3.5 h-3.5 text-sky-600" />
                ) : (
                  <Tag className="w-3.5 h-3.5 text-amber-600" />
                )}
                <span>{currentWorkflow.refLabel}</span>
              </label>

              {currentWorkflow.refType === 'branch' && (
                <button
                  type="button"
                  onClick={() => setIsCustomRef(!isCustomRef)}
                  className="text-[11px] text-sky-600 hover:text-sky-700 font-semibold cursor-pointer"
                >
                  {isCustomRef ? 'Chọn từ danh sách có sẵn' : '+ Nhập tên nhánh khác'}
                </button>
              )}
            </div>

            {currentWorkflow.refType === 'branch' && !isCustomRef ? (
              <select
                value={targetRef}
                onChange={(e) => setTargetRef(e.target.value)}
                className="w-full p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-neutral-900 font-mono font-semibold focus:outline-none focus:ring-2 focus:ring-sky-200 cursor-pointer"
              >
                {groupedBranches.map(([groupName, branches]) => (
                  <optgroup key={groupName} label={groupName} className="font-sans font-bold text-slate-800 bg-slate-100">
                    {branches.map((b) => (
                      <option key={b} value={b} className="font-mono font-medium text-neutral-900 bg-white">
                        {b} {b === 'main' ? '★ (Nhánh chính)' : ''}
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
