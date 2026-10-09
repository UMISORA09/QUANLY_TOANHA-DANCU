import React, { useEffect, useState } from 'react';
import { AmenityBookingWorklist as Worklist, ApiError, api } from '../../Services/api';
import { amenityCache } from '../../Services/amenityCache';

export function AmenityBookingWorklist({ onOpen, opening = false }: { opening?: boolean; onOpen: (amenityId: string, bookingCode: string) => void }) {
  const [bucket, setBucket] = useState<'PENDING' | 'REPORTED' | 'REVIEW'>('PENDING');
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Worklist | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    let running = false;
    const load = async () => {
      if (running || controller.signal.aborted || document.hidden) return;
      running = true;
      try {
        const result = await api.getAmenityBookingWorklist(bucket, page, controller.signal);
        if (!controller.signal.aborted && result?.items) { setData(result); setError(null); if (result.page !== page) setPage(result.page); }
      } catch (failure) { if (!controller.signal.aborted) setError(failure as ApiError); }
      finally { running = false; if (!controller.signal.aborted) setLoading(false); }
    };
    setLoading(true);
    void load();
    const timer = setInterval(() => void load(), 15000);
    const onVisible = () => { if (!document.hidden) void load(); };
    document.addEventListener('visibilitychange', onVisible);
    const unsubscribe = amenityCache.subscribe((event) => { if (event.type === 'AMENITY_BOOKING_CHANGED') void load(); });
    return () => { controller.abort(); clearInterval(timer); document.removeEventListener('visibilitychange', onVisible); unsubscribe(); };
  }, [bucket, page, revision]);
  return <section aria-label="Công việc đăng ký tiện ích" className="amenity-booking-controls rounded-2xl border border-neutral-200 bg-white p-4"><h2 className="font-bold text-neutral-900">Công việc đăng ký tiện ích</h2><p className="mt-1 text-xs text-neutral-500">Theo dõi đăng ký cần xử lý của tất cả tiện ích.</p><div className="my-3 flex flex-wrap gap-2">{Object.entries({ PENDING: 'Chờ duyệt', REPORTED: 'Chờ đối soát', REVIEW: 'Cần xử lý' }).map(([value, label]) => <button type="button" key={value} aria-pressed={bucket === value} onClick={() => { if (bucket === value) return; setBucket(value as typeof bucket); setPage(1); setData(null); }} className={`rounded-xl border px-3 py-2 text-xs font-semibold ${bucket === value ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-200'}`}>{label} ({data?.counts[value as typeof bucket] ?? "…"})</button>)}</div>{opening && <p role="status" className="text-xs">Đang mở đăng ký…</p>}{loading && <p role="status" className="text-xs">Đang tải công việc…</p>}{error && <p role="alert" className="text-xs text-rose-700">{error.message || 'Không thể tải công việc.'} <button type="button" onClick={() => setRevision((value) => value + 1)} className="underline">Thử lại</button></p>}{!loading && !error && data?.items.length === 0 && <p className="text-sm text-neutral-500">Không có đăng ký cần xử lý trong mục này.</p>}<div className="divide-y divide-neutral-100">{data?.items.map((item) => <article key={item.id} className="flex flex-col justify-between gap-2 py-3 text-xs sm:flex-row sm:items-center"><div><p className="font-semibold">{item.amenity_name} · {item.booking_code}</p><p className="mt-1 text-neutral-600">{item.resident_name} · Căn hộ {item.apartment_number} · {new Date(item.booking_date + 'T00:00:00').toLocaleDateString('vi-VN')} · {item.start_time.slice(0, 5)}–{item.end_time.slice(0, 5)}</p>{item.review_overdue && <p className="mt-1 font-semibold text-amber-800">Đối soát quá hạn — chỗ đang được giữ đến giờ sử dụng</p>}</div><button type="button" disabled={loading || opening || Boolean(error)} onClick={() => onOpen(item.amenity_id, item.booking_code)} className="shrink-0 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 font-semibold text-emerald-800 disabled:opacity-40">Mở đăng ký</button></article>)}</div>{data && <div className="mt-3 flex items-center justify-between text-xs"><button type="button" disabled={loading || page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Công việc trước</button><span>{page}/{data.total_pages}</span><button type="button" disabled={loading || page >= data.total_pages} onClick={() => setPage(page + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Công việc sau</button></div>}</section>;
}
