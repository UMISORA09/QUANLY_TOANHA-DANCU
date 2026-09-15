import React, { useState, useEffect } from 'react';
import { MorphIcon } from 'morphicons/react';
import {
  Menu as MenuData,
  X as XData,
  Bell as BellData,
  BellOff as BellOffData,
  ArrowRight as ArrowRightData,
  Sparkles as SparklesData,
  Check as CheckData,
  Users as UsersData,
  UserCheck as UserCheckData,
  CreditCard as CreditCardData,
  Receipt as ReceiptData,
  Wrench as WrenchData,
  Settings as SettingsData,
  ShieldCheck as ShieldCheckData,
  ShieldAlert as ShieldAlertData,
  Lock as LockData,
  Unlock as UnlockData,
  Zap as ZapData,
  Moon as MoonData,
  Sun as SunData,
  CheckCircle2 as CheckCircle2Data,
  LayoutDashboard as LayoutDashboardData,
  Mail as MailData,
  Send as SendData,
  Eye as EyeData,
  EyeOff as EyeOffData,
  UserPlus as UserPlusData,
  Activity as ActivityData,
  ChevronRight as ChevronRightData,
  Play as PlayData,
  Pause as PauseData,
  Car as CarData
} from 'lucide';
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
  PhoneCall,
  Play,
  Pause,
  Car,
  ChevronDown,
  LogOut,
  User as UserIcon,
  LayoutDashboard
} from 'lucide-react';
import { api } from '../Services/api';
import { Building3DModel } from '../Components/Building3DModel';
import { AuthModal, UserRole } from '../Components/AuthModal';

type WorkspaceTab = 'overview' | 'residents' | 'billing' | 'maintenance';

interface NotificationItem {
  id: number;
  title: string;
  desc: string;
  time: string;
  type: 'billing' | 'maintenance' | 'security';
}

interface HomeProps {
  initialAuthModal?: 'login' | 'register' | null;
  onLoginSuccess?: (role: UserRole, userEmail: string) => void;
  onNavigateAdmin?: () => void;
  currentUserRole?: string;
}

