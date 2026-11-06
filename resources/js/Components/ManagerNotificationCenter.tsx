import React, { useEffect, useRef, useState } from 'react';
import { api, ManagerNotification } from '../Services/api';
import { amenityCache } from '../Services/amenityCache';
import { CustomSelect } from './Common/CustomSelect';

export function useManagerNotifications(expanded = true) {
  const [items, setItems] = useState<ManagerNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [category, setCategory] = useState('');
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [revision, setRevision] = useState(0);
  const [account, setAccount] = useState(() => api.getAuthToken());
  const mutation = useRef(0);
  const pendingRead = useRef<ManagerNotification | null>(null);
  const activity = useRef<Record<string, string> | null>(null);
  const retry = () => setRevision((value) => value + 1);

  useEffect(() => {
    const sync = () => {
      const token = api.getAuthToken();
      if (token !== account) {
        mutation.current++; activity.current = null; pendingRead.current = null; setItems([]); setUnread(0); setPage(1); setError(''); setAccount(token);
      } else retry();
    };
    const storage = (event: StorageEvent) => { if (!event.key || ['smart_cassavas_token', 'smartcassavas_session', 'manager-notifications-read'].includes(event.key)) sync(); };
    window.addEventListener('storage', storage);
    window.addEventListener('AUTH_SESSION_CHANGED', sync);
    window.addEventListener('focus', sync);
    return () => { window.removeEventListener('storage', storage); window.removeEventListener('AUTH_SESSION_CHANGED', sync); window.removeEventListener('focus', sync); };
  }, [account]);

  useEffect(() => {
    let active = true, running = false;
    let timer: ReturnType<typeof setTimeout>;
    let controller: AbortController | undefined;
    setLoading(true);
    const load = async () => {
      if (!active || running || document.hidden || !account) { if (!account) setLoading(false); return; }
      if (api.getAuthToken() !== account) { mutation.current++; setItems([]); setUnread(0); setPage(1); setError(''); setAccount(api.getAuthToken()); return; }
      clearTimeout(timer); running = true; controller = new AbortController();
      const version = mutation.current;
      try {
        const data = await api.getManagerNotifications(page, category, unreadOnly, controller.signal, !expanded);
        if (!active || controller.signal.aborted || version !== mutation.current || api.getAuthToken() !== account) return;
        if (!Array.isArray(data.items)) throw new Error('Dữ liệu thông báo không hợp lệ.');
        setItems(data.items); setUnread(data.unread_count); setPages(data.total_pages); setPage(data.page); setError('');
        if (activity.current && data.category_activity) {
          for (const [category, event] of Object.entries({ TICKET: 'TICKET_NOTIFICATION_RECEIVED', AMENITY_BOOKING: 'AMENITY_NOTIFICATION_RECEIVED', SUGGESTION: 'SUGGESTION_NOTIFICATION_RECEIVED' })) {
            if (data.category_activity[category] !== activity.current[category]) window.dispatchEvent(new Event(event));
          }
        }
        activity.current = data.category_activity ?? null;
      } catch (err: any) {
        if (active && !controller.signal.aborted) {
          setError(err.message || 'Không thể tải thông báo.');
          if ([401, 403].includes(err.status)) { active = false; setItems([]); setUnread(0); setLoading(false); }
        }
      } finally {
        running = false;
        if (active) { setLoading(false); timer = setTimeout(load, 15000); }
      }
    };
    const visible = () => { if (document.hidden) { clearTimeout(timer); controller?.abort(); } else void load(); };
    document.addEventListener('visibilitychange', visible);
    const unsubscribe = amenityCache.subscribe((event) => { if (event.type === 'AMENITY_BOOKING_CHANGED') void load(); });
    void load();
    return () => { active = false; clearTimeout(timer); controller?.abort(); unsubscribe(); document.removeEventListener('visibilitychange', visible); };
  }, [account, page, category, unreadOnly, revision, expanded]);

  const read = async (item: ManagerNotification) => {
    const currentAccount = api.getAuthToken();
    if (currentAccount !== account) throw new Error('Phiên đăng nhập đã thay đổi. Vui lòng tải lại.');
    mutation.current++;
    const result = await api.readManagerNotification(item.id);
    if (api.getAuthToken() !== currentAccount) throw new Error('Phiên đăng nhập đã thay đổi. Vui lòng tải lại.');
    setItems((current) => current.map((notice) => notice.id === item.id ? { ...notice, is_read: true } : notice));
    setUnread(result.unread_count);
    if (unreadOnly) retry();
    try { localStorage.setItem('manager-notifications-read', String(Date.now())); } catch { /* Storage may be disabled; polling still synchronizes tabs. */ }
    return true;
  };
  const open = async (item: ManagerNotification) => {
    const target = new URL(item.deep_link_url, location.origin);
    const tab = { TICKET: 'tickets', AMENITY_BOOKING: 'amenities', SUGGESTION: 'suggestions' }[item.category];
    if (target.origin !== location.origin || !['/admin', '/quan-ly'].includes(target.pathname) || target.searchParams.get('tab') !== tab || target.username || target.password) throw new Error('Liên kết thông báo không hợp lệ.');
    if (tab === 'tickets' && !target.searchParams.get('ticket_id')) throw new Error('Thông báo không có mã sự cố.');
    const idKey = { tickets: 'ticket_id', amenities: 'booking_code', suggestions: 'suggestion_id' }[tab];
    if (!idKey || !target.searchParams.get(idKey)) throw new Error('Thông báo không có mã nội dung.');
    target.pathname = tab === 'amenities' || tab === 'suggestions' ? '/quan-ly' : location.pathname.startsWith('/admin') ? '/admin' : '/quan-ly';
    pendingRead.current = item;
    window.history.pushState({}, '', target.pathname + target.search);
    window.dispatchEvent(new PopStateEvent('popstate'));
  };
  useEffect(() => {
    const opened = (event: Event) => {
      const detail = (event as CustomEvent<{ category: ManagerNotification['category']; id: string }>).detail;
      const item = pendingRead.current;
      if (!item || detail.category !== item.category) return;
      const key = { TICKET: 'ticket_id', AMENITY_BOOKING: 'booking_code', SUGGESTION: 'suggestion_id' }[item.category];
      if (new URL(item.deep_link_url, location.origin).searchParams.get(key) !== detail.id) return;
      pendingRead.current = null;
      void read(item).catch((failure) => { setError(failure.message || 'Không thể đánh dấu đã đọc.'); });
    };
    window.addEventListener('NOTIFICATION_DETAIL_OPENED', opened);
    return () => window.removeEventListener('NOTIFICATION_DETAIL_OPENED', opened);
  });
  const readAll = async () => {
    const token = api.getAuthToken();
    if (token !== account) throw new Error('Phiên đăng nhập đã thay đổi.');
    mutation.current++;
    const result = await api.readAllManagerNotifications();
    if (api.getAuthToken() !== token) return;
    setUnread(result.unread_count);
    retry();
    try { localStorage.setItem('manager-notifications-read', String(Date.now())); } catch { /* Polling synchronizes when storage is unavailable. */ }
  };
  return { items, unread, page, pages, loading, error, retry, open, read, readAll, setPage, category, unreadOnly,
    setCategory: (value: string) => { setCategory(value); setPage(1); },
    setUnreadOnly: (value: boolean) => { setUnreadOnly(value); setPage(1); } };
}

