import React, { useEffect, useRef, useState } from 'react';
import { api, ApiError, AnnouncementAttachment, ResidentAnnouncement } from '../Services/api';
import { announcementReadEvent, useResidentAnnouncements } from '../Hooks/useResidentAnnouncements';
import { TicketDialog } from './TicketDialog';
import { CustomSelect } from './Common/CustomSelect';
import '../../css/scss/announcements.scss';
import '../../css/scss/resident-announcements.scss';

const currentId = () => new URLSearchParams(window.location.search).get('announcement') || '';
const readableError = (error: ApiError) => error.status === 401 ? 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.' : error.status === 404 ? 'Bài viết không còn hiển thị hoặc bạn không có quyền truy cập.' : error.message || 'Không tải được dữ liệu. Vui lòng thử lại.';
const scope = (item: ResidentAnnouncement) => item.target_scope === 'ALL_BLOCKS' ? 'Toàn khu' : [item.block_name, item.target_scope === 'FLOOR' && item.floor_name].filter(Boolean).join(' · ');

export function ResidentAnnouncementPanel() {
  const [query, setQuery] = useState(''); const [search, setSearch] = useState('');
  const [category, setCategory] = useState(''); const [filter, setFilter] = useState(''); const [page, setPage] = useState(1);
  const feed = useResidentAnnouncements(new URLSearchParams({ page: String(page), search, category, filter }).toString());
  const [id, setId] = useState(currentId); const [detail, setDetail] = useState<ResidentAnnouncement | null>(null);
  const [detailError, setDetailError] = useState(''); const [readError, setReadError] = useState(''); const [revision, reload] = useState(0);
  const [busy, setBusy] = useState(false); const [downloading, setDownloading] = useState(''); const [fileError, setFileError] = useState('');
  const activeDetail = useRef<AbortController | null>(null);
  const zone = feed.data?.timezone || 'Asia/Ho_Chi_Minh';
  useEffect(() => { if (query.trim() === search) return; const timer = setTimeout(() => { setSearch(query.trim()); setPage(1); }, 300); return () => clearTimeout(timer); }, [query, search]);
  useEffect(() => { const sync = () => setId(currentId()); window.addEventListener('popstate', sync); return () => window.removeEventListener('popstate', sync); }, []);
  useEffect(() => { if (feed.data && page > feed.data.last_page) setPage(Math.max(1, feed.data.last_page)); }, [feed.data, page]);
  useEffect(() => {
    const controller = new AbortController(); activeDetail.current = controller;
    setDetail(null); setDetailError(''); setReadError(''); setFileError(''); setBusy(false); setDownloading('');
    if (id) void api.getResidentAnnouncement(id, controller.signal).then((item) => { if (!controller.signal.aborted) setDetail(item); }).catch((error: ApiError) => { if (!controller.signal.aborted) setDetailError(readableError(error)); });
    return () => controller.abort();
  }, [id, revision]);
  useEffect(() => {
    if (!detail || detail.id !== id || detail.read_at) return;
    const controller = new AbortController();
    let sent = false;
    const markRead = () => {
      if (sent || document.hidden) return; sent = true;
      void api.readResidentAnnouncement(detail.id, detail.version, controller.signal).then((item) => {
        if (!controller.signal.aborted) { setDetail((current) => current?.id === item.id && current.version === item.version ? { ...current, read_at: item.read_at, acknowledged_at: item.acknowledged_at } : current); window.dispatchEvent(new Event(announcementReadEvent)); }
      }).catch((error: ApiError) => { if (!controller.signal.aborted) setReadError(readableError(error)); });
    };
    markRead(); document.addEventListener('visibilitychange', markRead);
    return () => { controller.abort(); document.removeEventListener('visibilitychange', markRead); };
  }, [detail?.id, detail?.version, id, revision]);
  const navigate = (next: string) => {
    const url = new URL(window.location.href); url.searchParams.set('tab', 'news');
    if (next) url.searchParams.set('announcement', next); else url.searchParams.delete('announcement');
    window.history.pushState({}, '', url); setId(next);
  };
  const acknowledge = async () => {
    if (!detail || busy) return; const controller = activeDetail.current; const item = detail; setBusy(true); setReadError('');
    try { const result = await api.acknowledgeResidentAnnouncement(item.id, item.version, controller?.signal); if (!controller?.signal.aborted) { setDetail((current) => current?.id === item.id ? { ...current, read_at: result.read_at, acknowledged_at: result.acknowledged_at } : current); window.dispatchEvent(new Event(announcementReadEvent)); } }
    catch (error) { if (!controller?.signal.aborted) setReadError(readableError(error as ApiError)); }
    finally { if (!controller?.signal.aborted) setBusy(false); }
  };
  const download = async (file: AnnouncementAttachment) => {
    if (!detail || downloading) return; const controller = activeDetail.current; setDownloading(file.id); setFileError('');
    try { const blob = await api.announcementAttachment(detail.id, file.id, 'resident', controller?.signal); if (controller?.signal.aborted) return; const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = file.file_name; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }
    catch (error) { if (!controller?.signal.aborted) setFileError((error as Error).message); }
    finally { if (!controller?.signal.aborted) setDownloading(''); }
  };
  return <section className="announcement-ui resident-announcements" aria-label="Bảng tin cư dân">
    <header className="announcement-ui__header"><div><p className="announcement-ui__eyebrow">THÔNG TIN TỪ BAN QUẢN LÝ</p><h1>Bảng tin / Thông báo</h1><p>Cập nhật thông tin dành cho nơi bạn đang cư trú.</p></div><button type="button" onClick={feed.retry} disabled={feed.loading}>Tải lại</button></header>
    <div className="announcement-ui__filters"><label>Tìm tiêu đề<input type="search" maxLength={100} value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Nhập tiêu đề thông báo…" /></label><CustomSelect label="Danh mục" value={category} onChange={(value) => { setCategory(value); setPage(1); }} options={[{ value: '', label: 'Tất cả danh mục' }, ...Object.entries(feed.data?.categories || {}).map(([value, label]) => ({ value, label }))]} overlay /><CustomSelect label="Trạng thái đọc" value={filter} onChange={(value) => { setFilter(value); setPage(1); }} options={[{ value: '', label: 'Tất cả' }, { value: 'unread', label: 'Chưa đọc' }, { value: 'acknowledgement', label: 'Cần xác nhận' }]} overlay /></div>
    {feed.loading && <p role="status">Đang tải bảng tin…</p>}
    {feed.error && <div role="alert" className="announcement-error">{readableError(feed.error)} <button type="button" onClick={feed.retry}>Thử lại</button></div>}
    {!feed.loading && !feed.error && feed.data?.data.length === 0 && <p className="announcement-empty">Không có thông báo phù hợp.</p>}
    <div className="announcement-ui__list">{feed.data?.data.map((item) => <article key={item.id} className={`announcement-card ${!item.read_at ? 'resident-announcements__unread' : ''}`}><div><div className="announcement-ui__tags">{!item.read_at && <span>Chưa đọc</span>}{item.is_pinned && <span>Đã ghim</span>}{item.is_urgent && <span className="announcement-urgent">Khẩn cấp</span>}{item.requires_acknowledgement && !item.acknowledged_at && <span>Cần xác nhận</span>}</div><h2>{item.title}</h2><p>{item.summary_excerpt}</p><p className="announcement-meta">{feed.data?.categories[item.category] || item.category} · {scope(item)} · {new Date(item.publish_at).toLocaleString('vi-VN', { timeZone: zone })}</p></div><button type="button" onClick={() => navigate(item.id)} aria-label={`Đọc thông báo: ${item.title}`}>Đọc thông báo</button></article>)}</div>
    {feed.data && feed.data.last_page > 1 && <nav aria-label="Phân trang bảng tin cư dân" className="announcement-ui__pagination"><button type="button" disabled={feed.loading || page <= 1} onClick={() => setPage(page - 1)}>Trang trước</button><span>Trang {page}/{feed.data.last_page} · {feed.data.total} thông báo</span><button type="button" disabled={feed.loading || page >= feed.data.last_page} onClick={() => setPage(page + 1)}>Trang sau</button></nav>}
    <TicketDialog open={!!id} title={detail?.title || 'Chi tiết thông báo'} busy={busy} onClose={() => navigate('')} footer={detail && <div className="announcement-ui announcement-ui__footer"><button type="button" disabled={busy} onClick={() => navigate('')}>Đóng</button>{detail.requires_acknowledgement && !detail.acknowledged_at && <button type="button" className="announcement-primary" disabled={busy || !detail.read_at || !!readError} onClick={() => void acknowledge()}>{busy ? 'Đang xác nhận…' : 'Xác nhận đã đọc'}</button>}</div>}>
      <div className="announcement-ui">{!detail && !detailError && <p role="status">Đang tải nội dung…</p>}{detailError && <p role="alert" className="announcement-error">{detailError} <button type="button" onClick={() => reload((value) => value + 1)}>Thử lại</button></p>}{detail && <><p className="announcement-meta">{scope(detail)} · {new Date(detail.publish_at).toLocaleString('vi-VN', { timeZone: zone })}</p><div className="announcement-content" dangerouslySetInnerHTML={{ __html: detail.content_html || '' }} />{!!detail.attachments?.length && <section aria-label="Tệp đính kèm"><h3>Tệp đính kèm</h3><ul className="resident-announcements__files">{detail.attachments.map((file) => <li key={file.id}><button type="button" disabled={!!downloading} onClick={() => void download(file)}>{downloading === file.id ? 'Đang tải…' : `Tải ${file.file_name}`}</button></li>)}</ul></section>}{fileError && <p role="alert" className="announcement-error">{fileError}</p>}{readError && <p role="alert" className="announcement-error">{readError} <button type="button" onClick={() => reload((value) => value + 1)}>Tải lại nội dung</button></p>}{detail.acknowledged_at ? <p role="status" className="announcement-success">Bạn đã xác nhận đọc lúc {new Date(detail.acknowledged_at).toLocaleString('vi-VN', { timeZone: zone })}.</p> : detail.requires_acknowledgement && <p>Bài viết này cần bạn bấm xác nhận sau khi đọc.</p>}</>}</div>
    </TicketDialog>
  </section>;
}
