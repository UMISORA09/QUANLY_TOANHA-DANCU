import React, { useState, useEffect, useMemo } from 'react';
import {
  LayoutDashboard,
  Building2,
  Users,
  Tag,
  Zap,
  Receipt,
  Wrench,
  Sparkles,
  Bell,
  FileCheck,
  ShieldCheck,
  ShieldAlert,
  Clock,
  HeartHandshake,
  LogOut,
  ChevronDown,
  ChevronRight,
  Maximize2,
  Minimize2,
  Download,
  Search,
  HelpCircle,
  TrendingUp,
  AlertCircle,
  CheckCircle2,
  Calendar,
  Layers,
  ArrowUpRight,
  SlidersHorizontal,
  RefreshCw,
  Eye,
  X,
  FileSpreadsheet,
  FileText,
  Building,
  Menu,
  Home,
  Check,
  MoreVertical,
  Activity,
  Send,
  Compass
} from 'lucide-react';
import { Building3DModel } from '../Components/Building3DModel';
import { AmenityManagement } from './Admin/AmenityManagement';
import { RbacManagement } from './Admin/RbacManagement';
import { AppLayout } from '../Components/Layout/AppLayout';
import { api } from '../Services/api';
import { amenityCache } from '../Services/amenityCache';

export interface ManagementHomeProps {
  onLogout?: () => void;
  onNavigateHome?: () => void;
  userRole?: 'manager' | 'admin' | string;
  userName?: string;
  userEmail?: string;
  initialTab?: string;
}

interface TicketItem {
  id: string;
  title: string;
  location: string;
  timeAgo?: string;
  status: 'new' | 'processing' | 'received' | 'done';
  statusText: string;
  priority: 'high' | 'medium' | 'low';
  description?: string;
  creatorName?: string;
  creatorPhone?: string;
}

interface ActivityRow {
  id: string;
  category: string;
  value: string;
  percentage?: number;
  status: 'stable' | 'attention' | 'passed' | 'normal' | 'done';
  statusText: string;
  updatedAt: string;
}

interface RevenueItem {
  month: string;
  period?: string;
  revenue: number;
  collection: number;
  debt: number;
  target: number;
  label: string;
  targetAchievedPercent?: number;
}

interface RevenueSummary {
  totalRevenueBillion: string;
  collectionRate: string;
  latestMonthLabel: string;
  latestTargetAchieved: string;
  growthYoY: string;
  growthNote: string;
}

