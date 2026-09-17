import React, { useState, useEffect } from 'react';
import {
  Building2,
  Home,
  Receipt,
  AlertCircle,
  Sparkles,
  Users,
  User,
  MessageSquare,
  HelpCircle,
  PhoneCall,
  LogOut,
  ChevronDown,
  Bell,
  FileText,
  Building,
  ArrowUpRight,
  Clock,
  CheckCircle2,
  Maximize2,
  Minimize2,
} from 'lucide-react';
import { api } from '../Services/api';
import { AppLayout } from '../Components/Layout/AppLayout';

export interface ResidentHomeProps {
  onLogout?: () => void;
  onNavigateHome?: () => void;
  onNavigateAdmin?: () => void;
  userRole?: string;
  userName?: string;
  userEmail?: string;
}

interface MenuItem {
  id: string;
  label: string;
  icon: React.ElementType;
  badge?: string;
}

export const ResidentHome: React.FC<ResidentHomeProps> = ({
  onLogout,
  onNavigateHome,
  userName = 'Nguyễn Văn A',
  userEmail = 'nguyenvana@cassavas.vn',
  userRole,
}) => {
  const [activeMenuId, setActiveMenuId] = useState<string>('overview');
  const [data, setData] = useState<any>(null);
  const [mousePos, setMousePos] = useState({ x: 0, y: 0 });

  // Sidebar Collapse / Expand State
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem('smartcassavas_resident_sidebar_collapsed') === 'true';
    } catch {
      return false;
    }
  });

  const toggleSidebarCollapse = () => {
    setIsSidebarCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem('smartcassavas_resident_sidebar_collapsed', String(next));
      } catch {
        // ignore
      }
      return next;
    });
  };

  // Fullscreen State & Handler
  const [isFullscreen, setIsFullscreen] = useState<boolean>(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [hoveredTooltip, setHoveredTooltip] = useState<{
    label: string;
    badge?: string | null;
    top: number;
  } | null>(null);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => {
      setToastMessage(null);
    }, 2800);
  };

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

  // Menu items matching the exact skeleton structure
  const menuItems: MenuItem[] = [
    { id: 'overview', label: 'Tổng quan Cư dân', icon: Home },
    { id: 'billing', label: 'Hóa đơn của tôi', icon: Receipt, badge: '1' },
    { id: 'tickets', label: 'Báo cáo sự cố', icon: AlertCircle, badge: '2' },
    { id: 'amenities', label: 'Đặt tiện ích', icon: Sparkles },
    { id: 'visitors', label: 'Khai báo khách', icon: Users },
    { id: 'profile', label: 'Thông tin cá nhân', icon: User },
    { id: 'community', label: 'Cộng đồng cư dân', icon: MessageSquare },
    { id: 'feedback', label: 'Góp ý & Khảo sát', icon: FileText },
    { id: 'contact_pet', label: 'Liên hệ & Thú cưng', icon: PhoneCall },
  ];

  useEffect(() => {
    const fetchData = async () => {
      try {
        const res = await api.getResidentOverview();
        setData(res);
      } catch (err) {
        console.warn('Sử dụng dữ liệu tĩnh khung xương:', err);
      }
    };
    fetchData();
  }, []);

  const userDisplayName = data?.user?.full_name || userName || 'Nguyễn Văn A';
  const userDisplayEmail = data?.user?.email || userEmail || 'nguyenvana@cassavas.vn';
  const apartmentNumber = 'A1-05';

  return (
    <AppLayout
      role="resident"
      userRole={userRole as any}
      activeItemId={activeMenuId}
      onItemClick={setActiveMenuId}
      customItems={menuItems}
      userName={userDisplayName}
      userEmail={userDisplayEmail}
      onLogout={onLogout}
      onNavigateHome={onNavigateHome}
      statusText="Cổng dịch vụ cư dân trực tuyến"
      extraTopbarActions={
        <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-neutral-200/80 text-xs font-medium text-neutral-800 bg-white/85 backdrop-blur-md shadow-xs hover:border-sky-300 transition-colors">
          <Building className="w-3.5 h-3.5 text-sky-600" />
          <span>
            Căn hộ <strong className="font-semibold text-neutral-950">{apartmentNumber}</strong>
          </span>
        </div>
      }
    >
      <div className="p-6 sm:p-8 lg:p-10">
        {activeMenuId === 'overview' ? (
          <div className="max-w-6xl mx-auto space-y-8 animate-in fade-in duration-300">
            {/* Page Title & Breadcrumb */}
            <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
              <div className="space-y-1">
                  {/* Breadcrumb */}
                  <div className="flex items-center gap-1.5 text-xs text-neutral-400 uppercase tracking-wider font-medium">
                    <Home className="w-3.5 h-3.5 text-neutral-400" />
                    <span>CỔNG CƯ DÂN</span>
                  </div>
                  {/* Title */}
                  <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight flex items-center gap-2.5">
                    Tổng quan Cư dân
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 shadow-2xs">
                      Hoạt động
                    </span>
                  </h1>
                  {/* Subtitle */}
                  <p className="text-xs sm:text-sm text-neutral-500">
                    Theo dõi hóa đơn, yêu cầu hỗ trợ và tiện ích của căn hộ.
                  </p>
                </div>

                {/* Right Top Action Button */}
                <button
                  type="button"
                  className="inline-flex items-center gap-2 px-4 py-2.5 bg-neutral-950 hover:bg-neutral-800 text-white rounded-xl text-xs font-semibold shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 active:scale-[0.98] self-start"
                >
                  <FileText className="w-3.5 h-3.5 text-neutral-300" />
                  <span>Lịch sử giao dịch</span>
                  <ArrowUpRight className="w-3.5 h-3.5 text-neutral-400" />
                </button>
              </div>

              {/* 4 Stat Cards in a Grid with Glassmorphism and Hover Micro-animations */}
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Card 1: Số dư cần thanh toán */}
                <div className="p-5 rounded-2xl border border-neutral-200/80 bg-white/80 backdrop-blur-md shadow-xs hover:shadow-lg hover:border-sky-300 hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between relative group overflow-hidden glass-specular-edge">
                  <div className="absolute top-0 right-0 w-24 h-24 bg-sky-100/30 rounded-full blur-2xl pointer-events-none -mr-8 -mt-8 group-hover:bg-sky-200/40 transition-colors" />
                  <div className="flex items-center justify-between text-xs text-neutral-500 font-medium">
                    <span>Số dư cần thanh toán</span>
                    <div className="w-7 h-7 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center shrink-0 border border-sky-100 group-hover:scale-110 transition-transform">
                      <Receipt className="w-3.5 h-3.5" />
                    </div>
                  </div>
                  <div className="my-3">
                    <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight group-hover:text-sky-700 transition-colors">
                      2.450.000đ
                    </span>
                  </div>
                  <div className="text-xs text-neutral-400 flex items-center gap-1.5">
                    <Clock className="w-3 h-3 text-amber-500" />
                    <span>Hạn thanh toán 15/09/2026</span>
                  </div>
                </div>

                {/* Card 2: Yêu cầu đang xử lý */}
                <div className="p-5 rounded-2xl border border-neutral-200/80 bg-white/80 backdrop-blur-md shadow-xs hover:shadow-lg hover:border-amber-300 hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between relative group overflow-hidden glass-specular-edge">
                  <div className="absolute top-0 right-0 w-24 h-24 bg-amber-100/30 rounded-full blur-2xl pointer-events-none -mr-8 -mt-8 group-hover:bg-amber-200/40 transition-colors" />
                  <div className="flex items-center justify-between text-xs text-neutral-500 font-medium">
                    <span>Yêu cầu đang xử lý</span>
                    <div className="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-100 group-hover:scale-110 transition-transform">
                      <AlertCircle className="w-3.5 h-3.5" />
                    </div>
                  </div>
                  <div className="my-3">
                    <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight group-hover:text-amber-700 transition-colors">
                      02
                    </span>
                  </div>
                  <div className="text-xs text-neutral-400 flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 rounded-full bg-rose-500" />
                    <span>01 yêu cầu ưu tiên cao</span>
                  </div>
                </div>

                {/* Card 3: Lịch tiện ích sắp tới */}
                <div className="p-5 rounded-2xl border border-neutral-200/80 bg-white/80 backdrop-blur-md shadow-xs hover:shadow-lg hover:border-emerald-300 hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between relative group overflow-hidden glass-specular-edge">
                  <div className="absolute top-0 right-0 w-24 h-24 bg-emerald-100/30 rounded-full blur-2xl pointer-events-none -mr-8 -mt-8 group-hover:bg-emerald-200/40 transition-colors" />
                  <div className="flex items-center justify-between text-xs text-neutral-500 font-medium">
                    <span>Lịch tiện ích sắp tới</span>
                    <div className="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 border border-emerald-100 group-hover:scale-110 transition-transform">
                      <Sparkles className="w-3.5 h-3.5" />
                    </div>
                  </div>
                  <div className="my-3">
                    <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight group-hover:text-emerald-700 transition-colors">
                      03
                    </span>
                  </div>
                  <div className="text-xs text-neutral-400 flex items-center gap-1.5">
                    <CheckCircle2 className="w-3 h-3 text-emerald-500" />
                    <span>Trong 30 ngày tới</span>
                  </div>
                </div>

                {/* Card 4: Khách đã khai báo */}
                <div className="p-5 rounded-2xl border border-neutral-200/80 bg-white/80 backdrop-blur-md shadow-xs hover:shadow-lg hover:border-indigo-300 hover:-translate-y-1 transition-all duration-300 flex flex-col justify-between relative group overflow-hidden glass-specular-edge">
                  <div className="absolute top-0 right-0 w-24 h-24 bg-indigo-100/30 rounded-full blur-2xl pointer-events-none -mr-8 -mt-8 group-hover:bg-indigo-200/40 transition-colors" />
                  <div className="flex items-center justify-between text-xs text-neutral-500 font-medium">
                    <span>Khách đã khai báo</span>
                    <div className="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0 border border-indigo-100 group-hover:scale-110 transition-transform">
                      <Users className="w-3.5 h-3.5" />
                    </div>
                  </div>
                  <div className="my-3">
                    <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight group-hover:text-indigo-700 transition-colors">
                      04
                    </span>
                  </div>
                  <div className="text-xs text-neutral-400 flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 rounded-full bg-indigo-500" />
                    <span>Trong tháng này</span>
                  </div>
                </div>
              </div>

              {/* Section: Việc cần làm (Glass container with custom scrollable table) */}
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
                      Việc cần làm
                      <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-600 border border-neutral-200/60">
                        3 mục cần chú ý
                      </span>
                    </h2>
                    <p className="text-xs text-neutral-400">Các hạng mục cần bạn theo dõi và thực hiện.</p>
                  </div>
                </div>

                {/* Table matching screenshot with glass styling and custom-scrollbar */}
                <div className="border border-neutral-200/80 bg-white/80 backdrop-blur-md rounded-2xl overflow-hidden shadow-xs glass-specular-edge">
                  <div className="overflow-x-auto custom-scrollbar">
                    <table className="w-full text-left text-xs border-collapse">
                      <thead>
                        <tr className="border-b border-neutral-200/70 bg-neutral-50/60 text-neutral-500 font-semibold uppercase text-[11px] tracking-wider">
                          <th className="py-3 px-4">HẠNG MỤC</th>
                          <th className="py-3 px-4">GIÁ TRỊ</th>
                          <th className="py-3 px-4">TRẠNG THÁI</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-neutral-200/60 text-neutral-800">
                        <tr className="hover:bg-neutral-50/80 transition-colors group cursor-pointer">
                          <td className="py-3.5 px-4 font-semibold text-neutral-900 flex items-center gap-2">
                            <span className="w-1.5 h-1.5 rounded-full bg-rose-500" />
                            Hóa đơn tháng 09/2026
                          </td>
                          <td className="py-3.5 px-4 text-neutral-600 font-mono font-medium">
                            2.450.000đ
                          </td>
                          <td className="py-3.5 px-4">
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-rose-50 text-rose-700 border border-rose-200/60">
                              Chưa thanh toán
                            </span>
                          </td>
                        </tr>
                        <tr className="hover:bg-neutral-50/80 transition-colors group cursor-pointer">
                          <td className="py-3.5 px-4 font-semibold text-neutral-900 flex items-center gap-2">
                            <span className="w-1.5 h-1.5 rounded-full bg-amber-500" />
                            Sửa vòi nước phòng tắm
                          </td>
                          <td className="py-3.5 px-4 text-neutral-600 font-mono font-medium">
                            TICKET-1024
                          </td>
                          <td className="py-3.5 px-4">
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-700 border border-amber-200/60">
                              Đang xử lý
                            </span>
                          </td>
                        </tr>
                        <tr className="hover:bg-neutral-50/80 transition-colors group cursor-pointer">
                          <td className="py-3.5 px-4 font-semibold text-neutral-900 flex items-center gap-2">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                            Đặt sân cầu lông
                          </td>
                          <td className="py-3.5 px-4 text-neutral-600 font-mono font-medium">
                            12/09 · 18:00
                          </td>
                          <td className="py-3.5 px-4">
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                              Đã xác nhận
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          ) : (
            /* Skeleton Placeholder View for Other Modules (Clean skeleton for teammates) */
            <div className="max-w-4xl mx-auto py-16 text-center space-y-5 animate-in fade-in duration-300">
              <div className="w-14 h-14 rounded-2xl bg-white/80 backdrop-blur-md border border-neutral-200/80 text-neutral-400 mx-auto flex items-center justify-center shadow-xs glass-specular-edge">
                <FileText className="w-7 h-7 text-neutral-500" />
              </div>
              <div className="space-y-1">
                <h2 className="text-xl font-bold text-neutral-900">
                  {menuItems.find((m) => m.id === activeMenuId)?.label || 'Phân hệ chức năng'}
                </h2>
                <p className="text-xs text-neutral-500 max-w-md mx-auto">
                  Khung xương giao diện sẵn sàng. Chức năng chi tiết sẽ do nhân sự được phân công nhiệm vụ triển khai tiếp theo.
                </p>
              </div>
              <div>
                <button
                  type="button"
                  onClick={() => setActiveMenuId('overview')}
                  className="px-4 py-2.5 rounded-xl bg-neutral-950 text-white text-xs font-semibold hover:bg-neutral-800 shadow-sm hover:shadow-md transition-all active:scale-[0.98]"
                >
                  Quay lại Tổng quan Cư dân
                </button>
              </div>
            </div>
          )}
      </div>

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

export default ResidentHome;

