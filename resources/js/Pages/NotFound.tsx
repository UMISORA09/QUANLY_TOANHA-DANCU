import React, { useState } from 'react';
import {
  Building2,
  ArrowLeft
} from 'lucide-react';

interface NotFoundProps {
  onBackHome?: () => void;
}

export const NotFound: React.FC<NotFoundProps> = ({ onBackHome }) => {
  const [mousePos, setMousePos] = useState<{ x: number; y: number }>({ x: 0, y: 0 });

  const handleGoHome = () => {
    if (onBackHome) {
      onBackHome();
    } else {
      window.location.href = '/';
    }
  };

  return (
    <div
      onMouseMove={(e) => setMousePos({ x: e.clientX, y: e.clientY })}
      className="min-h-screen bg-[#FAFAFA] text-[#171717] selection:bg-neutral-900 selection:text-white font-['Plus_Jakarta_Sans',sans-serif] relative overflow-hidden flex flex-col justify-between"
    >
      {/* ========================================================
          BACKGROUND AMBIANCE LIKE HOME PAGE
          ======================================================== */}
      {/* Dynamic Mouse Following Spotlight */}
      <div
        className="pointer-events-none fixed inset-0 z-0 transition-opacity duration-500 opacity-70 hidden md:block"
        style={{
          background: `radial-gradient(650px circle at ${mousePos.x}px ${mousePos.y}px, rgba(56, 189, 248, 0.07), transparent 80%)`,
        }}
      />

      {/* Background Cloud Atmosphere & Ambient Glows */}
      <div className="fixed inset-0 pointer-events-none overflow-hidden -z-10">
        <div className="absolute -top-40 -left-40 w-[600px] h-[600px] bg-gradient-to-br from-blue-100/50 via-sky-100/30 to-transparent rounded-full blur-3xl animate-float-orb opacity-75" />
        <div className="absolute top-1/4 -right-48 w-[650px] h-[650px] bg-gradient-to-bl from-slate-200/50 via-indigo-100/30 to-transparent rounded-full blur-3xl animate-float-orb-reverse opacity-70" />
        <div className="absolute bottom-10 left-1/3 w-[500px] h-[500px] bg-gradient-to-t from-sky-100/40 via-emerald-50/20 to-transparent rounded-full blur-3xl animate-cloud-float opacity-60" />

        {/* Subtle dot matrix grid */}
        <div
          className="absolute inset-0 opacity-[0.035]"
          style={{
            backgroundImage: 'radial-gradient(#000000 1px, transparent 1px)',
            backgroundSize: '24px 24px'
          }}
        />
      </div>

      {/* ================= HEADER / NAVBAR ================= */}
      <header className="sticky top-0 z-40 bg-white/75 backdrop-blur-xl border-b border-white/50 shadow-xs glass-specular-edge transition-all">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
          {/* Logo */}
          <a
            href="/"
            onClick={(e) => {
              e.preventDefault();
              handleGoHome();
            }}
            className="flex items-center gap-3 group"
          >
            <div className="w-10 h-10 bg-black text-white rounded-lg flex items-center justify-center shadow-sm group-hover:scale-105 transition-transform duration-200">
              <Building2 className="w-5 h-5 text-white" />
            </div>
            <div className="flex flex-col">
              <span className="font-bold tracking-tight text-lg text-neutral-950 flex items-center gap-1.5">
                SMART CASSAVAS
                <span className="inline-block w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
              </span>
              <span className="text-[10px] tracking-wider text-neutral-600 font-medium -mt-1 uppercase">
                Building OS
              </span>
            </div>
          </a>
        </div>
      </header>

      {/* ========================================================
          HERO 404 CARD: ULTRA GLASSMORPHISM & DYNAMIC MOTION
          ======================================================== */}
      <main className="relative z-10 w-full max-w-4xl mx-auto px-4 py-8 sm:py-16 flex flex-col items-center justify-center my-auto">
        <div className="relative w-full max-w-xl mx-auto animate-float-card">
          
          {/* Glowing Ambient Halo Behind Card */}
          <div className="absolute -inset-1.5 bg-gradient-to-r from-sky-400/20 via-emerald-400/20 to-indigo-400/20 rounded-3xl blur-xl opacity-75 group-hover:opacity-100 transition-opacity duration-700 pointer-events-none" />

          {/* Main Glass Crystal Card */}
          <div className="relative glass-crystal rounded-3xl p-8 sm:p-14 flex flex-col items-center text-center shadow-2xl glass-specular-edge transition-all duration-300">
            
            {/* Specular Light Bar */}
            <div className="absolute top-0 inset-x-12 h-px bg-gradient-to-r from-transparent via-white to-transparent opacity-80" />

            {/* Central Logo Box with Glowing Ring */}
            <div className="relative mb-6 group/logo cursor-pointer">
              {/* Spinning Subtle Gradient Ring */}
              <div className="absolute -inset-1.5 rounded-2xl bg-gradient-to-tr from-sky-400/40 via-transparent to-emerald-400/40 animate-light-ring opacity-60 group-hover/logo:opacity-100 transition-opacity" />
              
              <div className="relative w-16 h-16 rounded-2xl border border-white/90 bg-white/90 backdrop-blur-md flex items-center justify-center shadow-md group-hover/logo:scale-105 transition-transform duration-300">
                <Building2 className="w-8 h-8 text-neutral-900 stroke-[1.8] transition-transform duration-300 group-hover/logo:rotate-3" />
              </div>
            </div>

            {/* Subtitle Brand with Moving Text Lighting Animation */}
            <div className="mb-3">
              <span className="text-[11px] sm:text-xs font-bold tracking-[0.28em] uppercase animate-text-light text-neutral-600">
                SMART CASSAVAS
              </span>
            </div>

            {/* Primary Heading with Subtle Drop Shadow */}
            <h1 className="text-3xl sm:text-4xl font-extrabold text-neutral-900 tracking-tight mb-3">
              Không tìm thấy trang
            </h1>

            {/* Description Text */}
            <p className="text-neutral-600 text-sm sm:text-base leading-relaxed max-w-md mb-8 font-normal">
              Đường dẫn bạn truy cập không tồn tại hoặc đã được chuyển sang vị trí khác.
            </p>

            {/* Action Button Matching Reference with Sleek Shimmer & Hover */}
            <div className="flex items-center justify-center">
              <button
                onClick={handleGoHome}
                className="relative overflow-hidden inline-flex items-center justify-center gap-2.5 px-7 py-3.5 bg-black text-white text-sm font-semibold rounded-none hover:bg-neutral-800 active:scale-[0.98] transition-all duration-200 shadow-md hover:shadow-lg cursor-pointer group/btn"
              >
                {/* Light Sweep over button */}
                <div className="absolute inset-0 -translate-x-full group-hover/btn:translate-x-full transition-transform duration-700 bg-gradient-to-r from-transparent via-white/20 to-transparent pointer-events-none" />
                <ArrowLeft className="w-4 h-4 group-hover/btn:-translate-x-1 transition-transform" />
                <span>Về trang chủ</span>
              </button>
            </div>

          </div>
        </div>
      </main>

      {/* ================= FOOTER ================= */}
      <footer className="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-wrap items-center justify-between gap-3 text-xs text-neutral-500 border-t border-neutral-200/60 bg-white/40 backdrop-blur-md">
        <div className="flex items-center gap-2">
          <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
          <span>SMART CASSAVAS • Hệ sinh thái số tòa nhà thông minh</span>
        </div>
        <div>
          <span>© {new Date().getFullYear()} Cassavas Building OS. Bảo lưu mọi quyền.</span>
        </div>
      </footer>
    </div>
  );
};

export default NotFound;
