import { useCallback, useEffect, useState } from 'react';
import { api, ApiError, ResidentAnnouncementPage } from '../Services/api';

export const announcementReadEvent = 'RESIDENT_ANNOUNCEMENT_READ';

export function useResidentAnnouncements(query: string) {
  const [data, setData] = useState<ResidentAnnouncementPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const retry = useCallback(() => window.dispatchEvent(new Event(announcementReadEvent)), []);
  useEffect(() => {
    let stopped = false; let running = false; let pending = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;
    setLoading(true); setError(null);
    const load = async () => {
      if (stopped || document.hidden) return;
      clearTimeout(timer);
      if (running) { pending = true; return; }
      running = true; pending = false; controller = new AbortController();
      const active = controller;
      try {
        const result = await api.getResidentAnnouncements(new URLSearchParams(query), active.signal);
        if (!stopped && !active.signal.aborted && !pending) { setData(result); setError(null); }
      } catch (failure) {
        if (!stopped && !active.signal.aborted) {
          const issue = failure as ApiError; setError(issue);
          if ([401, 403].includes(issue.status || 0)) { setData(null); stopped = true; }
        }
      } finally {
        running = false;
        if (!stopped && !active.signal.aborted) setLoading(false);
        if (!stopped && !document.hidden) timer = setTimeout(() => void load(), pending ? 0 : 15000);
      }
    };
    const refresh = () => { if (!stopped) void load(); };
    const visible = () => { if (document.hidden) { clearTimeout(timer); controller?.abort(); } else refresh(); };
    const manual = () => { if (stopped) { stopped = false; setLoading(true); } refresh(); };
    void load();
    document.addEventListener('visibilitychange', visible);
    window.addEventListener(announcementReadEvent, manual);
    return () => { stopped = true; controller?.abort(); clearTimeout(timer); document.removeEventListener('visibilitychange', visible); window.removeEventListener(announcementReadEvent, manual); };
  }, [query]);
  return { data, loading, error, retry };
}
