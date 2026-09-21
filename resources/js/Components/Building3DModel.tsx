import React, { useState, useEffect } from 'react';
import {
  Layers,
  Building2,
  Compass,
  Cpu,
  Thermometer,
  ShieldCheck,
  ShieldAlert,
  Zap,
  Activity,
  Maximize2,
  CheckCircle2,
  Radio,
  Sparkles,
  ArrowUpRight,
  BatteryCharging,
  Eye,
  Sliders,
  Split,
  Box
} from 'lucide-react';
import { Building3DViewer } from './Admin/Building3DViewer';

export interface BuildingTier {
  id: string;
  name: string;
  floors: string;
  type: 'penthouse' | 'residence_high' | 'residence_mid' | 'commercial' | 'lobby' | 'basement';
  residentsCount: number;
  temperature: number;
  powerKw: number;
  securityStatus: 'Bình thường' | 'Cảnh báo' | 'Kiểm soát';
  elevation: string;
  details: string;
  accentColor: string;
}

const BUILDING_TIERS: BuildingTier[] = [
  {
    id: 'rooftop',
    name: 'Sky Garden & Penthouse Duplex',
    floors: 'Tầng 25 - 26',
    type: 'penthouse',
    residentsCount: 16,
    temperature: 24.2,
    powerKw: 42.5,
    securityStatus: 'Bình thường',
    elevation: '+92.40m',
    details: 'Sân thượng sinh thái, trạm đỗ trực thăng Helipad, hệ thống pin năng lượng mặt trời 45 kW/h, Sky Lounge & Bể bơi chân mây',
    accentColor: 'from-amber-400 to-amber-600'
  },
  {
    id: 'residence_high',
    name: 'Khu Căn hộ Cao cấp & Duplex',
    floors: 'Tầng 17 - 24',
    type: 'residence_high',
    residentsCount: 72,
    temperature: 23.8,
    powerKw: 58.2,
    securityStatus: 'Bình thường',
    elevation: '+64.80m',
    details: '64 căn hộ mặt kính Low-E 3 lớp cản nhiệt, ban công kính tràn viền, khóa FaceID, cảm biến khói IoT từng căn',
    accentColor: 'from-sky-400 to-blue-600'
  },
  {
    id: 'residence_mid',
    name: 'Khu Căn hộ Tiêu chuẩn Smart Home',
    floors: 'Tầng 7 - 16',
    type: 'residence_mid',
    residentsCount: 178,
    temperature: 24.5,
    powerKw: 74.6,
    securityStatus: 'Bình thường',
    elevation: '+32.00m',
    details: '160 căn hộ gia đình, tỷ lệ lấp đầy 94%, cấp nước sạch biến tần tăng áp, hệ thống chiếu sáng hành lang cảm biến chuyển động',
    accentColor: 'from-indigo-400 to-cyan-600'
  },
  {
    id: 'commercial',
    name: 'Khu Tiện ích Thương mại & Co-working',
    floors: 'Tầng 2 - 6',
    type: 'commercial',
    residentsCount: 45,
    temperature: 23.0,
    powerKw: 36.8,
    securityStatus: 'Bình thường',
    elevation: '+10.50m',
    details: 'Bể bơi 4 mùa nước nóng, phòng Gym thể thao, khu sinh hoạt cộng đồng, trung tâm thương mại mini & văn phòng chia sẻ',
    accentColor: 'from-emerald-400 to-teal-600'
  },
  {
    id: 'lobby',
    name: 'Đại sảnh Grand Lobby & Lễ tân',
    floors: 'Tầng 1',
    type: 'lobby',
    residentsCount: 22,
    temperature: 22.5,
    powerKw: 18.4,
    securityStatus: 'Kiểm soát',
    elevation: '±0.00m',
    details: 'Sảnh kính thông tầng cao 6.5m, mái sảnh đón Canopy hiện đại, cổng kiểm soát phân làn Speed Gate FaceID, lễ tân 24/7',
    accentColor: 'from-violet-400 to-purple-600'
  },
  {
    id: 'basement',
    name: 'Bãi đỗ xe Thông minh & Trạm sạc EV',
    floors: 'Tầng Hầm B1 - B2',
    type: 'basement',
    residentsCount: 8,
    temperature: 25.1,
    powerKw: 62.0,
    securityStatus: 'Bình thường',
    elevation: '-7.20m',
    details: '320 vị trí ô tô định vị AI, 24 trạm sạc nhanh EV 120kW, bể nước ngầm PCCC 800m3 & trạm biến áp dự phòng',
    accentColor: 'from-emerald-500 to-green-600'
  }
];

interface Building3DModelProps {
  nightMode?: boolean;
  highAlert?: boolean;
  isCompact?: boolean;
}

