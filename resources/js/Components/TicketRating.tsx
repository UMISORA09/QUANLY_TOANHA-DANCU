import React, { useEffect, useId, useRef, useState } from 'react';
import { Star } from 'lucide-react';
import api, { type TicketRatingResponse } from '@/Services/api';
import '../../css/scss/ticket-rating.scss';

export function TicketRating({ ticketId, version }: { ticketId: string; version: number }) {
  const reviewId = useId();
  const readGeneration = useRef(0);
  const [result, setResult] = useState<TicketRatingResponse | null>(null);
  const [stars, setStars] = useState(0);
  const [comment, setComment] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    const generation = ++readGeneration.current;
    setError('');
    api.getTicketRating(ticketId, controller.signal).then((data) => {
      if (!controller.signal.aborted && generation === readGeneration.current) setResult(data);
    }).catch((err: Error) => { if (!controller.signal.aborted && generation === readGeneration.current) setError(err.message); });
    return () => controller.abort();
  }, [ticketId, version, retry]);

  return <section className="ticket-rating" aria-label="Đánh giá sau xử lý sự cố">
    <h3>Đánh giá sau xử lý sự cố</h3>
    {error && <div role="alert"><p>{error}</p><button type="button" disabled={busy} onClick={() => setRetry(value => value + 1)}>Tải lại đánh giá</button></div>}
    {!result && !error && <p role="status">Đang tải đánh giá…</p>}
    {result?.data ? <div role="status">
      <p><strong>{result.data.rating_stars}/5 sao</strong> · Đã ghi nhận đánh giá</p>
      {result.data.review_comment && <p className="ticket-rating__comment">{result.data.review_comment}</p>}
    </div> : result?.can_rate ? <form onSubmit={async (event) => {
      event.preventDefault();
      if (busy) return;
      if (!stars) { setError('Vui lòng chọn từ 1 đến 5 sao.'); return; }
      readGeneration.current++;
      setBusy(true); setError('');
      const previousResult = result;
      // Optimistic update: instantly render the completed rating state (<20ms perceived latency)
      setResult({
        ...result,
        data: {
          id: 'optimistic-pending',
          ticket_id: ticketId,
          rating_stars: stars,
          review_comment: comment.trim() || null,
          created_at: new Date().toISOString(),
        },
        can_rate: false,
      });
      try {
        const serverData = await api.rateResidentTicket(ticketId, { version: result.ticket_version, rating_stars: stars, review_comment: comment });
        setResult(serverData);
      } catch (err) {
        // Rollback state if server returns conflict or error
        setResult(previousResult);
        setError(err instanceof Error ? err.message : 'Chưa gửi được đánh giá. Vui lòng thử lại.');
      } finally { readGeneration.current++; setBusy(false); }
    }}>
      <p>Yêu cầu đã được nghiệm thu. Bạn hài lòng thế nào với việc xử lý sự cố?</p>
      <fieldset disabled={busy}>
        <legend>Mức độ hài lòng (bắt buộc)</legend>
        <div className="ticket-rating__stars">{[1, 2, 3, 4, 5].map(value => <label key={value}>
          <input type="radio" name={`rating-${ticketId}`} value={value} checked={stars === value} onChange={() => setStars(value)} />
          <span><Star aria-hidden="true" fill={stars >= value ? 'currentColor' : 'none'} /><span className="sr-only">{value} sao</span></span>
        </label>)}</div>
      </fieldset>
      <div className="ticket-rating__review">
        <label htmlFor={reviewId}>Nhận xét (không bắt buộc)</label>
        <textarea id={reviewId} rows={3} maxLength={1000} disabled={busy} value={comment} onChange={event => setComment(event.target.value)} />
      </div>
      <p className="ticket-rating__hint">Mỗi yêu cầu được đánh giá một lần. Nhận xét tối đa 1.000 ký tự.</p>
      <button type="submit" disabled={busy}>{busy ? 'Đang gửi đánh giá…' : 'Gửi đánh giá'}</button>
    </form> : result && <p>Chỉ người báo sự cố còn quyền cư trú được gửi đánh giá sau nghiệm thu.</p>}
  </section>;
}
