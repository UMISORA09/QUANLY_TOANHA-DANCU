import React from 'react';
import { Layers, Sliders, Eye, Minimize2, Maximize2, ChevronsUpDown } from 'lucide-react';
import { PascalLevelMode } from './types';
import { FloorData } from '../Building3DViewer';

interface PascalLevelControlProps {
  levelMode: PascalLevelMode;
  setLevelMode: (mode: PascalLevelMode) => void;
  explodeFactor: number;
  setExplodeFactor: (factor: number) => void;
  selectedLevel: string | null;
  setSelectedLevel: (level: string | null) => void;
  floors: FloorData[];
}

export const PascalLevelControl: React.FC<PascalLevelControlProps> = ({
  levelMode,
  setLevelMode,
  explodeFactor,
  setExplodeFactor,
  selectedLevel,
  setSelectedLevel,
  floors,
}) => {
  return (
    <div className="bg-slate-900/90 backdrop-blur-md border border-white/15 rounded-2xl p-3 text-white shadow-xl flex flex-col gap-2.5 min-w-[280px]">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-white/10 pb-2">
        <div className="flex items-center gap-2">
          <div className="w-6 h-6 rounded-lg bg-sky-500/20 text-sky-400 flex items-center justify-center">
            <Layers className="w-3.5 h-3.5" />
          </div>
          <span className="text-xs font-semibold tracking-wide uppercase text-slate-200">
            Pascal Level System
          </span>
        </div>
        <span className="text-[10px] font-mono text-sky-400 px-1.5 py-0.5 rounded bg-sky-950/60 border border-sky-800/50">
          CAD/BIM
        </span>
      </div>

      {/* Mode Selector Buttons */}
      <div className="grid grid-cols-3 gap-1 bg-slate-950/60 p-1 rounded-xl border border-white/5">
        <button
          type="button"
          onClick={() => {
            setLevelMode('stacked');
            setSelectedLevel(null);
          }}
          className={`py-1.5 px-2 rounded-lg text-xs font-medium transition-all text-center flex flex-col items-center gap-1 cursor-pointer ${
            levelMode === 'stacked'
              ? 'bg-sky-600 text-white shadow-md shadow-sky-600/30'
              : 'text-slate-400 hover:text-slate-200 hover:bg-white/5'
          }`}
          title="Chế độ tầng nguyên khối tiêu chuẩn"
        >
          <Minimize2 className="w-3.5 h-3.5" />
          <span>Stacked</span>
        </button>

        <button
          type="button"
          onClick={() => {
            setLevelMode('exploded');
            setSelectedLevel(null);
          }}
          className={`py-1.5 px-2 rounded-lg text-xs font-medium transition-all text-center flex flex-col items-center gap-1 cursor-pointer ${
            levelMode === 'exploded'
              ? 'bg-sky-600 text-white shadow-md shadow-sky-600/30'
              : 'text-slate-400 hover:text-slate-200 hover:bg-white/5'
          }`}
          title="Bóc tách bung các tầng theo trục đứng (Exploded View)"
        >
          <Maximize2 className="w-3.5 h-3.5" />
          <span>Exploded</span>
        </button>

        <button
          type="button"
          onClick={() => {
            setLevelMode('solo');
            if (!selectedLevel && floors.length > 0) {
              setSelectedLevel(floors[0].level);
            }
          }}
          className={`py-1.5 px-2 rounded-lg text-xs font-medium transition-all text-center flex flex-col items-center gap-1 cursor-pointer ${
            levelMode === 'solo'
              ? 'bg-sky-600 text-white shadow-md shadow-sky-600/30'
              : 'text-slate-400 hover:text-slate-200 hover:bg-white/5'
          }`}
          title="Cách ly hiển thị duy nhất tầng được chọn"
        >
          <Eye className="w-3.5 h-3.5" />
          <span>Solo</span>
        </button>
      </div>

      {/* Exploded Mode Slider */}
      {levelMode === 'exploded' && (
        <div className="flex flex-col gap-1.5 pt-1 animate-in fade-in slide-in-from-top-1 duration-150">
          <div className="flex items-center justify-between text-[11px] text-slate-300">
            <span className="flex items-center gap-1 text-slate-400">
              <Sliders className="w-3 h-3 text-sky-400" />
              Độ giãn cách Y:
            </span>
            <span className="font-mono text-sky-400 font-semibold">{explodeFactor.toFixed(1)}x</span>
          </div>
          <input
            type="range"
            min={0.5}
            max={2.8}
            step={0.1}
            value={explodeFactor}
            onChange={(e) => setExplodeFactor(parseFloat(e.target.value))}
            className="w-full accent-sky-500 h-1.5 bg-slate-800 rounded-lg cursor-pointer"
          />
        </div>
      )}

      {/* Solo Mode Level Picker */}
      {levelMode === 'solo' && (
        <div className="flex flex-col gap-1.5 pt-1 animate-in fade-in slide-in-from-top-1 duration-150">
          <label className="text-[11px] text-slate-400 flex items-center justify-between">
            <span>Chọn tầng cách ly:</span>
            <span className="font-mono text-sky-400 font-semibold">{selectedLevel || 'Chưa chọn'}</span>
          </label>
          <div className="grid grid-cols-2 gap-1 max-h-36 overflow-y-auto custom-scrollbar p-1 bg-slate-950/40 rounded-xl border border-white/5">
            {floors.map((floor) => {
              const isSelected = selectedLevel === floor.level;
              return (
                <button
                  key={floor.level}
                  type="button"
                  onClick={() => setSelectedLevel(floor.level)}
                  className={`px-2 py-1.5 rounded-lg text-left text-xs transition-all flex items-center justify-between cursor-pointer ${
                    isSelected
                      ? 'bg-sky-500/25 border border-sky-400/50 text-white font-semibold'
                      : 'text-slate-300 hover:bg-white/5 hover:text-white border border-transparent'
                  }`}
                >
                  <span className="truncate">{floor.level}</span>
                  <span className="text-[9px] font-mono text-slate-400 ml-1">{floor.elevation}</span>
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
};
