import React, { useEffect, useRef, useState } from 'react';
import { Copy, X } from 'lucide-react';
import { AmenityBooking, AmenityBookingPayment, ApiError, api } from '../Services/api';

export const paymentStatusNames: Record<AmenityBookingPayment['status'], string> = {
  WAITING_APPROVAL: 'Thanh toán sau khi được duyệt', PENDING: 'Chờ thanh toán', REPORTED: 'Chờ đối soát',
  PAID: 'Đã thanh toán', EXPIRED: 'Hết hạn thanh toán', CANCELLED: 'Thanh toán đã hủy', REVIEW: 'Cần xử lý',
};
const money = (value: number | string) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(Number(value));
const fieldClass = 'w-full rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500';
const buttonClass = 'rounded-xl border border-neutral-200 px-3 py-2.5 text-sm font-semibold hover:bg-neutral-50 disabled:opacity-50';

export function AmenityPaymentDialog({ booking, manager = false, onClose, onChanged }: { booking: Omit<AmenityBooking, 'resident_name'>; manager?: boolean; onClose: () => void; onChanged: (payment: AmenityBookingPayment | null) => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const [loadedPayment, setPayment] = useState(booking.payment ?? null);
  const payment = manager ? booking.payment ?? null : loadedPayment;
  const [loading, setLoading] = useState(!manager);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [message, setMessage] = useState('');
  const [qrFailed, setQrFailed] = useState(false);
  const [transaction, setTransaction] = useState('');
  const [receivedAmount, setReceivedAmount] = useState('');
  const [receivedAt, setReceivedAt] = useState('');
  const [verified, setVerified] = useState(false);
  const [reason, setReason] = useState('');
  const [now, setNow] = useState(Date.now());
  const [loadRevision, setLoadRevision] = useState(0);
  const mutationVersion = useRef(0);
  const changed = useRef(onChanged);
  changed.current = onChanged;
  const previousStatus = useRef(booking.payment?.status);

  useEffect(() => {
    dialog.current?.showModal();
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    if (manager) return;
    setLoading(true);
    const controller = new AbortController();
    let pending = false;
    let stopped = false;
    const load = async () => {
      if (pending || stopped || document.visibilityState === 'hidden') return;
      pending = true;
      const version = mutationVersion.current;
      try {
        const result = await api.getResidentBookingPayment(booking.id, controller.signal);
        if (controller.signal.aborted || version !== mutationVersion.current) return;
        setPayment(result.payment);
        setError(null);
        if (result.payment?.status !== previousStatus.current) {
          previousStatus.current = result.payment?.status;
          changed.current(result.payment);
        }
        stopped = !result.payment || !['WAITING_APPROVAL', 'PENDING', 'REPORTED'].includes(result.payment.status);
      } catch (failure) {
        if (!controller.signal.aborted) {
          setError(failure as ApiError);
          stopped = [401, 403].includes((failure as ApiError).status ?? 0);
        }
      } finally {
        pending = false;
        if (!controller.signal.aborted) setLoading(false);
      }
    };
    void load();
    const timer = window.setInterval(() => void load(), 10000);
    return () => { controller.abort(); window.clearInterval(timer); };
  }, [booking.id, manager, loadRevision]);

  const remaining = payment?.expires_at ? Math.max(0, Math.ceil((new Date(payment.expires_at).getTime() - now) / 1000)) : null;
  const fieldError = (name: string) => error?.errors?.[name] && <span role="alert" className="block text-xs text-rose-700">{error.errors[name][0]}</span>;
  const copy = async (value: string) => {
    try { await navigator.clipboard.writeText(value); setMessage('Đã sao chép.'); }
    catch { setMessage('Không thể sao chép tự động. Vui lòng chọn và sao chép thông tin bên dưới.'); }
  };
  const send = async (reject = false) => {
    if (busy) return;
    mutationVersion.current += 1;
    setBusy(true); setError(null); setMessage('');
    try {
      const result = manager
        ? await api.decideAmenityBookingPayment(booking, reject ? { reason: reason.trim() } : { bank_transaction_id: transaction.trim(), received_amount: Number(receivedAmount), received_at: new Date(receivedAt).toISOString() })
        : await api.reportResidentBookingPayment(booking);
      setPayment(result.payment);
      setMessage(result.payment.status === 'PAID' ? 'Đã xác nhận thanh toán thành công.' : result.payment.status === 'REPORTED' ? 'Đã báo chuyển khoản. Vui lòng chờ xác nhận tiền vào tài khoản.' : 'Đã ghi nhận cần xử lý, đăng ký không được khôi phục.');
      previousStatus.current = result.payment.status;
      changed.current(result.payment);
    } catch (failure) { setError(failure as ApiError); }
    finally { setBusy(false); }
  };

  const openCheckout = async () => {
    if (busy) return;
    mutationVersion.current += 1;
    setBusy(true); setError(null);
    try {
      const checkout = await api.createResidentPaymentCheckout(booking.id);
      if (!['https://pay-sandbox.sepay.vn/v1/checkout/init', 'https://pay.sepay.vn/v1/checkout/init'].includes(checkout.action)) throw new Error('Địa chỉ thanh toán không hợp lệ.');
      const form = document.createElement('form');
      form.method = 'POST'; form.action = checkout.action;
      for (const [name, value] of Object.entries(checkout.fields)) {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = name; input.value = value;
        form.appendChild(input);
      }
      document.body.appendChild(form);
      form.submit();
      form.remove();
    } catch (failure) { setError(failure as ApiError); }
    finally { setBusy(false); }
  };

  return <dialog ref={dialog} aria-labelledby="amenity-payment-title" onCancel={(event) => { event.preventDefault(); if (!busy) onClose(); }} className="amenity-booking-controls m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-neutral-200 bg-white text-neutral-900 shadow-xl backdrop:bg-neutral-950/50">
    <div className="sticky top-0 z-10 flex items-center justify-between border-b border-neutral-200 bg-white p-4"><h2 id="amenity-payment-title" className="font-bold">{manager ? 'Đối soát thanh toán tiện ích' : 'Thanh toán tiện ích'}</h2><button type="button" aria-label="Đóng thanh toán" disabled={busy} onClick={onClose} className="rounded-lg p-2 hover:bg-neutral-100"><X className="h-5 w-5" /></button></div>
    <div className="space-y-4 p-4 sm:p-6">
      <p className="text-sm font-semibold">{booking.booking_code}</p>
      <p className="text-sm text-neutral-600">Phí {money(booking.total_amount)} · Cọc {money(booking.deposit_amount)}</p>
      {loading && <p role="status">Đang tải thanh toán…</p>}
      {payment && <>
        <p role="status" className={`rounded-xl p-3 text-sm font-semibold ${payment.status === 'PAID' ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'}`}>{paymentStatusNames[payment.status]}</p>
        <p className="text-lg font-bold">{payment.can_pay ? 'Tổng cần chuyển' : 'Số tiền của đăng ký'}: {money(payment.amount)}</p>
        {!manager && payment.checkout_available && <div className="space-y-2"><button type="button" disabled={busy || loading || Boolean(error) || remaining === 0} onClick={() => void openCheckout()} className="w-full rounded-xl bg-neutral-950 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Đang mở thanh toán…' : payment.checkout_environment === 'sandbox' ? 'Thử thanh toán SePay (Sandbox)' : 'Thanh toán qua SePay'}</button>{payment.checkout_environment === 'sandbox' && <p className="text-sm text-amber-800">SePay Sandbox dùng giao dịch giả lập. QR chuyển khoản trực tiếp bên dưới vẫn dùng tiền thật.</p>}</div>}
        {!manager && !loading && !error && payment.qr_url && remaining !== 0 && <div className="rounded-2xl border border-neutral-200 p-3 text-center">{!qrFailed ? <img alt="Mã QR chuyển khoản Vietcombank cho đăng ký tiện ích" src={payment.qr_url} referrerPolicy="no-referrer" onError={() => setQrFailed(true)} className="mx-auto w-64 max-w-full" /> : <p className="text-sm text-amber-800">Không tải được QR. Bạn có thể chuyển khoản theo thông tin bên dưới.</p>}</div>}
        <dl className="space-y-2 rounded-xl bg-neutral-50 p-4 text-sm"><div><dt className="text-neutral-500">Ngân hàng · Chủ tài khoản</dt><dd className="mt-1 font-semibold">{payment.bank_name} · {payment.account_name}</dd></div><div><dt className="text-neutral-500">Số tài khoản</dt><dd className="flex flex-wrap items-center justify-between gap-2"><span className="select-all font-mono">{payment.account_number}</span>{payment.can_pay && <button type="button" className={buttonClass} onClick={() => void copy(payment.account_number)}><Copy aria-hidden="true" className="mr-1 inline h-3 w-3" />Sao chép số tài khoản</button>}</dd></div><div><dt className="text-neutral-500">Nội dung chuyển khoản</dt><dd className="flex flex-wrap items-center justify-between gap-2"><span className="select-all break-all font-mono">{payment.reference}</span>{payment.can_pay && <button type="button" className={buttonClass} onClick={() => void copy(payment.reference)}>Sao chép nội dung</button>}</dd></div></dl>
        {remaining !== null && ['PENDING', 'REPORTED'].includes(payment.status) && <p className="text-sm text-neutral-600">{payment.status === 'REPORTED' ? 'Thời gian giữ chỗ còn lại' : 'Thời gian chuyển khoản còn lại'}: <strong>{Math.floor(remaining / 60)}:{String(remaining % 60).padStart(2, '0')}</strong>{remaining === 0 && ' · Đang chờ cập nhật trạng thái từ máy chủ. Không chuyển tiền thêm.'}</p>}
        {payment.status === 'REPORTED' && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{payment.review_overdue ? 'Đối soát quá hạn. Vui lòng liên hệ ban quản lý.' : 'Đang chờ xác nhận giao dịch. Hệ thống sẽ cập nhật khi nhận được kết quả; ban quản lý có thể đối soát thủ công.'} Chỗ được giữ đến giờ sử dụng; chưa được đánh dấu đã thanh toán.</p>}
        {payment.review_reason && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{payment.review_reason}</p>}
        {!manager && ['EXPIRED', 'CANCELLED', 'REVIEW'].includes(payment.status) && <p className="text-sm font-semibold text-rose-700">Không chuyển tiền thêm cho yêu cầu này. Nếu đã chuyển, liên hệ ban quản lý để đối soát.</p>}
        {payment.refund_required && <p className="text-sm text-amber-800">Cần liên hệ ban quản lý để xử lý hoàn tiền. Chỗ đã hủy không được khôi phục.</p>}
        {payment.bank_transaction_id && <p className="break-all text-xs text-neutral-600">Giao dịch: {payment.bank_transaction_id} · Thực nhận {money(payment.received_amount ?? 0)}</p>}
        {!manager && payment.can_pay && <><p className="text-xs leading-relaxed text-neutral-500">Kiểm tra người nhận trong ứng dụng ngân hàng, chuyển đúng số tiền và nội dung. Nút bên dưới chỉ báo chuyển khoản, chưa xác nhận đã nhận tiền.</p><button type="button" disabled={busy || loading || Boolean(error) || remaining === 0} onClick={() => void send()} className="w-full rounded-xl bg-neutral-950 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Đang gửi…' : 'Đã chuyển khoản'}</button></>}
        {manager && payment.can_confirm && <form className="space-y-3 border-t border-neutral-200 pt-4" onSubmit={(event) => { event.preventDefault(); void send(); }}>
          <label className="block space-y-1 text-sm">Mã giao dịch ngân hàng<input required maxLength={100} disabled={busy} value={transaction} onChange={(event) => setTransaction(event.target.value)} className={fieldClass} />{fieldError('bank_transaction_id')}</label>
          <label className="block space-y-1 text-sm">Số tiền thực nhận<input type="number" required min={1} step={1} disabled={busy} value={receivedAmount} onChange={(event) => setReceivedAmount(event.target.value)} className={fieldClass} />{fieldError('received_amount')}</label>
          <label className="block space-y-1 text-sm">Thời điểm nhận tiền<input type="datetime-local" required disabled={busy} value={receivedAt} onChange={(event) => setReceivedAt(event.target.value)} className={fieldClass} />{fieldError('received_at')}</label>
          <label className="flex items-start gap-2 text-xs"><input type="checkbox" required checked={verified} disabled={busy} onChange={(event) => setVerified(event.target.checked)} />Tôi đã đối chiếu giao dịch thực tế trên tài khoản ngân hàng.</label>
          <button type="submit" disabled={busy || !verified} className="w-full rounded-xl bg-neutral-950 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">Xác nhận nhận tiền</button>
          <label className="block space-y-1 text-sm">Lý do cần xử lý<textarea maxLength={500} disabled={busy} value={reason} onChange={(event) => setReason(event.target.value)} className={fieldClass} />{fieldError('reason')}</label>
          <p className="text-xs text-neutral-500">Ghi nhận cần xử lý sẽ hủy đăng ký và giải phóng chỗ. Tiền đã chuyển cần được đối soát riêng.</p>
          <button type="button" disabled={busy || !reason.trim()} onClick={() => void send(true)} className={`${buttonClass} w-full text-rose-700`}>Ghi nhận cần xử lý</button>
        </form>}
      </>}
      {!loading && !payment && !error && <p className="text-sm text-neutral-600">Đăng ký miễn phí hoặc được thu tiền theo quy trình cũ.</p>}
      {error && <div role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{error.status === 401 ? 'Phiên đăng nhập đã hết hạn.' : error.message || 'Không thể xử lý. Vui lòng kiểm tra lại kết quả trước khi chuyển tiền thêm.'}{error.status === 401 ? <a href="/dang-nhap" className="ml-2 underline">Đăng nhập lại</a> : !manager && <button type="button" disabled={busy} onClick={() => setLoadRevision((value) => value + 1)} className="ml-2 underline">Tải lại trạng thái</button>}</div>}
      {message && <p role="status" className="text-sm text-emerald-800">{message}</p>}
      <button type="button" disabled={busy} className={`${buttonClass} w-full`} onClick={onClose}>Đóng</button>
    </div>
  </dialog>;
}