export const ManagementHome: React.FC<ManagementHomeProps> = ({
  onLogout,
  onNavigateHome,
  userRole = 'admin',
  userName = 'Admin Cassavas',
  userEmail = 'admin@cassavas.vn',
  initialTab,
}) => {
  // Helper to determine initial active tab:
  // - Khi mới đăng nhập hoặc khởi động lại trang quản lý (/admin): luôn luôn là 'overview' (Tổng quan)
  // - Khi người dùng bấm sang mục khác: chuyển và ở yên mục đó cho đến khi out khỏi
  const resolveInitialTab = (): string => {
    const path = window.location.pathname;

    // 1. Kiểm tra URL path trực tiếp tới tiện ích hoặc phân quyền
    if (path === '/admin/amenities' || path === '/admin/tien-ich' || path.startsWith('/admin/amenities')) {
      return 'amenities';
    }
    if (path === '/admin/roles' || path === '/admin/rbac' || path === '/admin/phan-quyen' || path.startsWith('/admin/roles')) {
      return 'roles';
    }

    // 2. Kiểm tra URL query param: ?tab=xxx
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam) {
      return tabParam;
    }

    // 3. Prop initialTab được truyền từ component cha (nếu có giá trị cụ thể khác overview)
    if (initialTab && initialTab !== 'overview') {
      return initialTab;
    }

    // 4. Khi vào route quản trị gốc (/admin, /dashboard, /quan-ly, /manager) mà không có tab query:
    // MẶC ĐỊNH LUÔN LUÔN LÀ 'overview' (Tổng quan) khi mới đăng nhập hoặc khởi động lại trang quản lý.
    if (path === '/admin' || path === '/dashboard' || path === '/quan-ly' || path === '/manager') {
      return 'overview';
    }

    // 5. Trong cùng một phiên làm việc (session), kiểm tra sessionStorage nếu người dùng reload F5
    try {
      const savedTab = sessionStorage.getItem('smartcassavas_active_admin_tab');
      if (savedTab) {
        return savedTab;
      }
    } catch {
      // ignore
    }

    return 'overview';
  };

  // Navigation & Interactive states
  const [activeMenuId, setActiveMenuId] = useState<string>(resolveInitialTab);
  const [selectedBuilding, setSelectedBuilding] = useState<string>('Khu A - Tất cả tòa nhà');
  const [isBuildingDropdownOpen, setIsBuildingDropdownOpen] = useState<boolean>(false);
  const [isNotificationOpen, setIsNotificationOpen] = useState<boolean>(false);
  const [isHelpOpen, setIsHelpOpen] = useState<boolean>(false);
  const [isExportModalOpen, setIsExportModalOpen] = useState<boolean>(false);
  const [is3DModelOpen, setIs3DModelOpen] = useState<boolean>(false);
  const [selectedTicket, setSelectedTicket] = useState<TicketItem | null>(null);
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState<boolean>(false);
  const [chartActiveBar, setChartActiveBar] = useState<number | null>(5); // Default to T8
  const [chartFilter, setChartFilter] = useState<'revenue' | 'collection' | 'debt'>('revenue');
  const [mousePos, setMousePos] = useState<{ x: number; y: number }>({ x: 0, y: 0 });
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [currentTime, setCurrentTime] = useState<string>('');

  // Sidebar Collapse / Expand State
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

  // Fullscreen State & Handler
  const [isFullscreen, setIsFullscreen] = useState<boolean>(false);
  const [hoveredTooltip, setHoveredTooltip] = useState<{
    label: string;
    badge?: string | null;
    top: number;
  } | null>(null);

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
        showToast('Đã chuyển sang chế độ Toàn màn hình');
      } else {
        if (document.exitFullscreen) {
          await document.exitFullscreen();
          showToast('Đã thoát chế độ Toàn màn hình');
        }
      }
    } catch {
      showToast('Trình duyệt không hỗ trợ hoặc bị chặn toàn màn hình');
    }
  };

  // Live formatted Vietnamese date
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

  // Tự động tải trước (Prefetch) danh mục Tiện ích ở background SAU KHI trang chính đã render mượt mà
  useEffect(() => {
    const timer = setTimeout(() => {
      api.prefetchAmenities({ page: 1, limit: 10, sort: 'created_at_desc' });
      api.getCategories().catch(() => {});
      api.getBlocks().catch(() => {});
    }, 3500);
    return () => clearTimeout(timer);
  }, []);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  // Đọc snapshot lưu trong sessionStorage để hiển thị tức thì (0ms) khi tải trang
  const getCachedDashboard = () => {
    try {
      const raw = sessionStorage.getItem('smartcassavas_dashboard_cache');
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  };
  const cachedDash = getCachedDashboard();

  // KPI Summary State from Database
  const [kpis, setKpis] = useState(() => {
    if (cachedDash?.kpis) {
      return {
        totalResidents: Number(cachedDash.kpis.totalResidents).toLocaleString('vi-VN'),
        residentsGrowth: cachedDash.kpis.residentsGrowth || '+8 người trong tháng này',
        unpaidInvoices: cachedDash.kpis.unpaidInvoices ?? 86,
        unpaidInvoicesPercent: cachedDash.kpis.unpaidInvoicesPercent || '12.4% tổng hóa đơn',
        activeTickets: cachedDash.kpis.activeTickets ?? 24,
        overdueTickets: cachedDash.kpis.overdueTickets || '8 ticket quá hạn',
        amenityBookings: cachedDash.kpis.amenityBookings ?? 32,
        amenityFreeSlots: cachedDash.kpis.amenityFreeSlots || '8 khung giờ còn trống',
      };
    }
    return {
      totalResidents: '1,248',
      residentsGrowth: '+8 người trong tháng này',
      unpaidInvoices: 86,
      unpaidInvoicesPercent: '14.3% tổng hóa đơn',
      activeTickets: 24,
      overdueTickets: '8 ticket quá hạn',
      amenityBookings: 32,
      amenityFreeSlots: '8 khung giờ còn trống',
    };
  });

  // Revenue Bar Chart Data from Database (6 months: T3 to T8)
  const [revenueData, setRevenueData] = useState<RevenueItem[]>(() => {
    if (cachedDash?.revenueData?.length) {
      return cachedDash.revenueData;
    }
    return [
      { month: 'T3', period: '2026-03', revenue: 1150, collection: 1012, debt: 138, target: 1100, label: '1.15 tỷ', targetAchievedPercent: 104.5 },
      { month: 'T4', period: '2026-04', revenue: 1220, collection: 1147, debt: 73, target: 1200, label: '1.22 tỷ', targetAchievedPercent: 101.7 },
      { month: 'T5', period: '2026-05', revenue: 1280, collection: 1101, debt: 179, target: 1250, label: '1.28 tỷ', targetAchievedPercent: 102.4 },
      { month: 'T6', period: '2026-06', revenue: 1340, collection: 1206, debt: 134, target: 1300, label: '1.34 tỷ', targetAchievedPercent: 103.1 },
      { month: 'T7', period: '2026-07', revenue: 1390, collection: 1168, debt: 222, target: 1350, label: '1.39 tỷ', targetAchievedPercent: 103 },
      { month: 'T8', period: '2026-08', revenue: 1480, collection: 1066, debt: 414, target: 1400, label: '1.48 tỷ', targetAchievedPercent: 105.7 },
    ];
  });

  // Revenue Summary from Database
  const [revenueSummary, setRevenueSummary] = useState<RevenueSummary>(() => {
    if (cachedDash?.revenueSummary) {
      return cachedDash.revenueSummary;
    }
    return {
      totalRevenueBillion: '7.86 Tỷ VNĐ',
      collectionRate: '85.2%',
      latestMonthLabel: 'Chỉ tiêu tháng 8',
      latestTargetAchieved: '105.7% Đạt',
      growthYoY: '+28.7%',
      growthNote: 'Tổng thu thực tế trong 6 tháng gần nhất từ Database',
    };
  });

  // Notifications state
  const [notifications, setNotifications] = useState<Array<{
    id: string;
    title: string;
    message: string;
    category?: string;
    timeAgo?: string;
    isRead?: boolean;
  }>>([
    { id: '1', title: 'Sự cố nước ban công', message: 'Căn hộ A012 yêu cầu kỹ thuật kiểm tra van tổng.', timeAgo: '10 phút trước', isRead: false },
    { id: '2', title: 'Hóa đơn quá hạn', message: '8 hóa đơn dịch vụ tháng 8 chưa hoàn tất thanh toán.', timeAgo: '45 phút trước', isRead: false },
    { id: '3', title: 'Đồng bộ chỉ số', message: 'Đã đồng bộ 270 chỉ số công tơ điện nước thông minh.', timeAgo: '2 giờ trước', isRead: false },
    { id: '4', title: 'Lịch bảo trì thang máy', message: 'Đội kỹ thuật Otis tiến hành kiểm định thang T2 Tháp A.', timeAgo: '3 giờ trước', isRead: false },
    { id: '5', title: 'Cư dân đăng ký mới', message: 'Hộ gia đình căn B1405 hoàn tất xác thực eKYC.', timeAgo: '5 giờ trước', isRead: false },
  ]);

  // Sidebar Menu Items dynamically bound to Database KPIs and userRole
  const menuItems = useMemo(
    () => {
      const baseItems = [
        { id: 'overview', label: userRole === 'admin' ? 'Tổng quan Hệ thống' : 'Bàn làm việc Vận hành', icon: LayoutDashboard, badge: null, active: true },
        { id: 'buildings', label: 'Khối / Tòa nhà & Căn hộ', icon: Building2, badge: null },
        { id: 'residents', label: 'Cư dân', icon: Users, badge: kpis.totalResidents },
        { id: 'pricing', label: 'Đơn giá', icon: Tag, badge: null },
        { id: 'metering', label: 'Chốt điện / nước', icon: Zap, badge: 'IoT' },
        { id: 'invoices', label: 'Hóa đơn', icon: Receipt, badge: String(kpis.unpaidInvoices) },
        { id: 'tickets', label: 'Yêu cầu / Sự cố', icon: Wrench, badge: String(kpis.activeTickets) },
        { id: 'amenities', label: 'Quản lý & Danh mục tiện ích', icon: Sparkles, badge: String(kpis.amenityBookings) },
        { id: 'news', label: 'Bảng tin / Thông báo', icon: Bell, badge: `${notifications.filter(n => !n.isRead).length || 2} mới` },
        { id: 'contracts', label: 'Hợp đồng & Chữ ký điện tử', icon: FileCheck, badge: null },
        { id: 'ekyc', label: 'eKYC & Xác thực CCCD', icon: ShieldCheck, badge: 'AI' },
        { id: 'assets', label: 'Tài sản & Bảo trì thiết bị', icon: ShieldAlert, badge: null },
        { id: 'shifts', label: 'Chấm công & Bàn giao ca', icon: Clock, badge: null },
        { id: 'feedback', label: 'Góp ý & Phân tích cảm xúc', icon: HeartHandshake, badge: '96%' },
      ];

      if (userRole === 'admin') {
        baseItems.splice(1, 0, {
          id: 'roles',
          label: 'Phân quyền tài khoản (Admin)',
          icon: ShieldCheck,
          badge: 'Đặc quyền',
        });
      }

      return baseItems;
    },
    [kpis, notifications, userRole]
  );

  // Ticket Data matching database seed
  const [tickets, setTickets] = useState<TicketItem[]>([
    {
      id: 'TK-1082',
      title: 'Rò rỉ nước tại ban công',
      location: 'Căn hộ A012 · 2 giờ trước',
      status: 'new',
      statusText: 'MỚI',
      priority: 'high',
    },
    {
      id: 'TK-1081',
      title: 'Đăng ký thẻ cư dân mới',
      location: 'Căn hộ A013 · 2 giờ trước',
      status: 'processing',
      statusText: 'ĐANG XỬ LÝ',
      priority: 'medium',
    },
    {
      id: 'TK-1080',
      title: 'Đèn hành lang tầng 8',
      location: 'Căn hộ A014 · 2 giờ trước',
      status: 'processing',
      statusText: 'ĐANG XỬ LÝ',
      priority: 'medium',
    },
    {
      id: 'TK-1079',
      title: 'Khóa vân tay sảnh chính tháp B',
      location: 'Căn hộ B201 · 3 giờ trước',
      status: 'received',
      statusText: 'ĐÃ TIẾP NHẬN',
      priority: 'high',
    },
    {
      id: 'TK-1078',
      title: 'Khiếu nại tiếng ồn sau 22h',
      location: 'Căn hộ C505 · Hôm qua',
      status: 'done',
      statusText: 'ĐÃ XONG',
      priority: 'low',
    },
  ]);

  // Activity Rows matching database seed
  const [activities, setActivities] = useState<ActivityRow[]>([
    {
      id: 'act-1',
      category: 'Khu A - 120 căn hộ',
      value: '98% lấp đầy (118/120)',
      percentage: 98,
      status: 'stable',
      statusText: 'Ổn định',
      updatedAt: '5 phút trước',
    },
    {
      id: 'act-2',
      category: 'Khu B - 86 căn hộ',
      value: '91% lấp đầy (78/86)',
      percentage: 91,
      status: 'stable',
      statusText: 'Ổn định',
      updatedAt: '12 phút trước',
    },
    {
      id: 'act-3',
      category: 'Khu C - 64 căn hộ',
      value: '74% lấp đầy (47/64)',
      percentage: 74,
      status: 'attention',
      statusText: 'Cần chú ý',
      updatedAt: '1 giờ trước',
    },
    {
      id: 'act-4',
      category: 'Bảo trì thang máy Tòa A',
      value: 'Hoàn thành kiểm định 4/4 buồng',
      status: 'passed',
      statusText: 'Đạt chuẩn',
      updatedAt: '2 giờ trước',
    },
    {
      id: 'act-5',
      category: 'Hệ thống PCCC khu B',
      value: 'Kiểm tra áp lực ống dẫn 2.5 bar',
      status: 'normal',
      statusText: 'Bình thường',
      updatedAt: 'Hôm qua',
    },
    {
      id: 'act-6',
      category: 'Chốt số điện/nước T8',
      value: 'Đạt 99.2% dữ liệu IoT Smart Meter',
      status: 'done',
      statusText: 'Hoàn thành',
      updatedAt: '2 ngày trước',
    },
  ]);



  // Fetch live seeded data from Laravel backend API
  const fetchDashboardData = async (isManualRefresh = false) => {
    try {
      if (isManualRefresh) {
        showToast('Đang kết nối cơ sở dữ liệu Docker...');
      }
      const res = await fetch('/api/management/overview');
      if (!res.ok) return;
      const data = await res.json();
      if (data.success) {
        if (data.kpis) {
          setKpis({
            totalResidents: Number(data.kpis.totalResidents).toLocaleString('vi-VN'),
            residentsGrowth: data.kpis.residentsGrowth || '+8 người trong tháng này',
            unpaidInvoices: data.kpis.unpaidInvoices ?? 86,
            unpaidInvoicesPercent: data.kpis.unpaidInvoicesPercent || '12.4% tổng hóa đơn',
            activeTickets: data.kpis.activeTickets ?? 24,
            overdueTickets: data.kpis.overdueTickets || '8 ticket quá hạn',
            amenityBookings: data.kpis.amenityBookings ?? 32,
            amenityFreeSlots: data.kpis.amenityFreeSlots || '8 khung giờ còn trống',
          });
        }
        if (data.revenueData?.length) {
          setRevenueData(data.revenueData);
        }
        if (data.revenueSummary) {
          setRevenueSummary(data.revenueSummary);
        }
        if (data.tickets?.length) {
          setTickets(data.tickets);
        }
        if (data.activities?.length) {
          setActivities(data.activities);
        }
        if (data.notifications?.length) {
          setNotifications(data.notifications);
        }
        try {
          sessionStorage.setItem('smartcassavas_dashboard_cache', JSON.stringify(data));
        } catch {
          // ignore
        }
        if (isManualRefresh) {
          showToast('Đồng bộ dữ liệu từ Database Docker thành công!');
        }
      }
    } catch {
      // Keep existing data gracefully
    }
  };

  useEffect(() => {
    fetchDashboardData();
  }, []);

  // Lắng nghe sự kiện đồng bộ từ module Tiện ích để làm mới số liệu Dashboard
  useEffect(() => {
    const unsubscribe = amenityCache.subscribe((event) => {
      if (
        ['AMENITY_BOOKING_CHANGED', 'AMENITY_CREATED', 'AMENITY_DELETED'].includes(
          event.type
        )
      ) {
        fetchDashboardData();
      }
    });
    return () => {
      unsubscribe();
    };
  }, []);

  useEffect(() => {
    if (initialTab && initialTab !== activeMenuId) {
      setActiveMenuId(initialTab);
    }
  }, [initialTab]);

  useEffect(() => {
    const handlePopState = () => {
      setActiveMenuId(resolveInitialTab());
    };
    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, []);

  const handleMenuClick = (id: string) => {
    setActiveMenuId(id);
    setMobileSidebarOpen(false);

    // Lưu vào sessionStorage để trong phiên làm việc (ở yên chỗ đó khi chuyển đổi/F5)
    // Khi khởi động lại hoặc đăng xuất (out khỏi) sẽ tự động quay về Tổng quan
    try {
      sessionStorage.setItem('smartcassavas_active_admin_tab', id);
      localStorage.removeItem('smartcassavas_active_admin_tab');
    } catch {
      // ignore
    }

    // Cập nhật URL trên thanh địa chỉ (Deep Linking & History API)
    if (id === 'amenities') {
      window.history.pushState({ tab: id }, '', '/admin/amenities');
    } else if (id === 'overview') {
      window.history.pushState({ tab: id }, '', '/admin');
    } else {
      window.history.pushState({ tab: id }, '', `/admin?tab=${id}`);
    }
    if (id === 'buildings') {
      setIs3DModelOpen(true);
    } else if (id !== 'overview') {
      showToast(`Chuyển đến phân hệ: ${menuItems.find((m) => m.id === id)?.label}`);
    }
  };

  const handleExport = (format: string) => {
    setIsExportModalOpen(false);
    showToast(`Đang kết xuất báo cáo định dạng ${format.toUpperCase()}...`);
    setTimeout(() => {
      showToast(`Tải xuống báo cáo vận hành_${new Date().toISOString().slice(0, 10)}.${format} thành công!`);
    }, 1200);
  };

  return (
    <AppLayout
      role={(userRole as any) || 'manager'}
      userRole={(userRole as any) || 'manager'}
      activeItemId={activeMenuId}
      onItemClick={handleMenuClick}
      customItems={menuItems}
      userName={userName}
      userEmail={userEmail}
      onLogout={onLogout}
      onNavigateHome={onNavigateHome}
      onNotificationClick={() => setIsNotificationOpen(!isNotificationOpen)}
      onHelpClick={() => setIsHelpOpen(!isHelpOpen)}
      unreadNotificationCount={notifications.filter((n) => !n.isRead).length}
      extraTopbarActions={
        <>
          {/* Quick 3D Building Toggle */}
          <button
            type="button"
            onClick={() => setIs3DModelOpen(true)}
            className="hidden xl:flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/80 hover:bg-white text-xs font-semibold text-sky-700 border border-sky-100 shadow-xs hover:shadow-sm transition-all cursor-pointer"
            title="Xem mô hình 3D tòa nhà"
          >
            <Layers className="w-3.5 h-3.5 text-sky-500 animate-pulse-subtle" />
            <span>Mô hình 3D</span>
          </button>

          {/* Building Selector Dropdown */}
          <div className="relative">
            <button
              type="button"
              onClick={() => setIsBuildingDropdownOpen(!isBuildingDropdownOpen)}
              className="flex items-center gap-1.5 sm:gap-2 px-2 sm:px-3 py-1.5 sm:py-2 rounded-xl bg-white/80 hover:bg-white text-xs font-semibold text-neutral-800 border border-slate-200/80 shadow-xs hover:shadow-sm transition-all cursor-pointer"
            >
              <Building className="w-3.5 h-3.5 text-sky-600 shrink-0" />
              <span className="max-w-[80px] sm:max-w-[140px] md:max-w-none truncate">{selectedBuilding}</span>
              <ChevronDown
                className={`w-3.5 h-3.5 text-slate-400 transition-transform shrink-0 ${
                  isBuildingDropdownOpen ? 'rotate-180' : ''
                }`}
              />
            </button>

            {isBuildingDropdownOpen && (
              <>
                <div
                  onClick={() => setIsBuildingDropdownOpen(false)}
                  className="fixed inset-0 z-40"
                />
                <div className="absolute right-0 mt-2 w-64 rounded-2xl bg-white/95 backdrop-blur-2xl border border-white/80 shadow-2xl p-2 z-50 animate-in fade-in slide-in-from-top-2 duration-200">
                  <div className="text-[10px] uppercase font-bold text-slate-400 px-3 py-1.5 font-mono">
                    Chọn phạm vi quản lý
                  </div>
                  {['Khu A - Tất cả tòa nhà', 'Khu B - Tháp Ruby', 'Khu C - Tháp Sapphire', 'Toàn bộ khu đô thị'].map(
                    (bldg) => (
                      <button
                        key={bldg}
                        type="button"
                        onClick={() => {
                          setSelectedBuilding(bldg);
                          setIsBuildingDropdownOpen(false);
                          showToast(`Đã chuyển bộ lọc: ${bldg}`);
                        }}
                        className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-colors cursor-pointer ${
                          selectedBuilding === bldg
                            ? 'bg-neutral-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100'
                        }`}
                      >
                        <span>{bldg}</span>
                        {selectedBuilding === bldg && <Check className="w-3.5 h-3.5 text-sky-400" />}
                      </button>
                    )
                  )}
                </div>
              </>
            )}
          </div>
        </>
      }
    >
      {/* Notifications Dropdown Panel */}
      {isNotificationOpen && (
        <div className="fixed top-20 right-4 sm:right-10 w-80 sm:w-96 rounded-3xl bg-white/95 backdrop-blur-2xl border border-slate-200/90 shadow-2xl p-4 z-50 animate-in fade-in slide-in-from-top-2 duration-200">
          <div className="flex items-center justify-between pb-3 border-b border-slate-100">
            <div className="flex items-center gap-2">
              <span className="font-bold text-xs text-neutral-900">Thông báo vận hành</span>
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
          <div className="relative mt-2">
            <div className="space-y-2 max-h-[260px] overflow-y-auto custom-scrollbar pr-1 pb-7">
              {notifications.map((notif) => {
                const isError = notif.category === 'TICKET' || notif.title.includes('Sự cố');
                const isWarning = notif.category === 'BILLING' || notif.title.includes('quá hạn');
                const isSuccess = notif.category === 'IOT' || notif.title.includes('Đồng bộ');
                const isInfo = notif.category === 'MAINTENANCE' || notif.title.includes('bảo trì');

                const badgeColor = isError
                  ? 'text-rose-600'
                  : isWarning
                  ? 'text-amber-600'
                  : isSuccess
                  ? 'text-emerald-600'
                  : isInfo
                  ? 'text-sky-600'
                  : 'text-purple-600';

                return (
                  <div
                    key={notif.id}
                    className="p-2.5 rounded-xl bg-slate-50/90 hover:bg-slate-100/90 transition-colors border border-slate-100 text-left"
                  >
                    <div className="flex items-center justify-between text-[11px] text-slate-400">
                      <span className={`font-semibold ${badgeColor}`}>{notif.title}</span>
                      <span>{notif.timeAgo || 'Vừa xong'}</span>
                    </div>
                    <p className="text-xs text-slate-700 mt-0.5">{notif.message}</p>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      )}
          {activeMenuId === 'amenities' ? (
            <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 transition-all duration-300 ease-in-out">
              <AmenityManagement embedded={true} />
            </div>
          ) : activeMenuId === 'roles' || activeMenuId === 'rbac' ? (
            <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 transition-all duration-300 ease-in-out">
              <RbacManagement embedded={true} />
            </div>
          ) : (
            <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 transition-all duration-300 ease-in-out">
          {/* Top Title & Subtitle + Export Report Button */}
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2">
            <div>
              <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono">
                <LayoutDashboard className={`w-3.5 h-3.5 ${userRole === 'admin' ? 'text-amber-500' : 'text-sky-500'}`} />
                <span className={userRole === 'admin' ? 'text-amber-700 font-extrabold' : 'text-slate-500'}>
                  {userRole === 'admin' ? 'TRUNG TÂM QUẢN TRỊ ADMIN' : 'BAN QUẢN LÝ TÒA NHÀ'}
                </span>
                <span className="text-slate-300">·</span>
                <span className={`text-[10px] px-2 py-0.5 rounded-md font-extrabold border ${
                  userRole === 'admin'
                    ? 'bg-amber-100/90 text-amber-900 border-amber-300'
                    : 'bg-sky-100/90 text-sky-900 border-sky-300'
                }`}>
                  {userRole === 'admin' ? 'TOÀN QUYỀN HỆ THỐNG & CHUYỂN CỔNG' : 'VẬN HÀNH & BẢO TRÌ'}
                </span>
              </div>
              <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1 animate-text-light-brand">
                {userRole === 'admin' ? 'Bàn làm việc Quản trị viên (Admin)' : 'Bức tranh vận hành hôm nay'}
              </h1>
              <p className="text-xs sm:text-sm text-slate-500 mt-1">
                {userRole === 'admin'
                  ? 'Toàn quyền cấu hình tham số, phân quyền tài khoản và chuyển đổi linh hoạt giữa các phân hệ của tòa nhà.'
                  : 'Theo dõi nhanh tình hình cư dân, doanh thu, yêu cầu hỗ trợ và hoạt động vận hành trong toàn tòa nhà.'}
              </p>
            </div>

            <div className="flex items-center gap-2.5">
              <button
                onClick={() => setIsExportModalOpen(true)}
                className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white text-xs sm:text-sm font-semibold shadow-lg shadow-neutral-900/20 hover:shadow-xl hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 group cursor-pointer"
              >
                <Download className="w-4 h-4 text-sky-400 group-hover:translate-y-0.5 transition-transform" />
                <span>Xuất báo cáo</span>
              </button>
            </div>
          </div>

          {/* ================= 4 KPI STATS CARDS ================= */}
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 sm:gap-5 lg:gap-6 transition-all duration-300">
            {/* Card 1: Tổng cư dân */}
            <div className="bg-white/70 backdrop-blur-xl border border-white/70 rounded-2xl p-5 shadow-lg shadow-slate-200/40 hover:shadow-2xl hover:border-sky-300/40 hover:-translate-y-1 transition-all duration-300 relative group overflow-hidden glass-specular-edge">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-slate-500">Tổng cư dân</span>
                <div className="w-8 h-8 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600 group-hover:scale-110 transition-transform">
                  <Users className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-3">
                <span className="text-3xl font-extrabold text-neutral-900 tracking-tight font-mono">{kpis.totalResidents}</span>
              </div>
              <div className="mt-3 flex items-center gap-1.5 text-xs text-emerald-600 font-medium">
                <TrendingUp className="w-3.5 h-3.5" />
                <span>{kpis.residentsGrowth}</span>
              </div>
              <div className="absolute bottom-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-emerald-400 to-transparent opacity-0 group-hover:opacity-100 transition-opacity" />
            </div>

            {/* Card 2: Hóa đơn chưa thu */}
            <div className="bg-white/70 backdrop-blur-xl border border-white/70 rounded-2xl p-5 shadow-lg shadow-slate-200/40 hover:shadow-2xl hover:border-amber-300/40 hover:-translate-y-1 transition-all duration-300 relative group overflow-hidden glass-specular-edge">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-slate-500">Hóa đơn chưa thu</span>
                <div className="w-8 h-8 rounded-xl bg-amber-50 border border-amber-100 flex items-center justify-center text-amber-600 group-hover:scale-110 transition-transform">
                  <Receipt className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-3">
                <span className="text-3xl font-extrabold text-neutral-900 tracking-tight font-mono">{kpis.unpaidInvoices}</span>
              </div>
              <div className="mt-3 flex items-center gap-1.5 text-xs text-amber-700 font-medium">
                <AlertCircle className="w-3.5 h-3.5 text-amber-500" />
                <span>{kpis.unpaidInvoicesPercent}</span>
              </div>
              <div className="absolute bottom-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-amber-400 to-transparent opacity-0 group-hover:opacity-100 transition-opacity" />
            </div>

            {/* Card 3: Ticket đang xử lý */}
            <div className="bg-white/70 backdrop-blur-xl border border-white/70 rounded-2xl p-5 shadow-lg shadow-slate-200/40 hover:shadow-2xl hover:border-rose-300/40 hover:-translate-y-1 transition-all duration-300 relative group overflow-hidden glass-specular-edge">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-slate-500">Ticket đang xử lý</span>
                <div className="w-8 h-8 rounded-xl bg-rose-50 border border-rose-100 flex items-center justify-center text-rose-600 group-hover:scale-110 transition-transform">
                  <Wrench className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-3">
                <span className="text-3xl font-extrabold text-neutral-900 tracking-tight font-mono">{kpis.activeTickets}</span>
              </div>
              <div className="mt-3 flex items-center gap-1.5 text-xs text-rose-600 font-medium">
                <span className="w-2 h-2 rounded-full bg-rose-500 animate-ping" />
                <span>{kpis.overdueTickets}</span>
              </div>
              <div className="absolute bottom-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-rose-400 to-transparent opacity-0 group-hover:opacity-100 transition-opacity" />
            </div>

            {/* Card 4: Lịch tiện ích hôm nay */}
            <div className="bg-white/70 backdrop-blur-xl border border-white/70 rounded-2xl p-5 shadow-lg shadow-slate-200/40 hover:shadow-2xl hover:border-sky-300/40 hover:-translate-y-1 transition-all duration-300 relative group overflow-hidden glass-specular-edge">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-slate-500">Lịch tiện ích hôm nay</span>
                <div className="w-8 h-8 rounded-xl bg-sky-50 border border-sky-100 flex items-center justify-center text-sky-600 group-hover:scale-110 transition-transform">
                  <Sparkles className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-3">
                <span className="text-3xl font-extrabold text-neutral-900 tracking-tight font-mono">{kpis.amenityBookings}</span>
              </div>
              <div className="mt-3 flex items-center gap-1.5 text-xs text-sky-600 font-medium">
                <Calendar className="w-3.5 h-3.5" />
                <span>{kpis.amenityFreeSlots}</span>
              </div>
              <div className="absolute bottom-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-sky-400 to-transparent opacity-0 group-hover:opacity-100 transition-opacity" />
            </div>
          </div>

          {/* ================= MIDDLE SECTION: 2 COLUMNS ================= */}
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
            {/* COLUMN 1: DOANH THU THEO THÁNG (Approx 65% width) */}
            <div className="lg:col-span-8 bg-white/75 backdrop-blur-2xl border border-white/75 rounded-2xl p-6 shadow-xl shadow-slate-200/50 relative overflow-hidden glass-specular-edge">
              {/* Header */}
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
                <div>
                  <h2 className="text-base font-bold text-neutral-900 flex items-center gap-2">
                    <span>Doanh thu theo tháng</span>
                    <span className="text-[10px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-800 font-semibold font-mono">
                      {revenueSummary.growthYoY} YoY
                    </span>
                  </h2>
                  <p className="text-xs text-slate-500 mt-0.5">{revenueSummary.growthNote}</p>
                </div>

                {/* Filter tabs */}
                <div className="flex items-center gap-1 p-1 bg-slate-100/80 rounded-xl">
                  {(['revenue', 'collection', 'debt'] as const).map((filter) => (
                    <button
                      key={filter}
                      onClick={() => setChartFilter(filter)}
                      className={`px-3 py-1 rounded-lg text-xs font-medium transition-all ${
                        chartFilter === filter
                          ? 'bg-white text-neutral-900 shadow-xs font-semibold'
                          : 'text-slate-500 hover:text-neutral-800'
                      }`}
                    >
                      {filter === 'revenue' ? 'Tổng thu' : filter === 'collection' ? 'Đã thu' : 'Tồn đọng'}
                    </button>
                  ))}
                </div>
              </div>

              {/* Chart Body */}
              <div className="pt-6">
                {/* Stats summary row */}
                <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
                  <div className="flex items-center gap-6">
                    <div>
                      <div className="text-[11px] text-slate-400">Tổng thu 6 tháng</div>
                      <div className="text-lg font-extrabold text-neutral-900 font-mono">
                        {revenueSummary.totalRevenueBillion}
                      </div>
                    </div>
                    <div className="hidden sm:block w-px h-8 bg-slate-200" />
                    <div>
                      <div className="text-[11px] text-slate-400">Tỷ lệ thu đúng hạn</div>
                      <div className="text-lg font-extrabold text-emerald-600 font-mono">
                        {revenueSummary.collectionRate}
                      </div>
                    </div>
                    <div className="hidden sm:block w-px h-8 bg-slate-200" />
                    <div>
                      <div className="text-[11px] text-slate-400">{revenueSummary.latestMonthLabel}</div>
                      <div className="text-lg font-extrabold text-sky-600 font-mono">
                        {revenueSummary.latestTargetAchieved}
                      </div>
                    </div>
                  </div>

                  {/* Legend */}
                  <div className="flex items-center gap-3 text-xs text-slate-500">
                    <span className="flex items-center gap-1.5">
                      <span className="w-2.5 h-2.5 rounded-full bg-sky-500" />
                      Doanh thu
                    </span>
                    <span className="flex items-center gap-1.5">
                      <span className="w-2.5 h-2.5 rounded-full bg-emerald-500" />
                      Mục tiêu
                    </span>
                  </div>
                </div>

                {/* Interactive SVG / Bar Chart */}
                <div className="relative h-64 w-full flex items-end justify-between gap-2 sm:gap-4 px-2 pt-8 pb-4 border-b border-slate-200/80">
                  {/* Background grid lines */}
                  <div className="absolute inset-0 flex flex-col justify-between pointer-events-none opacity-30">
                    <div className="border-b border-dashed border-slate-300 w-full" />
                    <div className="border-b border-dashed border-slate-300 w-full" />
                    <div className="border-b border-dashed border-slate-300 w-full" />
                    <div className="border-b border-dashed border-slate-300 w-full" />
                  </div>

                  {/* SVG Spline Trendline Overlay */}
                  <svg className="absolute inset-0 w-full h-full pointer-events-none" preserveAspectRatio="none" viewBox="0 0 665 240">
                    <defs>
                      <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#0284c7" stopOpacity="0.25" />
                        <stop offset="100%" stopColor="#0284c7" stopOpacity="0" />
                      </linearGradient>
                    </defs>
                    <path
                      d="M 25 180 Q 90 160, 160 145 T 320 120 T 480 90 T 640 45 L 640 240 L 25 240 Z"
                      fill="url(#chartGradient)"
                    />
                    <path
                      d="M 25 180 Q 90 160, 160 145 T 320 120 T 480 90 T 640 45"
                      fill="none"
                      stroke="#0284c7"
                      strokeWidth="2.5"
                      strokeDasharray="4 2"
                    />
                  </svg>

                  {/* Bars for T3 to T8 */}
                  {revenueData.map((item, index) => {
                    const value =
                      chartFilter === 'revenue'
                        ? item.revenue
                        : chartFilter === 'collection'
                        ? item.collection
                        : item.debt;

                    const maxScale = Math.max(
                      ...revenueData.map((d) =>
                        chartFilter === 'revenue' ? d.revenue : chartFilter === 'collection' ? d.collection : d.debt
                      ),
                      1
                    );
                    const heightPercent = Math.min(Math.round((value / (maxScale * 1.15)) * 100), 100);
                    const isHovered = chartActiveBar === index;

                    return (
                      <div
                        key={item.month}
                        onMouseEnter={() => setChartActiveBar(index)}
                        className="flex-1 flex flex-col items-center justify-end h-full relative group cursor-pointer z-10"
                      >
                        {/* Tooltip on active bar */}
                        {isHovered && (
                          <div className="absolute -top-14 bg-neutral-900 text-white text-[11px] font-mono px-2.5 py-1.5 rounded-lg shadow-xl whitespace-nowrap pointer-events-none animate-in fade-in zoom-in-95 duration-150 z-20">
                            <span className="text-sky-400 font-bold">{item.month}: </span>
                            {chartFilter === 'revenue'
                              ? `${item.revenue.toLocaleString('vi-VN')} tr (${item.label})`
                              : chartFilter === 'collection'
                              ? `${item.collection.toLocaleString('vi-VN')} tr đã thu`
                              : `${item.debt.toLocaleString('vi-VN')} tr tồn đọng`}
                            <div className="text-[9px] text-slate-300">
                              Đạt {item.targetAchievedPercent ?? Math.round((item.revenue / (item.target || 1)) * 100)}% chỉ tiêu
                            </div>
                            <div className="absolute left-1/2 -bottom-1 -translate-x-1/2 w-2 h-2 bg-neutral-900 rotate-45" />
                          </div>
                        )}

                        {/* Bar Pillar */}
                        <div className={`w-full ${isSidebarCollapsed ? 'max-w-[56px] xl:max-w-[64px]' : 'max-w-[44px] sm:max-w-[50px]'} h-full flex items-end justify-center transition-all duration-300`}>
                          <div
                            style={{ height: `${heightPercent}%` }}
                            className={`w-full rounded-t-xl transition-all duration-300 relative ${
                              isHovered
                                ? 'bg-gradient-to-t from-sky-600 via-sky-500 to-sky-400 shadow-lg shadow-sky-500/30 scale-105'
                                : 'bg-gradient-to-t from-slate-300 via-slate-200 to-sky-200 hover:from-sky-500 hover:to-sky-400'
                            }`}
                          >
                            {/* Shiny Top highlight */}
                            <div className="absolute top-0 left-0 right-0 h-1 rounded-t-xl bg-white/60" />
                          </div>
                        </div>

                        {/* Month Label */}
                        <span
                          className={`mt-2 text-xs font-semibold font-mono transition-colors ${
                            isHovered ? 'text-sky-600 scale-110' : 'text-slate-400'
                          }`}
                        >
                          {item.month}
                        </span>
                      </div>
                    );
                  })}
                </div>

                <div className="mt-4 flex items-center justify-between text-[11px] text-slate-400">
                  <span>* Đơn vị tính: Tỷ Việt Nam Đồng (VND). Tự động tổng hợp từ ngân hàng & ví điện tử.</span>
                  <button
                    onClick={() => fetchDashboardData(true)}
                    className="flex items-center gap-1 text-sky-600 hover:underline font-medium cursor-pointer"
                  >
                    <RefreshCw className="w-3 h-3" />
                    Làm mới
                  </button>
                </div>
              </div>
            </div>

            {/* COLUMN 2: TICKET GẦN ĐÂY (Approx 35% width) */}
            <div className="lg:col-span-4 bg-white/75 backdrop-blur-2xl border border-white/75 rounded-2xl p-6 shadow-xl shadow-slate-200/50 relative overflow-hidden glass-specular-edge flex flex-col justify-between">
              <div>
                {/* Header */}
                <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                  <div>
                    <h2 className="text-base font-bold text-neutral-900 flex items-center gap-2">
                      <span>Ticket gần đây</span>
                    </h2>
                    <p className="text-xs text-slate-500 mt-0.5">Các yêu cầu cần được chú ý</p>
                  </div>
                  <button
                    onClick={() => handleMenuClick('tickets')}
                    className="text-xs text-sky-600 hover:text-sky-700 font-semibold flex items-center gap-1 hover:underline"
                  >
                    <span>Xem tất cả</span>
                    <ChevronRight className="w-3.5 h-3.5" />
                  </button>
                </div>

                {/* Ticket List matching user image */}
                <div className="mt-4 space-y-3">
                  {tickets.map((ticket) => (
                    <div
                      key={ticket.id}
                      onClick={() => setSelectedTicket(ticket)}
                      className="p-3.5 rounded-xl bg-white/90 hover:bg-white border border-slate-200/70 hover:border-sky-300/60 shadow-xs hover:shadow-md transition-all duration-200 cursor-pointer group relative"
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <h3 className="text-xs sm:text-sm font-bold text-neutral-900 truncate group-hover:text-sky-600 transition-colors">
                            {ticket.title}
                          </h3>
                          <p className="text-[11px] text-slate-400 mt-1 flex items-center gap-1.5">
                            <span>{ticket.location}</span>
                          </p>
                        </div>

                        {/* Status badge */}
                        <span
                          className={`text-[10px] uppercase tracking-wider font-extrabold px-2.5 py-1 rounded-md shrink-0 ${
                            ticket.status === 'new'
                              ? 'bg-sky-100 text-sky-800 border border-sky-200 animate-pulse-subtle'
                              : ticket.status === 'processing'
                              ? 'bg-amber-100 text-amber-800 border border-amber-200'
                              : ticket.status === 'received'
                              ? 'bg-purple-100 text-purple-800 border border-purple-200'
                              : 'bg-emerald-100 text-emerald-800 border border-emerald-200'
                          }`}
                        >
                          {ticket.statusText}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              {/* Bottom Quick Action */}
              <div className="pt-4 mt-4 border-t border-slate-100">
                <button
                  onClick={() => showToast('Mở biểu mẫu tiếp nhận yêu cầu hỗ trợ mới')}
                  className="w-full py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200/80 text-xs font-semibold text-slate-700 flex items-center justify-center gap-1.5 transition-colors"
                >
                  <Wrench className="w-3.5 h-3.5 text-slate-500" />
                  <span>+ Tiếp nhận ticket mới</span>
                </button>
              </div>
            </div>
          </div>

          {/* ================= BOTTOM SECTION: HOẠT ĐỘNG GẦN ĐÂY (TABLE) ================= */}
          <div className="bg-white/75 backdrop-blur-2xl border border-white/75 rounded-2xl p-6 shadow-xl shadow-slate-200/50 relative overflow-hidden glass-specular-edge">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
              <div>
                <h2 className="text-base font-bold text-neutral-900 flex items-center gap-2">
                  <span>Hoạt động gần đây</span>
                </h2>
                <p className="text-xs text-slate-500 mt-0.5">Tổng hợp nhanh từ các phân hệ</p>
              </div>

              <div className="flex items-center gap-2">
                <button
                  onClick={() => fetchDashboardData(true)}
                  className="p-2 rounded-xl bg-white hover:bg-slate-50 text-slate-500 hover:text-slate-800 border border-slate-200/80 shadow-xs transition-all cursor-pointer"
                  title="Tải lại bảng từ database"
                >
                  <RefreshCw className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>

            {/* Table Container */}
            <div className="overflow-x-auto mt-4">
              <table className="w-full text-left border-collapse">
                <thead>
                  <tr className="border-b border-slate-200/70 text-[11px] font-bold uppercase text-slate-400 font-mono tracking-wider">
                    <th className="py-3 px-4">HẠNG MỤC</th>
                    <th className="py-3 px-4">GIÁ TRỊ</th>
                    <th className="py-3 px-4">TRẠNG THÁI</th>
                    <th className="py-3 px-4 hidden sm:table-cell">CẬP NHẬT</th>
                    <th className="py-3 px-4 text-right">THAO TÁC</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-xs">
                  {activities.map((item) => (
                    <tr
                      key={item.id}
                      className="hover:bg-white/80 transition-colors group"
                    >
                      {/* Hạng mục */}
                      <td className="py-3.5 px-4 font-semibold text-neutral-900 flex items-center gap-2.5">
                        <div className="w-7 h-7 rounded-lg bg-slate-100 group-hover:bg-sky-50 flex items-center justify-center text-slate-600 group-hover:text-sky-600 transition-colors">
                          <Building2 className="w-3.5 h-3.5" />
                        </div>
                        <span>{item.category}</span>
                      </td>

                      {/* Giá trị */}
                      <td className="py-3.5 px-4 font-mono text-slate-700">
                        <div className="flex items-center gap-2">
                          <span>{item.value}</span>
                          {item.percentage && (
                            <div className="w-16 bg-slate-100 rounded-full h-1.5 overflow-hidden hidden md:block">
                              <div
                                className={`h-full rounded-full ${
                                  item.percentage > 90 ? 'bg-emerald-500' : 'bg-amber-500'
                                }`}
                                style={{ width: `${item.percentage}%` }}
                              />
                            </div>
                          )}
                        </div>
                      </td>

                      {/* Trạng thái badge */}
                      <td className="py-3.5 px-4">
                        <span
                          className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold ${
                            item.status === 'stable' || item.status === 'normal'
                              ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/60'
                              : item.status === 'passed'
                              ? 'bg-sky-50 text-sky-700 border border-sky-200/60'
                              : item.status === 'done'
                              ? 'bg-indigo-50 text-indigo-700 border border-indigo-200/60'
                              : 'bg-amber-50 text-amber-700 border border-amber-200/60'
                          }`}
                        >
                          <span
                            className={`w-1.5 h-1.5 rounded-full ${
                              item.status === 'stable' || item.status === 'normal'
                                ? 'bg-emerald-500'
                                : item.status === 'passed'
                                ? 'bg-sky-500'
                                : item.status === 'done'
                                ? 'bg-indigo-500'
                                : 'bg-amber-500'
                            }`}
                          />
                          {item.statusText}
                        </span>
                      </td>

                      {/* Cập nhật */}
                      <td className="py-3.5 px-4 text-slate-400 text-[11px] hidden sm:table-cell">
                        {item.updatedAt}
                      </td>

                      {/* Thao tác */}
                      <td className="py-3.5 px-4 text-right">
                        <button
                          onClick={() => showToast(`Xem chi tiết: ${item.category}`)}
                          className="px-2.5 py-1 rounded-lg hover:bg-slate-100 text-sky-600 hover:text-sky-700 font-medium text-[11px] transition-colors"
                        >
                          Chi tiết
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL: XUẤT BÁO CÁO (EXPORT REPORT)
          ======================================================== */}
      {isExportModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="w-full max-w-md rounded-3xl bg-white/95 backdrop-blur-2xl border border-white/80 shadow-2xl p-6 relative">
            <div className="flex items-center justify-between pb-4 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-neutral-900 text-sky-400 flex items-center justify-center shadow-md">
                  <FileSpreadsheet className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="font-bold text-sm text-neutral-900">Xuất báo cáo vận hành</h3>
                  <p className="text-[11px] text-slate-400">Chọn định dạng file kết xuất</p>
                </div>
              </div>
              <button
                onClick={() => setIsExportModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="mt-4 space-y-2.5">
              <button
                onClick={() => handleExport('xlsx')}
                className="w-full flex items-center justify-between p-3.5 rounded-2xl bg-emerald-50/60 hover:bg-emerald-50 border border-emerald-100 transition-all text-left group"
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-emerald-600 text-white flex items-center justify-center font-bold text-xs">
                    XLS
                  </div>
                  <div>
                    <div className="text-xs font-bold text-emerald-950">Microsoft Excel (.xlsx)</div>
                    <div className="text-[11px] text-emerald-700">Đầy đủ bảng tính, công thức doanh thu & cư dân</div>
                  </div>
                </div>
                <Download className="w-4 h-4 text-emerald-600 group-hover:translate-y-0.5 transition-transform" />
              </button>

              <button
                onClick={() => handleExport('pdf')}
                className="w-full flex items-center justify-between p-3.5 rounded-2xl bg-rose-50/60 hover:bg-rose-50 border border-rose-100 transition-all text-left group"
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-rose-600 text-white flex items-center justify-center font-bold text-xs">
                    PDF
                  </div>
                  <div>
                    <div className="text-xs font-bold text-rose-950">Tài liệu chuẩn in (.pdf)</div>
                    <div className="text-[11px] text-rose-700">Bản in đóng dấu chữ ký số của Ban quản lý</div>
                  </div>
                </div>
                <Download className="w-4 h-4 text-rose-600 group-hover:translate-y-0.5 transition-transform" />
              </button>

              <button
                onClick={() => handleExport('csv')}
                className="w-full flex items-center justify-between p-3.5 rounded-2xl bg-sky-50/60 hover:bg-sky-50 border border-sky-100 transition-all text-left group"
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-sky-600 text-white flex items-center justify-center font-bold text-xs">
                    CSV
                  </div>
                  <div>
                    <div className="text-xs font-bold text-sky-950">Dữ liệu thô (.csv)</div>
                    <div className="text-[11px] text-sky-700">Tối ưu để import vào hệ thống ERP / Kế toán</div>
                  </div>
                </div>
                <Download className="w-4 h-4 text-sky-600 group-hover:translate-y-0.5 transition-transform" />
              </button>
            </div>

            <div className="mt-5 flex justify-end">
              <button
                onClick={() => setIsExportModalOpen(false)}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-colors"
              >
                Hủy bỏ
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          MODAL: CHI TIẾT TICKET POPUP
          ======================================================== */}
      {selectedTicket && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="w-full max-w-md rounded-3xl bg-white/95 backdrop-blur-2xl border border-white/80 shadow-2xl p-6 relative">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <span className="font-mono text-xs font-bold text-sky-600">{selectedTicket.id}</span>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded-md ${
                    selectedTicket.status === 'new'
                      ? 'bg-sky-100 text-sky-800'
                      : 'bg-amber-100 text-amber-800'
                  }`}
                >
                  {selectedTicket.statusText}
                </span>
              </div>
              <button
                onClick={() => setSelectedTicket(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="mt-4 space-y-3">
              <h4 className="font-bold text-base text-neutral-900">{selectedTicket.title}</h4>
              <p className="text-xs text-slate-500">{selectedTicket.location}</p>

              <div className="p-3 rounded-xl bg-slate-50 border border-slate-100 text-xs text-slate-600 space-y-1.5">
                <div><span className="font-semibold text-slate-800">Người gửi:</span> {selectedTicket.creatorName || 'Cư dân'}</div>
                <div><span className="font-semibold text-slate-800">Số điện thoại:</span> {selectedTicket.creatorPhone || '090 123 4567'}</div>
                <div><span className="font-semibold text-slate-800">Mức độ ưu tiên:</span> {selectedTicket.priority.toUpperCase()}</div>
                <div><span className="font-semibold text-slate-800">Mô tả / Ghi chú:</span> {selectedTicket.description || 'Đang chờ bộ phận kỹ thuật tiếp nhận.'}</div>
              </div>
            </div>

            <div className="mt-5 flex items-center justify-end gap-2">
              <button
                onClick={() => setSelectedTicket(null)}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-colors"
              >
                Đóng
              </button>
              <button
                onClick={() => {
                  showToast(`Đã nhận xử lý ticket ${selectedTicket.id}`);
                  setSelectedTicket(null);
                }}
                className="px-4 py-2 rounded-xl text-xs font-semibold bg-neutral-900 hover:bg-neutral-800 text-white shadow-md transition-all"
              >
                Nhận phân công
              </button>
            </div>
          </div>
        </div>
      )}

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
                  <p className="text-[11px] text-slate-400">Khối / Tòa nhà & Căn hộ trực quan</p>
                </div>
              </div>
              <button
                onClick={() => setIs3DModelOpen(false)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100"
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

      {/* ================= TOAST NOTIFICATION ================= */}
      {toastMessage && (
        <div className="fixed bottom-6 right-6 z-50 flex items-center gap-2.5 px-4 py-3 rounded-2xl bg-neutral-900/90 text-white text-xs font-medium shadow-2xl backdrop-blur-md border border-white/10 animate-in fade-in slide-in-from-bottom-3 duration-200">
          <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
          <span>{toastMessage}</span>
        </div>
      )}
    </AppLayout>
  );
};

export default ManagementHome;