export function ManagerNotificationCenter({ feed, onClose }: { feed: ReturnType<typeof useManagerNotifications>; onClose: () => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const panel = useRef<HTMLElement>(null);
  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    panel.current?.focus();
    return () => { opener?.focus(); };
  }, []);
  const act = async (item: ManagerNotification, open: boolean) => {
    if (busy) return;
    setBusy(true); setError('');
    try { if (open) { await feed.open(item); onClose(); } else await feed.read(item); }
    catch (err: any) { setError(err.message || 'Không thể cập nhật thông báo.'); }
    finally { setBusy(false); }
  };
  return <section ref={panel} tabIndex={-1} aria-label="Thông báo vận hành" className="fixed right-4 top-20 z-[60] max-h-[calc(100dvh-6rem)] w-[calc(100%-2rem)] max-w-sm space-y-3 overflow-y-auto rounded-2xl border border-neutral-200 bg-white p-4 shadow-lg outline-none" onKeyDown={(event) => { if (event.key === 'Escape') onClose(); }}>
    <div className="flex items-center justify-between gap-2"><h2 className="text-sm font-bold">Thông báo vận hành · {feed.unread} chưa đọc</h2><button type="button" aria-label="Đóng thông báo" onClick={onClose} className="min-h-10 rounded-lg px-2 text-xs hover:bg-neutral-100">Đóng</button></div>
    <button type="button" disabled={busy || feed.unread === 0} className="min-h-10 cursor-pointer rounded-lg px-2 text-xs text-sky-800 underline disabled:opacity-40" onClick={async () => { setBusy(true); setError(''); try { await feed.readAll(); } catch (err: any) { setError(err.message || 'Không thể đánh dấu đã đọc.'); } finally { setBusy(false); } }}>Đánh dấu tất cả đã đọc</button>
    <CustomSelect overlay label="Nhóm thông báo" value={feed.category} onChange={feed.setCategory} options={[{ value: '', label: 'Tất cả' }, { value: 'TICKET', label: 'Sự cố' }, { value: 'AMENITY_BOOKING', label: 'Tiện ích' }, { value: 'SUGGESTION', label: 'Góp ý' }]} />
    <label className="flex min-h-10 items-center gap-2 text-xs"><input type="checkbox" checked={feed.unreadOnly} onChange={(event) => feed.setUnreadOnly(event.target.checked)} />Chỉ thông báo chưa đọc</label>
    {feed.loading && <p role="status" className="text-xs">Đang tải thông báo…</p>}
    {feed.error && <p role="alert" className="text-xs text-rose-700">{feed.error} <button type="button" onClick={feed.retry} className="underline">Thử lại</button></p>}
    {error && <p role="alert" className="text-xs text-rose-700">{error}</p>}
    {!feed.loading && !feed.error && !feed.items.length && <p className="text-sm text-neutral-500">Chưa có thông báo.</p>}
    <ol className="space-y-2">{feed.items.map((item) => <li key={item.id} className={`rounded-xl border p-3 text-xs ${item.is_read ? 'border-neutral-200' : 'border-sky-200 bg-sky-50'}`}>
      <p className="mb-1 text-neutral-500">{{ TICKET: 'Sự cố', AMENITY_BOOKING: 'Tiện ích', SUGGESTION: 'Góp ý' }[item.category]} · {item.is_read ? 'Đã đọc' : 'Chưa đọc'}</p>
      <p className="font-semibold">{item.title}</p><p className="mt-1 break-words">{item.body_message}</p><time className="mt-1 block text-neutral-500" dateTime={item.created_at}>{new Date(item.created_at).toLocaleString('vi-VN')}</time>
      <div className="mt-2 flex flex-wrap gap-2"><button type="button" disabled={busy} className="min-h-10 cursor-pointer rounded-lg border px-3 disabled:opacity-50" onClick={() => void act(item, true)}>Xem chi tiết</button>{!item.is_read && <button type="button" disabled={busy} className="min-h-10 cursor-pointer rounded-lg px-2 text-sky-800 underline disabled:opacity-50" onClick={() => void act(item, false)}>Đánh dấu đã đọc</button>}</div>
    </li>)}</ol>
    <nav aria-label="Phân trang thông báo" className="flex items-center justify-between gap-2 text-xs"><button type="button" disabled={feed.loading || busy || feed.page <= 1} className="min-h-10 rounded-lg border px-3 disabled:opacity-40" onClick={() => feed.setPage(feed.page - 1)}>Thông báo trước</button><span>{feed.page}/{feed.pages}</span><button type="button" disabled={feed.loading || busy || feed.page >= feed.pages} className="min-h-10 rounded-lg border px-3 disabled:opacity-40" onClick={() => feed.setPage(feed.page + 1)}>Thông báo sau</button></nav>
  </section>;
}
