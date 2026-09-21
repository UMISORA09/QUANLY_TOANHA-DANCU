import React from 'react';
import {
  FolderTree,
  SlidersHorizontal,
  Palette,
  Crop,
  Grid,
  RotateCw,
  Wand2,
  Layers,
  Sparkles,
  Ruler,
  Terminal
} from 'lucide-react';
import { A3DShadingMode, A3DAspectRatio } from './types';

interface A3DStudioDockProps {
  activeDrawer: 'none' | 'outliner' | 'environment' | 'ai_studio' | 'level_control';
  onToggleDrawer: (drawer: 'outliner' | 'environment' | 'ai_studio' | 'level_control') => void;
  shadingMode: A3DShadingMode;
  onChangeShadingMode: (mode: A3DShadingMode) => void;
  aspectRatio: A3DAspectRatio;
  onChangeAspectRatio: (ratio: A3DAspectRatio) => void;
  showWorldGrid: boolean;
  onToggleWorldGrid: () => void;
  isAutoRotate: boolean;
  onToggleAutoRotate: () => void;
  outlinerItemCount: number;
  isMeasuring?: boolean;
  onToggleMeasure?: () => void;
  isAgentConsoleOpen?: boolean;
  onToggleAgentConsole?: () => void;
}

export const A3DStudioDock: React.FC<A3DStudioDockProps> = ({
  activeDrawer,
  onToggleDrawer,
  shadingMode,
  onChangeShadingMode,
  aspectRatio,
  onChangeAspectRatio,
  showWorldGrid,
  onToggleWorldGrid,
  isAutoRotate,
  onToggleAutoRotate,
  outlinerItemCount,
  isMeasuring = false,
  onToggleMeasure,
  isAgentConsoleOpen = false,
  onToggleAgentConsole,
}) => {
  return (
    <div className="absolute bottom-4 left-1/2 -translate-x-1/2 z-30 flex items-center gap-1.5 p-1.5 rounded-2xl bg-slate-900/90 backdrop-blur-2xl border border-white/10 shadow-2xl max-w-[95vw] overflow-x-auto select-none pointer-events-auto">
      {/* 1. Nút Outliner */}
      <button
        type="button"
        onClick={() => onToggleDrawer('outliner')}
        title="Bật/Tắt Cây cấu trúc đối tượng 3D (Scene Outliner)"
        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
          activeDrawer === 'outliner'
            ? 'bg-sky-600 text-white shadow-lg shadow-sky-500/25'
            : 'text-slate-300 hover:text-white hover:bg-white/10'
        }`}
      >
        <FolderTree className="w-3.5 h-3.5 text-sky-400" />
        <span className="hidden sm:inline">Outliner</span>
        <span className="px-1.5 py-0.2 rounded-full text-[9px] font-mono bg-white/15 text-white">
          {outlinerItemCount}
        </span>
      </button>

      {/* 2. Pascal Level System (Stacked / Exploded / Solo) */}
      <button
        type="button"
        onClick={() => onToggleDrawer('level_control')}
        title="Pascal Level System: Bóc tách bung tầng (Exploded) hoặc cách ly tầng (Solo)"
        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
          activeDrawer === 'level_control'
            ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/25'
            : 'text-slate-300 hover:text-white hover:bg-white/10'
        }`}
      >
        <Layers className="w-3.5 h-3.5 text-indigo-400" />
        <span className="hidden sm:inline">Tầng</span>
      </button>

      {/* 3. Nút Environment */}
      <button
        type="button"
        onClick={() => onToggleDrawer('environment')}
        title="Tùy chỉnh Ánh sáng, Mặt trời & Sương mù"
        className={`flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
          activeDrawer === 'environment'
            ? 'bg-amber-600 text-white shadow-lg shadow-amber-500/25'
            : 'text-slate-300 hover:text-white hover:bg-white/10'
        }`}
      >
        <SlidersHorizontal className="w-3.5 h-3.5 text-amber-400" />
        <span className="hidden sm:inline">Môi Trường</span>
      </button>

      <div className="w-px h-5 bg-white/10 mx-0.5" />

      {/* Pascal 3D Measure Tool */}
      {onToggleMeasure && (
        <button
          type="button"
          onClick={onToggleMeasure}
          title={isMeasuring ? 'Đang bật thước đo (Nhấp để tắt)' : 'Bật thước đo khoảng cách 3D (Pascal Dimension)'}
          className={`p-2 rounded-xl transition-all cursor-pointer ${
            isMeasuring
              ? 'bg-amber-500/30 text-amber-300 border border-amber-500/50 shadow-md shadow-amber-500/20'
              : 'text-slate-400 hover:text-white hover:bg-white/10'
          }`}
        >
          <Ruler className="w-3.5 h-3.5" />
        </button>
      )}

      {/* Pascal Agent Console (CLI / MCP Tool) */}
      {onToggleAgentConsole && (
        <button
          type="button"
          onClick={onToggleAgentConsole}
          title={isAgentConsoleOpen ? 'Đóng Pascal Agent Console' : 'Mở Pascal Agent Console (MCP CLI)'}
          className={`p-2 rounded-xl transition-all cursor-pointer ${
            isAgentConsoleOpen
              ? 'bg-sky-500/30 text-sky-300 border border-sky-500/50 shadow-md shadow-sky-500/20'
              : 'text-slate-400 hover:text-white hover:bg-white/10'
          }`}
        >
          <Terminal className="w-3.5 h-3.5" />
        </button>
      )}

      <div className="w-px h-5 bg-white/10 mx-0.5" />

      {/* 3. Shading Mode Switcher */}
      <div className="flex items-center p-0.5 rounded-xl bg-slate-950/60 border border-white/5 text-[11px]">
        {(
          [
            { id: 'realistic', label: 'PBR', title: 'Vật liệu PBR thực tế' },
            { id: 'clay', label: 'Clay', title: 'Sa bàn thạch cao trắng (Clay Model)' },
            { id: 'wireframe', label: 'Wire', title: 'Khung dây Blueprint kỹ thuật' },
            { id: 'depth', label: 'Depth', title: 'Bản đồ độ sâu đen trắng (Z-Buffer)' },
          ] as const
        ).map((sm) => (
          <button
            key={sm.id}
            type="button"
            onClick={() => onChangeShadingMode(sm.id)}
            title={sm.title}
            className={`px-2 py-1 rounded-lg font-bold transition-all cursor-pointer ${
              shadingMode === sm.id
                ? 'bg-sky-500/30 text-sky-300 shadow-sm border border-sky-500/40'
                : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            {sm.label}
          </button>
        ))}
      </div>

      <div className="w-px h-5 bg-white/10 mx-0.5" />

      {/* 4. Aspect Ratio Selector */}
      <div className="flex items-center p-0.5 rounded-xl bg-slate-950/60 border border-white/5 text-[11px]">
        {(['free', '16:9', '4:3', '1:1', '9:16'] as const).map((r) => (
          <button
            key={r}
            type="button"
            onClick={() => onChangeAspectRatio(r)}
            title={`Khung ngắm tỷ lệ ${r}`}
            className={`px-2 py-1 rounded-lg font-mono font-bold transition-all cursor-pointer ${
              aspectRatio === r
                ? 'bg-amber-500/30 text-amber-300 shadow-sm border border-amber-500/40'
                : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            {r === 'free' ? 'Free' : r}
          </button>
        ))}
      </div>

      <div className="w-px h-5 bg-white/10 mx-0.5" />

      {/* 5. World Grid Toggle */}
      <button
        type="button"
        onClick={onToggleWorldGrid}
        title={showWorldGrid ? 'Tắt lưới tọa độ thế giới' : 'Bật lưới tọa độ thế giới'}
        className={`p-2 rounded-xl transition-all cursor-pointer ${
          showWorldGrid
            ? 'bg-sky-500/20 text-sky-400 border border-sky-500/30'
            : 'text-slate-400 hover:text-white hover:bg-white/10'
        }`}
      >
        <Grid className="w-3.5 h-3.5" />
      </button>

      {/* 6. Auto-Rotate Toggle */}
      <button
        type="button"
        onClick={onToggleAutoRotate}
        title={isAutoRotate ? 'Dừng xoay tự động' : 'Bật tự động xoay 360°'}
        className={`p-2 rounded-xl transition-all cursor-pointer ${
          isAutoRotate
            ? 'bg-teal-500/20 text-teal-400 border border-teal-500/30 animate-pulse'
            : 'text-slate-400 hover:text-white hover:bg-white/10'
        }`}
      >
        <RotateCw className="w-3.5 h-3.5" />
      </button>

      <div className="w-px h-5 bg-white/10 mx-0.5" />

      {/* 7. AI Render Studio Button */}
      <button
        type="button"
        onClick={() => onToggleDrawer('ai_studio')}
        title="Mở phòng Studio Kết xuất AI & Depth Map"
        className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${
          activeDrawer === 'ai_studio'
            ? 'bg-gradient-to-r from-purple-600 to-indigo-600 text-white shadow-lg shadow-purple-500/25 ring-2 ring-purple-400/50'
            : 'bg-gradient-to-r from-purple-600/30 to-indigo-600/30 text-purple-200 border border-purple-500/30 hover:bg-purple-600/50 hover:text-white'
        }`}
      >
        <Wand2 className="w-3.5 h-3.5 text-purple-300 animate-spin-slow" />
        <span>AI Studio</span>
      </button>
    </div>
  );
};
