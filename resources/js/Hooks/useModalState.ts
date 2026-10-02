import { useState, useCallback } from 'react';

export interface UseModalStateReturn<T = any> {
  isOpen: boolean;
  data: T | null;
  open: (payload?: T | null) => void;
  close: () => void;
  toggle: () => void;
  setData: (data: T | null | ((prev: T | null) => T | null)) => void;
}

/**
 * Custom hook for reusable modal state management with type-safe payload support.
 */
export function useModalState<T = any>(initialOpen = false, initialData: T | null = null): UseModalStateReturn<T> {
  const [isOpen, setIsOpen] = useState<boolean>(initialOpen);
  const [data, setData] = useState<T | null>(initialData);

  const open = useCallback((payload: T | null = null) => {
    if (payload !== undefined && payload !== null) {
      setData(payload);
    }
    setIsOpen(true);
  }, []);

  const close = useCallback(() => {
    setIsOpen(false);
    setData(null);
  }, []);

  const toggle = useCallback(() => {
    setIsOpen((prev) => !prev);
  }, []);

  return {
    isOpen,
    data,
    open,
    close,
    toggle,
    setData,
  };
}
