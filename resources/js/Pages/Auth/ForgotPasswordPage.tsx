import React, { useState, useEffect, useRef } from 'react';
import {
  Building2,
  Lock,
  Mail,
  Eye,
  EyeOff,
  ArrowRight,
  ArrowLeft,
  CheckCircle2,
  KeyRound,
  RotateCcw,
  Sparkles,
  AlertCircle,
  Copy,
  Check
} from 'lucide-react';
import { api } from '../../Services/api';

interface ForgotPasswordPageProps {
  onNavigate: (path: string) => void;
}

type Step = 'REQUEST_OTP' | 'VERIFY_OTP' | 'RESET_PASSWORD' | 'SUCCESS';

export const ForgotPasswordPage: React.FC<ForgotPasswordPageProps> = ({ onNavigate }) => {
  const [currentStep, setCurrentStep] = useState<Step>('REQUEST_OTP');
  const [email, setEmail] = useState('');
  const [otpDigits, setOtpDigits] = useState(['', '', '', '', '', '']);
  const [debugOtp, setDebugOtp] = useState<string | null>(null);
  const [resetToken, setResetToken] = useState<string | null>(null);

  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // Countdown timer for resend OTP
  const [countdown, setCountdown] = useState(60);
  const [canResend, setCanResend] = useState(false);

  const [copiedOtp, setCopiedOtp] = useState(false);
  const inputRefs = useRef<(HTMLInputElement | null)[]>([]);

  useEffect(() => {
    let timer: NodeJS.Timeout;
    if (currentStep === 'VERIFY_OTP' && countdown > 0) {
      timer = setTimeout(() => setCountdown(countdown - 1), 1000);
    } else if (countdown === 0) {
      setCanResend(true);
    }
    return () => clearTimeout(timer);
  }, [countdown, currentStep]);

  // Handle single digit input
  const handleDigitChange = (index: number, value: string) => {
    if (!/^\d*$/.test(value)) return;

    const newDigits = [...otpDigits];
    newDigits[index] = value.slice(-1);
    setOtpDigits(newDigits);

    // Auto advance to next input
    if (value && index < 5) {
      inputRefs.current[index + 1]?.focus();
    }
  };

  const handleKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !otpDigits[index] && index > 0) {
      inputRefs.current[index - 1]?.focus();
    }
  };

  const handlePaste = (e: React.ClipboardEvent) => {
    e.preventDefault();
    const pasted = e.clipboardData.getData('text').trim();
    if (/^\d{6}$/.test(pasted)) {
      const arr = pasted.split('');
      setOtpDigits(arr);
      inputRefs.current[5]?.focus();
    }
  };

  // Step 1: Request OTP
  const handleRequestOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    const cleanEmail = email.trim().toLowerCase();
    if (!cleanEmail || !cleanEmail.includes('@')) {
      setErrorMessage('Vui lòng nhập địa chỉ email hợp lệ.');
      return;
    }

    setIsLoading(true);

    try {
      const res = await api.forgotPassword(cleanEmail);
      if (res.debug_otp) {
        setDebugOtp(res.debug_otp);
      }
      setToastMessage(res.message || 'Mã xác thực OTP đã được gửi đến email của bạn.');
      setCurrentStep('VERIFY_OTP');
      setCountdown(60);
      setCanResend(false);
      setTimeout(() => inputRefs.current[0]?.focus(), 200);
    } catch (err: any) {
      setErrorMessage(
        err.message || 'Không tìm thấy tài khoản tương ứng với email này.'
      );
    } finally {
      setIsLoading(false);
    }
  };

  // Resend OTP
  const handleResendOtp = async () => {
    if (!canResend || isLoading) return;
    setErrorMessage(null);
    setIsLoading(true);

    try {
      const res = await api.forgotPassword(email.trim().toLowerCase());
      if (res.debug_otp) {
        setDebugOtp(res.debug_otp);
      }
      setToastMessage('Đã gửi lại mã OTP mới đến email của bạn.');
      setCountdown(60);
      setCanResend(false);
      setOtpDigits(['', '', '', '', '', '']);
      inputRefs.current[0]?.focus();
    } catch (err: any) {
      setErrorMessage(err.message || 'Lỗi khi gửi lại mã OTP. Vui lòng thử lại sau.');
    } finally {
      setIsLoading(false);
    }
  };

  // Step 2: Verify OTP
  const handleVerifyOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    const otpCode = otpDigits.join('');
    if (otpCode.length !== 6) {
      setErrorMessage('Vui lòng nhập đầy đủ 6 chữ số của mã OTP.');
      return;
    }

    setIsLoading(true);

    try {
      const res = await api.verifyOtp(email.trim().toLowerCase(), otpCode);
      if (res.reset_token) {
        setResetToken(res.reset_token);
      }
      setToastMessage('Xác thực OTP thành công! Vui lòng tạo mật khẩu mới.');
      setCurrentStep('RESET_PASSWORD');
    } catch (err: any) {
      setErrorMessage(err.message || 'Mã OTP không chính xác hoặc đã hết hạn.');
    } finally {
      setIsLoading(false);
    }
  };

  // Step 3: Reset Password
  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    if (newPassword.length < 6) {
      setErrorMessage('Mật khẩu mới phải có tối thiểu 6 ký tự.');
      return;
    }
    if (newPassword !== confirmPassword) {
      setErrorMessage('Mật khẩu xác nhận không khớp. Vui lòng nhập lại.');
      return;
    }
    if (!resetToken) {
      setErrorMessage('Phiên làm việc hết hạn. Vui lòng thử lại từ bước đầu.');
      setCurrentStep('REQUEST_OTP');
      return;
    }

    setIsLoading(true);

    try {
      await api.resetPassword({
        email: email.trim().toLowerCase(),
        reset_token: resetToken,
        password: newPassword,
        password_confirmation: confirmPassword,
      });

      setCurrentStep('SUCCESS');
    } catch (err: any) {
      setErrorMessage(err.message || 'Lỗi khi đặt lại mật khẩu. Vui lòng thử lại.');
    } finally {
      setIsLoading(false);
    }
  };

  const getStrength = (pass: string) => {
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

  const strength = getStrength(newPassword);

  const copyDebugCode = () => {
    if (!debugOtp) return;
    navigator.clipboard.writeText(debugOtp);
    setCopiedOtp(true);
    setOtpDigits(debugOtp.split(''));
    setTimeout(() => setCopiedOtp(false), 2000);
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

      {/* Main Area */}
      <main className="relative z-10 flex-1 flex items-center justify-center px-4 py-10 sm:py-14">
        <div className="w-full max-w-lg">
          <div className="bg-white/90 backdrop-blur-2xl border border-neutral-200/90 rounded-2xl p-7 sm:p-9 shadow-xl relative overflow-hidden">
            {/* Step Wizard Indicator */}
            {currentStep !== 'SUCCESS' && (
              <div className="mb-7">
                <div className="flex items-center justify-between text-[11px] font-semibold text-neutral-500 mb-2">
                  <span className={currentStep === 'REQUEST_OTP' ? 'text-neutral-950 font-bold' : ''}>
                    1. Gửi OTP
                  </span>
                  <span className={currentStep === 'VERIFY_OTP' ? 'text-neutral-950 font-bold' : ''}>
                    2. Xác thực OTP
                  </span>
                  <span className={currentStep === 'RESET_PASSWORD' ? 'text-neutral-950 font-bold' : ''}>
                    3. Mật khẩu mới
                  </span>
                </div>
                <div className="h-1.5 w-full bg-neutral-100 rounded-full overflow-hidden flex gap-1">
                  <div
                    className={`h-full flex-1 rounded-full transition-all duration-300 ${
                      currentStep === 'REQUEST_OTP' || currentStep === 'VERIFY_OTP' || currentStep === 'RESET_PASSWORD'
                        ? 'bg-neutral-950'
                        : 'bg-neutral-200'
                    }`}
                  />
                  <div
                    className={`h-full flex-1 rounded-full transition-all duration-300 ${
                      currentStep === 'VERIFY_OTP' || currentStep === 'RESET_PASSWORD'
                        ? 'bg-neutral-950'
                        : 'bg-neutral-200'
                    }`}
                  />
                  <div
                    className={`h-full flex-1 rounded-full transition-all duration-300 ${
                      currentStep === 'RESET_PASSWORD'
                        ? 'bg-neutral-950'
                        : 'bg-neutral-200'
                    }`}
                  />
                </div>
              </div>
            )}

            {/* Error Message */}
            {errorMessage && (
              <div className="mb-5 p-3 rounded-xl bg-rose-50 border border-rose-200 flex items-start gap-2.5 text-rose-700 text-xs">
                <AlertCircle className="w-4 h-4 shrink-0 mt-0.5 text-rose-600" />
                <span>{errorMessage}</span>
              </div>
            )}

            {/* Toast Message */}
            {toastMessage && (
              <div className="mb-5 p-3 rounded-xl bg-emerald-50 border border-emerald-200 flex items-start gap-2.5 text-emerald-800 text-xs">
                <CheckCircle2 className="w-4 h-4 shrink-0 mt-0.5 text-emerald-600" />
                <span>{toastMessage}</span>
              </div>
            )}

            {/* ================= STEP 1: REQUEST OTP ================= */}
            {currentStep === 'REQUEST_OTP' && (
              <div>
                <div className="text-center mb-6">
                  <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-neutral-100 border border-neutral-200 text-neutral-800 text-xs font-semibold mb-3">
                    <KeyRound className="w-3.5 h-3.5 text-neutral-900" />
                    <span>Khôi Phục Mật Khẩu</span>
                  </div>
                  <h1 className="text-2xl font-bold tracking-tight text-neutral-950 mb-2">
                    Quên mật khẩu?
                  </h1>
                  <p className="text-xs sm:text-sm text-neutral-500 leading-relaxed">
                    Nhập email tài khoản của bạn để nhận mã xác thực OTP 6 chữ số
                  </p>
                </div>

                <form onSubmit={handleRequestOtp} className="space-y-4">
                  <div>
                    <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider mb-1.5">
                      Địa chỉ Email tài khoản
                    </label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                        <Mail className="w-4 h-4" />
                      </div>
                      <input
                        type="email"
                        required
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="nguyenvanan@cassavas.vn"
                        className="w-full pl-10 pr-4 py-2.5 bg-white border border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 rounded-xl text-sm text-neutral-900 placeholder-neutral-400 transition-all outline-none"
                      />
                    </div>
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
                        <span>Gửi mã xác thực OTP qua Email</span>
                        <ArrowRight className="w-4 h-4" />
                      </>
                    )}
                  </button>
                </form>

                <div className="mt-6 pt-5 border-t border-neutral-100 text-center">
                  <button
                    type="button"
                    onClick={() => onNavigate('/login')}
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-neutral-700 hover:text-neutral-950 transition-colors cursor-pointer"
                  >
                    <ArrowLeft className="w-3.5 h-3.5" />
                    <span>Quay lại trang Đăng nhập</span>
                  </button>
                </div>
              </div>
            )}

            {/* ================= STEP 2: VERIFY OTP ================= */}
            {currentStep === 'VERIFY_OTP' && (
              <div>
                <div className="text-center mb-6">
                  <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-neutral-100 border border-neutral-200 text-neutral-800 text-xs font-semibold mb-3">
                    <Sparkles className="w-3.5 h-3.5 text-emerald-600" />
                    <span>Mã OTP 6 Số</span>
                  </div>
                  <h1 className="text-2xl font-bold tracking-tight text-neutral-950 mb-2">
                    Xác thực mã OTP
                  </h1>
                  <p className="text-xs sm:text-sm text-neutral-500 leading-relaxed">
                    Mã 6 chữ số đã được gửi đến <span className="font-semibold text-neutral-900">{email}</span>
                  </p>
                </div>

                {/* Local Debug Box */}
                {debugOtp && (
                  <div className="mb-5 p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-semibold text-amber-900">Mã OTP thử nghiệm:</span>
                      <span className="font-mono font-bold text-amber-950 tracking-widest text-sm bg-white px-2 py-0.5 rounded border border-amber-300">
                        {debugOtp}
                      </span>
                    </div>
                    <button
                      type="button"
                      onClick={copyDebugCode}
                      className="text-xs font-semibold text-amber-900 hover:text-amber-950 flex items-center gap-1 bg-amber-100/70 hover:bg-amber-100 px-2 py-1 rounded transition-colors cursor-pointer"
                    >
                      {copiedOtp ? (
                        <>
                          <Check className="w-3.5 h-3.5 text-emerald-600" />
                          <span>Đã điền</span>
                        </>
                      ) : (
                        <>
                          <Copy className="w-3.5 h-3.5" />
                          <span>Tự điền</span>
                        </>
                      )}
                    </button>
                  </div>
                )}

                <form onSubmit={handleVerifyOtp} className="space-y-5">
                  {/* 6 OTP Boxes */}
                  <div className="flex justify-center items-center gap-2 sm:gap-2.5" onPaste={handlePaste}>
                    {otpDigits.map((digit, idx) => (
                      <input
                        key={idx}
                        ref={(el) => { inputRefs.current[idx] = el; }}
                        type="text"
                        inputMode="numeric"
                        maxLength={1}
                        value={digit}
                        onChange={(e) => handleDigitChange(idx, e.target.value)}
                        onKeyDown={(e) => handleKeyDown(idx, e)}
                        className="w-11 sm:w-12 h-13 sm:h-14 text-center text-xl font-bold bg-white border border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 rounded-xl text-neutral-900 transition-all outline-none"
                      />
                    ))}
                  </div>

                  {/* Resend Countdown */}
                  <div className="flex items-center justify-center gap-2 text-xs text-neutral-500 pt-1">
                    {canResend ? (
                      <button
                        type="button"
                        onClick={handleResendOtp}
                        disabled={isLoading}
                        className="font-bold text-neutral-950 hover:underline flex items-center gap-1 cursor-pointer"
                      >
                        <RotateCcw className="w-3.5 h-3.5" />
                        <span>Gửi lại mã OTP</span>
                      </button>
                    ) : (
                      <span>
                        Gửi lại mã sau <span className="font-mono font-bold text-neutral-950">{countdown}s</span>
                      </span>
                    )}
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
                        <span>Xác nhận mã OTP</span>
                        <ArrowRight className="w-4 h-4" />
                      </>
                    )}
                  </button>
                </form>

                <div className="mt-6 pt-5 border-t border-neutral-100 flex items-center justify-between text-xs">
                  <button
                    type="button"
                    onClick={() => setCurrentStep('REQUEST_OTP')}
                    className="text-neutral-600 hover:text-neutral-950 transition-colors cursor-pointer"
                  >
                    ← Đổi địa chỉ email khác
                  </button>
                  <button
                    type="button"
                    onClick={() => onNavigate('/login')}
                    className="font-semibold text-neutral-700 hover:text-neutral-950 transition-colors cursor-pointer"
                  >
                    Về Đăng nhập
                  </button>
                </div>
              </div>
            )}

            {/* ================= STEP 3: RESET PASSWORD ================= */}
            {currentStep === 'RESET_PASSWORD' && (
              <div>
                <div className="text-center mb-6">
                  <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-neutral-100 border border-neutral-200 text-neutral-800 text-xs font-semibold mb-3">
                    <Lock className="w-3.5 h-3.5 text-neutral-900" />
                    <span>Mật Khẩu Mới</span>
                  </div>
                  <h1 className="text-2xl font-bold tracking-tight text-neutral-950 mb-2">
                    Tạo mật khẩu mới
                  </h1>
                  <p className="text-xs sm:text-sm text-neutral-500 leading-relaxed">
                    Vui lòng nhập mật khẩu mới và xác nhận để hoàn tất khôi phục
                  </p>
                </div>

                <form onSubmit={handleResetPassword} className="space-y-4">
                  <div>
                    <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider mb-1.5">
                      Mật khẩu mới <span className="text-rose-500">*</span>
                    </label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-neutral-400">
                        <Lock className="w-4 h-4" />
                      </div>
                      <input
                        type={showNewPassword ? 'text' : 'password'}
                        required
                        value={newPassword}
                        onChange={(e) => setNewPassword(e.target.value)}
                        placeholder="Tối thiểu 6 ký tự"
                        className="w-full pl-10 pr-10 py-2.5 bg-white border border-neutral-200 focus:border-neutral-950 focus:ring-2 focus:ring-neutral-950/10 rounded-xl text-sm text-neutral-900 placeholder-neutral-400 transition-all outline-none"
                      />
                      <button
                        type="button"
                        onClick={() => setShowNewPassword(!showNewPassword)}
                        className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-neutral-400 hover:text-neutral-700 cursor-pointer"
                      >
                        {showNewPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                      </button>
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-neutral-800 uppercase tracking-wider mb-1.5">
                      Xác nhận mật khẩu mới <span className="text-rose-500">*</span>
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
                        placeholder="Nhập lại mật khẩu mới"
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

                  {/* Strength Bar */}
                  {newPassword && (
                    <div className="p-2.5 rounded-xl bg-neutral-50 border border-neutral-200/80">
                      <div className="flex items-center justify-between text-[11px] mb-1.5">
                        <span className="text-neutral-500">Độ mạnh mật khẩu:</span>
                        <span className="font-semibold text-neutral-900">{strength.text}</span>
                      </div>
                      <div className="h-1.5 w-full bg-neutral-200 rounded-full overflow-hidden flex gap-1">
                        <div className={`h-full flex-1 rounded-full ${strength.score >= 1 ? strength.color : 'bg-neutral-200'}`} />
                        <div className={`h-full flex-1 rounded-full ${strength.score >= 2 ? strength.color : 'bg-neutral-200'}`} />
                        <div className={`h-full flex-1 rounded-full ${strength.score >= 3 ? strength.color : 'bg-neutral-200'}`} />
                      </div>
                    </div>
                  )}

                  <button
                    type="submit"
                    disabled={isLoading}
                    className="w-full py-3 px-4 rounded-xl bg-neutral-950 hover:bg-neutral-800 active:scale-[0.99] text-white font-medium text-sm shadow-sm hover:shadow transition-all flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer mt-2"
                  >
                    {isLoading ? (
                      <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                    ) : (
                      <>
                        <span>Lưu mật khẩu mới & Đăng nhập</span>
                        <ArrowRight className="w-4 h-4" />
                      </>
                    )}
                  </button>
                </form>
              </div>
            )}

            {/* ================= STEP 4: SUCCESS ================= */}
            {currentStep === 'SUCCESS' && (
              <div className="text-center py-4">
                <div className="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-4 shadow-sm">
                  <CheckCircle2 className="w-9 h-9" />
                </div>
                <h2 className="text-2xl font-bold text-neutral-950 mb-2">
                  Đổi mật khẩu thành công!
                </h2>
                <p className="text-xs sm:text-sm text-neutral-500 mb-7 max-w-sm mx-auto leading-relaxed">
                  Mật khẩu mới của bạn đã được cập nhật thành công vào hệ thống. Bạn có thể đăng nhập ngay bây giờ.
                </p>
                <button
                  type="button"
                  onClick={() => onNavigate('/login')}
                  className="w-full py-3 px-4 rounded-xl bg-neutral-950 hover:bg-neutral-800 active:scale-[0.99] text-white font-medium text-sm shadow-sm hover:shadow transition-all flex items-center justify-center gap-2 cursor-pointer"
                >
                  <span>Chuyển đến trang Đăng nhập</span>
                  <ArrowRight className="w-4 h-4" />
                </button>
              </div>
            )}
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

export default ForgotPasswordPage;
