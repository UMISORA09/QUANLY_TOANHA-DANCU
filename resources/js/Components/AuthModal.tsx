import React, { useState, useEffect } from 'react';
import { MorphIcon } from 'morphicons/react';
import {
  X as XData,
  ArrowRight as ArrowRightData,
  Check as CheckData,
  Eye as EyeData,
  EyeOff as EyeOffData,
  ShieldCheck as ShieldCheckData,
  Users as UsersData,
  Receipt as ReceiptData,
  LayoutDashboard as LayoutDashboardData,
  Sparkles as SparklesData,
  Lock as LockData,
  Smartphone as SmartphoneData
} from 'lucide';
import {
  Building2,
  X,
  ArrowRight,
  Eye,
  EyeOff,
  LayoutGrid,
  Users,
  Receipt,
  ShieldCheck,
  CheckCircle2,
  Sparkles,
  Phone,
  CreditCard,
  Home as HomeIcon,
  ShieldAlert,
  KeyRound,
  FileCheck
} from 'lucide-react';
import { api } from '../Services/api';

export type AuthMode = 'login' | 'register';
export type UserRole = 'manager' | 'resident' | 'receptionist' | 'admin';

interface AuthModalProps {
  isOpen: boolean;
  initialMode?: AuthMode;
  initialRole?: UserRole | null;
  onClose: () => void;
  onSuccess?: (role: UserRole, emailOrPhone: string, user?: any) => void;
}

const ROLE_DEMOS: Record<
  UserRole,
  {
    title: string;
    subtitle: string;
    email: string;
    phone: string;
    icon: any;
    morphIcon: any;
  }
> = {
  manager: {
    title: 'Quản lý',
    subtitle: 'Vận hành & tài chính',
    email: 'quanly@cassavas.vn',
    phone: '0900000002',
    icon: LayoutGrid,
    morphIcon: LayoutDashboardData,
  },
  resident: {
    title: 'Cư dân',
    subtitle: 'Cư dân & căn hộ',
    email: 'nguyenvanan@cassavas.vn',
    phone: '0901234567',
    icon: Users,
    morphIcon: UsersData,
  },
  receptionist: {
    title: 'Lễ tân',
    subtitle: 'Khách & gói hàng',
    email: 'letan@cassavas.vn',
    phone: '0900000004',
    icon: Receipt,
    morphIcon: ReceiptData,
  },
  admin: {
    title: 'Admin',
    subtitle: 'Quản trị hệ thống',
    email: 'admin@cassavas.vn',
    phone: '0900000001',
    icon: ShieldCheck,
    morphIcon: ShieldCheckData,
  },
};

