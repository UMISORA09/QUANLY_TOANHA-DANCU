import React, { useEffect, useState } from 'react';
import { api } from '../../Services/api';

export function AmenityGalleryImage({ url, name, className }: { url: string; name: string; className?: string }) {
  const internal = url.startsWith('/api/v1/admin/amenity-images/');
  const [src, setSrc] = useState('');
  const [failed, setFailed] = useState(false);
  const [attempt, retry] = useState(0);
  useEffect(() => {
    setFailed(false); setSrc('');
    if (!internal) return;
    const controller = new AbortController(); let objectUrl = '';
    api.amenityGalleryImage(url, controller.signal).then(blob => {
      if (controller.signal.aborted) return;
      objectUrl = URL.createObjectURL(blob); setSrc(objectUrl);
    }).catch(() => { if (!controller.signal.aborted) setFailed(true); });
    return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [url, internal, attempt]);
  if (failed) return <div className="grid h-full place-content-center gap-1 p-2 text-center text-xs text-rose-700">Không tải được ảnh<button type="button" aria-label={'Thử lại ảnh ' + name} onClick={() => retry(value => value + 1)} className="underline">Thử lại</button></div>;
  if (internal && !src) return <span role="status" className="grid h-full place-content-center text-xs text-slate-500">Đang tải ảnh…</span>;
  return <img src={internal ? src : url} alt={name} className={className} onError={() => setFailed(true)} />;
}
