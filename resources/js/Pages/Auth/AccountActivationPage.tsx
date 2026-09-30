import React, { useState, useEffect } from 'react';
import { ShieldCheck, Lock, CheckCircle2, AlertCircle, ArrowRight, Building2, KeyRound } from 'lucide-react';

export const AccountActivationPage: React.FC = () => {
  const [email, setEmail] = useState('');
  const [token, setToken] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [activatedUsername, setActivatedUsername] = useState('');

  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const emailParam = params.get('email') || '';
    const tokenParam = params.get('token') || '';
    setEmail(emailParam);
    setToken(tokenParam);
    if (!tokenParam) {
      setError('Không tìm thấy mã kích hoạt (token) trong liên kết. Vui lòng kiểm tra lại liên kết trong email.');
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
          email,
          token,
          password,
          password_confirmation: passwordConfirmation,
        })
      });

      const json = await res.json();

      if (!res.ok) {
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

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-indigo-950 flex items-center justify-center p-4">
      <div className="w-full max-w-md bg-white/95 backdrop-blur-2xl rounded-3xl p-8 shadow-2xl border border-white/20">
        <div className="text-center mb-6">
          <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-sky-500 to-indigo-600 text-white flex items-center justify-center mx-auto mb-4 shadow-lg shadow-sky-500/20">
            {success ? <CheckCircle2 className="w-7 h-7" /> : <KeyRound className="w-7 h-7" />}
          </div>
          <h1 className="text-xl font-extrabold text-slate-900 tracking-tight uppercase">
            {success ? 'Kích hoạt tài khoản thành công' : 'THIẾT LẬP MẬT KHẨU'}
          </h1>
          <p className="text-xs text-slate-500 mt-1">
            {success
              ? 'Tài khoản của bạn đã sẵn sàng. Vui lòng đăng nhập để bắt đầu sử dụng.'
              : 'Nhập mật khẩu mới và xác nhận mật khẩu để hoàn tất kích hoạt'}
          </p>
        </div>

        {error && (
          <div className="mb-5 p-3.5 bg-rose-50 border border-rose-200/80 rounded-2xl text-xs text-rose-700 flex items-start gap-2.5">
            <AlertCircle className="w-4 h-4 text-rose-500 shrink-0 mt-0.5" />
            <span>{error}</span>
          </div>
        )}

        {success ? (
          <div className="space-y-5 text-center">
            <div className="p-4 bg-emerald-50 border border-emerald-200/60 rounded-2xl text-xs text-emerald-800 leading-relaxed text-left space-y-2">
              <div className="font-semibold text-emerald-900 flex items-center gap-1.5">
                <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                Kích hoạt tài khoản thành công
              </div>
              <p>Mật khẩu của bạn đã được thiết lập an toàn.</p>
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
              className="w-full py-3 px-4 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-sky-600/20 flex items-center justify-center gap-2 transition-all"
            >
              <span>Đăng nhập</span>
              <ArrowRight className="w-4 h-4" />
            </a>
          </div>
        ) : (
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
                <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                <input
                  type="password"
                  required
                  placeholder="Nhập mật khẩu mới (tối thiểu 8 ký tự)..."
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="w-full pl-9 pr-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1.5">
                Xác nhận mật khẩu <span className="text-rose-500">*</span>
              </label>
              <div className="relative">
                <ShieldCheck className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                <input
                  type="password"
                  required
                  placeholder="Nhập lại mật khẩu mới..."
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  className="w-full pl-9 pr-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500 transition-all"
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={loading || !token}
              className="w-full mt-2 py-3 px-4 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-sky-600/20 flex items-center justify-center gap-2 transition-all disabled:opacity-50 tracking-wider"
            >
              {loading ? 'Đang xử lý...' : 'XÁC NHẬN'}
            </button>
          </form>
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