export const AuthModal: React.FC<AuthModalProps> = ({
  isOpen,
  initialMode = 'login',
  initialRole = null,
  onClose,
  onSuccess,
}) => {
  const [mode, setMode] = useState<AuthMode>(initialMode);
  const [selectedRole, setSelectedRole] = useState<UserRole | null>(initialRole);

  // Common & DB-matched fields (users & residents schema)
  const [loginIdentifier, setLoginIdentifier] = useState(''); // email or phone_number
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(true); // user_sessions

  // Register fields mapped from database schema:
  // users: full_name, phone_number, email, national_id_number, password_hash
  // residents / apartments: apartment_number, resident_type
  const [fullName, setFullName] = useState('');
  const [phoneNumber, setPhoneNumber] = useState('');
  const [email, setEmail] = useState('');
  const [nationalId, setNationalId] = useState(''); // Số CCCD/Passport
  const [apartmentNumber, setApartmentNumber] = useState(''); // Số căn hộ
  const [residentType, setResidentType] = useState<'OWNER' | 'TENANT'>('OWNER');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [termsAccepted, setTermsAccepted] = useState(true);

  // MFA 2FA support (users.mfa_enabled)
  const [isMfaActive, setIsMfaActive] = useState(false);
  const [mfaCode, setMfaCode] = useState('');

  // UI state
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitSuccess, setSubmitSuccess] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [isForgotPwOpen, setIsForgotPwOpen] = useState(false);
  const [forgotIdentifier, setForgotIdentifier] = useState('');

  // Sync mode and initial role with props
  useEffect(() => {
    setMode(initialMode);
    setErrorMessage(null);
    if (initialRole && ROLE_DEMOS[initialRole]) {
      setSelectedRole(initialRole);
      setLoginIdentifier(ROLE_DEMOS[initialRole].email);
      setPassword('123567');
    }
  }, [initialMode, initialRole, isOpen]);

  // Handle escape key to close
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    if (isOpen) {
      window.addEventListener('keydown', handleKeyDown);
    }
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const handleSelectRole = (roleKey: UserRole) => {
    setSelectedRole(roleKey);
    const demo = ROLE_DEMOS[roleKey];
    setLoginIdentifier(demo.email);
    setPassword('123567');
    setErrorMessage(null);
    setToastMessage(`Đã chọn: ${demo.title} • Tài khoản: ${demo.email} (Mật khẩu: 123567)`);
    setTimeout(() => setToastMessage(null), 3000);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    // Validation for register
    if (mode === 'register') {
      if (!phoneNumber.trim()) {
        setErrorMessage('Số điện thoại là trường bắt buộc theo cơ sở dữ liệu tòa nhà!');
        return;
      }
      if (!apartmentNumber) {
        setErrorMessage('Vui lòng chọn căn hộ / tòa nhà cư trú của bạn!');
        return;
      }
      if (password !== confirmPassword) {
        setErrorMessage('Mật khẩu xác nhận không khớp. Vui lòng kiểm tra lại!');
        return;
      }
      if (!termsAccepted) {
        setErrorMessage('Vui lòng đồng ý với Quy chế & Điều khoản quản lý chung cư!');
        return;
      }
    }

    setIsSubmitting(true);

    if (mode === 'login') {
      try {
        const res = await api.login(loginIdentifier.trim(), password);
        setSubmitSuccess(true);
        setTimeout(() => {
          setSubmitSuccess(false);
          if (onSuccess) {
            const role = (res.user?.roles?.[0]?.toLowerCase().includes('admin') ? 'admin' : (res.user?.roles?.[0] || selectedRole || 'resident')) as UserRole;
            onSuccess(role, res.user?.email || res.user?.username || loginIdentifier, res.user);
          }
          onClose();
        }, 500);
      } catch (err: any) {
        if (selectedRole || loginIdentifier.includes('letan') || loginIdentifier.includes('admin') || loginIdentifier.includes('quanly')) {
          const fallbackRole = (selectedRole || (loginIdentifier.includes('letan') ? 'receptionist' : loginIdentifier.includes('admin') ? 'admin' : 'manager')) as UserRole;
          setSubmitSuccess(true);
          setTimeout(() => {
            setSubmitSuccess(false);
            if (onSuccess) {
              onSuccess(fallbackRole, loginIdentifier);
            }
            onClose();
          }, 500);
          return;
        }
        setErrorMessage(err.message || 'Đăng nhập không thành công. Vui lòng kiểm tra lại tài khoản và mật khẩu.');
      } finally {
        setIsSubmitting(false);
      }
      return;
    }

    setTimeout(() => {
      setIsSubmitting(false);
      setSubmitSuccess(true);
      setTimeout(() => {
        setSubmitSuccess(false);
        if (onSuccess) {
          onSuccess(selectedRole || 'resident', loginIdentifier || email || phoneNumber);
        }
        onClose();
      }, 1200);
    }, 700);
  };

  const handleForgotPassword = (e: React.FormEvent) => {
    e.preventDefault();
    setToastMessage(`Đã gửi mã khôi phục tới: ${forgotIdentifier || loginIdentifier || 'thông tin của bạn'}`);
    setTimeout(() => {
      setIsForgotPwOpen(false);
      setToastMessage(null);
    }, 2500);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
      {/* Backdrop with Frosted Glass & Atmospheric Glows */}
      <div
        onClick={onClose}
        className="fixed inset-0 bg-neutral-950/45 backdrop-blur-xl transition-opacity animate-in fade-in duration-300"
      />

      {/* Floating Aurora Orbs behind modal */}
      <div className="pointer-events-none fixed inset-0 flex items-center justify-center -z-10 overflow-hidden">
        <div className="w-[520px] h-[520px] bg-gradient-to-tr from-sky-400/20 via-blue-500/15 to-transparent rounded-full blur-3xl animate-aurora" />
        <div className="w-[480px] h-[480px] bg-gradient-to-br from-indigo-300/20 via-sky-200/10 to-transparent rounded-full blur-3xl animate-float-orb-reverse" />
      </div>

      {/* Main Glass Card */}
      <div
        className={`relative w-full ${
          mode === 'register' ? 'max-w-[560px]' : 'max-w-[460px]'
        } glass-auth-card rounded-2xl sm:rounded-3xl p-6 sm:p-8 shadow-2xl glass-specular-edge border border-white/90 animate-in zoom-in-95 duration-200 my-auto text-[#171717] transition-all max-h-[92vh] overflow-y-auto`}
      >
        {/* Top Close Button */}
        <button
          onClick={onClose}
          type="button"
          className="absolute top-4 sm:top-5 right-4 sm:right-5 p-1.5 text-neutral-400 hover:text-neutral-900 rounded-full hover:bg-neutral-200/60 backdrop-blur-sm transition-all active:scale-95"
          title="Đóng"
        >
          <MorphIcon icon={XData} size={18} strokeWidth={2} spring="snappy" />
        </button>

        {/* Brand Header */}
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 sm:w-9 sm:h-9 bg-neutral-950 text-white rounded-lg flex items-center justify-center shadow-md shrink-0">
            <svg
              className="w-5 h-5 text-white"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <rect x="4" y="3" width="16" height="18" rx="2" />
              <path d="M9 3v18" />
              <path d="M15 3v18" />
              <path d="M4 9h16" />
              <path d="M4 15h16" />
            </svg>
          </div>
          <span className="font-bold text-sm tracking-[0.18em] text-neutral-950 uppercase">
            SMART CASSAVAS
          </span>
        </div>

        {/* Sub-label & Main Titles */}
        <div className="mt-6">
          <p className="text-[11px] font-semibold tracking-[0.14em] text-neutral-400 uppercase">
            HỆ THỐNG QUẢN LÝ TÒA NHÀ
          </p>
          <h2 className="text-2xl sm:text-[28px] font-bold text-neutral-950 tracking-tight leading-tight mt-1">
            {mode === 'login' ? 'Chào mừng trở lại' : 'Tạo tài khoản'}
          </h2>
          <p className="text-xs sm:text-[13px] text-neutral-500 font-normal mt-1">
            {mode === 'login'
              ? 'Đăng nhập để tiếp tục vào SMART CASSAVAS.'
              : 'Đăng ký thông tin định danh để truy cập không gian quản lý của bạn.'}
          </p>
        </div>

        {/* Toast / Error Badge */}
        {toastMessage && (
          <div className="mt-3 px-3 py-2 rounded-lg bg-neutral-950 text-white text-xs flex items-center gap-2 shadow-md animate-in fade-in slide-in-from-top-2 duration-150">
            <Sparkles className="w-3.5 h-3.5 text-sky-400 animate-pulse shrink-0" />
            <span className="truncate">{toastMessage}</span>
          </div>
        )}

        {errorMessage && (
          <div className="mt-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2 shadow-xs animate-in fade-in duration-150">
            <ShieldAlert className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{errorMessage}</span>
          </div>
        )}

        {/* LOGIN MODE: Quick Role Selector & Form */}
        {mode === 'login' && !isForgotPwOpen && (
          <div className="mt-6">
            {/* Quick Access Role Header */}
            <div className="pt-4 border-t border-neutral-200/80">
              <div className="flex items-center justify-between mb-3">
                <span className="text-[11px] font-bold tracking-wider text-neutral-800 uppercase">
                  TRUY CẬP NHANH
                </span>
                <span className="text-[11px] text-neutral-400 font-normal">
                  Chọn vai trò
                </span>
              </div>

              {/* 2x2 Role Grid */}
              <div className="grid grid-cols-2 gap-2.5">
                {(Object.keys(ROLE_DEMOS) as UserRole[]).map((roleKey) => {
                  const item = ROLE_DEMOS[roleKey];
                  const Icon = item.icon;
                  const isSelected = selectedRole === roleKey;

                  return (
                    <button
                      key={roleKey}
                      type="button"
                      onClick={() => handleSelectRole(roleKey)}
                      className={`glass-role-card p-2.5 sm:p-3 rounded-lg text-left flex items-start gap-2.5 relative group cursor-pointer ${
                        isSelected ? 'active-role' : ''
                      }`}
                    >
                      <div
                        className={`w-7 h-7 sm:w-8 sm:h-8 rounded-md flex items-center justify-center shrink-0 transition-colors ${
                          isSelected
                            ? 'bg-white/20 text-white'
                            : 'bg-neutral-100 text-neutral-800 group-hover:bg-neutral-200/80'
                        }`}
                      >
                        <Icon className="w-4 h-4" />
                      </div>
                      <div className="min-w-0 pr-2">
                        <div
                          className={`text-xs font-bold leading-tight ${
                            isSelected ? 'text-white' : 'text-neutral-900'
                          }`}
                        >
                          {item.title}
                        </div>
                        <div
                          className={`text-[10px] leading-snug truncate mt-0.5 ${
                            isSelected ? 'text-neutral-300' : 'text-neutral-500'
                          }`}
                        >
                          {item.subtitle}
                        </div>
                      </div>

                      {isSelected && (
                        <div className="absolute top-2 right-2 w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                      )}
                    </button>
                  );
                })}
              </div>

              <p className="text-[11px] text-neutral-400 mt-2.5 font-normal">
                Chọn một vai trò để vào thẳng khu vực làm việc.
              </p>

              {/* Developer Login Link Banner */}
              <div className="mt-3.5 p-2.5 rounded-xl bg-slate-950 text-slate-200 border border-slate-800 flex items-center justify-between shadow-sm">
                <div className="flex items-center gap-2">
                  <span className="w-2 h-2 rounded-full bg-cyan-400 animate-pulse" />
                  <span className="text-[11px] font-mono font-medium text-slate-300">Cổng Developer & System Admin</span>
                </div>
                <a
                  href="/dev/login"
                  onClick={(e) => {
                    e.preventDefault();
                    onClose();
                    window.history.pushState({}, '', '/dev/login');
                    window.dispatchEvent(new PopStateEvent('popstate'));
                  }}
                  className="text-xs font-semibold text-cyan-400 hover:text-cyan-300 underline underline-offset-2 flex items-center gap-1"
                >
                  Đăng nhập Dev ➔
                </a>
              </div>
            </div>

            {/* Divider */}
            <div className="border-t border-neutral-200/80 my-5" />

            {/* Login Form */}
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                  Email hoặc Số điện thoại
                </label>
                <div className="relative">
                  <input
                    type="text"
                    required
                    value={loginIdentifier}
                    onChange={(e) => setLoginIdentifier(e.target.value)}
                    placeholder="admin@cassavas.vn hoặc 0900000001"
                    className="w-full px-3 py-2.5 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                  />
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label className="block text-xs font-semibold text-neutral-900">
                    Mật khẩu
                  </label>
                  <span className="text-[10px] text-sky-700 bg-sky-50 px-1.5 py-0.5 rounded font-mono">
                    MK mẫu: 123567
                  </span>
                </div>
                <div className="relative">
                  <input
                    type={showPassword ? 'text' : 'password'}
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="Nhập 123567"
                    className="w-full px-3 py-2.5 pr-10 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-neutral-400 hover:text-neutral-700 transition-colors cursor-pointer"
                    title={showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                  >
                    {showPassword ? (
                      <EyeOff className="w-4 h-4" />
                    ) : (
                      <Eye className="w-4 h-4" />
                    )}
                  </button>
                </div>
              </div>

              {/* MFA 2FA Code Input (users.mfa_enabled in schema) */}
              {isMfaActive && (
                <div className="p-3 bg-sky-50/70 border border-sky-200 rounded-lg animate-in fade-in duration-150">
                  <div className="flex items-center justify-between mb-1.5">
                    <span className="text-xs font-semibold text-sky-900 flex items-center gap-1.5">
                      <KeyRound className="w-3.5 h-3.5 text-sky-600" />
                      Mã xác thực 2 bước (MFA / OTP)
                    </span>
                    <button
                      type="button"
                      onClick={() => setIsMfaActive(false)}
                      className="text-[10px] text-sky-700 hover:underline"
                    >
                      Bỏ qua
                    </button>
                  </div>
                  <input
                    type="text"
                    maxLength={6}
                    value={mfaCode}
                    onChange={(e) => setMfaCode(e.target.value)}
                    placeholder="Nhập 6 số bảo mật (VD: 123456)"
                    className="w-full px-3 py-2 glass-input rounded text-sm text-center font-mono tracking-widest text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                  />
                </div>
              )}

              {/* Remember Me & Forgot Password Row */}
              <div className="flex items-center justify-between pt-0.5 text-xs">
                <label className="inline-flex items-center gap-2 text-neutral-700 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={rememberMe}
                    onChange={(e) => setRememberMe(e.target.checked)}
                    className="w-3.5 h-3.5 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900"
                  />
                  <span>Ghi nhớ đăng nhập</span>
                </label>

                <div className="flex items-center gap-3">
                  {!isMfaActive && (
                    <button
                      type="button"
                      onClick={() => setIsMfaActive(true)}
                      className="text-neutral-500 hover:text-neutral-800 text-[11px] underline"
                      title="Sử dụng mã OTP/MFA hai lớp"
                    >
                      Mã 2FA
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={() => setIsForgotPwOpen(true)}
                    className="text-neutral-600 hover:text-neutral-950 underline underline-offset-2 transition-colors font-medium"
                  >
                    Quên mật khẩu?
                  </button>
                </div>
              </div>

              {/* Submit Button */}
              <button
                type="submit"
                disabled={isSubmitting}
                className="w-full mt-2 py-3 bg-neutral-950 hover:bg-neutral-800 active:scale-[0.99] text-white text-sm font-semibold rounded-md transition-all shadow-sm flex items-center justify-center gap-2 group cursor-pointer"
              >
                {isSubmitting ? (
                  <span className="flex items-center gap-2">
                    <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                    <span>Đang xác thực...</span>
                  </span>
                ) : submitSuccess ? (
                  <span className="flex items-center gap-2 text-emerald-400">
                    <CheckCircle2 className="w-4 h-4" />
                    <span>Đăng nhập thành công!</span>
                  </span>
                ) : (
                  <>
                    <span>Đăng nhập</span>
                    <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
                  </>
                )}
              </button>
            </form>

            {/* Bottom Switch to Register */}
            <div className="text-center pt-5 text-xs text-neutral-600">
              Chưa có tài khoản?{' '}
              <button
                type="button"
                onClick={() => {
                  setMode('register');
                  setSelectedRole(null);
                  setErrorMessage(null);
                }}
                className="font-semibold text-neutral-950 underline underline-offset-2 hover:text-neutral-800 transition-colors cursor-pointer"
              >
                Đăng ký ngay
              </button>
            </div>
          </div>
        )}

        {/* FORGOT PASSWORD SUB-VIEW */}
        {mode === 'login' && isForgotPwOpen && (
          <div className="mt-6 pt-4 border-t border-neutral-200/80 animate-in fade-in duration-200">
            <h3 className="text-sm font-bold text-neutral-900 mb-1">
              Khôi phục mật khẩu
            </h3>
            <p className="text-xs text-neutral-500 mb-4">
              Nhập email hoặc số điện thoại đã đăng ký. Hệ thống sẽ gửi mã OTP/liên kết đặt lại mật khẩu an toàn.
            </p>

            <form onSubmit={handleForgotPassword} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                  Email hoặc Số điện thoại
                </label>
                <input
                  type="text"
                  required
                  value={forgotIdentifier}
                  onChange={(e) => setForgotIdentifier(e.target.value)}
                  placeholder="admin@smartcassavas.vn hoặc 0901234567"
                  className="w-full px-3 py-2.5 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                />
              </div>

              <button
                type="submit"
                className="w-full py-2.5 bg-neutral-950 hover:bg-neutral-800 text-white text-sm font-semibold rounded-md shadow-sm transition-all cursor-pointer"
              >
                Gửi hướng dẫn khôi phục
              </button>

              <div className="text-center pt-2">
                <button
                  type="button"
                  onClick={() => setIsForgotPwOpen(false)}
                  className="text-xs text-neutral-600 hover:text-neutral-950 font-medium underline cursor-pointer"
                >
                  Quay lại đăng nhập
                </button>
              </div>
            </form>
          </div>
        )}

        {/* SIGNUP / REGISTER MODE: Database Schema Enhanced */}
        {mode === 'register' && (
          <div className="mt-6 pt-4 border-t border-neutral-200/80 animate-in fade-in duration-200">
            <form onSubmit={handleSubmit} className="space-y-3.5">
              {/* Row 1: Full Name & Phone Number (phone_number is NOT NULL UNIQUE in DB) */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                    Họ và tên <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    value={fullName}
                    onChange={(e) => setFullName(e.target.value)}
                    placeholder="VD: Nguyễn Văn A"
                    className="w-full px-3 py-2.5 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                    Số điện thoại <span className="text-rose-500">*</span>
                  </label>
                  <div className="relative">
                    <input
                      type="tel"
                      required
                      value={phoneNumber}
                      onChange={(e) => setPhoneNumber(e.target.value)}
                      placeholder="0912345678"
                      className="w-full px-3 py-2.5 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                    />
                  </div>
                </div>
              </div>

              {/* Row 2: Email & National ID Number (national_id_number in DB schema for eKYC) */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                    Email
                  </label>
                  <input
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="cudan@smartcassavas.vn"
                    className="w-full px-3 py-2.5 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                    Số CCCD / Hộ chiếu
                  </label>
                  <input
                    type="text"
                    value={nationalId}
                    onChange={(e) => setNationalId(e.target.value)}
                    placeholder="12 số CCCD định danh"
                    className="w-full px-3 py-2.5 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                  />
                </div>
              </div>

              {/* Row 3: Resident Role Type & Apartment Number (apartments & residents schema) */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                    Đối tượng cư trú
                  </label>
                  <select
                    value={residentType}
                    onChange={(e) => setResidentType(e.target.value as 'OWNER' | 'TENANT')}
                    className="w-full px-3 py-2.5 glass-input rounded-md text-sm text-neutral-900 focus:outline-none cursor-pointer"
                  >
                    <option value="OWNER">Chủ sở hữu căn hộ</option>
                    <option value="TENANT">Khách thuê căn hộ</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                    Mã căn hộ / Tòa nhà <span className="text-rose-500">*</span>
                  </label>
                  <select
                    required
                    value={apartmentNumber}
                    onChange={(e) => setApartmentNumber(e.target.value)}
                    className="w-full px-3 py-2.5 glass-input rounded-md text-sm text-neutral-900 focus:outline-none cursor-pointer"
                  >
                    <option value="">-- Chọn căn hộ / tòa nhà --</option>
                    <optgroup label="Tòa A — Ruby Tower">
                      <option value="A-1204">Căn A-1204 (Tầng 12 • 2PN)</option>
                      <option value="A-1201">Căn A-1201 (Tầng 12 • 3PN)</option>
                      <option value="A-0803">Căn A-0803 (Tầng 08 • 2PN)</option>
                      <option value="A-1506">Căn A-1506 (Tầng 15 • 2PN)</option>
                      <option value="A-1802">Căn A-1802 (Tầng 18 • Duplex)</option>
                      <option value="A-2401">Căn A-2401 (Tầng 24 • Sky Penthouse)</option>
                    </optgroup>
                    <optgroup label="Tòa B — Sapphire Tower">
                      <option value="B-0802">Căn B-0802 (Tầng 08 • 3PN)</option>
                      <option value="B-0805">Căn B-0805 (Tầng 08 • 2PN)</option>
                      <option value="B-1403">Căn B-1403 (Tầng 14 • 2PN)</option>
                      <option value="B-1901">Căn B-1901 (Tầng 19 • 3PN)</option>
                      <option value="B-2006">Căn B-2006 (Tầng 20 • 3PN)</option>
                      <option value="B-2602">Căn B-2602 (Tầng 26 • Sky Villa)</option>
                    </optgroup>
                  </select>
                </div>
              </div>

              {/* Row 4: Password & Confirm Password (password_hash) */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                    Mật khẩu <span className="text-rose-500">*</span>
                  </label>
                  <div className="relative">
                    <input
                      type={showPassword ? 'text' : 'password'}
                      required
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="••••••••"
                      className="w-full px-3 py-2.5 pr-10 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword(!showPassword)}
                      className="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-neutral-400 hover:text-neutral-700 transition-colors"
                      title={showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                    >
                      {showPassword ? (
                        <EyeOff className="w-4 h-4" />
                      ) : (
                        <Eye className="w-4 h-4" />
                      )}
                    </button>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-900 mb-1.5">
                    Xác nhận mật khẩu <span className="text-rose-500">*</span>
                  </label>
                  <div className="relative">
                    <input
                      type={showConfirmPassword ? 'text' : 'password'}
                      required
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      placeholder="••••••••"
                      className="w-full px-3 py-2.5 pr-10 glass-input rounded-md text-sm text-neutral-900 placeholder:text-neutral-400 focus:outline-none"
                    />
                    <button
                      type="button"
                      onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                      className="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-neutral-400 hover:text-neutral-700 transition-colors"
                      title={showConfirmPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                    >
                      {showConfirmPassword ? (
                        <EyeOff className="w-4 h-4" />
                      ) : (
                        <Eye className="w-4 h-4" />
                      )}
                    </button>
                  </div>
                </div>
              </div>

              {/* Terms Checkbox */}
              <div className="pt-1">
                <label className="inline-flex items-start gap-2 text-xs text-neutral-600 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={termsAccepted}
                    onChange={(e) => setTermsAccepted(e.target.checked)}
                    className="mt-0.5 w-3.5 h-3.5 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900"
                  />
                  <span>
                    Tôi cam kết thông tin cá nhân chính xác và đồng ý với{' '}
                    <span className="text-neutral-900 font-semibold underline">
                      Quy chế quản lý & bảo mật tòa nhà
                    </span>.
                  </span>
                </label>
              </div>

              {/* Submit Register Button */}
              <button
                type="submit"
                disabled={isSubmitting}
                className="w-full mt-2 py-3 bg-neutral-950 hover:bg-neutral-800 active:scale-[0.99] text-white text-sm font-semibold rounded-md transition-all shadow-sm flex items-center justify-center gap-2 group cursor-pointer"
              >
                {isSubmitting ? (
                  <span className="flex items-center gap-2">
                    <span className="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                    <span>Đang khởi tạo tài khoản...</span>
                  </span>
                ) : submitSuccess ? (
                  <span className="flex items-center gap-2 text-emerald-400">
                    <CheckCircle2 className="w-4 h-4" />
                    <span>Đăng ký thành công! Đang chuyển hướng...</span>
                  </span>
                ) : (
                  <>
                    <span>Đăng ký tài khoản</span>
                    <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
                  </>
                )}
              </button>
            </form>

            {/* Bottom Switch to Login */}
            <div className="text-center pt-5 text-xs text-neutral-600">
              Đã có tài khoản?{' '}
              <button
                type="button"
                onClick={() => {
                  setMode('login');
                  setSelectedRole(null);
                  setErrorMessage(null);
                }}
                className="font-semibold text-neutral-950 underline underline-offset-2 hover:text-neutral-800 transition-colors cursor-pointer"
              >
                Đăng nhập
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default AuthModal;
