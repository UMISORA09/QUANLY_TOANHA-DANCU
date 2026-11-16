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
import { AppLayout } from '../Components/Layout/AppLayout';
import { VehicleManagement } from './Admin/VehicleManagement';
import { RfidManagement } from './Admin/RfidManagement';
import { ReceptionVisitorCheckinPanel } from '../Components/ReceptionVisitorCheckinPanel';
import { ReceptionParcelPanel } from '../Components/ReceptionParcelPanel';
import { ReceptionDashboardPanel } from '../Components/ReceptionDashboardPanel';


export interface ReceptionHomeProps {
  onLogout?: () => void;
  onNavigateHome?: () => void;
  onNavigateAdmin?: () => void;
  userName?: string;
  userEmail?: string;
  userRole?: string;
  initialTab?: string;
}

interface MenuItem {
  id: string;
  label: string;
  icon: React.ComponentType<{ className?: string }> | React.ElementType;
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
  message?: string;
  desc?: string;
  time?: string;
  timeAgo?: string;
  category?: 'GUEST' | 'PARCEL' | 'SECURITY' | string;
  isRead?: boolean;
}

export const ReceptionHome: React.FC<ReceptionHomeProps> = ({
  onLogout,
  onNavigateHome,
  onNavigateAdmin,
  userName = 'Lễ Tân Sảnh Chính',
  userEmail = 'receptionist@demo.local',
  userRole = 'receptionist',
  initialTab,
}) => {
  // Helper xác định tab ban đầu linh hoạt theo URL hoặc prop
  const resolveInitialTab = (): string => {
    const path = window.location.pathname;
    if (
      path === '/le-tan/vehicles' ||
      path === '/le-tan/phuong-tien' ||
      path === '/an-ninh/vehicles' ||
      path === '/an-ninh/phuong-tien' ||
      path.includes('/vehicles') ||
      path.includes('/phuong-tien')
    ) {
      return 'vehicle_reg';
    }
    if (
      path === '/le-tan/visitors' ||
      path === '/le-tan/khach' ||
      path === '/an-ninh/visitors' ||
      path.includes('/visitors')
    ) {
      return 'visitors';
    }
    if (
      path === '/le-tan/parcels' ||
      path === '/le-tan/buu-pham' ||
      path.includes('/parcels') ||
      path.includes('/buu-pham')
    ) {
      return 'parcels';
    }
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam) {
      if (tabParam === 'vehicles' || tabParam === 'phuong-tien') return 'vehicle_reg';
      if (tabParam === 'rfid' || tabParam === 'rfid_cards' || tabParam === 'the-tu') return 'rfid_cards';
      if (tabParam === 'visitors' || tabParam === 'khach' || tabParam === 'checkin') return 'visitors';
      if (tabParam === 'parcels' || tabParam === 'buu_pham' || tabParam === 'kien_hang') return 'parcels';
      return tabParam;
    }
    if (initialTab) {
      if (initialTab === 'vehicles' || initialTab === 'phuong-tien') return 'vehicle_reg';
      if (initialTab === 'visitors' || initialTab === 'khach') return 'visitors';
      if (initialTab === 'parcels' || initialTab === 'buu_pham') return 'parcels';
      return initialTab;
    }
    return 'overview';
  };

  // Navigation & Interactive states
  const [activeMenuId, setActiveMenuId] = useState<string>(resolveInitialTab);
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState<boolean>(false);
  const [isNotificationOpen, setIsNotificationOpen] = useState<boolean>(false);
  const [isHelpOpen, setIsHelpOpen] = useState<boolean>(false);
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

  // Live Stats from Database / Seeders (Chuẩn dữ liệu theo ảnh mẫu)
  const [kpis, setKpis] = useState({
    activeGuests: 344,
    activeGuestsNote: 'Tất cả khu vực',
    pendingParcels: 86,
    overdueParcelsNote: '42 bưu phẩm quá hạn',
    pendingVehicles: 174,
    pendingVehiclesNote: 'Cần kiểm tra giấy tờ',
    securityIncidents: '02',
    securityIncidentsNote: 'Đang được xử lý',
  });

  const [activities, setActivities] = useState<ActivityItem[]>([
    { num: '01', title: 'Khách Nguyễn Minh Anh đã check-in', time: '10 phút trước', type: 'guest' },
    { num: '02', title: 'Bưu phẩm PKG–031 đã được tiếp nhận', time: '25 phút trước', type: 'parcel' },
    { num: '03', title: 'Xe 30K–678.90 chờ duyệt đăng ký', time: '45 phút trước', type: 'vehicle' },
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

  // Menu items with live badges (Chuẩn 100% Ảnh mẫu)
  const menuItems: MenuItem[] = useMemo(
    () => [
      { id: 'overview', label: 'Tổng quan Lễ tân', icon: Gauge },
      { id: 'visitors', label: 'Khách & Check-in An ninh', icon: Users, badge: `${kpis.activeGuests}` },
      { id: 'vehicle_reg', label: 'Đăng ký xe & Đẩy p...', icon: CreditCard, badge: `${kpis.pendingVehicles} chờ duyệt` },
      { id: 'rfid_cards', label: 'Quản lý Thẻ từ RFID', icon: CreditCard },
      { id: 'parking', label: 'Bãi đỗ xe & ANPR', icon: Car },
      { id: 'lost_found', label: 'Đồ thất lạc', icon: PackageOpen },
      { id: 'smart_lockers', label: 'Tủ đồ thông minh', icon: Layers, badge: '24 ô' },
      { id: 'walkin_guest', label: 'Check-In Khách vãng lai', icon: UserPlus },
      { id: 'parcels', label: 'Quản lý Bưu phẩm', icon: Package, badge: `${kpis.pendingParcels}` },
    ],
    [kpis]
  );


  const handleMenuClick = (id: string) => {
    setActiveMenuId(id);
    setMobileSidebarOpen(false);
    if (id !== 'overview') {
      const item = menuItems.find((m) => m.id === id);
      showToast(`Chuyển đến phân hệ: ${item?.label || id}`);
    }
  };

  return (
    <AppLayout
      role="receptionist"
      userRole={userRole as any}
      activeItemId={activeMenuId}
      onItemClick={handleMenuClick}
      customItems={menuItems}
      userName={userName}
      userEmail={userEmail}
      onLogout={onLogout}
      onNavigateHome={onNavigateHome}
      statusText="Ca trực an ninh hoạt động"
      onNotificationClick={() => setIsNotificationOpen(!isNotificationOpen)}
      onHelpClick={() => setIsHelpOpen(!isHelpOpen)}
      unreadNotificationCount={notifications.filter((n) => !n.isRead).length}
      extraTopbarActions={
        <>
          {/* Nút Làm mới Database */}
          <button
            type="button"
            onClick={() => fetchReceptionData(true)}
            className="p-2 rounded-xl bg-white/80 hover:bg-white text-slate-500 hover:text-sky-600 border border-slate-200/70 shadow-xs hover:shadow-sm transition-all cursor-pointer group"
            title="Đồng bộ dữ liệu Database"
          >
            <RefreshCw
              className={`w-4 h-4 ${
                isLoading
                  ? 'animate-spin text-sky-600'
                  : 'group-hover:rotate-180 transition-transform duration-500'
              }`}
            />
          </button>

          {/* Bộ điều khiển Thu phóng giao diện (UI Zoom Control) - Ẩn trên mobile để chống tràn thanh tiêu đề */}
          <div className="hidden md:flex items-center bg-slate-100/90 hover:bg-slate-100 border border-slate-200/80 rounded-xl p-0.5 shadow-xs transition-all">
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
              disabled={zoomLevel >= 120}
              className="p-1.5 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-white disabled:opacity-30 disabled:hover:bg-transparent transition-all cursor-pointer"
              title="Phóng to giao diện (Ctrl +)"
            >
              <ZoomIn className="w-3.5 h-3.5" />
            </button>
          </div>
        </>
      }
    >
      {/* Notifications Popover */}
      {isNotificationOpen && (
        <div className="fixed top-20 right-4 sm:right-10 w-80 sm:w-96 rounded-3xl bg-white/95 backdrop-blur-2xl border border-slate-200/90 shadow-2xl p-4 z-50 animate-in fade-in slide-in-from-top-2 duration-150 text-left">
          <div className="flex items-center justify-between pb-3 border-b border-slate-100">
            <div className="flex items-center gap-2">
              <span className="font-bold text-xs text-neutral-900">Thông báo ca trực</span>
              <span className="text-[10px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 font-semibold">
                {notifications.filter((n) => !n.isRead).length} mới
              </span>
            </div>
            <button
              type="button"
              onClick={() => setIsNotificationOpen(false)}
              className="text-slate-400 hover:text-slate-600 text-xs cursor-pointer p-1"
            >
              Đóng
            </button>
          </div>
          <div className="mt-2 space-y-2 max-h-[260px] overflow-y-auto custom-scrollbar pr-1">
            {notifications.map((notif) => (
              <div
                key={notif.id}
                className="p-2.5 rounded-xl bg-slate-50/90 hover:bg-slate-100/90 transition-colors border border-slate-100 text-left"
              >
                <div className="flex items-center justify-between text-[11px] text-slate-400">
                  <span className="font-semibold text-neutral-800">{notif.title}</span>
                  <span>{notif.time || notif.timeAgo || 'Vừa xong'}</span>
                </div>
                <p className="text-xs text-slate-600 mt-0.5">{notif.desc || notif.message || ''}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Help Popover */}
      {isHelpOpen && (
        <div className="fixed top-20 right-4 sm:right-14 w-72 rounded-3xl bg-white/95 backdrop-blur-2xl border border-slate-200/90 shadow-2xl p-4 z-50 animate-in fade-in slide-in-from-top-2 duration-150 text-left">
          <div className="flex items-center justify-between pb-2 border-b border-slate-100">
            <span className="font-bold text-xs text-neutral-900">Trợ giúp Lễ tân</span>
            <button
              type="button"
              onClick={() => setIsHelpOpen(false)}
              className="text-slate-400 hover:text-slate-600 text-xs cursor-pointer p-1"
            >
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

      {/* Main Content Container */}
      <div className="p-4 sm:p-6 lg:p-8 xl:p-10">
        {activeMenuId === 'vehicle_reg' || activeMenuId === 'parking' ? (
          <div className="w-full max-w-7xl mx-auto space-y-6 animate-in fade-in duration-200">
            {/* Embedded Vehicle Management Module */}
            <div className="bg-white/95 backdrop-blur-2xl border border-white/90 rounded-3xl p-6 sm:p-8 shadow-2xl glass-specular-edge">
              <VehicleManagement embedded={true} portalMode="receptionist" />
            </div>
          </div>
        ) : activeMenuId === 'rfid_cards' ? (
          <div className="w-full max-w-7xl mx-auto space-y-6 animate-in fade-in duration-200">
            <div className="bg-white/95 backdrop-blur-2xl border border-white/90 rounded-3xl p-6 sm:p-8 shadow-2xl glass-specular-edge">
              <RfidManagement embedded={true} portalMode="receptionist" />
            </div>
          </div>
        ) : activeMenuId === 'visitors' || activeMenuId === 'walkin_guest' ? (
          <div className="w-full max-w-7xl mx-auto space-y-6 animate-in fade-in duration-200">
            <div className="bg-white/95 backdrop-blur-2xl border border-white/90 rounded-3xl p-6 sm:p-8 shadow-2xl glass-specular-edge">
              <ReceptionVisitorCheckinPanel embedded={true} />
            </div>
          </div>
        ) : activeMenuId === 'parcels' ? (
          <div className="w-full max-w-7xl mx-auto space-y-6 animate-in fade-in duration-200">
            <div className="bg-white/95 backdrop-blur-2xl border border-white/90 rounded-3xl p-6 sm:p-8 shadow-2xl glass-specular-edge">
              <ReceptionParcelPanel embedded={true} />
            </div>
          </div>
        ) : (
          <div className="w-full max-w-7xl mx-auto space-y-6 animate-in fade-in duration-200">
            <ReceptionDashboardPanel onNavigateTab={handleMenuClick} />
          </div>
        )}
      </div>

      {/* ================= TOAST NOTIFICATION ================= */}
      {toastMessage && (
        <div className="fixed top-6 right-6 z-50 flex items-center gap-2.5 px-4 py-3 rounded-2xl bg-neutral-900/90 text-white text-xs font-medium shadow-2xl backdrop-blur-md border border-white/10 animate-in fade-in slide-in-from-top-3 duration-200">
          <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
          <span>{toastMessage}</span>
        </div>
      )}
    </AppLayout>
  );
};

export default ReceptionHome;
