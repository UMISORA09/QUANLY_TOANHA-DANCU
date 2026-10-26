import '../../css/scss/ticket-discussion.scss';
import React, { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { api, TicketComment, TicketCommentPage, TicketCommentPayload, TicketHistoryItem } from '../Services/api';

const button = 'ticket-discussion__button';
const statuses: Record<string, string> = { NEW: 'Mới gửi', RECEIVED: 'Đã tiếp nhận', IN_PROGRESS: 'Đang xử lý', RESOLVED: 'Chờ nghiệm thu', CLOSED: 'Đã hoàn tất', CANCELLED: 'Đã hủy' };
const actions: Record<string, string> = { CREATED: 'Tạo yêu cầu', UPDATED: 'Cập nhật yêu cầu', ASSIGNED: 'Phân công kỹ thuật', STATUS_CHANGED: 'Chuyển trạng thái', ATTACHMENT_ADDED: 'Thêm ảnh', ATTACHMENT_REMOVED: 'Bỏ ảnh', ARCHIVED: 'Lưu trữ yêu cầu' };
const workflowNotes: Record<string, string> = { receive: 'Tiếp nhận yêu cầu', start: 'Bắt đầu xử lý', confirm: 'Xác nhận nghiệm thu', cancel: 'Hủy yêu cầu', reopen: 'Phản hồi chưa đạt', resolve: 'Báo đã khắc phục' };
type DiscussionError = Error & { status?: number; errors?: Record<string, string[]> };
function mergeItems<T extends { id: string; created_at: string }>(existing: T[], incoming: T[]): T[] {
  return [...new Map([...existing, ...incoming].map((item) => [item.id, item])).values()].sort((a, b) => a.created_at.localeCompare(b.created_at) || a.id.localeCompare(b.id));
}

export function TicketDiscussion({ ticketId, active = true }: { ticketId: string; active?: boolean }) {
  const fieldId = useId();
  const [tab, setTab] = useState<'comments' | 'history'>('comments');
  const [comments, setComments] = useState<TicketComment[]>([]);
  const [history, setHistory] = useState<TicketHistoryItem[]>([]);
  const [meta, setMeta] = useState<TicketCommentPage['meta'] | null>(null);
  const [draft, setDraft] = useState('');
  const [internal, setInternal] = useState(false);
  const [sending, setSending] = useState(false);
  const [reading, setReading] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [sendError, setSendError] = useState('');
  const [fatal, setFatal] = useState(false);
  const [expired, setExpired] = useState(false);
  const [newCount, setNewCount] = useState(0);
  const [commentsCursor, setCommentsCursor] = useState<string | null>(null);
  const [historyCursor, setHistoryCursor] = useState<string | null>(null);
  const rows = useRef<TicketComment[]>([]);
  const historyRows = useRef<TicketHistoryItem[]>([]);
  const latest = useRef<string | undefined>(undefined);
  const initialized = useRef(false);
  const historyCount = useRef<number | null>(null);
  const commentsNext = useRef<string | null>(null);
  const historyNext = useRef<string | null>(null);
  const metaRef = useRef<TicketCommentPage['meta'] | null>(null);
  const stopped = useRef(false);
  const list = useRef<HTMLDivElement>(null);
  const nearBottom = useRef(true);
  const scrollPlan = useRef<{ bottom: boolean; id?: string; top?: number } | null>(null);
  const pending = useRef<TicketCommentPayload | null>(null);
  const sendController = useRef<AbortController | null>(null);
  const controls = useRef<{ refresh: () => void; older: (kind: 'comments' | 'history') => void } | null>(null);

  const rememberScroll = () => {
    const container = list.current;
    const anchor = container && Array.from(container.querySelectorAll<HTMLElement>('[data-comment-id]')).find((item) => item.getBoundingClientRect().bottom > container.getBoundingClientRect().top);
    scrollPlan.current = { bottom: nearBottom.current, id: anchor?.dataset.commentId, top: anchor?.getBoundingClientRect().top };
  };
  const receiveComments = (incoming: TicketComment[], older = false) => {
    rememberScroll();
    if (older && scrollPlan.current) scrollPlan.current.bottom = false;
    const known = new Set(rows.current.map((row) => row.id));
    const added = incoming.filter((row) => !known.has(row.id)).length;
    if (initialized.current && !older && !nearBottom.current && added) setNewCount((count) => count + added);
    rows.current = mergeItems(rows.current, incoming);
    setComments(rows.current);
    latest.current = rows.current.at(-1)?.created_at;
  };
  const receiveHistory = (incoming: TicketHistoryItem[]) => {
    historyRows.current = mergeItems(historyRows.current, incoming);
    setHistory(historyRows.current);
  };
  const deny = (failure: DiscussionError) => {
    stopped.current = true;
    setFatal(true); setExpired(failure.status === 401); setMeta(null);
    rows.current = []; historyRows.current = []; latest.current = undefined;
    setComments([]); setHistory([]); setNewCount(0);
    setError(failure.status === 401 ? 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.' : 'Bạn không còn quyền xem yêu cầu này hoặc yêu cầu đã được lưu trữ.');
  };

  useLayoutEffect(() => {
    const container = list.current;
    const plan = scrollPlan.current;
    if (!container || !plan) return;
    if (plan.bottom) container.scrollTop = container.scrollHeight;
    else if (plan.id && plan.top !== undefined) {
      const anchor = Array.from(container.querySelectorAll<HTMLElement>('[data-comment-id]')).find((item) => item.dataset.commentId === plan.id);
      if (anchor) container.scrollTop += anchor.getBoundingClientRect().top - plan.top;
    }
    scrollPlan.current = null;
  }, [comments, tab]);

  useEffect(() => {
    if (!active || stopped.current) return;
    let alive = true, running = false, refreshPending = false, failures = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | null = null;
    const load = async (older?: 'comments' | 'history') => {
      if (!alive || stopped.current || document.hidden) return;
      if (running) { if (!older) refreshPending = true; return; }
      running = true; clearTimeout(timer); setReading(true);
      controller = new AbortController();
      const signal = controller.signal;
      try {
        if (older === 'history' && historyNext.current) {
          const page = await api.getTicketDiscussionHistory(ticketId, historyNext.current, signal);
          if (!alive || stopped.current) return;
          receiveHistory(page.items); historyNext.current = page.next_cursor; setHistoryCursor(page.next_cursor);
        } else {
          const since = older ? undefined : latest.current;
          const page = await api.getTicketComments(ticketId, { ...(older && commentsNext.current ? { cursor: commentsNext.current } : {}), ...(since ? { since } : {}) }, signal);
          const incoming = [...page.items];
          // Inclusive timestamps and all delta pages also catch late UUIDs within the same MySQL second.
          if (!older && initialized.current) {
            let cursor = page.next_cursor;
            while (cursor && alive && !signal.aborted) {
              const next = await api.getTicketComments(ticketId, { cursor, ...(since ? { since } : {}) }, signal);
              incoming.push(...next.items); cursor = next.next_cursor;
            }
          }
          if (!alive || stopped.current) return;
          if (!page.meta.can_view_internal) {
            rows.current = rows.current.filter((row) => !row.is_internal_staff_note);
            if (metaRef.current?.can_view_internal) setNewCount(0);
          }
          receiveComments(incoming, !!older);
          metaRef.current = page.meta; setMeta(page.meta);
          if (older || !initialized.current) { commentsNext.current = page.next_cursor; setCommentsCursor(page.next_cursor); }
          initialized.current = true;
          if (historyCount.current !== page.meta.history_count) {
            const historyPage = await api.getTicketDiscussionHistory(ticketId, undefined, signal);
            const historyItems = [...historyPage.items];
            const known = new Set(historyRows.current.map((row) => row.id));
            const expected = historyCount.current === null ? 0 : Math.max(0, page.meta.history_count - historyCount.current);
            let cursor = historyPage.next_cursor;
            while (cursor && historyItems.filter((item) => !known.has(item.id)).length < expected && alive && !signal.aborted) {
              const next = await api.getTicketDiscussionHistory(ticketId, cursor, signal);
              historyItems.push(...next.items); cursor = next.next_cursor;
            }
            if (!alive || stopped.current) return;
            receiveHistory(historyItems);
            if (historyCount.current === null) { historyNext.current = historyPage.next_cursor; setHistoryCursor(historyPage.next_cursor); }
            historyCount.current = page.meta.history_count;
          }
        }
        if (alive) { failures = 0; setError(''); }
      } catch (failure) {
        if (!alive || signal.aborted) return;
        const err = failure as DiscussionError;
        if ([401, 403, 404].includes(err.status || 0)) deny(err);
        else { failures++; setError('Chưa tải được trao đổi mới. Nội dung đã có vẫn được giữ lại.'); }
      } finally {
        running = false;
        if (alive) {
          setReading(false); setLoading(false);
          if (!document.hidden && !stopped.current) {
            const delay = refreshPending ? 0 : [3000, 6000, 12000, 30000][Math.min(Math.max(failures - 1, 0), 3)];
            refreshPending = false; timer = setTimeout(() => { void load(); }, delay);
          }
        }
      }
    };
    const refresh = () => { failures = 0; clearTimeout(timer); void load(); };
    const visible = () => { if (document.hidden) { clearTimeout(timer); controller?.abort(); } else refresh(); };
    controls.current = { refresh, older: (kind) => { void load(kind); } };
    document.addEventListener('visibilitychange', visible);
    window.addEventListener('focus', refresh);
    void load();
    return () => { alive = false; clearTimeout(timer); controller?.abort(); controls.current = null; document.removeEventListener('visibilitychange', visible); window.removeEventListener('focus', refresh); };
  }, [ticketId, active]);

  useEffect(() => () => { sendController.current?.abort(); }, [ticketId]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (sendController.current || !meta?.can_comment || stopped.current || !draft.trim() || (internal && !meta.can_internal_note)) return;
    const text = draft.trim();
    if (Array.from(text).length > 2000) { setSendError('Nội dung không được vượt quá 2.000 ký tự.'); return; }
    if (!pending.current || pending.current.comment_text !== text || pending.current.is_internal_staff_note !== internal) pending.current = { id: crypto.randomUUID(), comment_text: text, is_internal_staff_note: internal };
    const controller = new AbortController(); sendController.current = controller;
    setSending(true); setSendError('');
    try {
      const result = await api.postTicketComment(ticketId, pending.current, controller.signal);
      if (controller.signal.aborted || stopped.current) return;
      nearBottom.current = true; setNewCount(0); receiveComments([result.data]);
      setDraft(''); setInternal(false); pending.current = null;
      controls.current?.refresh();
    } catch (failure) {
      if (controller.signal.aborted) return;
      const err = failure as DiscussionError;
      if ([401, 403, 404].includes(err.status || 0)) deny(err);
      else { setSendError(err.errors?.comment_text?.[0] || err.message || 'Chưa gửi được. Hãy thử lại với nội dung đang nhập.'); if (err.status === 409) controls.current?.refresh(); }
    } finally { if (!controller.signal.aborted) { setSending(false); sendController.current = null; } }
  };

  return <section className="ticket-discussion" aria-label="Trao đổi và lịch sử xử lý Ticket">
    <div className="flex flex-wrap gap-2" role="tablist" aria-label="Nội dung yêu cầu" onKeyDown={(event) => {
      if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        event.preventDefault(); const next = event.key === 'Home' ? 'comments' : event.key === 'End' ? 'history' : tab === 'comments' ? 'history' : 'comments';
        setTab(next); document.getElementById(`${fieldId}-${next}-tab`)?.focus();
      }
    }}>
      {(['comments', 'history'] as const).map((key) => <button key={key} type="button" role="tab" id={`${fieldId}-${key}-tab`} aria-controls={`${fieldId}-${key}-panel`} tabIndex={tab === key ? 0 : -1} aria-selected={tab === key} onClick={() => setTab(key)} className={button}>{key === 'comments' ? 'Trao đổi' : 'Lịch sử xử lý'}</button>)}
    </div>
    {loading && <p role="status" className="text-sm text-neutral-500">Đang tải trao đổi…</p>}
    {error && <p role="alert" className="text-sm text-rose-700">{error} {!fatal && <button type="button" className="min-h-11 cursor-pointer px-2 underline" disabled={reading} onClick={() => controls.current?.refresh()}>Thử lại</button>} {expired && <a href="/login" className="underline">Đăng nhập lại</a>}</p>}
    <div role="tabpanel" id={`${fieldId}-comments-panel`} aria-labelledby={`${fieldId}-comments-tab`} hidden={tab !== 'comments'} className="space-y-3">
      {commentsCursor && !fatal && <button type="button" className={button} disabled={reading} onClick={() => controls.current?.older('comments')}>Tải nội dung trước</button>}
      <div ref={list} role="log" aria-live="polite" aria-relevant="additions" tabIndex={0} aria-label="Danh sách bình luận" className="ticket-discussion__comments" onScroll={() => { const box = list.current; if (box) { nearBottom.current = box.scrollHeight - box.scrollTop - box.clientHeight < 48; if (nearBottom.current) setNewCount(0); } }}>
        {!loading && !comments.length && !fatal && <p className="rounded-xl bg-neutral-50 p-4 text-sm text-neutral-500">Chưa có trao đổi. Bạn có thể gửi thông tin bổ sung tại đây.</p>}
        {comments.map((comment) => <article key={comment.id} data-comment-id={comment.id} className={`ticket-discussion__comment${comment.is_internal_staff_note ? ' ticket-discussion__comment--internal' : ''}`}>
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"><strong className="text-neutral-900">{comment.sender?.full_name || 'Tài khoản không còn hoạt động'}</strong><span className="text-neutral-500">{comment.sender?.role}</span>{comment.is_internal_staff_note && <span className="rounded-md bg-amber-100 px-2 py-0.5 font-medium text-amber-900">Ghi chú nội bộ</span>}<time className="text-neutral-500" dateTime={comment.created_at}>{new Date(comment.created_at).toLocaleString('vi-VN')}</time></div>
          <p className="mt-2 whitespace-pre-wrap break-words text-neutral-800 [overflow-wrap:anywhere]">{comment.comment_text}</p>
        </article>)}
      </div>
      {!!newCount && !fatal && <button type="button" className={`${button} ticket-discussion__new`} onClick={() => { if (list.current) list.current.scrollTop = list.current.scrollHeight; nearBottom.current = true; setNewCount(0); }}>Có bình luận mới ({newCount})</button>}
      <p role="status" aria-live="polite" className="text-xs text-neutral-500">{fatal ? '' : meta?.can_comment === false ? 'Yêu cầu đã kết thúc hoặc bạn không còn quyền gửi. Bạn vẫn có thể xem nội dung được phép.' : 'Tự cập nhật khi đang mở yêu cầu.'}</p>
      <form onSubmit={submit} className="space-y-3 border-t border-neutral-200 pt-3">
        <label htmlFor={fieldId} className="block text-sm font-medium text-neutral-800">Nội dung trao đổi</label>
        <textarea id={fieldId} value={draft} onChange={(event) => setDraft(event.target.value)} rows={3} maxLength={2000} disabled={sending || fatal || !meta?.can_comment} aria-invalid={!!sendError} aria-describedby={`${fieldId}-help ${sendError ? `${fieldId}-error` : ''}`} className="ticket-discussion__input" placeholder="Bổ sung thông tin hoặc trao đổi về yêu cầu…" />
        <div className="flex flex-wrap items-center justify-between gap-2"><span id={`${fieldId}-help`} className="text-xs text-neutral-500">{Array.from(draft).length}/2.000 ký tự · Văn bản thuần</span>{(meta?.can_internal_note || internal) && !fatal && <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-neutral-700"><input type="checkbox" checked={internal} disabled={sending} onChange={(event) => setInternal(event.target.checked)} className="h-4 w-4 accent-neutral-950" />Ghi chú nội bộ</label>}</div>
        {internal && !meta?.can_internal_note && !fatal && <p role="alert" className="text-sm text-amber-800">Bạn không còn quyền gửi ghi chú nội bộ. Chỉ bỏ chọn nếu muốn chuyển nội dung sang trao đổi chung.</p>}
        {sendError && <p id={`${fieldId}-error`} role="alert" className="text-sm text-rose-700">{sendError}</p>}
        <button type="submit" disabled={sending || fatal || !meta?.can_comment || !draft.trim() || (internal && !meta.can_internal_note)} className={`${button} ticket-discussion__submit`}>{sending ? 'Đang gửi…' : 'Gửi bình luận'}</button>
      </form>
    </div>
    <div role="tabpanel" id={`${fieldId}-history-panel`} aria-labelledby={`${fieldId}-history-tab`} hidden={tab !== 'history'} className="space-y-3">
      {historyCursor && !fatal && <button type="button" className={button} disabled={reading} onClick={() => controls.current?.older('history')}>Tải nội dung trước</button>}
      {!loading && !history.length && !fatal && <p className="text-sm text-neutral-500">Chưa có lịch sử thay đổi.</p>}
      <ol className="ticket-discussion__history" aria-label="Lịch sử xử lý">
        {history.map((entry) => <li key={entry.id} className="rounded-xl border border-neutral-200 bg-neutral-50 p-3 text-sm">
          <div className="flex flex-wrap gap-2 text-xs text-neutral-500"><strong className="text-neutral-800">{entry.performer?.full_name || 'Hệ thống'}</strong><span>{entry.performer?.role}</span><time dateTime={entry.created_at}>{new Date(entry.created_at).toLocaleString('vi-VN')}</time></div>
          <p className="mt-1 font-medium text-neutral-900">{actions[entry.action_type] || 'Cập nhật xử lý'}{entry.new_status && entry.old_status !== entry.new_status && ` · ${statuses[entry.old_status || ''] || 'Khởi tạo'} → ${statuses[entry.new_status] || 'Cập nhật trạng thái'}`}</p>
          {entry.notes && <p className="mt-1 whitespace-pre-wrap break-words text-neutral-600 [overflow-wrap:anywhere]">{workflowNotes[entry.notes] || entry.notes}</p>}
        </li>)}
      </ol>
    </div>
  </section>;
}
