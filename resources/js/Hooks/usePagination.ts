import { useState, useCallback, useMemo } from 'react';

export interface UsePaginationOptions {
  initialPage?: number;
  initialLimit?: number;
  total?: number;
  onPageChange?: (page: number) => void;
}

export interface UsePaginationReturn {
  page: number;
  limit: number;
  total: number;
  totalPages: number;
  setPage: (page: number | ((prev: number) => number)) => void;
  setLimit: (limit: number) => void;
  setTotal: (total: number) => void;
  goToNextPage: () => void;
  goToPrevPage: () => void;
  canNextPage: boolean;
  canPrevPage: boolean;
  pageNumbers: (number | string)[];
  handlePageChange: (targetPage: number) => void;
}

/**
 * Generates an array of page numbers with ellipsis for pagination controls.
 */
export function getPageNumbers(current: number, total: number): (number | string)[] {
  if (total <= 7) {
    return Array.from({ length: Math.max(total, 1) }, (_, i) => i + 1);
  }
  const pages: (number | string)[] = [];
  if (current <= 4) {
    for (let i = 1; i <= 5; i++) {
      pages.push(i);
    }
    pages.push('...');
    pages.push(total);
  } else if (current >= total - 3) {
    pages.push(1);
    pages.push('...');
    for (let i = total - 4; i <= total; i++) {
      pages.push(i);
    }
  } else {
    pages.push(1);
    pages.push('...');
    pages.push(current - 1);
    pages.push(current);
    pages.push(current + 1);
    pages.push('...');
    pages.push(total);
  }
  return pages;
}

/**
 * Custom hook encapsulating pagination logic, page boundary guards, and page number calculation.
 */
export function usePagination(options: UsePaginationOptions = {}): UsePaginationReturn {
  const { initialPage = 1, initialLimit = 10, total: initialTotal = 0, onPageChange } = options;

  const [page, setPageState] = useState<number>(initialPage);
  const [limit, setLimitState] = useState<number>(initialLimit);
  const [total, setTotalState] = useState<number>(initialTotal);

  const totalPages = useMemo(() => {
    return Math.max(1, Math.ceil(total / (limit || 10)));
  }, [total, limit]);

  const canPrevPage = page > 1;
  const canNextPage = page < totalPages;

  const handlePageChange = useCallback(
    (targetPage: number) => {
      const clampedPage = Math.max(1, Math.min(targetPage, totalPages));
      if (clampedPage !== page) {
        setPageState(clampedPage);
        onPageChange?.(clampedPage);
      }
    },
    [page, totalPages, onPageChange]
  );

  const goToPrevPage = useCallback(() => {
    if (canPrevPage) {
      handlePageChange(page - 1);
    }
  }, [canPrevPage, page, handlePageChange]);

  const goToNextPage = useCallback(() => {
    if (canNextPage) {
      handlePageChange(page + 1);
    }
  }, [canNextPage, page, handlePageChange]);

  const pageNumbers = useMemo(() => getPageNumbers(page, totalPages), [page, totalPages]);

  return {
    page,
    limit,
    total,
    totalPages,
    setPage: setPageState,
    setLimit: setLimitState,
    setTotal: setTotalState,
    goToNextPage,
    goToPrevPage,
    canNextPage,
    canPrevPage,
    pageNumbers,
    handlePageChange,
  };
}
