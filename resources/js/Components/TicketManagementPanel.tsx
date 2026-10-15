import React, { useCallback, useEffect, useState } from 'react';
import { api, ManagedTicket, TicketOptions } from '../Services/api';
import { CustomSelect } from './Common/CustomSelect';
import { TicketDialog } from './TicketDialog';
import { TicketImage } from './TicketImage';

export const ticketStatuses = { NEW: 'Mới gửi', RECEIVED: 'Đã tiếp nhận', IN_PROGRESS: 'Đang xử lý', RESOLVED: 'Chờ nghiệm thu', CLOSED: 'Đã đóng', CANCELLED: 'Đã hủy' };
export const ticketPriorities = { LOW: 'Thấp', MEDIUM: 'Bình thường', HIGH: 'Cao', URGENT: 'Khẩn cấp' };
const button = 'rounded-xl border px-4 py-2 text-sm font-medium cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed';
const input = 'w-full rounded-xl border border-neutral-200 px-3 py-2 text-sm';
const blank = { title: '', description: '', location: '', category_id: '', priority: 'MEDIUM', preferred_service_time: '', block_id: '', apartment_id: '', reporter_user_id: '', version: 1, idempotency_key: '' };

export function TicketManagementPanel() {
  const [options, setOptions] = useState<TicketOptions | null>(null);
  const [rows, setRows] = useState<ManagedTicket[]>([]);
  const [selected, setSelected] = useState<ManagedTicket | null>(null);
  const [query, setQuery] = useState('');
  const [keyword, setKeyword] = useState('');
  const [filters, setFilters] = useState({ status: '', priority: '', category_id: '', block_id: '' });
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [mode, setMode] = useState<'create' | 'edit' | 'archive' | null>(null);
  const [form, setForm] = useState(blank);
  const [reason, setReason] = useState('');
  const [preview, setPreview] = useState('');
  const reload = () => setRevision((value) => value + 1);
  useEffect(() => { const timer = setTimeout(() => { setKeyword(query); setPage(1); }, 350); return () => clearTimeout(timer); }, [query]);
  useEffect(() => { let active = true; api.getTicketOptions().then((data) => { if (active) setOptions(data); }).catch((err) => { if (active) setError(err.message); }); return () => { active = false; }; }, [revision]);
  useEffect(() => {
    const controller = new AbortController();
    const params = new URLSearchParams({ page: String(page), search: keyword });
    Object.entries(filters).forEach(([key, value]) => { if (value) params.set(key, value); });
    setLoading(true); setError('');
    api.getManagedTickets(params, controller.signal).then((data) => { if (!controller.signal.aborted) { setRows(data.data); setPages(data.last_page); setTotal(data.total); } })
      .catch((err) => { if (!controller.signal.aborted) setError(err.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [filters, keyword, page, revision]);
  const open = useCallback(async (id: string) => { setBusy(true); try { setSelected(await api.getManagedTicket(id)); } catch (err: any) { setError(err.message); } finally { setBusy(false); } }, []);
  useEffect(() => { const id = new URLSearchParams(location.search).get('ticket_id'); if (id) void open(id); }, [open]);
  const fail = async (err: any) => {
    setError(err.message); setFields(err.errors || {});
    if (err.status === 409 && selected) { setSelected(await api.getManagedTicket(selected.id)); reload(); }
  };
  const submit = async (event: React.FormEvent) => {
    event.preventDefault(); if (busy) return; setBusy(true); setError(''); setFields({});
    try {
      if (mode === 'archive' && selected) { await api.archiveTicket(selected.id, selected.version, reason); setSelected(null); }
      else if (mode === 'create') {
        const { block_id, version, ...payload } = form;
        setSelected(await api.createManagedTicket(payload));
      } else if (selected) {
        const payload: Record<string, unknown> = { version: form.version, category_id: form.category_id, priority: form.priority };
        if (selected.status === 'NEW') Object.assign(payload, { title: form.title, description: form.description, location: form.location, preferred_service_time: form.preferred_service_time || null });
        setSelected(await api.updateManagedTicket(selected.id, payload));
      }
      setMode(null); setMessage('Đã lưu thay đổi.'); reload();
    } catch (err) { await fail(err); } finally { setBusy(false); }
  };
  const field = (key: keyof typeof blank, label: string, textarea = false) => <label className="block space-y-1 text-sm">{label}{textarea ? <textarea required className={input} value={String(form[key])} onChange={(e) => setForm({ ...form, [key]: e.target.value })} /> : <input required className={input} maxLength={key === 'title' ? 200 : 255} value={String(form[key])} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />}{fields[key] && <span className="text-rose-600">{fields[key][0]}</span>}</label>;
  const selectOptions = (data: Record<string, string>) => Object.entries(data).map(([value, label]) => ({ value, label }));
  return <section className="mx-auto max-w-[1600px] space-y-5 p-4 sm:p-6">
    <header className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-semibold">Yêu cầu / Sự cố</h1><p className="text-sm text-neutral-500">Tiếp nhận và theo dõi yêu cầu sửa chữa của cư dân.</p></div><div className="flex gap-2"><button className={button} disabled={loading || busy} onClick={reload}>Tải lại</button>{options?.can_create && <button className={`${button} bg-neutral-900 text-white`} onClick={() => { setForm({ ...blank, category_id: options.categories[0]?.id || '', idempotency_key: crypto.randomUUID() }); setFields({}); setError(''); setMode('create'); }}>Thêm yêu cầu</button>}</div></header>
    {message && <p role="status" className="rounded-xl bg-emerald-50 p-3 text-emerald-800">{message}</p>}
    {error && <div role="alert" className="rounded-xl bg-rose-50 p-3 text-rose-700">{error} <button className="underline cursor-pointer" onClick={reload}>Thử lại</button></div>}
    <div className="grid gap-3 rounded-2xl border bg-white p-4 sm:grid-cols-2 xl:grid-cols-5"><label className="text-xs">Tìm yêu cầu<input className={`${input} mt-1`} value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Mã, tiêu đề, vị trí…" /></label>
      {(['status', 'priority', 'category_id', 'block_id'] as const).map((key) => <CustomSelect key={key} label={{ status: 'Trạng thái', priority: 'Ưu tiên', category_id: 'Danh mục', block_id: 'Tòa nhà' }[key]} value={filters[key]} onChange={(value) => { setFilters({ ...filters, [key]: value }); setPage(1); }} options={[{ value: '', label: 'Tất cả' }, ...(key === 'status' ? selectOptions(ticketStatuses) : key === 'priority' ? selectOptions(ticketPriorities) : key === 'category_id' ? options?.categories.map((c) => ({ value: c.id, label: c.category_name })) || [] : options?.blocks.map((b) => ({ value: b.id, label: b.block_name })) || [])]} />)}
    </div>
    <div className="overflow-x-auto rounded-2xl border bg-white"><table className="w-full min-w-[760px] text-left text-sm"><thead className="bg-neutral-50"><tr>{['Mã yêu cầu', 'Nội dung', 'Căn hộ', 'Ưu tiên', 'Trạng thái', 'Ngày gửi', 'Thao tác'].map((label) => <th className="p-3" key={label}>{label}</th>)}</tr></thead><tbody>{loading ? <tr><td colSpan={7} className="p-8 text-center">Đang tải…</td></tr> : rows.length ? rows.map((ticket) => <tr key={ticket.id} className="border-t"><td className="p-3 font-medium">{ticket.ticket_number}</td><td className="p-3">{ticket.title}</td><td className="p-3">{ticket.apartment?.apartment_number}</td><td className="p-3">{ticketPriorities[ticket.priority as keyof typeof ticketPriorities]}</td><td className="p-3">{ticketStatuses[ticket.status as keyof typeof ticketStatuses]}</td><td className="p-3">{new Date(ticket.created_at).toLocaleString('vi-VN')}</td><td className="p-3"><button className={button} disabled={busy} onClick={() => void open(ticket.id)}>Xem chi tiết</button></td></tr>) : <tr><td colSpan={7} className="p-8 text-center text-neutral-500">Chưa có yêu cầu phù hợp.</td></tr>}</tbody></table></div>
    <div className="flex items-center justify-between text-sm"><span>{total} yêu cầu · 15 dòng/trang</span><div className="flex items-center gap-3"><button className={button} disabled={loading || page <= 1} onClick={() => setPage(page - 1)}>Trước</button><span>{page}/{pages}</span><button className={button} disabled={loading || page >= pages} onClick={() => setPage(page + 1)}>Sau</button></div></div>
    <TicketDialog open={!!selected && !mode} title={selected?.ticket_number || 'Chi tiết yêu cầu'} busy={busy} onClose={() => setSelected(null)}>{selected && <div className="space-y-4"><h3 className="font-semibold">{selected.title}</h3><p className="whitespace-pre-wrap">{selected.description}</p><p>{selected.location} · {ticketStatuses[selected.status as keyof typeof ticketStatuses]}</p><p>Kỹ thuật viên: {selected.technician?.full_name || 'Chưa phân công'}</p><div className="grid grid-cols-2 gap-3">{selected.attachments?.map((att) => <TicketImage key={att.id} name={att.file_name} url={att.file_url} onPreview={setPreview} />)}</div><div className="flex flex-wrap gap-2">{selected.actions.edit && <button className={button} onClick={() => { setForm({ ...blank, title: selected.title, description: selected.description, location: selected.location || '', category_id: selected.category_id, priority: selected.priority, preferred_service_time: selected.preferred_service_time?.slice(0, 16) || '', version: selected.version }); setMode('edit'); }}>Sửa / Phân loại</button>}{selected.actions.archive && <button className={`${button} text-rose-700`} onClick={() => { setReason(''); setMode('archive'); }}>Lưu trữ</button>}</div><ol className="space-y-2 border-t pt-3 text-xs">{selected.history?.map((entry) => <li key={entry.id}>{entry.notes} · {new Date(entry.created_at).toLocaleString('vi-VN')}</li>)}</ol></div>}</TicketDialog>
    <TicketDialog open={!!mode} title={mode === 'create' ? 'Thêm yêu cầu hộ cư dân' : mode === 'archive' ? 'Lưu trữ yêu cầu' : 'Sửa / Phân loại yêu cầu'} busy={busy} onClose={() => setMode(null)}><form onSubmit={submit} className="space-y-4">
      {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
      {mode === 'archive' ? <><p className="text-sm">Giữ lại lịch sử và ảnh. Yêu cầu sẽ được ẩn khỏi danh sách đang sử dụng.</p><label className="block text-sm">Lý do lưu trữ<textarea required minLength={5} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} className={input} /></label></> : <>
        {mode === 'create' && <div className="grid gap-3 sm:grid-cols-2"><CustomSelect label="Tòa nhà" value={form.block_id} onChange={(value) => setForm({ ...form, block_id: value, apartment_id: '', reporter_user_id: '' })} options={options?.blocks.map((b) => ({ value: b.id, label: b.block_name })) || []} /><CustomSelect label="Căn hộ" value={form.apartment_id} onChange={(value) => setForm({ ...form, apartment_id: value, reporter_user_id: '' })} options={options?.apartments.filter((a) => a.block_id === form.block_id).map((a) => ({ value: a.id, label: a.apartment_number })) || []} /><CustomSelect label="Người báo" value={form.reporter_user_id} onChange={(value) => setForm({ ...form, reporter_user_id: value })} options={options?.residents.filter((r) => r.apartment_id === form.apartment_id).map((r) => ({ value: r.id, label: r.full_name })) || []} /></div>}
        {(mode === 'create' || selected?.status === 'NEW') && <>{field('title', 'Tiêu đề')}{field('location', 'Vị trí')}{field('description', 'Mô tả', true)}<label className="block text-sm">Thời gian thuận tiện<input type="datetime-local" className={input} value={form.preferred_service_time} onChange={(e) => setForm({ ...form, preferred_service_time: e.target.value })} /></label></>}
        <div className="grid gap-3 sm:grid-cols-2"><CustomSelect label="Danh mục" value={form.category_id} onChange={(value) => setForm({ ...form, category_id: value })} options={options?.categories.map((c) => ({ value: c.id, label: c.category_name })) || []} /><CustomSelect label="Mức độ ưu tiên" value={form.priority} onChange={(value) => setForm({ ...form, priority: value })} options={selectOptions(ticketPriorities)} /></div>
        {Object.entries(fields).filter(([key]) => !['title', 'location', 'description'].includes(key)).map(([key, errors]) => <p key={key} className="text-xs text-rose-700">{errors[0]}</p>)}
      </>}
      <footer className="sticky bottom-0 flex justify-end gap-2 border-t bg-white py-3"><button type="button" className={button} disabled={busy} onClick={() => setMode(null)}>Đóng</button><button className={`${button} bg-neutral-900 text-white`} disabled={busy}>{busy ? 'Đang lưu…' : mode === 'archive' ? 'Xác nhận lưu trữ' : 'Lưu yêu cầu'}</button></footer>
    </form></TicketDialog>
    <TicketDialog open={!!preview} title="Ảnh sự cố" onClose={() => setPreview('')}><img src={preview} alt="Ảnh sự cố phóng to" className="max-h-[70vh] w-full object-contain" /></TicketDialog>
  </section>;
}
