import React from 'react';
import { Ruler, X, RotateCcw, Check } from 'lucide-react';
import { PascalMeasurement } from './types';

interface PascalMeasureOverlayProps {
  isMeasuring: boolean;
  measurement: PascalMeasurement | null;
  measureStep: number; // 0 = idle, 1 = picked start, 2 = picked end
  onResetMeasurement: () => void;
  onToggleMeasure: () => void;
}

export const PascalMeasureOverlay: React.FC<PascalMeasureOverlayProps> = ({
  isMeasuring,
  measurement,
  measureStep,
  onResetMeasurement,
  onToggleMeasure,
}) => {
  if (!isMeasuring) return null;

  return (
    <div className="absolute top-4 left-4 z-30 bg-slate-900/90 backdrop-blur-md border border-amber-500/30 rounded-2xl p-3 text-white shadow-2xl flex flex-col gap-2 min-w-[260px] animate-in fade-in slide-in-from-top-2 duration-150">
      <div className="flex items-center justify-between border-b border-white/10 pb-2">
        <div className="flex items-center gap-2">
          <div className="w-6 h-6 rounded-lg bg-amber-500/20 text-amber-400 flex items-center justify-center">
            <Ruler className="w-3.5 h-3.5" />
          </div>
          <span className="text-xs font-bold uppercase tracking-wider text-amber-400">
            Thước Đo Kiến Trúc
          </span>
        </div>
        <button
          type="button"
          onClick={onToggleMeasure}
          className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition-colors cursor-pointer"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      <div className="text-[11px] text-slate-300">
        {measureStep === 0 && (
          <p className="text-amber-200">
            👉 Nhấp chuột lên một điểm trên mô hình để chọn <strong>Điểm đầu</strong>.
          </p>
        )}
        {measureStep === 1 && (
          <p className="text-sky-300 animate-pulse">
            👉 Nhấp chuột tiếp theo để chọn <strong>Điểm cuối</strong>.
          </p>
        )}
        {measureStep === 2 && measurement && (
          <div className="space-y-1.5 bg-slate-950/60 p-2.5 rounded-xl border border-white/5">
            <div className="flex items-center justify-between">
              <span className="text-slate-400">Khoảng cách thực tế:</span>
              <span className="text-base font-bold font-mono text-emerald-400">
                {measurement.distanceMeters.toFixed(2)} m
              </span>
            </div>
            <div className="text-[10px] text-slate-400 grid grid-cols-2 gap-1 border-t border-white/5 pt-1">
              <span>Độ chênh cao Y:</span>
              <span className="font-mono text-slate-200 text-right">
                {Math.abs(measurement.end[1] - measurement.start[1]).toFixed(2)} m
              </span>
              <span>Khoảng cách mặt bằng XZ:</span>
              <span className="font-mono text-slate-200 text-right">
                {Math.hypot(
                  measurement.end[0] - measurement.start[0],
                  measurement.end[2] - measurement.start[2]
                ).toFixed(2)}{' '}
                m
              </span>
            </div>
          </div>
        )}
      </div>

      <div className="flex items-center gap-2 pt-1">
        {measureStep > 0 && (
          <button
            type="button"
            onClick={onResetMeasurement}
            className="flex-1 py-1.5 px-2 rounded-xl bg-white/10 hover:bg-white/15 text-slate-200 text-[11px] font-medium transition-colors flex items-center justify-center gap-1 cursor-pointer"
          >
            <RotateCcw className="w-3 h-3" />
            <span>Đo lại</span>
          </button>
        )}
        <button
          type="button"
          onClick={onToggleMeasure}
          className="flex-1 py-1.5 px-2 rounded-xl bg-amber-600/80 hover:bg-amber-600 text-white text-[11px] font-medium transition-colors text-center cursor-pointer"
        >
          Xong
        </button>
      </div>
    </div>
  );
};
