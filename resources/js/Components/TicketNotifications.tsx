import React, { useEffect, useRef, useState } from 'react';
import { api, TicketNotification } from '../Services/api';

export function TicketNotifications({ onChange }: { onChange: () => void }) {
  const [items, setItems] = useState<TicketNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState('');
  const callback = useRef(onChange); callback.current = onChange;
  const latest = useRef<string | null>(null);
  useEffect(() => {
    let active = true, running = false;
    let timer: ReturnType<typeof setTimeout>;
    const controller = new AbortController();
    const load = async () => {
      if (!active || running) return;
      clearTimeout(timer);
      if (document.hidden) { timer = setTimeout(load, 15000); return; }
      running = true;
      try {
        const data = await api.getTicketNotifications(page, controller.signal);
        if (!active) return;
        setItems(data.items); setUnread(data.unread_count); setPages(data.total_pages); setError('');
        if (page === 1 && data.items[0]?.id !== latest.current) {
          if (latest.current) callback.current();
          latest.current = data.items[0]?.id || null;
        }
      } catch (err: any) { if (active) setError(err.message); }
      finally { running = false; if (active) timer = setTimeout(load, 15000); }
    };
    const visible = () => { if (!document.hidden) void load(); };
    document.addEventListener('visibilitychange', visible); window.addEventListener('focus', visible);
    void load();
    return () => { active = false; clearTimeout(timer); controller.abort(); document.removeEventListener('visibilitychange', visible); window.removeEventListener('focus', visible); };
  }, [page]);
  return <aside className="rounded-2xl border bg-white p-3 text-sm"><button type="button" aria-expanded={open} className="cursor-pointer font-semibold" onClick={() => setOpen(!open)}>Thông báo sự cố ({unread} chưa đọc)</button>
    {open && <div className="mt-3 space-y-2">{error && <p role="alert" className="text-rose-700">{error}</p>}{!items.length && <p className="text-neutral-500">Chưa có thông báo.</p>}{items.map((item) => <button key={item.id} type="button" className={`block w-full cursor-pointer rounded-xl border p-3 text-left ${item.is_read ? '' : 'bg-sky-50'}`} onClick={async () => {
      try { await api.readTicketNotification(item.id); const target = new URL(item.deep_link_url, location.origin); if (target.origin === location.origin && ['/admin', '/quan-ly', '/cu-dan'].includes(target.pathname)) location.assign(target.href); }
      catch (err: any) { setError(err.message); }
    }}><span className="block font-medium">{item.title}</span><span className="text-xs text-neutral-500">{item.body_message}</span></button>)}<div className="flex items-center justify-end gap-3"><button disabled={page <= 1} className="cursor-pointer disabled:opacity-40" onClick={() => setPage(page - 1)}>Thông báo trước</button><span>{page}/{pages}</span><button disabled={page >= pages} className="cursor-pointer disabled:opacity-40" onClick={() => setPage(page + 1)}>Thông báo sau</button></div></div>}
  </aside>;
}
