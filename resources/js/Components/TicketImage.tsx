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
  if (failed) {
    return (
      <div className="grid aspect-video place-content-center gap-2 rounded-2xl border border-rose-200/80 bg-rose-50/50 p-3 text-center shadow-2xs">
        <p role="status" className="text-xs font-semibold text-rose-700">Không tải được ảnh</p>
        <button
          type="button"
          onClick={() => setAttempt((value) => value + 1)}
          aria-label={`Thử tải lại ảnh ${name}`}
          className="min-h-11 cursor-pointer rounded-xl border border-slate-200 bg-white px-3.5 text-xs font-bold text-slate-700 shadow-2xs hover:bg-slate-50 hover:text-slate-900 transition-all"
        >
          Thử tải lại
        </button>
      </div>
    );
  }
  return (
    <button
      type="button"
      disabled={!src}
      onClick={() => onPreview(src)}
      className="group relative rounded-2xl overflow-hidden border border-slate-200/90 aspect-video bg-slate-100 shadow-2xs hover:shadow-xs hover:border-blue-300 transition-all cursor-pointer focus-visible:outline-2 focus-visible:outline-blue-600 disabled:cursor-default"
      aria-label={`Xem ảnh ${name}`}
    >
      {src ? (
        <>
          <img src={src} alt={name} onError={() => setFailed(true)} className="w-full h-full object-cover transition-transform group-hover:scale-105 duration-200" />
          <div className="absolute inset-0 bg-slate-950/20 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white font-medium text-xs">
            <span className="bg-slate-900/70 backdrop-blur-xs px-2.5 py-1 rounded-full text-[11px] shadow">Phóng to</span>
          </div>
        </>
      ) : (
        <span className="text-xs font-medium text-slate-500">Đang tải ảnh…</span>
      )}
    </button>
  );
}
