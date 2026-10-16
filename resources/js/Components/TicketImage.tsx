import React, { useEffect, useState } from 'react';
import { api } from '../Services/api';

export function TicketImage({ url, name, onPreview }: { url: string; name: string; onPreview: (url: string) => void }) {
  const [src, setSrc] = useState('');
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    let objectUrl = '';
    setSrc('');
    setFailed(false);
    api.ticketImage(url, controller.signal).then((blob) => {
      if (controller.signal.aborted) return;
      objectUrl = URL.createObjectURL(blob);
      setSrc(objectUrl);
    }).catch(() => { if (!controller.signal.aborted) setFailed(true); });
    return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [url, attempt]);
  if (failed) return <div className="grid aspect-video place-content-center gap-2 rounded-xl border border-neutral-200 bg-neutral-50 p-3 text-center"><p role="status" className="text-xs text-neutral-600">Không tải được ảnh</p><button type="button" onClick={() => setAttempt((value) => value + 1)} aria-label={`Thử tải lại ảnh ${name}`} className="min-h-11 cursor-pointer rounded-lg border border-neutral-200 bg-white px-3 text-sm font-medium hover:bg-neutral-100">Thử tải lại</button></div>;
  return <button type="button" disabled={!src} onClick={() => onPreview(src)} className="rounded-xl overflow-hidden border border-neutral-200 aspect-video bg-neutral-100 cursor-pointer focus-visible:outline-2 focus-visible:outline-sky-600 disabled:cursor-default" aria-label={`Xem ảnh ${name}`}>
    {src ? <img src={src} alt={name} className="w-full h-full object-cover" /> : <span className="text-xs text-neutral-500">Đang tải ảnh…</span>}
  </button>;
}
