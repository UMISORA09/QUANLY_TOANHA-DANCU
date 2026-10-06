import React, { useState, useEffect } from 'react';
import {
  Building2,
  Lock,
  Mail,
  Eye,
  EyeOff,
  ArrowRight,
  ShieldCheck,
  LayoutGrid,
  Users,
  Receipt,
  ArrowLeft,
  CheckCircle2,
  Sparkles,
  AlertCircle
} from 'lucide-react';
import { api } from '../../Services/api';

export type UserRole = 'manager' | 'resident' | 'receptionist' | 'admin';

interface LoginPageProps {
  onNavigate: (path: string) => void;
  onLoginSuccess: (role: string, email: string, residentType?: 'OWNER' | 'TENANT') => void;
}

const DEMO_ROLES: Array<{
  role: UserRole;
  title: string;
  badge: string;
  email: string;
  pass: string;
  icon: any;
}> = [
  {
    role: 'admin',
    title: 'Admin / IT',
    badge: 'Toàn quyền',
    email: 'admin@cassavas.vn',
    pass: '123567',
    icon: ShieldCheck,
  },
  {
    role: 'manager',
    title: 'Ban Quản Lý',
    badge: 'Vận hành',
    email: 'quanly@cassavas.vn',
    pass: '123567',
    icon: LayoutGrid,
  },
  {
    role: 'receptionist',
    title: 'Lễ Tân',
    badge: 'Khách & Xe',
    email: 'letan@cassavas.vn',
    pass: '123567',
    icon: Receipt,
  },
  {
    role: 'resident',
    title: 'Cư Dân',
    badge: 'Căn hộ A-1204',
    email: 'nguyenvanan@cassavas.vn',
    pass: '123567',
    icon: Users,
  },
];

