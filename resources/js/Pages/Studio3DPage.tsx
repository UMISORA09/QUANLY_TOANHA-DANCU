import React, { useState } from 'react';
import {
  ArrowLeft,
  Layers,
  Sparkles,
  Maximize2,
  Minimize2,
  Building2,
  Compass,
  Cpu,
  Share2,
  Sliders,
  HelpCircle,
  RotateCcw
} from 'lucide-react';
import { Building3DViewer } from '../Components/Admin/Building3DViewer';

export interface Studio3DPageProps {
  onBack?: () => void;
  onNavigateHome?: () => void;
}

export const Studio3DPage: React.FC<Studio3DPageProps> = ({
  onBack = () => {
    if (window.history.length > 1) {
      window.history.back();
    } else {
      window.location.href = '/admin';
    }
  },
  onNavigateHome = () => {
    window.location.href = '/home';
  }
}) => {
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [showInfo, setShowInfo] = useState(false);

  const toggleFullscreen = () => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().then(() => setIsFullscreen(true)).catch(() => {});
    } else {
      document.exitFullscreen().then(() => setIsFullscreen(false)).catch(() => {});
    }
  };

  return (
    <div className="w-screen h-screen flex flex-col bg-slate-950 text-slate-100 overflow-hidden font-sans select-none">
      {/* =========================================================================
          STUDIO NAVIGATION & BIM HEADER BAR
          ========================================================================= */}
      <header className="h-14 px-4 bg-slate-900/95 border-b border-white/10 flex items-center justify-between shrink-0 z-30 backdrop-blur-md">
        {/* Left: Branding & Back Navigation */}
        <div className="flex items-center gap-3">
          <button
            onClick={onBack}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white border border-white/10 text-xs font-semibold transition-all cursor-pointer shadow-sm active:scale-95"
            title="Quay lại phân hệ quản lý"
          >
            <ArrowLeft className="w-4 h-4" />
            <span className="hidden sm:inline">Quản Trị</span>
          </button>

          <div className="h-5 w-[1px] bg-white/15 hidden sm:block" />

          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-xl bg-gradient-to-tr from-sky-500 via-indigo-600 to-purple-600 flex items-center justify-center text-white shadow-md shadow-sky-500/20">
              <Layers className="w-4 h-4" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-sm font-bold tracking-tight text-white flex items-center gap-1.5">
                  CASSAVAS 3D STUDIO
                  <span className="px-2 py-0.5 text-[10px] font-mono font-bold bg-sky-500/20 text-sky-400 rounded-full border border-sky-500/30">
                    PBR PRO
                  </span>
                </h1>
              </div>
              <p className="text-[10px] text-slate-400 hidden md:block">
                Digital Twin • Khảo sát Kiến trúc • Tách tầng Pascal CAD • Vật liệu PBR chuẩn vật lý
              </p>
            </div>
          </div>
        </div>

        {/* Center: System Status & PBR Engine Specs */}
        <div className="hidden lg:flex items-center gap-2 text-[11px] font-mono text-slate-300 bg-slate-950/70 px-3 py-1 rounded-full border border-white/10">
          <div className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
          <span className="text-slate-400">Engine:</span>
          <span className="text-sky-400 font-semibold">Three.js WebGL</span>
          <span className="text-white/20">|</span>
          <span className="text-slate-400">Shading:</span>
          <span className="text-emerald-400 font-semibold">PBR ACES-Filmic</span>
          <span className="text-white/20">|</span>
          <span className="text-slate-400">Environment:</span>
          <span className="text-amber-400 font-semibold">PMREM 360° IBL</span>
        </div>

        {/* Right: Studio Quick Actions */}
        <div className="flex items-center gap-2">
          <button
            onClick={() => setShowInfo(!showInfo)}
            className={`p-2 rounded-xl border text-xs transition-all cursor-pointer ${
              showInfo
                ? 'bg-sky-500/20 text-sky-400 border-sky-500/40'
                : 'bg-white/5 text-slate-400 hover:text-white border-white/10 hover:bg-white/10'
            }`}
            title="Hướng dẫn phím tắt & tính năng"
          >
            <HelpCircle className="w-4 h-4" />
          </button>

          <button
            onClick={toggleFullscreen}
            className="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white border border-white/10 transition-all cursor-pointer"
            title={isFullscreen ? 'Thu nhỏ cửa sổ' : 'Toàn màn hình'}
          >
            {isFullscreen ? <Minimize2 className="w-4 h-4" /> : <Maximize2 className="w-4 h-4" />}
          </button>

          <button
            onClick={onNavigateHome}
            className="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold transition-all shadow-md shadow-sky-600/30 active:scale-95"
          >
            <Building2 className="w-3.5 h-3.5" />
            <span>Trang Chủ</span>
          </button>
        </div>
      </header>

      {/* =========================================================================
          INFO MODAL / CHEAT SHEET
          ========================================================================= */}
      {showInfo && (
        <div className="absolute top-16 right-4 w-96 p-4 rounded-2xl bg-slate-900/95 border border-white/15 shadow-2xl backdrop-blur-xl z-50 animate-in fade-in slide-in-from-top-2 text-xs">
          <div className="flex items-center justify-between pb-2 mb-3 border-b border-white/10">
            <h3 className="font-bold text-white flex items-center gap-1.5">
              <Sparkles className="w-4 h-4 text-sky-400" />
              Công cụ 3D Studio Nâng Cao
            </h3>
            <button
              onClick={() => setShowInfo(false)}
              className="text-slate-400 hover:text-white"
            >
              ✕
            </button>
          </div>

          <div className="space-y-2.5 text-slate-300">
            <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
              <div className="font-semibold text-sky-300 mb-1">🎮 Điều khiển Camera</div>
              <ul className="list-disc pl-4 space-y-1 text-[11px] text-slate-400">
                <li><b className="text-slate-200">Chuột trái + Kéo:</b> Xoay camera 360° quanh công trình</li>
                <li><b className="text-slate-200">Cuộn chuột:</b> Phóng to / Thu nhỏ mượt mà</li>
                <li><b className="text-slate-200">Chuột phải:</b> Tự do định hướng góc nhìn</li>
              </ul>
            </div>

            <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
              <div className="font-semibold text-emerald-300 mb-1">📐 Hệ Thống Pascal CAD & Đo Đạc</div>
              <ul className="list-disc pl-4 space-y-1 text-[11px] text-slate-400">
                <li><b className="text-slate-200">Thước đo 3D (Pascal Measure):</b> Bật công cụ thước đo ở thanh công cụ để đo khoảng cách thực tế giữa 2 điểm bất kỳ.</li>
                <li><b className="text-slate-200">Tách tầng kiến trúc (Level Solo/Exploded):</b> Tách nổ các tầng để phân tích kết cấu hầm, khối đế và tháp.</li>
                <li><b className="text-slate-200">A3D AI Shading:</b> Chuyển đổi giữa chế độ PBR thực tế, Clay Model (đất sét kiến trúc) và Wireframe.</li>
              </ul>
            </div>

            <div className="p-2.5 rounded-xl bg-white/5 border border-white/5">
              <div className="font-semibold text-amber-300 mb-1">🌴 Cảnh Quan & Môi Trường PBR</div>
              <p className="text-[11px] text-slate-400">
                Mô hình đã được trang bị hệ thống cây dừa ven biển, cây bóng mát đô thị, thảm cỏ vi sợi PBR, gợn sóng biển và IBL bầu trời chân thực.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* =========================================================================
          MAIN 3D WORKSPACE
          ========================================================================= */}
      <main className="flex-1 w-full h-full relative overflow-hidden bg-slate-950">
        <Building3DViewer className="w-full h-full" />
      </main>
    </div>
  );
};

export default Studio3DPage;
