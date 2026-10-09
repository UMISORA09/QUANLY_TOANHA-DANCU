import { useEffect, useRef, useState } from 'react';
import { AmenityBookingNotification, ApiError, api } from '../Services/api';
import { amenityCache } from '../Services/amenityCache';

export function useAmenityNotifications(audience: 'resident' | 'admin') {
  const [items, setItems] = useState<AmenityBookingNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [alert, setAlert] = useState<AmenityBookingNotification | null>(null);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  const seen = useRef(new Set<string>());
  const mutation = useRef(0);
  useEffect(() => {
    let stopped = false;
    let running = false;
    let timer: ReturnType<typeof setTimeout>;
    let controller: AbortController | undefined;
    setLoading(true);
    const load = async () => {
      if (stopped || running || document.hidden) return;
      clearTimeout(timer);
      running = true;
      controller = new AbortController();
      const version = mutation.current;
      let delay = 5000;
      try {
        const data = await api.getAmenityNotifications(audience, page, unreadOnly, controller.signal);
        if (stopped || version !== mutation.current || !Array.isArray(data?.items)) return;
        setItems(data.items); setUnreadCount(data.unread_count); setPages(data.total_pages || 1);
        if (data.page && data.page !== page) setPage(data.page);
        setError(null);
        const fresh = data.latest_unread ?? data.items.find((item) => !item.isRead);
        if (fresh && !seen.current.has(fresh.id)) {
          seen.current.add(fresh.id);
          if (seen.current.size > 100) seen.current.delete(seen.current.values().next().value!);
          setAlert(fresh);
          if (audience === 'resident') window.dispatchEvent(new Event('AMENITY_NOTIFICATION_RECEIVED'));
        }
      } catch (failure) {
        if (!stopped) {
          const issue = failure as ApiError;
          setError(issue);
          if ([401, 403].includes(issue.status || 0)) { stopped = true; setItems([]); setUnreadCount(0); setAlert(null); }
        }
        delay = 15000;
      } finally {
        running = false;
        if (!controller.signal.aborted) setLoading(false);
        if (!stopped) timer = setTimeout(() => void load(), delay);
      }
    };
    const visible = () => { if (!document.hidden) void load(); };
    void load();
    document.addEventListener('visibilitychange', visible);
    window.addEventListener('focus', visible);
    const unsubscribe = amenityCache.subscribe((event) => { if (event.type === 'AMENITY_BOOKING_CHANGED') void load(); });
    return () => { stopped = true; clearTimeout(timer); controller?.abort(); document.removeEventListener('visibilitychange', visible); window.removeEventListener('focus', visible); unsubscribe(); };
  }, [audience, page, unreadOnly, revision]);

  const markRead = async (item: AmenityBookingNotification) => {
    mutation.current++;
    try {
      await api.readAmenityNotification(audience, item.id);
      setItems((current) => current.map((notice) => notice.id === item.id ? { ...notice, isRead: true } : notice));
      setAlert((current) => current?.id === item.id ? null : current);
      setRevision((value) => value + 1);
    } catch (failure) { setError(failure as ApiError); }
  };
  return { items, unreadCount, alert, setAlert, page, pages, setPage, unreadOnly,
    setUnreadOnly: (value: boolean) => { setUnreadOnly(value); setPage(1); }, error, loading, markRead,
    retry: () => setRevision((value) => value + 1) };
}
