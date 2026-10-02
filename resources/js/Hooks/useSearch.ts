import { useState, useRef, useEffect, useCallback } from 'react';
import { useDebounce } from './useDebounce';

export interface UseSearchOptions<T = any> {
  debounceMs?: number;
  onSearch?: (query: string, signal: AbortSignal) => Promise<T>;
  initialQuery?: string;
}

export interface UseSearchReturn<T = any> {
  query: string;
  debouncedQuery: string;
  setQuery: (q: string) => void;
  isSearching: boolean;
  results: T | null;
  setResults: (results: T | null) => void;
  error: Error | null;
  clear: () => void;
}

/**
 * Custom hook managing search query state, debouncing, and race-condition resilient fetch operations.
 */
export function useSearch<T = any>(options: UseSearchOptions<T> = {}): UseSearchReturn<T> {
  const { debounceMs = 250, onSearch, initialQuery = '' } = options;

  const [query, setQuery] = useState<string>(initialQuery);
  const debouncedQuery = useDebounce(query, debounceMs);
  const [isSearching, setIsSearching] = useState<boolean>(false);
  const [results, setResults] = useState<T | null>(null);
  const [error, setError] = useState<Error | null>(null);

  const abortControllerRef = useRef<AbortController | null>(null);
  const sequenceRef = useRef<number>(0);

  const clear = useCallback(() => {
    setQuery('');
    setResults(null);
    setError(null);
  }, []);

  useEffect(() => {
    if (!onSearch) return;

    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    const abortController = new AbortController();
    abortControllerRef.current = abortController;
    const currentSeq = ++sequenceRef.current;

    setIsSearching(true);
    setError(null);

    onSearch(debouncedQuery, abortController.signal)
      .then((data) => {
        if (currentSeq === sequenceRef.current && !abortController.signal.aborted) {
          setResults(data);
          setIsSearching(false);
        }
      })
      .catch((err) => {
        if (err.name === 'AbortError') return;
        if (currentSeq === sequenceRef.current) {
          setError(err instanceof Error ? err : new Error(String(err)));
          setIsSearching(false);
        }
      });

    return () => {
      abortController.abort();
    };
  }, [debouncedQuery, onSearch]);

  return {
    query,
    debouncedQuery,
    setQuery,
    isSearching,
    results,
    setResults,
    error,
    clear,
  };
}
