import React, { useState, useEffect, useMemo, useRef } from 'react';
import {
  Building2,
  Building,
  Gauge,
  Users,
  Car,
  PackageOpen,
  Layers,
  UserPlus,
  Package,
  CreditCard,
  HelpCircle,
  ChevronDown,
  LogOut,
  User,
  Bell,
  Share2,
  Menu,
  X,
  ArrowLeft,
  CheckCircle2,
  PanelLeft,
  Maximize2,
  Minimize2,
  ZoomIn,
  ZoomOut,
  RotateCcw,
  Check,
  RefreshCw,
  ShieldCheck,
  AlertTriangle,
  Clock,
  Sparkles,
  Home as HomeIcon,
  Download,
  Search,
} from 'lucide-react';
import { Building3DModel } from '../Components/Building3DModel';

export interface ReceptionHomeProps {
  onLogout?: () => void;
  onNavigateHome?: () => void;
  onNavigateAdmin?: () => void;
  userName?: string;
  userEmail?: string;
  userRole?: string;
}

interface MenuItem {
  id: string;
  label: string;
  icon: React.ElementType;
  badge?: string;
}

interface ActivityItem {
  num: string;
  title: string;
  time: string;
  type: string;
}

interface NotificationItem {
  id: string;
  title: string;
  message: string;
  timeAgo: string;
  category: 'GUEST' | 'PARCEL' | 'SECURITY' | string;
}