export const Building3DModel: React.FC<Building3DModelProps> = ({
  nightMode = false,
  highAlert = false,
  isCompact = false
}) => {
  const [engineMode, setEngineMode] = useState<'webgl_3d' | 'bim_svg'>('webgl_3d');
  const [selectedTierId, setSelectedTierId] = useState<string>('residence_high');
  const [viewAngle, setViewAngle] = useState<'isometric' | 'front' | 'exploded'>('isometric');
  const [activeLayer, setActiveLayer] = useState<'all' | 'energy' | 'security' | 'iot'>('all');
  const [elevatorTarget, setElevatorTarget] = useState<number>(18);
  const [currentElevatorFloor, setCurrentElevatorFloor] = useState<number>(18);
  const [isElevatorGliding, setIsElevatorGliding] = useState<boolean>(false);

  // Active selected tier details
  const selectedTier = BUILDING_TIERS.find((t) => t.id === selectedTierId) || BUILDING_TIERS[1];

  // Handle Elevator Floor Calling
  const callElevator = (floor: number) => {
    if (floor === currentElevatorFloor) return;
    setIsElevatorGliding(true);
    setElevatorTarget(floor);
    setTimeout(() => {
      setCurrentElevatorFloor(floor);
      setIsElevatorGliding(false);
    }, 1800);
  };

  // Compute elevator Y position in the SVG coordinate space (Tower top ~140, Ground ~490)
  const getElevatorY = (floor: number) => {
    const minFloor = -2;
    const maxFloor = 26;
    const norm = (maxFloor - floor) / (maxFloor - minFloor);
    return Math.round(155 + norm * 325);
  };

  return (
    <div
      className={`relative w-full rounded-2xl overflow-hidden transition-all duration-700 select-none ${
        nightMode
          ? 'glass-panel-dark text-slate-100'
          : 'glass-panel text-neutral-900'
      } ${isCompact ? 'p-3 sm:p-5' : 'p-4 sm:p-7 border'}`}
    >
      {/* Top Header & HUD Status Bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 pb-3.5 border-b border-neutral-200/50 dark:border-slate-800/80">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-black text-white dark:bg-cyan-500 dark:text-slate-950 flex items-center justify-center shadow-xs">
            <Building2 className="w-4 h-4" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="text-xs sm:text-sm font-bold tracking-tight">
                MÔ HÌNH KIẾN TRÚC 3D DIGITAL TWIN
              </span>
              <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/15 text-emerald-500 border border-emerald-500/30">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                Live 60 FPS • BIM Level 2
              </span>
            </div>
            <p className="text-[11px] text-neutral-500 dark:text-slate-400">
              Mô phỏng phối cảnh trực quan WebGL & Trục đo đa tầng • Tháp đôi Cassavas
            </p>
          </div>
        </div>

        {/* Engine Switcher (WebGL 3D GPU vs BIM SVG) */}
        <div className="flex items-center p-1 rounded-xl bg-neutral-100 dark:bg-slate-800 border border-neutral-200/80 dark:border-slate-700/80 text-xs font-semibold">
          <button
            type="button"
            onClick={() => setEngineMode('webgl_3d')}
            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-all cursor-pointer ${
              engineMode === 'webgl_3d'
                ? 'bg-sky-600 text-white shadow-md'
                : 'text-slate-600 dark:text-slate-400 hover:text-neutral-900 dark:hover:text-white'
            }`}
          >
            <Box className="w-3.5 h-3.5" />
            <span>WebGL 3D (GPU)</span>
          </button>
          <button
            type="button"
            onClick={() => setEngineMode('bim_svg')}
            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg transition-all cursor-pointer ${
              engineMode === 'bim_svg'
                ? 'bg-sky-600 text-white shadow-md'
                : 'text-slate-600 dark:text-slate-400 hover:text-neutral-900 dark:hover:text-white'
            }`}
          >
            <Layers className="w-3.5 h-3.5" />
            <span>Sơ Đồ BIM 2.5D</span>
          </button>
        </div>

        {/* View Angle & Mode Controls (Chỉ hiển thị trong chế độ Sơ đồ BIM 2.5D) */}
        {engineMode === 'bim_svg' && (
          <div className="flex flex-wrap items-center gap-1.5 text-xs">
            <div className="flex items-center rounded-lg p-0.5 bg-neutral-100/80 dark:bg-slate-800/90 border border-neutral-200/60 dark:border-slate-700/60">
              <button
                onClick={() => setViewAngle('isometric')}
                className={`px-2.5 py-1 rounded-md text-[11px] font-medium transition-all ${
                  viewAngle === 'isometric'
                    ? 'bg-white dark:bg-cyan-500 text-neutral-950 dark:text-slate-950 font-bold shadow-xs'
                    : 'text-neutral-600 dark:text-slate-400 hover:text-neutral-900'
                }`}
                title="Phối cảnh trục đo 3 chiều tiêu chuẩn"
              >
                Isometric 3D
              </button>
              <button
                onClick={() => setViewAngle('front')}
                className={`px-2.5 py-1 rounded-md text-[11px] font-medium transition-all ${
                  viewAngle === 'front'
                    ? 'bg-white dark:bg-cyan-500 text-neutral-950 dark:text-slate-950 font-bold shadow-xs'
                    : 'text-neutral-600 dark:text-slate-400 hover:text-neutral-900'
                }`}
                title="Mặt đứng kiến trúc trực diện"
              >
                Mặt đứng
              </button>
              <button
                onClick={() => setViewAngle('exploded')}
                className={`px-2.5 py-1 rounded-md text-[11px] font-medium transition-all flex items-center gap-1 ${
                  viewAngle === 'exploded'
                    ? 'bg-sky-500 text-white font-bold shadow-xs'
                    : 'text-neutral-600 dark:text-slate-400 hover:text-neutral-900'
                }`}
                title="Mặt cắt phân tầng bóc tách cấu trúc"
              >
                <Split className="w-3 h-3" />
                <span>Phân tầng bóc tách</span>
              </button>
            </div>

            {/* Layer Filter Pills */}
            <div className="hidden sm:flex items-center gap-1">
              <button
                onClick={() => setActiveLayer('all')}
                className={`px-2 py-1 rounded text-[11px] font-medium border transition-colors ${
                  activeLayer === 'all'
                    ? 'bg-neutral-900 text-white dark:bg-slate-700 border-transparent'
                    : 'bg-transparent border-neutral-300 dark:border-slate-700 text-neutral-600 dark:text-slate-400'
                }`}
              >
                Tất cả
              </button>
              <button
                onClick={() => setActiveLayer('energy')}
                className={`px-2 py-1 rounded text-[11px] font-medium border flex items-center gap-1 transition-colors ${
                  activeLayer === 'energy'
                    ? 'bg-amber-500 text-white border-transparent'
                    : 'bg-transparent border-neutral-300 dark:border-slate-700 text-neutral-600 dark:text-slate-400'
                }`}
              >
                <Zap className="w-2.5 h-2.5" />
                Điện & Sạc
              </button>
              <button
                onClick={() => setActiveLayer('security')}
                className={`px-2 py-1 rounded text-[11px] font-medium border flex items-center gap-1 transition-colors ${
                  activeLayer === 'security'
                    ? 'bg-rose-500 text-white border-transparent'
                    : 'bg-transparent border-neutral-300 dark:border-slate-700 text-neutral-600 dark:text-slate-400'
                }`}
              >
                <ShieldAlert className="w-2.5 h-2.5" />
                An ninh PCCC
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Key Architectural & Engineering Metrics Ribbon */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 my-3">
        <div className="p-2.5 rounded-xl bg-neutral-100/70 dark:bg-slate-900/60 border border-neutral-200/60 dark:border-slate-800 flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-lg bg-sky-500/15 text-sky-500 flex items-center justify-center font-bold text-xs">
            28T
          </div>
          <div>
            <div className="text-[10px] text-neutral-500 dark:text-slate-400">Chiều cao công trình</div>
            <div className="text-xs font-black font-mono">95.00m (Tháp 10)</div>
          </div>
        </div>

        <div className="p-2.5 rounded-xl bg-neutral-100/70 dark:bg-slate-900/60 border border-neutral-200/60 dark:border-slate-800 flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-lg bg-amber-500/15 text-amber-500 flex items-center justify-center font-bold text-xs">
            BIM
          </div>
          <div>
            <div className="text-[10px] text-neutral-500 dark:text-slate-400">Tổng sàn xây dựng</div>
            <div className="text-xs font-black font-mono">72.150 m²</div>
          </div>
        </div>

        <div className="p-2.5 rounded-xl bg-neutral-100/70 dark:bg-slate-900/60 border border-neutral-200/60 dark:border-slate-800 flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-lg bg-emerald-500/15 text-emerald-500 flex items-center justify-center font-bold text-xs">
            PCCC
          </div>
          <div>
            <div className="text-[10px] text-neutral-500 dark:text-slate-400">An toàn phòng cháy</div>
            <div className="text-xs font-black font-mono">TCVN 06:2022</div>
          </div>
        </div>

        <div className="p-2.5 rounded-xl bg-neutral-100/70 dark:bg-slate-900/60 border border-neutral-200/60 dark:border-slate-800 flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-lg bg-purple-500/15 text-purple-500 flex items-center justify-center font-bold text-xs">
            EV
          </div>
          <div>
            <div className="text-[10px] text-neutral-500 dark:text-slate-400">Trạm sạc xe điện</div>
            <div className="text-xs font-black font-mono">24 Trụ (120kW)</div>
          </div>
        </div>

        <div className="p-2.5 rounded-xl bg-neutral-100/70 dark:bg-slate-900/60 border border-neutral-200/60 dark:border-slate-800 flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-lg bg-cyan-500/15 text-cyan-500 flex items-center justify-center font-bold text-xs">
            T5
          </div>
          <div>
            <div className="text-[10px] text-neutral-500 dark:text-slate-400">Bể bơi vô cực & Bar</div>
            <div className="text-xs font-black font-mono">Ốc Đảo Sinh Thái</div>
          </div>
        </div>

        <div className="p-2.5 rounded-xl bg-neutral-100/70 dark:bg-slate-900/60 border border-neutral-200/60 dark:border-slate-800 flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-lg bg-indigo-500/15 text-indigo-500 flex items-center justify-center font-bold text-xs">
            MEP
          </div>
          <div>
            <div className="text-[10px] text-neutral-500 dark:text-slate-400">Trạm biến áp</div>
            <div className="text-xs font-black font-mono">2 x 2000 kVA</div>
          </div>
        </div>
      </div>

      {engineMode === 'webgl_3d' ? (
        <div className="w-full min-h-[740px] sm:min-h-[780px]">
          <Building3DViewer />
        </div>
      ) : (
        /* Main 3D Stage & Interactive Columns */
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 mt-4 items-center min-h-[500px]">
        {/* Left Side: Precision Architectural 3D SVG Viewport */}
        <div
          className={`lg:col-span-7 relative flex items-center justify-center min-h-[440px] sm:min-h-[520px] overflow-hidden rounded-xl border transition-colors duration-700 ${
            nightMode
              ? 'bg-[#060D1E] border-slate-800'
              : 'bg-gradient-to-b from-slate-900 via-[#0B1528] to-slate-950 border-slate-700'
          }`}
        >
          {/* Subtle Grid Lines & Compass Rose */}
          <div className="absolute top-3 left-3 flex items-center gap-2 text-[10px] font-mono text-cyan-400/80 bg-slate-950/60 px-2.5 py-1 rounded-full border border-cyan-500/20 backdrop-blur-sm z-20">
            <Compass className="w-3 h-3 text-cyan-400 animate-spin" style={{ animationDuration: '30s' }} />
            <span>TRỤC TỌA ĐỘ: ISO 30° / NORTH 0°</span>
          </div>

          {/* High Alert Laser Radar Plane */}
          {highAlert && (
            <div className="absolute inset-0 pointer-events-none z-20">
              <div className="absolute inset-x-0 h-1 bg-gradient-to-r from-transparent via-amber-400 to-transparent opacity-80 animate-radar-scan blur-[1px]" />
              <div className="absolute top-3 right-3 text-[10px] font-mono text-amber-500 flex items-center gap-1 bg-amber-500/10 px-2.5 py-1 rounded-full border border-amber-500/30 backdrop-blur-sm">
                <Radio className="w-3 h-3 animate-spin" />
                RADAR QUÉT LỚP MẠNG AN NINH TẦNG CAO
              </div>
            </div>
          )}

          {/* Architectural SVG Skyscraper */}
          <div className="relative w-full max-w-[460px] h-[480px] sm:h-[530px] flex items-center justify-center transition-transform duration-700">
            <svg
              viewBox="0 0 540 680"
              className="w-full h-full drop-shadow-2xl overflow-visible"
              style={{
                transform:
                  viewAngle === 'front'
                    ? 'scale(0.96) translateY(-10px)'
                    : 'scale(1)',
                transition: 'transform 0.6s cubic-bezier(0.16, 1, 0.3, 1)'
              }}
            >
              <defs>
                {/* Facade gradients */}
                <linearGradient id="glassWallLeft" x1="0%" y1="0%" x2="100%" y2="100%">
                  <stop offset="0%" stopColor={nightMode ? '#0f2744' : '#1e3a5f'} />
                  <stop offset="100%" stopColor={nightMode ? '#071526' : '#112238'} />
                </linearGradient>

                <linearGradient id="glassWallRight" x1="0%" y1="0%" x2="100%" y2="100%">
                  <stop offset="0%" stopColor={nightMode ? '#1e3e6b' : '#2563eb'} />
                  <stop offset="50%" stopColor={nightMode ? '#132847' : '#1d4ed8'} />
                  <stop offset="100%" stopColor={nightMode ? '#0b192e' : '#172554'} />
                </linearGradient>

                <linearGradient id="roofSlabGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                  <stop offset="0%" stopColor={nightMode ? '#1e385c' : '#38bdf8'} stopOpacity="0.8" />
                  <stop offset="100%" stopColor={nightMode ? '#0e1d33' : '#0284c7'} stopOpacity="0.9" />
                </linearGradient>

                <linearGradient id="highlightGlow" x1="0%" y1="0%" x2="100%" y2="0%">
                  <stop offset="0%" stopColor="#38bdf8" stopOpacity="0.8" />
                  <stop offset="100%" stopColor="#22d3ee" stopOpacity="0.9" />
                </linearGradient>

                {/* Ground shadow radial */}
                <radialGradient id="groundShadow" cx="50%" cy="50%" r="50%">
                  <stop offset="0%" stopColor="#000000" stopOpacity="0.6" />
                  <stop offset="80%" stopColor="#020617" stopOpacity="0" />
                </radialGradient>
              </defs>

              {/* 1. Ground Plaza / Foundation Shadow */}
              <ellipse cx="270" cy="540" rx="200" ry="60" fill="url(#groundShadow)" />

              {/* Ground Isometric Grid Platform */}
              <polygon
                points="270,470 470,545 270,620 70,545"
                fill={nightMode ? '#070F1E' : '#0c1a2e'}
                stroke={nightMode ? '#1e3a5f' : '#334155'}
                strokeWidth="1.5"
                strokeDasharray="4 4"
              />

              {/* Driveway Entrance Lines */}
              <polygon
                points="270,545 350,575 270,605 190,575"
                fill={nightMode ? '#0f172a' : '#1e293b'}
                stroke="#38bdf8"
                strokeWidth="0.8"
                strokeOpacity="0.5"
              />

              {/* ========================================================
                  TIER 6: SUBTERRANEAN BASEMENT B1 - B2
                  ======================================================== */}
              <g
                id="tier-basement"
                onClick={() => setSelectedTierId('basement')}
                className="cursor-pointer transition-all duration-300 group"
                transform={viewAngle === 'exploded' ? 'translate(0, 35)' : 'translate(0, 0)'}
              >
                {/* Left Cutaway Retaining Wall */}
                <polygon
                  points="270,545 170,508 170,565 270,605"
                  fill={nightMode ? '#0c1322' : '#182438'}
                  stroke={selectedTierId === 'basement' ? '#10b981' : '#334155'}
                  strokeWidth={selectedTierId === 'basement' ? 2 : 1}
                />
                {/* Right Cutaway Wall with Parking Stripes */}
                <polygon
                  points="270,545 370,508 370,565 270,605"
                  fill={nightMode ? '#111c2e' : '#1e304a'}
                  stroke={selectedTierId === 'basement' ? '#10b981' : '#334155'}
                  strokeWidth={selectedTierId === 'basement' ? 2 : 1}
                />
                {/* EV Charging Green Neon Dots */}
                <circle cx="240" cy="565" r="2.5" fill="#10b981" className="animate-pulse" />
                <circle cx="260" cy="573" r="2.5" fill="#10b981" className="animate-pulse" />
                <circle cx="280" cy="573" r="2.5" fill="#10b981" className="animate-pulse" />
                <circle cx="300" cy="565" r="2.5" fill="#10b981" className="animate-pulse" />
                <text x="270" y="590" textAnchor="middle" fill="#10b981" fontSize="9" fontWeight="bold" fontFamily="monospace">
                  B1-B2 EV CHARGING HUB (24 TRỤ)
                </text>
              </g>

              {/* ========================================================
                  TIER 5: GRAND LOBBY (TẦNG 1)
                  ======================================================== */}
              <g
                id="tier-lobby"
                onClick={() => setSelectedTierId('lobby')}
                className="cursor-pointer transition-all duration-300 group"
                transform={viewAngle === 'exploded' ? 'translate(0, 20)' : 'translate(0, 0)'}
              >
                {/* Left Facade (Grand Glass Atrium) */}
                <polygon
                  points="270,480 180,446 180,508 270,545"
                  fill="url(#glassWallLeft)"
                  stroke={selectedTierId === 'lobby' ? '#38bdf8' : '#334155'}
                  strokeWidth={selectedTierId === 'lobby' ? 2.5 : 1}
                />
                {/* Right Facade (Entrance Revolving Door) */}
                <polygon
                  points="270,480 360,446 360,508 270,545"
                  fill="url(#glassWallRight)"
                  stroke={selectedTierId === 'lobby' ? '#38bdf8' : '#334155'}
                  strokeWidth={selectedTierId === 'lobby' ? 2.5 : 1}
                />
                {/* Entrance Canopy Roof */}
                <polygon
                  points="270,515 320,496 270,478 220,496"
                  fill="#0284c7"
                  fillOpacity="0.4"
                  stroke="#38bdf8"
                  strokeWidth="1"
                />
                {/* Lobby Atrium Columns */}
                <line x1="225" y1="463" x2="225" y2="525" stroke="#60a5fa" strokeWidth="1.2" strokeOpacity="0.7" />
                <line x1="315" y1="463" x2="315" y2="525" stroke="#60a5fa" strokeWidth="1.2" strokeOpacity="0.7" />
                {/* Warm Welcome Lights */}
                <circle cx="270" cy="510" r="3" fill="#fef08a" className="animate-pulse" />
              </g>

              {/* ========================================================
                  TIER 4: COMMERCIAL & AMENITIES (TẦNG 2 - 6)
                  ======================================================== */}
              <g
                id="tier-commercial"
                onClick={() => setSelectedTierId('commercial')}
                className="cursor-pointer transition-all duration-300 group"
                transform={viewAngle === 'exploded' ? 'translate(0, 8)' : 'translate(0, 0)'}
              >
                {/* Left Facade */}
                <polygon
                  points="270,405 180,371 180,446 270,480"
                  fill="url(#glassWallLeft)"
                  stroke={selectedTierId === 'commercial' ? '#22d3ee' : '#334155'}
                  strokeWidth={selectedTierId === 'commercial' ? 2.5 : 1}
                />
                {/* Right Facade (Indoor Pool & Gym Glass Wall) */}
                <polygon
                  points="270,405 360,371 360,446 270,480"
                  fill="url(#glassWallRight)"
                  stroke={selectedTierId === 'commercial' ? '#22d3ee' : '#334155'}
                  strokeWidth={selectedTierId === 'commercial' ? 2.5 : 1}
                />
                {/* Floor dividing mullions (Tầng 2, 3, 4, 5, 6) */}
                {[420, 435, 450, 465].map((yVal, idx) => (
                  <g key={idx}>
                    <line x1="180" y1={yVal - 34} x2="270" y2={yVal} stroke="#38bdf8" strokeWidth="0.8" strokeOpacity="0.6" />
                    <line x1="270" y1={yVal} x2="360" y2={yVal - 34} stroke="#38bdf8" strokeWidth="0.8" strokeOpacity="0.6" />
                  </g>
                ))}
                {/* Cyan Glow for Pool & Gym */}
                <rect x="230" y="430" width="80" height="15" fill="#06b6d4" fillOpacity="0.25" rx="2" />
              </g>

              {/* ========================================================
                  TIER 3: STANDARD RESIDENCES (TẦNG 7 - 16)
                  ======================================================== */}
              <g
                id="tier-residence-mid"
                onClick={() => setSelectedTierId('residence_mid')}
                className="cursor-pointer transition-all duration-300 group"
                transform={viewAngle === 'exploded' ? 'translate(0, -6)' : 'translate(0, 0)'}
              >
                {/* Left Facade */}
                <polygon
                  points="270,270 180,236 180,371 270,405"
                  fill="url(#glassWallLeft)"
                  stroke={selectedTierId === 'residence_mid' ? '#38bdf8' : '#334155'}
                  strokeWidth={selectedTierId === 'residence_mid' ? 2.5 : 1}
                />
                {/* Right Facade */}
                <polygon
                  points="270,270 360,236 360,371 270,405"
                  fill="url(#glassWallRight)"
                  stroke={selectedTierId === 'residence_mid' ? '#38bdf8' : '#334155'}
                  strokeWidth={selectedTierId === 'residence_mid' ? 2.5 : 1}
                />
                {/* Residential window array & floor slabs */}
                {[284, 298, 312, 326, 340, 354, 368, 382, 396].map((yVal, idx) => (
                  <g key={idx}>
                    <line x1="180" y1={yVal - 34} x2="270" y2={yVal} stroke="#64748b" strokeWidth="0.7" strokeOpacity="0.5" />
                    <line x1="270" y1={yVal} x2="360" y2={yVal - 34} stroke="#64748b" strokeWidth="0.7" strokeOpacity="0.5" />
                    {/* Window light highlights */}
                    <circle cx="225" cy={yVal - 16} r="1.5" fill={idx % 2 === 0 ? '#fde047' : '#38bdf8'} fillOpacity={nightMode ? 0.9 : 0.4} />
                    <circle cx="315" cy={yVal - 16} r="1.5" fill={idx % 3 === 0 ? '#fde047' : '#38bdf8'} fillOpacity={nightMode ? 0.9 : 0.4} />
                  </g>
                ))}
              </g>

              {/* ========================================================
                  TIER 2: LUXURY RESIDENCES & DUPLEX (TẦNG 17 - 24)
                  ======================================================== */}
              <g
                id="tier-residence-high"
                onClick={() => setSelectedTierId('residence_high')}
                className="cursor-pointer transition-all duration-300 group"
                transform={viewAngle === 'exploded' ? 'translate(0, -20)' : 'translate(0, 0)'}
              >
                {/* Left Facade */}
                <polygon
                  points="270,165 180,131 180,236 270,270"
                  fill="url(#glassWallLeft)"
                  stroke={selectedTierId === 'residence_high' ? '#38bdf8' : '#334155'}
                  strokeWidth={selectedTierId === 'residence_high' ? 2.5 : 1}
                />
                {/* Right Facade (Panoramic Double-glazed Glass Curtain) */}
                <polygon
                  points="270,165 360,131 360,236 270,270"
                  fill="url(#glassWallRight)"
                  stroke={selectedTierId === 'residence_high' ? '#38bdf8' : '#334155'}
                  strokeWidth={selectedTierId === 'residence_high' ? 2.5 : 1}
                />
                {/* Luxury cantilever glass balconies */}
                {[178, 192, 206, 220, 234, 248, 262].map((yVal, idx) => (
                  <g key={idx}>
                    <line x1="180" y1={yVal - 34} x2="270" y2={yVal} stroke="#38bdf8" strokeWidth="1" strokeOpacity="0.8" />
                    <line x1="270" y1={yVal} x2="360" y2={yVal - 34} stroke="#38bdf8" strokeWidth="1" strokeOpacity="0.8" />
                    {/* Warm interior penthouse chandeliers */}
                    <circle cx="215" cy={yVal - 16} r="2" fill="#fbbf24" fillOpacity={nightMode ? 1 : 0.6} />
                    <circle cx="245" cy={yVal - 8} r="2" fill="#38bdf8" fillOpacity={nightMode ? 0.9 : 0.5} />
                    <circle cx="295" cy={yVal - 8} r="2" fill="#fbbf24" fillOpacity={nightMode ? 1 : 0.6} />
                    <circle cx="325" cy={yVal - 16} r="2" fill="#38bdf8" fillOpacity={nightMode ? 0.9 : 0.5} />
                  </g>
                ))}
              </g>

              {/* ========================================================
                  TIER 1: SKY GARDEN & ROOFTOP PENTHOUSE (TẦNG 25 - 26)
                  ======================================================== */}
              <g
                id="tier-rooftop"
                onClick={() => setSelectedTierId('rooftop')}
                className="cursor-pointer transition-all duration-300 group"
                transform={viewAngle === 'exploded' ? 'translate(0, -35)' : 'translate(0, 0)'}
              >
                {/* Penthouse setback pavilion */}
                <polygon
                  points="270,115 200,89 200,131 270,157"
                  fill="url(#glassWallLeft)"
                  stroke={selectedTierId === 'rooftop' ? '#fbbf24' : '#334155'}
                  strokeWidth={selectedTierId === 'rooftop' ? 2.5 : 1}
                />
                <polygon
                  points="270,115 340,89 340,131 270,157"
                  fill="url(#glassWallRight)"
                  stroke={selectedTierId === 'rooftop' ? '#fbbf24' : '#334155'}
                  strokeWidth={selectedTierId === 'rooftop' ? 2.5 : 1}
                />

                {/* Rooftop Slab with Sky Garden turf & Helipad */}
                <polygon
                  points="270,75 350,105 270,135 190,105"
                  fill={nightMode ? '#0f3830' : '#15803d'}
                  stroke="#22c55e"
                  strokeWidth="1.2"
                />

                {/* Helipad Circle & "H" mark */}
                <ellipse cx="270" cy="105" rx="30" ry="12" fill="none" stroke="#f8fafc" strokeWidth="1.5" />
                <text x="270" y="109" textAnchor="middle" fill="#f8fafc" fontSize="12" fontWeight="bold" fontFamily="sans-serif">
                  H
                </text>

                {/* Solar PV Panels array */}
                <polygon
                  points="220,95 245,86 255,90 230,99"
                  fill="#1e3a8a"
                  stroke="#60a5fa"
                  strokeWidth="0.8"
                />
                <polygon
                  points="285,99 310,90 320,94 295,103"
                  fill="#1e3a8a"
                  stroke="#60a5fa"
                  strokeWidth="0.8"
                />

                {/* Rooftop Telecommunication Mast & Aviation Beacon */}
                <line x1="270" y1="75" x2="270" y2="40" stroke="#94a3b8" strokeWidth="2" />
                <circle cx="270" cy="40" r="3" fill="#ef4444" className="animate-ping" />
                <circle cx="270" cy="40" r="2" fill="#ef4444" />
              </g>

              {/* ========================================================
                  GLASS ELEVATOR SPINE & MOVING CABIN (RIGHT FLANK)
                  ======================================================== */}
              <g id="elevator-shaft">
                {/* Structural steel lattice frame */}
                <line x1="380" y1="140" x2="380" y2="505" stroke="#38bdf8" strokeWidth="1.5" strokeOpacity="0.7" />
                <line x1="400" y1="132" x2="400" y2="497" stroke="#38bdf8" strokeWidth="1.5" strokeOpacity="0.7" />
                <line x1="380" y1="140" x2="400" y2="132" stroke="#38bdf8" strokeWidth="1.5" />
                <line x1="380" y1="505" x2="400" y2="497" stroke="#38bdf8" strokeWidth="1.5" />

                {/* Diagonal steel trusses along the shaft */}
                {[180, 240, 300, 360, 420, 480].map((yT) => (
                  <g key={yT}>
                    <line x1="380" y1={yT - 30} x2="400" y2={yT - 22} stroke="#38bdf8" strokeWidth="0.8" strokeOpacity="0.4" />
                    <line x1="380" y1={yT} x2="400" y2={yT - 30} stroke="#38bdf8" strokeWidth="0.8" strokeOpacity="0.4" />
                  </g>
                ))}

                {/* Moving Elevator Cabin with Floor Indicator */}
                <g
                  transform={`translate(378, ${getElevatorY(currentElevatorFloor)})`}
                  style={{
                    transition: 'transform 1.4s cubic-bezier(0.45, 0.05, 0.55, 0.95)'
                  }}
                >
                  {/* Glass Cabin Body */}
                  <rect
                    x="0"
                    y="0"
                    width="24"
                    height="28"
                    rx="3"
                    fill={nightMode ? '#0284c7' : '#0284c7'}
                    fillOpacity="0.85"
                    stroke="#38bdf8"
                    strokeWidth="1.5"
                    filter="drop-shadow(0 0 8px rgba(56,189,248,0.7))"
                  />
                  {/* Cabin interior floor light */}
                  <rect x="3" y="3" width="18" height="12" rx="1.5" fill="#ffffff" fillOpacity="0.9" />
                  {/* Floor Level Text */}
                  <text
                    x="12"
                    y="22"
                    textAnchor="middle"
                    fill="#ffffff"
                    fontSize="8"
                    fontWeight="bold"
                    fontFamily="monospace"
                  >
                    {currentElevatorFloor > 0 ? `F${currentElevatorFloor}` : 'B1'}
                  </text>
                </g>
              </g>

              {/* ========================================================
                  ARCHITECTURAL LEADER LINE & FLOATING HUD DIMENSION
                  ======================================================== */}
              <g id="leader-callout">
                {selectedTierId === 'rooftop' && (
                  <g className="animate-in fade-in duration-300">
                    <line x1="200" y1="110" x2="110" y2="90" stroke="#38bdf8" strokeWidth="1.2" strokeDasharray="3 3" />
                    <circle cx="200" cy="110" r="3" fill="#38bdf8" />
                    <rect x="30" y="70" width="100" height="26" rx="4" fill="#0f172a" fillOpacity="0.85" stroke="#38bdf8" strokeWidth="1" />
                    <text x="80" y="86" textAnchor="middle" fill="#38bdf8" fontSize="10" fontWeight="bold" fontFamily="sans-serif">
                      TẦNG 25-26 (+92.4m)
                    </text>
                  </g>
                )}
                {selectedTierId === 'residence_high' && (
                  <g className="animate-in fade-in duration-300">
                    <line x1="180" y1="180" x2="100" y2="170" stroke="#38bdf8" strokeWidth="1.2" strokeDasharray="3 3" />
                    <circle cx="180" cy="180" r="3" fill="#38bdf8" />
                    <rect x="20" y="152" width="115" height="26" rx="4" fill="#0f172a" fillOpacity="0.85" stroke="#38bdf8" strokeWidth="1" />
                    <text x="77" y="168" textAnchor="middle" fill="#38bdf8" fontSize="10" fontWeight="bold" fontFamily="sans-serif">
                      TẦNG 17-24 (+64.8m)
                    </text>
                  </g>
                )}
                {selectedTierId === 'residence_mid' && (
                  <g className="animate-in fade-in duration-300">
                    <line x1="180" y1="320" x2="100" y2="310" stroke="#38bdf8" strokeWidth="1.2" strokeDasharray="3 3" />
                    <circle cx="180" cy="320" r="3" fill="#38bdf8" />
                    <rect x="20" y="292" width="115" height="26" rx="4" fill="#0f172a" fillOpacity="0.85" stroke="#38bdf8" strokeWidth="1" />
                    <text x="77" y="308" textAnchor="middle" fill="#38bdf8" fontSize="10" fontWeight="bold" fontFamily="sans-serif">
                      TẦNG 7-16 (+32.0m)
                    </text>
                  </g>
                )}
                {selectedTierId === 'commercial' && (
                  <g className="animate-in fade-in duration-300">
                    <line x1="180" y1="420" x2="100" y2="410" stroke="#22d3ee" strokeWidth="1.2" strokeDasharray="3 3" />
                    <circle cx="180" cy="420" r="3" fill="#22d3ee" />
                    <rect x="20" y="392" width="115" height="26" rx="4" fill="#0f172a" fillOpacity="0.85" stroke="#22d3ee" strokeWidth="1" />
                    <text x="77" y="408" textAnchor="middle" fill="#22d3ee" fontSize="10" fontWeight="bold" fontFamily="sans-serif">
                      TẦNG 2-6 (+10.5m)
                    </text>
                  </g>
                )}
                {selectedTierId === 'lobby' && (
                  <g className="animate-in fade-in duration-300">
                    <line x1="180" y1="490" x2="100" y2="480" stroke="#38bdf8" strokeWidth="1.2" strokeDasharray="3 3" />
                    <circle cx="180" cy="490" r="3" fill="#38bdf8" />
                    <rect x="25" y="462" width="105" height="26" rx="4" fill="#0f172a" fillOpacity="0.85" stroke="#38bdf8" strokeWidth="1" />
                    <text x="77" y="478" textAnchor="middle" fill="#38bdf8" fontSize="10" fontWeight="bold" fontFamily="sans-serif">
                      SẢNH ĐÓN (±0.00m)
                    </text>
                  </g>
                )}
                {selectedTierId === 'basement' && (
                  <g className="animate-in fade-in duration-300">
                    <line x1="170" y1="550" x2="90" y2="550" stroke="#10b981" strokeWidth="1.2" strokeDasharray="3 3" />
                    <circle cx="170" cy="550" r="3" fill="#10b981" />
                    <rect x="15" y="534" width="110" height="26" rx="4" fill="#0f172a" fillOpacity="0.85" stroke="#10b981" strokeWidth="1" />
                    <text x="70" y="550" textAnchor="middle" fill="#10b981" fontSize="10" fontWeight="bold" fontFamily="sans-serif">
                      HẦM B1-B2 (-7.2m)
                    </text>
                  </g>
                )}
              </g>
            </svg>
          </div>

          {/* Quick Elevator Call Overlay Toolbar */}
          <div className="absolute bottom-2 inset-x-2 flex items-center justify-between px-3 py-2 rounded-xl bg-slate-900/90 backdrop-blur-md border border-slate-700/80 text-[11px] z-20">
            <div className="flex items-center gap-1.5 text-slate-200">
              <span className="w-1.5 h-1.5 rounded-full bg-sky-400 animate-pulse" />
              <span className="font-semibold hidden sm:inline">
                Điều khiển thang máy kính:
              </span>
              <span className="font-semibold sm:hidden">
                Thang máy:
              </span>
            </div>
            <div className="flex items-center gap-1">
              {[
                { label: 'F26 (Sky)', floor: 26 },
                { label: 'F18 (Cao)', floor: 18 },
                { label: 'F8 (Dân)', floor: 8 },
                { label: 'F1 (Sảnh)', floor: 1 },
                { label: 'B1 (Hầm)', floor: -1 }
              ].map((btn) => (
                <button
                  key={btn.label}
                  onClick={() => callElevator(btn.floor)}
                  disabled={isElevatorGliding}
                  className={`px-2.5 py-1 rounded text-[10px] font-mono font-medium transition-all ${
                    currentElevatorFloor === btn.floor
                      ? 'bg-sky-500 text-white font-bold shadow-xs'
                      : 'bg-slate-800 hover:bg-slate-700 text-slate-300'
                  }`}
                >
                  {btn.label}
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* Right Side: Realtime Telemetry HUD Glass Card for Selected Tier */}
        <div className="lg:col-span-5 space-y-4">
          <div
            className={`p-5 sm:p-6 rounded-2xl border transition-all duration-500 ${
              nightMode
                ? 'bg-slate-900/90 border-slate-700/80 text-white shadow-xl shadow-cyan-500/5'
                : 'bg-white border-neutral-300 text-neutral-950 shadow-xl'
            }`}
          >
            {/* Tier Header */}
            <div className={`flex items-start justify-between gap-3 pb-3 border-b ${
              nightMode ? 'border-slate-800' : 'border-neutral-200'
            }`}>
              <div>
                <div className="flex items-center gap-2">
                  <span className="text-[10px] font-mono uppercase tracking-wider text-sky-500 font-bold">
                    {selectedTier.floors} • Cao độ {selectedTier.elevation}
                  </span>
                </div>
                <h3 className={`text-lg sm:text-xl font-bold tracking-tight mt-0.5 ${
                  nightMode ? 'text-white' : 'text-neutral-950'
                }`}>
                  {selectedTier.name}
                </h3>
              </div>
              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-500 border border-emerald-500/20">
                <CheckCircle2 className="w-3.5 h-3.5" />
                {selectedTier.securityStatus}
              </span>
            </div>

            {/* Telemetry Metric Grid */}
            <div className="grid grid-cols-2 gap-3 mt-4">
              {/* Metric 1: Nhiệt độ */}
              <div
                className={`p-3.5 rounded-xl border ${
                  nightMode
                    ? 'bg-slate-800/70 border-slate-700'
                    : 'bg-neutral-50 border-neutral-200'
                }`}
              >
                <div className={`flex items-center gap-1.5 text-[11px] font-medium ${
                  nightMode ? 'text-slate-400' : 'text-neutral-600'
                }`}>
                  <Thermometer className="w-3.5 h-3.5 text-amber-500" />
                  <span>Nhiệt độ trung bình</span>
                </div>
                <div className={`text-2xl font-bold font-mono mt-1 ${
                  nightMode ? 'text-white' : 'text-neutral-950'
                }`}>
                  {selectedTier.temperature}°C
                </div>
                <span className="text-[10px] text-emerald-500 font-medium">HVAC biến tần tối ưu</span>
              </div>

              {/* Metric 2: Tiêu thụ điện */}
              <div
                className={`p-3.5 rounded-xl border ${
                  nightMode
                    ? 'bg-slate-800/70 border-slate-700'
                    : 'bg-neutral-50 border-neutral-200'
                }`}
              >
                <div className={`flex items-center gap-1.5 text-[11px] font-medium ${
                  nightMode ? 'text-slate-400' : 'text-neutral-600'
                }`}>
                  <Zap className="w-3.5 h-3.5 text-sky-500" />
                  <span>Công suất điện</span>
                </div>
                <div className={`text-2xl font-bold font-mono mt-1 ${
                  nightMode ? 'text-white' : 'text-neutral-950'
                }`}>
                  {selectedTier.powerKw} kW
                </div>
                <span className="text-[10px] text-sky-500 font-medium">-14% so với giờ cao điểm</span>
              </div>

              {/* Metric 3: Cư dân */}
              <div
                className={`p-3.5 rounded-xl border ${
                  nightMode
                    ? 'bg-slate-800/70 border-slate-700'
                    : 'bg-neutral-50 border-neutral-200'
                }`}
              >
                <div className={`flex items-center gap-1.5 text-[11px] font-medium ${
                  nightMode ? 'text-slate-400' : 'text-neutral-600'
                }`}>
                  <Activity className="w-3.5 h-3.5 text-indigo-500" />
                  <span>Cư dân hiện diện</span>
                </div>
                <div className={`text-2xl font-bold font-mono mt-1 ${
                  nightMode ? 'text-white' : 'text-neutral-950'
                }`}>
                  {selectedTier.residentsCount} người
                </div>
                <span className="text-[10px] text-emerald-500 font-medium">FaceID xác thực 100%</span>
              </div>

              {/* Metric 4: An toàn PCCC */}
              <div
                className={`p-3.5 rounded-xl border ${
                  nightMode
                    ? 'bg-slate-800/70 border-slate-700'
                    : 'bg-neutral-50 border-neutral-200'
                }`}
              >
                <div className={`flex items-center gap-1.5 text-[11px] font-medium ${
                  nightMode ? 'text-slate-400' : 'text-neutral-600'
                }`}>
                  <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
                  <span>Bảo vệ PCCC</span>
                </div>
                <div className="text-2xl font-bold font-mono mt-1 text-emerald-500">
                  100%
                </div>
                <span className={`text-[10px] font-medium ${
                  nightMode ? 'text-slate-400' : 'text-neutral-600'
                }`}>Cảm biến khói online</span>
              </div>
            </div>

            {/* Architectural & Operational Description */}
            <div className={`mt-4 p-3.5 rounded-xl border text-xs leading-relaxed ${
              nightMode
                ? 'bg-slate-800/60 border-slate-700 text-slate-300'
                : 'bg-neutral-100/90 border-neutral-200 text-neutral-800'
            }`}>
              <span className={`font-semibold ${nightMode ? 'text-white' : 'text-neutral-950'}`}>Thông số thiết kế kiến trúc: </span>
              {selectedTier.details}
            </div>

            {/* Quick Interactive Floor Selector Pills */}
            <div className={`mt-4 pt-3.5 border-t ${
              nightMode ? 'border-slate-800' : 'border-neutral-200'
            }`}>
              <div className={`text-[11px] font-bold uppercase tracking-wider mb-2.5 ${
                nightMode ? 'text-neutral-400' : 'text-neutral-600'
              }`}>
                Chọn phân tầng kiến trúc để kiểm tra:
              </div>
              <div className="flex flex-wrap gap-1.5">
                {BUILDING_TIERS.map((tier) => (
                  <button
                    key={tier.id}
                    onClick={() => setSelectedTierId(tier.id)}
                    className={`px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all ${
                      selectedTierId === tier.id
                        ? 'bg-sky-500 text-white font-bold shadow-md shadow-sky-500/20 scale-105'
                        : nightMode
                        ? 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                        : 'bg-neutral-100 text-neutral-800 hover:bg-neutral-200 border border-neutral-200'
                    }`}
                  >
                    {tier.name.split('&')[0]}
                  </button>
                ))}
              </div>
            </div>
          </div>
        </div>
      </div>
      )}
    </div>
  );
};
