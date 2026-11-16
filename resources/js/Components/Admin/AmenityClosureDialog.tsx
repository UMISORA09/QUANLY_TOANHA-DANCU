import React, { useEffect, useRef, useState } from 'react';
import { Amenity, AmenityClosureImpact, api } from '../../Services/api';

export function ClosureImpactSummary({ impact }: { impact: AmenityClosureImpact }) {
  return <div className="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><p><strong>{impact.count} đăng ký bị ảnh hưởng</strong> · Đã nhận {new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(impact.received_amount)} · {impact.reported_count} đơn báo chuyển khoản.</p><p>Đơn bị hủy sẽ được thông báo cho cư dân. Tiền đã nhận cần hoàn lại thủ công; đơn báo chuyển khoản cần đối soát.</p><ul className="max-h-36 overflow-y-auto">{impact.items.map((item) => <li key={item.booking_code}>{item.booking_code} · {item.booking_date} · {item.start_time.slice(0, 5)}–{item.end_time.slice(0, 5)}</li>)}</ul>{impact.count > impact.items.length && <p>Hiển thị {impact.items.length}/{impact.count} đơn. Xác nhận áp dụng cho toàn bộ {impact.count} đơn.</p>}</div>;
}

export function AmenityClosureDialog({ amenity, onClose, onChanged }: { amenity: Amenity; onClose: () => void; onChanged: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const [action, setAction] = useState<'keep' | 'cancel'>('keep');
  const [reason, setReason] = useState('');
  const [impact, setImpact] = useState<AmenityClosureImpact | null>(null);
  const [current, setCurrent] = useState(amenity);
  const [acknowledged, setAcknowledged] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => { dialog.current?.showModal(); }, []);
  const preview = async () => {
    setBusy(true); setError(''); setAcknowledged(false); setImpact(null);
    try {
      const [item, result] = await Promise.all([api.getAmenity(amenity.id, true), api.getAmenityClosureImpact(amenity.id)]);
      setCurrent(item); setImpact(result);
    } catch (failure: any) { setError(failure.message || 'Không thể xem các đơn bị ảnh hưởng.'); }
    finally { setBusy(false); }
  };
  const submit = async () => {
    setBusy(true); setError('');
    try {
      await api.patchAmenityStatus(amenity.id, false, current.updated_at, { booking_action: action, ...(action === 'cancel' ? { reason: reason.trim(), confirmation_token: impact?.confirmation_token } : {}) });
      onChanged(); onClose();
    } catch (failure: any) { setError(failure.message || 'Không thể tạm ngưng tiện ích.'); if (failure.status === 409) { setImpact(null); setAcknowledged(false); } }
    finally { setBusy(false); }
  };
  return <dialog ref={dialog} aria-labelledby="amenity-closure-title" onCancel={(event) => { event.preventDefault(); if (!busy) onClose(); }} className="amenity-booking-controls m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-2xl border border-neutral-200 bg-white p-6 text-neutral-900 shadow-xl backdrop:bg-neutral-950/50">
    <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); if (!busy) void submit(); }}>
      <h2 id="amenity-closure-title" className="font-bold">Tạm ngưng tiện ích · {amenity.amenity_name}</h2>
      <fieldset disabled={busy} className="space-y-3"><legend className="mb-2 text-sm font-semibold">Cách xử lý đăng ký</legend><label className="flex items-start gap-2 text-sm"><input type="radio" name="closure-action" checked={action === 'keep'} onChange={() => { setAction('keep'); setAcknowledged(false); }} />Ngừng nhận đăng ký mới, tiếp tục phục vụ các đơn đã đặt</label><label className="flex items-start gap-2 text-sm"><input type="radio" name="closure-action" checked={action === 'cancel'} onChange={() => { setAction('cancel'); setAcknowledged(false); }} />Đóng cửa, hủy các đơn chưa kết thúc</label></fieldset>
      {action === 'keep' ? <p className="rounded-xl bg-neutral-50 p-3 text-sm">Các đơn cũ tiếp tục có hiệu lực và có thể thanh toán. Tiện ích ngừng nhận đơn mới đến khi được kích hoạt lại.</p> : <><div className="space-y-2 text-sm"><label htmlFor="amenity-closure-reason" className="block">Lý do đóng cửa</label><textarea id="amenity-closure-reason" required maxLength={255} disabled={busy} value={reason} onChange={(event) => setReason(event.target.value)} className="w-full rounded-xl border border-neutral-200 p-3" /></div><button type="button" disabled={busy} onClick={() => void preview()} className="rounded-xl border px-3 py-2 text-sm">Xem lại các đơn bị ảnh hưởng</button>{impact && <><ClosureImpactSummary impact={impact} /><label className="flex items-start gap-2 text-sm"><input type="checkbox" disabled={busy} checked={acknowledged} onChange={(event) => setAcknowledged(event.target.checked)} />Tôi xác nhận hủy toàn bộ {impact.count} đơn bị ảnh hưởng và xử lý khoản tiền liên quan.</label></>}</>}
      {error && <div><p role="alert" className="text-sm text-rose-700">{error}</p><button type="button" disabled={busy} onClick={() => void preview()} className="mt-2 rounded-xl border px-3 py-2 text-sm">Cập nhật dữ liệu và xem lại</button></div>}{busy && <p role="status" className="text-sm">Đang xử lý…</p>}
      <div className="flex justify-end gap-2"><button type="button" disabled={busy} onClick={onClose} className="rounded-xl border px-3 py-2 text-sm">Đóng</button><button type="submit" disabled={busy || (action === 'cancel' && (!impact || !acknowledged || !reason.trim()))} className="rounded-xl bg-neutral-950 px-3 py-2 text-sm font-semibold text-white disabled:opacity-40">{action === 'keep' ? 'Xác nhận ngừng nhận đơn mới' : 'Xác nhận đóng cửa và hủy đơn'}</button></div>
    </form>
  </dialog>;
}
