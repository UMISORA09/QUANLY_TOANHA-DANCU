import React, { useState, useEffect } from 'react';
import { ShieldCheck, Lock, CheckCircle2, AlertCircle, ArrowRight, Building2, KeyRound, Eye, EyeOff, UserCheck } from 'lucide-react';

export const AccountActivationPage: React.FC = () => {
  const [email, setEmail] = useState('');
  const [token, setToken] = useState('');
  const [userId, setUserId] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);
  const [activatedUsername, setActivatedUsername] = useState('');
  const [activatedFullName, setActivatedFullName] = useState('');

  const [checkingStatus, setCheckingStatus] = useState(true);
  const [isAlreadyActivated, setIsAlreadyActivated] = useState(false);
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const emailParam = params.get('email') || '';
    const tokenParam = params.get('token') || '';
    const idParam = params.get('id') || '';
    setEmail(emailParam);
    setToken(tokenParam);
    setUserId(idParam);

    if (!tokenParam && !emailParam && !idParam) {
      setError('Không tìm thấy thông tin kích hoạt trong liên kết. Vui lòng kiểm tra lại liên kết trong email.');
      setCheckingStatus(false);
      return;
    }

    // Kiểm tra trạng thái tài khoản: Nếu đã kích hoạt trước đó (xác nhận lần 2)
    if (emailParam || idParam) {
      const query = new URLSearchParams();
      if (emailParam) query.set('email', emailParam);
      if (idParam) query.set('id', idParam);

      fetch(`/api/v1/account-provisioning/status?${query.toString()}`, {
        headers: { 'Accept': 'application/json' }
      })
        .then(res => res.json())
        .then(data => {
          if (data.is_activated || data.status === 'ALREADY_ACTIVE') {
            setIsAlreadyActivated(true);
            if (data.username) setActivatedUsername(data.username);
            if (data.full_name) setActivatedFullName(data.full_name);
          } else if (!tokenParam) {
            setError('Không tìm thấy mã kích hoạt (token) trong liên kết. Vui lòng kiểm tra lại liên kết trong email.');
          }
        })
        .catch(err => {
          console.error('Lỗi kiểm tra trạng thái kích hoạt:', err);
          if (!tokenParam) {
            setError('Không tìm thấy mã kích hoạt (token) trong liên kết. Vui lòng kiểm tra lại liên kết trong email.');
          }
        })
        .finally(() => {
          setCheckingStatus(false);
        });
    } else {
      if (!tokenParam) {
        setError('Không tìm thấy mã kích hoạt (token) trong liên kết. Vui lòng kiểm tra lại liên kết trong email.');
      }
      setCheckingStatus(false);
    }
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!password || !passwordConfirmation) {
      setError('Vui lòng nhập đầy đủ mật khẩu mới và xác nhận mật khẩu.');
      return;
    }

    if (password.length < 8) {
      setError('Mật khẩu phải có độ dài tối thiểu 8 ký tự.');
      return;
    }

    if (password !== passwordConfirmation) {
      setError('Mật khẩu xác nhận không khớp với mật khẩu mới.');
      return;
    }

    try {
      setLoading(true);
      const res = await fetch('/api/v1/account-provisioning/activate', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          id: userId || undefined,
          email,
          token,
          password,
          password_confirmation: passwordConfirmation,
        })
      });

      const json = await res.json();

      if (!res.ok) {
        // Nếu tài khoản đã xác nhận rồi hoặc token đã sử dụng (xác nhận lần 2)
        if (
          json.error === 'ALREADY_ACTIVE' ||
          (json.message && (
            json.message.toLowerCase().includes('đã kích hoạt') ||
            json.message.toLowerCase().includes('đã được kích hoạt') ||
            json.message.toLowerCase().includes('đã được sử dụng')
          ))
        ) {
          setIsAlreadyActivated(true);
          return;
        }
        throw new Error(json.message || 'Kích hoạt tài khoản thất bại.');
      }

      setActivatedUsername(json.data?.username || '');
      setSuccess(true);
    } catch (err: any) {
      setError(err.message || 'Đã xảy ra lỗi trong quá trình kích hoạt.');
    } finally {
      setLoading(false);
    }
  };

  const isAlreadyActive = error && (error.toLowerCase().includes('kích hoạt') || error.toLowerCase().includes('đã kích hoạt'));

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 flex items-center justify-center p-4">
      <div className="w-full max-w-md bg-white/95 backdrop-blur-2xl rounded-3xl p-8 shadow-2xl border border-white/20">
        
        {/* Trường hợp 1: Đang kiểm tra trạng thái liên kết */}
        {checkingStatus ? (
          <div className="py-16 flex flex-col items-center justify-center gap-3 text-slate-500">
            <div className="w-9 h-9 border-3 border-sky-500 border-t-transparent rounded-full animate-spin" />
            <span className="text-xs font-medium">Đang kiểm tra trạng thái tài khoản...</span>
          </div>
        ) : isAlreadyActivated ? (
          /* Trường hợp 2: Đã kích hoạt 1 lần rồi, lần 2 báo Đã kích hoạt tài khoản bạn vui lòng đăng nhập */
          <div className="space-y-6 text-center">
            <div className="w-16 h-16 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-600 text-white flex items-center justify-center mx-auto shadow-lg shadow-emerald-500/20">
              <CheckCircle2 className="w-8 h-8" />
            </div>

            <div>
              <h1 className="text-xl font-extrabold text-slate-900 tracking-tight">
                Đã kích hoạt tài khoản bạn vui lòng đăng nhập
              </h1>
              <p className="text-xs text-slate-500 mt-2">
                Liên kết kích hoạt chỉ có hiệu lực sử dụng 1 lần. Tài khoản của bạn đã được kích hoạt thành công.
              </p>
            </div>

            <div className="p-4 bg-emerald-50 border border-emerald-200/80 rounded-2xl text-xs text-emerald-900 leading-relaxed text-left space-y-3">
              <div className="flex items-center gap-2 text-emerald-800 font-bold">
                <ShieldCheck className="w-4 h-4 text-emerald-600 shrink-0" />
                <span>Trạng thái: Đã kích hoạt (Hoạt động)</span>
              </div>

              <div className="bg-white/90 p-3 rounded-xl border border-emerald-200/60 space-y-1.5 font-sans">
                {activatedFullName && (
                  <div className="flex justify-between items-center text-xs">
                    <span className="text-slate-500">Họ và tên:</span>
                    <span className="font-semibold text-slate-800">{activatedFullName}</span>
                  </div>
                )}
                {email && (
                  <div className="flex justify-between items-center text-xs">
                    <span className="text-slate-500">Email:</span>
                    <span className="font-mono text-slate-700">{email}</span>
                  </div>
                )}
                {activatedUsername && (
                  <div className="flex justify-between items-center text-xs pt-1 border-t border-slate-100">
                    <span className="text-slate-500">Tên đăng nhập:</span>
                    <span className="font-mono font-bold text-slate-900">{activatedUsername}</span>
                  </div>
                )}
              </div>

              <p className="text-[11px] text-emerald-800 leading-normal font-medium bg-emerald-100/60 p-2.5 rounded-xl border border-emerald-300/40">
                Tài khoản đã sẵn sàng hoạt động. Quý cư dân / nhân viên vui lòng bấm nút <strong>ĐĂNG NHẬP NGAY</strong> để đăng nhập bằng mật khẩu đã thiết lập.
              </p>
            </div>

            <a
              href="/login"
              className="w-full py-3.5 px-4 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-sky-600/20 flex items-center justify-center gap-2 transition-all tracking-wider"
            >
              <span>ĐĂNG NHẬP NGAY</span>
              <ArrowRight className="w-4 h-4" />
            </a>
          </div>
        ) : success ? (
          /* Trường hợp 3: Vừa kích hoạt thành công lần đầu */
          <div className="space-y-5 text-center">
            <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-600 text-white flex items-center justify-center mx-auto shadow-lg shadow-emerald-500/20">
              <CheckCircle2 className="w-7 h-7" />
            </div>

            <div>
              <h1 className="text-xl font-extrabold text-slate-900 tracking-tight uppercase">
                Kích hoạt tài khoản thành công
              </h1>
              <p className="text-xs text-slate-500 mt-1">
                Tài khoản của bạn đã sẵn sàng. Vui lòng đăng nhập để bắt đầu sử dụng.
              </p>
            </div>

            <div className="p-4 bg-emerald-50 border border-emerald-200/60 rounded-2xl text-xs text-emerald-800 leading-relaxed text-left space-y-2">
              <div className="font-semibold text-emerald-900 flex items-center gap-1.5">
                <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                Mật khẩu của bạn đã được thiết lập an toàn.
              </div>
              {activatedUsername && (
                <div className="bg-white/80 p-2.5 rounded-xl border border-emerald-200/60 font-mono text-xs">
                  <span className="text-slate-500 block text-[10px] uppercase font-sans">Tên đăng nhập (Username):</span>
                  <span className="font-bold text-slate-900 text-sm">{activatedUsername}</span>
                </div>
              )}
              <p className="text-[11px] text-slate-600">
                Nhân viên sử dụng <strong>Tên đăng nhập (Username)</strong> và <strong>Mật khẩu vừa đặt</strong> để đăng nhập vào hệ thống.
              </p>
            </div>

            <a
              href="/login"
              className="w-full py-3.5 px-4 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-sky-600/20 flex items-center justify-center gap-2 transition-all tracking-wider"
            >
              <span>ĐĂNG NHẬP</span>
              <ArrowRight className="w-4 h-4" />
            </a>
          </div>
        ) : (
          /* Trường hợp 4: Form thiết lập lần đầu */
          <div>
            <div className="text-center mb-6">
              <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-sky-500 to-indigo-600 text-white flex items-center justify-center mx-auto mb-4 shadow-lg shadow-sky-500/20">
                <KeyRound className="w-7 h-7" />
              </div>
              <h1 className="text-xl font-extrabold text-slate-900 tracking-tight uppercase">
                THIẾT LẬP TÀI KHOẢN
              </h1>
              <p className="text-xs text-slate-500 mt-1">
                Thiết lập mật khẩu cá nhân để hoàn tất kích hoạt tài khoản
              </p>
              <div className="mt-2 inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-sky-50 border border-sky-200/80 text-[11px] font-medium text-sky-700">
                <UserCheck className="w-3.5 h-3.5" />
                <span>Liên kết dùng 1 lần cho tài khoản duy nhất</span>
              </div>
            </div>

            {error && (
              <div className="mb-5 p-3.5 bg-rose-50 border border-rose-200/80 rounded-2xl text-xs text-rose-700 space-y-2">
                <div className="flex items-start gap-2.5">
                  <AlertCircle className="w-4 h-4 text-rose-500 shrink-0 mt-0.5" />
                  <span>{error}</span>
                </div>
                {isAlreadyActive && (
                  <div className="pt-2 border-t border-rose-200/60 text-right">
                    <a
                      href="/login"
                      className="inline-flex items-center gap-1 text-[11px] font-bold text-rose-800 hover:text-rose-900 underline"
                    >
                      <span>Chuyển tới trang Đăng nhập</span>
                      <ArrowRight className="w-3 h-3" />
                    </a>
                  </div>
                )}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4">
              {email && (
                <div>
                  <label className="block text-xs font-medium text-slate-700 mb-1.5">
                    Email tài khoản
                  </label>
                  <input
                    type="email"
                    disabled
                    value={email}
                    className="w-full px-3.5 py-2.5 text-xs bg-slate-100 border border-slate-200 rounded-xl text-slate-500 font-mono cursor-not-allowed"
                  />
                </div>
              )}

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Mật khẩu mới <span className="text-rose-500">*</span>
                </label>
                <div className="relative">
                  <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-3 pointer-events-none" />
                  <input
                    type={showPassword ? 'text' : 'password'}
                    required
                    placeholder="Nhập mật khẩu mới (tối thiểu 8 ký tự)..."
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="w-full pl-9 pr-10 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-3 top-2.5 p-1 text-slate-400 hover:text-slate-600 focus:outline-none transition-colors"
                    title={showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                    aria-label={showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1.5">
                  Xác nhận mật khẩu <span className="text-rose-500">*</span>
                </label>
                <div className="relative">
                  <ShieldCheck className="w-4 h-4 text-slate-400 absolute left-3 top-3 pointer-events-none" />
                  <input
                    type={showPasswordConfirmation ? 'text' : 'password'}
                    required
                    placeholder="Nhập lại mật khẩu mới..."
                    value={passwordConfirmation}
                    onChange={(e) => setPasswordConfirmation(e.target.value)}
                    className="w-full pl-9 pr-10 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
                    className="absolute right-3 top-2.5 p-1 text-slate-400 hover:text-slate-600 focus:outline-none transition-colors"
                    title={showPasswordConfirmation ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                    aria-label={showPasswordConfirmation ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}
                  >
                    {showPasswordConfirmation ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <button
                type="submit"
                disabled={loading || !token}
                className="w-full mt-2 py-3.5 px-4 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-sky-600/20 flex items-center justify-center gap-2 transition-all disabled:opacity-50 tracking-wider"
              >
                {loading ? 'Đang xử lý...' : 'XÁC NHẬN'}
              </button>
            </form>
          </div>
        )}

        <div className="mt-6 pt-4 border-t border-slate-100 text-center">
          <span className="text-[11px] text-slate-400">
            Hệ thống Quản lý Tòa nhà & Cư dân Cassavas
          </span>
        </div>
      </div>
    </div>
  );
};
export default AccountActivationPage;
