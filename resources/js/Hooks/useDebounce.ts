import { useState, useEffect } from 'react';

/**
 * Custom hook to debounce a rapidly changing value.
 * Useful for search inputs to prevent redundant network requests on every keystroke.
 */
export function useDebounce<T>(value: T, delayMs: number = 200): T {
  const [debouncedValue, setDebouncedValue] = useState<T>(value);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedValue(value);
    }, delayMs);

    return () => {
      clearTimeout(timer);
    };
  }, [value, delayMs]);

  return debouncedValue;
}

export default useDebounce;
