import React, { useState } from 'react';
import { RotateCcw, X, AlertTriangle, ShieldCheck, Check, Info, Server, Sparkles } from 'lucide-react';

interface RollbackModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (targetVersion: string) => Promise<void>;
  currentVersion?: string;
  environment?: string;
}

export const RollbackModal: React.FC<RollbackModalProps> = ({
  isOpen,
  onClose,
  onSubmit,
  currentVersion = 'v1.0.0',
  environment = 'production',
}) => {
  const [rollbackMode, setRollbackMode] = useState<'previous' | 'custom'>('previous');
  const [customVersion, setCustomVersion] = useState<string>('');
  const [confirmedSafe, setConfirmedSafe] = useState<boolean>(false);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  if (!isOpen) return null;

  const targetVersion = rollbackMode === 'previous' ? 'previous' : customVersion.trim();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!confirmedSafe) return;

    if (rollbackMode === 'custom' && !customVersion.trim()) {
      setError('Vui lòng nhập phiên bản hoặc image tag cần khôi phục.');
      return;
    }

    setIsLoading(true);
    setError(null);
    try {
      await onSubmit(targetVersion);
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
      <div className="relative w-full max-w-lg bg-white/95 backdrop-blur-xl border border-white/80 rounded-3xl p-6 sm:p-7 shadow-2xl animate-in zoom-in-95 text-neutral-900">
        {/* Header */}
        <div className="flex items-center justify-between pb-4 border-b border-slate-100">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-2xl bg-rose-100 text-rose-700">
              <RotateCcw className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base sm:text-lg font-black text-neutral-900">
                Khôi phục Phiên bản (Rollback {environment.toUpperCase()})
              </h3>
              <p className="text-xs text-slate-500">
                Phục hồi an toàn về Docker Image đã kiểm định trước đó
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
          {/* Current Version Banner */}
          <div className="p-3 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between">
            <span className="text-slate-500 font-medium">Phiên bản đang hoạt động:</span>
            <span className="font-mono font-black text-sm text-neutral-900 bg-white px-2.5 py-0.5 rounded border border-slate-200 shadow-2xs">
              {currentVersion || 'Chưa xác định'}
            </span>
          </div>

          {/* Rollback Target Selection */}
          <div className="space-y-2">
            <label className="font-bold text-neutral-800 block">
              Chọn cơ chế khôi phục (Rollback Target):
            </label>

            <div className="space-y-2">
              <label
                className={`p-3 rounded-xl border flex items-center justify-between cursor-pointer transition-all ${
                  rollbackMode === 'previous'
                    ? 'bg-rose-50/70 border-rose-300 ring-2 ring-rose-100'
                    : 'bg-white hover:bg-slate-50 border-slate-200'
                }`}
              >
                <div className="flex items-center gap-3">
                  <input
                    type="radio"
                    name="rollback_mode"
                    value="previous"
                    checked={rollbackMode === 'previous'}
                    onChange={() => setRollbackMode('previous')}
                    className="accent-rose-600"
                  />
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-mono font-black text-neutral-900">Phiên bản ổn định trước đó (PREVIOUS_IMAGE)</span>
                      <span className="text-[10px] bg-emerald-100 text-emerald-800 font-bold px-1.5 py-0.5 rounded">Khuyên dùng</span>
                    </div>
                    <span className="text-[11px] text-slate-500 block">
                      Tự động trích xuất từ file lưu vết của máy chủ, đảm bảo image đã vượt qua health check trước đó.
                    </span>
                  </div>
                </div>
              </label>

              <label
                className={`p-3 rounded-xl border flex items-center justify-between cursor-pointer transition-all ${
                  rollbackMode === 'custom'
                    ? 'bg-rose-50/70 border-rose-300 ring-2 ring-rose-100'
                    : 'bg-white hover:bg-slate-50 border-slate-200'
                }`}
              >
                <div className="flex items-center gap-3">
                  <input
                    type="radio"
                    name="rollback_mode"
                    value="custom"
                    checked={rollbackMode === 'custom'}
                    onChange={() => setRollbackMode('custom')}
                    className="accent-rose-600"
                  />
                  <div>
                    <span className="font-mono font-black text-neutral-900">Chỉ định cụ thể phiên bản / Image Tag</span>
                    <span className="text-[11px] text-slate-500 block">
                      Nhập thủ công phiên bản (ví dụ: v1.0.0, sha-d1bc9af, hoặc deployment-id Vercel)
                    </span>
                  </div>
                </div>
              </label>
            </div>

            {rollbackMode === 'custom' && (
              <div className="pt-2 animate-in fade-in">
                <input
                  type="text"
                  value={customVersion}
                  onChange={(e) => setCustomVersion(e.target.value)}
                  placeholder="Ví dụ: v1.0.0 hoặc ghcr.io/...:staging"
                  className="w-full p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-neutral-900 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-rose-200"
                />
              </div>
            )}
          </div>

          {/* Database Safety Warning */}
          <div className="p-3.5 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 space-y-1.5">
            <div className="flex items-center gap-2 font-bold text-xs">
              <Info className="w-4 h-4 text-amber-600 shrink-0" />
              <span>Chính sách An toàn Cơ sở dữ liệu (Database Preservation Policy)</span>
            </div>
            <p className="text-[11px] text-amber-800 leading-relaxed pl-6">
              Hệ thống sẽ chỉ khôi phục Docker Image ứng dụng và <strong>KHÔNG</strong> tự động rollback cấu trúc bảng database để bảo toàn 100% dữ liệu cư dân thực tế.
            </p>
          </div>

          {/* Explicit Safety Checkbox */}
          <label className="flex items-start gap-2.5 p-3 rounded-xl bg-slate-50 border border-slate-200 cursor-pointer">
            <input
              type="checkbox"
              checked={confirmedSafe}
              onChange={(e) => setConfirmedSafe(e.target.checked)}
              className="mt-0.5 accent-rose-600 w-4 h-4 rounded cursor-pointer"
            />
            <span className="text-xs text-neutral-700 font-medium">
              Tôi xác nhận kích hoạt lệnh Rollback khẩn cấp cho môi trường <strong>{environment}</strong> về <strong>{targetVersion}</strong>.
            </span>
          </label>

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
              disabled={isLoading || !confirmedSafe}
              className="px-5 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-xl flex items-center gap-2 transition-all cursor-pointer shadow-md active:scale-95"
            >
              {isLoading ? (
                <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
              ) : (
                <RotateCcw className="w-3.5 h-3.5" />
              )}
              <span>Xác nhận Rollback</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
