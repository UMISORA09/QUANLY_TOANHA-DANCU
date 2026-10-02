import { useState, useEffect, useCallback, useRef } from 'react';
import { cicdApi, FreshnessOverviewData } from '../Services/cicdApi';

export interface UseFreshnessOptions {
  pollingIntervalMs?: number;
  autoStart?: boolean;
  initialData?: FreshnessOverviewData | null;
  onError?: (err: Error) => void;
}

export interface UseFreshnessReturn {
  freshness: FreshnessOverviewData | null;
  loading: boolean;
  isRefreshing: boolean;
  error: Error | null;
  refresh: (force?: boolean) => Promise<void>;
  status: string;
}

/**
 * Custom hook for freshness monitoring and automated polling.
 */
export function useFreshness(options: UseFreshnessOptions = {}): UseFreshnessReturn {
  const {
    pollingIntervalMs = 30000,
    autoStart = true,
    initialData = null,
    onError,
  } = options;

  const [freshness, setFreshness] = useState<FreshnessOverviewData | null>(initialData);
  const [loading, setLoading] = useState<boolean>(!initialData);
  const [isRefreshing, setIsRefreshing] = useState<boolean>(false);
  const [error, setError] = useState<Error | null>(null);

  const isMountedRef = useRef(true);

  const fetchFreshness = useCallback(
    async (force = false, isBackground = false) => {
      if (!isBackground) {
        if (!freshness) setLoading(true);
        else setIsRefreshing(true);
      }
      setError(null);

      try {
        const data = await cicdApi.getFreshness(force);
        if (isMountedRef.current) {
          setFreshness(data);
        }
      } catch (err: any) {
        if (isMountedRef.current) {
          const fetchError = err instanceof Error ? err : new Error(String(err));
          setError(fetchError);
          onError?.(fetchError);
        }
      } finally {
        if (isMountedRef.current) {
          setLoading(false);
          setIsRefreshing(false);
        }
      }
    },
    [freshness, onError]
  );

  const refresh = useCallback(
    async (force = true) => {
      await fetchFreshness(force, false);
    },
    [fetchFreshness]
  );

  useEffect(() => {
    isMountedRef.current = true;

    if (autoStart && !initialData) {
      fetchFreshness(false, false);
    }

    let intervalId: NodeJS.Timeout | null = null;
    if (autoStart && pollingIntervalMs > 0) {
      intervalId = setInterval(() => {
        fetchFreshness(false, true);
      }, pollingIntervalMs);
    }

    return () => {
      isMountedRef.current = false;
      if (intervalId) clearInterval(intervalId);
    };
  }, [autoStart, pollingIntervalMs, initialData, fetchFreshness]);

  const status = freshness?.overall_state || 'UNKNOWN';

  return {
    freshness,
    loading,
    isRefreshing,
    error,
    refresh,
    status,
  };
}
