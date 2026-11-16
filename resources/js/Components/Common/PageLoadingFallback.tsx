import React from 'react';

export const PageLoadingFallback: React.FC<{ message?: string }> = () => {
  return <p role="status" aria-live="polite" className="p-6 text-sm text-slate-600">Đang tải trang…</p>;
};

export default PageLoadingFallback;
