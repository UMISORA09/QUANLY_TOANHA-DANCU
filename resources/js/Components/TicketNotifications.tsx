import React, { useEffect, useRef, useState } from 'react';
import { api, TicketNotification } from '../Services/api';

export function useTicketNotifications() {
  const [items, setItems] = useState<TicketNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  const latest = useRef<string | null>(null);
  const previousUnread = useRef<number | null>(null);
  const initialized = useRef(false);
  const mutation = useRef(0);
  const pendingRead = useRef<TicketNotification | null>(null);
  useEffect(() => {
    let active = true, running = false;
    let timer: ReturnType<typeof setTimeout>;
    const controller = new AbortController();
    setLoading(true);
    const load = async () => {
      if (!active || running) return;
      clearTimeout(timer);
      if (document.hidden) { timer = setTimeout(load, 15000); return; }
      running = true;
      const version = mutation.current;
      try {
        const data = await api.getTicketNotifications(page, controller.signal);
        if (!active || version !== mutation.current) return;
        if (!Array.isArray(data.items)) throw new Error('Dữ liệu thông báo sự cố không hợp lệ.');
        setItems(data.items); setUnread(data.unread_count); setPages(data.total_pages); setError('');
        const newest = data.items[0]?.id || null;
        if (initialized.current && ((page === 1 && newest !== latest.current) || data.unread_count > (previousUnread.current ?? 0))) {
          window.dispatchEvent(new Event('TICKET_NOTIFICATION_RECEIVED'));
        }
        if (page === 1) latest.current = newest;
        previousUnread.current = data.unread_count;
        initialized.current = true;
      } catch (err: any) {
        if (active) {
          setError(err.message);
          if ([401, 403].includes(err.status)) { setLoading(false); active = false; setItems([]); setUnread(0); }
        }
      } finally { running = false; if (active) { setLoading(false); timer = setTimeout(load, 15000); } }
    };
    const visible = () => { if (!document.hidden) void load(); };
    document.addEventListener('visibilitychange', visible); window.addEventListener('focus', visible);
    void load();
    return () => { active = false; clearTimeout(timer); controller.abort(); document.removeEventListener('visibilitychange', visible); window.removeEventListener('focus', visible); };
  }, [page, revision]);
  const retry = () => setRevision((value) => value + 1);
  const open = async (item: TicketNotification) => {
    const target = new URL(item.deep_link_url, location.origin);
    if (target.origin !== location.origin || !['/admin', '/quan-ly', '/cu-dan'].includes(target.pathname) || target.searchParams.get('tab') !== 'tickets') throw new Error('Liên kết thông báo sự cố không hợp lệ.');
    if (!target.searchParams.get('ticket_id')) throw new Error('Thông báo không có mã sự cố.');
    pendingRead.current = item;
    window.history.pushState({}, '', target.pathname + target.search);
    window.dispatchEvent(new PopStateEvent('popstate'));
    window.dispatchEvent(new Event('TICKET_NOTIFICATION_RECEIVED'));
  };
  useEffect(() => {
    const opened = (event: Event) => {
      const detail = (event as CustomEvent<{ category: string; id: string }>).detail;
      const item = pendingRead.current;
      if (!item || detail.category !== 'TICKET' || new URL(item.deep_link_url, location.origin).searchParams.get('ticket_id') !== detail.id) return;
      pendingRead.current = null;
      const token = api.getAuthToken();
      mutation.current++;
      void api.readTicketNotification(item.id).then(() => {
        if (api.getAuthToken() !== token) return;
        retry();
      }).catch((failure) => setError(failure.message || 'Không thể đánh dấu đã đọc.'));
    };
    window.addEventListener('NOTIFICATION_DETAIL_OPENED', opened);
    return () => window.removeEventListener('NOTIFICATION_DETAIL_OPENED', opened);
  });
  return { items, unread, page, pages, setPage, error, loading, retry, open };
}

export function useTicketNotificationRefresh(onChange: () => void) {
  const callback = useRef(onChange); callback.current = onChange;
  useEffect(() => {
    const refresh = () => callback.current();
    window.addEventListener('TICKET_NOTIFICATION_RECEIVED', refresh);
    return () => window.removeEventListener('TICKET_NOTIFICATION_RECEIVED', refresh);
  }, []);
}

export function TicketNotificationList({ feed }: { feed: ReturnType<typeof useTicketNotifications> }) {
  const [opening, setOpening] = useState(false);
  const [openError, setOpenError] = useState('');
  return <section aria-label="Thông báo sự cố" className="mt-4 space-y-3 border-t border-neutral-200 pt-4">
    <h3 className="text-sm font-semibold text-neutral-900">Sự cố · {feed.unread} chưa đọc</h3>
    {feed.loading && <p role="status" className="text-xs text-neutral-500">Đang tải thông báo sự cố…</p>}
    {feed.error && <p role="alert" className="text-xs text-rose-700">Không thể tải thông báo sự cố. <button type="button" className="underline" onClick={feed.retry}>Thử lại</button></p>}
    {openError && <p role="alert" className="text-xs text-rose-700">{openError}</p>}
    {!feed.loading && !feed.error && !feed.items.length && <p className="text-xs text-neutral-500">Chưa có thông báo sự cố.</p>}
    <div className="max-h-64 space-y-2 overflow-y-auto">{feed.items.map((item) => <button key={item.id} type="button" disabled={opening} className={`block w-full cursor-pointer rounded-xl border p-3 text-left text-xs disabled:opacity-50 ${item.is_read ? 'border-neutral-200' : 'border-sky-200 bg-sky-50'}`} onClick={async () => {
      setOpening(true); setOpenError('');
      try { await feed.open(item); } catch (err: any) { setOpenError(err.message); } finally { setOpening(false); }
    }}><span className="block font-semibold">{item.title}</span><span className="mt-1 block">{item.body_message}</span>{item.created_at && <time dateTime={item.created_at} className="mt-1 block text-neutral-500">{new Date(item.created_at).toLocaleString('vi-VN')}</time>}</button>)}</div>
    {feed.pages > 1 && <nav aria-label="Phân trang thông báo sự cố" className="flex items-center justify-between gap-2 text-xs"><button type="button" disabled={feed.loading || opening || feed.page <= 1} className="min-h-9 rounded-lg border border-neutral-200 px-3 disabled:opacity-40" onClick={() => feed.setPage(feed.page - 1)}>Sự cố trước</button><span>{feed.page}/{feed.pages}</span><button type="button" disabled={feed.loading || opening || feed.page >= feed.pages} className="min-h-9 rounded-lg border border-neutral-200 px-3 disabled:opacity-40" onClick={() => feed.setPage(feed.page + 1)}>Sự cố tiếp theo</button></nav>}
  </section>;
}
