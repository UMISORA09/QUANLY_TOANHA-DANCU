import React from 'react';
import {
  X,
  LogOut,
  ChevronDown,
  Building2,
  Sparkles,
} from 'lucide-react';
import {
  UserRole,
  NavigationItem,
  NavigationRoleConfig,
  NAVIGATION_CONFIGS,
} from './navigationConfig';

export interface AppSidebarProps {
  role?: UserRole;
  isCollapsed: boolean;
  onToggleCollapse: () => void;
  mobileOpen: boolean;
  onMobileClose: () => void;
  activeItemId: string;
  onItemClick: (id: string) => void;
  customItems?: NavigationItem[];
  customRoleConfig?: Partial<NavigationRoleConfig>;
  userName?: string;
  userEmail?: string;
  onLogout?: () => void;
  onNavigateHome?: () => void;
  onSetHoveredTooltip: (
    tooltip: { label: string; badge?: string | number | null; top: number } | null
  ) => void;
}

export const AppSidebar: React.FC<AppSidebarProps> = ({
  role = 'manager',
  isCollapsed,
  onToggleCollapse,
  mobileOpen,
  onMobileClose,
  activeItemId,
  onItemClick,
  customItems,
  customRoleConfig,
  userName = 'Người Dùng',
  userEmail = 'user@cassavas.vn',
  onLogout,
  onNavigateHome,
  onSetHoveredTooltip,
}) => {
  const config = {
    ...NAVIGATION_CONFIGS[role],
    ...customRoleConfig,
  };

  const menuItems = customItems || config.items;
  const RoleIcon = config.icon || Building2;

  // Khi mở Drawer trên mobile (mobileOpen = true), LUÔN hiển thị đầy đủ tên, logo, text
  // Chỉ áp dụng thu gọn (isCollapsed) khi ở màn hình lớn Desktop
  const isDesktopCollapsed = isCollapsed && !mobileOpen;

  // Lấy 2 ký tự đầu cho Avatar
  const getInitials = (name: string) => {
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    return name.slice(0, 2).toUpperCase();
  };

  const getBadgeStyle = (item: NavigationItem, isActive: boolean) => {
    if (isActive) {
      return 'bg-neutral-800 text-sky-300 ring-1 ring-white/10';
    }
    const badgeType = item.badgeType;
    const badgeText = String(item.badge || '');

    if (badgeType === 'danger' || badgeText.includes('mới') || badgeText === '24' || badgeText === '12') {
      return 'bg-rose-50 text-rose-600 border border-rose-200/80';
    }
    if (badgeType === 'warning' || badgeText === '86' || badgeText.includes('chờ')) {
      return 'bg-amber-50 text-amber-700 border border-amber-200/80';
    }
    if (badgeType === 'info' || badgeText.includes('đang ở')) {
      return 'bg-sky-50 text-sky-700 border border-sky-200/80';
    }
    return 'bg-slate-100 text-slate-600 border border-slate-200/60';
  };

  return (
    <>
      {/* Mobile Drawer Backdrop */}
      {mobileOpen && (
        <div
          onClick={onMobileClose}
          className="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-xs lg:hidden transition-opacity"
        />
      )}

      {/* ========================================================
          LEFT SIDEBAR (ULTRA GLASSMORPHISM & COLLAPSIBLE)
          ======================================================== */}
      <aside
        aria-label="Menu điều hướng chính"
        aria-hidden={!mobileOpen}
        className={`fixed lg:relative top-0 left-0 z-50 h-screen shrink-0 bg-white/95 lg:bg-white/85 backdrop-blur-2xl border-r border-white/60 shadow-2xl lg:shadow-xs flex-col justify-between transition-all duration-300 ease-in-out glass-specular-edge overflow-x-hidden ${
          mobileOpen
            ? 'flex w-72 max-w-[85vw] translate-x-0'
            : isDesktopCollapsed
            ? 'hidden lg:flex lg:w-20 lg:translate-x-0'
            : 'hidden lg:flex lg:w-72 lg:translate-x-0'
        }`}
      >
        {/* ================= TOP BRAND HEADER ================= */}
        <div
          className={`p-4 border-b border-slate-200/60 flex items-center ${
            isDesktopCollapsed ? 'justify-center' : 'justify-between'
          }`}
        >
          <div
            className={`flex items-center ${isDesktopCollapsed ? 'justify-center' : 'gap-3'} min-w-0 cursor-pointer`}
            onClick={() => {
              if (onNavigateHome) {
                onNavigateHome();
              } else if (isDesktopCollapsed) {
                onToggleCollapse();
              }
            }}
            title={isDesktopCollapsed ? 'Bấm để mở rộng thanh menu' : 'Về trang chủ'}
          >
            <div className="relative group shrink-0">
              <div className="w-10 h-10 rounded-xl bg-neutral-900 flex items-center justify-center text-white shadow-md shadow-neutral-900/20 group-hover:scale-105 transition-all duration-300">
                <RoleIcon className="w-5 h-5 text-sky-400 group-hover:rotate-6 transition-transform" />
              </div>
              <div className="absolute -inset-0.5 rounded-xl bg-gradient-to-r from-sky-400 to-indigo-500 opacity-0 group-hover:opacity-30 blur-xs transition-opacity -z-10" />
            </div>

            {!isDesktopCollapsed && (
              <div className="min-w-0 transition-opacity duration-200">
                <div className="flex items-center gap-1.5">
                  <span className="font-extrabold text-sm tracking-wider text-neutral-900 font-mono truncate">
                    SMART CASSAVAS
                  </span>
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                </div>
                <span className="text-[10px] tracking-widest font-semibold uppercase text-sky-600 block truncate">
                  {config.portalSubtitle}
                </span>
              </div>
            )}
          </div>

          {!isDesktopCollapsed && (
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={onMobileClose}
                className="lg:hidden p-1.5 rounded-xl hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition-colors cursor-pointer active:scale-95"
                aria-label="Đóng menu điều hướng"
                title="Đóng menu"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
          )}
        </div>

        {/* ================= SECTION TITLE ================= */}
        <div className={`px-4 pt-4 pb-2 shrink-0 ${isDesktopCollapsed ? 'flex justify-center' : ''}`}>
          {isDesktopCollapsed ? (
            <div className="w-8 h-1 rounded-full bg-slate-200 flex items-center justify-center relative my-1">
              <span className="w-1.5 h-1.5 rounded-full bg-sky-500 animate-pulse" />
            </div>
          ) : (
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold tracking-wider text-slate-400 uppercase font-mono truncate">
                {config.sectionTitle}
              </span>
              <span className="relative flex h-2 w-2 shrink-0">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-sky-400 opacity-75" />
                <span className="relative inline-flex rounded-full h-2 w-2 bg-sky-500" />
              </span>
            </div>
          )}
        </div>

        {/* ================= SCROLLABLE MENU LIST ================= */}
        <div className="relative flex-1 min-h-0 flex flex-col overflow-hidden group/nav">
          <nav
            onScroll={() => onSetHoveredTooltip(null)}
            className={`flex-1 overflow-y-auto overflow-x-hidden ${
              isDesktopCollapsed ? 'px-2' : 'pl-3 pr-2'
            } py-2 pb-8 space-y-1 custom-scrollbar`}
          >
            {menuItems.map((item) => {
              const Icon = item.icon;
              const isActive = activeItemId === item.id;

              return (
                <div key={item.id} className="relative group">
                  <button
                    type="button"
                    onClick={() => {
                      onSetHoveredTooltip(null);
                      onItemClick(item.id);
                      if (mobileOpen) {
                        onMobileClose();
                      }
                    }}
                    onMouseEnter={(e) => {
                      if (isDesktopCollapsed) {
                        const rect = e.currentTarget.getBoundingClientRect();
                        onSetHoveredTooltip({
                          label: item.label,
                          badge: item.badge,
                          top: rect.top + rect.height / 2,
                        });
                      }
                    }}
                    onMouseLeave={() => onSetHoveredTooltip(null)}
                    className={`w-full flex items-center ${
                      isDesktopCollapsed ? 'justify-center px-0 py-2.5' : 'justify-between px-3 py-2.5'
                    } rounded-xl text-xs font-medium transition-all duration-200 relative cursor-pointer active:scale-[0.98] ${
                      isActive
                        ? 'bg-neutral-900 text-white shadow-md shadow-neutral-900/15'
                        : 'text-slate-600 hover:text-neutral-900 hover:bg-white/90 hover:shadow-xs'
                    }`}
                  >
                    <div className={`flex items-center ${isDesktopCollapsed ? 'justify-center' : 'gap-3'} min-w-0`}>
                      <div
                        className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 transition-colors ${
                          isActive
                            ? 'bg-neutral-800 text-sky-400'
                            : 'bg-slate-100/80 text-slate-500 group-hover:text-sky-600 group-hover:bg-sky-50'
                        }`}
                      >
                        <Icon className="w-4 h-4 transition-transform group-hover:scale-110" />
                      </div>
                      {!isDesktopCollapsed && <span className="truncate">{item.label}</span>}
                    </div>

                    {!isDesktopCollapsed && item.badge !== undefined && item.badge !== null && (
                      <span
                        className={`text-[10px] px-2 py-0.5 rounded-full font-semibold transition-all shrink-0 ${getBadgeStyle(
                          item,
                          isActive
                        )}`}
                      >
                        {item.badge}
                      </span>
                    )}

                    {isActive && !isDesktopCollapsed && (
                      <div className="absolute right-0 top-1/2 -translate-y-1/2 w-1 h-5 rounded-l-full bg-sky-400" />
                    )}
                  </button>
                </div>
              );
            })}
          </nav>

          {/* Bottom Gradient Fade & Peek Indicator */}
          <div className="pointer-events-none absolute bottom-0 left-0 right-0 h-9 bg-gradient-to-t from-white/95 via-white/50 to-transparent flex items-end justify-center pb-1">
            <ChevronDown className="w-3.5 h-3.5 text-slate-400 animate-bounce" />
          </div>
        </div>

        {/* ================= BOTTOM USER PROFILE ================= */}
        <div
          className={`p-3 border-t border-slate-200/60 bg-white/60 backdrop-blur-md ${
            isDesktopCollapsed ? 'flex flex-col items-center gap-2' : ''
          }`}
        >
          {isDesktopCollapsed ? (
            <div
              onMouseEnter={(e) => {
                const rect = e.currentTarget.getBoundingClientRect();
                onSetHoveredTooltip({
                  label: `${userName} · ${userEmail}`,
                  badge: config.roleName,
                  top: rect.top + rect.height / 2,
                });
              }}
              onMouseLeave={() => onSetHoveredTooltip(null)}
              className="relative group cursor-pointer"
            >
              <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center text-white font-bold text-xs shadow-xs hover:scale-105 transition-transform">
                {getInitials(userName)}
              </div>
              <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-500 border-2 border-white" />
            </div>
          ) : (
            <div className="flex items-center justify-between p-2 rounded-xl hover:bg-white/80 transition-colors border border-transparent hover:border-slate-200/60 group">
              <div className="flex items-center gap-2.5 min-w-0">
                <div className="relative shrink-0">
                  <div className="w-9 h-9 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center text-white font-bold text-xs shadow-xs">
                    {getInitials(userName)}
                  </div>
                  <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-500 border-2 border-white" />
                </div>
                <div className="min-w-0">
                  <div className="text-xs font-bold text-neutral-900 truncate flex items-center gap-1">
                    {userName}
                  </div>
                  <div className="text-[10px] text-slate-400 truncate flex items-center gap-1">
                    <span>{config.roleName}</span>
                    <span>·</span>
                    <span className="truncate">{userEmail}</span>
                  </div>
                </div>
              </div>
            </div>
          )}

          <button
            type="button"
            onClick={() => {
              onSetHoveredTooltip(null);
              if (onLogout) {
                onLogout();
              } else {
                window.location.href = '/';
              }
            }}
            onMouseEnter={(e) => {
              if (isDesktopCollapsed) {
                const rect = e.currentTarget.getBoundingClientRect();
                onSetHoveredTooltip({
                  label: 'Đăng xuất tài khoản',
                  top: rect.top + rect.height / 2,
                });
              }
            }}
            onMouseLeave={() => onSetHoveredTooltip(null)}
            className={`${
              isDesktopCollapsed
                ? 'w-10 h-10 p-0 flex items-center justify-center'
                : 'w-full mt-2 flex items-center justify-center gap-2 px-3 py-2'
            } rounded-xl text-xs font-semibold text-rose-600 hover:bg-rose-50/80 border border-rose-100 transition-all duration-200 group relative cursor-pointer active:scale-95`}
            title="Đăng xuất"
          >
            <LogOut className="w-4 h-4 group-hover:-translate-x-0.5 transition-transform" />
            {!isDesktopCollapsed && <span>Đăng xuất</span>}
          </button>
        </div>
      </aside>
    </>
  );
};
