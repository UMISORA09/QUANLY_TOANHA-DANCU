import { useEffect, useRef, useCallback, useState } from 'react';

export interface RealtimeEventPayload {
  id?: number;
  module: string;
  entity: string;
  action: string;
  entity_id?: string;
  updated_at?: string;
  version?: string;
  timestamp?: number;
  cooldown_until?: string;
  cooldown_seconds?: number;
  cooldown_until_ts?: number;
  actor_id?: string;
  [key: string]: any;
}

interface UseRealtimeSyncOptions {
  channel: string | string[];
  onEvent: (event: RealtimeEventPayload) => void;
  onReconnect?: () => void;
  enabled?: boolean;
}

export function useRealtimeSync({
  channel,
  onEvent,
  onReconnect,
  enabled = true,
}: UseRealtimeSyncOptions) {
  const channelString = Array.isArray(channel) ? channel.join(',') : channel;
  const onEventRef = useRef(onEvent);
  const onReconnectRef = useRef(onReconnect);
  const lastEventIdRef = useRef<number>(0);
  const lastAppliedTimestampRef = useRef<number>(0);
  const abortControllerRef = useRef<AbortController | null>(null);
  const reconnectTimeoutRef = useRef<any>(null);
  const isMountedRef = useRef<boolean>(true);

  // Keep latest callback references to prevent reconnect loops
  useEffect(() => {
    onEventRef.current = onEvent;
  }, [onEvent]);

  useEffect(() => {
    onReconnectRef.current = onReconnect;
  }, [onReconnect]);

  const getAuthInfo = useCallback(() => {
    try {
      const token =
        localStorage.getItem('smart_cassavas_token') ||
        sessionStorage.getItem('smart_cassavas_token') ||
        localStorage.getItem('smart_token') ||
        sessionStorage.getItem('smart_token') ||
        localStorage.getItem('token') ||
        sessionStorage.getItem('token') ||
        '';

      let userId = '';
      const rawUser = localStorage.getItem('smart_cassavas_user') || sessionStorage.getItem('smart_cassavas_user');
      if (rawUser) {
        try {
          const parsed = JSON.parse(rawUser);
          userId = parsed.id || parsed.user_id || '';
        } catch {
          // ignore
        }
      }

      if (!userId) {
        const rawSession = localStorage.getItem('smartcassavas_session');
        if (rawSession) {
          try {
            const parsed = JSON.parse(rawSession);
            userId = parsed.user?.id || parsed.id || '';
            if (!token && parsed.token) {
              return { token: parsed.token, userId };
            }
          } catch {
            // ignore
          }
        }
      }

      return { token, userId };
    } catch {
      return { token: '', userId: '' };
    }
  }, []);

  const catchUpMissedEvents = useCallback(async () => {
    if (!channelString) return;
    try {
      const { token, userId } = getAuthInfo();
      const headers: Record<string, string> = { Accept: 'application/json' };
      if (token) headers['Authorization'] = `Bearer ${token}`;
      if (userId) headers['X-User-Id'] = userId;

      const userParam = userId ? `&user_id=${encodeURIComponent(userId)}` : '';
      const res = await fetch(
        `/api/realtime/events?channels=${encodeURIComponent(channelString)}&since_id=${lastEventIdRef.current}${userParam}`,
        { headers }
      );
      if (!res.ok) return;

      const data = await res.json();
      if (data.success && Array.isArray(data.events)) {
        for (const ev of data.events) {
          const evTime = Number(ev.timestamp || 0);
          if (evTime > 0 && evTime <= lastAppliedTimestampRef.current) {
            continue; // Skip stale event
          }
          if (evTime > 0) {
            lastAppliedTimestampRef.current = evTime;
          }
          if (ev.id && ev.id > lastEventIdRef.current) {
            lastEventIdRef.current = ev.id;
          }
          if (ev.cooldown_until || ev.action === 'EDIT_COOLDOWN_STARTED') {
            window.dispatchEvent(new CustomEvent('quoc-tin:cooldown', { detail: ev }));
          }
          onEventRef.current(ev);
        }
      }
    } catch {
      // ignore network errors during catch-up
    }
  }, [channelString, getAuthInfo]);

  useEffect(() => {
    isMountedRef.current = true;
    if (!enabled || !channelString) return;

    // Cross-tab / cross-window instant sync via BroadcastChannel (0.1ms latency on same machine)
    let localBroadcastChannel: BroadcastChannel | null = null;
    try {
      if (typeof BroadcastChannel !== 'undefined') {
        localBroadcastChannel = new BroadcastChannel('smart_cassavas_realtime_local');
        localBroadcastChannel.onmessage = (messageEvent) => {
          if (!isMountedRef.current || !messageEvent.data) return;
          const ev = messageEvent.data;
          const channels = Array.isArray(channel) ? channel : [channel];
          if (channels.includes(ev.channel) || channels.includes(ev.module)) {
            const evTime = Number(ev.timestamp || 0);
            if (evTime > 0 && evTime <= lastAppliedTimestampRef.current) {
              return;
            }
            if (evTime > 0) {
              lastAppliedTimestampRef.current = evTime;
            }
            if (ev.id && ev.id > lastEventIdRef.current) {
              lastEventIdRef.current = ev.id;
            }
            if (ev.cooldown_until || ev.action === 'EDIT_COOLDOWN_STARTED') {
              window.dispatchEvent(new CustomEvent('quoc-tin:cooldown', { detail: ev }));
            }
            onEventRef.current(ev);
          }
        };
      }
    } catch {
      // ignore BroadcastChannel failure
    }

    let retryDelay = 300;

    const startStream = async () => {
      if (!isMountedRef.current) return;

      // Abort previous connection if active
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      abortControllerRef.current = new AbortController();

      const { token, userId } = getAuthInfo();
      const headers: Record<string, string> = {
        Accept: 'text/event-stream',
        'Cache-Control': 'no-cache',
      };
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }
      if (userId) {
        headers['X-User-Id'] = userId;
      }

      try {
        const userParam = userId ? `&user_id=${encodeURIComponent(userId)}` : '';
        const streamUrl = `/api/realtime/stream?channels=${encodeURIComponent(
          channelString
        )}&since_id=${lastEventIdRef.current}${userParam}`;

        const response = await fetch(streamUrl, {
          headers,
          signal: abortControllerRef.current.signal,
        });

        if (!response.ok || !response.body) {
          throw new Error(`HTTP stream status: ${response.status}`);
        }

        // Reset reconnect delay on successful connection
        retryDelay = 300;

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        while (isMountedRef.current) {
          const { value, done } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });
          const messages = buffer.split('\n\n');
          buffer = messages.pop() || '';

          for (const rawMessage of messages) {
            if (!rawMessage.trim()) continue;

            const lines = rawMessage.split('\n');
            let eventType = 'message';
            let eventId: number | null = null;
            let dataStr = '';

            for (const line of lines) {
              if (line.startsWith('event:')) {
                eventType = line.slice(6).trim();
              } else if (line.startsWith('id:')) {
                const idVal = parseInt(line.slice(3).trim(), 10);
                if (!isNaN(idVal)) eventId = idVal;
              } else if (line.startsWith('data:')) {
                dataStr = line.slice(5).trim();
              }
            }

            // Normal cycle termination to avoid hanging PHP workers
            if (eventType === 'cycle') {
              if (isMountedRef.current) {
                setTimeout(startStream, 50);
              }
              return;
            }

            if (eventId && eventId > lastEventIdRef.current) {
              lastEventIdRef.current = eventId;
            }

            if (eventType === 'QuocTinEvent' && dataStr) {
              try {
                const payload: RealtimeEventPayload = JSON.parse(dataStr);

                // Anti-stale monotonic check
                const evTime = Number(payload.timestamp || 0);
                if (evTime > 0 && evTime <= lastAppliedTimestampRef.current) {
                  continue; // Skip stale event
                }
                if (evTime > 0) {
                  lastAppliedTimestampRef.current = evTime;
                }

                // Broadcast cooldown event to shared state listeners
                if (payload.cooldown_until || payload.action === 'EDIT_COOLDOWN_STARTED') {
                  window.dispatchEvent(new CustomEvent('quoc-tin:cooldown', { detail: payload }));
                }

                onEventRef.current(payload);
              } catch {
                // Ignore parse errors on malformed messages
              }
            }
          }
        }
      } catch (err: any) {
        if (err?.name === 'AbortError') {
          return;
        }

        // Network or server drop -> schedule reconnect with exponential backoff
        if (isMountedRef.current) {
          reconnectTimeoutRef.current = setTimeout(async () => {
            if (isMountedRef.current) {
              await catchUpMissedEvents();
              onReconnectRef.current?.();
              startStream();
            }
          }, retryDelay);

          retryDelay = Math.min(retryDelay * 2, 10000);
        }
      }
    };

    startStream();

    // Reconnect on tab focus or returning online
    const handleVisibility = () => {
      if (document.visibilityState === 'visible' && isMountedRef.current) {
        catchUpMissedEvents();
        onReconnectRef.current?.();
        startStream();
      }
    };

    const handleOnline = () => {
      if (isMountedRef.current) {
        catchUpMissedEvents();
        onReconnectRef.current?.();
        startStream();
      }
    };

    document.addEventListener('visibilitychange', handleVisibility);
    window.addEventListener('online', handleOnline);

    return () => {
      isMountedRef.current = false;
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      if (reconnectTimeoutRef.current) {
        clearTimeout(reconnectTimeoutRef.current);
      }
      document.removeEventListener('visibilitychange', handleVisibility);
      window.removeEventListener('online', handleOnline);
    };
  }, [channelString, enabled, getAuthInfo, catchUpMissedEvents]);
}