export const LoginPage: React.FC<LoginPageProps> = ({ onNavigate, onLoginSuccess }) => {
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(true);
  const [selectedRole, setSelectedRole] = useState<UserRole | null>(null);

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const selectDemoAccount = (item: typeof DEMO_ROLES[0]) => {
    setSelectedRole(item.role);
    setIdentifier(item.email);
    setPassword(item.pass);
    setErrorMessage(null);
  };

  useEffect(() => {
    try {
      const urlRole = new URLSearchParams(window.location.search).get('role');
      if (urlRole) {
        const found = DEMO_ROLES.find(
          (d) => d.role.toLowerCase() === urlRole.toLowerCase()
        );
        if (found) {
          selectDemoAccount(found);
        }
      }
    } catch {
      // ignore
    }
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    const cleanIdentifier = identifier.trim();
    if (!cleanIdentifier || !password) {
      setErrorMessage('Vui lòng nhập đầy đủ tên đăng nhập / email và mật khẩu.');
      return;
    }

    setIsLoading(true);

    try {
      const res = await api.login(cleanIdentifier, password);
      setSuccessMessage('Đăng nhập thành công! Đang chuyển hướng...');

      const roleFound = (
        res.user?.roles?.[0]?.toLowerCase().includes('admin')
          ? 'admin'
          : res.user?.roles?.[0] || selectedRole || 'resident'
      ) as string;

      const residentTypeFound = (res.user?.resident_type === 'TENANT' ? 'TENANT' : 'OWNER') as ('OWNER' | 'TENANT');

      onLoginSuccess(roleFound, res.user?.email || cleanIdentifier, residentTypeFound);
    } catch (err: any) {
      setErrorMessage(
        err.message || 'Đăng nhập không thành công. Vui lòng kiểm tra lại thông tin tài khoản.'
      );
    } finally {
      setIsLoading(false);
    }
  };



  return (
    <div className="min-h-screen bg-[#FAFAFA] text-[#171717] selection:bg-neutral-900 selection:text-white font-sans relative overflow-x-hidden flex flex-col justify-between">
      {/* Background Atmosphere & Ambient Glows */}
      <div className="fixed inset-0 pointer-events-none overflow-hidden -z-10">
        <div className="absolute -top-40 -left-40 w-[600px] h-[600px] bg-gradient-to-br from-blue-100/50 via-sky-100/30 to-transparent rounded-full blur-3xl opacity-75" />
        <div className="absolute top-1/4 -right-48 w-[650px] h-[650px] bg-gradient-to-bl from-slate-200/50 via-indigo-100/30 to-transparent rounded-full blur-3xl opacity-70" />
        <div className="absolute bottom-10 left-1/3 w-[500px] h-[500px] bg-gradient-to-t from-sky-100/40 via-emerald-50/20 to-transparent rounded-full blur-3xl opacity-60" />

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
      <header className="sticky top-0 z-40 bg-white/75 backdrop-blur-xl border-b border-white/50 shadow-xs transition-all">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
          {/* Logo */}
          <a
            href="/home"
            onClick={(e) => {
              e.preventDefault();
              onNavigate('/home');
            }}
            className="flex items-center gap-3 group"
          >
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

          {/* Navigation Actions */}
          <div className="flex items-center gap-3">
            <button
              onClick={() => onNavigate('/home')}
              className="text-sm font-medium text-neutral-700 hover:text-neutral-950 px-3 py-2 rounded-md transition-colors flex items-center gap-1.5 cursor-pointer"
            >
              <ArrowLeft className="w-4 h-4" />
              <span className="hidden sm:inline">Về trang chủ</span>
            </button>
            <button
              onClick={() => onNavigate('/register')}
              className="text-sm font-medium text-neutral-900 bg-neutral-100 hover:bg-neutral-200 active:scale-95 px-4 py-2 rounded-md transition-all cursor-pointer"
            >
              Đăng ký
            </button>
          </div>
        </div>
      </header>

      {/* Main Login Card Area */}
      <main className="relative z-10 flex-1 flex items-center justify-center px-4 py-10 sm:py-14">
        <div className="w-full max-w-md">
          {/* Card */}
          <div className="bg-white/90 backdrop-blur-2xl border border-neutral-200/90 rounded-2xl p-7 sm:p-9 shadow-xl relative overflow-hidden">
            {/* Title & Subtitle */}
            <div className="text-center mb-7">
              <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-neutral-100 border border-neutral-200 text-neutral-800 text-xs font-semibold mb-3">
                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                <span>Cổng Xác Thực An Toàn</span>
              </div>
              <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-neutral-950 mb-2">
                Đăng nhập tài khoản
              </h1>
              <p className="text-xs sm:text-sm text-neutral-500 leading-relaxed">
                Nhập thông tin tài khoản để truy cập không gian làm việc của bạn
              </p>
            </div>

            {/* Quick Demo Selector */}
            <div className="mb-6">
              <div className="flex items-center justify-between mb-2">
                <span className="text-[11px] font-bold text-neutral-900 uppercase tracking-wider flex items-center gap-1.5">
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                  Tài khoản dùng thử nhanh:
                </span>
                <span className="text-[10px] text-neutral-500 font-mono">1-click điền</span>
              </div>
              <div className="grid grid-cols-2 gap-2">
                {DEMO_ROLES.map((item) => {
                  const Icon = item.icon;
                  const isSelected = selectedRole === item.role;
                  return (
                    <button
                      key={item.role}
                      type="button"
                      onClick={() => selectDemoAccount(item)}
                      className={`p-2.5 rounded-xl border text-left transition-all flex items-center gap-2 cursor-pointer ${
                        isSelected
                          ? 'bg-neutral-950 text-white border-neutral-950 shadow-sm'
                          : 'bg-white hover:bg-neutral-50 border-neutral-200 text-neutral-900 hover:border-neutral-300'
                      }`}
                    >
                      <div
                        className={`w-7 h-7 rounded-lg flex items-center justify-center shrink-0 ${
                          isSelected
                            ? 'bg-neutral-800 text-white'
                            : 'bg-neutral-100 text-neutral-700'
                        }`}
                      >
                        <Icon className="w-3.5 h-3.5" />
                      </div>
                      <div className="min-w-0">
                        <div className="text-xs font-bold truncate">{item.title}</div>
                        <div
                          className={`text-[10px] truncate ${
                            isSelected ? 'text-neutral-300' : 'text-neutral-500'
                          }`}
                        >
                          {item.badge}
                        </div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Feedback Alerts */}
            {errorMessage && (
              <div className="mb-5 p-3 rounded-xl bg-rose-50 border border-rose-200 flex items-start gap-2.5 text-rose-700 text-xs">
                <AlertCircle className="w-4 h-4 shrink-0 mt-0.5 text-rose-600" />
                <span>{errorMessage}</span>
              </div>
            )}

            {successMessage && (
              <div className="mb-5 p-3 rounded-xl bg-emerald-50 border border-emerald-200 flex items-start gap-2.5 text-emerald-800 text-xs">
                <CheckCircle2 className="w-4 h-4 shrink-0 mt-0.5 text-emerald-600" />
                <span>{successMessage}</span>
              </div>
            )}

            {/* Login Form */}
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider mb-1.5">
                  Tài khoản / Email / Số điện thoại
                </label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                    <Mail className="w-4 h-4" />
                  </div>
                  <input
                    type="text"
                    required
                    value={identifier}
                    onChange={(e) => setIdentifier(e.target.value)}
                    placeholder="email@cassavas.vn hoặc 090..."
                    className="w-full pl-10 pr-4 py-2.5 bg-white border border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 rounded-xl text-sm text-neutral-900 placeholder-neutral-400 transition-all outline-none"
                  />
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider">
                    Mật khẩu
                  </label>
                  <button
                    type="button"
                    onClick={() => onNavigate('/forgot-password')}
                    className="text-xs font-semibold text-neutral-700 hover:text-neutral-950 transition-colors cursor-pointer"
                  >
                    Quên mật khẩu?
                  </button>
                </div>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                    <Lock className="w-4 h-4" />
                  </div>
                  <input
                    type={showPassword ? 'text' : 'password'}
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full pl-10 pr-10 py-2.5 bg-white border border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 rounded-xl text-sm text-neutral-900 placeholder-neutral-400 transition-all outline-none"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-neutral-400 hover:text-neutral-700 cursor-pointer"
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div className="flex items-center justify-between pt-1">
                <label className="flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={rememberMe}
                    onChange={(e) => setRememberMe(e.target.checked)}
                    className="w-4 h-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-950"
                  />
                  <span className="text-xs text-neutral-600 font-medium">Ghi nhớ đăng nhập</span>
                </label>
              </div>

              <button
                type="submit"
                disabled={isLoading}
                className="w-full py-3 px-4 rounded-xl bg-neutral-950 hover:bg-neutral-800 active:scale-[0.99] text-white font-medium text-sm shadow-sm hover:shadow transition-all flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer"
              >
                {isLoading ? (
                  <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                ) : (
                  <>
                    <span>Đăng nhập hệ thống</span>
                    <ArrowRight className="w-4 h-4" />
                  </>
                )}
              </button>
            </form>

            {/* Bottom Link to Register */}
            <div className="mt-6 pt-5 border-t border-neutral-100 text-center">
              <p className="text-xs text-neutral-500 mb-2.5">Chưa có tài khoản cư dân?</p>
              <div className="flex items-center justify-center gap-2">
                <button
                  type="button"
                  onClick={() => onNavigate('/register?type=owner')}
                  className="px-3 py-1.5 rounded-lg border border-neutral-200 hover:border-neutral-900 bg-neutral-50 hover:bg-white text-xs font-semibold text-neutral-800 transition-all cursor-pointer shadow-2xs"
                >
                  Đăng ký Chủ hộ
                </button>
                <button
                  type="button"
                  onClick={() => onNavigate('/register?type=tenant')}
                  className="px-3 py-1.5 rounded-lg border border-emerald-200 hover:border-emerald-500 bg-emerald-50/70 hover:bg-emerald-100/70 text-xs font-semibold text-emerald-800 transition-all cursor-pointer shadow-2xs"
                >
                  Đăng ký Khách thuê
                </button>
              </div>
            </div>
          </div>
        </div>
      </main>

      {/* Footer */}
      <footer className="relative z-10 py-6 text-center text-xs text-neutral-500 border-t border-neutral-200/80 bg-white/40 backdrop-blur-sm">
        <p>© 2026 SMART CASSAVAS • Hệ sinh thái quản lý chung cư & tòa nhà thông minh</p>
      </footer>
    </div>
  );
};

export default LoginPage;