export const Home: React.FC<HomeProps> = ({
  initialAuthModal = null,
  onLoginSuccess,
  onNavigateAdmin,
  currentUserRole,
}) => {
  // Navigation & Interactive states
  const [activeTab, setActiveTab] = useState<WorkspaceTab>('overview');
  const [showNotification, setShowNotification] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [authModal, setAuthModal] = useState<'login' | 'register' | null>(initialAuthModal);
  const [loginFeedback, setLoginFeedback] = useState<string | null>(null);
  const [activeBarIndex, setActiveBarIndex] = useState<number | null>(null);
  const [liveUptimeSeconds, setLiveUptimeSeconds] = useState(0);

  // Authenticated user state
  const [currentUser, setCurrentUser] = useState<any>(() => api.getUser());
  const [isUserDropdownOpen, setIsUserDropdownOpen] = useState<boolean>(false);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (!(e.target as HTMLElement).closest('.user-dropdown-container')) {
        setIsUserDropdownOpen(false);
      }
    };
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, []);

  useEffect(() => {
    const token = localStorage.getItem('smart_cassavas_token');
    if (token) {
      api.getMe()
        .then((user) => {
          api.setUser(user);
          setCurrentUser(user);
        })
        .catch(() => {
          // Token expired or invalid
          api.logout();
          setCurrentUser(null);
        });
    } else {
      setCurrentUser(null);
    }
  }, []);

  const handleLogout = () => {
    api.logout();
    setCurrentUser(null);
    setLoginFeedback('Đã đăng xuất tài khoản thành công.');
    setTimeout(() => setLoginFeedback(null), 3000);
  };

  const openAuth = (mode: 'login' | 'register') => {
    setAuthModal(mode);
    window.history.pushState({}, '', mode === 'login' ? '/login' : '/register');
  };

  const closeAuth = () => {
    setAuthModal(null);
    if (window.location.pathname === '/login' || window.location.pathname === '/register') {
      window.history.pushState({}, '', '/');
    }
  };

  // Morphicons interactive states
  const [isHeroCtaHovered, setIsHeroCtaHovered] = useState(false);
  const [hoveredFeatureCard, setHoveredFeatureCard] = useState<number | null>(null);
  const [buildingNightMode, setBuildingNightMode] = useState(false);
  const [gateLocked, setGateLocked] = useState(true);
  const [highAlertMode, setHighAlertMode] = useState(false);
  const [evChargingActive, setEvChargingActive] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
  const [copiedStatus, setCopiedStatus] = useState(false);

  // Advanced motion & simulation states
  const [isAutoSimulating, setIsAutoSimulating] = useState(false);
  const [mousePos, setMousePos] = useState({ x: 600, y: 300 });
  const [tilt, setTilt] = useState({ x: 0, y: 0 });
  const [carPassAnimation, setCarPassAnimation] = useState(false);

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

  // Auto-simulation loop: sequentially morphs icons and toggles modes
  useEffect(() => {
    if (!isAutoSimulating) return;
    const tabs: WorkspaceTab[] = ['overview', 'residents', 'billing', 'maintenance'];
    let step = 0;
    const interval = setInterval(() => {
      step = (step + 1) % 4;
      if (step === 0) {
        setBuildingNightMode((prev) => !prev);
        setActiveTab('overview');
      } else if (step === 1) {
        setGateLocked(false);
        setCarPassAnimation(true);
        setTimeout(() => setCarPassAnimation(false), 2000);
        setActiveTab('residents');
      } else if (step === 2) {
        setHighAlertMode((prev) => !prev);
        setGateLocked(true);
        setActiveTab('billing');
      } else {
        setEvChargingActive((prev) => !prev);
        setActiveTab('maintenance');
      }
    }, 2400);
    return () => clearInterval(interval);
  }, [isAutoSimulating]);

  const handleCardMouseMove = (e: React.MouseEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const x = e.clientX - rect.left - rect.width / 2;
    const y = e.clientY - rect.top - rect.height / 2;
    const rotateX = -(y / (rect.height / 2)) * 3;
    const rotateY = (x / (rect.width / 2)) * 3;
    setTilt({ x: rotateX, y: rotateY });
  };

  const handleCardMouseLeave = () => {
    setTilt({ x: 0, y: 0 });
  };

  const handleGateToggle = () => {
    const nextState = !gateLocked;
    setGateLocked(nextState);
    if (!nextState) {
      setCarPassAnimation(true);
      setTimeout(() => setCarPassAnimation(false), 2400);
    }
  };

  const scrollToSection = (id: string) => {
    const el = document.getElementById(id);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth' });
      setIsMobileMenuOpen(false);
    }
  };

  return (
    <div
      onMouseMove={(e) => setMousePos({ x: e.clientX, y: e.clientY })}
      className="min-h-screen bg-[#FAFAFA] text-[#171717] selection:bg-neutral-900 selection:text-white font-sans relative overflow-x-hidden transition-colors duration-500"
    >
      {/* Dynamic Mouse Following Spotlight */}
      <div
        className="pointer-events-none fixed inset-0 z-0 transition-opacity duration-500 opacity-70 hidden md:block"
        style={{
          background: `radial-gradient(650px circle at ${mousePos.x}px ${mousePos.y}px, rgba(56, 189, 248, 0.06), transparent 80%)`,
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
              href="#model3d"
              onClick={(e) => {
                e.preventDefault();
                scrollToSection('model3d');
              }}
              className="text-sm font-medium text-neutral-600 hover:text-neutral-950 transition-colors flex items-center gap-1.5"
            >
              <span className="w-1.5 h-1.5 rounded-full bg-cyan-500 animate-pulse" />
              <span>Mô hình 3D</span>
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
          <div className="hidden md:flex items-center gap-3">
            {currentUser ? (
              <div className="flex items-center gap-2.5">
                {/* User Info Badge with Dropdown */}
                <div className="relative user-dropdown-container">
                  <button
                    type="button"
                    onClick={() => setIsUserDropdownOpen((prev) => !prev)}
                    className="flex items-center gap-2 pl-2 pr-2.5 py-1.5 rounded-full bg-neutral-100/90 hover:bg-neutral-200/80 border border-neutral-200/80 shadow-2xs backdrop-blur-sm transition-all cursor-pointer group"
                    title="Menu tài khoản"
                  >
                    <div className="w-7 h-7 rounded-full bg-neutral-900 text-white flex items-center justify-center font-bold text-xs shadow-xs shrink-0 group-hover:scale-105 transition-transform">
                      {currentUser.full_name ? currentUser.full_name.charAt(0).toUpperCase() : 'A'}
                    </div>
                    <div className="flex flex-col text-left">
                      <span className="text-xs font-bold text-neutral-900 leading-tight">
                        {currentUser.full_name || currentUser.name || currentUser.username || 'Admin Cassavas'}
                      </span>
                      <span className="text-[10px] text-neutral-500 font-medium">
                        {currentUser.roles?.includes('SUPER_ADMIN') || currentUser.role === 'admin'
                          ? 'admin'
                          : currentUser.roles?.[0] || currentUser.role || 'Cư dân'}
                      </span>
                    </div>
                    <ChevronDown className={`w-3.5 h-3.5 text-neutral-500 transition-transform duration-200 ${isUserDropdownOpen ? 'rotate-180 text-neutral-900' : ''}`} />
                  </button>

                  {/* Dropdown Menu sổ xuống */}
                  {isUserDropdownOpen && (
                    <div className="absolute right-0 mt-2 w-60 rounded-2xl bg-white/95 backdrop-blur-2xl border border-neutral-200/90 shadow-2xl p-2 z-50 animate-in fade-in slide-in-from-top-2 duration-150">
                      {/* User Info Header */}
                      <div className="px-3 py-2 border-b border-neutral-100 mb-1">
                        <div className="text-xs font-bold text-neutral-900 truncate">
                          {currentUser.full_name || currentUser.name || currentUser.username || 'Admin Cassavas'}
                        </div>
                        <div className="text-[10px] text-neutral-500 truncate font-mono">
                          {currentUser.email || 'admin@cassavas.vn'}
                        </div>
                      </div>

                      {/* Menu Item: Vào Trang Quản lý */}
                      <a
                        href="/admin"
                        onClick={(e) => {
                          e.preventDefault();
                          setIsUserDropdownOpen(false);
                          if (onNavigateAdmin) {
                            onNavigateAdmin();
                          } else {
                            window.history.pushState({}, '', '/admin');
                            window.dispatchEvent(new PopStateEvent('popstate'));
                          }
                        }}
                        className="flex items-center gap-2.5 px-3 py-2.5 text-xs font-semibold text-sky-950 bg-sky-50/80 hover:bg-sky-100 border border-sky-200/70 rounded-xl transition-all shadow-2xs cursor-pointer group"
                      >
                        <div className="w-7 h-7 rounded-lg bg-sky-600 text-white flex items-center justify-center shrink-0 shadow-xs group-hover:scale-105 transition-transform">
                          <LayoutDashboard className="w-4 h-4" />
                        </div>
                        <div className="flex flex-col text-left">
                          <span className="font-bold text-sky-950">Vào trang quản lý</span>
                          <span className="text-[10px] text-sky-600 font-normal">Dashboard & Vận hành</span>
                        </div>
                      </a>

                      <div className="my-1.5 border-t border-neutral-100" />

                      {/* Menu Item: Đăng xuất */}
                      <button
                        type="button"
                        onClick={() => {
                          setIsUserDropdownOpen(false);
                          handleLogout();
                        }}
                        className="w-full flex items-center gap-2 px-3 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50 rounded-xl transition-colors cursor-pointer text-left"
                      >
                        <LogOut className="w-3.5 h-3.5" />
                        <span>Đăng xuất</span>
                      </button>
                    </div>
                  )}
                </div>

                {/* Đăng xuất button */}
                <button
                  type="button"
                  onClick={handleLogout}
                  className="text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 border border-transparent hover:border-rose-200 px-2.5 py-2 rounded-lg transition-all flex items-center gap-1.5 cursor-pointer"
                  title="Đăng xuất tài khoản"
                >
                  <LogOut className="w-3.5 h-3.5" />
                  <span className="hidden lg:inline">Đăng xuất</span>
                </button>
              </div>
            ) : (
              <>
                <button
                  onClick={() => openAuth('register')}
                  className="text-sm font-medium text-neutral-700 hover:text-neutral-950 px-3 py-2 rounded-md transition-colors"
                >
                  Đăng ký
                </button>
                <button
                  onClick={() => openAuth('login')}
                  className="text-sm font-medium text-white bg-neutral-950 hover:bg-neutral-800 active:scale-95 px-5 py-2.5 rounded-md shadow-sm transition-all flex items-center gap-2"
                >
                  <span>Đăng nhập</span>
                  <ChevronRight className="w-3.5 h-3.5" />
                </button>
              </>
            )}
          </div>

          {/* Mobile Menu Button */}
          <button
            onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
            className="md:hidden p-2 rounded-lg text-neutral-700 hover:bg-neutral-100 transition-colors focus:outline-none"
            aria-label="Toggle menu"
          >
            <MorphIcon
              icon={isMobileMenuOpen ? XData : MenuData}
              size={24}
              strokeWidth={2}
              spring="snappy"
              className="text-neutral-900"
            />
          </button>
        </div>

        {/* Mobile Dropdown */}
        {isMobileMenuOpen && (
          <div className="md:hidden border-b border-neutral-200 glass-panel px-4 pt-3 pb-6 space-y-3 shadow-lg">
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
              href="#model3d"
              onClick={(e) => {
                e.preventDefault();
                scrollToSection('model3d');
                setIsMobileMenuOpen(false);
              }}
              className="block px-3 py-2 text-base font-medium text-neutral-700 hover:bg-neutral-50 rounded-md"
            >
              Mô hình 3D Digital Twin
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
            {currentUser ? (
              <div className="pt-4 border-t border-neutral-100 flex flex-col gap-2.5">
                <div className="flex items-center gap-3 p-3 bg-neutral-100/80 rounded-xl border border-neutral-200/60">
                  <div className="w-9 h-9 rounded-full bg-neutral-900 text-white flex items-center justify-center font-bold text-sm shrink-0">
                    {currentUser.full_name ? currentUser.full_name.charAt(0).toUpperCase() : 'U'}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="text-sm font-bold text-neutral-900 truncate">
                      {currentUser.full_name || currentUser.username}
                    </div>
                    <div className="text-xs text-neutral-500">
                      {currentUser.email || currentUser.phone_number}
                    </div>
                  </div>
                </div>
                <a
                  href="/admin"
                  onClick={(e) => {
                    e.preventDefault();
                    setIsMobileMenuOpen(false);
                    if (onNavigateAdmin) {
                      onNavigateAdmin();
                    } else {
                      window.history.pushState({}, '', '/admin');
                      window.dispatchEvent(new PopStateEvent('popstate'));
                    }
                  }}
                  className="w-full text-center py-2.5 bg-sky-50 hover:bg-sky-100 border border-sky-200 text-sky-950 rounded-lg text-sm font-semibold flex items-center justify-center gap-2 transition-colors cursor-pointer"
                >
                  <LayoutDashboard className="w-4 h-4 text-sky-600" />
                  <span>Vào trang quản lý</span>
                </a>
                <button
                  type="button"
                  onClick={() => {
                    setIsMobileMenuOpen(false);
                    handleLogout();
                  }}
                  className="w-full text-center py-2.5 border border-rose-200 text-rose-600 hover:bg-rose-50 rounded-lg text-sm font-medium flex items-center justify-center gap-2 transition-colors cursor-pointer"
                >
                  <LogOut className="w-4 h-4" />
                  <span>Đăng xuất</span>
                </button>
              </div>
            ) : (
              <div className="pt-4 border-t border-neutral-100 flex flex-col gap-2">
                <button
                  onClick={() => {
                    setIsMobileMenuOpen(false);
                    openAuth('register');
                  }}
                  className="w-full text-center py-2.5 border border-neutral-300 rounded-md text-sm font-medium text-neutral-800"
                >
                  Đăng ký
                </button>
                <button
                  onClick={() => {
                    setIsMobileMenuOpen(false);
                    openAuth('login');
                  }}
                  className="w-full text-center py-2.5 bg-neutral-950 text-white rounded-md text-sm font-medium"
                >
                  Đăng nhập
                </button>
              </div>
            )}
          </div>
        )}
      </header>

      {/* ================= HERO SECTION ================= */}
      <main id="home" className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-24 lg:pt-16 lg:pb-32">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-14 items-center">

          {/* Left Column: Heading & Value Proposition */}
          <div className="lg:col-span-5 space-y-8">
            {/* Tagline Badge with Line & Glass Specular Effect */}
            <div className="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full glass-pill text-xs font-semibold tracking-wider text-neutral-800 uppercase shadow-xs">
              <span className="w-2 h-2 rounded-full bg-sky-500 animate-pulse" />
              <span className="animate-text-light-brand font-bold">Nền tảng quản lý tòa nhà thông minh 4.0</span>
            </div>

            {/* Main Headline with Animated Text Lighting Sweep */}
            <h1 className="text-4xl sm:text-5xl lg:text-[56px] font-extrabold tracking-tight leading-[1.12] animate-text-light drop-shadow-xs">
              Mọi vai trò, <br />
              một không gian <br className="hidden sm:inline" />
              quản lý.
            </h1>

            {/* Subtext */}
            <p className="text-base sm:text-lg text-neutral-600 leading-relaxed max-w-lg font-normal">
              <strong className="font-semibold text-neutral-900">SMART CASSAVAS</strong> kết nối cư dân, ban quản lý, lễ tân và admin trong một hệ thống rõ ràng, an toàn và trực quan hóa 3D toàn diện.
            </p>

            {/* Action Buttons */}
            <div className="flex flex-wrap items-center gap-3 pt-2">
              <button
                onClick={() => scrollToSection('features')}
                onMouseEnter={() => setIsHeroCtaHovered(true)}
                onMouseLeave={() => setIsHeroCtaHovered(false)}
                className="group inline-flex items-center justify-center gap-3 bg-neutral-950 hover:bg-neutral-800 active:scale-[0.98] text-white font-medium text-sm px-6 py-3.5 rounded-sm shadow-md hover:shadow-lg transition-all"
              >
                <span>Khám phá tính năng</span>
                <MorphIcon
                  icon={isHeroCtaHovered ? SparklesData : ArrowRightData}
                  size={16}
                  strokeWidth={2}
                  spring="snappy"
                  className="transition-transform group-hover:translate-x-0.5"
                />
              </button>

              <button
                onClick={() => scrollToSection('model3d')}
                className="inline-flex items-center justify-center gap-2 glass-pill hover:bg-white active:scale-[0.98] text-neutral-900 font-semibold text-sm px-5 py-3.5 rounded-sm border border-neutral-300/80 shadow-xs hover:border-neutral-400 transition-all group"
              >
                <Building2 className="w-4 h-4 text-sky-600 group-hover:scale-110 transition-transform" />
                <span>Mô hình 3D Live</span>
              </button>

              {currentUser ? (
                <button
                  onClick={() => scrollToSection('amenities')}
                  className="inline-flex items-center justify-center bg-neutral-900 hover:bg-neutral-800 active:scale-[0.98] text-white font-medium text-sm px-5 py-3.5 rounded-sm shadow-xs transition-all gap-2 cursor-pointer"
                >
                  <Sparkles className="w-4 h-4 text-emerald-400" />
                  <span>Khám phá tiện ích</span>
                </button>
              ) : (
                <button
                  onClick={() => openAuth('register')}
                  className="inline-flex items-center justify-center bg-white/80 hover:bg-white active:scale-[0.98] text-neutral-900 font-medium text-sm px-5 py-3.5 rounded-sm border border-neutral-300/70 shadow-xs hover:border-neutral-400 transition-all cursor-pointer"
                >
                  Tạo tài khoản
                </button>
              )}
            </div>

            {/* Quick Glass Role Showcase Bar matching user screenshot */}
            <div className="pt-2">
              <div className="glass-panel rounded-xl p-3 sm:p-3.5 border border-white/80 shadow-xs relative overflow-hidden group">
                <div className="flex flex-wrap items-center justify-between gap-2 mb-2">
                  <span className="text-[11px] font-bold text-neutral-900 uppercase tracking-wider flex items-center gap-1.5">
                    <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                    Truy cập nhanh theo vai trò
                  </span>
                  <span className="text-[10px] text-neutral-500 font-mono">
                    Chọn vai trò để vào thẳng
                  </span>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                  <button
                    type="button"
                    onClick={() => openAuth('login')}
                    className="glass-role-card p-2 rounded-lg text-left hover:border-neutral-900 transition-all flex items-center gap-2 group/btn cursor-pointer"
                  >
                    <div className="w-6 h-6 rounded bg-neutral-100 flex items-center justify-center text-neutral-800 text-xs font-bold shrink-0">
                      🏢
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-bold text-neutral-900 leading-tight">Quản lý</div>
                      <div className="text-[10px] text-neutral-500 truncate">Vận hành</div>
                    </div>
                  </button>
                  <button
                    type="button"
                    onClick={() => openAuth('login')}
                    className="glass-role-card p-2 rounded-lg text-left hover:border-neutral-900 transition-all flex items-center gap-2 group/btn cursor-pointer"
                  >
                    <div className="w-6 h-6 rounded bg-neutral-100 flex items-center justify-center text-neutral-800 text-xs font-bold shrink-0">
                      👥
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-bold text-neutral-900 leading-tight">Cư dân</div>
                      <div className="text-[10px] text-neutral-500 truncate">Căn hộ</div>
                    </div>
                  </button>
                  <button
                    type="button"
                    onClick={() => openAuth('login')}
                    className="glass-role-card p-2 rounded-lg text-left hover:border-neutral-900 transition-all flex items-center gap-2 group/btn cursor-pointer"
                  >
                    <div className="w-6 h-6 rounded bg-neutral-100 flex items-center justify-center text-neutral-800 text-xs font-bold shrink-0">
                      📋
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-bold text-neutral-900 leading-tight">Lễ tân</div>
                      <div className="text-[10px] text-neutral-500 truncate">Khách hàng</div>
                    </div>
                  </button>
                  <button
                    type="button"
                    onClick={() => openAuth('login')}
                    className="glass-role-card p-2 rounded-lg text-left hover:border-neutral-900 transition-all flex items-center gap-2 group/btn cursor-pointer"
                  >
                    <div className="w-6 h-6 rounded bg-neutral-100 flex items-center justify-center text-neutral-800 text-xs font-bold shrink-0">
                      🛡️
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-bold text-neutral-900 leading-tight">Admin</div>
                      <div className="text-[10px] text-neutral-500 truncate">Hệ thống</div>
                    </div>
                  </button>
                </div>
              </div>
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
              <div
                className={`absolute -inset-2 rounded-2xl blur-xl transition-all duration-700 -z-10 ${
                  highAlertMode
                    ? 'bg-gradient-to-r from-amber-500/30 via-rose-500/30 to-amber-500/30 opacity-80 animate-pulse'
                    : buildingNightMode
                    ? 'bg-gradient-to-r from-indigo-500/25 via-sky-500/20 to-purple-600/25 opacity-70'
                    : 'bg-gradient-to-r from-neutral-200 via-sky-100 to-neutral-300 opacity-40 group-hover:opacity-60'
                }`}
              />

              {/* Main Window Card with 3D Tilt and Cockpit Mode */}
              <div
                onMouseMove={handleCardMouseMove}
                onMouseLeave={handleCardMouseLeave}
                style={{
                  transform:
                    tilt.x !== 0 || tilt.y !== 0
                      ? `perspective(1000px) rotateX(${tilt.x.toFixed(2)}deg) rotateY(${tilt.y.toFixed(2)}deg)`
                      : 'perspective(1000px) rotateX(0deg) rotateY(0deg)',
                  transition: tilt.x === 0 && tilt.y === 0 ? 'transform 0.5s ease-out, background-color 0.7s, border-color 0.7s' : 'background-color 0.7s, border-color 0.7s'
                }}
                className={`rounded-xl shadow-2xl overflow-hidden transition-all duration-700 glass-specular-edge ${
                  highAlertMode ? 'ring-2 ring-amber-500 shadow-[0_0_35px_rgba(245,158,11,0.25)]' : ''
                } ${
                  buildingNightMode
                    ? 'glass-panel-dark text-white shadow-[0_20px_60px_-15px_rgba(2,6,23,0.85)]'
                    : 'glass-panel text-[#171717] hover:shadow-2xl'
                }`}
              >
                {/* High Alert Security Marquee if enabled */}
                {highAlertMode && (
                  <div className="bg-amber-500 text-slate-950 px-4 py-1 text-[11px] font-bold flex items-center justify-between tracking-wide animate-pulse">
                    <div className="flex items-center gap-2">
                      <span className="w-2 h-2 rounded-full bg-slate-950 animate-ping" />
                      <span>CẢNH BÁO AN NINH: ĐANG TRONG CA TUẦN TRA CAO ĐIỂM 24/7 (RADAR ACTIVE)</span>
                    </div>
                    <span className="font-mono text-[10px] uppercase">Hệ thống kích hoạt</span>
                  </div>
                )}

                {/* Top Control Bar */}
                <div
                  className={`px-5 py-3 border-b flex items-center justify-between transition-colors duration-700 backdrop-blur-md ${
                    buildingNightMode
                      ? 'bg-[#1C2541]/75 border-slate-800 text-slate-200'
                      : 'bg-white/65 border-neutral-200/80 text-neutral-800'
                  }`}
                >
                  <div className="flex items-center gap-2.5">
                    <span className={`w-2 h-2 rounded-full ${buildingNightMode ? 'bg-cyan-400 animate-pulse' : 'bg-neutral-900'}`} />
                    <span className="text-xs font-semibold tracking-wide">
                      SMART CASSAVAS / {activeTab === 'overview' ? 'Overview' : activeTab === 'residents' ? 'Residents' : activeTab === 'billing' ? 'Billing' : 'Maintenance'}
                    </span>
                    {buildingNightMode && (
                      <span className="text-[10px] bg-indigo-950 text-cyan-300 border border-cyan-800/60 px-1.5 py-0.5 rounded font-mono">
                        NIGHT COCKPIT
                      </span>
                    )}
                  </div>

                  {/* Top Right Bell & Notification Popover */}
                  <div className="relative">
                    <button
                      onClick={() => setShowNotification(!showNotification)}
                      className={`p-1.5 rounded-md transition-colors relative ${
                        buildingNightMode
                          ? 'text-slate-300 hover:text-white hover:bg-slate-800'
                          : 'text-neutral-600 hover:text-neutral-950 hover:bg-neutral-200/60'
                      }`}
                      title="Thông báo hệ thống"
                    >
                      <MorphIcon
                        icon={showNotification ? XData : BellData}
                        size={16}
                        strokeWidth={2}
                        spring="snappy"
                      />
                      {!showNotification && (
                        <span className="absolute top-1 right-1 w-2 h-2 bg-rose-500 rounded-full ring-2 ring-white animate-pulse" />
                      )}
                    </button>

                    {showNotification && (
                      <div className={`absolute right-0 mt-2 w-80 rounded-lg shadow-2xl p-3 z-50 animate-in fade-in slide-in-from-top-2 duration-150 ${
                        buildingNightMode ? 'bg-slate-900 border border-slate-700 text-slate-100' : 'bg-white border border-neutral-200 text-neutral-900'
                      }`}>
                        <div className="flex items-center justify-between pb-2 mb-2 border-b border-neutral-100 dark:border-slate-800">
                          <span className="text-xs font-semibold">Thông báo thời gian thực</span>
                          <span className="text-[10px] text-emerald-600 font-medium bg-emerald-50 px-1.5 py-0.5 rounded">
                            3 mới
                          </span>
                        </div>
                        <div className="space-y-2.5">
                          {notifications.map((item) => (
                            <div
                              key={item.id}
                              className={`p-2 rounded text-left transition-colors ${
                                buildingNightMode ? 'hover:bg-slate-800/80' : 'hover:bg-neutral-50'
                              }`}
                            >
                              <div className="text-xs font-medium">{item.title}</div>
                              <div className={`text-[11px] mt-0.5 leading-snug ${buildingNightMode ? 'text-slate-400' : 'text-neutral-500'}`}>
                                {item.desc}
                              </div>
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
                  <div
                    className={`col-span-12 sm:col-span-4 border-b sm:border-b-0 sm:border-r p-4 transition-colors duration-700 ${
                      buildingNightMode
                        ? 'bg-[#0F172A]/90 border-slate-800'
                        : 'bg-neutral-50/40 border-neutral-200'
                    }`}
                  >
                    <div className="text-[11px] font-bold text-neutral-400 uppercase tracking-widest px-2 mb-3">
                      Workspace
                    </div>

                    <nav className="space-y-1">
                      <button
                        onClick={() => setActiveTab('overview')}
                        className={`w-full text-left px-3 py-2.5 rounded text-xs font-medium transition-all flex items-center justify-between ${
                          activeTab === 'overview'
                            ? buildingNightMode
                              ? 'bg-cyan-500 text-slate-950 font-bold shadow-md shadow-cyan-500/20'
                              : 'bg-neutral-950 text-white shadow-sm'
                            : buildingNightMode
                            ? 'text-slate-400 hover:text-white hover:bg-slate-800/60'
                            : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'
                        }`}
                      >
                        <div className="flex items-center gap-2.5">
                          <MorphIcon
                            icon={activeTab === 'overview' ? SparklesData : LayoutDashboardData}
                            size={15}
                            strokeWidth={2}
                            spring="bouncy"
                            className={activeTab === 'overview' ? (buildingNightMode ? 'text-slate-950' : 'text-amber-300') : 'text-neutral-500'}
                          />
                          <span>Tổng quan</span>
                        </div>
                        {activeTab === 'overview' && (
                          <span className={`w-1.5 h-1.5 rounded-full ${buildingNightMode ? 'bg-slate-950' : 'bg-white'}`} />
                        )}
                      </button>

                      <button
                        onClick={() => setActiveTab('residents')}
                        className={`w-full text-left px-3 py-2.5 rounded text-xs font-medium transition-all flex items-center justify-between ${
                          activeTab === 'residents'
                            ? buildingNightMode
                              ? 'bg-cyan-500 text-slate-950 font-bold shadow-md shadow-cyan-500/20'
                              : 'bg-neutral-950 text-white shadow-sm'
                            : buildingNightMode
                            ? 'text-slate-400 hover:text-white hover:bg-slate-800/60'
                            : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'
                        }`}
                      >
                        <div className="flex items-center gap-2.5">
                          <MorphIcon
                            icon={activeTab === 'residents' ? UserCheckData : UsersData}
                            size={15}
                            strokeWidth={2}
                            spring="bouncy"
                            className={activeTab === 'residents' ? (buildingNightMode ? 'text-slate-950' : 'text-sky-300') : 'text-neutral-500'}
                          />
                          <span>Cư dân</span>
                        </div>
                        {activeTab === 'residents' && (
                          <span className={`w-1.5 h-1.5 rounded-full ${buildingNightMode ? 'bg-slate-950' : 'bg-white'}`} />
                        )}
                      </button>

                      <button
                        onClick={() => setActiveTab('billing')}
                        className={`w-full text-left px-3 py-2.5 rounded text-xs font-medium transition-all flex items-center justify-between ${
                          activeTab === 'billing'
                            ? buildingNightMode
                              ? 'bg-cyan-500 text-slate-950 font-bold shadow-md shadow-cyan-500/20'
                              : 'bg-neutral-950 text-white shadow-sm'
                            : buildingNightMode
                            ? 'text-slate-400 hover:text-white hover:bg-slate-800/60'
                            : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'
                        }`}
                      >
                        <div className="flex items-center gap-2.5">
                          <MorphIcon
                            icon={activeTab === 'billing' ? ReceiptData : CreditCardData}
                            size={15}
                            strokeWidth={2}
                            spring="bouncy"
                            className={activeTab === 'billing' ? (buildingNightMode ? 'text-slate-950' : 'text-emerald-300') : 'text-neutral-500'}
                          />
                          <span>Hóa đơn</span>
                        </div>
                        {activeTab === 'billing' && (
                          <span className={`w-1.5 h-1.5 rounded-full ${buildingNightMode ? 'bg-slate-950' : 'bg-white'}`} />
                        )}
                      </button>

                      <button
                        onClick={() => setActiveTab('maintenance')}
                        className={`w-full text-left px-3 py-2.5 rounded text-xs font-medium transition-all flex items-center justify-between ${
                          activeTab === 'maintenance'
                            ? buildingNightMode
                              ? 'bg-cyan-500 text-slate-950 font-bold shadow-md shadow-cyan-500/20'
                              : 'bg-neutral-950 text-white shadow-sm'
                            : buildingNightMode
                            ? 'text-slate-400 hover:text-white hover:bg-slate-800/60'
                            : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'
                        }`}
                      >
                        <div className="flex items-center gap-2.5">
                          <MorphIcon
                            icon={activeTab === 'maintenance' ? SettingsData : WrenchData}
                            size={15}
                            strokeWidth={2}
                            spring="bouncy"
                            className={activeTab === 'maintenance' ? (buildingNightMode ? 'text-slate-950' : 'text-orange-300') : 'text-neutral-500'}
                          />
                          <span>Bảo trì</span>
                        </div>
                        {activeTab === 'maintenance' && (
                          <span className={`w-1.5 h-1.5 rounded-full ${buildingNightMode ? 'bg-slate-950' : 'bg-white'}`} />
                        )}
                      </button>
                    </nav>

                    {/* Quick Live Building Switcher / Info */}
                    <div className={`mt-8 pt-4 border-t px-2 transition-colors duration-700 ${buildingNightMode ? 'border-slate-800' : 'border-neutral-200/80'}`}>
                      <div className="text-[10px] text-neutral-400 uppercase font-semibold">Tòa nhà trực tuyến</div>
                      <div className={`text-xs font-semibold mt-1 flex items-center gap-1.5 ${buildingNightMode ? 'text-slate-200' : 'text-neutral-800'}`}>
                        <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                        Cassavas Tower 01
                      </div>
                      <div className={`text-[11px] ${buildingNightMode ? 'text-slate-400' : 'text-neutral-500'}`}>
                        26 Tầng • 260 Căn hộ
                      </div>
                    </div>
                  </div>

                  {/* Right Main Content Panel */}
                  <div className={`col-span-12 sm:col-span-8 p-5 flex flex-col justify-between transition-colors duration-700 ${
                    buildingNightMode ? 'bg-[#0B132B] text-slate-100' : 'bg-white text-neutral-900'
                  }`}>
                    {activeTab === 'overview' && (
                      <div className="space-y-5 animate-in fade-in duration-200">
                        {/* Section Subtitle */}
                        <div className="flex items-center justify-between">
                          <span className={`text-xs font-semibold ${buildingNightMode ? 'text-slate-300' : 'text-neutral-700'}`}>
                            Tổng quan vận hành
                          </span>
                          <span className="text-[11px] text-neutral-400 font-mono">Cập nhật 1 phút trước</span>
                        </div>

                        {/* Top Metric Cards */}
                        <div className="grid grid-cols-3 gap-3">
                          <div className={`p-3 border rounded-sm transition-all duration-300 hover:scale-[1.02] ${
                            buildingNightMode
                              ? 'bg-slate-800/80 border-slate-700/80'
                              : 'bg-neutral-50/50 border-neutral-200 hover:bg-neutral-50'
                          }`}>
                            <div className="text-[11px] text-neutral-400 font-medium">Cư dân</div>
                            <div className={`text-2xl font-bold mt-1 tracking-tight ${buildingNightMode ? 'text-white' : 'text-neutral-900'}`}>
                              248
                            </div>
                            <div className="text-[10px] text-emerald-500 font-medium flex items-center gap-0.5 mt-0.5">
                              <TrendingUp className="w-2.5 h-2.5" /> +8 căn mới
                            </div>
                          </div>

                          <div className={`p-3 border rounded-sm transition-all duration-300 hover:scale-[1.02] ${
                            buildingNightMode
                              ? 'bg-slate-800/80 border-slate-700/80'
                              : 'bg-neutral-50/50 border-neutral-200 hover:bg-neutral-50'
                          }`}>
                            <div className="text-[11px] text-neutral-400 font-medium">Đã thu</div>
                            <div className={`text-2xl font-bold mt-1 tracking-tight ${buildingNightMode ? 'text-white' : 'text-neutral-900'}`}>
                              84%
                            </div>
                            <div className="w-full bg-neutral-200/50 h-1.5 rounded-full mt-1.5 overflow-hidden">
                              <div
                                className={`h-full rounded-full w-[84%] transition-all duration-700 ${
                                  buildingNightMode ? 'bg-cyan-400 shadow-[0_0_8px_rgba(34,211,238,0.8)]' : 'bg-neutral-900'
                                }`}
                              />
                            </div>
                          </div>

                          <div className={`p-3 border rounded-sm transition-all duration-300 hover:scale-[1.02] ${
                            buildingNightMode
                              ? 'bg-slate-800/80 border-slate-700/80'
                              : 'bg-neutral-50/50 border-neutral-200 hover:bg-neutral-50'
                          }`}>
                            <div className="text-[11px] text-neutral-400 font-medium">Ticket</div>
                            <div className={`text-2xl font-bold mt-1 tracking-tight ${buildingNightMode ? 'text-white' : 'text-neutral-900'}`}>
                              12
                            </div>
                            <div className="text-[10px] text-amber-500 font-medium flex items-center gap-0.5 mt-0.5">
                              <Clock className="w-2.5 h-2.5" /> 3 chờ duyệt
                            </div>
                          </div>
                        </div>

                        {/* Dynamic Bar Chart Box */}
                        <div className={`border rounded-sm p-4 relative transition-colors duration-700 ${
                          buildingNightMode ? 'bg-slate-900/90 border-slate-800' : 'bg-white border-neutral-200'
                        }`}>
                          <div className="flex items-center justify-between mb-4">
                            <div className={`text-xs font-semibold ${buildingNightMode ? 'text-slate-200' : 'text-neutral-800'}`}>
                              Doanh thu tháng
                            </div>
                            <span className={`text-[11px] font-semibold px-2 py-0.5 rounded border flex items-center gap-1 ${
                              buildingNightMode
                                ? 'bg-emerald-950/80 text-emerald-300 border-emerald-800/50'
                                : 'text-emerald-700 bg-emerald-50 border-emerald-200'
                            }`}>
                              <TrendingUp className="w-3 h-3" />
                              +12.4%
                            </span>
                          </div>

                          {/* Interactive Bars with Hover Glow */}
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
                                    <div className="absolute -top-8 bg-neutral-900 text-white text-[10px] py-0.5 px-1.5 rounded shadow-lg whitespace-nowrap z-20 pointer-events-none animate-in zoom-in-95 duration-100">
                                      {item.month}: {item.amount}
                                    </div>
                                  )}

                                  {/* Bar column */}
                                  <div className={`w-full rounded-t-sm h-full flex items-end ${
                                    buildingNightMode ? 'bg-slate-800/60' : 'bg-neutral-100'
                                  }`}>
                                    <div
                                      style={{ height: `${heightPct}%` }}
                                      className={`w-full rounded-t-sm transition-all duration-300 ${
                                        buildingNightMode
                                          ? idx === 9 || isHovered
                                            ? 'bg-cyan-400 shadow-[0_0_12px_rgba(34,211,238,0.7)] scale-y-105'
                                            : 'bg-sky-600/70 group-hover:bg-cyan-500'
                                          : idx === 9 || isHovered
                                          ? 'bg-neutral-950 scale-y-105'
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
                          <span className={`text-xs font-semibold ${buildingNightMode ? 'text-slate-200' : 'text-neutral-800'}`}>
                            Quản lý cư dân & Căn hộ
                          </span>
                          <span className="text-[11px] text-emerald-500 bg-emerald-50/80 px-2 py-0.5 rounded font-medium">
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
                              className={`p-2.5 border rounded flex items-center justify-between transition-all duration-200 hover:translate-x-1 ${
                                buildingNightMode
                                  ? 'border-slate-800 hover:bg-slate-800/80 bg-slate-900/40'
                                  : 'border-neutral-200 hover:bg-neutral-50 bg-white'
                              }`}
                            >
                              <div>
                                <div className={`text-xs font-semibold ${buildingNightMode ? 'text-slate-100' : 'text-neutral-900'}`}>
                                  {res.room} - {res.owner}
                                </div>
                                <div className="text-[11px] text-neutral-400">{res.count}</div>
                              </div>
                              <span className={`text-[10px] font-medium px-2 py-0.5 rounded ${
                                buildingNightMode ? 'bg-slate-800 text-slate-300' : 'bg-neutral-100 text-neutral-700'
                              }`}>
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
                          <span className={`text-xs font-semibold ${buildingNightMode ? 'text-slate-200' : 'text-neutral-800'}`}>
                            Doanh thu & Thu hộ tháng 9
                          </span>
                          <span className={`text-xs font-bold font-mono ${buildingNightMode ? 'text-cyan-400' : 'text-neutral-950'}`}>
                            1,040,000,000 đ
                          </span>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                          <div className={`p-2.5 border rounded ${
                            buildingNightMode ? 'border-slate-800 bg-slate-900/60' : 'border-neutral-200 bg-neutral-50'
                          }`}>
                            <div className="text-[11px] text-neutral-400">Phí quản lý & dịch vụ</div>
                            <div className={`text-sm font-bold mt-1 ${buildingNightMode ? 'text-white' : 'text-neutral-900'}`}>
                              680.5 tr đ
                            </div>
                            <div className="text-[10px] text-emerald-500 font-medium mt-0.5">88% đã thanh toán</div>
                          </div>
                          <div className={`p-2.5 border rounded ${
                            buildingNightMode ? 'border-slate-800 bg-slate-900/60' : 'border-neutral-200 bg-neutral-50'
                          }`}>
                            <div className="text-[11px] text-neutral-400">Điện, nước, gửi xe</div>
                            <div className={`text-sm font-bold mt-1 ${buildingNightMode ? 'text-white' : 'text-neutral-900'}`}>
                              359.5 tr đ
                            </div>
                            <div className="text-[10px] text-emerald-500 font-medium mt-0.5">79% đã thanh toán</div>
                          </div>
                        </div>
                        <div className={`p-3 border border-dashed rounded text-center ${
                          buildingNightMode ? 'border-slate-700 bg-slate-900/30' : 'border-neutral-300'
                        }`}>
                          <span className={`text-xs font-medium ${buildingNightMode ? 'text-slate-300' : 'text-neutral-600'}`}>
                            ⚡ Tích hợp QR Napas 24/7 tự động gạch nợ tức thời
                          </span>
                        </div>
                      </div>
                    )}

                    {/* Maintenance Tab Preview */}
                    {activeTab === 'maintenance' && (
                      <div className="space-y-4 animate-in fade-in duration-200">
                        <div className="flex items-center justify-between">
                          <span className={`text-xs font-semibold ${buildingNightMode ? 'text-slate-200' : 'text-neutral-800'}`}>
                            Quy trình sự cố & bảo trì
                          </span>
                          <span className="text-[11px] text-amber-500 bg-amber-500/10 px-2 py-0.5 rounded font-medium">
                            3 cần xử lý
                          </span>
                        </div>
                        <div className="space-y-2">
                          {[
                            { title: 'Kiểm tra áp lực nước tầng 18', tag: 'Kỹ thuật nước', state: 'Đang xử lý', time: '10p trước' },
                            { title: 'Bảo trì hệ thống PCCC định kỳ', tag: 'An toàn PCCC', state: 'Đã hoàn thành', time: 'Hôm qua' },
                            { title: 'Thay đèn chiếu sáng sảnh B2', tag: 'Hạ tầng', state: 'Chờ vật tư', time: '2 giờ trước' },
                          ].map((t, idx) => (
                            <div
                              key={idx}
                              className={`p-2.5 border rounded flex items-center justify-between transition-all duration-200 hover:translate-x-1 ${
                                buildingNightMode ? 'border-slate-800 bg-slate-900/40 hover:bg-slate-800/60' : 'border-neutral-200 hover:bg-neutral-50'
                              }`}
                            >
                              <div>
                                <div className={`text-xs font-medium ${buildingNightMode ? 'text-slate-100' : 'text-neutral-900'}`}>
                                  {t.title}
                                </div>
                                <div className="text-[10px] text-neutral-400">{t.tag} • {t.time}</div>
                              </div>
                              <span
                                className={`text-[10px] font-medium px-2 py-0.5 rounded ${
                                  t.state === 'Đã hoàn thành'
                                    ? 'bg-emerald-500/10 text-emerald-500'
                                    : t.state === 'Đang xử lý'
                                    ? 'bg-blue-500/10 text-blue-400'
                                    : 'bg-neutral-500/10 text-neutral-400'
                                }`}
                              >
                                {t.state}
                              </span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Status Bottom Bar with Live Feedback */}
                    <div className={`mt-4 pt-3 border-t flex flex-wrap items-center justify-between gap-2 text-xs transition-colors duration-700 ${
                      buildingNightMode ? 'border-slate-800 text-slate-400' : 'border-neutral-200 text-neutral-500'
                    }`}>
                      <div className="flex items-center gap-2">
                        <span className="relative flex h-2 w-2">
                          <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
                          <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
                        </span>
                        <span className={`font-medium ${buildingNightMode ? 'text-slate-200' : 'text-neutral-700'}`}>
                          Hệ thống đang hoạt động ổn định
                        </span>
                      </div>

                      {/* Live Animated Car Simulation Badge when Barrier opened */}
                      {carPassAnimation && (
                        <div className="flex items-center gap-1.5 px-2 py-0.5 rounded bg-emerald-500/15 border border-emerald-500/30 text-emerald-500 text-[11px] animate-in fade-in slide-in-from-left duration-300">
                          <Car className="w-3.5 h-3.5 animate-bounce" />
                          <span className="font-semibold">Xe 29A-882.11 qua cổng thành công</span>
                        </div>
                      )}

                      <div className="hidden sm:flex items-center gap-2 font-mono text-[11px] text-neutral-400">
                        <span>Ping 24ms</span>
                        <span>•</span>
                        <span>{liveUptimeSeconds}s live</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Interactive Morphicons Quick Controls Bar with Glassmorphism */}
              <div className="mt-3 p-3.5 glass-panel rounded-xl shadow-sm hover:shadow-md transition-all glass-specular-edge">
                <div className="flex flex-wrap items-center justify-between gap-2 mb-2.5">
                  <div className="flex items-center gap-2">
                    <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                    <span className="text-[11px] font-bold text-neutral-800 uppercase tracking-wider">
                      Bảng điều khiển vi chuyển động thời gian thực (Morphicons)
                    </span>
                  </div>

                  {/* Auto-Cycle Showcase Button */}
                  <button
                    onClick={() => setIsAutoSimulating(!isAutoSimulating)}
                    className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium transition-all shadow-xs active:scale-95 ${
                      isAutoSimulating
                        ? 'bg-rose-500 text-white shadow-rose-500/30 animate-pulse'
                        : 'bg-neutral-900 text-white hover:bg-neutral-800'
                    }`}
                    title="Bật/Tắt tự động chuyển động tất cả biểu tượng morphing liên tục"
                  >
                    <MorphIcon
                      icon={isAutoSimulating ? PauseData : PlayData}
                      size={12}
                      strokeWidth={2.5}
                      spring="bouncy"
                    />
                    <span>{isAutoSimulating ? 'Dừng tự động' : 'Tự động trình diễn'}</span>
                  </button>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                  {/* Control 1: Day/Night Mode */}
                  <button
                    onClick={() => setBuildingNightMode(!buildingNightMode)}
                    className={`p-2.5 rounded-md border transition-all flex items-center justify-between group active:scale-95 text-left ${
                      buildingNightMode
                        ? 'bg-indigo-950/20 border-indigo-300 ring-1 ring-indigo-400/40'
                        : 'bg-neutral-50/80 border-neutral-200 hover:bg-neutral-100'
                    }`}
                    title="Chuyển chế độ giao diện và chiếu sáng tòa nhà"
                  >
                    <div className="flex flex-col">
                      <span className="text-[11px] font-semibold text-neutral-900">
                        {buildingNightMode ? 'Chế độ Đêm' : 'Chế độ Ngày'}
                      </span>
                      <span className="text-[10px] text-neutral-500">Giao diện Cockpit</span>
                    </div>
                    <div className="w-7 h-7 rounded-full bg-white shadow-xs border border-neutral-200 flex items-center justify-center text-neutral-800 group-hover:border-neutral-400 transition-colors">
                      <MorphIcon
                        icon={buildingNightMode ? MoonData : SunData}
                        size={15}
                        strokeWidth={2}
                        spring="snappy"
                        className={buildingNightMode ? 'text-indigo-600' : 'text-amber-500'}
                      />
                    </div>
                  </button>

                  {/* Control 2: Barrier Gate */}
                  <button
                    onClick={handleGateToggle}
                    className={`p-2.5 rounded-md border transition-all flex items-center justify-between group active:scale-95 text-left ${
                      !gateLocked
                        ? 'bg-emerald-500/10 border-emerald-300 ring-1 ring-emerald-400/40'
                        : 'bg-neutral-50/80 border-neutral-200 hover:bg-neutral-100'
                    }`}
                    title="Khóa / Mở barrier tự động"
                  >
                    <div className="flex flex-col">
                      <span className="text-[11px] font-semibold text-neutral-900">
                        {gateLocked ? 'Barrier Đóng' : 'Barrier Mở'}
                      </span>
                      <span className="text-[10px] text-neutral-500">Cổng kiểm soát</span>
                    </div>
                    <div className="w-7 h-7 rounded-full bg-white shadow-xs border border-neutral-200 flex items-center justify-center text-neutral-800 group-hover:border-neutral-400 transition-colors">
                      <MorphIcon
                        icon={gateLocked ? LockData : UnlockData}
                        size={15}
                        strokeWidth={2}
                        spring="snappy"
                        className={gateLocked ? 'text-rose-600' : 'text-emerald-600'}
                      />
                    </div>
                  </button>

                  {/* Control 3: Security Patrol */}
                  <button
                    onClick={() => setHighAlertMode(!highAlertMode)}
                    className={`p-2.5 rounded-md border transition-all flex items-center justify-between group active:scale-95 text-left ${
                      highAlertMode
                        ? 'bg-amber-500/10 border-amber-300 ring-1 ring-amber-400/40'
                        : 'bg-neutral-50/80 border-neutral-200 hover:bg-neutral-100'
                    }`}
                    title="Bật / tắt chế độ tuần tra an ninh"
                  >
                    <div className="flex flex-col">
                      <span className="text-[11px] font-semibold text-neutral-900">
                        {highAlertMode ? 'Tuần tra cao điểm' : 'An toàn chuẩn'}
                      </span>
                      <span className="text-[10px] text-neutral-500">Radar bảo vệ</span>
                    </div>
                    <div className="w-7 h-7 rounded-full bg-white shadow-xs border border-neutral-200 flex items-center justify-center text-neutral-800 group-hover:border-neutral-400 transition-colors">
                      <MorphIcon
                        icon={highAlertMode ? ShieldAlertData : ShieldCheckData}
                        size={15}
                        strokeWidth={2}
                        spring="bouncy"
                        className={highAlertMode ? 'text-amber-600' : 'text-blue-600'}
                      />
                    </div>
                  </button>

                  {/* Control 4: EV Charging */}
                  <button
                    onClick={() => setEvChargingActive(!evChargingActive)}
                    className={`p-2.5 rounded-md border transition-all flex items-center justify-between group active:scale-95 text-left ${
                      evChargingActive
                        ? 'bg-amber-500/10 border-amber-300 ring-1 ring-amber-400/40'
                        : 'bg-neutral-50/80 border-neutral-200 hover:bg-neutral-100'
                    }`}
                    title="Bật / tắt trạm sạc xe điện"
                  >
                    <div className="flex flex-col">
                      <span className="text-[11px] font-semibold text-neutral-900">
                        {evChargingActive ? 'Đang sạc nhanh' : 'Đã sạc đầy'}
                      </span>
                      <span className="text-[10px] text-neutral-500">Trạm sạc hầm B1</span>
                    </div>
                    <div className="w-7 h-7 rounded-full bg-white shadow-xs border border-neutral-200 flex items-center justify-center text-neutral-800 group-hover:border-neutral-400 transition-colors">
                      <MorphIcon
                        icon={evChargingActive ? ZapData : CheckCircle2Data}
                        size={15}
                        strokeWidth={2}
                        spring="snappy"
                        className={evChargingActive ? 'text-amber-500' : 'text-emerald-600'}
                      />
                    </div>
                  </button>
                </div>
              </div>
            </div>
          </div>

        </div>
      </main>

      {/* ================= 3D DIGITAL TWIN ARCHITECTURAL MODEL ================= */}
      <section id="model3d" className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24 border-t border-neutral-200/80 relative">
        <div className="space-y-4 mb-10">
          <div className="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full glass-pill text-xs font-bold text-neutral-800 tracking-wider uppercase shadow-xs">
            <span className="w-2 h-2 rounded-full bg-cyan-500 animate-pulse" />
            <span className="animate-text-light-brand font-bold">Bản sao kỹ thuật số 3D • Digital Twin Building OS</span>
          </div>

          <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div>
              <h2 className="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight text-neutral-950 animate-text-light">
                Trực quan hóa toàn bộ tòa nhà trong không gian 3D.
              </h2>
              <p className="text-base sm:text-lg text-neutral-600 max-w-2xl mt-2.5 font-normal leading-relaxed">
                Khám phá tháp đôi 26 tầng, giám sát năng lượng, cảm biến an ninh PCCC, thang máy kính xuyên thấu và trạng thái phân tầng trong thời gian thực.
              </p>
            </div>

            <div className="flex items-center gap-2.5">
              <button
                onClick={() => setBuildingNightMode(!buildingNightMode)}
                className="px-3.5 py-2 rounded-lg glass-pill text-xs font-semibold text-neutral-800 hover:text-neutral-950 border border-neutral-300 shadow-xs hover:scale-105 active:scale-95 transition-all flex items-center gap-2"
              >
                <MorphIcon
                  icon={buildingNightMode ? MoonData : SunData}
                  size={14}
                  strokeWidth={2}
                  spring="snappy"
                  className={buildingNightMode ? 'text-indigo-600' : 'text-amber-500'}
                />
                <span>{buildingNightMode ? 'Chế độ Đêm' : 'Chế độ Ngày'}</span>
              </button>

              <button
                onClick={() => setHighAlertMode(!highAlertMode)}
                className={`px-3.5 py-2 rounded-lg text-xs font-semibold border shadow-xs hover:scale-105 active:scale-95 transition-all flex items-center gap-2 ${
                  highAlertMode
                    ? 'bg-amber-500 text-slate-950 border-amber-600 font-bold'
                    : 'glass-pill text-neutral-800 border-neutral-300'
                }`}
              >
                <MorphIcon
                  icon={highAlertMode ? ShieldAlertData : ShieldCheckData}
                  size={14}
                  strokeWidth={2}
                  spring="bouncy"
                  className={highAlertMode ? 'text-slate-950' : 'text-blue-600'}
                />
                <span>{highAlertMode ? 'Radar Quét 24/7' : 'Quét An ninh'}</span>
              </button>
            </div>
          </div>
        </div>

        {/* 3D Model Component */}
        <Building3DModel
          nightMode={buildingNightMode}
          highAlert={highAlertMode}
          isCompact={false}
        />
      </section>

      {/* ================= CORE CAPABILITIES / NĂNG LỰC CỐT LÕI ================= */}
      <section id="features" className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24 border-t border-neutral-200/80">
        {/* Section Header */}
        <div className="space-y-3 mb-12">
          <div className="inline-flex items-center gap-2">
            <span className="text-xs font-bold tracking-widest text-neutral-700 uppercase">
              Năng lực cốt lõi |
            </span>
          </div>
          <h2 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-neutral-950 animate-text-light">
            Vận hành gọn hơn, sống tốt hơn.
          </h2>
        </div>

        {/* 3 Core Capability Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">

          {/* Card 1: Finance / Billing */}
          <div
            onMouseEnter={() => setHoveredFeatureCard(1)}
            onMouseLeave={() => setHoveredFeatureCard(null)}
            className="group relative glass-panel rounded-xl p-7 flex flex-col justify-between hover:border-neutral-950 hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-300 cursor-pointer overflow-hidden glass-specular-edge"
          >
            {/* Ambient hover top glow */}
            <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-emerald-400 via-sky-400 to-indigo-500 opacity-0 group-hover:opacity-100 transition-opacity duration-300" />

            <div>
              {/* Icon with Morph animation */}
              <div className="w-12 h-12 rounded-lg bg-neutral-50/80 border border-neutral-200/80 flex items-center justify-center text-neutral-900 mb-6 group-hover:bg-neutral-950 group-hover:text-white group-hover:border-neutral-950 group-hover:scale-110 group-hover:rotate-1 transition-all duration-300 shadow-xs">
                <MorphIcon
                  icon={hoveredFeatureCard === 1 ? SendData : MailData}
                  size={22}
                  strokeWidth={1.8}
                  spring="bouncy"
                />
              </div>

              {/* Title */}
              <h3 className="text-lg font-bold text-neutral-950 mb-2 group-hover:text-neutral-950">
                Quản lý tài chính tự động
              </h3>

              {/* Body */}
              <p className="text-sm text-neutral-600 leading-relaxed font-normal">
                Chốt số, sinh hóa đơn hàng loạt và theo dõi công nợ. Rê chuột để xem biểu tượng chuyển động gửi biên lai.
              </p>
            </div>

            {/* Bottom Module Tag */}
            <div className="mt-10 pt-5 border-t border-neutral-200/60 flex items-center justify-between">
              <span className="text-[11px] font-semibold tracking-wider text-neutral-500 uppercase group-hover:text-neutral-950 transition-colors">
                Module Tài chính
              </span>
              <div className="w-6 h-6 rounded-full bg-neutral-100 flex items-center justify-center group-hover:bg-neutral-950 group-hover:text-white transition-all">
                <MorphIcon
                  icon={hoveredFeatureCard === 1 ? CheckData : ArrowRightData}
                  size={12}
                  strokeWidth={2.5}
                  spring="snappy"
                />
              </div>
            </div>
          </div>

          {/* Card 2: Operations / Maintenance */}
          <div
            onMouseEnter={() => setHoveredFeatureCard(2)}
            onMouseLeave={() => setHoveredFeatureCard(null)}
            className="group relative glass-panel rounded-xl p-7 flex flex-col justify-between hover:border-neutral-950 hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-300 cursor-pointer overflow-hidden glass-specular-edge"
          >
            {/* Ambient hover top glow */}
            <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-amber-400 via-orange-500 to-rose-500 opacity-0 group-hover:opacity-100 transition-opacity duration-300" />

            <div>
              {/* Icon with Morph animation */}
              <div className="w-12 h-12 rounded-lg bg-neutral-50/80 border border-neutral-200/80 flex items-center justify-center text-neutral-900 mb-6 group-hover:bg-neutral-950 group-hover:text-white group-hover:border-neutral-950 group-hover:scale-110 group-hover:-rotate-1 transition-all duration-300 shadow-xs">
                <MorphIcon
                  icon={hoveredFeatureCard === 2 ? SettingsData : WrenchData}
                  size={22}
                  strokeWidth={1.8}
                  spring="bouncy"
                />
              </div>

              {/* Title */}
              <h3 className="text-lg font-bold text-neutral-950 mb-2">
                Quy trình xử lý sự cố
              </h3>

              {/* Body */}
              <p className="text-sm text-neutral-600 leading-relaxed font-normal">
                Báo cáo tức thì, theo dõi tiến độ sửa chữa trực quan. Rê chuột để xem biểu tượng chuyển sang cấu hình bảo dưỡng.
              </p>
            </div>

            {/* Bottom Module Tag */}
            <div className="mt-10 pt-5 border-t border-neutral-200/60 flex items-center justify-between">
              <span className="text-[11px] font-semibold tracking-wider text-neutral-500 uppercase group-hover:text-neutral-950 transition-colors">
                Module Vận hành
              </span>
              <div className="w-6 h-6 rounded-full bg-neutral-100 flex items-center justify-center group-hover:bg-neutral-950 group-hover:text-white transition-all">
                <MorphIcon
                  icon={hoveredFeatureCard === 2 ? CheckData : ArrowRightData}
                  size={12}
                  strokeWidth={2.5}
                  spring="snappy"
                />
              </div>
            </div>
          </div>

          {/* Card 3: Security & Reception */}
          <div
            onMouseEnter={() => setHoveredFeatureCard(3)}
            onMouseLeave={() => setHoveredFeatureCard(null)}
            className="group relative glass-panel rounded-xl p-7 flex flex-col justify-between hover:border-neutral-950 hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-300 cursor-pointer overflow-hidden glass-specular-edge"
          >
            {/* Ambient hover top glow */}
            <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-sky-400 via-indigo-500 to-purple-500 opacity-0 group-hover:opacity-100 transition-opacity duration-300" />

            <div>
              {/* Icon with Morph animation */}
              <div className="w-12 h-12 rounded-lg bg-neutral-50/80 border border-neutral-200/80 flex items-center justify-center text-neutral-900 mb-6 group-hover:bg-neutral-950 group-hover:text-white group-hover:border-neutral-950 group-hover:scale-110 group-hover:rotate-1 transition-all duration-300 shadow-xs">
                <MorphIcon
                  icon={hoveredFeatureCard === 3 ? LockData : ShieldCheckData}
                  size={22}
                  strokeWidth={1.8}
                  spring="bouncy"
                />
              </div>

              {/* Title */}
              <h3 className="text-lg font-bold text-neutral-950 mb-2">
                An ninh & bưu phẩm
              </h3>

              {/* Body */}
              <p className="text-sm text-neutral-600 leading-relaxed font-normal">
                Thông báo nhận hàng và kiểm soát khách ra vào chặt chẽ. Rê chuột để xem kích hoạt trạng thái khóa an toàn.
              </p>
            </div>

            {/* Bottom Module Tag */}
            <div className="mt-10 pt-5 border-t border-neutral-200/60 flex items-center justify-between">
              <span className="text-[11px] font-semibold tracking-wider text-neutral-500 uppercase group-hover:text-neutral-950 transition-colors">
                Module Lễ tân
              </span>
              <div className="w-6 h-6 rounded-full bg-neutral-100 flex items-center justify-center group-hover:bg-neutral-950 group-hover:text-white transition-all">
                <MorphIcon
                  icon={hoveredFeatureCard === 3 ? CheckData : ArrowRightData}
                  size={12}
                  strokeWidth={2.5}
                  spring="snappy"
                />
              </div>
            </div>
          </div>

        </div>
      </section>

      {/* ================= INTERACTIVE BANNER / CTA ================= */}
      <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div className="bg-neutral-950 text-white rounded-xl p-8 sm:p-14 relative overflow-hidden flex flex-col lg:flex-row items-center justify-between gap-8 shadow-2xl border border-neutral-800">
          <div className="space-y-3 text-center lg:text-left z-10">
            <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-neutral-800/80 border border-neutral-700/60 text-[11px] text-cyan-300 font-medium">
              <span className="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-ping" />
              <span>Khởi động chuyển đổi số thông minh</span>
            </div>
            <h3 className="text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight leading-tight">
              Sẵn sàng chuyển đổi số <br className="hidden sm:inline" /> cho tòa nhà của bạn?
            </h3>
            <p className="text-neutral-400 text-sm sm:text-base max-w-xl leading-relaxed">
              Đồng bộ dữ liệu cư dân, tự động hóa hóa đơn và số hóa quy trình vận hành chỉ trong 24 giờ triển khai.
            </p>
          </div>
          <div className="flex flex-wrap items-center justify-center gap-4 z-10">
            <button
              onClick={() => setAuthModal('register')}
              className="bg-white hover:bg-neutral-100 text-neutral-950 text-sm font-semibold px-7 py-3.5 rounded-sm transition-all shadow-md hover:shadow-lg active:scale-95"
            >
              Trải nghiệm miễn phí
            </button>
            <a
              href="#contact"
              onClick={(e) => {
                e.preventDefault();
                scrollToSection('contact');
              }}
              className="border border-neutral-700 hover:border-neutral-400 text-white text-sm font-medium px-7 py-3.5 rounded-sm transition-all hover:bg-white/5 active:scale-95"
            >
              Tư vấn chuyên sâu
            </a>
          </div>

          {/* Animated Multi-layer Aurora Glow in dark banner */}
          <div className="absolute -right-20 -bottom-20 w-96 h-96 bg-gradient-to-tl from-indigo-600/30 via-sky-500/20 to-transparent rounded-full blur-3xl pointer-events-none animate-float-orb opacity-70" />
          <div className="absolute -left-20 -top-20 w-80 h-80 bg-gradient-to-br from-purple-600/20 via-blue-500/15 to-transparent rounded-full blur-3xl pointer-events-none animate-float-orb-reverse opacity-60" />
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

      {/* Toast Feedback for Login */}
      {loginFeedback && (
        <div className="fixed bottom-6 right-6 z-50 glass-panel rounded-xl px-4 py-3 shadow-xl border border-white/90 flex items-center gap-3 animate-in slide-in-from-bottom-5 duration-200">
          <div className="w-8 h-8 rounded-full bg-emerald-500 text-white flex items-center justify-center font-bold text-sm shadow-sm">
            ✓
          </div>
          <div>
            <div className="text-xs font-bold text-neutral-900">Xác thực thành công</div>
            <div className="text-[11px] text-neutral-600">{loginFeedback}</div>
          </div>
        </div>
      )}

      {/* ================= ULTRA GLASSMORPHISM AUTH MODAL ================= */}
      <AuthModal
        isOpen={authModal !== null}
        initialMode={authModal || 'login'}
        onClose={closeAuth}
        onSuccess={(role, userEmail, userObj) => {
          const user = userObj || api.getUser();
          if (user) {
            setCurrentUser(user);
          }
          setLoginFeedback(`Đã đăng nhập thành công với vai trò: ${role} (${user?.full_name || userEmail})`);
          setTimeout(() => setLoginFeedback(null), 4000);
          if (onLoginSuccess) {
            onLoginSuccess(role, userEmail);
          }
        }}
      />
    </div>
  );
};

export default Home;
