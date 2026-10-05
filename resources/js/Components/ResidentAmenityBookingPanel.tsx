import React, { useCallback, useEffect, useRef, useState } from 'react';
import { CalendarDays, CheckCircle2, Clock, MapPin, Search, Sparkles, Users, X } from 'lucide-react';
import { Amenity, ApiError, ResidentAmenityBooking, ResidentAmenityCatalog, ResidentAvailableSlot, ResidentBookingList, api } from '../Services/api';
import { amenityCache } from '../Services/amenityCache';

const money = (value: number | string) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(Number(value));
const dateLabel = (date: string) => new Intl.DateTimeFormat('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Asia/Ho_Chi_Minh' }).format(new Date(`${date}T12:00:00+07:00`));
const addDays = (date: string, days: number) => {
  const value = new Date(`${date}T12:00:00Z`);
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
};
const statusNames: Record<string, string> = { PENDING: 'Chờ duyệt', APPROVED: 'Đã duyệt', CONFIRMED: 'Đã xác nhận', CHECKED_IN: 'Đang sử dụng', COMPLETED: 'Đã hoàn tất', CANCELLED: 'Đã hủy', REJECTED: 'Bị từ chối' };
const inputClass = 'w-full rounded-xl border border-neutral-200 bg-white px-3 py-2.5 text-sm text-neutral-900 focus:outline-none focus:ring-2 focus:ring-emerald-500';
const primaryClass = 'inline-flex items-center justify-center gap-2 rounded-xl bg-neutral-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-neutral-800 disabled:opacity-50 disabled:cursor-not-allowed';

