import { TicketDialog } from '../TicketDialog';
import React from 'react';
import { AlertTriangle, X, Check } from 'lucide-react';

interface ConfirmDialogProps {
  variant?: 'default' | 'feature';
  isOpen: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  isDangerous?: boolean;
  isLoading?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

export const ConfirmDialog: React.FC<ConfirmDialogProps> = ({
  variant = 'default',
  isOpen,
  title,
  message,
  confirmLabel = 'Xác nhận',
  cancelLabel = 'Hủy bỏ',
  isDangerous = false,
  isLoading = false,
  onConfirm,
  onCancel,
}) => {
  if (!isOpen) return null;

  if (variant === 'feature') return <TicketDialog variant="feature" open={isOpen} title={title} busy={isLoading} onClose={onCancel} footer={<footer className="flex flex-wrap justify-end gap-3 border-t p-4">
    <button type="button" disabled={isLoading} onClick={onCancel} className="border border-slate-300 bg-white px-4 text-slate-700">{cancelLabel}</button>
    <button type="button" disabled={isLoading} onClick={onConfirm} className={`px-4 text-white ${isDangerous ? 'bg-rose-600' : 'bg-neutral-900'}`}>{isLoading ? 'Đang xử lý…' : confirmLabel}</button>
  </footer>}><p className="text-sm text-slate-700">{message}</p></TicketDialog>;

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center p-4">
      {/* Backdrop */}
      <div
        onClick={!isLoading ? onCancel : undefined}
        className="fixed inset-0 bg-neutral-950/50 backdrop-blur-sm transition-opacity animate-in fade-in"
      />

      {/* Dialog Card */}
      <div className="relative w-full max-w-md bg-white/95 backdrop-blur-xl border border-white/80 rounded-2xl p-6 shadow-2xl animate-in zoom-in-95 text-neutral-900">
        <div className="flex items-start gap-4">
          <div
            className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${
              isDangerous ? 'bg-rose-100 text-rose-600' : 'bg-amber-100 text-amber-600'
            }`}
          >
            <AlertTriangle className="w-5 h-5" />
          </div>

          <div className="flex-1 min-w-0">
            <h3 className="text-base font-bold text-neutral-900 leading-tight">{title}</h3>
            <p className="text-xs sm:text-sm text-neutral-600 mt-2 leading-relaxed">{message}</p>
          </div>

          <button
            type="button"
            disabled={isLoading}
            onClick={onCancel}
            className="p-1 text-neutral-400 hover:text-neutral-700 rounded-lg transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="mt-6 flex items-center justify-end gap-2.5">
          <button
            type="button"
            disabled={isLoading}
            onClick={onCancel}
            className="px-4 py-2 text-xs font-semibold text-neutral-700 hover:bg-neutral-100 rounded-lg transition-colors cursor-pointer"
          >
            {cancelLabel}
          </button>

          <button
            type="button"
            disabled={isLoading}
            onClick={onConfirm}
            className={`px-4 py-2 text-xs font-semibold text-white rounded-lg flex items-center gap-2 transition-all cursor-pointer shadow-sm ${
              isDangerous
                ? 'bg-rose-600 hover:bg-rose-700 active:scale-95'
                : 'bg-neutral-900 hover:bg-neutral-800 active:scale-95'
            }`}
          >
            {isLoading ? (
              <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
            ) : (
              <Check className="w-3.5 h-3.5" />
            )}
            <span>{confirmLabel}</span>
          </button>
        </div>
      </div>
    </div>
  );
};
