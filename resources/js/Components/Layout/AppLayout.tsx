import React, { useState, useEffect } from 'react';
import { AppSidebar } from './AppSidebar';
import { AppTopbar } from './AppTopbar';
import { AppSidebarTooltip } from './AppSidebarTooltip';
import { UserRole, NavigationItem, NavigationRoleConfig } from './navigationConfig';

export interface AppLayoutProps {
  role?: UserRole;
  userRole?: UserRole;
  activeItemId: string;
  onItemClick: (id: string) => void;
  customItems?: NavigationItem[];
  customRoleConfig?: Partial<NavigationRoleConfig>;
  userName?: string;
  userEmail?: string;
  onLogout?: () => void;
  onNavigateHome?: () => void;
  statusText?: string;
  extraTopbarActions?: React.ReactNode;
  onNotificationClick?: () => void;
  unreadNotificationCount?: number;
  onHelpClick?: () => void;
  children: React.ReactNode;
}

export const AppLayout: React.FC<AppLayoutProps> = ({
  role = 'manager',
  userRole,
  activeItemId,
  onItemClick,
  customItems,
  customRoleConfig,
  userName = 'Admin Cassavas',
  userEmail = 'admin@cassavas.vn',
  onLogout,
  onNavigateHome,
  statusText,
  extraTopbarActions,
  onNotificationClick,
  unreadNotificationCount = 3,
  onHelpClick,
  children,
}) => {
  // Lấy thông tin phiên đăng nhập từ localStorage
  const sessionUser = (() => {
    try {
      const saved = localStorage.getItem('smartcassavas_session');
      return saved ? JSON.parse(saved) : null;
    } catch {
      return null;
    }
  })();

  // ĐẶC QUYỀN ADMIN / DEV: Luôn duy trì quyền admin khi admin di chuyển giữa các cổng
  const effectiveUserRole: UserRole =
    userRole === 'admin' || userRole === 'dev' || sessionUser?.role === 'admin' || sessionUser?.role === 'dev'
      ? 'admin'
      : (userRole || (sessionUser?.role as UserRole) || role);

  const isAdmin = effectiveUserRole === 'admin';
  // Collapse State with localStorage persistence
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem('smartcassavas_sidebar_collapsed') === 'true';
    } catch {
      return false;
    }
  });

  const toggleSidebarCollapse = () => {
    setIsSidebarCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem('smartcassavas_sidebar_collapsed', String(next));
      } catch {
        // ignore
      }
      return next;
    });
  };

  // Mobile Drawer State
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState<boolean>(false);

  // 1. Đồng bộ JS với CSS Breakpoint qua matchMedia: Tự động đóng Drawer khi resize lên Desktop (>1024px)
  useEffect(() => {
    const mediaQuery = window.matchMedia('(min-width: 1024px)');
    const handleBreakpointChange = (e: MediaQueryListEvent | MediaQueryList) => {
      if (e.matches) {
        setMobileSidebarOpen(false);
      }
    };

    // Kiểm tra ngay khi mount
    if (mediaQuery.matches) {
      setMobileSidebarOpen(false);
    }

    if (mediaQuery.addEventListener) {
      mediaQuery.addEventListener('change', handleBreakpointChange);
      return () => mediaQuery.removeEventListener('change', handleBreakpointChange);
    } else {
      mediaQuery.addListener(handleBreakpointChange);
      return () => mediaQuery.removeListener(handleBreakpointChange);
    }
  }, []);

  // 2. Khóa cuộn trang trên mobile khi Drawer đang mở để tránh cuộn nền ngoài ý muốn
  useEffect(() => {
    if (mobileSidebarOpen) {
      const originalOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      return () => {
        document.body.style.overflow = originalOverflow;
      };
    }
  }, [mobileSidebarOpen]);

  // 3. Hỗ trợ đóng Drawer khi nhấn phím Escape (Accessibility)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && mobileSidebarOpen) {
        setMobileSidebarOpen(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [mobileSidebarOpen]);

  // Floating Tooltip State
  const [hoveredTooltip, setHoveredTooltip] = useState<{
    label: string;
    badge?: string | number | null;
    top: number;
  } | null>(null);

  // Mouse Glow Position
  const [mousePos, setMousePos] = useState<{ x: number; y: number }>({ x: 0, y: 0 });

  return (
    <div
      onMouseMove={(e) => setMousePos({ x: e.clientX, y: e.clientY })}
      className="flex h-screen w-full bg-[#F8FAFC]/90 text-neutral-900 font-sans antialiased overflow-hidden relative selection:bg-neutral-900 selection:text-white"
    >
      {/* Dynamic Mouse Following Ambient Glow (Desktop only) */}
      <div
        className="pointer-events-none fixed inset-0 z-0 transition-opacity duration-500 opacity-60 hidden lg:block"
        style={{
          background: `radial-gradient(650px circle at ${mousePos.x}px ${mousePos.y}px, rgba(56, 189, 248, 0.08), transparent 80%)`,
        }}
      />

      {/* Atmospheric Aurora & Dot Matrix Background - Bọc trong container cố định riêng biệt chống vỡ layout */}
      <div className="pointer-events-none fixed inset-0 overflow-hidden z-0">
        <div className="absolute -top-40 -left-40 w-[600px] h-[600px] bg-gradient-to-br from-sky-400/10 via-indigo-400/5 to-transparent rounded-full blur-3xl" />
        <div className="absolute -bottom-40 -right-40 w-[600px] h-[600px] bg-gradient-to-tl from-emerald-400/10 via-sky-400/5 to-transparent rounded-full blur-3xl" />
        <div
          className="absolute inset-0 opacity-[0.03]"
          style={{
            backgroundImage: 'radial-gradient(#000000 1px, transparent 1px)',
            backgroundSize: '24px 24px',
          }}
        />
      </div>

      {/* ================= LEFT SIDEBAR ================= */}
      <AppSidebar
        role={role}
        isCollapsed={isSidebarCollapsed}
        onToggleCollapse={toggleSidebarCollapse}
        mobileOpen={mobileSidebarOpen}
        onMobileClose={() => setMobileSidebarOpen(false)}
        activeItemId={activeItemId}
        onItemClick={onItemClick}
        customItems={customItems}
        customRoleConfig={customRoleConfig}
        userName={userName}
        userEmail={userEmail}
        onLogout={onLogout}
        onNavigateHome={onNavigateHome}
        onSetHoveredTooltip={setHoveredTooltip}
      />

      {/* ================= FLOATING TOOLTIP ================= */}
      {isSidebarCollapsed && hoveredTooltip && (
        <AppSidebarTooltip
          label={hoveredTooltip.label}
          badge={hoveredTooltip.badge}
          top={hoveredTooltip.top}
        />
      )}

      {/* ================= RIGHT MAIN CONTAINER ================= */}
      <div className="flex-1 flex flex-col h-screen min-w-0 overflow-hidden transition-all duration-300 ease-in-out relative z-10">
        {/* Topbar Header */}
        <AppTopbar
          isSidebarCollapsed={isSidebarCollapsed}
          onToggleSidebar={() => {
            if (window.innerWidth < 1024) {
              setMobileSidebarOpen((prev) => !prev);
            } else {
              toggleSidebarCollapse();
            }
          }}
          userName={isAdmin ? (sessionUser?.name || userName) : userName}
          userRole={effectiveUserRole}
          portalRole={role}
          statusText={statusText}
          extraActions={extraTopbarActions}
          onNotificationClick={onNotificationClick}
          unreadNotificationCount={unreadNotificationCount}
          onHelpClick={onHelpClick}
          showPortalSwitcher={isAdmin}
        />

        {/* Scrollable Page Content */}
        <main className="flex-1 overflow-y-auto overflow-x-hidden custom-scrollbar relative">
          {children}
        </main>
      </div>
    </div>
  );
};
export default AppLayout;
