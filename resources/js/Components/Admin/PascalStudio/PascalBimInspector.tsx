import React from 'react';
import {
  X,
  Info,
  Maximize2,
  MapPin,
  Building,
  Layers,
  Thermometer,
  Zap,
  Users,
  CheckCircle2,
  AlertTriangle,
  Compass,
  FileSpreadsheet
} from 'lucide-react';
import { PascalInspectorData } from './types';

interface PascalBimInspectorProps {
  data: PascalInspectorData | null;
  onClose: () => void;
  onFocus: () => void;
}

export const PascalBimInspector: React.FC<PascalBimInspectorProps> = ({
  data,
  onClose,
  onFocus,
}) => {
  if (!data) return null;

  return (
    <div className="bg-slate-900/95 backdrop-blur-xl border border-white/20 rounded-3xl p-4 sm:p-5 text-white shadow-2xl w-80 sm:w-96 max-h-[85vh] overflow-y-auto custom-scrollbar flex flex-col gap-4 animate-in fade-in slide-in-from-right-4 duration-200">
      {/* Header */}
      <div className="flex items-start justify-between border-b border-white/10 pb-3">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-sky-500/20">
            <Building className="w-5 h-5" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-sky-500/20 text-sky-400 border border-sky-500/30">
                {data.code}
              </span>
              <span className="text-[10px] uppercase tracking-wider text-slate-400 font-medium">
                {data.category}
              </span>
            </div>
            <h3 className="font-bold text-sm text-white mt-0.5 leading-tight">{data.name}</h3>
          </div>
        </div>

        <button
          type="button"
          onClick={onClose}
          className="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition-colors cursor-pointer"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      {/* Primary Metrics: Elevation & Area */}
      {(data.elevation || data.areaM2) && (
        <div className="grid grid-cols-2 gap-2 bg-slate-950/60 p-3 rounded-2xl border border-white/5">
          {data.elevation && (
            <div>
              <span className="text-[11px] text-slate-400 block">Cao độ tầng (Elevation)</span>
              <span className="text-sm font-bold font-mono text-sky-400">{data.elevation}</span>
            </div>
          )}
          {data.areaM2 && (
            <div>
              <span className="text-[11px] text-slate-400 block">Diện tích sàn</span>
              <span className="text-sm font-bold font-mono text-emerald-400">
                {data.areaM2.toLocaleString('vi-VN')} m²
              </span>
            </div>
          )}
        </div>
      )}

      {/* Functions & Spaces */}
      {data.functions && data.functions.length > 0 && (
        <div className="space-y-1.5">
          <span className="text-[11px] font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-1.5">
            <Layers className="w-3 h-3 text-sky-400" />
            Công năng & Không gian kiến trúc
          </span>
          <ul className="space-y-1 bg-slate-950/40 p-2.5 rounded-xl border border-white/5 text-xs text-slate-300">
            {data.functions.map((fn, idx) => (
              <li key={idx} className="flex items-start gap-1.5">
                <span className="text-sky-400 mt-0.5">•</span>
                <span>{fn}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* BIM Properties / IoT Parameters */}
      {data.properties && data.properties.length > 0 && (
        <div className="space-y-2">
          <span className="text-[11px] font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-1.5">
            <Info className="w-3 h-3 text-indigo-400" />
            Thông số kỹ thuật & Vận hành
          </span>
          <div className="grid grid-cols-2 gap-2">
            {data.properties.map((prop, idx) => (
              <div
                key={idx}
                className="bg-slate-950/40 p-2.5 rounded-xl border border-white/5 flex flex-col gap-0.5"
              >
                <span className="text-[10px] text-slate-400 truncate">{prop.label}</span>
                <span className="text-xs font-bold text-white font-mono flex items-center gap-1">
                  {prop.value}
                  {prop.unit && <span className="text-[10px] text-slate-400 font-normal">{prop.unit}</span>}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Description */}
      {data.description && (
        <div className="text-xs text-slate-400 leading-relaxed bg-white/5 p-3 rounded-2xl border border-white/5">
          {data.description}
        </div>
      )}

      {/* Actions */}
      <div className="pt-2 border-t border-white/10 flex items-center gap-2">
        <button
          type="button"
          onClick={onFocus}
          className="flex-1 py-2 px-3 rounded-xl bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white font-semibold text-xs transition-all shadow-md shadow-sky-600/20 flex items-center justify-center gap-1.5 cursor-pointer"
        >
          <Maximize2 className="w-3.5 h-3.5" />
          <span>Focus Camera</span>
        </button>
      </div>
    </div>
  );
};
