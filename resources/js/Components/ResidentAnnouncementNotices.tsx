import React from 'react';
import { useResidentAnnouncements } from '../Hooks/useResidentAnnouncements';
import '../../css/scss/resident-announcements.scss';

export function ResidentAnnouncementNotices({ feed, onOpen }: { feed: ReturnType<typeof useResidentAnnouncements>; onOpen: (id: string) => void }) {
  return <section className="resident-announcement-notices" aria-label="Thông báo từ ban quản lý"><h3>Bảng tin BQL · {feed.data?.unread_count || 0} chưa đọc</h3>
    {feed.loading && <p role="status">Đang tải thông báo BQL…</p>}
    {feed.error && <p role="alert">{feed.error.status === 401 ? 'Phiên đăng nhập đã hết hạn.' : feed.error.message || 'Không tải được thông báo BQL.'} <button type="button" onClick={feed.retry}>Thử lại thông báo BQL</button></p>}
    {!feed.loading && !feed.error && !feed.data?.data.length && <p>Bạn đã đọc hết thông báo hiện có.</p>}
    <ul>{feed.data?.data.map((item) => <li key={item.id}><button type="button" onClick={() => onOpen(item.id)}><strong>{item.is_urgent ? 'Khẩn cấp · ' : ''}{item.title}</strong><time dateTime={item.publish_at}>{new Date(item.publish_at).toLocaleString('vi-VN', { timeZone: feed.data?.timezone || 'Asia/Ho_Chi_Minh' })}</time></button></li>)}</ul>
    {!!feed.data && feed.data.total > 15 && <p>Còn {feed.data.total - 15} thông báo chưa đọc. Xem thêm tại Bảng tin / Thông báo.</p>}
  </section>;
}