export const ReceptionHome: React.FC<ReceptionHomeProps> = ({
  onLogout,
  onNavigateHome,
  onNavigateAdmin,
  userName = 'Admin Cassavas',
  userEmail = 'admin@cassavas.vn',
  userRole = 'receptionist',
}) => {
  // Navigation & Interactive states
  const [activeMenuId, setActiveMenuId] = useState<string>('overview');
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState<boolean>(false);
  const [selectedBuilding, setSelectedBuilding] = useState<string>('Khu A - Tất cả tòa nhà');
  const [isBuildingDropdownOpen, setIsBuildingDropdownOpen] = useState<boolean>(false);
  const [isNotificationOpen, setIsNotificationOpen] = useState<boolean>(false);
  const [isHelpOpen, setIsHelpOpen] = useState<boolean>(false);
  const [is3DModelOpen, setIs3DModelOpen] = useState<boolean>(false);
  const [mousePos, setMousePos] = useState<{ x: number; y: number }>({ x: 0, y: 0 });
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [currentTime, setCurrentTime] = useState<string>('');
  const [isLoading, setIsLoading] = useState<boolean>(false);

  // Sidebar Collapse / Expand State (Matching ManagementHome)
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem('smartcassavas_reception_sidebar_collapsed') === 'true';
    } catch {
      return false;
    }
  });

  const toggleSidebarCollapse = () => {
    setIsSidebarCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem('smartcassavas_reception_sidebar_collapsed', String(next));
      } catch {
        // ignore
      }
      showToast(next ? 'Đã thu gọn thanh điều hướng (Sidebar)' : 'Đã mở rộng thanh điều hướng (Sidebar)');
      return next;
    });
  };

  const handleToggleSidebar = () => {
    if (window.innerWidth < 768) {
      setMobileSidebarOpen((prev) => !prev);
    } else {
      toggleSidebarCollapse();
    }
  };

  // Tooltip for collapsed sidebar
  const [hoveredTooltip, setHoveredTooltip] = useState<{
    label: string;
    badge?: string | null;
    top: number;
  } | null>(null);

  // Fullscreen State (Trạng thái Toàn màn hình trình duyệt F11)
  const [isFullscreen, setIsFullscreen] = useState<boolean>(false);

  // Zoom Level State (80% - 130%)
  const [zoomLevel, setZoomLevel] = useState<number>(() => {
    try {
      const saved = localStorage.getItem('smartcassavas_reception_zoom');
      return saved ? parseInt(saved, 10) : 100;
    } catch {
      return 100;
    }
  });

  const handleZoomIn = () => {
    setZoomLevel((prev) => {
      const next = Math.min(prev + 10, 130);
      showToast(`Tỉ lệ hiển thị: ${next}%`);
      return next;
    });
  };

  const handleZoomOut = () => {
    setZoomLevel((prev) => {
      const next = Math.max(prev - 10, 80);
      showToast(`Tỉ lệ hiển thị: ${next}%`);
      return next;
    });
  };

  const handleResetZoom = () => {
    setZoomLevel(100);
    showToast('Đã đặt lại tỉ lệ chuẩn 100%');
  };

  // Sync zoom level with document body
  useEffect(() => {
    try {
      localStorage.setItem('smartcassavas_reception_zoom', String(zoomLevel));
      (document.body.style as any).zoom = `${zoomLevel}%`;
    } catch {
      // ignore
    }
  }, [zoomLevel]);

  // Clean up zoom on unmount
  useEffect(() => {
    return () => {
      try {
        (document.body.style as any).zoom = '100%';
      } catch {
        // ignore
      }
    };
  }, []);

  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(!!(document.fullscreenElement || (document as any).webkitFullscreenElement));
    };
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
    return () => {
      document.removeEventListener('fullscreenchange', handleFullscreenChange);
      document.removeEventListener('webkitfullscreenchange', handleFullscreenChange);
    };
  }, []);

  const toggleFullscreen = async () => {
    try {
      const isCurrentlyFullscreen = !!(document.fullscreenElement || (document as any).webkitFullscreenElement);
      if (!isCurrentlyFullscreen) {
        if (document.documentElement.requestFullscreen) {
          await document.documentElement.requestFullscreen();
        } else if ((document.documentElement as any).webkitRequestFullscreen) {
          await (document.documentElement as any).webkitRequestFullscreen();
        }
        showToast('Đã chuyển sang chế độ Toàn màn hình');
      } else {
        if (document.exitFullscreen) {
          await document.exitFullscreen();
        } else if ((document as any).webkitExitFullscreen) {
          await (document as any).webkitExitFullscreen();
        }
        showToast('Đã thoát chế độ Toàn màn hình');
      }
    } catch {
      showToast('Trình duyệt không hỗ trợ hoặc bị chặn toàn màn hình');
    }
  };

  // Keyboard Shortcuts (F11 for Fullscreen, Ctrl +/-/0 for Zoom)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'F11') {
        e.preventDefault();
        toggleFullscreen();
      } else if (e.ctrlKey && (e.key === '=' || e.key === '+')) {
        e.preventDefault();
        handleZoomIn();
      } else if (e.ctrlKey && e.key === '-') {
        e.preventDefault();
        handleZoomOut();
      } else if (e.ctrlKey && e.key === '0') {
        e.preventDefault();
        handleResetZoom();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  // Live Vietnamese Date
  useEffect(() => {
    const updateDateTime = () => {
      const now = new Date();
      const days = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
      const dayName = days[now.getDay()];
      const day = String(now.getDate()).padStart(2, '0');
      const month = String(now.getMonth() + 1).padStart(2, '0');
      const year = now.getFullYear();
      setCurrentTime(`${dayName}, ${day} tháng ${month}, ${year}`);
    };
    updateDateTime();
    const timer = setInterval(updateDateTime, 60000);
    return () => clearInterval(timer);
  }, []);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  // Live Stats from Database / Seeders
  const [kpis, setKpis] = useState({
    activeGuests: 18,
    activeGuestsNote: 'Tất cả khu vực',
    pendingParcels: 42,
    overdueParcelsNote: '4 bưu phẩm quá hạn',
    pendingVehicles: 7,
    pendingVehiclesNote: 'Cần kiểm tra giấy tờ',
    securityIncidents: '02',
    securityIncidentsNote: 'Đang được xử lý',
  });

  const [activities, setActivities] = useState<ActivityItem[]>([
    { num: '01', title: 'Khách Nguyễn Minh Anh đã check-in', time: '10 phút trước', type: 'guest' },
    { num: '02', title: 'Bưu phẩm PKG-031 đã được tiếp nhận', time: '25 phút trước', type: 'parcel' },
    { num: '03', title: 'Xe 30K-678.90 chờ duyệt đăng ký', time: '45 phút trước', type: 'vehicle' },
  ]);

  const [notifications, setNotifications] = useState<NotificationItem[]>([
    {
      id: '1',
      title: 'Check-in khách',
      message: 'Khách Nguyễn Minh Anh vào thăm căn hộ A1001.',
      timeAgo: '10 phút trước',
      category: 'GUEST',
    },
    {
      id: '2',
      title: 'Bưu phẩm mới',
      message: 'Kiện hàng PKG-031 từ Shopee Express đã tiếp nhận tại quầy.',
      timeAgo: '25 phút trước',
      category: 'PARCEL',
    },
    {
      id: '3',
      title: 'Cảnh báo an ninh',
      message: 'Cửa kỹ thuật thoát hiểm tầng 14 chưa đóng hoàn toàn.',
      timeAgo: '50 phút trước',
      category: 'SECURITY',
    },
  ]);

  const fetchReceptionData = async (isManual = false) => {
    setIsLoading(true);
    try {
      const res = await fetch('/api/v1/reception/overview');
      if (res.ok) {
        const data = await res.json();
        if (data.kpis) setKpis(data.kpis);
        if (data.activities) setActivities(data.activities);
        if (data.notifications) setNotifications(data.notifications);
        if (isManual) {
          showToast('Đồng bộ dữ liệu Lễ tân & An ninh từ Database thành công!');
        }
      }
    } catch {
      if (isManual) {
        showToast('Không thể kết nối API, tiếp tục hiển thị dữ liệu mẫu.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchReceptionData();
  }, []);

  // Menu items with live badges
  const menuItems: MenuItem[] = useMemo(
    () => [
      { id: 'overview', label: 'Tổng quan Lễ tân', icon: Gauge },
      { id: 'visitors', label: 'Khách & Check-in An ninh', icon: Users, badge: `${kpis.activeGuests}` },
      { id: 'parking', label: 'Bãi đỗ & ANPR', icon: Car, badge: `${kpis.pendingVehicles}` },
      { id: 'lost_found', label: 'Đồ thất lạc', icon: PackageOpen },
      { id: 'smart_lockers', label: 'Tủ đồ thông minh', icon: Layers, badge: '24 ô' },
      { id: 'walkin_guest', label: 'Check-in Khách vãng lai', icon: UserPlus },
      { id: 'parcels', label: 'Quản lý Bưu phẩm', icon: Package, badge: `${kpis.pendingParcels}` },
      { id: 'vehicle_reg', label: 'Quản lý Đăng ký xe', icon: CreditCard },
    ],
    [kpis]
  );

  const buildingOptions = [
    'Khu A - Tất cả tòa nhà',
    'Tòa S1 - Sky Tower',
    'Tòa S2 - Aqua Tower',
    'Tòa S3 - Garden Tower',
    'Toàn bộ khu đô thị',
  ];

  const handleMenuClick = (id: string) => {
    setActiveMenuId(id);
    setMobileSidebarOpen(false);
    if (id !== 'overview') {
      const item = menuItems.find((m) => m.id === id);
      showToast(`Chuyển đến phân hệ: ${item?.label || id}`);
    }
  };

  return (
    <div
      onMouseMove={(e) => setMousePos({ x: e.clientX, y: e.clientY })}
      className="h-screen w-full bg-[#F8FAFC] text-[#0F172A] font-['Plus_Jakarta_Sans',sans-serif] relative flex overflow-hidden"
    >

      {/* ========================================================
          BACKGROUND AMBIANCE (ULTRA GLASS & SPOTLIGHT)
          ======================================================== */}
      {/* Dynamic Mouse Following Spotlight */}
      <div
        className="pointer-events-none fixed inset-0 z-0 transition-opacity duration-500 opacity-60 hidden lg:block"
        style={{
          background: `radial-gradient(750px circle at ${mousePos.x}px ${mousePos.y}px, rgba(56, 189, 248, 0.08), transparent 80%)`,
        }}
      />

      {/* Atmospheric Floating Gradient Orbs */}
      <div className="fixed inset-0 pointer-events-none overflow-hidden -z-10">
        <div className="absolute -top-32 -left-32 w-[550px] h-[550px] bg-gradient-to-br from-blue-200/40 via-sky-100/30 to-transparent rounded-full blur-3xl animate-float-orb opacity-75" />
        <div className="absolute top-1/3 -right-36 w-[600px] h-[600px] bg-gradient-to-bl from-indigo-200/35 via-slate-100/40 to-transparent rounded-full blur-3xl animate-float-orb-reverse opacity-70" />
        <div className="absolute bottom-10 left-1/4 w-[500px] h-[500px] bg-gradient-to-t from-emerald-100/35 via-sky-50/25 to-transparent rounded-full blur-3xl animate-cloud-float opacity-60" />

        {/* Subtle dot matrix grid */}
        <div
          className="absolute inset-0 opacity-[0.03]"
          style={{
            backgroundImage: 'radial-gradient(#000000 1px, transparent 1px)',
            backgroundSize: '24px 24px',
          }}
        />
      </div>

      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed bottom-6 right-6 z-[100000] flex items-center gap-2.5 px-4 py-3 bg-neutral-900/95 backdrop-blur-2xl text-white text-xs font-medium rounded-2xl shadow-2xl border border-white/20 animate-in fade-in slide-in-from-bottom-3 duration-200">
          <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
          <span>{toastMessage}</span>
        </div>
      )}

      {/* Mobile Sidebar Backdrop */}
      {mobileSidebarOpen && (
        <div
          onClick={() => setMobileSidebarOpen(false)}
          className="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-xs md:hidden transition-opacity"
        />
      )}

      {/* ========================================================
          LEFT SIDEBAR (ULTRA GLASSMORPHISM, COLLAPSIBLE & ZOOM)
          ======================================================== */}
      <aside
        className={`fixed md:relative top-0 left-0 z-50 h-screen shrink-0 bg-white/80 backdrop-blur-2xl border-r border-white/60 shadow-2xl md:shadow-xs flex flex-col justify-between transition-all duration-300 ease-in-out glass-specular-edge overflow-x-hidden ${
          isSidebarCollapsed ? 'w-20' : 'w-72'
        } ${mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'}`}
      >
        {/* Top Brand Header */}
        <div className={`p-4 border-b border-slate-200/60 flex items-center ${isSidebarCollapsed ? 'justify-center' : 'justify-between'}`}>
          <div className={`flex items-center ${isSidebarCollapsed ? 'justify-center' : 'gap-3'} min-w-0`}>
            <div
              onClick={isSidebarCollapsed ? toggleSidebarCollapse : undefined}
              className={`relative group shrink-0 ${isSidebarCollapsed ? 'cursor-pointer' : ''}`}
              title={isSidebarCollapsed ? 'Bấm để mở rộng menu sidebar' : undefined}
            >
              <div className="w-10 h-10 rounded-xl bg-neutral-900 flex items-center justify-center text-white shadow-md shadow-neutral-900/20 group-hover:scale-105 transition-all duration-300 cursor-pointer">
                <Building2 className="w-5 h-5 text-sky-400 group-hover:rotate-6 transition-transform" />
              </div>
              <div className="absolute -inset-0.5 rounded-xl bg-gradient-to-r from-sky-400 to-indigo-500 opacity-0 group-hover:opacity-30 blur-xs transition-opacity -z-10" />
            </div>
            {!isSidebarCollapsed && (
              <div className="min-w-0 transition-opacity duration-200">
                <div className="flex items-center gap-1.5">
                  <span className="font-extrabold text-sm tracking-wider text-neutral-900 font-mono truncate">
                    SMART CASSAVAS
                  </span>
                </div>
                <span className="text-[10px] tracking-widest font-semibold uppercase text-sky-600 block truncate">
                  LỄ TÂN & BẢO VỆ
                </span>
              </div>
            )}
          </div>
          {!isSidebarCollapsed && (
            <div className="flex items-center gap-1">
              <button
                onClick={toggleSidebarCollapse}
                className="hidden md:flex p-1.5 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-700 transition-colors cursor-pointer"
                title="Thu gọn sidebar (w-20)"
              >
                <PanelLeft className="w-4 h-4" />
              </button>
              <button
                onClick={() => setMobileSidebarOpen(false)}
                className="md:hidden p-1.5 rounded-lg hover:bg-slate-100 text-slate-500 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
          )}
        </div>

        {/* Section Header */}
        <div className={`px-4 pt-4 pb-2 ${isSidebarCollapsed ? 'flex justify-center' : ''}`}>
          {isSidebarCollapsed ? (
            <div className="w-8 h-1 rounded-full bg-slate-200 flex items-center justify-center relative">
              <span className="w-1.5 h-1.5 rounded-full bg-sky-500 animate-pulse" />
            </div>
          ) : (
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold tracking-wider text-slate-400 uppercase font-mono truncate">
                KHÔNG GIAN LỄ TÂN
              </span>
              <span className="relative flex h-2 w-2 shrink-0">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-sky-400 opacity-75" />
                <span className="relative inline-flex rounded-full h-2 w-2 bg-sky-500" />
              </span>
            </div>
          )}
        </div>

        {/* Scrollable Navigation Menu with Custom Scrollbar */}
        <div className="relative flex-1 min-h-0 flex flex-col overflow-hidden group/nav">
          <nav
            onScroll={() => setHoveredTooltip(null)}
            className={`flex-1 overflow-y-auto overflow-x-hidden ${isSidebarCollapsed ? 'px-2' : 'pl-3 pr-2'} py-2 pb-8 space-y-1 custom-scrollbar`}
          >
            {menuItems.map((item) => {
              const Icon = item.icon;
              const isActive = activeMenuId === item.id;
              return (
                <div key={item.id} className="relative group">
                  <button
                    onClick={() => {
                      setHoveredTooltip(null);
                      handleMenuClick(item.id);
                    }}
                    onMouseEnter={(e) => {
                      if (isSidebarCollapsed) {
                        const rect = e.currentTarget.getBoundingClientRect();
                        setHoveredTooltip({
                          label: item.label,
                          badge: item.badge,
                          top: rect.top + rect.height / 2,
                        });
                      }
                    }}
                    onMouseLeave={() => setHoveredTooltip(null)}
                    className={`w-full flex items-center ${
                      isSidebarCollapsed ? 'justify-center px-0 py-2.5' : 'justify-between px-3 py-2.5'
                    } rounded-xl text-xs font-medium transition-all duration-200 relative cursor-pointer ${
                      isActive
                        ? 'bg-neutral-900 text-white shadow-md shadow-neutral-900/15'
                        : 'text-slate-600 hover:text-neutral-900 hover:bg-white/90 hover:shadow-xs'
                    }`}
                  >
                    <div className={`flex items-center ${isSidebarCollapsed ? 'justify-center' : 'gap-3'} min-w-0`}>
                      <div
                        className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 transition-all ${
                          isActive
                            ? 'bg-neutral-800 text-sky-400'
                            : 'bg-slate-100/80 text-slate-500 group-hover:text-sky-600 group-hover:bg-sky-50 group-hover:scale-110'
                        }`}
                      >
                        <Icon className="w-4 h-4 transition-transform group-hover:scale-110" />
                      </div>
                      {!isSidebarCollapsed && <span className="truncate">{item.label}</span>}
                    </div>

                    {!isSidebarCollapsed && item.badge && (
                      <span
                        className={`text-[10px] px-2 py-0.5 rounded-full font-semibold transition-all shrink-0 ${
                          isActive
                            ? 'bg-neutral-800 text-sky-300'
                            : item.badge.includes('18') || item.badge.includes('42')
                            ? 'bg-sky-50 text-sky-700 border border-sky-200/60'
                            : 'bg-slate-100 text-slate-600'
                        }`}
                      >
                        {item.badge}
                      </span>
                    )}

                    {isActive && !isSidebarCollapsed && (
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

        {/* Bottom User Profile Section */}
        <div className={`p-3 border-t border-slate-200/60 bg-white/60 backdrop-blur-md ${isSidebarCollapsed ? 'flex flex-col items-center gap-2' : ''}`}>
          {isSidebarCollapsed ? (
            <div
              onMouseEnter={(e) => {
                const rect = e.currentTarget.getBoundingClientRect();
                setHoveredTooltip({
                  label: `${userName} · ${userEmail}`,
                  top: rect.top + rect.height / 2,
                });
              }}
              onMouseLeave={() => setHoveredTooltip(null)}
              className="relative group cursor-pointer"
            >
              <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center text-white font-bold text-xs shadow-xs hover:scale-105 transition-transform">
                AD
              </div>
              <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-500 border-2 border-white" />
            </div>
          ) : (
            <div className="flex items-center justify-between p-2 rounded-xl hover:bg-white/80 transition-colors border border-transparent hover:border-slate-200/60 group">
              <div className="flex items-center gap-2.5 min-w-0">
                <div className="relative shrink-0">
                  <div className="w-9 h-9 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center text-white font-bold text-xs shadow-xs">
                    AD
                  </div>
                  <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-emerald-500 border-2 border-white" />
                </div>
                <div className="min-w-0">
                  <div className="text-xs font-bold text-neutral-900 truncate flex items-center gap-1">
                    {userName}
                  </div>
                  <div className="text-[11px] text-slate-400 truncate">{userEmail}</div>
                </div>
              </div>
              <ChevronDown className="w-4 h-4 text-slate-400 group-hover:text-slate-600 transition-transform" />
            </div>
          )}

          <button
            onClick={() => {
              setHoveredTooltip(null);
              if (onLogout) {
                onLogout();
              } else if (onNavigateHome) {
                onNavigateHome();
              } else {
                window.location.href = '/home';
              }
            }}
            onMouseEnter={(e) => {
              if (isSidebarCollapsed) {
                const rect = e.currentTarget.getBoundingClientRect();
                setHoveredTooltip({
                  label: 'Đăng xuất tài khoản',
                  top: rect.top + rect.height / 2,
                });
              }
            }}
            onMouseLeave={() => setHoveredTooltip(null)}
            className={`${
              isSidebarCollapsed
                ? 'w-10 h-10 p-0 flex items-center justify-center'
                : 'w-full mt-2 flex items-center justify-center gap-2 px-3 py-2'
            } rounded-xl text-xs font-semibold text-rose-600 hover:bg-rose-50/80 border border-rose-100 transition-all duration-200 group relative cursor-pointer`}
          >
            <LogOut className="w-4 h-4 group-hover:-translate-x-0.5 transition-transform" />
            {!isSidebarCollapsed && <span>Đăng xuất</span>}
          </button>
        </div>
      </aside>

      {/* ================= FLOATING TOOLTIP FOR COLLAPSED SIDEBAR (FIXED POSITION - NO OVERFLOW) ================= */}
      {isSidebarCollapsed && hoveredTooltip && (
        <div
          style={{
            top: `${hoveredTooltip.top}px`,
            left: '84px',
          }}
          className="fixed -translate-y-1/2 z-[70] px-3.5 py-2 rounded-xl bg-neutral-950/95 text-white text-xs font-bold shadow-2xl backdrop-blur-xl border border-white/20 pointer-events-none flex items-center gap-2 animate-in fade-in zoom-in-95 duration-100 ring-1 ring-black/40"
        >
          <span className="tracking-wide">{hoveredTooltip.label}</span>
          {hoveredTooltip.badge && (
            <span className="text-[10px] px-1.5 py-0.5 rounded-md bg-sky-500/25 text-sky-300 font-extrabold border border-sky-400/40">
              {hoveredTooltip.badge}
            </span>
          )}
          {/* Subtle pointer arrow */}
          <div className="absolute -left-1 top-1/2 -translate-y-1/2 w-2 h-2 bg-neutral-950 rotate-45 border-l border-b border-white/20" />
        </div>
      )}

      {/* ========================================================
          MAIN CONTENT AREA & TOPBAR
          ======================================================== */}
      <div className="flex-1 flex flex-col h-screen min-w-0 overflow-hidden transition-all duration-300 ease-in-out">
        {/* ================= TOPBAR (GLASS & ANIMATIONS - CỐ ĐỊNH NHƯ HOME QUẢN LÝ) ================= */}
        <header className="shrink-0 z-30 bg-white/85 backdrop-blur-xl border-b border-white/60 shadow-xs glass-specular-edge transition-all duration-300">
          <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 h-18 flex items-center justify-between gap-4 transition-all duration-300">
            {/* Left: Single Toggle Button for Sidebar & Date/Greeting */}
            <div className="flex items-center gap-3 min-w-0">
              <button
                onClick={handleToggleSidebar}
                className="p-2 rounded-xl bg-white/80 hover:bg-white border border-slate-200/80 text-slate-600 hover:text-sky-600 shadow-xs hover:shadow-sm transition-all flex items-center justify-center cursor-pointer group"
                title={isSidebarCollapsed ? 'Mở rộng thanh menu (Sidebar)' : 'Thu gọn thanh menu (Sidebar)'}
              >
                <PanelLeft
                  className={`w-4 h-4 transition-transform duration-200 ${
                    isSidebarCollapsed ? 'text-sky-600 rotate-180' : 'group-hover:scale-105'
                  }`}
                />
              </button>

              <div>
                <div className="text-[11px] font-medium text-slate-400 flex items-center gap-2">
                  <span>{currentTime || 'Thứ Hai, 08 tháng 09, 2026'}</span>
                  <span className="hidden sm:inline-block w-1 h-1 rounded-full bg-slate-300" />
                  <span className="hidden sm:inline-flex items-center gap-1 text-emerald-600 font-medium">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                    Ca trực an ninh hoạt động
                  </span>
                </div>
                <div className="text-base font-bold text-neutral-900 flex items-center gap-1.5">
                  <span>Xin chào, Admin</span>
                  <span className="inline-block origin-[70%_70%] hover:animate-bounce cursor-default">👋</span>
                </div>
              </div>
            </div>

            {/* Right: Actions matching ManagementHome Topbar */}
            <div className="flex items-center gap-2 sm:gap-3">
              {/* Nút Làm mới Database */}
              <button
                onClick={() => fetchReceptionData(true)}
                className="p-2 rounded-xl bg-white/80 hover:bg-white text-slate-500 hover:text-sky-600 border border-slate-200/70 shadow-xs hover:shadow-sm transition-all cursor-pointer group"
                title="Đồng bộ dữ liệu Database"
              >
                <RefreshCw className={`w-4 h-4 ${isLoading ? 'animate-spin text-sky-600' : 'group-hover:rotate-180 transition-transform duration-500'}`} />
              </button>

              {/* Bộ điều khiển Thu phóng giao diện (UI Zoom Control) */}
              <div className="flex items-center bg-slate-100/90 hover:bg-slate-100 border border-slate-200/80 rounded-xl p-0.5 shadow-xs transition-all">
                <button
                  type="button"
                  onClick={handleZoomOut}
                  disabled={zoomLevel <= 80}
                  className="p-1.5 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-white disabled:opacity-30 disabled:hover:bg-transparent transition-all cursor-pointer"
                  title="Thu nhỏ giao diện (Ctrl -)"
                >
                  <ZoomOut className="w-3.5 h-3.5" />
                </button>
                <button
                  type="button"
                  onClick={handleResetZoom}
                  className="px-2 py-0.5 text-xs font-semibold text-slate-700 hover:text-sky-600 rounded-md hover:bg-white transition-all cursor-pointer select-none"
                  title="Tỉ lệ hiện tại - Bấm để đặt lại chuẩn 100% (Ctrl 0)"
                >
                  {zoomLevel}%
                </button>
                <button
                  type="button"
                  onClick={handleZoomIn}
                  disabled={zoomLevel >= 130}
                  className="p-1.5 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-white disabled:opacity-30 disabled:hover:bg-transparent transition-all cursor-pointer"
                  title="Phóng to giao diện (Ctrl +)"
                >
                  <ZoomIn className="w-3.5 h-3.5" />
                </button>
              </div>

              {/* Nút Toàn màn hình (Fullscreen / Kiosk) */}
              <button
                type="button"
                onClick={toggleFullscreen}
                className={`p-2 rounded-xl border shadow-xs hover:shadow-sm transition-all relative group cursor-pointer ${
                  isFullscreen
                    ? 'bg-sky-50 text-sky-600 border-sky-200 hover:bg-sky-100 ring-2 ring-sky-400/30'
                    : 'bg-white/80 hover:bg-white text-slate-500 hover:text-slate-800 border-slate-200/70'
                }`}
                title={isFullscreen ? 'Thoát toàn màn hình (Esc)' : 'Toàn màn hình / Chế độ Kiosk (F11)'}
              >
                {isFullscreen ? (
                  <Minimize2 className="w-4 h-4 text-sky-600 animate-in zoom-in-75 duration-150" />
                ) : (
                  <Maximize2 className="w-4 h-4 group-hover:scale-110 transition-transform" />
                )}
              </button>

              {/* Quick 3D Building Toggle */}
              <button
                onClick={() => setIs3DModelOpen(true)}
                className="hidden xl:flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/80 hover:bg-white text-xs font-semibold text-sky-700 border border-sky-100 shadow-xs hover:shadow-sm transition-all cursor-pointer"
                title="Xem mô hình 3D tòa nhà"
              >
                <Layers className="w-3.5 h-3.5 text-sky-500 animate-pulse-subtle" />
                <span>Mô hình 3D</span>
              </button>

              {/* System Health Status */}
              <div className="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-50/80 border border-emerald-200/60 text-emerald-700 text-xs font-medium">
                <span className="relative flex h-2 w-2">
                  <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
                  <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
                </span>
                <span>99.98% Ổn định</span>
              </div>

              {/* Help Button */}
              <div className="relative">
                <button
                  onClick={() => setIsHelpOpen(!isHelpOpen)}
                  className="p-2 rounded-xl bg-white/80 hover:bg-white text-slate-500 hover:text-slate-800 border border-slate-200/70 shadow-xs transition-all relative cursor-pointer"
                  title="Trợ giúp nghiệp vụ lễ tân"
                >
                  <HelpCircle className="w-4 h-4" />
                </button>

                {isHelpOpen && (
                  <div className="absolute right-0 mt-2 w-72 rounded-2xl bg-white/95 backdrop-blur-2xl border border-white/80 shadow-2xl p-4 z-50 animate-in fade-in slide-in-from-top-2 duration-150 text-left">
                    <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                      <span className="font-bold text-xs text-neutral-900">Trợ giúp Lễ tân</span>
                      <button onClick={() => setIsHelpOpen(false)} className="text-slate-400 hover:text-slate-600 text-xs cursor-pointer">
                        Đóng
                      </button>
                    </div>
                    <div className="mt-2 space-y-2 text-xs text-slate-600">
                      <p>• <strong>Check-in khách:</strong> Tiếp nhận thông tin khách và phát thẻ thang máy tạm.</p>
                      <p>• <strong>Bưu phẩm:</strong> Quét mã kiện hàng và xếp vào tủ đồ thông minh.</p>
                      <p>• <strong>Đăng ký xe:</strong> Phê duyệt cà vẹt xe và cấp thẻ gửi xe tháng.</p>
                    </div>
                  </div>
                )}
              </div>

              {/* Notification Bell with Dropdown Panel */}
              <div className="relative">
                <button
                  onClick={() => setIsNotificationOpen(!isNotificationOpen)}
                  className="p-2 rounded-xl bg-white/80 hover:bg-white text-slate-500 hover:text-slate-800 border border-slate-200/70 shadow-xs transition-all relative group cursor-pointer"
                  title="Thông báo ca trực"
                >
                  <Bell className="w-4 h-4 group-hover:rotate-12 transition-transform" />
                  <span className="absolute top-1 right-1 w-2 h-2 rounded-full bg-rose-500 ring-2 ring-white" />
                </button>

                {isNotificationOpen && (
                  <div className="absolute right-0 mt-2 w-80 sm:w-96 rounded-2xl bg-white/95 backdrop-blur-2xl border border-white/80 shadow-2xl p-4 z-50 animate-in fade-in slide-in-from-top-2 duration-200">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                      <div className="flex items-center gap-2">
                        <span className="font-bold text-xs text-neutral-900">Thông báo ca trực</span>
                        <span className="text-[10px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 font-semibold">
                          {notifications.length} mới
                        </span>
                      </div>
                      <button
                        onClick={() => setIsNotificationOpen(false)}
                        className="text-slate-400 hover:text-slate-600 text-xs cursor-pointer"
                      >
                        Đóng
                      </button>
                    </div>

                    <div className="relative mt-2">
                      <div className="space-y-2 max-h-[240px] overflow-y-auto custom-scrollbar pr-1 pb-6">
                        {notifications.map((notif) => {
                          const isSec = notif.category === 'SECURITY';
                          const isGuest = notif.category === 'GUEST';
                          const badgeColor = isSec ? 'text-rose-600' : isGuest ? 'text-sky-600' : 'text-emerald-600';

                          return (
                            <div
                              key={notif.id}
                              className="p-2.5 rounded-xl bg-slate-50/90 hover:bg-slate-100/90 transition-colors border border-slate-100 text-left"
                            >
                              <div className="flex items-center justify-between text-[11px] text-slate-400">
                                <span className={`font-semibold ${badgeColor}`}>{notif.title}</span>
                                <span>{notif.timeAgo}</span>
                              </div>
                              <p className="text-xs text-slate-700 mt-0.5">{notif.message}</p>
                            </div>
                          );
                        })}
                      </div>

                      {/* Bottom Gradient Fade in Notification */}
                      <div className="pointer-events-none absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-white via-white/85 to-transparent rounded-b-2xl flex items-end justify-center pb-1">
                        <div className="flex items-center gap-1 text-[10px] text-slate-500 font-medium bg-white/90 backdrop-blur-xs px-2.5 py-0.5 rounded-full shadow-xs border border-slate-200/60">
                          <span>Còn nội dung ở dưới</span>
                          <ChevronDown className="w-2.5 h-2.5 text-sky-500 animate-bounce" />
                        </div>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              {/* Building Selector Dropdown */}
              <div className="relative">
                <button
                  onClick={() => setIsBuildingDropdownOpen(!isBuildingDropdownOpen)}
                  className="flex items-center gap-2 px-3 py-2 rounded-xl bg-white/80 hover:bg-white text-xs font-semibold text-neutral-800 border border-slate-200/80 shadow-xs hover:shadow-sm transition-all cursor-pointer"
                >
                  <Building className="w-3.5 h-3.5 text-sky-600" />
                  <span className="max-w-[130px] sm:max-w-none truncate">{selectedBuilding}</span>
                  <ChevronDown
                    className={`w-3.5 h-3.5 text-slate-400 transition-transform ${
                      isBuildingDropdownOpen ? 'rotate-180' : ''
                    }`}
                  />
                </button>

                {isBuildingDropdownOpen && (
                  <div className="absolute right-0 mt-2 w-64 rounded-2xl bg-white/95 backdrop-blur-2xl border border-white/80 shadow-2xl p-2 z-50 animate-in fade-in slide-in-from-top-2 duration-200">
                    <div className="text-[10px] uppercase font-bold text-slate-400 px-3 py-1.5 font-mono">
                      Chọn phạm vi tiếp đón
                    </div>
                    {buildingOptions.map((bldg) => (
                      <button
                        key={bldg}
                        onClick={() => {
                          setSelectedBuilding(bldg);
                          setIsBuildingDropdownOpen(false);
                          showToast(`Đã chuyển phạm vi: ${bldg}`);
                        }}
                        className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-colors cursor-pointer ${
                          selectedBuilding === bldg
                            ? 'bg-neutral-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100/80'
                        }`}
                      >
                        <span className="truncate">{bldg}</span>
                        {selectedBuilding === bldg && <Check className="w-3.5 h-3.5 text-sky-400 shrink-0" />}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Back to Public Home Page */}
              <button
                onClick={() => {
                  if (onNavigateHome) {
                    onNavigateHome();
                  } else {
                    window.history.pushState({}, '', '/home');
                    window.dispatchEvent(new PopStateEvent('popstate'));
                  }
                }}
                className="hidden sm:flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200/80 text-xs font-semibold text-slate-700 transition-colors cursor-pointer"
                title="Về trang chủ"
              >
                <HomeIcon className="w-3.5 h-3.5" />
                <span>Trang chủ</span>
              </button>
            </div>
          </div>
        </header>

        {/* ================= BODY CONTENT (CUỘN ĐỘC LẬP VỚI CUSTOM SCROLLBAR) ================= */}
        <main className="flex-1 overflow-y-auto w-full custom-scrollbar transition-all duration-300 ease-in-out p-4 sm:p-6 lg:p-8 xl:p-10">
          <div className="w-full max-w-7xl mx-auto space-y-6">
            {/* Section Breadcrumb Tag */}
            <div className="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-wider font-mono">
              <Building2 className="w-3.5 h-3.5 text-sky-500" />
              <span>RECEPTION PORTAL</span>
              <span>/</span>
              <span className="text-slate-700">CA TRỰC HIỆN TẠI</span>
            </div>

            {/* Page Heading & Export Button */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-2">
              <div>
                <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
                  Tổng quan Lễ tân
                </h1>
                <p className="text-xs sm:text-sm text-slate-500 mt-1">
                  Theo dõi khách vãng lai, bưu phẩm và đăng ký xe trong ca trực hiện tại.
                </p>
              </div>

              <div className="flex items-center gap-2.5">
                <button
                  onClick={() => showToast('Đang kết xuất báo cáo ca trực định dạng Excel / PDF...')}
                  className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white text-xs sm:text-sm font-semibold shadow-lg shadow-neutral-900/20 hover:shadow-xl hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 group cursor-pointer"
                >
                  <Download className="w-4 h-4 text-sky-400 group-hover:translate-y-0.5 transition-transform" />
                  <span>Xuất báo cáo</span>
                </button>
              </div>
            </div>

            {/* 4 Summary Stats Cards with Ultra Glassmorphism */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {/* Stat 1: Khách đang ở trong tòa */}
              <div className="bg-white/80 backdrop-blur-xl border border-white/70 rounded-2xl p-5 shadow-sm hover:shadow-md hover:border-sky-300/80 transition-all group relative overflow-hidden glass-specular-edge">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-slate-500 font-medium">Khách đang ở trong tòa</span>
                  <div className="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center group-hover:scale-110 transition-transform shadow-2xs">
                    <Users className="w-4 h-4" />
                  </div>
                </div>
                <div className="text-3xl sm:text-4xl font-black text-neutral-950 mt-3 tracking-tight">
                  {kpis.activeGuests}
                </div>
                <div className="text-[11px] text-slate-400 mt-2 flex items-center gap-1.5">
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                  <span>{kpis.activeGuestsNote}</span>
                </div>
                <div className="absolute -right-6 -bottom-6 w-24 h-24 bg-sky-400/10 rounded-full blur-xl pointer-events-none" />
              </div>

              {/* Stat 2: Bưu phẩm chờ nhận */}
              <div className="bg-white/80 backdrop-blur-xl border border-white/70 rounded-2xl p-5 shadow-sm hover:shadow-md hover:border-amber-300/80 transition-all group relative overflow-hidden glass-specular-edge">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-slate-500 font-medium">Bưu phẩm chờ nhận</span>
                  <div className="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center group-hover:scale-110 transition-transform shadow-2xs">
                    <Package className="w-4 h-4" />
                  </div>
                </div>
                <div className="text-3xl sm:text-4xl font-black text-neutral-950 mt-3 tracking-tight">
                  {kpis.pendingParcels}
                </div>
                <div className="text-[11px] text-amber-600 mt-2 font-medium flex items-center gap-1.5">
                  <Clock className="w-3 h-3" />
                  <span>{kpis.overdueParcelsNote}</span>
                </div>
                <div className="absolute -right-6 -bottom-6 w-24 h-24 bg-amber-400/10 rounded-full blur-xl pointer-events-none" />
              </div>

              {/* Stat 3: Đăng ký xe chờ duyệt */}
              <div className="bg-white/80 backdrop-blur-xl border border-white/70 rounded-2xl p-5 shadow-sm hover:shadow-md hover:border-indigo-300/80 transition-all group relative overflow-hidden glass-specular-edge">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-slate-500 font-medium">Đăng ký xe chờ duyệt</span>
                  <div className="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center group-hover:scale-110 transition-transform shadow-2xs">
                    <Car className="w-4 h-4" />
                  </div>
                </div>
                <div className="text-3xl sm:text-4xl font-black text-neutral-950 mt-3 tracking-tight">
                  {kpis.pendingVehicles}
                </div>
                <div className="text-[11px] text-slate-400 mt-2 flex items-center gap-1.5">
                  <span>{kpis.pendingVehiclesNote}</span>
                </div>
                <div className="absolute -right-6 -bottom-6 w-24 h-24 bg-indigo-400/10 rounded-full blur-xl pointer-events-none" />
              </div>

              {/* Stat 4: Sự cố an ninh */}
              <div className="bg-white/80 backdrop-blur-xl border border-white/70 rounded-2xl p-5 shadow-sm hover:shadow-md hover:border-rose-300/80 transition-all group relative overflow-hidden glass-specular-edge">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-slate-500 font-medium">Sự cố an ninh</span>
                  <div className="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center group-hover:scale-110 transition-transform shadow-2xs">
                    <ShieldCheck className="w-4 h-4" />
                  </div>
                </div>
                <div className="text-3xl sm:text-4xl font-black text-neutral-950 mt-3 tracking-tight">
                  {kpis.securityIncidents}
                </div>
                <div className="text-[11px] text-rose-600 mt-2 font-medium flex items-center gap-1.5">
                  <span className="w-1.5 h-1.5 rounded-full bg-rose-500 animate-ping" />
                  <span>{kpis.securityIncidentsNote}</span>
                </div>
                <div className="absolute -right-6 -bottom-6 w-24 h-24 bg-rose-400/10 rounded-full blur-xl pointer-events-none" />
              </div>
            </div>

            {/* 2 Main Panels Below with Ultra Glassmorphism */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {/* Panel 1: Hoạt động trong ca */}
              <div className="bg-white/80 backdrop-blur-xl border border-white/70 rounded-2xl p-5 sm:p-6 shadow-sm hover:shadow-md transition-all flex flex-col justify-between glass-specular-edge">
                <div>
                  <div className="flex items-start justify-between">
                    <div>
                      <h3 className="text-base font-bold text-neutral-900">Hoạt động trong ca</h3>
                      <p className="text-xs text-slate-400 mt-0.5">Tổng hợp lượt ra vào mới nhất</p>
                    </div>
                    <div className="w-8 h-8 rounded-xl bg-slate-100/90 flex items-center justify-center text-slate-500">
                      <User className="w-4 h-4" />
                    </div>
                  </div>

                  <div className="mt-5 space-y-3">
                    {activities.map((act) => (
                      <div
                        key={act.num}
                        className="flex items-center justify-between p-3 rounded-xl bg-slate-50/80 hover:bg-slate-100/90 border border-slate-100 transition-colors"
                      >
                        <div className="flex items-center gap-3 min-w-0">
                          <span className="text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-1 shrink-0 shadow-2xs">
                            {act.num}
                          </span>
                          <span className="text-xs sm:text-sm font-semibold text-neutral-800 truncate">
                            {act.title}
                          </span>
                        </div>
                        <span className="text-[11px] text-slate-400 shrink-0 ml-2">{act.time}</span>
                      </div>
                    ))}
                  </div>
                </div>

                <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                  <span>Dữ liệu đồng bộ tự động theo thời gian thực</span>
                  <button
                    onClick={() => fetchReceptionData(true)}
                    className="text-sky-600 font-semibold hover:underline cursor-pointer"
                  >
                    Làm mới ngay
                  </button>
                </div>
              </div>

              {/* Panel 2: Thao tác nhanh */}
              <div className="bg-white/80 backdrop-blur-xl border border-white/70 rounded-2xl p-5 sm:p-6 shadow-sm hover:shadow-md transition-all flex flex-col justify-between glass-specular-edge">
                <div>
                  <div className="flex items-start justify-between">
                    <div>
                      <h3 className="text-base font-bold text-neutral-900">Thao tác nhanh</h3>
                      <p className="text-xs text-slate-400 mt-0.5">Các nghiệp vụ thường dùng tại quầy</p>
                    </div>
                    <div className="w-8 h-8 rounded-xl bg-slate-100/90 flex items-center justify-center text-slate-500">
                      <Bell className="w-4 h-4" />
                    </div>
                  </div>

                  <div className="grid grid-cols-3 gap-3 mt-5">
                    {/* Action 1: Check-in khách */}
                    <button
                      onClick={() => showToast('Mở cửa sổ tiếp đón & Check-in khách')}
                      className="flex flex-col items-center justify-center p-4 sm:p-5 border border-slate-200/90 rounded-2xl hover:border-sky-300 hover:bg-sky-50/50 transition-all text-neutral-800 cursor-pointer group shadow-2xs hover:shadow-xs"
                    >
                      <div className="w-10 h-10 rounded-xl bg-slate-100 group-hover:bg-sky-500 group-hover:text-white flex items-center justify-center text-slate-700 transition-colors mb-2.5">
                        <User className="w-5 h-5 group-hover:scale-110 transition-transform" />
                      </div>
                      <span className="text-xs font-semibold text-neutral-800 group-hover:text-sky-900">
                        Check-in khách
                      </span>
                    </button>

                    {/* Action 2: Nhận bưu phẩm */}
                    <button
                      onClick={() => showToast('Mở máy quét mã & Nhận bưu phẩm tại quầy')}
                      className="flex flex-col items-center justify-center p-4 sm:p-5 border border-slate-200/90 rounded-2xl hover:border-amber-300 hover:bg-amber-50/50 transition-all text-neutral-800 cursor-pointer group shadow-2xs hover:shadow-xs"
                    >
                      <div className="w-10 h-10 rounded-xl bg-slate-100 group-hover:bg-amber-500 group-hover:text-white flex items-center justify-center text-slate-700 transition-colors mb-2.5">
                        <Package className="w-5 h-5 group-hover:scale-110 transition-transform" />
                      </div>
                      <span className="text-xs font-semibold text-neutral-800 group-hover:text-amber-900">
                        Nhận bưu phẩm
                      </span>
                    </button>

                    {/* Action 3: Đăng ký xe */}
                    <button
                      onClick={() => showToast('Mở danh sách hồ sơ xe chờ phê duyệt')}
                      className="flex flex-col items-center justify-center p-4 sm:p-5 border border-slate-200/90 rounded-2xl hover:border-indigo-300 hover:bg-indigo-50/50 transition-all text-neutral-800 cursor-pointer group shadow-2xs hover:shadow-xs"
                    >
                      <div className="w-10 h-10 rounded-xl bg-slate-100 group-hover:bg-indigo-500 group-hover:text-white flex items-center justify-center text-slate-700 transition-colors mb-2.5">
                        <Car className="w-5 h-5 group-hover:scale-110 transition-transform" />
                      </div>
                      <span className="text-xs font-semibold text-neutral-800 group-hover:text-indigo-900">
                        Đăng ký xe
                      </span>
                    </button>
                  </div>
                </div>

                <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
                  <span>Hỗ trợ phím tắt và quét mã vạch</span>
                  <span className="text-emerald-600 font-medium">Sẵn sàng nhận lệnh</span>
                </div>
              </div>
            </div>
          </div>
        </main>
      </div>

      {/* ========================================================
          MODAL: MÔ HÌNH 3D TÒA NHÀ (INTERACTIVE 3D VIEWER)
          ======================================================== */}
      {is3DModelOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-6 bg-slate-950/70 backdrop-blur-md animate-in fade-in duration-200">
          <div className="w-full max-w-5xl h-[85vh] rounded-3xl bg-white/95 backdrop-blur-2xl border border-white/80 shadow-2xl overflow-hidden flex flex-col relative">
            <div className="p-4 border-b border-slate-100 flex items-center justify-between bg-white/80">
              <div className="flex items-center gap-2.5">
                <div className="w-8 h-8 rounded-lg bg-sky-500 text-white flex items-center justify-center shadow-sm">
                  <Layers className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="font-bold text-sm text-neutral-900">Mô hình phân tầng 3D Tòa nhà</h3>
                  <p className="text-[11px] text-slate-400">Khối / Tòa nhà & Tiếp đón trực quan</p>
                </div>
              </div>
              <button
                onClick={() => setIs3DModelOpen(false)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-4 sm:p-6 bg-slate-50/50 custom-scrollbar">
              <Building3DModel />
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ReceptionHome;
