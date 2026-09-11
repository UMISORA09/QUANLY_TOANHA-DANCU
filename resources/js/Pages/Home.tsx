import React, { useState, useEffect } from 'react';
import {
  Building2,
  Bell,
  ArrowRight,
  Mail,
  Wrench,
  ShieldCheck,
  CheckCircle2,
  Clock,
  ChevronRight,
  Sparkles,
  TrendingUp,
  Users,
  CreditCard,
  AlertCircle,
  FileText,
  X,
  Menu,
  Cloud,
  Check,
  Zap,
  Lock,
  PhoneCall
} from 'lucide-react';

type WorkspaceTab = 'overview' | 'residents' | 'billing' | 'maintenance';

interface NotificationItem {
  id: number;
  title: string;
  desc: string;
  time: string;
  type: 'billing' | 'maintenance' | 'security';
}

export const Home: React.FC = () => {
  // Navigation & Interactive states
  const [activeTab, setActiveTab] = useState<WorkspaceTab>('overview');
  const [showNotification, setShowNotification] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [authModal, setAuthModal] = useState<'login' | 'register' | null>(null);
  const [activeBarIndex, setActiveBarIndex] = useState<number | null>(null);
  const [liveUptimeSeconds, setLiveUptimeSeconds] = useState(0);

  // Dynamic bar chart data representing monthly revenue
  const monthlyData = [
    { month: 'T1', value: 45, amount: '450 tr' },
    { month: 'T2', value: 58, amount: '580 tr' },
    { month: 'T3', value: 52, amount: '520 tr' },
    { month: 'T4', value: 72, amount: '720 tr' },
    { month: 'T5', value: 68, amount: '680 tr' },
    { month: 'T6', value: 85, amount: '850 tr' },
    { month: 'T7', value: 92, amount: '920 tr' },
    { month: 'T8', value: 78, amount: '780 tr' },
    { month: 'T9', value: 98, amount: '980 tr' },
    { month: 'T10', value: 104, amount: '1.04 tỷ' },
  ];

  const notifications: NotificationItem[] = [
    {
      id: 1,
      title: 'Hóa đơn tháng 9 đã phát hành',
      desc: '248 căn hộ đã nhận thông báo qua ứng dụng cư dân.',
      time: '5 phút trước',
      type: 'billing'
    },
    {
      id: 2,
      title: 'Bảo trì thang máy số 2 hoàn tất',
      desc: 'Kỹ thuật viên đã kiểm định và đưa vào sử dụng.',
      time: '32 phút trước',
      type: 'maintenance'
    },
    {
      id: 3,
      title: 'Đăng ký khách ra vào mới',
      desc: 'Căn 12.04 đăng ký gửi xe qua đêm (29A-882.11).',
      time: '1 giờ trước',
      type: 'security'
    }
  ];

  // Simulating live system heartbeat
  useEffect(() => {
    const timer = setInterval(() => {
      setLiveUptimeSeconds((prev) => prev + 1);
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  const scrollToSection = (id: string) => {
    const el = document.getElementById(id);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth' });
      setIsMobileMenuOpen(false);
    }
  };

  return (
    <div className="min-h-screen bg-[#FAFAFA] text-[#171717] selection:bg-neutral-900 selection:text-white font-sans relative overflow-x-hidden">
      {/* Background Cloud Atmosphere & Ambient Glows */}
      <div className="fixed inset-0 pointer-events-none overflow-hidden -z-10">
        <div className="absolute -top-40 -left-40 w-[550px] h-[550px] bg-gradient-to-br from-blue-100/40 via-sky-50/30 to-transparent rounded-full blur-3xl animate-cloud-drift opacity-70" />
        <div className="absolute top-1/4 -right-48 w-[600px] h-[600px] bg-gradient-to-bl from-slate-200/40 via-neutral-100/30 to-transparent rounded-full blur-3xl animate-cloud-float opacity-60" />
        <div className="absolute bottom-10 left-1/3 w-[450px] h-[450px] bg-gradient-to-t from-sky-50/40 via-indigo-50/20 to-transparent rounded-full blur-2xl opacity-50" />

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
      <header className="sticky top-0 z-40 bg-white/80 backdrop-blur-md border-b border-neutral-200/70 transition-all">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
          {/* Logo */}
          <a href="#" className="flex items-center gap-3 group">
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

          {/* Desktop Navigation */}
          <nav className="hidden md:flex items-center gap-8">
            <a
              href="#home"
              onClick={(e) => {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
              }}
              className="text-sm font-semibold text-neutral-900 transition-colors relative py-1 after:absolute after:bottom-0 after:left-0 after:right-0 after:h-0.5 after:bg-neutral-900"
            >
              Trang chủ
            </a>
            <a
              href="#features"
              onClick={(e) => {
                e.preventDefault();
                scrollToSection('features');
              }}
              className="text-sm font-medium text-neutral-600 hover:text-neutral-950 transition-colors"
            >
              Tính năng
            </a>
            <a
              href="#contact"
              onClick={(e) => {
                e.preventDefault();
                scrollToSection('contact');
              }}
              className="text-sm font-medium text-neutral-600 hover:text-neutral-950 transition-colors"
            >
              Liên hệ
            </a>
          </nav>

          {/* Actions */}
          <div className="hidden md:flex items-center gap-4">
            <button
              onClick={() => setAuthModal('register')}
              className="text-sm font-medium text-neutral-700 hover:text-neutral-950 px-3 py-2 rounded-md transition-colors"
            >
              Đăng ký
            </button>
            <button
              onClick={() => setAuthModal('login')}
              className="text-sm font-medium text-white bg-neutral-950 hover:bg-neutral-800 active:scale-95 px-5 py-2.5 rounded-md shadow-sm transition-all flex items-center gap-2"
            >
              <span>Đăng nhập</span>
              <ChevronRight className="w-3.5 h-3.5" />
            </button>
          </div>

          {/* Mobile Menu Button */}
          <button
            onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
            className="md:hidden p-2 rounded-lg text-neutral-700 hover:bg-neutral-100 transition-colors"
            aria-label="Toggle menu"
          >
            {isMobileMenuOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
          </button>
        </div>

        {/* Mobile Dropdown */}
        {isMobileMenuOpen && (
          <div className="md:hidden border-b border-neutral-200 bg-white px-4 pt-3 pb-6 space-y-3 shadow-lg">
            <a
              href="#home"
              onClick={(e) => {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
                setIsMobileMenuOpen(false);
              }}
              className="block px-3 py-2 text-base font-semibold text-neutral-900 rounded-md bg-neutral-50"
            >
              Trang chủ
            </a>
            <a
              href="#features"
              onClick={(e) => {
                e.preventDefault();
                scrollToSection('features');
              }}
              className="block px-3 py-2 text-base font-medium text-neutral-700 hover:bg-neutral-50 rounded-md"
            >
              Tính năng
            </a>
            <a
              href="#contact"
              onClick={(e) => {
                e.preventDefault();
                scrollToSection('contact');
              }}
              className="block px-3 py-2 text-base font-medium text-neutral-700 hover:bg-neutral-50 rounded-md"
            >
              Liên hệ
            </a>
            <div className="pt-4 border-t border-neutral-100 flex flex-col gap-2">
              <button
                onClick={() => {
                  setIsMobileMenuOpen(false);
                  setAuthModal('register');
                }}
                className="w-full text-center py-2.5 border border-neutral-300 rounded-md text-sm font-medium text-neutral-800"
              >
                Đăng ký
              </button>
              <button
                onClick={() => {
                  setIsMobileMenuOpen(false);
                  setAuthModal('login');
                }}
                className="w-full text-center py-2.5 bg-neutral-950 text-white rounded-md text-sm font-medium"
              >
                Đăng nhập
              </button>
            </div>
          </div>
        )}
      </header>

      {/* ================= HERO SECTION ================= */}
      <main id="home" className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-24 lg:pt-16 lg:pb-32">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-14 items-center">

          {/* Left Column: Heading & Value Proposition */}
          <div className="lg:col-span-5 space-y-8">
            {/* Tagline Badge with Line */}
            <div className="inline-flex items-center gap-3">
              <span className="w-8 h-[2px] bg-neutral-900" />
              <span className="text-xs font-semibold tracking-widest text-neutral-800 uppercase">
                Nền tảng quản lý tòa nhà
              </span>
            </div>

            {/* Main Headline */}
            <h1 className="text-4xl sm:text-5xl lg:text-[56px] font-extrabold tracking-tight text-neutral-950 leading-[1.12]">
              Mọi vai trò, <br />
              một không gian <br className="hidden sm:inline" />
              quản lý.
            </h1>

            {/* Subtext */}
            <p className="text-base sm:text-lg text-neutral-600 leading-relaxed max-w-lg font-normal">
              <strong className="font-semibold text-neutral-900">SMART CASSAVAS</strong> kết nối cư dân, ban quản lý, lễ tân và admin trong một hệ thống rõ ràng, an toàn và dễ sử dụng.
            </p>

            {/* Action Buttons */}
            <div className="flex flex-wrap items-center gap-4 pt-2">
              <button
                onClick={() => scrollToSection('features')}
                className="group inline-flex items-center justify-center gap-3 bg-neutral-950 hover:bg-neutral-800 active:scale-[0.98] text-white font-medium text-sm px-6 py-3.5 rounded-sm shadow-md hover:shadow-lg transition-all"
              >
                <span>Khám phá tính năng</span>
                <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
              </button>

              <button
                onClick={() => setAuthModal('register')}
                className="inline-flex items-center justify-center bg-white hover:bg-neutral-50 active:scale-[0.98] text-neutral-900 font-medium text-sm px-6 py-3.5 rounded-sm border border-neutral-300 shadow-sm hover:border-neutral-400 transition-all"
              >
                Tạo tài khoản
              </button>
            </div>

            {/* Micro Cloud Highlights */}
            <div className="pt-4 border-t border-neutral-200/80 grid grid-cols-3 gap-4">
              <div className="flex items-center gap-2">
                <div className="p-1 rounded bg-neutral-100 text-neutral-700">
                  <Cloud className="w-3.5 h-3.5" />
                </div>
                <span className="text-xs text-neutral-600 font-medium">Cloud SaaS</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="p-1 rounded bg-neutral-100 text-neutral-700">
                  <Lock className="w-3.5 h-3.5" />
                </div>
                <span className="text-xs text-neutral-600 font-medium">Bảo mật ISO</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="p-1 rounded bg-neutral-100 text-neutral-700">
                  <Zap className="w-3.5 h-3.5" />
                </div>
                <span className="text-xs text-neutral-600 font-medium">99.9% Uptime</span>
              </div>
            </div>
          </div>

          {/* Right Column: Live Interactive Mockup Dashboard Card */}
          <div className="lg:col-span-7">
            <div className="relative group">
              {/* Soft Ambient Floating Glow behind the card */}
              <div className="absolute -inset-1 bg-gradient-to-r from-neutral-200 via-sky-100 to-neutral-300 rounded-xl blur-lg opacity-40 group-hover:opacity-60 transition duration-1000 -z-10" />

              {/* Main Window Card */}
              <div className="bg-white border border-neutral-300 rounded-lg shadow-xl overflow-hidden transition-all duration-300 hover:shadow-2xl">
                {/* Top Control Bar */}
                <div className="px-5 py-3 border-b border-neutral-200 bg-neutral-50/90 flex items-center justify-between">
                  <div className="flex items-center gap-2.5">
                    <span className="w-2 h-2 rounded-full bg-neutral-900" />
                    <span className="text-xs font-semibold text-neutral-800 tracking-wide">
                      SMART CASSAVAS / {activeTab === 'overview' ? 'Overview' : activeTab === 'residents' ? 'Residents' : activeTab === 'billing' ? 'Billing' : 'Maintenance'}
                    </span>
                  </div>

                  {/* Top Right Bell & Notification Popover */}
                  <div className="relative">
                    <button
                      onClick={() => setShowNotification(!showNotification)}
                      className="p-1.5 text-neutral-600 hover:text-neutral-950 rounded-md hover:bg-neutral-200/60 transition-colors relative"
                      title="Thông báo hệ thống"
                    >
                      <Bell className="w-4 h-4" />
                      <span className="absolute top-1 right-1 w-2 h-2 bg-rose-500 rounded-full ring-2 ring-white animate-pulse" />
                    </button>

                    {showNotification && (
                      <div className="absolute right-0 mt-2 w-80 bg-white border border-neutral-200 rounded-lg shadow-2xl p-3 z-50 animate-in fade-in slide-in-from-top-2 duration-150">
                        <div className="flex items-center justify-between pb-2 mb-2 border-b border-neutral-100">
                          <span className="text-xs font-semibold text-neutral-900">Thông báo thời gian thực</span>
                          <span className="text-[10px] text-emerald-600 font-medium bg-emerald-50 px-1.5 py-0.5 rounded">
                            3 mới
                          </span>
                        </div>
                        <div className="space-y-2.5">
                          {notifications.map((item) => (
                            <div key={item.id} className="p-2 hover:bg-neutral-50 rounded text-left transition-colors">
                              <div className="text-xs font-medium text-neutral-900">{item.title}</div>
                              <div className="text-[11px] text-neutral-500 mt-0.5 leading-snug">{item.desc}</div>
                              <div className="text-[10px] text-neutral-400 mt-1 flex items-center gap-1">
                                <Clock className="w-3 h-3" />
                                <span>{item.time}</span>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                </div>

                {/* Dashboard Inner Grid: Sidebar + Viewport */}
                <div className="grid grid-cols-12 min-h-[420px]">
                  {/* Left Workspace Sidebar */}
                  <div className="col-span-12 sm:col-span-4 border-b sm:border-b-0 sm:border-r border-neutral-200 p-4 bg-neutral-50/40">
                    <div className="text-[11px] font-bold text-neutral-400 uppercase tracking-widest px-2 mb-3">
                      Workspace
                    </div>

                    <nav className="space-y-1">
                      <button
                        onClick={() => setActiveTab('overview')}
                        className={`w-full text-left px-3 py-2 rounded text-xs font-medium transition-all flex items-center justify-between ${activeTab === 'overview'
                            ? 'bg-neutral-950 text-white shadow-sm'
                            : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'
                          }`}
                      >
                        <span>Tổng quan</span>
                        {activeTab === 'overview' && <span className="w-1.5 h-1.5 rounded-full bg-white" />}
                      </button>

                      <button
                        onClick={() => setActiveTab('residents')}
                        className={`w-full text-left px-3 py-2 rounded text-xs font-medium transition-all flex items-center justify-between ${activeTab === 'residents'
                            ? 'bg-neutral-950 text-white shadow-sm'
                            : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'
                          }`}
                      >
                        <span>Cư dân</span>
                        {activeTab === 'residents' && <span className="w-1.5 h-1.5 rounded-full bg-white" />}
                      </button>

                      <button
                        onClick={() => setActiveTab('billing')}
                        className={`w-full text-left px-3 py-2 rounded text-xs font-medium transition-all flex items-center justify-between ${activeTab === 'billing'
                            ? 'bg-neutral-950 text-white shadow-sm'
                            : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'
                          }`}
                      >
                        <span>Hóa đơn</span>
                        {activeTab === 'billing' && <span className="w-1.5 h-1.5 rounded-full bg-white" />}
                      </button>

                      <button
                        onClick={() => setActiveTab('maintenance')}
                        className={`w-full text-left px-3 py-2 rounded text-xs font-medium transition-all flex items-center justify-between ${activeTab === 'maintenance'
                            ? 'bg-neutral-950 text-white shadow-sm'
                            : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'
                          }`}
                      >
                        <span>Bảo trì</span>
                        {activeTab === 'maintenance' && <span className="w-1.5 h-1.5 rounded-full bg-white" />}
                      </button>
                    </nav>

                    {/* Quick Live Building Switcher / Info */}
                    <div className="mt-8 pt-4 border-t border-neutral-200/80 px-2">
                      <div className="text-[10px] text-neutral-400 uppercase font-semibold">Tòa nhà trực tuyến</div>
                      <div className="text-xs font-semibold text-neutral-800 mt-1 flex items-center gap-1.5">
                        <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                        Cassavas Tower 01
                      </div>
                      <div className="text-[11px] text-neutral-500">26 Tầng • 260 Căn hộ</div>
                    </div>
                  </div>

                  {/* Right Main Content Panel */}
                  <div className="col-span-12 sm:col-span-8 p-5 flex flex-col justify-between bg-white">
                    {activeTab === 'overview' && (
                      <div className="space-y-5 animate-in fade-in duration-200">
                        {/* Section Subtitle */}
                        <div className="flex items-center justify-between">
                          <span className="text-xs font-semibold text-neutral-700">Tổng quan vận hành</span>
                          <span className="text-[11px] text-neutral-400">Cập nhật 1 phút trước</span>
                        </div>

                        {/* Top Metric Cards */}
                        <div className="grid grid-cols-3 gap-3">
                          <div className="p-3 border border-neutral-200 rounded-sm bg-neutral-50/50 hover:bg-neutral-50 transition-colors">
                            <div className="text-[11px] text-neutral-500 font-medium">Cư dân</div>
                            <div className="text-2xl font-bold text-neutral-900 mt-1 tracking-tight">248</div>
                            <div className="text-[10px] text-emerald-600 font-medium flex items-center gap-0.5 mt-0.5">
                              <TrendingUp className="w-2.5 h-2.5" /> +8 căn mới
                            </div>
                          </div>

                          <div className="p-3 border border-neutral-200 rounded-sm bg-neutral-50/50 hover:bg-neutral-50 transition-colors">
                            <div className="text-[11px] text-neutral-500 font-medium">Đã thu</div>
                            <div className="text-2xl font-bold text-neutral-900 mt-1 tracking-tight">84%</div>
                            <div className="w-full bg-neutral-200 h-1.5 rounded-full mt-1.5 overflow-hidden">
                              <div className="bg-neutral-900 h-full rounded-full w-[84%] transition-all duration-700" />
                            </div>
                          </div>

                          <div className="p-3 border border-neutral-200 rounded-sm bg-neutral-50/50 hover:bg-neutral-50 transition-colors">
                            <div className="text-[11px] text-neutral-500 font-medium">Ticket</div>
                            <div className="text-2xl font-bold text-neutral-900 mt-1 tracking-tight">12</div>
                            <div className="text-[10px] text-amber-600 font-medium flex items-center gap-0.5 mt-0.5">
                              <Clock className="w-2.5 h-2.5" /> 3 chờ duyệt
                            </div>
                          </div>
                        </div>

                        {/* Dynamic Bar Chart Box */}
                        <div className="border border-neutral-200 rounded-sm p-4 bg-white relative">
                          <div className="flex items-center justify-between mb-4">
                            <div className="text-xs font-semibold text-neutral-800">Doanh thu tháng</div>
                            <span className="text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 flex items-center gap-1">
                              <TrendingUp className="w-3 h-3" />
                              +12.4%
                            </span>
                          </div>

                          {/* Interactive Bars */}
                          <div className="h-28 flex items-end justify-between gap-1.5 sm:gap-2 pt-2 px-1">
                            {monthlyData.map((item, idx) => {
                              const heightPct = Math.round((item.value / 110) * 100);
                              const isHovered = activeBarIndex === idx;
                              return (
                                <div
                                  key={item.month}
                                  className="flex-1 flex flex-col items-center group relative cursor-pointer"
                                  onMouseEnter={() => setActiveBarIndex(idx)}
                                  onMouseLeave={() => setActiveBarIndex(null)}
                                >
                                  {/* Tooltip on hover */}
                                  {isHovered && (
                                    <div className="absolute -top-8 bg-neutral-900 text-white text-[10px] py-0.5 px-1.5 rounded shadow-lg whitespace-nowrap z-20 pointer-events-none">
                                      {item.month}: {item.amount}
                                    </div>
                                  )}

                                  {/* Bar column */}
                                  <div className="w-full bg-neutral-100 rounded-t-sm h-full flex items-end">
                                    <div
                                      style={{ height: `${heightPct}%` }}
                                      className={`w-full rounded-t-sm transition-all duration-300 ${idx === 9 || isHovered
                                          ? 'bg-neutral-950'
                                          : 'bg-neutral-700 group-hover:bg-neutral-900'
                                        }`}
                                    />
                                  </div>
                                  <span className="text-[9px] text-neutral-400 mt-1 font-mono">{item.month}</span>
                                </div>
                              );
                            })}
                          </div>
                        </div>
                      </div>
                    )}

                    {/* Residents Tab Preview */}
                    {activeTab === 'residents' && (
                      <div className="space-y-4 animate-in fade-in duration-200">
                        <div className="flex items-center justify-between">
                          <span className="text-xs font-semibold text-neutral-800">Quản lý cư dân & Căn hộ</span>
                          <span className="text-[11px] text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded font-medium">
                            248/260 đã ở
                          </span>
                        </div>
                        <div className="space-y-2">
                          {[
                            { room: 'Căn 12.04', owner: 'Nguyễn Văn An', status: 'Chính chủ', count: '4 thành viên' },
                            { room: 'Căn 15.02', owner: 'Trần Thị Mai', status: 'Thuê dài hạn', count: '2 thành viên' },
                            { room: 'Căn 08.11', owner: 'Phạm Quang Huy', status: 'Chính chủ', count: '3 thành viên' },
                            { room: 'Căn 03.05', owner: 'Hoàng Minh Tuấn', status: 'Mới dọn vào', count: '1 thành viên' },
                          ].map((res) => (
                            <div
                              key={res.room}
                              className="p-2.5 border border-neutral-200 rounded flex items-center justify-between hover:bg-neutral-50 transition-colors"
                            >
                              <div>
                                <div className="text-xs font-semibold text-neutral-900">{res.room} - {res.owner}</div>
                                <div className="text-[11px] text-neutral-500">{res.count}</div>
                              </div>
                              <span className="text-[10px] font-medium bg-neutral-100 text-neutral-700 px-2 py-0.5 rounded">
                                {res.status}
                              </span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Billing Tab Preview */}
                    {activeTab === 'billing' && (
                      <div className="space-y-4 animate-in fade-in duration-200">
                        <div className="flex items-center justify-between">
                          <span className="text-xs font-semibold text-neutral-800">Doanh thu & Thu hộ tháng 9</span>
                          <span className="text-xs font-bold text-neutral-950">1,040,000,000 đ</span>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                          <div className="p-2.5 border border-neutral-200 rounded bg-neutral-50">
                            <div className="text-[11px] text-neutral-500">Phí quản lý & dịch vụ</div>
                            <div className="text-sm font-bold text-neutral-900 mt-1">680.5 tr đ</div>
                            <div className="text-[10px] text-emerald-600 font-medium mt-0.5">88% đã thanh toán</div>
                          </div>
                          <div className="p-2.5 border border-neutral-200 rounded bg-neutral-50">
                            <div className="text-[11px] text-neutral-500">Điện, nước, gửi xe</div>
                            <div className="text-sm font-bold text-neutral-900 mt-1">359.5 tr đ</div>
                            <div className="text-[10px] text-emerald-600 font-medium mt-0.5">79% đã thanh toán</div>
                          </div>
                        </div>
                        <div className="p-3 border border-dashed border-neutral-300 rounded text-center">
                          <span className="text-xs text-neutral-600 font-medium">
                            ⚡ Tích hợp QR Napas 24/7 tự động gạch nợ tức thời
                          </span>
                        </div>
                      </div>
                    )}

                    {/* Maintenance Tab Preview */}
                    {activeTab === 'maintenance' && (
                      <div className="space-y-4 animate-in fade-in duration-200">
                        <div className="flex items-center justify-between">
                          <span className="text-xs font-semibold text-neutral-800">Quy trình sự cố & bảo trì</span>
                          <span className="text-[11px] text-amber-700 bg-amber-50 px-2 py-0.5 rounded font-medium">
                            3 cần xử lý
                          </span>
                        </div>
                        <div className="space-y-2">
                          {[
                            { title: 'Kiểm tra áp lực nước tầng 18', tag: 'Kỹ thuật nước', state: 'Đang xử lý', time: '10p trước' },
                            { title: 'Bảo trì hệ thống PCCC định kỳ', tag: 'An toàn PCCC', state: 'Đã hoàn thành', time: 'Hôm qua' },
                            { title: 'Thay đèn chiếu sáng sảnh B2', tag: 'Hạ tầng', state: 'Chờ vật tư', time: '2 giờ trước' },
                          ].map((t, idx) => (
                            <div key={idx} className="p-2.5 border border-neutral-200 rounded flex items-center justify-between">
                              <div>
                                <div className="text-xs font-medium text-neutral-900">{t.title}</div>
                                <div className="text-[10px] text-neutral-400">{t.tag} • {t.time}</div>
                              </div>
                              <span
                                className={`text-[10px] font-medium px-2 py-0.5 rounded ${t.state === 'Đã hoàn thành'
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : t.state === 'Đang xử lý'
                                      ? 'bg-blue-50 text-blue-700'
                                      : 'bg-neutral-100 text-neutral-600'
                                  }`}
                              >
                                {t.state}
                              </span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Status Bottom Bar with Realtime Pulse */}
                    <div className="mt-4 pt-3 border-t border-neutral-200 flex items-center justify-between text-xs text-neutral-500">
                      <div className="flex items-center gap-2">
                        <span className="relative flex h-2 w-2">
                          <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
                          <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
                        </span>
                        <span className="text-neutral-700 font-medium">Hệ thống đang hoạt động ổn định</span>
                      </div>
                      <div className="hidden sm:flex items-center gap-2 font-mono text-[11px] text-neutral-400">
                        <span>Ping 24ms</span>
                        <span>•</span>
                        <span>{liveUptimeSeconds}s live</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </main>

      {/* ================= CORE CAPABILITIES / NĂNG LỰC CỐT LÕI ================= */}
      <section id="features" className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24 border-t border-neutral-200">
        {/* Section Header */}
        <div className="space-y-3 mb-12">
          <div className="inline-flex items-center gap-2">
            <span className="text-xs font-bold tracking-widest text-neutral-700 uppercase">
              Năng lực cốt lõi |
            </span>
          </div>
          <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-neutral-950">
            Vận hành gọn hơn, sống tốt hơn.
          </h2>
        </div>

        {/* 3 Core Capability Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">

          {/* Card 1: Finance / Billing */}
          <div className="group bg-white border border-neutral-300 rounded-sm p-7 flex flex-col justify-between hover:border-neutral-950 hover:shadow-lg transition-all duration-200">
            <div>
              {/* Icon */}
              <div className="w-8 h-8 text-neutral-900 mb-6 group-hover:-translate-y-0.5 transition-transform">
                <Mail className="w-6 h-6 stroke-[1.75]" />
              </div>

              {/* Title */}
              <h3 className="text-lg font-bold text-neutral-950 mb-2">
                Quản lý tài chính tự động
              </h3>

              {/* Body */}
              <p className="text-sm text-neutral-600 leading-relaxed font-normal">
                Chốt số, sinh hóa đơn hàng loạt và theo dõi công nợ.
              </p>
            </div>

            {/* Bottom Module Tag */}
            <div className="mt-10 pt-5 border-t border-neutral-200">
              <span className="text-[11px] font-semibold tracking-wider text-neutral-500 uppercase group-hover:text-neutral-950 transition-colors">
                Module Tài chính
              </span>
            </div>
          </div>

          {/* Card 2: Operations / Maintenance */}
          <div className="group bg-white border border-neutral-300 rounded-sm p-7 flex flex-col justify-between hover:border-neutral-950 hover:shadow-lg transition-all duration-200">
            <div>
              {/* Icon */}
              <div className="w-8 h-8 text-neutral-900 mb-6 group-hover:-translate-y-0.5 transition-transform">
                <Wrench className="w-6 h-6 stroke-[1.75]" />
              </div>

              {/* Title */}
              <h3 className="text-lg font-bold text-neutral-950 mb-2">
                Quy trình xử lý sự cố
              </h3>

              {/* Body */}
              <p className="text-sm text-neutral-600 leading-relaxed font-normal">
                Báo cáo tức thì, theo dõi tiến độ sửa chữa trực quan.
              </p>
            </div>

            {/* Bottom Module Tag */}
            <div className="mt-10 pt-5 border-t border-neutral-200">
              <span className="text-[11px] font-semibold tracking-wider text-neutral-500 uppercase group-hover:text-neutral-950 transition-colors">
                Module Vận hành
              </span>
            </div>
          </div>

          {/* Card 3: Security & Reception */}
          <div className="group bg-white border border-neutral-300 rounded-sm p-7 flex flex-col justify-between hover:border-neutral-950 hover:shadow-lg transition-all duration-200">
            <div>
              {/* Icon */}
              <div className="w-8 h-8 text-neutral-900 mb-6 group-hover:-translate-y-0.5 transition-transform">
                <ShieldCheck className="w-6 h-6 stroke-[1.75]" />
              </div>

              {/* Title */}
              <h3 className="text-lg font-bold text-neutral-950 mb-2">
                An ninh & bưu phẩm
              </h3>

              {/* Body */}
              <p className="text-sm text-neutral-600 leading-relaxed font-normal">
                Thông báo nhận hàng và kiểm soát khách ra vào chặt chẽ.
              </p>
            </div>

            {/* Bottom Module Tag */}
            <div className="mt-10 pt-5 border-t border-neutral-200">
              <span className="text-[11px] font-semibold tracking-wider text-neutral-500 uppercase group-hover:text-neutral-950 transition-colors">
                Module Lễ tân
              </span>
            </div>
          </div>

        </div>
      </section>

      {/* ================= INTERACTIVE BANNER / CTA ================= */}
      <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div className="bg-neutral-950 text-white rounded-lg p-8 sm:p-12 relative overflow-hidden flex flex-col lg:flex-row items-center justify-between gap-8">
          <div className="space-y-3 text-center lg:text-left z-10">
            <h3 className="text-2xl sm:text-3xl font-bold tracking-tight">
              Sẵn sàng chuyển đổi số cho tòa nhà của bạn?
            </h3>
            <p className="text-neutral-400 text-sm sm:text-base max-w-xl">
              Đồng bộ dữ liệu cư dân, tự động hóa hóa đơn và số hóa quy trình vận hành chỉ trong 24 giờ triển khai.
            </p>
          </div>
          <div className="flex flex-wrap items-center justify-center gap-4 z-10">
            <button
              onClick={() => setAuthModal('register')}
              className="bg-white hover:bg-neutral-100 text-neutral-950 text-sm font-semibold px-6 py-3.5 rounded-sm transition-colors"
            >
              Trải nghiệm miễn phí
            </button>
            <a
              href="#contact"
              onClick={(e) => {
                e.preventDefault();
                scrollToSection('contact');
              }}
              className="border border-neutral-700 hover:border-neutral-500 text-white text-sm font-medium px-6 py-3.5 rounded-sm transition-colors"
            >
              Tư vấn chuyên sâu
            </a>
          </div>

          {/* Ambient Glow in dark banner */}
          <div className="absolute -right-20 -bottom-20 w-80 h-80 bg-neutral-800/40 rounded-full blur-2xl pointer-events-none" />
        </div>
      </section>

      {/* ================= FOOTER ================= */}
      <footer id="contact" className="border-t border-neutral-200 bg-white mt-12">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
          <div className="grid grid-cols-1 md:grid-cols-12 gap-10">

            {/* Brand Column */}
            <div className="md:col-span-4 space-y-4">
              <div className="flex items-center gap-2.5">
                <div className="w-7 h-7 bg-neutral-950 text-white rounded flex items-center justify-center">
                  <Building2 className="w-4 h-4" />
                </div>
                <span className="font-bold text-base tracking-tight text-neutral-950">
                  SMART CASSAVAS
                </span>
              </div>
              <p className="text-xs text-neutral-500 leading-relaxed max-w-xs">
                Một nền tảng rõ ràng cho mọi hoạt động trong tòa nhà.
              </p>
            </div>

            {/* Links: Sản phẩm */}
            <div className="md:col-span-2 space-y-3">
              <h4 className="text-xs font-bold text-neutral-900 tracking-wide">Sản phẩm</h4>
              <ul className="space-y-2 text-xs text-neutral-600">
                <li>
                  <a
                    href="#features"
                    onClick={(e) => {
                      e.preventDefault();
                      scrollToSection('features');
                    }}
                    className="hover:text-neutral-950 transition-colors"
                  >
                    Tính năng
                  </a>
                </li>
                <li>
                  <button
                    onClick={() => setAuthModal('register')}
                    className="hover:text-neutral-950 transition-colors text-left"
                  >
                    Tạo tài khoản
                  </button>
                </li>
                <li>
                  <span className="text-neutral-400">Ứng dụng di động</span>
                </li>
              </ul>
            </div>

            {/* Links: Chính sách */}
            <div className="md:col-span-3 space-y-3">
              <h4 className="text-xs font-bold text-neutral-900 tracking-wide">Chính sách</h4>
              <ul className="space-y-2 text-xs text-neutral-600">
                <li>
                  <a href="#" className="hover:text-neutral-950 transition-colors">
                    Bảo mật
                  </a>
                </li>
                <li>
                  <a href="#" className="hover:text-neutral-950 transition-colors">
                    Điều khoản
                  </a>
                </li>
                <li>
                  <a href="#" className="hover:text-neutral-950 transition-colors">
                    Hỗ trợ
                  </a>
                </li>
              </ul>
            </div>

            {/* Links: Liên hệ */}
            <div className="md:col-span-3 space-y-3">
              <h4 className="text-xs font-bold text-neutral-900 tracking-wide">Liên hệ</h4>
              <ul className="space-y-2 text-xs text-neutral-600">
                <li className="font-mono text-neutral-900">support@smartcassavas.vn</li>
                <li>Thứ 2 — Thứ 6, 08:00 — 17:30</li>
                <li className="text-neutral-400">Hotline: 1900 6868 (Miễn phí)</li>
              </ul>
            </div>

          </div>

          {/* Bottom Copyright */}
          <div className="mt-12 pt-6 border-t border-neutral-100 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-neutral-400">
            <div>
              © 2026 SMART CASSAVAS. Bảo lưu mọi quyền.
            </div>
            <div className="flex items-center gap-4 text-[11px]">
              <span>Hệ thống quản lý tòa nhà thông minh</span>
              <span>•</span>
              <span className="flex items-center gap-1">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                Máy chủ đám mây hoạt động tốt
              </span>
            </div>
          </div>
        </div>
      </footer>

      {/* ================= MODAL LOGIN / REGISTER SIMULATION ================= */}
      {authModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-white border border-neutral-200 rounded-lg max-w-md w-full p-6 shadow-2xl relative animate-in zoom-in-95 duration-150">
            <button
              onClick={() => setAuthModal(null)}
              className="absolute top-4 right-4 p-1 text-neutral-400 hover:text-neutral-700 rounded-full hover:bg-neutral-100 transition-colors"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-3 mb-6">
              <div className="w-8 h-8 bg-black text-white rounded flex items-center justify-center">
                <Building2 className="w-4 h-4" />
              </div>
              <h3 className="text-lg font-bold text-neutral-900">
                {authModal === 'login' ? 'Đăng nhập SMART CASSAVAS' : 'Đăng ký trải nghiệm hệ thống'}
              </h3>
            </div>

            <form
              onSubmit={(e) => {
                e.preventDefault();
                alert(authModal === 'login' ? 'Đăng nhập hệ thống thành công!' : 'Đã gửi yêu cầu đăng ký tài khoản!');
                setAuthModal(null);
              }}
              className="space-y-4"
            >
              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">Email công việc</label>
                <input
                  type="email"
                  required
                  placeholder="admin@toanha.vn"
                  className="w-full px-3 py-2 border border-neutral-300 rounded text-sm focus:outline-none focus:border-neutral-900 transition-colors"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">Mật khẩu</label>
                <input
                  type="password"
                  required
                  placeholder="••••••••"
                  className="w-full px-3 py-2 border border-neutral-300 rounded text-sm focus:outline-none focus:border-neutral-900 transition-colors"
                />
              </div>

              {authModal === 'register' && (
                <div>
                  <label className="block text-xs font-semibold text-neutral-700 mb-1">Tên Tòa nhà / Dự án</label>
                  <input
                    type="text"
                    required
                    placeholder="VD: Chung cư Cassavas Complex"
                    className="w-full px-3 py-2 border border-neutral-300 rounded text-sm focus:outline-none focus:border-neutral-900 transition-colors"
                  />
                </div>
              )}

              <button
                type="submit"
                className="w-full mt-2 py-3 bg-neutral-950 hover:bg-neutral-800 text-white text-sm font-semibold rounded transition-colors shadow-sm"
              >
                {authModal === 'login' ? 'Đăng nhập ngay' : 'Bắt đầu dùng thử miễn phí'}
              </button>

              <div className="text-center pt-2">
                <button
                  type="button"
                  onClick={() => setAuthModal(authModal === 'login' ? 'register' : 'login')}
                  className="text-xs text-neutral-600 hover:text-neutral-950 font-medium"
                >
                  {authModal === 'login' ? 'Chưa có tài khoản? Đăng ký ngay' : 'Đã có tài khoản? Đăng nhập'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default Home;
