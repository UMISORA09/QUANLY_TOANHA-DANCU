import React from 'react';
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react';

export const HistoryPagination: React.FC<{
  page: number;
  total: number;
  onPageChange: (page: number) => void;
  label: string;
  itemLabel: string;
  pageSize?: number;
}> = ({ page, total, onPageChange, label, itemLabel, pageSize = 15 }) => {
  const pages = Math.max(1, Math.ceil(total / pageSize));
  const current = Math.min(Math.max(1, page), pages);

  if (!total) return null;

  const getPageNumbers = (curr: number, max: number): (number | string)[] => {
    if (max <= 7) {
      return Array.from({ length: max }, (_, i) => i + 1);
    }
    if (curr <= 4) {
      return [1, 2, 3, 4, 5, '...', max];
    }
    if (curr >= max - 3) {
      return [1, '...', max - 4, max - 3, max - 2, max - 1, max];
    }
    return [1, '...', curr - 1, curr, curr + 1, '...', max];
  };

  const pageNumbers = getPageNumbers(current, pages);
  const startItem = (current - 1) * pageSize + 1;
  const endItem = Math.min(current * pageSize, total);

  return (
    <nav
      aria-label={label}
      className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 bg-slate-50/90 border border-slate-200/90 rounded-2xl text-xs text-slate-500 shadow-2xs"
    >
      <div role="status" className="font-medium">
        Hiển thị <span className="font-bold text-slate-700">{startItem}–{endItem}</span> / <span className="font-bold text-slate-700">{total}</span> {itemLabel} · Trang <span className="font-bold text-slate-700">{current}</span>/{pages}
      </div>

      <input type="range" aria-label={`Chuyển trang ${itemLabel}`} min={1} max={pages} value={current} disabled={pages === 1} onChange={event => onPageChange(Number(event.target.value))} className="w-full sm:w-32 accent-sky-600" />

      <div className="flex items-center gap-1.5 flex-wrap">
        {/* Nút Trang đầu */}
        <button
          type="button"
          disabled={current <= 1}
          onClick={() => onPageChange(1)}
          className="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed text-slate-700 transition-all shadow-2xs cursor-pointer"
          title="Trang đầu"
        >
          <ChevronsLeft className="w-3.5 h-3.5" />
        </button>

        {/* Nút Trang trước */}
        <button
          type="button"
          disabled={current <= 1}
          onClick={() => onPageChange(current - 1)}
          className="px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1 font-semibold text-slate-700 transition-all shadow-2xs cursor-pointer"
          title="Trang trước"
        >
          <ChevronLeft className="w-3.5 h-3.5" />
          <span className="hidden sm:inline">Trước</span>
        </button>

        {/* Danh sách các nút số trang */}
        <div className="flex items-center gap-1">
          {pageNumbers.map((pNum, idx) => {
            if (pNum === '...') {
              return (
                <span key={`ellipsis-${idx}`} className="px-1.5 py-1 text-slate-400 select-none">
                  ...
                </span>
              );
            }
            const isCurrent = pNum === current;
            const numVal = Number(pNum);
            return (
              <button
                key={`page-${pNum}`}
                type="button"
                onClick={() => onPageChange(numVal)}
                className={`min-w-[28px] h-[28px] px-2 rounded-lg text-xs font-semibold transition-all cursor-pointer ${
                  isCurrent
                    ? 'bg-slate-900 text-white shadow-xs'
                    : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 shadow-2xs'
                }`}
              >
                {pNum}
              </button>
            );
          })}
        </div>

        {/* Nút Trang sau */}
        <button
          type="button"
          disabled={current >= pages}
          onClick={() => onPageChange(current + 1)}
          className="px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed flex items-center gap-1 font-semibold text-slate-700 transition-all shadow-2xs cursor-pointer"
          title="Trang sau"
        >
          <span className="hidden sm:inline">Sau</span>
          <ChevronRight className="w-3.5 h-3.5" />
        </button>

        {/* Nút Trang cuối */}
        <button
          type="button"
          disabled={current >= pages}
          onClick={() => onPageChange(pages)}
          className="p-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-100 disabled:opacity-30 disabled:cursor-not-allowed text-slate-700 transition-all shadow-2xs cursor-pointer"
          title="Trang cuối"
        >
          <ChevronsRight className="w-3.5 h-3.5" />
        </button>
      </div>
    </nav>
  );
};
