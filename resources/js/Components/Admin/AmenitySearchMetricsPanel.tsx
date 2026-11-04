import React, { useEffect, useRef, useState } from 'react';
import { api, AmenitySearchMetrics } from '../../Services/api';

export function AmenitySearchMetricsPanel() {
  const roles: unknown[] = api.getUser()?.roles ?? [];
  const allowed = roles.some((role) => ['ADMIN', 'SUPER_ADMIN', 'SUPER_ADMI'].includes(typeof role === 'string' ? role : String((role as { role_code?: string })?.role_code ?? '')));
  const [metrics, setMetrics] = useState<AmenitySearchMetrics | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const request = useRef<AbortController | null>(null);
  useEffect(() => () => request.current?.abort(), []);
  if (!allowed) return null;

  const load = () => {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    setLoading(true); setError('');
    api.getAmenitySearchMetrics(controller.signal).then((data) => { if (!controller.signal.aborted) setMetrics(data); })
      .catch((reason: Error) => { if (!controller.signal.aborted) setError(reason.message || 'Không tải được số liệu.'); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
  };

  return <details className="amenity-search-metrics" onToggle={(event) => { if (event.currentTarget.open && !metrics && !loading) load(); if (!event.currentTarget.open) { request.current?.abort(); setLoading(false); } }}>
    <summary>Hiệu quả tìm kiếm · 7 ngày</summary>
    {loading && <p role="status">Đang tải số liệu…</p>}
    {error && <p role="alert">{error}</p>}
    {metrics && <>
      <dl>
        <div><dt>Lượt tìm kiếm</dt><dd>{metrics.requests.toLocaleString('vi-VN')}</dd></div>
        <div><dt>Độ trễ p50 / p95</dt><dd>{metrics.p50_ms ?? '—'} / {metrics.p95_ms ?? '—'} ms</dd></div>
        <div><dt>Dùng tìm kiếm dự phòng</dt><dd>{Math.round(metrics.fallback_rate * 100)}%</dd></div>
        <div><dt>Không có kết quả</dt><dd>{Math.round(metrics.zero_result_rate * 100)}%</dd></div>
        <div><dt>Chờ đồng bộ / lỗi</dt><dd>{metrics.dirty_documents} / {metrics.failed_documents}</dd></div>
        <div><dt>Đồng bộ gần nhất</dt><dd>{metrics.last_synced_at ? new Date(metrics.last_synced_at.replace(' ', 'T')).toLocaleString('vi-VN') : 'Chưa có'}</dd></div>
        {metrics.engines.map((item) => <div key={item.engine}><dt>{item.engine}</dt><dd>{item.requests} lượt</dd></div>)}
      </dl>
      <p><small>{metrics.estimated_algolia_calls} lần gọi Algolia do ứng dụng ghi nhận; không thay thế số liệu quota trên Algolia.</small></p>
    </>}
    <button type="button" disabled={loading} onClick={load}>Tải lại số liệu</button>
  </details>;
}
