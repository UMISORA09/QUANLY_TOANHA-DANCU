import React, { useEffect, useState } from 'react';
import { api } from '../Services/api';

export function TicketImage({ url, name, onPreview }: { url: string; name: string; onPreview: (url: string) => void }) {
  const [src, setSrc] = useState('');
  const [failed, setFailed] = useState(false);
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
  }, [url]);
  return <button type="button" disabled={!src} onClick={() => onPreview(src)} className="rounded-xl overflow-hidden border border-neutral-200 aspect-video bg-neutral-100 cursor-pointer disabled:cursor-default" aria-label={`Xem ảnh ${name}`}>
    {src ? <img src={src} alt={name} className="w-full h-full object-cover" /> : <span className="text-xs text-neutral-500">{failed ? 'Không tải được ảnh' : 'Đang tải ảnh…'}</span>}
  </button>;
}
