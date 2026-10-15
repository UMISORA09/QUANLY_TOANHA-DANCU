import React, { useEffect, useId, useRef } from 'react';

export function TicketDialog({ open, title, busy = false, onClose, children }: { open: boolean; title: string; busy?: boolean; onClose: () => void; children: React.ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
    if (!open) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = previous; };
  }, [open]);
  return <dialog ref={ref} aria-labelledby={titleId} onCancel={(event) => { event.preventDefault(); if (!busy) onClose(); }} onClick={(event) => { if (event.target === event.currentTarget && !busy) onClose(); }} className="m-auto w-[calc(100%-2rem)] max-w-2xl max-h-[90dvh] overflow-visible rounded-2xl border border-neutral-200 bg-white p-0 text-neutral-900 shadow-2xl backdrop:bg-black/40">
    <div className="flex max-h-[90dvh] flex-col rounded-2xl bg-white">
      <header className="flex shrink-0 items-center justify-between border-b p-5"><h2 id={titleId} className="text-lg font-semibold">{title}</h2><button type="button" disabled={busy} onClick={onClose} aria-label="Đóng hộp thoại" className="cursor-pointer rounded-lg px-3 py-1 hover:bg-neutral-100">×</button></header>
      <div className="overflow-y-auto p-5">{open && children}</div>
    </div>
  </dialog>;
}