/**
 * Standardize module key to canonical name
 */
export function normalizeModuleKey(module: string): string {
  const m = (module || '').toLowerCase().trim();
  switch (m) {
    case 'rbac':
    case 'roles':
    case 'permissions':
      return 'rbac';
    case 'residents':
    case 'resident':
      return 'residents';
    case 'temporary_registrations':
    case 'temporary-registrations':
    case 'temporary_registration':
      return 'temporary_registrations';
    case 'account_provisioning':
    case 'account-provisioning':
    case 'accounts':
      return 'account_provisioning';
    case 'vehicles':
    case 'vehicle':
      return 'vehicles';
    default:
      return m.replace(/-/g, '_');
  }
}

export interface ModuleCooldownState {
  isCooldownActive: boolean;
  remainingSeconds: number;
  cooldownUntil: string | null;
  actorId: string | null;
  message: string;
  startCooldown: (cooldownUntil: string, seconds?: number, msg?: string) => void;
  checkServerCooldown: () => Promise<boolean>;
}

/**
 * Hook to manage server-synchronized 120s edit cooldown for a specific module
 */
export function useModuleCooldown(module: string): ModuleCooldownState {
  const canonicalModule = normalizeModuleKey(module);
  const [isCooldownActive, setIsCooldownActive] = useState<boolean>(false);
  const [remainingSeconds, setRemainingSeconds] = useState<number>(0);
  const [cooldownUntil, setCooldownUntil] = useState<string | null>(null);
  const [actorId, setActorId] = useState<string | null>(null);
  const [message, setMessage] = useState<string>('');

  const checkServerCooldown = useCallback(async (): Promise<boolean> => {
    try {
      const res = await fetch(`/api/v1/realtime/cooldown?module=${canonicalModule}`, {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return false;
      const json = await res.json();
      const modData = json?.data?.[canonicalModule];

      if (modData?.is_in_cooldown) {
        const untilStr = modData.cooldown_until;
        const retrySec = Number(modData.retry_after || 0);
        setIsCooldownActive(true);
        setCooldownUntil(untilStr);
        setRemainingSeconds(retrySec > 0 ? retrySec : 120);
        setActorId(modData.actor_id || null);
        setMessage('Chức năng đang tạm khóa chỉnh sửa.');
        return true;
      } else {
        setIsCooldownActive(false);
        setCooldownUntil(null);
        setRemainingSeconds(0);
        return false;
      }
    } catch {
      return false;
    }
  }, [canonicalModule]);

  const startCooldown = useCallback((untilStr: string, seconds?: number, msg?: string) => {
    const sec = seconds || (untilStr ? Math.max(1, Math.ceil((new Date(untilStr).getTime() - Date.now()) / 1000)) : 120);
    setIsCooldownActive(true);
    setCooldownUntil(untilStr);
    setRemainingSeconds(sec);
    if (msg) setMessage(msg);
  }, []);

  // Listen to incoming realtime broadcast cooldown events
  useEffect(() => {
    const handleCooldownEvent = (e: any) => {
      const detail = e.detail;
      if (!detail) return;
      const targetMod = normalizeModuleKey(detail.module || '');
      if (targetMod === canonicalModule) {
        const untilStr = detail.cooldown_until;
        const sec = Number(detail.cooldown_seconds || 120);
        const rem = untilStr ? Math.max(1, Math.ceil((new Date(untilStr).getTime() - Date.now()) / 1000)) : sec;
        setIsCooldownActive(true);
        setCooldownUntil(untilStr || null);
        setRemainingSeconds(rem);
        setActorId(detail.actor_id || null);
        setMessage(detail.message || 'Chức năng đang tạm khóa chỉnh sửa.');
      }
    };

    window.addEventListener('quoc-tin:cooldown', handleCooldownEvent);
    return () => window.removeEventListener('quoc-tin:cooldown', handleCooldownEvent);
  }, [canonicalModule]);

  // Initial check on mount, and on focus / reconnect
  useEffect(() => {
    checkServerCooldown();

    const handleVisibility = () => {
      if (document.visibilityState === 'visible') {
        checkServerCooldown();
      }
    };

    document.addEventListener('visibilitychange', handleVisibility);
    window.addEventListener('online', checkServerCooldown);

    return () => {
      document.removeEventListener('visibilitychange', handleVisibility);
      window.removeEventListener('online', checkServerCooldown);
    };
  }, [checkServerCooldown]);

  // Countdown timer: decrements every second
  useEffect(() => {
    if (!isCooldownActive || remainingSeconds <= 0) return;

    const timer = setInterval(() => {
      setRemainingSeconds((prev) => {
        if (prev <= 1) {
          clearInterval(timer);
          // When countdown reaches 0, verify with server before unlocking
          checkServerCooldown();
          return 0;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(timer);
  }, [isCooldownActive, remainingSeconds, checkServerCooldown]);

  return {
    isCooldownActive,
    remainingSeconds,
    cooldownUntil,
    actorId,
    message,
    startCooldown,
    checkServerCooldown,
  };
}

/**
 * Emit an instant real-time event across tabs and windows on the local machine
 */
export function emitLocalRealtimeEvent(payload: RealtimeEventPayload): void {
  try {
    if (typeof BroadcastChannel !== 'undefined') {
      const bc = new BroadcastChannel('smart_cassavas_realtime_local');
      bc.postMessage(payload);
      bc.close();
    }
  } catch {
    // ignore
  }
}

