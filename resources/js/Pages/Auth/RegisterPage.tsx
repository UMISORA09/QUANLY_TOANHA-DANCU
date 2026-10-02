import React, { useState, useEffect } from 'react';
import {
  Building2,
  Lock,
  Mail,
  Eye,
  EyeOff,
  ArrowRight,
  ArrowLeft,
  CheckCircle2,
  User,
  Phone,
  AlertCircle,
  KeyRound,
  Send,
  Check,
  Clock,
  ShieldCheck,
} from 'lucide-react';
import { api } from '../../Services/api';

interface RegisterPageProps {
  onNavigate: (path: string) => void;
  onRegisterSuccess?: (role: string, email: string, residentType?: 'OWNER' | 'TENANT') => void;
}

export const RegisterPage: React.FC<RegisterPageProps> = ({ onNavigate, onRegisterSuccess }) => {
  // 2 distinct registration types: OWNER vs TENANT
  const [registerType, setRegisterType] = useState<'OWNER' | 'TENANT'>('OWNER');

  // Shared inputs
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [phoneNumber, setPhoneNumber] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [acceptTerms, setAcceptTerms] = useState(true);

  // Email OTP Verification states
  const [otp, setOtp] = useState('');
  const [isOtpSent, setIsOtpSent] = useState(false);
  const [isSendingOtp, setIsSendingOtp] = useState(false);
  const [isVerifyingOtp, setIsVerifyingOtp] = useState(false);
  const [isEmailVerified, setIsEmailVerified] = useState(false);
  const [verificationToken, setVerificationToken] = useState('');
  const [otpCountdown, setOtpCountdown] = useState(0);
  const [otpDebugCode, setOtpDebugCode] = useState<string | null>(null);
  const [otpMessage, setOtpMessage] = useState<string | null>(null);
  const [otpError, setOtpError] = useState<string | null>(null);

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Read URL query parameter ?type=tenant or ?type=owner on mount
  useEffect(() => {
    try {
      const typeParam = new URLSearchParams(window.location.search).get('type');
      if (typeParam?.toLowerCase() === 'tenant' || typeParam?.toLowerCase() === 'khach-thue') {
        setRegisterType('TENANT');
      } else if (typeParam?.toLowerCase() === 'owner' || typeParam?.toLowerCase() === 'chu-ho') {
        setRegisterType('OWNER');
      }
    } catch {
      // ignore
    }
  }, []);

  // Cooldown countdown for resending OTP
  useEffect(() => {
    if (otpCountdown > 0) {
      const timer = setTimeout(() => {
        setOtpCountdown((prev) => prev - 1);
      }, 1000);
      return () => clearTimeout(timer);
    }
  }, [otpCountdown]);

  const getPasswordStrength = (pass: string) => {
    if (!pass) return { score: 0, text: 'Chưa nhập', color: 'bg-neutral-200' };
    let score = 0;
    if (pass.length >= 6) score += 1;
    if (pass.length >= 8) score += 1;
    if (/[A-Z]/.test(pass)) score += 1;
    if (/[0-9]/.test(pass)) score += 1;
    if (/[^A-Za-z0-9]/.test(pass)) score += 1;

    if (score <= 2) return { score: 1, text: 'Yếu', color: 'bg-rose-500' };
    if (score <= 3) return { score: 2, text: 'Trung bình', color: 'bg-amber-500' };
    return { score: 3, text: 'Mạnh', color: 'bg-emerald-500' };
  };

  const passStrength = getPasswordStrength(password);

  // Gửi mã OTP xác thực email
  const handleSendOtp = async () => {
    setErrorMessage(null);
    setOtpError(null);
    setOtpMessage(null);

    const cleanEmail = email.trim().toLowerCase();
    if (!cleanEmail || !cleanEmail.includes('@')) {
      setErrorMessage('Vui lòng nhập địa chỉ email hợp lệ trước khi gửi mã OTP.');
      return;
    }

    setIsSendingOtp(true);
    try {
      const res = await api.sendRegisterOtp(cleanEmail);
      setIsOtpSent(true);
      setOtpCountdown(60);
      setOtpDebugCode(res.debug_otp || null);
      setOtpMessage(res.message || 'Mã xác thực OTP đã được gửi đến email của bạn.');
    } catch (err: any) {
      setOtpError(err.message || 'Không thể gửi mã OTP. Vui lòng kiểm tra lại email.');
    } finally {
      setIsSendingOtp(false);
    }
  };

  // Xác thực mã OTP người dùng nhập
  const handleVerifyOtp = async () => {
    setOtpError(null);
    const cleanEmail = email.trim().toLowerCase();
    const cleanOtp = otp.trim();

    if (!cleanOtp || cleanOtp.length < 6) {
      setOtpError('Vui lòng nhập đầy đủ 6 chữ số mã OTP.');
      return;
    }

    setIsVerifyingOtp(true);
    try {
      const res = await api.verifyRegisterOtp(cleanEmail, cleanOtp);
      setIsEmailVerified(true);
      setVerificationToken(res.verification_token || '');
      setOtpMessage('Xác thực email thành công! Bạn đã có thể ấn nút Đăng ký bên dưới.');
      setOtpError(null);
    } catch (err: any) {
      setOtpError(err.message || 'Mã OTP không chính xác hoặc đã hết thời gian hiệu lực.');
    } finally {
      setIsVerifyingOtp(false);
    }
  };

  // Đổi email và xác thực lại
  const handleResetEmail = () => {
    setIsEmailVerified(false);
    setVerificationToken('');
    setIsOtpSent(false);
    setOtp('');
    setOtpMessage(null);
    setOtpError(null);
    setOtpDebugCode(null);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    if (!fullName.trim()) {
      setErrorMessage('Vui lòng nhập họ và tên của bạn.');
      return;
    }
    if (!email.trim() || !email.includes('@')) {
      setErrorMessage('Vui lòng nhập địa chỉ email hợp lệ.');
      return;
    }
    if (!isEmailVerified) {
      setErrorMessage('Vui lòng xác thực email qua mã OTP đúng trước khi ấn nút Đăng ký.');
      return;
    }
    if (!phoneNumber.trim() || phoneNumber.length < 9) {
      setErrorMessage('Vui lòng nhập số điện thoại hợp lệ.');
      return;
    }
    if (password.length < 6) {
      setErrorMessage('Mật khẩu phải có tối thiểu 6 ký tự.');
      return;
    }
    if (password !== confirmPassword) {
      setErrorMessage('Mật khẩu xác nhận không khớp.');
      return;
    }
    if (!acceptTerms) {
      setErrorMessage('Vui lòng đồng ý với Điều khoản và Quy chế quản lý tòa nhà.');
      return;
    }

    setIsSubmitting(true);

    try {
      await api.register({
        full_name: fullName.trim(),
        email: email.trim().toLowerCase(),
        phone_number: phoneNumber.trim(),
        resident_type: registerType,
        password: password,
        otp: otp.trim() || undefined,
        verification_token: verificationToken || undefined,
      });

      if (registerType === 'TENANT') {
        setSuccessMessage('Đăng ký tài khoản Khách thuê thành công! Đang chuyển hướng vào xem danh mục tòa nhà & căn hộ cho thuê...');
      } else {
        setSuccessMessage('Đăng ký tài khoản Chủ sở hữu thành công! Đang chuyển hướng vào cổng quản lý căn hộ...');
      }

      setTimeout(() => {
        if (onRegisterSuccess) {
          onRegisterSuccess('resident', email.trim().toLowerCase(), registerType);
        } else {
          if (registerType === 'TENANT') {
            onNavigate('/cu-dan?tab=rentals');
          } else {
            onNavigate('/cu-dan');
          }
        }
      }, 700);
    } catch (err: any) {
      setErrorMessage(
        err.message || 'Đăng ký không thành công. Vui lòng kiểm tra lại thông tin.'
      );
    } finally {
      setIsSubmitting(false);
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
              onClick={() => onNavigate('/login')}
              className="text-sm font-medium text-white bg-neutral-950 hover:bg-neutral-800 active:scale-95 px-4 py-2 rounded-md shadow-sm transition-all cursor-pointer"
            >
              Đăng nhập
            </button>
          </div>
        </div>
      </header>

      {/* Main Register Area */}
      <main className="relative z-10 flex-1 flex items-center justify-center px-4 py-10 sm:py-14">
        <div className="w-full max-w-xl">
          <div className="bg-white/90 backdrop-blur-2xl border border-neutral-200/90 rounded-2xl p-7 sm:p-9 shadow-xl relative overflow-hidden">
            {/* Header Badge */}
            <div className="text-center mb-5">
              <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-neutral-100 border border-neutral-200 text-neutral-800 text-xs font-semibold mb-2.5">
                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                <span>Cổng Đăng Ký Tài Khoản</span>
              </div>
              <h1 className="text-2xl sm:text-3xl font-bold tracking-tight text-neutral-950 mb-1.5">
                {registerType === 'OWNER' ? 'Đăng ký Chủ sở hữu căn hộ' : 'Đăng ký Khách thuê căn hộ'}
              </h1>
              <p className="text-xs sm:text-sm text-neutral-500 leading-relaxed max-w-md mx-auto">
                {registerType === 'OWNER'
                  ? 'Dành cho gia chủ sở hữu: Quản lý căn hộ, thanh toán dịch vụ & đăng tin cho thuê căn hộ.'
                  : 'Dành cho khách thuê: Xem danh mục các tòa chung cư & căn hộ của chủ sở hữu cho thuê, đặt lịch xem nhà.'}
              </p>
            </div>

            {/* 2 DISTINCT REGISTRATION TABS SWITCHER */}
            <div className="mb-6 p-1.5 bg-neutral-100/90 rounded-xl border border-neutral-200/90 grid grid-cols-2 gap-1.5">
              <button
                type="button"
                onClick={() => setRegisterType('OWNER')}
                className={`py-2.5 px-3 rounded-lg text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer ${
                  registerType === 'OWNER'
                    ? 'bg-white text-neutral-950 shadow-xs border border-neutral-200/60'
                    : 'text-neutral-600 hover:text-neutral-950'
                }`}
              >
                <Building2 className={`w-4 h-4 ${registerType === 'OWNER' ? 'text-neutral-950' : 'text-neutral-400'}`} />
                <span>Chủ sở hữu</span>
                <span className="text-[10px] py-0.5 px-1.5 rounded-full bg-neutral-100 text-neutral-600 border border-neutral-200 hidden sm:inline">
                  Gia chủ
                </span>
              </button>

              <button
                type="button"
                onClick={() => setRegisterType('TENANT')}
                className={`py-2.5 px-3 rounded-lg text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer ${
                  registerType === 'TENANT'
                    ? 'bg-neutral-950 text-white shadow-xs'
                    : 'text-neutral-600 hover:text-neutral-950'
                }`}
              >
                <KeyRound className={`w-4 h-4 ${registerType === 'TENANT' ? 'text-emerald-400' : 'text-neutral-400'}`} />
                <span>Khách thuê</span>
                <span className={`text-[10px] py-0.5 px-1.5 rounded-full border hidden sm:inline ${
                  registerType === 'TENANT'
                    ? 'bg-white/10 text-emerald-300 border-white/20'
                    : 'bg-neutral-100 text-neutral-600 border-neutral-200'
                }`}>
                  Xem căn hộ cho thuê
                </span>
              </button>
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

            {/* Form */}
            <form onSubmit={handleSubmit} className="space-y-4">
              {/* Họ tên & SĐT */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider mb-1.5">
                    Họ và tên <span className="text-rose-500">*</span>
                  </label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                      <User className="w-4 h-4" />
                    </div>
                    <input
                      type="text"
                      required
                      value={fullName}
                      onChange={(e) => setFullName(e.target.value)}
                      placeholder="Nguyễn Văn A"
                      className="w-full pl-10 pr-3.5 py-2.5 bg-white border border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 rounded-xl text-sm text-neutral-900 placeholder-neutral-400 transition-all outline-none"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider mb-1.5">
                    Số điện thoại <span className="text-rose-500">*</span>
                  </label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                      <Phone className="w-4 h-4" />
                    </div>
                    <input
                      type="tel"
                      required
                      value={phoneNumber}
                      onChange={(e) => setPhoneNumber(e.target.value)}
                      placeholder="0912 345 678"
                      className="w-full pl-10 pr-3.5 py-2.5 bg-white border border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 rounded-xl text-sm text-neutral-900 placeholder-neutral-400 transition-all outline-none"
                    />
                  </div>
                </div>
              </div>

              {/* ================= KHỐI EMAIL & XÁC THỰC MÃ OTP (BỎ CCCD) ================= */}
              <div className="p-4 rounded-xl bg-neutral-50/90 border border-neutral-200/90 space-y-3">
                <div className="flex items-center justify-between">
                  <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider">
                    Địa chỉ Email <span className="text-rose-500">*</span>
                  </label>
                  {isEmailVerified ? (
                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-100/80 px-2.5 py-0.5 rounded-full border border-emerald-200">
                      <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                      Email đã xác thực
                    </span>
                  ) : (
                    <span className="text-[11px] text-amber-700 font-semibold flex items-center gap-1">
                      <ShieldCheck className="w-3.5 h-3.5 text-amber-600" />
                      Cần xác thực OTP
                    </span>
                  )}
                </div>

                <div className="flex flex-col sm:flex-row gap-2">
                  <div className="relative flex-1">
                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                      <Mail className="w-4 h-4" />
                    </div>
                    <input
                      type="email"
                      required
                      disabled={isEmailVerified}
                      value={email}
                      onChange={(e) => {
                        setEmail(e.target.value);
                        if (isOtpSent && !isEmailVerified) {
                          setOtp('');
                        }
                      }}
                      placeholder="email@vidu.vn"
                      className={`w-full pl-10 pr-3.5 py-2.5 bg-white border rounded-xl text-sm transition-all outline-none ${
                        isEmailVerified
                          ? 'border-emerald-300 bg-emerald-50/30 text-neutral-800 font-medium'
                          : 'border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 text-neutral-900'
                      }`}
                    />
                  </div>

                  {!isEmailVerified ? (
                    <button
                      type="button"
                      onClick={handleSendOtp}
                      disabled={isSendingOtp || otpCountdown > 0 || !email.includes('@')}
                      className="px-4 py-2.5 rounded-xl bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold transition-all flex items-center justify-center gap-1.5 shadow-2xs disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap"
                    >
                      {isSendingOtp ? (
                        <>
                          <div className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                          <span>Đang gửi...</span>
                        </>
                      ) : otpCountdown > 0 ? (
                        <>
                          <Clock className="w-3.5 h-3.5 text-neutral-300" />
                          <span>Gửi lại ({otpCountdown}s)</span>
                        </>
                      ) : (
                        <>
                          <Send className="w-3.5 h-3.5 text-emerald-400" />
                          <span>{isOtpSent ? 'Gửi lại mã OTP' : 'Gửi mã OTP'}</span>
                        </>
                      )}
                    </button>
                  ) : (
                    <button
                      type="button"
                      onClick={handleResetEmail}
                      className="px-3 py-2 text-xs font-semibold text-neutral-600 hover:text-neutral-950 hover:bg-neutral-200/60 rounded-lg transition-colors cursor-pointer whitespace-nowrap"
                    >
                      Đổi email khác
                    </button>
                  )}
                </div>

                {/* Hộp nhập mã OTP khi đã gửi */}
                {isOtpSent && !isEmailVerified && (
                  <div className="p-3.5 rounded-xl bg-white border border-neutral-200 shadow-2xs space-y-2.5 animate-in fade-in slide-in-from-top-1 duration-200">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-semibold text-neutral-800 flex items-center gap-1.5">
                        <KeyRound className="w-3.5 h-3.5 text-emerald-600" />
                        Nhập mã OTP 6 chữ số gửi về email:
                      </span>
                      {otpCountdown > 0 && (
                        <span className="text-[11px] text-neutral-400">Hiệu lực: 15 phút</span>
                      )}
                    </div>

                    <div className="flex items-center gap-2">
                      <input
                        type="text"
                        maxLength={6}
                        value={otp}
                        onChange={(e) => {
                          const val = e.target.value.replace(/\D/g, '');
                          setOtp(val);
                          setOtpError(null);
                        }}
                        placeholder="••••••"
                        className="w-40 px-3 py-2 bg-neutral-50 border border-neutral-300 focus:border-neutral-950 rounded-xl text-center font-mono text-base tracking-widest font-bold text-neutral-950 outline-none"
                      />

                      <button
                        type="button"
                        onClick={handleVerifyOtp}
                        disabled={isVerifyingOtp || otp.length < 6}
                        className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5 cursor-pointer shadow-2xs"
                      >
                        {isVerifyingOtp ? (
                          <div className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                        ) : (
                          <Check className="w-3.5 h-3.5" />
                        )}
                        <span>Xác nhận OTP</span>
                      </button>
                    </div>

                    {/* Debug test code preview for instant 1-click test */}
                    {otpDebugCode && (
                      <div className="flex items-center gap-2 pt-1">
                        <span className="text-[11px] text-amber-800">
                          Mã OTP thử nghiệm:{' '}
                          <button
                            type="button"
                            onClick={() => {
                              setOtp(otpDebugCode);
                              setOtpError(null);
                            }}
                            className="font-mono font-bold underline hover:text-amber-950 cursor-pointer ml-1 bg-amber-100/70 px-1.5 py-0.5 rounded"
                            title="Bấm để điền nhanh"
                          >
                            {otpDebugCode} (Bấm để điền)
                          </button>
                        </span>
                      </div>
                    )}

                    {otpError && (
                      <div className="text-[11px] text-rose-600 font-medium flex items-center gap-1 pt-1">
                        <AlertCircle className="w-3 h-3 text-rose-500 shrink-0" />
                        <span>{otpError}</span>
                      </div>
                    )}
                  </div>
                )}

                {/* Thông báo xác thực email thành công */}
                {isEmailVerified && (
                  <div className="p-2.5 rounded-lg bg-emerald-50 border border-emerald-200/80 flex items-center gap-2 text-emerald-800 text-xs font-medium">
                    <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                    <span>Email đã được xác thực thành công qua mã OTP. Nút Đăng ký bên dưới đã được kích hoạt!</span>
                  </div>
                )}
              </div>


              {/* Mật khẩu & Xác nhận */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                  <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider mb-1.5">
                    Mật khẩu <span className="text-rose-500">*</span>
                  </label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                      <Lock className="w-4 h-4" />
                    </div>
                    <input
                      type={showPassword ? 'text' : 'password'}
                      required
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="Tối thiểu 6 ký tự"
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

                <div>
                  <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider mb-1.5">
                    Xác nhận mật khẩu <span className="text-rose-500">*</span>
                  </label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                      <Lock className="w-4 h-4" />
                    </div>
                    <input
                      type={showConfirmPassword ? 'text' : 'password'}
                      required
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      placeholder="Nhập lại mật khẩu"
                      className="w-full pl-10 pr-10 py-2.5 bg-white border border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 rounded-xl text-sm text-neutral-900 placeholder-neutral-400 transition-all outline-none"
                    />
                    <button
                      type="button"
                      onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                      className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-neutral-400 hover:text-neutral-700 cursor-pointer"
                    >
                      {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                    </button>
                  </div>
                </div>
              </div>

              {/* Password Strength Indicator */}
              {password && (
                <div className="p-2.5 rounded-xl bg-neutral-50 border border-neutral-200/80">
                  <div className="flex items-center justify-between text-[11px] mb-1.5">
                    <span className="text-neutral-500">Độ mạnh mật khẩu:</span>
                    <span className="font-semibold text-neutral-900">{passStrength.text}</span>
                  </div>
                  <div className="h-1.5 w-full bg-neutral-200 rounded-full overflow-hidden flex gap-1">
                    <div className={`h-full flex-1 rounded-full ${passStrength.score >= 1 ? passStrength.color : 'bg-neutral-200'}`} />
                    <div className={`h-full flex-1 rounded-full ${passStrength.score >= 2 ? passStrength.color : 'bg-neutral-200'}`} />
                    <div className={`h-full flex-1 rounded-full ${passStrength.score >= 3 ? passStrength.color : 'bg-neutral-200'}`} />
                  </div>
                </div>
              )}

              {/* Terms checkbox */}
              <div className="pt-1">
                <label className="flex items-start gap-2.5 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={acceptTerms}
                    onChange={(e) => setAcceptTerms(e.target.checked)}
                    className="mt-0.5 w-4 h-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-950"
                  />
                  <span className="text-xs text-neutral-600 leading-relaxed">
                    Tôi cam kết thông tin khai báo là chính xác và đồng ý tuân thủ{' '}
                    <span className="text-neutral-950 font-semibold underline">Quy chế ban quản trị tòa nhà</span>.
                  </span>
                </label>
              </div>

              {/* Hướng dẫn khi chưa xác thực email */}
              {!isEmailVerified && (
                <div className="p-3 rounded-xl bg-amber-50 border border-amber-200/90 flex items-start gap-2.5 text-amber-800 text-xs animate-in fade-in duration-200">
                  <Lock className="w-4 h-4 shrink-0 mt-0.5 text-amber-600" />
                  <div>
                    <span className="font-bold">Chưa thể đăng ký: </span>
                    <span>Vui lòng nhấn <strong>"Gửi mã OTP"</strong> ở mục Email và nhập đúng 6 chữ số để kích hoạt nút đăng ký tài khoản.</span>
                  </div>
                </div>
              )}

              {/* Nút Đăng ký tài khoản (Chỉ ấn được khi mã OTP email đúng) */}
              <button
                type="submit"
                disabled={isSubmitting || !isEmailVerified}
                className={`w-full py-3.5 px-4 rounded-xl font-bold text-sm shadow-sm transition-all flex items-center justify-center gap-2 mt-2 ${
                  isEmailVerified
                    ? 'bg-neutral-950 hover:bg-neutral-800 active:scale-[0.99] text-white shadow-md hover:shadow-lg cursor-pointer ring-2 ring-emerald-500/20'
                    : 'bg-neutral-100 text-neutral-400 border border-neutral-200 cursor-not-allowed select-none'
                }`}
              >
                {isSubmitting ? (
                  <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                ) : !isEmailVerified ? (
                  <>
                    <Lock className="w-4 h-4 text-neutral-400" />
                    <span>Cần xác thực mã OTP email để ấn đăng ký</span>
                  </>
                ) : (
                  <>
                    <CheckCircle2 className="w-4 h-4 text-emerald-400" />
                    <span>
                      {registerType === 'OWNER'
                        ? 'Tạo tài khoản Chủ sở hữu'
                        : 'Tạo tài khoản & Xem căn hộ cho thuê'}
                    </span>
                    <ArrowRight className="w-4 h-4" />
                  </>
                )}
              </button>
            </form>

            {/* Bottom Link to Login */}
            <div className="mt-6 pt-5 border-t border-neutral-100 text-center">
              <p className="text-xs text-neutral-600">
                Đã có tài khoản trước đó?{' '}
                <button
                  type="button"
                  onClick={() => onNavigate('/login')}
                  className="font-bold text-neutral-950 hover:underline transition-colors ml-1 cursor-pointer"
                >
                  Đăng nhập tại đây
                </button>
              </p>
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

export default RegisterPage;
