import React from 'react';
import {
  Sun,
  Moon,
  Sunset,
  SlidersHorizontal,
  Grid,
  CloudFog,
  Sparkles,
  X
} from 'lucide-react';
import { A3DEnvironmentSettings } from './types';

interface A3DEnvironmentPanelProps {
  settings: A3DEnvironmentSettings;
  timeMode: 'day' | 'sunset' | 'night';
  onChangeSettings: (newSettings: Partial<A3DEnvironmentSettings>) => void;
  onChangeTimeMode: (mode: 'day' | 'sunset' | 'night') => void;
  onClose: () => void;
}

export const A3DEnvironmentPanel: React.FC<A3DEnvironmentPanelProps> = ({
  settings,
  timeMode,
  onChangeSettings,
  onChangeTimeMode,
  onClose,
}) => {
  return (
    <div className="w-80 max-h-[580px] flex flex-col rounded-3xl bg-slate-900/95 backdrop-blur-2xl border border-white/10 shadow-2xl overflow-hidden animate-in fade-in slide-in-from-left-2">
      {/* Header */}
      <div className="p-3.5 border-b border-white/10 flex items-center justify-between bg-slate-950/40">
        <div className="flex items-center gap-2">
          <div className="p-1.5 rounded-xl bg-amber-500/20 text-amber-400 border border-amber-500/30">
            <SlidersHorizontal className="w-4 h-4" />
          </div>
          <div>
            <h4 className="text-xs font-black text-white tracking-wide uppercase font-mono">
              Environment Studio
            </h4>
            <p className="text-[10px] text-slate-400">Ánh sáng, mặt trời & sương mù môi trường</p>
          </div>
        </div>
        <button
          type="button"
          onClick={onClose}
          className="p-1.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition-all cursor-pointer"
        >
          <X className="w-4 h-4" />
        </button>
      </div>

      <div className="p-4 space-y-4 overflow-y-auto max-h-[500px]">
        {/* Presets Thời Gian */}
        <div>
          <label className="text-[11px] font-bold text-slate-300 font-mono uppercase tracking-wider block mb-2">
            Preset Chiếu Sáng
          </label>
          <div className="grid grid-cols-3 gap-1.5 p-1 rounded-2xl bg-slate-950/60 border border-white/5">
            <button
              type="button"
              onClick={() => {
                onChangeTimeMode('day');
                onChangeSettings({ sunElevation: 58, sunAzimuth: 45, ambientIntensity: 1.2 });
              }}
              className={`flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                timeMode === 'day'
                  ? 'bg-sky-600 text-white shadow-md'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <Sun className="w-3.5 h-3.5 text-amber-300" />
              <span>Ngày</span>
            </button>
            <button
              type="button"
              onClick={() => {
                onChangeTimeMode('sunset');
                onChangeSettings({ sunElevation: 12, sunAzimuth: 85, ambientIntensity: 1.4 });
              }}
              className={`flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                timeMode === 'sunset'
                  ? 'bg-amber-600 text-white shadow-md'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <Sunset className="w-3.5 h-3.5 text-orange-300" />
              <span>Hoàng hôn</span>
            </button>
            <button
              type="button"
              onClick={() => {
                onChangeTimeMode('night');
                onChangeSettings({ sunElevation: 25, sunAzimuth: -40, ambientIntensity: 0.9 });
              }}
              className={`flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
                timeMode === 'night'
                  ? 'bg-indigo-600 text-white shadow-md'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <Moon className="w-3.5 h-3.5 text-indigo-300" />
              <span>Đêm</span>
            </button>
          </div>
        </div>

        {/* Góc độ cao mặt trời (Elevation) */}
        <div>
          <div className="flex items-center justify-between text-xs mb-1.5">
            <span className="text-slate-300 font-semibold flex items-center gap-1.5">
              <Sun className="w-3.5 h-3.5 text-amber-400" />
              Độ Cao Mặt Trời (Altitude)
            </span>
            <span className="font-mono text-amber-400 font-bold">{settings.sunElevation}°</span>
          </div>
          <input
            type="range"
            min={5}
            max={85}
            step={1}
            value={settings.sunElevation}
            onChange={(e) => onChangeSettings({ sunElevation: Number(e.target.value) })}
            className="w-full accent-amber-500 cursor-pointer"
          />
        </div>

        {/* Góc xoay hướng nắng (Azimuth) */}
        <div>
          <div className="flex items-center justify-between text-xs mb-1.5">
            <span className="text-slate-300 font-semibold">Góc Xoay Hướng Nắng (Azimuth)</span>
            <span className="font-mono text-sky-400 font-bold">{settings.sunAzimuth}°</span>
          </div>
          <input
            type="range"
            min={-180}
            max={180}
            step={5}
            value={settings.sunAzimuth}
            onChange={(e) => onChangeSettings({ sunAzimuth: Number(e.target.value) })}
            className="w-full accent-sky-500 cursor-pointer"
          />
        </div>

        {/* Cường độ ánh sáng môi trường */}
        <div>
          <div className="flex items-center justify-between text-xs mb-1.5">
            <span className="text-slate-300 font-semibold">Cường Độ Sáng (Ambient)</span>
            <span className="font-mono text-emerald-400 font-bold">{settings.ambientIntensity.toFixed(2)}x</span>
          </div>
          <input
            type="range"
            min={0.3}
            max={2.5}
            step={0.05}
            value={settings.ambientIntensity}
            onChange={(e) => onChangeSettings({ ambientIntensity: Number(e.target.value) })}
            className="w-full accent-emerald-500 cursor-pointer"
          />
        </div>

        {/* Sương mù khí quyển (Atmosphere Fog) */}
        <div>
          <div className="flex items-center justify-between text-xs mb-1.5">
            <span className="text-slate-300 font-semibold flex items-center gap-1.5">
              <CloudFog className="w-3.5 h-3.5 text-cyan-400" />
              Sương Mù Biển (Fog)
            </span>
            <span className="font-mono text-cyan-400 font-bold">{(settings.fogDensity * 1000).toFixed(1)}‰</span>
          </div>
          <input
            type="range"
            min={0}
            max={0.015}
            step={0.0005}
            value={settings.fogDensity}
            onChange={(e) => onChangeSettings({ fogDensity: Number(e.target.value) })}
            className="w-full accent-cyan-500 cursor-pointer"
          />
        </div>

        {/* Công tắc Lưới tọa độ thế giới (World Grid) */}
        <div className="pt-2 border-t border-white/10 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Grid className="w-4 h-4 text-sky-400" />
            <div>
              <div className="text-xs font-bold text-slate-200">Lưới Tọa Độ (World Grid)</div>
              <div className="text-[10px] text-slate-400">Lưới đo đạc chuẩn Three.js Studio</div>
            </div>
          </div>
          <button
            type="button"
            onClick={() => onChangeSettings({ showWorldGrid: !settings.showWorldGrid })}
            className={`w-11 h-6 rounded-full transition-colors relative cursor-pointer ${
              settings.showWorldGrid ? 'bg-sky-600' : 'bg-slate-700'
            }`}
          >
            <div
              className={`w-4 h-4 rounded-full bg-white transition-transform absolute top-1 ${
                settings.showWorldGrid ? 'left-6' : 'left-1'
              }`}
            />
          </button>
        </div>
      </div>
    </div>
  );
};
