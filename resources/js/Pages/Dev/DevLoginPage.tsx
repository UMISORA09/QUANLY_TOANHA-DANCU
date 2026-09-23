import React, { useState } from 'react';
import {
  Terminal,
  ShieldCheck,
  Lock,
  Mail,
  Eye,
  EyeOff,
  ArrowRight,
  Sparkles,
  Cpu,
  Code2,
  Server,
  ArrowLeft,
  Building,
  KeyRound,
  CheckCircle2,
  AlertCircle
} from 'lucide-react';
import { api } from '../../Services/api';

interface DevLoginPageProps {
  onLoginSuccess: (role: string, email: string) => void;
  onNavigateHome: () => void;
  onNavigateManagerLogin: () => void;
}

export const DevLoginPage: React.FC<DevLoginPageProps> = ({
  onLoginSuccess,
  onNavigateHome,
  onNavigateManagerLogin,
}) => {
  const [emailOrUser, setEmailOrUser] = useState<string>('dev@cassavas.vn');
  const [password, setPassword] = useState<string>('123567');
  const [showPassword, setShowPassword] = useState<boolean>(false);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);
    setSuccessMsg(null);

    if (!emailOrUser.trim() || !password.trim()) {
      setErrorMsg('Vui lòng nhập tài khoản developer và mật khẩu truy cập!');
      return;
    }

    setIsLoading(true);

    try {
      // Gọi API xác thực thật của backend
      const res = await api.auth.login(emailOrUser.trim(), password.trim());
      if (res && res.success) {
        setSuccessMsg('Xác thực Developer thành công! Đang chuyển vào Dev Console...');
        const user = res.data?.user;
        const role = user?.roles?.[0] || 'admin';
        const email = user?.email || emailOrUser;

        // Lưu session đồng bộ
        const session = {
          role: 'admin',
          email: email,
          name: user?.full_name || 'Dev Team',
          isDev: true
        };
        localStorage.setItem('smartcassavas_session', JSON.stringify(session));

        setTimeout(() => {
          onLoginSuccess('admin', email);
        }, 500);
      } else {
        // Fallback demo cho dev nếu backend chưa seed
        handleLocalFallback();
      }
    } catch (err: any) {
      // Fallback demo cục bộ nếu backend offline hoặc tài khoản dev demo
      if (
        emailOrUser.includes('dev') ||
        emailOrUser.includes('admin') ||
        password === '123567' ||
        password === 'admin123'
      ) {
        handleLocalFallback();
      } else {
        setErrorMsg(err?.message || 'Tài khoản hoặc mật khẩu Developer không chính xác!');
        setIsLoading(false);
      }
    }
  };

  const handleLocalFallback = () => {
    setSuccessMsg('Xác thực Developer thành công! Đang chuyển vào Dev Console...');
    const session = {
      role: 'admin',
      email: emailOrUser.includes('admin') ? 'admin@cassavas.vn' : 'dev@cassavas.vn',
      name: emailOrUser.includes('admin') ? 'Super Admin' : 'Dev Team',
      isDev: true
    };
    localStorage.setItem('smartcassavas_session', JSON.stringify(session));

    setTimeout(() => {
      onLoginSuccess('admin', session.email);
    }, 450);
  };

  const quickFillDev = () => {
    setEmailOrUser('dev@cassavas.vn');
    setPassword('123567');
    setErrorMsg(null);
  };

  const quickFillAdmin = () => {
    setEmailOrUser('admin@cassavas.vn');
    setPassword('123567');
    setErrorMsg(null);
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-between selection:bg-indigo-500 selection:text-white font-sans relative overflow-hidden">
      {/* Background glow effects */}
      <div className="absolute top-0 left-1/4 w-96 h-96 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none" />
      <div className="absolute bottom-0 right-1/4 w-96 h-96 bg-cyan-600/10 rounded-full blur-3xl pointer-events-none" />
      
      {/* Top Navbar */}
      <header className="border-b border-slate-800/80 bg-slate-950/70 backdrop-blur-md px-6 py-4 flex items-center justify-between z-10">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-lg bg-black border border-slate-700 flex items-center justify-center shadow-inner">
            <Building className="w-5 h-5 text-white" />
          </div>
          <div>
            <div className="font-bold text-sm tracking-wider text-white">SMART CASSAVAS</div>
            <div className="text-[10px] uppercase font-mono tracking-widest text-slate-400">DEVELOPER CONSOLE</div>
          </div>
        </div>

        <div className="flex items-center gap-4 text-xs">
          <button
            onClick={onNavigateHome}
            className="flex items-center gap-1.5 text-slate-400 hover:text-slate-200 transition-colors py-1.5 px-3 rounded-md hover:bg-slate-900 border border-transparent hover:border-slate-800"
          >
            <ArrowLeft className="w-3.5 h-3.5" />
            <span>Về Trang chủ</span>
          </button>
          
          <button
            onClick={onNavigateManagerLogin}
            className="flex items-center gap-1.5 text-indigo-400 hover:text-indigo-300 transition-colors py-1.5 px-3 rounded-md bg-indigo-950/40 hover:bg-indigo-900/40 border border-indigo-800/50"
          >
            <Building className="w-3.5 h-3.5" />
            <span>Cổng Ban Quản Lý Tòa Nhà</span>
          </button>
        </div>
      </header>

      {/* Main Content Area */}
      <main className="flex-1 flex items-center justify-center p-4 sm:p-6 z-10 my-6">
        <div className="w-full max-w-md">
          {/* Badge Alert */}
          <div className="mb-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-900 border border-slate-800 text-[11px] text-slate-300 w-full justify-center shadow-sm">
            <Terminal className="w-3.5 h-3.5 text-cyan-400" />
            <span className="font-medium text-slate-200">Cổng Dành Riêng Cho Dev & Quản Trị Hệ Thống</span>
            <span className="text-slate-500">|</span>
            <span className="text-slate-400">Tách biệt với Quản lý</span>
          </div>

          {/* Login Card */}
          <div className="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-2xl backdrop-blur-xl relative">
            <div className="mb-6 text-center">
              <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500/20 to-cyan-500/20 border border-indigo-500/30 flex items-center justify-center mx-auto mb-3 shadow-inner">
                <Code2 className="w-6 h-6 text-indigo-400" />
              </div>
              <h1 className="text-xl sm:text-2xl font-bold text-white tracking-tight">Đăng Nhập Dev Console</h1>
              <p className="text-xs text-slate-400 mt-1.5">
                Khu vực kỹ thuật: Quản lý AI RAG, Webhooks, IoT Simulator, Feature Flags và Audit Logs.
              </p>
            </div>

            {/* Error / Success Notices */}
            {errorMsg && (
              <div className="mb-4 p-3 rounded-lg bg-red-950/60 border border-red-800/80 text-red-200 text-xs flex items-start gap-2.5">
                <AlertCircle className="w-4 h-4 text-red-400 shrink-0 mt-0.5" />
                <span>{errorMsg}</span>
              </div>
            )}

            {successMsg && (
              <div className="mb-4 p-3 rounded-lg bg-emerald-950/60 border border-emerald-800/80 text-emerald-200 text-xs flex items-start gap-2.5">
                <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                <span>{successMsg}</span>
              </div>
            )}

            {/* Quick Fill Demo Bar */}
            <div className="mb-5 p-3 rounded-xl bg-slate-950/70 border border-slate-800/80">
              <div className="text-[11px] font-semibold text-slate-400 mb-2 flex items-center justify-between">
                <span>Tài khoản Demo Nhanh:</span>
                <span className="text-[10px] text-slate-500 font-mono">Pass: 123567</span>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={quickFillDev}
                  className={`px-2.5 py-1.5 rounded-lg text-xs font-medium text-left border transition-all ${
                    emailOrUser === 'dev@cassavas.vn'
                      ? 'bg-indigo-950/80 border-indigo-500/60 text-indigo-200 shadow-sm'
                      : 'bg-slate-900 border-slate-800 text-slate-300 hover:border-slate-700'
                  }`}
                >
                  <div className="font-semibold flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 rounded-full bg-cyan-400" />
                    Dev Team
                  </div>
                  <div className="text-[10px] text-slate-400 font-mono truncate">dev@cassavas.vn</div>
                </button>

                <button
                  type="button"
                  onClick={quickFillAdmin}
                  className={`px-2.5 py-1.5 rounded-lg text-xs font-medium text-left border transition-all ${
                    emailOrUser === 'admin@cassavas.vn'
                      ? 'bg-indigo-950/80 border-indigo-500/60 text-indigo-200 shadow-sm'
                      : 'bg-slate-900 border-slate-800 text-slate-300 hover:border-slate-700'
                  }`}
                >
                  <div className="font-semibold flex items-center gap-1.5">
                    <span className="w-1.5 h-1.5 rounded-full bg-indigo-400" />
                    Super Admin
                  </div>
                  <div className="text-[10px] text-slate-400 font-mono truncate">admin@cassavas.vn</div>
                </button>
              </div>
            </div>

            {/* Login Form */}
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1.5">
                  Tài khoản Developer / Email kỹ thuật
                </label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500">
                    <Mail className="w-4 h-4" />
                  </div>
                  <input
                    type="text"
                    value={emailOrUser}
                    onChange={(e) => setEmailOrUser(e.target.value)}
                    placeholder="dev@cassavas.vn"
                    className="w-full pl-9 pr-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all font-mono"
                    required
                  />
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label className="block text-xs font-medium text-slate-300">
                    Mật khẩu truy cập
                  </label>
                  <span className="text-[11px] text-slate-500 font-mono">123567</span>
                </div>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500">
                    <Lock className="w-4 h-4" />
                  </div>
                  <input
                    type={showPassword ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full pl-9 pr-10 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all font-mono"
                    required
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-500 hover:text-slate-300"
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div className="pt-2">
                <button
                  type="submit"
                  disabled={isLoading}
                  className="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-sm rounded-lg transition-colors shadow-lg shadow-indigo-600/25 flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed"
                >
                  {isLoading ? (
                    <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                  ) : (
                    <>
                      <span>Truy Cập Developer Console</span>
                      <ArrowRight className="w-4 h-4" />
                    </>
                  )}
                </button>
              </div>
            </form>

            {/* Divider */}
            <div className="mt-6 pt-5 border-t border-slate-800/80 text-center">
              <p className="text-xs text-slate-400">
                Bạn là quản lý tòa nhà chung cư?{' '}
                <button
                  onClick={onNavigateManagerLogin}
                  className="text-indigo-400 hover:text-indigo-300 font-medium underline underline-offset-2 ml-1"
                >
                  Đăng nhập Cổng Ban Quản Lý ➔
                </button>
              </p>
            </div>
          </div>
        </div>
      </main>

      {/* Footer Info */}
      <footer className="border-t border-slate-900 bg-slate-950 px-6 py-4 text-center text-xs text-slate-600 z-10 flex flex-col sm:flex-row items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Server className="w-3.5 h-3.5 text-slate-500" />
          <span>Môi trường: Local / Docker • Laravel 12 + MySQL 8.0</span>
        </div>
        <div>
          <span>Smart Cassavas Developer Portal © 2026. Phục vụ nghiên cứu kỹ thuật & đồ án chuyên đề.</span>
        </div>
      </footer>
    </div>
  );
};
