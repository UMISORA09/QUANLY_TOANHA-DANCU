import React, { useCallback, useEffect, useState } from 'react';
import { api, ManagedTicket, TicketOptions } from '../Services/api';
import { CustomSelect } from './Common/CustomSelect';
import { TicketDialog } from './TicketDialog';
import { TicketImage } from './TicketImage';
import { useTicketNotificationRefresh } from './TicketNotifications';

export const ticketStatuses = { NEW: 'Mới gửi', RECEIVED: 'Đã tiếp nhận', IN_PROGRESS: 'Đang xử lý', RESOLVED: 'Chờ nghiệm thu', CLOSED: 'Đã hoàn tất', CANCELLED: 'Đã hủy' };
export const ticketPriorities = { LOW: 'Thấp', MEDIUM: 'Bình thường', HIGH: 'Cao', URGENT: 'Khẩn cấp' };
const button = 'min-h-11 rounded-xl border border-neutral-200 px-4 py-2 text-sm font-medium cursor-pointer hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600 disabled:opacity-40 disabled:cursor-not-allowed';
const input = 'min-h-11 w-full rounded-xl border border-neutral-200 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-sky-600';
const selectAppearance = { buttonClassName: 'min-h-11 text-sm' };
const statusColors = { NEW: 'bg-sky-50 text-sky-800', RECEIVED: 'bg-blue-50 text-blue-800', IN_PROGRESS: 'bg-amber-50 text-amber-800', RESOLVED: 'bg-violet-50 text-violet-800', CLOSED: 'bg-emerald-50 text-emerald-800', CANCELLED: 'bg-neutral-100 text-neutral-600' };
const statusBadge = (ticket: ManagedTicket) => <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${statusColors[ticket.status]}`}>{ticketStatuses[ticket.status]}</span>;
const blank = { title: '', description: '', location: '', category_id: '', priority: 'MEDIUM', preferred_service_time: '', block_id: '', apartment_id: '', reporter_user_id: '', version: 1, idempotency_key: '' };
const workflowLabels: Record<string, string> = { receive: 'Tiếp nhận', assign: 'Phân công kỹ thuật', start: 'Bắt đầu xử lý', resolve: 'Báo đã khắc phục', cancel: 'Hủy yêu cầu' };

export function TicketManagementPanel() {
  const [options, setOptions] = useState<TicketOptions | null>(null);
  const [rows, setRows] = useState<ManagedTicket[]>([]);
  const [selected, setSelected] = useState<ManagedTicket | null>(null);
  const [query, setQuery] = useState('');
  const [keyword, setKeyword] = useState('');
  const [filters, setFilters] = useState({ status: '', priority: '', category_id: '', block_id: '' });
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [fields, setFields] = useState<Record<string, string[]>>({});
  const [mode, setMode] = useState<'create' | 'edit' | 'archive' | 'workflow' | null>(null);
  const [action, setAction] = useState('');
  const [technicians, setTechnicians] = useState<{ id: string; full_name: string }[]>([]);
  const [technician, setTechnician] = useState('');
  const [form, setForm] = useState(blank);
  const [reason, setReason] = useState('');
  const [preview, setPreview] = useState('');
  const [removeImageId, setRemoveImageId] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);
  const reload = () => setRevision((value) => value + 1);
  useEffect(() => { if (query === keyword) return; const timer = setTimeout(() => { setKeyword(query); setPage(1); }, 350); return () => clearTimeout(timer); }, [query, keyword]);
  useEffect(() => { let active = true; api.getTicketOptions().then((data) => { if (active) setOptions(data); }).catch((err) => { if (active) setError(err.message); }); return () => { active = false; }; }, [revision]);
  useEffect(() => {
    const controller = new AbortController();
    const params = new URLSearchParams({ page: String(page), search: keyword });
    Object.entries(filters).forEach(([key, value]) => { if (value) params.set(key, value); });
    setLoading(true); setError('');
    api.getManagedTickets(params, controller.signal).then((data) => { if (!controller.signal.aborted) { const lastPage = Math.max(1, data.last_page); setPages(lastPage); setTotal(data.total); if (page > lastPage) { setPage(lastPage); return; } setRows(data.data); } })
      .catch((err) => { if (!controller.signal.aborted) setError(err.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [filters, keyword, page, revision]);
  const open = useCallback(async (id: string) => { setBusy(true); try { setSelected(await api.getManagedTicket(id)); } catch (err: any) { setError(err.message); } finally { setBusy(false); } }, []);
  useEffect(() => { const id = new URLSearchParams(location.search).get('ticket_id'); if (id) void open(id); }, [open]);
  useEffect(() => {
    const openLinked = () => { const id = new URLSearchParams(location.search).get('ticket_id'); if (id) void open(id); };
    window.addEventListener('popstate', openLinked);
    window.addEventListener('TICKET_NOTIFICATION_RECEIVED', openLinked);
    return () => { window.removeEventListener('popstate', openLinked); window.removeEventListener('TICKET_NOTIFICATION_RECEIVED', openLinked); };
  }, [open]);
  const fail = async (err: any) => {
    setError(err.message); setFields(err.errors || {});
    if (err.status === 409 && selected) {
      setConflict(true);
      try { setSelected(await api.getManagedTicket(selected.id)); reload(); }
      catch { setError('Yêu cầu đã thay đổi nhưng chưa tải được bản mới. Hãy đóng form và thử tải lại.'); }
    }
  };
  const submit = async (event: React.FormEvent) => {
    event.preventDefault(); if (busy || conflict) return; setBusy(true); setError(''); setFields({});
    try {
      if (mode === 'workflow' && selected) { setSelected(await api.performTicketAction(selected.id, { action, version: form.version, technician_user_id: technician || undefined, reason: reason || undefined, note: reason || undefined })); }
      else if (mode === 'archive' && selected) { await api.archiveTicket(selected.id, form.version, reason); setSelected(null); }
      else if (mode === 'create') {
        const { block_id, version, preferred_service_time, ...payload } = form;
        setSelected(await api.createManagedTicket(preferred_service_time ? { ...payload, preferred_service_time } : payload));
      } else if (selected) {
        const payload: Record<string, unknown> = { version: form.version, category_id: form.category_id, priority: form.priority };
        if (selected.status === 'NEW') Object.assign(payload, { title: form.title, description: form.description, location: form.location, preferred_service_time: form.preferred_service_time || null });
        setSelected(await api.updateManagedTicket(selected.id, payload));
      }
      setMode(null); setMessage('Đã lưu thay đổi.'); reload();
    } catch (err) { await fail(err); } finally { setBusy(false); }
  };
  const field = (key: keyof typeof blank, label: string, textarea = false) => <label className="block space-y-1 text-sm">{label} <span aria-hidden="true" className="text-rose-600">*</span>{textarea ? <textarea aria-label={label} required minLength={5} rows={4} aria-invalid={!!fields[key]} className={input} value={String(form[key])} onChange={(e) => setForm({ ...form, [key]: e.target.value })} /> : <input aria-label={label} required aria-invalid={!!fields[key]} className={input} maxLength={key === 'title' ? 200 : 255} value={String(form[key])} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />}{fields[key] && <span className="text-rose-600">{fields[key][0]}</span>}</label>;
  const selectOptions = (data: Record<string, string>) => Object.entries(data).map(([value, label]) => ({ value, label }));
  const cannotSubmit = busy || conflict
    || (mode === 'create' && (!form.apartment_id || !form.reporter_user_id || !form.category_id))
    || (mode === 'workflow' && action === 'assign' && !technician)
    || ((mode === 'archive' || (mode === 'workflow' && ['cancel', 'resolve'].includes(action))) && reason.trim().length < 5);
  useTicketNotificationRefresh(() => { reload(); if (selected && !mode) void open(selected.id); });

  return <section className="mx-auto max-w-[1600px] space-y-5 p-4 sm:p-6">

    <header className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-semibold">Yêu cầu / Sự cố</h1><p className="text-sm text-neutral-500">Tiếp nhận và theo dõi yêu cầu sửa chữa của cư dân.</p></div><div className="flex gap-2"><button className={button} disabled={loading || busy} onClick={reload}>Tải lại</button>{options?.can_create && <button className={`${button} bg-neutral-900 text-white hover:bg-neutral-800`} onClick={() => { setForm({ ...blank, category_id: options.categories[0]?.id || '', idempotency_key: crypto.randomUUID() }); setFields({}); setError(''); setConflict(false); setMode('create'); }}>Thêm yêu cầu</button>}</div></header>

    {message && <p role="status" className="rounded-xl bg-emerald-50 p-3 text-emerald-800">{message}</p>}
    {error && <div role="alert" className="rounded-xl bg-rose-50 p-3 text-rose-700">{error} <button className="underline cursor-pointer" onClick={reload}>Thử lại</button></div>}
    <div className="space-y-4 rounded-2xl border border-neutral-200 bg-white p-4"><div className="flex items-end gap-3"><label className="min-w-0 flex-1 text-sm">Tìm yêu cầu<input className={`${input} mt-1`} value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Mã, tiêu đề, vị trí…" /></label><button type="button" className={`${button} shrink-0`} aria-expanded={filtersOpen} aria-controls="ticket-management-filters" onClick={() => setFiltersOpen(!filtersOpen)}>Bộ lọc{Object.values(filters).filter(Boolean).length > 0 ? ` (${Object.values(filters).filter(Boolean).length})` : ''}</button></div>
    {filtersOpen && <div id="ticket-management-filters" className="grid gap-3 border-t border-neutral-100 pt-4 sm:grid-cols-2 2xl:grid-cols-4">
      {(['status', 'priority', 'category_id', 'block_id'] as const).map((key) => <CustomSelect overlay {...selectAppearance} key={key} label={{ status: 'Trạng thái', priority: 'Ưu tiên', category_id: 'Danh mục', block_id: 'Tòa nhà' }[key]} value={filters[key]} onChange={(value) => { setFilters({ ...filters, [key]: value }); setPage(1); }} options={[{ value: '', label: 'Tất cả' }, ...(key === 'status' ? selectOptions(ticketStatuses) : key === 'priority' ? selectOptions(ticketPriorities) : key === 'category_id' ? options?.categories.map((c) => ({ value: c.id, label: c.category_name })) || [] : options?.blocks.map((b) => ({ value: b.id, label: b.block_name })) || [])]} />)}
    </div>}
    </div>
    {(query || Object.values(filters).some(Boolean)) && <div className="flex justify-end"><button type="button" className="min-h-11 cursor-pointer px-3 text-sm font-medium text-sky-700 underline" onClick={() => { setQuery(''); setKeyword(''); setFilters({ status: '', priority: '', category_id: '', block_id: '' }); setPage(1); }}>Xóa bộ lọc</button></div>}
    <div aria-busy={loading} className="rounded-2xl border border-neutral-200 bg-white">
      {loading ? <p role="status" className="p-8 text-center text-sm text-neutral-500">Đang tải yêu cầu…</p> : !rows.length ? <div className="space-y-2 p-8 text-center"><p className="font-medium">Chưa có yêu cầu phù hợp.</p><p className="text-sm text-neutral-500">Thử đổi từ khóa hoặc bỏ bớt bộ lọc.</p></div> : <>
        <ul className="divide-y divide-neutral-100 lg:hidden">{rows.map((ticket) => <li key={ticket.id} className="space-y-3 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-mono text-xs text-neutral-500">{ticket.ticket_number}</span>{statusBadge(ticket)}</div>
          <h2 className="break-words font-semibold text-neutral-900">{ticket.title}</h2>
          <p className="text-sm text-neutral-600">Căn hộ {ticket.apartment?.apartment_number || '—'} · Ưu tiên {ticketPriorities[ticket.priority]}</p>
          <div className="flex flex-wrap items-center justify-between gap-2"><time className="text-xs text-neutral-500" dateTime={ticket.created_at}>{new Date(ticket.created_at).toLocaleString('vi-VN')}</time><button className={button} disabled={busy} onClick={() => void open(ticket.id)}>Xem chi tiết</button></div>
        </li>)}</ul>
        <div className="hidden overflow-x-auto rounded-2xl lg:block"><table className="w-full min-w-[760px] text-left text-sm"><caption className="sr-only">Danh sách yêu cầu hỗ trợ của cư dân</caption><thead className="bg-neutral-50 text-xs text-neutral-500"><tr>{['Mã yêu cầu', 'Nội dung', 'Căn hộ', 'Ưu tiên', 'Trạng thái', 'Ngày gửi', 'Thao tác'].map((label) => <th scope="col" className="p-4" key={label}>{label}</th>)}</tr></thead><tbody>{rows.map((ticket) => <tr key={ticket.id} className="border-t border-neutral-100 hover:bg-neutral-50/60"><td className="whitespace-nowrap p-4 font-mono text-xs">{ticket.ticket_number}</td><td className="min-w-48 max-w-sm break-words p-4 font-medium">{ticket.title}</td><td className="whitespace-nowrap p-4">{ticket.apartment?.apartment_number || '—'}</td><td className="p-4"><span className={ticket.priority === 'URGENT' ? 'font-semibold text-rose-700' : 'text-neutral-600'}>{ticketPriorities[ticket.priority]}</span></td><td className="whitespace-nowrap p-4">{statusBadge(ticket)}</td><td className="whitespace-nowrap p-4 text-xs text-neutral-500">{new Date(ticket.created_at).toLocaleString('vi-VN')}</td><td className="p-4"><button className={`${button} whitespace-nowrap`} disabled={busy} onClick={() => void open(ticket.id)}>Xem chi tiết</button></td></tr>)}</tbody></table></div>
      </>}
    </div>
    <nav aria-label="Phân trang yêu cầu" className="flex flex-wrap items-center justify-between gap-3 text-sm"><span className="text-neutral-500">{total} yêu cầu · 15 dòng/trang</span><div className="flex items-center gap-3"><button className={button} disabled={loading || page <= 1} onClick={() => setPage(page - 1)}>Trước</button><span aria-live="polite">Trang {page}/{pages}</span><button className={button} disabled={loading || page >= pages} onClick={() => setPage(page + 1)}>Sau</button></div></nav>
    <TicketDialog open={!!selected && !mode && !removeImageId} title={selected?.ticket_number || 'Chi tiết yêu cầu'} busy={busy} onClose={() => setSelected(null)}>{selected && <div className="space-y-4">
      {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      <div className="space-y-2">{statusBadge(selected)}<h3 className="break-words text-lg font-semibold">{selected.title}</h3></div>
      <dl className="grid grid-cols-1 gap-4 rounded-xl bg-neutral-50 p-4 text-sm sm:grid-cols-2">
        {Object.entries({ 'Người báo': selected.creator?.full_name || '—', 'Căn hộ': `${selected.apartment?.apartment_number || '—'}${selected.apartment?.block?.block_name ? ` · ${selected.apartment.block.block_name}` : ''}`, 'Danh mục': selected.category?.category_name || options?.categories.find((item) => item.id === selected.category_id)?.category_name || '—', 'Mức độ ưu tiên': ticketPriorities[selected.priority], 'Vị trí': selected.location || '—', 'Kỹ thuật viên': selected.technician?.full_name || 'Chưa phân công' }).map(([label, value]) => <div key={label}><dt className="text-xs text-neutral-500">{label}</dt><dd className="mt-1 break-words font-medium">{value}</dd></div>)}
      </dl>
      <section className="space-y-2"><h4 className="text-sm font-semibold">Mô tả sự cố</h4><p className="whitespace-pre-wrap break-words text-sm leading-6 text-neutral-700">{selected.description}</p></section>
      <section className="space-y-2"><h4 className="text-sm font-semibold">Ảnh đính kèm ({selected.attachments?.length || 0}/5)</h4>{selected.attachments?.length ? <div className="grid grid-cols-2 gap-3">{selected.attachments.map((att) => <div key={att.id} className="grid gap-1"><TicketImage name={att.file_name} url={att.file_url} onPreview={setPreview} /><p className="text-xs text-neutral-500">{att.is_resolution_proof ? 'Kết quả xử lý' : 'Hiện trường'}</p>{selected.actions.edit && !busy && <button type="button" className="text-left text-xs text-rose-700 underline cursor-pointer" onClick={() => { setError(''); setRemoveImageId(att.id); }}>Bỏ ảnh {att.file_name}</button>}</div>)}</div> : <p className="text-sm text-neutral-500">Chưa có ảnh đính kèm.</p>}</section>
      {selected.actions.resolve && <label className="block space-y-2 text-sm">Ảnh kết quả xử lý<span className="block text-xs text-neutral-500">Tối đa 5 ảnh cho cả yêu cầu, JPG/PNG/WebP, mỗi ảnh tối đa 5 MB.</span><input type="file" accept="image/png,image/jpeg,image/webp" multiple disabled={busy || (selected.attachments?.length || 0) >= 5} className="mt-2 block" onChange={async (event) => {
        const files = Array.from(event.target.files || []); event.target.value = ''; if (!files.length) return;
        if (files.length + (selected.attachments?.length || 0) > 5 || files.some((file) => file.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) { setError('Chỉ nhận tối đa 5 ảnh JPG, PNG, WebP cho mỗi yêu cầu, tối đa 5 MB/ảnh.'); return; }
        setBusy(true); try { await api.addTicketImages(selected.id, files, selected.version); setSelected(await api.getManagedTicket(selected.id)); } catch (err) { await fail(err); } finally { setBusy(false); }
      }} /></label>}
      <div className="flex flex-wrap gap-2">{selected.actions.edit && <button className={button} disabled={busy} onClick={() => { setForm({ ...blank, title: selected.title, description: selected.description, location: selected.location || '', category_id: selected.category_id, priority: selected.priority, preferred_service_time: selected.preferred_service_time?.slice(0, 16) || '', version: selected.version }); setFields({}); setError(''); setConflict(false); setMode('edit'); }}>Sửa / Phân loại</button>}{selected.actions.archive && <button className={`${button} text-rose-700`} disabled={busy} onClick={() => { setReason(''); setForm({ ...blank, version: selected.version }); setError(''); setConflict(false); setMode('archive'); }}>Lưu trữ</button>}
      {Object.entries(workflowLabels).filter(([key]) => selected.actions[key]).map(([key, label]) => <button key={key} disabled={busy} className={button} onClick={async () => {
        setAction(key); setReason(''); setTechnician(''); setError(''); setForm({ ...blank, version: selected.version });
        if (key === 'assign') { setBusy(true); try { setTechnicians(await api.getTicketTechnicians(selected.id)); } catch (err) { await fail(err); return; } finally { setBusy(false); } }
        setFields({}); setConflict(false); setMode('workflow');
      }}>{label}</button>)}
      </div><section className="space-y-3 border-t border-neutral-200 pt-4"><h4 className="text-sm font-semibold">Lịch sử xử lý</h4>{selected.history?.length ? <ol className="space-y-3">{selected.history.map((entry) => <li key={entry.id} className="border-l-2 border-neutral-200 pl-3 text-sm"><p className="break-words">{entry.notes}</p><p className="mt-1 text-xs text-neutral-500">{entry.performer?.full_name || 'Hệ thống'} · {new Date(entry.created_at).toLocaleString('vi-VN')}</p></li>)}</ol> : <p className="text-sm text-neutral-500">Chưa có cập nhật xử lý.</p>}</section>
    </div>}</TicketDialog>
    <TicketDialog open={!!mode} title={mode === 'workflow' ? workflowLabels[action] : mode === 'create' ? 'Thêm yêu cầu hộ cư dân' : mode === 'archive' ? 'Lưu trữ yêu cầu' : 'Sửa / Phân loại yêu cầu'} busy={busy} onClose={() => setMode(null)} footer={<footer className="shrink-0 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-white px-5 pb-4 pt-3"><button type="button" className={button} disabled={busy} onClick={() => setMode(null)}>Đóng</button><button type="submit" form="ticket-management-form" className={`${button} ${mode === 'archive' || (mode === 'workflow' && action === 'cancel') ? 'border-rose-600 bg-rose-600 text-white hover:bg-rose-700' : 'bg-neutral-900 text-white hover:bg-neutral-800'}`} disabled={cannotSubmit}>{busy ? 'Đang lưu…' : mode === 'archive' ? 'Xác nhận lưu trữ' : mode === 'workflow' ? workflowLabels[action] : 'Lưu yêu cầu'}</button></footer>}><form id="ticket-management-form" onSubmit={submit} className="space-y-4">
      {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
      {conflict && <div role="status" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Yêu cầu đã được người khác cập nhật. Dữ liệu bạn nhập vẫn được giữ; hãy xem lại bản mới trước khi tiếp tục. <button type="button" className="min-h-11 font-semibold underline" onClick={() => setMode(null)}>Xem bản mới nhất</button></div>}
      {mode === 'workflow' ? <><p className="text-sm">Xác nhận thao tác với {selected?.ticket_number}.</p>{action === 'assign' && <CustomSelect overlay {...selectAppearance} label="Kỹ thuật viên" required disabled={!technicians.length} value={technician} onChange={setTechnician} options={technicians.map((user) => ({ value: user.id, label: user.full_name }))} placeholder={technicians.length ? 'Chọn kỹ thuật viên' : 'Chưa có kỹ thuật viên hợp lệ'} />}{action === 'assign' && !technicians.length && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Chưa có kỹ thuật viên đủ quyền trong tòa nhà này. Vui lòng kiểm tra hồ sơ nhân viên và quyền phân công.</p>}<label className="block text-sm">{action === 'cancel' ? 'Lý do hủy (ít nhất 5 ký tự)' : action === 'resolve' ? 'Ghi chú khắc phục (ít nhất 5 ký tự)' : 'Ghi chú xử lý (không bắt buộc)'}<textarea required={['cancel', 'resolve'].includes(action)} minLength={5} maxLength={500} className={input} value={reason} onChange={(event) => setReason(event.target.value)} /></label></> : mode === 'archive' ? <><p className="text-sm">Giữ lại lịch sử và ảnh. Yêu cầu sẽ được ẩn khỏi danh sách đang sử dụng.</p><label className="block text-sm">Lý do lưu trữ<textarea required minLength={5} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} className={input} /></label></> : <>
        {mode === 'create' && <p className="text-sm text-neutral-500">Chọn tòa nhà, căn hộ và cư dân cần hỗ trợ. Các trường có dấu * là bắt buộc.</p>}{mode === 'create' && <div className="grid gap-3 sm:grid-cols-2"><CustomSelect overlay {...selectAppearance} label="Tòa nhà" required value={form.block_id} onChange={(value) => setForm({ ...form, block_id: value, apartment_id: '', reporter_user_id: '' })} options={options?.blocks.map((b) => ({ value: b.id, label: b.block_name })) || []} /><CustomSelect overlay {...selectAppearance} label="Căn hộ" required disabled={!form.block_id} value={form.apartment_id} onChange={(value) => setForm({ ...form, apartment_id: value, reporter_user_id: '' })} options={options?.apartments.filter((a) => a.block_id === form.block_id).map((a) => ({ value: a.id, label: a.apartment_number })) || []} /><CustomSelect overlay {...selectAppearance} label="Người báo" required disabled={!form.apartment_id} value={form.reporter_user_id} onChange={(value) => setForm({ ...form, reporter_user_id: value })} options={options?.residents.filter((r) => r.apartment_id === form.apartment_id).map((r) => ({ value: r.id, label: r.full_name })) || []} /></div>}
        {(mode === 'create' || selected?.status === 'NEW') && <>{field('title', 'Tiêu đề')}{field('location', 'Vị trí')}{field('description', 'Mô tả', true)}<label className="block text-sm">Thời gian thuận tiện<input type="datetime-local" className={input} value={form.preferred_service_time} onChange={(e) => setForm({ ...form, preferred_service_time: e.target.value })} /></label></>}
        <div className="grid gap-3 sm:grid-cols-2"><CustomSelect overlay {...selectAppearance} label="Danh mục" required value={form.category_id} onChange={(value) => setForm({ ...form, category_id: value })} options={options?.categories.map((c) => ({ value: c.id, label: c.category_name })) || []} /><CustomSelect overlay {...selectAppearance} label="Mức độ ưu tiên" value={form.priority} onChange={(value) => setForm({ ...form, priority: value })} options={selectOptions(ticketPriorities)} /></div>
        {Object.entries(fields).filter(([key]) => !['title', 'location', 'description'].includes(key)).map(([key, errors]) => <p key={key} className="text-xs text-rose-700">{errors[0]}</p>)}
      </>}

    </form></TicketDialog>
    <TicketDialog open={!!removeImageId} title="Bỏ ảnh sự cố" busy={busy} onClose={() => setRemoveImageId(null)}>
      {error && <p role="alert" className="mb-3 text-sm text-rose-700">{error}</p>}
      <p className="text-sm">Bạn muốn bỏ ảnh này khỏi yêu cầu? Ảnh đã bỏ không thể khôi phục từ màn hình này.</p>
      <div className="mt-4 flex justify-end gap-2"><button type="button" disabled={busy} className={button} onClick={() => setRemoveImageId(null)}>Đóng</button><button type="button" disabled={busy} className={`${button} bg-rose-600 text-white hover:bg-rose-700`} onClick={async () => {
        if (!selected || !removeImageId || busy) return;
        setBusy(true); setError('');
        try { setSelected(await api.removeManagedTicketImage(selected.id, removeImageId, selected.version)); setRemoveImageId(null); setMessage('Đã bỏ ảnh sự cố.'); reload(); }
        catch (err) { await fail(err); }
        finally { setBusy(false); }
      }}>{busy ? 'Đang bỏ ảnh…' : 'Xác nhận bỏ ảnh'}</button></div>
    </TicketDialog>
    <TicketDialog open={!!preview} title="Ảnh sự cố" onClose={() => setPreview('')}><img src={preview} alt="Ảnh sự cố phóng to" className="max-h-[70vh] w-full object-contain" /></TicketDialog>
  </section>;
}