function ErrorNotice({ error, retry }: { error: ApiError | null; retry?: () => void }) {
  if (!error) return null;
  return <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
    <p>{error.status === 401 ? 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại để tiếp tục.' : error.message}</p>
    {error.status === 401 ? <a href="/dang-nhap" className="mt-2 inline-block font-semibold underline">Đăng nhập lại</a> : retry && <button type="button" onClick={retry} className="mt-2 font-semibold underline">Thử lại</button>}
  </div>;
}

function BookingStatus({ booking }: { booking: ResidentAmenityBooking }) {
  return <div className="flex flex-wrap gap-2 text-xs font-medium">
    <span className={`rounded-full border px-2.5 py-1 ${booking.status === 'PENDING' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-neutral-200 bg-neutral-50 text-neutral-700'}`}>{statusNames[booking.status] || booking.status}</span>
    <span className={`rounded-full border px-2.5 py-1 ${booking.is_paid ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-800'}`}>{booking.is_paid ? 'Không còn khoản cần thu' : 'Chưa thanh toán · Thu sau'}</span>
  </div>;
}

export function ResidentAmenityBookingPanel() {
  const [tab, setTab] = useState<'register' | 'mine'>('register');
  const [catalog, setCatalog] = useState<ResidentAmenityCatalog | null>(null);
  const [catalogLoading, setCatalogLoading] = useState(true);
  const [catalogError, setCatalogError] = useState<ApiError | null>(null);
  const [catalogRevision, setCatalogRevision] = useState(0);
  const [revision, setRevision] = useState(0);
  const [apartmentId, setApartmentId] = useState('');
  const [amenityId, setAmenityId] = useState('');
  const [search, setSearch] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [date, setDate] = useState('');
  const [slots, setSlots] = useState<ResidentAvailableSlot[]>([]);
  const [slotId, setSlotId] = useState('');
  const [availabilityLoading, setAvailabilityLoading] = useState(false);
  const [availabilityError, setAvailabilityError] = useState<ApiError | null>(null);
  const [attendees, setAttendees] = useState('1');
  const [notes, setNotes] = useState('');
  const [acceptedRules, setAcceptedRules] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const submittingRef = useRef(false);
  const [formError, setFormError] = useState<ApiError | null>(null);
  const [success, setSuccess] = useState<ResidentAmenityBooking | null>(null);
  const [bookings, setBookings] = useState<ResidentBookingList | null>(null);
  const [bookingsLoading, setBookingsLoading] = useState(false);
  const [bookingsError, setBookingsError] = useState<ApiError | null>(null);
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [detail, setDetail] = useState<ResidentAmenityBooking | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [modalError, setModalError] = useState<ApiError | null>(null);
  const [modalSuccess, setModalSuccess] = useState<string | null>(null);
  const [cancelMode, setCancelMode] = useState(false);
  const [reason, setReason] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const dialog = useRef<HTMLDialogElement>(null);
  const detailRequest = useRef<AbortController | null>(null);
  const selectionVersion = useRef(0);
  const amenity = catalog?.amenities.find((item) => item.id === amenityId);
  const apartment = catalog?.apartments.find((item) => item.id === apartmentId);
  const slot = slots.find((item) => item.slot_id === slotId);

  const refresh = useCallback(() => setRevision((value) => value + 1), []);
  useEffect(() => {
    const controller = new AbortController();
    setCatalogLoading(true);
    setCatalogError(null);
    api.getResidentAmenities(controller.signal).then((data) => {
      if (controller.signal.aborted || !data) return;
      setCatalog(data);
      setApartmentId((current) => data.apartments.some((item) => item.id === current) ? current : data.apartments.length === 1 ? data.apartments[0].id : '');
      setAmenityId((current) => data.amenities.some((item) => item.id === current) ? current : '');
      setDate((current) => current && current >= data.today ? current : data.today);
    }).catch((error: ApiError) => { if (!controller.signal.aborted) setCatalogError(error); })
      .finally(() => { if (!controller.signal.aborted) setCatalogLoading(false); });
    return () => controller.abort();
  }, [catalogRevision]);

  useEffect(() => {
    selectionVersion.current += 1;
    setSlotId('');
    setSlots([]);
    setAcceptedRules(false);
    setFormError(null);
    setSuccess(null);
  }, [amenityId, apartmentId, date]);

  useEffect(() => {
    if (!amenityId || !apartmentId || !date || tab !== 'register') return;
    const controller = new AbortController();
    setAvailabilityLoading(true);
    setAvailabilityError(null);
    api.getResidentAvailability(amenityId, date, apartmentId, controller.signal).then((data) => {
      if (controller.signal.aborted || !data) return;
      setSlots(data.slots);
      setSlotId((current) => data.slots.some((item) => item.slot_id === current && item.available) ? current : '');
    }).catch((error: ApiError) => { if (!controller.signal.aborted) { setSlots([]); setSlotId(''); setAvailabilityError(error); } })
      .finally(() => { if (!controller.signal.aborted) setAvailabilityLoading(false); });
    return () => controller.abort();
  }, [amenityId, apartmentId, date, tab, revision]);

  useEffect(() => {
    if (tab !== 'mine') return;
    const controller = new AbortController();
    setBookingsLoading(true);
    setBookingsError(null);
    api.getResidentBookings(status, page, controller.signal).then((data) => {
      if (!controller.signal.aborted && data) {
        if (page > data.total_pages) setPage(data.total_pages);
        else setBookings(data);
      }
    }).catch((error: ApiError) => { if (!controller.signal.aborted) setBookingsError(error); })
      .finally(() => { if (!controller.signal.aborted) setBookingsLoading(false); });
    return () => controller.abort();
  }, [tab, status, page, revision]);

  useEffect(() => {
    const unsubscribe = amenityCache.subscribe((event) => {
      refresh();
      if (event.type !== 'AMENITY_BOOKING_CHANGED') setCatalogRevision((value) => value + 1);
    });
    const focused = () => { if (!document.hidden) { refresh(); setCatalogRevision((value) => value + 1); } };
    window.addEventListener('focus', focused);
    document.addEventListener('visibilitychange', focused);
    return () => { unsubscribe(); window.removeEventListener('focus', focused); document.removeEventListener('visibilitychange', focused); detailRequest.current?.abort(); };
  }, [refresh]);

  useEffect(() => {
    if (detail && !dialog.current?.open) dialog.current?.showModal();
    if (!detail && dialog.current?.open) dialog.current?.close();
  }, [detail]);

  const pickAmenity = (item: Amenity) => { setAmenityId(item.id); setDate(catalog!.today); };
  const fieldError = (name: string) => formError?.errors?.[name] && <p className="mt-1 text-xs text-rose-700" role="alert">{formError.errors[name][0]}</p>;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!amenity || !slot || submittingRef.current) return;
    const version = selectionVersion.current;
    submittingRef.current = true;
    setSubmitting(true);
    setFormError(null);
    try {
      const latest = await api.getResidentAvailability(amenity.id, date, apartmentId);
      if (version !== selectionVersion.current) return;
      const current = latest.slots.find((item) => item.slot_id === slot.slot_id);
      setSlots(latest.slots);
      if (!current?.available || current.remaining_attendees < Number(attendees)) {
        setSlotId('');
        throw Object.assign(new Error(current?.reason || 'Khung giờ không còn đủ chỗ. Vui lòng chọn lại.'), { status: 409 });
      }
      if (current.total_amount !== slot.total_amount || current.deposit_amount !== slot.deposit_amount) {
        throw Object.assign(new Error('Biểu phí vừa thay đổi. Vui lòng kiểm tra số tiền mới và xác nhận lại.'), { status: 409 });
      }
      const result = await api.createResidentBooking({ amenity_id: amenity.id, slot_id: slot.slot_id, apartment_id: apartmentId, booking_date: date, attendee_count: Number(attendees), resident_notes: notes, accepted_rules: acceptedRules });
      setSuccess(result.booking);
      setSlotId('');
      setAcceptedRules(false);
      refresh();
    } catch (error) {
      if (version === selectionVersion.current) { setFormError(error as ApiError); if ((error as ApiError).status === 409) refresh(); }
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  };

  const openDetail = async (booking: ResidentAmenityBooking, cancel: boolean) => {
    setModalSuccess(null);
    detailRequest.current?.abort();
    const controller = new AbortController();
    detailRequest.current = controller;
    setDetail(booking);
    setDetailLoading(true);
    setCancelMode(cancel);
    setReason('');
    setModalError(null);
    try {
      const current = await api.getResidentBooking(booking.id, controller.signal);
      if (!controller.signal.aborted && current) setDetail(current);
    } catch (error) { if (!controller.signal.aborted) setModalError(error as ApiError); }
    finally { if (!controller.signal.aborted) setDetailLoading(false); }
  };

  const closeDetail = () => { if (!cancelling) { detailRequest.current?.abort(); setDetail(null); } };
  const cancelBooking = async () => {
    if (!detail || cancelling) return;
    setCancelling(true);
    setModalError(null);
    setModalSuccess(null);
    try {
      const result = await api.cancelResidentBooking(detail, reason);
      setModalSuccess(`Đã hủy đăng ký ${result.booking.booking_code} thành công.`);
      setDetail(result.booking);
      setCancelMode(false);
      refresh();
    } catch (error) { setModalError(error as ApiError); refresh(); }
    finally { setCancelling(false); }
  };

  return <section className="mx-auto max-w-6xl space-y-6">
    <header>
      <p className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-emerald-700"><Sparkles className="h-4 w-4" /> Không gian cư dân</p>
      <h1 className="text-2xl font-extrabold tracking-tight text-neutral-950 sm:text-3xl">Đăng ký sử dụng tiện ích</h1>
      <p className="mt-2 text-sm text-neutral-500">Chọn tiện ích yêu thích và khung giờ phù hợp cho bạn cùng gia đình.</p>
    </header>
    <div className="flex gap-2 border-b border-neutral-200 pb-3" aria-label="Nội dung tiện ích">
      {(['register', 'mine'] as const).map((value) => <button key={value} type="button" aria-pressed={tab === value} onClick={() => { setTab(value); refresh(); }} className={`rounded-xl px-4 py-2.5 text-sm font-semibold ${tab === value ? 'bg-neutral-950 text-white' : 'text-neutral-600 hover:bg-neutral-100'}`}>{value === 'register' ? 'Đăng ký tiện ích' : 'Lịch của tôi'}</button>)}
    </div>
    {tab === 'register' ? <>
      <ErrorNotice error={catalogError} retry={() => setCatalogRevision((value) => value + 1)} />
      {catalogLoading && !catalog && <p role="status" className="p-8 text-center text-sm text-neutral-500">Đang tải tiện ích…</p>}
      {catalog && !catalogError && <>
        {!catalog.apartments.length ? <div className="rounded-2xl border border-neutral-200 bg-white p-8 text-center text-sm text-neutral-600">Bạn chưa có hồ sơ cư trú còn hiệu lực. Vui lòng liên hệ ban quản lý để được hỗ trợ.</div> : <>
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="space-y-1 text-xs font-semibold text-neutral-700">Căn hộ đăng ký<select aria-label="Căn hộ đăng ký" className={inputClass} disabled={submitting} value={apartmentId} onChange={(event) => { setApartmentId(event.target.value); setAmenityId(''); }}><option value="">Chọn căn hộ</option>{catalog.apartments.map((item) => <option key={item.id} value={item.id}>{item.apartment_number} · {item.block_name}</option>)}</select></label>
            <label className="space-y-1 text-xs font-semibold text-neutral-700">Tìm tiện ích<div className="relative"><Search className="absolute left-3 top-3 h-4 w-4 text-neutral-400" /><input className={`${inputClass} pl-9`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tên tiện ích…" /></div></label>
            <label className="space-y-1 text-xs font-semibold text-neutral-700">Danh mục<select className={inputClass} value={categoryId} onChange={(event) => setCategoryId(event.target.value)}><option value="">Tất cả danh mục</option>{catalog.categories.map((item) => <option key={item.id} value={item.id}>{item.category_name}</option>)}</select></label>
          </div>
          {!apartmentId ? <p className="rounded-xl bg-neutral-100 p-4 text-sm text-neutral-600">Chọn căn hộ trước khi đăng ký tiện ích.</p> : <>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {catalog.amenities.filter((item) => (!item.block_id || item.block_id === apartment?.block_id) && (!categoryId || item.category_id === categoryId) && item.amenity_name.toLocaleLowerCase('vi').includes(search.toLocaleLowerCase('vi'))).map((item) => <button key={item.id} type="button" disabled={submitting} aria-pressed={amenityId === item.id} onClick={() => pickAmenity(item)} className={`overflow-hidden rounded-2xl border bg-white text-left shadow-xs transition hover:shadow-md disabled:opacity-60 ${amenityId === item.id ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'border-neutral-200'}`}>
                <div className="relative flex h-28 items-center justify-center overflow-hidden bg-gradient-to-br from-emerald-50 to-sky-50"><Sparkles className="h-9 w-9 text-emerald-500/70" />{item.cover_image_url && <img alt="" loading="lazy" src={item.cover_image_url} onError={(event) => { event.currentTarget.style.display = 'none'; }} className="absolute inset-0 h-full w-full object-cover" />}</div>
                <div className="space-y-2 p-4"><div className="flex items-start justify-between gap-2"><h2 className="font-bold text-neutral-900">{item.amenity_name}</h2>{Boolean(item.requires_admin_approval) && <span className="shrink-0 rounded-full bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-800">Cần duyệt</span>}</div><p className="flex items-center gap-1.5 text-xs text-neutral-500"><MapPin className="h-3.5 w-3.5 shrink-0" />{item.location_detail}</p><div className="flex flex-wrap justify-between gap-2 text-xs"><span className="flex items-center gap-1 text-neutral-500"><Users className="h-3.5 w-3.5" />{item.max_capacity_per_slot} người/khung giờ</span><strong className="text-emerald-700">{Number(item.hourly_rate) ? `${money(item.hourly_rate)}/giờ` : 'Miễn phí'}</strong></div></div>
              </button>)}
            </div>
            {!catalog.amenities.some((item) => (!item.block_id || item.block_id === apartment?.block_id) && (!categoryId || item.category_id === categoryId) && item.amenity_name.toLocaleLowerCase('vi').includes(search.toLocaleLowerCase('vi'))) && <p className="p-8 text-center text-sm text-neutral-500">Không có tiện ích phù hợp.</p>}
          </>}
          {success && <div role="status" className="space-y-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-5"><h2 className="flex items-center gap-2 font-bold text-emerald-900"><CheckCircle2 className="h-5 w-5" />Đăng ký thành công · {success.booking_code}</h2><BookingStatus booking={success} /><button type="button" className={primaryClass} onClick={() => { setTab('mine'); setPage(1); setStatus(''); }}>Xem lịch của tôi</button></div>}
          {amenity && <form onSubmit={submit} className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div className="space-y-5 rounded-2xl border border-neutral-200 bg-white/90 p-5 sm:p-6">
              <h2 className="text-lg font-bold text-neutral-950">{amenity.amenity_name}</h2>
              <div className="rounded-xl bg-neutral-50 p-4 text-xs leading-relaxed text-neutral-600"><p>Đặt trước tối đa <strong>{amenity.advance_booking_days_limit} ngày</strong> · Hủy trước ít nhất <strong>{amenity.min_cancel_hours_before} giờ</strong></p><p className="mt-2 whitespace-pre-wrap">{amenity.rules_and_regulations || 'Vui lòng sử dụng đúng giờ và giữ gìn vệ sinh chung.'}</p></div>
              <label className="block space-y-2 text-sm font-semibold text-neutral-800">Ngày sử dụng<input className={inputClass} type="date" value={date} min={catalog.today} max={addDays(catalog.today, amenity.advance_booking_days_limit)} required disabled={submitting} onChange={(event) => setDate(event.target.value)} />{fieldError('booking_date')}</label>
              <div className="flex flex-wrap gap-2">{Array.from({ length: Math.min(7, amenity.advance_booking_days_limit + 1) }, (_, index) => addDays(catalog.today, index)).map((value, index) => <button key={value} type="button" disabled={submitting} aria-pressed={date === value} onClick={() => setDate(value)} className={`rounded-lg border px-3 py-2 text-xs ${date === value ? 'border-emerald-500 bg-emerald-50 font-semibold text-emerald-800' : 'border-neutral-200 text-neutral-600'}`}>{index === 0 ? 'Hôm nay' : index === 1 ? 'Ngày mai' : dateLabel(value).slice(0, 5)}</button>)}</div>
              <div className="space-y-3"><h3 className="flex items-center gap-2 text-sm font-bold text-neutral-900"><Clock className="h-4 w-4 text-emerald-600" />Chọn khung giờ</h3><ErrorNotice error={availabilityError} retry={refresh} />{availabilityLoading ? <p role="status" className="text-sm text-neutral-500">Đang kiểm tra chỗ trống…</p> : !availabilityError && !slots.length ? <p className="rounded-xl bg-neutral-50 p-4 text-sm text-neutral-500">Ngày này chưa có khung giờ được cấu hình.</p> : <div className="grid gap-2 sm:grid-cols-2">{slots.map((item) => <button key={item.slot_id} type="button" disabled={!item.available || submitting} aria-pressed={slotId === item.slot_id} onClick={() => { setSlotId(item.slot_id); setFormError(null); }} className={`rounded-xl border p-3 text-left disabled:cursor-not-allowed disabled:bg-neutral-50 disabled:text-neutral-400 ${slotId === item.slot_id ? 'border-emerald-500 bg-emerald-50' : 'border-neutral-200'}`}><span className="block text-sm font-bold">{item.start_time} – {item.end_time}</span>{item.slot_label && <span className="mt-1 block text-xs">{item.slot_label}</span>}<span className="mt-1 block text-xs">{item.available ? `Còn ${item.remaining_bookings} lượt · ${item.remaining_attendees} chỗ` : item.reason}</span></button>)}</div>}{fieldError('slot_id')}</div>
              <div className="grid gap-4 sm:grid-cols-[120px_1fr]"><label className="space-y-2 text-sm font-semibold text-neutral-800">Số người<input className={inputClass} type="number" min={1} max={slot?.remaining_attendees ?? amenity.max_capacity_per_slot} step={1} required disabled={submitting} value={attendees} onChange={(event) => setAttendees(event.target.value)} aria-invalid={Boolean(formError?.errors?.attendee_count)} />{fieldError('attendee_count')}</label><label className="space-y-2 text-sm font-semibold text-neutral-800">Ghi chú<textarea aria-label="Ghi chú" className={inputClass} rows={2} maxLength={500} disabled={submitting} value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Yêu cầu hỗ trợ, nếu có…" />{fieldError('resident_notes')}</label></div>
            </div>
            <aside className="space-y-4 rounded-2xl border border-neutral-200 bg-white/95 p-5 shadow-xs lg:sticky lg:top-6"><h3 className="flex items-center gap-2 font-bold text-neutral-950"><CalendarDays className="h-4 w-4 text-emerald-600" />Xác nhận đăng ký</h3><dl className="space-y-3 text-sm text-neutral-600"><div><dt className="text-xs text-neutral-400">Tiện ích</dt><dd className="mt-1 font-semibold text-neutral-900">{amenity.amenity_name}</dd></div><div><dt className="text-xs text-neutral-400">Căn hộ</dt><dd>{apartment?.apartment_number}</dd></div><div><dt className="text-xs text-neutral-400">Ngày và giờ</dt><dd>{date ? dateLabel(date) : 'Chưa chọn ngày'}<br />{slot ? `${slot.start_time} – ${slot.end_time}` : 'Chưa chọn khung giờ'}</dd></div><div className="flex justify-between"><dt>Số người</dt><dd>{attendees || '—'}</dd></div><div className="flex justify-between border-t border-neutral-100 pt-3"><dt>Phí sử dụng</dt><dd>{slot ? money(slot.total_amount) : '—'}</dd></div><div className="flex justify-between"><dt>Tiền cọc</dt><dd>{slot ? money(slot.deposit_amount) : money(amenity.security_deposit_required)}</dd></div><div className="flex justify-between border-t border-neutral-100 pt-3 font-bold text-neutral-950"><dt>Tổng cần thu</dt><dd>{slot ? money(slot.total_amount + slot.deposit_amount) : '—'}</dd></div></dl><p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">Phí và tiền cọc được thu sau theo hướng dẫn của ban quản lý.{Boolean(amenity.requires_admin_approval) && ' Đăng ký cần được ban quản lý duyệt.'}</p><label className="flex items-start gap-2 text-xs leading-relaxed text-neutral-600"><input type="checkbox" className="mt-0.5 h-4 w-4 accent-emerald-600" required disabled={submitting} checked={acceptedRules} onChange={(event) => setAcceptedRules(event.target.checked)} />Tôi đã đọc và đồng ý với nội quy tiện ích.</label>{fieldError('accepted_rules')}<ErrorNotice error={formError} /><button className={`${primaryClass} w-full`} type="submit" disabled={!slot?.available || !acceptedRules || availabilityLoading || submitting || Boolean(catalogError)}>{submitting ? 'Đang đăng ký…' : 'Xác nhận đăng ký'}</button></aside>
          </form>}
        </>}
      </>}
    </> : <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-bold text-neutral-950">Lịch đăng ký của tôi</h2><label className="text-xs font-semibold text-neutral-600">Trạng thái<select aria-label="Lọc trạng thái" className={`${inputClass} mt-1`} value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>{[['', 'Tất cả trạng thái'], ...Object.entries(statusNames)].map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
      <ErrorNotice error={bookingsError} retry={refresh} />
      {bookingsLoading ? <p role="status" className="p-8 text-center text-sm text-neutral-500">Đang tải lịch đăng ký…</p> : !bookingsError && <>
        {!bookings?.items.length && <div className="rounded-2xl border border-neutral-200 bg-white p-8 text-center"><CalendarDays className="mx-auto mb-3 h-8 w-8 text-neutral-400" /><p className="text-sm text-neutral-600">Chưa có lượt đăng ký phù hợp.</p><button type="button" onClick={() => setTab('register')} className={`${primaryClass} mt-4`}>Đăng ký tiện ích</button></div>}
        {bookings?.items.map((item) => <article key={item.id} className="space-y-3 rounded-2xl border border-neutral-200 bg-white/90 p-5"><div className="flex flex-wrap justify-between gap-3"><div><p className="text-xs font-mono text-neutral-400">{item.booking_code}</p><h3 className="mt-1 font-bold text-neutral-900">{item.amenity_name}</h3><p className="mt-1 text-sm text-neutral-500">{dateLabel(item.booking_date)} · {item.start_time} – {item.end_time}</p></div><BookingStatus booking={item} /></div><p className="text-xs text-neutral-500">Căn hộ {item.apartment_number} · {item.attendee_count} người · Phí {money(item.total_amount)} · Cọc {money(item.deposit_amount)}</p><div className="flex gap-3"><button type="button" className="rounded-lg border border-neutral-200 px-3 py-2 text-xs font-semibold text-neutral-700 hover:bg-neutral-50" onClick={() => openDetail(item, false)}>Xem chi tiết</button>{item.can_cancel && <button type="button" className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50" onClick={() => openDetail(item, true)}>Hủy đăng ký</button>}</div></article>)}
        {bookings && bookings.total > 0 && <div className="flex items-center justify-between text-sm text-neutral-500"><span>Trang {page}/{bookings.total_pages} · {bookings.total} lượt</span><div className="flex gap-2"><button type="button" disabled={page <= 1} className="rounded-lg border px-3 py-2 disabled:opacity-40" onClick={() => setPage((value) => value - 1)}>Trước</button><button type="button" disabled={page >= bookings.total_pages} className="rounded-lg border px-3 py-2 disabled:opacity-40" onClick={() => setPage((value) => value + 1)}>Sau</button></div></div>}
      </>}
    </div>}
    <dialog ref={dialog} aria-labelledby="resident-booking-dialog-title" onCancel={(event) => { event.preventDefault(); closeDetail(); }} onClose={() => setDetail(null)} className="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-neutral-200 bg-white p-6 text-neutral-900 shadow-2xl backdrop:bg-neutral-950/50 backdrop:backdrop-blur-sm">
      {modalSuccess && <p role="status" className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{modalSuccess}</p>}
      {detail && <div className="space-y-4"><div className="flex justify-between gap-3"><h2 id="resident-booking-dialog-title" className="text-lg font-bold">{cancelMode ? 'Hủy đăng ký tiện ích' : 'Chi tiết đăng ký'}</h2><button type="button" aria-label="Đóng chi tiết" disabled={cancelling} onClick={closeDetail}><X className="h-5 w-5" /></button></div><p className="text-sm font-semibold">{detail.amenity_name} · {detail.booking_code}</p><BookingStatus booking={detail} /><dl className="space-y-2 text-sm"><div><dt className="text-neutral-400">Ngày giờ</dt><dd>{dateLabel(detail.booking_date)} · {detail.start_time} – {detail.end_time}</dd></div><div><dt className="text-neutral-400">Căn hộ · Số người</dt><dd>{detail.apartment_number} · {detail.attendee_count} người</dd></div><div><dt className="text-neutral-400">Phí sử dụng · Tiền cọc</dt><dd>{money(detail.total_amount)} · {money(detail.deposit_amount)}</dd></div>{detail.resident_notes && <div><dt className="text-neutral-400">Ghi chú</dt><dd className="whitespace-pre-wrap">{detail.resident_notes}</dd></div>}{detail.rejection_reason && <div><dt className="text-neutral-400">Lý do từ chối</dt><dd>{detail.rejection_reason}</dd></div>}<div><dt className="text-neutral-400">Hạn hủy</dt><dd>{new Date(detail.cancel_deadline).toLocaleString('vi-VN', { timeZone: catalog?.timezone || 'Asia/Ho_Chi_Minh' })}</dd></div></dl>{detailLoading && <p role="status" className="text-sm text-neutral-500">Đang cập nhật thông tin…</p>}<ErrorNotice error={modalError} retry={() => openDetail(detail, cancelMode)} />{cancelMode && detail.can_cancel && <label className="block space-y-2 text-sm">Lý do hủy (không bắt buộc)<textarea className={inputClass} rows={3} maxLength={500} disabled={cancelling} value={reason} onChange={(event) => setReason(event.target.value)} /></label>}{cancelMode && !detail.can_cancel && <p className="text-sm text-amber-800">Lượt đăng ký không còn được phép hủy.</p>}<div className="flex justify-end gap-2"><button type="button" className="rounded-xl border px-4 py-2 text-sm" disabled={cancelling} onClick={closeDetail}>Đóng</button>{detail.can_cancel && (cancelMode ? <button type="button" className="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" disabled={detailLoading || cancelling || Boolean(modalError)} onClick={cancelBooking}>{cancelling ? 'Đang hủy…' : 'Xác nhận hủy'}</button> : <button type="button" className={primaryClass} disabled={detailLoading || Boolean(modalError)} onClick={() => setCancelMode(true)}>Hủy đăng ký</button>)}</div></div>}
    </dialog>
  </section>;
}
