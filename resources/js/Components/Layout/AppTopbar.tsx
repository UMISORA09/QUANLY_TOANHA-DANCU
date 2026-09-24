import React, { useState, useEffect } from 'react';
import {
  PanelLeft,
  Maximize2,
  Minimize2,
  HelpCircle,
  Bell,
  ChevronDown,
  Layers,
  Sparkles,
  ExternalLink,
} from 'lucide-react';
import { UserRole, QUICK_PORTALS } from './navigationConfig';

export interface AppTopbarProps {
  isSidebarCollapsed: boolean;
  onToggleSidebar: () => void;
  userName?: string;
  userRole?: UserRole;
  portalRole?: UserRole;
  statusText?: string;
  extraActions?: React.ReactNode;
  onNotificationClick?: () => void;
  unreadNotificationCount?: number;
  onHelpClick?: () => void;
  showPortalSwitcher?: boolean;
}

export const AppTopbar: React.FC<AppTopbarProps> = ({
  isSidebarCollapsed,
  onToggleSidebar,
  userName = 'Người dùng Cassavas',
  userRole = 'manager',
  portalRole,
  statusText,
  extraActions,
  onNotificationClick,
  unreadNotificationCount = 3,
  onHelpClick,
  showPortalSwitcher = false,
}) => {
  const [isFullscreen, setIsFullscreen] = useState<boolean>(false);
  const [currentTime, setCurrentTime] = useState<string>('');
  const [isPortalDropdownOpen, setIsPortalDropdownOpen] = useState<boolean>(false);

  // ĐẢM BẢO QUYỀN ADMIN: Kiểm tra cả prop và session lưu trong localStorage
  // Khi Admin di chuyển vào Cổng Lễ Tân, Cổng Cư Dân, hay Cổng Quản Lý,
  // quyền Admin và nút "Chuyển Cổng" KHÔNG BAO GIỜ BỊ MẤT!
  const sessionUser = (() => {
    try {
      const saved = localStorage.getItem('smartcassavas_session');
      if (saved) {
        return JSON.parse(saved);
      }
    } catch {
      // ignore
    }
    return null;
  })();

  const effectiveUserRole =
    userRole === 'admin' || userRole === 'dev' || sessionUser?.role === 'admin' || sessionUser?.role === 'dev'
      ? 'admin'
      : (userRole || sessionUser?.role || 'manager');

  const isAdmin = effectiveUserRole === 'admin';

  // CHỈ CÓ ADMIN / DEV MỚI CÓ QUYỀN ĐƯỢC CHUYỂN CỔNG
  const canSwitchPortal = isAdmin || (showPortalSwitcher && (userRole === 'admin' || userRole === 'dev'));

  // Fullscreen Detection
  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(!!document.fullscreenElement);
    };
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => document.removeEventListener('fullscreenchange', handleFullscreenChange);
  }, []);

  const toggleFullscreen = async () => {
    try {
      if (!document.fullscreenElement) {
        await document.documentElement.requestFullscreen();
      } else {
        if (document.exitFullscreen) {
          await document.exitFullscreen();
        }
      }
    } catch {
      // ignore
    }
  };

  // Live Vietnamese Date & Time
  useEffect(() => {
    const updateTime = () => {
      const now = new Date();
      const days = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
      const dayName = days[now.getDay()];
      const day = String(now.getDate()).padStart(2, '0');
      const month = String(now.getMonth() + 1).padStart(2, '0');
      const year = now.getFullYear();
      const hours = String(now.getHours()).padStart(2, '0');
      const minutes = String(now.getMinutes()).padStart(2, '0');
      const seconds = String(now.getSeconds()).padStart(2, '0');
      setCurrentTime(`${dayName}, ${day}/${month}/${year} · ${hours}:${minutes}:${seconds}`);
    };

    updateTime();
    const interval = setInterval(updateTime, 1000);
    return () => clearInterval(interval);
  }, []);

  // Default status text & role badge per role
  const roleMeta = {
    admin: {
      status: 'Hệ thống Quản trị Toàn quyền',
      badgeLabel: 'QUẢN TRỊ VIÊN (ADMIN)',
      badgeClass: 'bg-amber-100 text-amber-900 border-amber-300/80 shadow-xs',
      pulseColor: 'bg-amber-500',
    },
    manager: {
      status: 'Bàn làm việc Quản lý Vận hành',
      badgeLabel: 'BAN QUẢN LÝ (MANAGER)',
      badgeClass: 'bg-sky-100 text-sky-900 border-sky-300/80 shadow-xs',
      pulseColor: 'bg-sky-500',
    },
    receptionist: {
      status: 'Ca trực Lễ tân & An ninh',
      badgeLabel: 'LỄ TÂN & AN NINH',
      badgeClass: 'bg-indigo-100 text-indigo-900 border-indigo-300/80 shadow-xs',
      pulseColor: 'bg-indigo-500',
    },
    resident: {
      status: 'Cổng dịch vụ cư dân trực tuyến',
      badgeLabel: 'CƯ DÂN',
      badgeClass: 'bg-emerald-100 text-emerald-900 border-emerald-300/80 shadow-xs',
      pulseColor: 'bg-emerald-500',
    },
  }[effectiveUserRole] || {
    status: 'Hệ thống vận hành trơn tru',
    badgeLabel: String(effectiveUserRole).toUpperCase(),
    badgeClass: 'bg-slate-100 text-slate-800 border-slate-300',
    pulseColor: 'bg-slate-500',
  };

  const resolvedStatus = statusText || roleMeta.status;

  return (
    <header className="shrink-0 z-30 bg-white/90 backdrop-blur-xl border-b border-white/60 shadow-xs glass-specular-edge transition-all duration-300">
      <div className="w-full max-w-[2000px] mx-auto px-3 sm:px-5 lg:px-8 h-16 lg:h-18 flex items-center justify-between gap-2 sm:gap-4 transition-all duration-300">
        {/* ================= LEFT SECTION ================= */}
        <div className="flex items-center gap-2 sm:gap-3 min-w-0">
          {/* Panel / Hamburger Toggle Button (Desktop & Mobile) */}
          <button
            type="button"
            onClick={onToggleSidebar}
            aria-label={isSidebarCollapsed ? 'Mở rộng menu điều hướng' : 'Thu nhỏ menu điều hướng'}
            aria-expanded={!isSidebarCollapsed}
            className="p-2 rounded-xl bg-white/80 hover:bg-white border border-slate-200/80 text-slate-600 hover:text-sky-600 shadow-xs hover:shadow-sm transition-all flex items-center justify-center cursor-pointer group shrink-0 active:scale-95"
            title={isSidebarCollapsed ? 'Mở rộng menu (Sidebar)' : 'Thu nhỏ menu (Sidebar)'}
          >
            <PanelLeft
              className={`w-4 h-4 transition-transform duration-200 ${
                isSidebarCollapsed ? 'text-sky-600 rotate-180' : 'group-hover:scale-105'
              }`}
            />
          </button>

          {/* Date, Time, Greeting & Role Badge */}
          <div className="min-w-0">
            {/* Live Vietnamese Time - Ẩn trên mobile để tối ưu không gian hiển thị */}
            <div className="text-[11px] font-medium text-slate-400 hidden sm:flex items-center gap-2 truncate">
              <span>{currentTime || 'Hệ thống Smart Cassavas'}</span>
              <span className="w-1 h-1 rounded-full bg-slate-300" />
              <span className="inline-flex items-center gap-1 font-medium shrink-0 text-slate-600">
                <span className={`w-1.5 h-1.5 rounded-full ${roleMeta.pulseColor} animate-pulse`} />
                {resolvedStatus}
              </span>
            </div>

            {/* Chào & Vai trò người dùng (Tự động co giãn chống tràn) */}
            <div className="text-xs sm:text-sm md:text-base font-bold text-neutral-900 flex items-center gap-1.5 sm:gap-2 min-w-0">
              <span className="truncate max-w-[110px] sm:max-w-xs md:max-w-none">
                Xin chào, {isAdmin ? (sessionUser?.name || 'Admin') : userName}
              </span>
              <span
                className={`inline-flex items-center px-1.5 sm:px-2 py-0.5 rounded-md text-[9px] sm:text-[10px] font-extrabold border tracking-wider font-mono shrink-0 ${roleMeta.badgeClass}`}
                title={`Vai trò người dùng: ${roleMeta.badgeLabel}`}
              >
                {roleMeta.badgeLabel}
              </span>
              {isAdmin && portalRole && portalRole !== 'admin' && (
                <span className="hidden md:inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-semibold bg-slate-100 text-slate-600 border border-slate-200 uppercase font-mono shrink-0">
                  Đang xem: {portalRole === 'receptionist' ? 'Lễ Tân' : portalRole === 'resident' ? 'Cư Dân' : 'Quản Lý'}
                </span>
              )}
            </div>
          </div>
        </div>

        {/* ================= RIGHT SECTION ================= */}
        <div className="flex items-center gap-1.5 sm:gap-2.5 shrink-0">
          {/* Extra Custom Action Slot (Zoom control, Refresh button, 3D model, Apartment badge, etc.) */}
          {extraActions && <div className="flex items-center gap-1.5 sm:gap-2">{extraActions}</div>}

          {/* Fullscreen Toggle - Ẩn trên mobile vì không cần thiết */}
          <button
            type="button"
            onClick={toggleFullscreen}
            className="hidden sm:flex p-2 rounded-xl bg-white/80 hover:bg-white text-slate-500 hover:text-slate-800 border border-slate-200/70 shadow-xs hover:shadow-sm transition-all cursor-pointer group active:scale-95"
            title={isFullscreen ? 'Thoát toàn màn hình (Esc)' : 'Toàn màn hình (F11)'}
          >
            {isFullscreen ? (
              <Minimize2 className="w-4 h-4 text-sky-600" />
            ) : (
              <Maximize2 className="w-4 h-4 group-hover:scale-110 transition-transform" />
            )}
          </button>

          {/* Quick Portal Switcher - CHỈ ADMIN MỚI CÓ ĐẶC QUYỀN CHUYỂN CỔNG */}
          {canSwitchPortal && (
            <div className="relative">
              <button
                type="button"
                onClick={() => setIsPortalDropdownOpen((prev) => !prev)}
                className="flex items-center gap-1 sm:gap-1.5 px-2 sm:px-3 py-1.5 rounded-xl bg-gradient-to-r from-amber-500/15 via-amber-500/10 to-amber-500/20 hover:from-amber-500/25 hover:to-amber-500/30 text-xs font-bold text-amber-900 border border-amber-300/90 shadow-xs hover:shadow-sm transition-all cursor-pointer group active:scale-95 shrink-0"
                title="Đặc quyền Admin: Chuyển đổi linh hoạt giữa tất cả các phân hệ cổng"
              >
                <Sparkles className="w-3.5 h-3.5 text-amber-600 group-hover:rotate-12 transition-transform shrink-0" />
                <span className="hidden sm:inline font-semibold">Chuyển Cổng</span>
                <span className="text-[9px] px-1 py-0.2 rounded bg-amber-200 text-amber-900 font-mono font-extrabold uppercase shrink-0">
                  Admin
                </span>
                <ChevronDown
                  className={`w-3.5 h-3.5 text-amber-700 transition-transform duration-200 shrink-0 ${
                    isPortalDropdownOpen ? 'rotate-180' : ''
                  }`}
                />
              </button>

              {isPortalDropdownOpen && (
                <>
                  <div
                    onClick={() => setIsPortalDropdownOpen(false)}
                    className="fixed inset-0 z-40"
                  />
                  <div className="absolute right-0 top-full mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-2xl bg-white/95 backdrop-blur-2xl border border-slate-200/90 shadow-2xl p-2.5 z-50 animate-in fade-in zoom-in-95 duration-150">
                    <div className="px-3 py-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider font-mono border-b border-slate-100 mb-1.5 flex items-center justify-between">
                      <span>ĐẶC QUYỀN QUẢN TRỊ VIÊN</span>
                      <span className="text-amber-600 font-bold">CHUYỂN CỔNG</span>
                    </div>
                    {QUICK_PORTALS.map((portal) => {
                      const Icon = portal.icon;
                      const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';
                      const isCurrent =
                        (portalRole ? portal.role === portalRole : false) ||
                        portal.path === currentPath ||
                        (portal.path === '/dev' && (currentPath.startsWith('/dev') || currentPath.startsWith('/developer'))) ||
                        (portal.path === '/admin' && currentPath.startsWith('/admin')) ||
                        (portal.path === '/quan-ly' && (currentPath.startsWith('/quan-ly') || currentPath.startsWith('/manager') || currentPath === '/dashboard')) ||
                        (portal.path === '/le-tan' && (currentPath.startsWith('/le-tan') || currentPath.startsWith('/receptionist'))) ||
                        (portal.path === '/cu-dan' && (currentPath.startsWith('/cu-dan') || currentPath.startsWith('/resident')));
                      return (
                        <a
                          key={portal.role}
                          href={portal.path}
                          onClick={() => setIsPortalDropdownOpen(false)}
                          className={`flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all my-0.5 ${
                            isCurrent
                              ? 'bg-amber-50/90 text-amber-950 font-bold border border-amber-200/80 shadow-2xs'
                              : 'text-slate-600 hover:text-neutral-900 hover:bg-slate-100/80'
                          }`}
                        >
                          <div className="flex items-center gap-2.5 min-w-0">
                            <div className="w-7 h-7 rounded-lg bg-white border border-slate-200/60 flex items-center justify-center shrink-0 shadow-2xs">
                              <Icon className={`w-4 h-4 ${portal.color}`} />
                            </div>
                            <div className="flex flex-col text-left min-w-0">
                              <span className="font-semibold text-slate-900 truncate">{portal.label}</span>
                              <span className="text-[10px] text-slate-400 font-mono">{portal.path}</span>
                            </div>
                          </div>
                          {isCurrent ? (
                            <span className="inline-flex items-center gap-1 text-[10px] text-amber-700 font-semibold shrink-0 ml-2">
                              <span>Hiện tại</span>
                              <span className="w-2 h-2 rounded-full bg-amber-500 ring-2 ring-amber-200" />
                            </span>
                          ) : (
                            <ExternalLink className="w-3.5 h-3.5 text-slate-400 shrink-0 ml-2" />
                          )}
                        </a>
                      );
                    })}
                  </div>
                </>
              )}
            </div>
          )}

          {/* System Health Status */}
          <div className="hidden xl:flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50/80 border border-emerald-200/60 text-emerald-700 text-xs font-medium">
            <span className="relative flex h-2 w-2">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
            </span>
            <span>99.98% Ổn định</span>
          </div>

          {/* Help Button */}
          {onHelpClick && (
            <button
              type="button"
              onClick={onHelpClick}
              className="p-2 rounded-xl bg-white/80 hover:bg-white text-slate-500 hover:text-slate-800 border border-slate-200/70 shadow-xs transition-all relative cursor-pointer active:scale-95"
              title="Trợ giúp & Hướng dẫn"
            >
              <HelpCircle className="w-4 h-4" />
            </button>
          )}

          {/* Notification Bell */}
          <div className="relative">
            <button
              type="button"
              onClick={onNotificationClick}
              className="p-2 rounded-xl bg-white/80 hover:bg-white text-slate-500 hover:text-slate-800 border border-slate-200/70 shadow-xs transition-all relative group cursor-pointer active:scale-95"
              title="Thông báo hệ thống"
            >
              <Bell className="w-4 h-4 group-hover:scale-110 transition-transform" />
              {unreadNotificationCount > 0 && (
                <span className="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-rose-500 ring-2 ring-white animate-pulse" />
              )}
            </button>
          </div>
        </div>
      </div>
    </header>
  );
};
