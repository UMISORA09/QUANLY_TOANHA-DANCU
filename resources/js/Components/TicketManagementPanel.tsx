import React, { useCallback, useEffect, useState } from 'react';
import { api, ManagedTicket, TicketOptions } from '../Services/api';
import { CustomSelect } from './Common/CustomSelect';
import { TicketDialog } from './TicketDialog';
import { TicketImage } from './TicketImage';
import { useTicketNotificationRefresh } from './TicketNotifications';

export const ticketStatuses = { NEW: 'Mới gửi', RECEIVED: 'Đã tiếp nhận', IN_PROGRESS: 'Đang xử lý', RESOLVED: 'Chờ nghiệm thu', CLOSED: 'Đã hoàn tất', CANCELLED: 'Đã hủy' };
export const ticketPriorities = { LOW: 'Thấp', MEDIUM: 'Bình thường', HIGH: 'Cao', URGENT: 'Khẩn cấp' };
const buttonBase = 'inline-flex items-center justify-center min-h-11 rounded-xl border px-4 py-2 text-sm font-medium cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 shadow-2xs disabled:opacity-40 disabled:cursor-not-allowed';
const button = `${buttonBase} border-slate-200 bg-white text-slate-700 hover:bg-slate-50`;
const input = 'min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus-visible:outline-2 focus-visible:outline-blue-600 shadow-2xs';
const selectAppearance = { buttonClassName: 'min-h-11 text-sm' };
const statusColors = {
  NEW: 'bg-sky-50 text-sky-700 border border-sky-200/80 font-semibold',
  RECEIVED: 'bg-blue-50 text-blue-700 border border-blue-200/80 font-semibold',
  IN_PROGRESS: 'bg-amber-50 text-amber-800 border border-amber-200/80 font-semibold',
  RESOLVED: 'bg-indigo-50 text-indigo-700 border border-indigo-200/80 font-semibold',
  CLOSED: 'bg-emerald-50 text-emerald-700 border border-emerald-200/80 font-semibold',
  CANCELLED: 'bg-slate-100 text-slate-600 border border-slate-200/80 font-medium'
};
const statusBadge = (ticket: ManagedTicket) => (
  <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs shadow-2xs ${statusColors[ticket.status]}`}>
    {ticketStatuses[ticket.status]}
  </span>
);
const blank = { title: '', description: '', location: '', category_id: '', priority: 'MEDIUM', preferred_service_time: '', block_id: '', apartment_id: '', reporter_user_id: '', version: 1, idempotency_key: '' };
const workflowLabels: Record<string, string> = { receive: 'Tiếp nhận', assign: 'Phân công kỹ thuật', start: 'Bắt đầu xử lý', resolve: 'Báo đã khắc phục', cancel: 'Hủy yêu cầu' };

const workflowButtonStyles: Record<string, string> = {
  receive: 'bg-blue-600 text-white hover:bg-blue-700 border-blue-600 shadow-xs',
  assign: 'bg-indigo-600 text-white hover:bg-indigo-700 border-indigo-600 shadow-xs',
  start: 'bg-amber-600 text-white hover:bg-amber-700 border-amber-600 shadow-xs',
  resolve: 'bg-emerald-600 text-white hover:bg-emerald-700 border-emerald-600 shadow-xs',
  cancel: 'border-rose-200 text-rose-700 hover:bg-rose-50 shadow-2xs',
};

const workflowSubmitStyles: Record<string, string> = {
  receive: 'bg-blue-600 text-white hover:bg-blue-700 border-blue-600',
  assign: 'bg-indigo-600 text-white hover:bg-indigo-700 border-indigo-600',
  start: 'bg-amber-600 text-white hover:bg-amber-700 border-amber-600',
  resolve: 'bg-emerald-600 text-white hover:bg-emerald-700 border-emerald-600',
  cancel: 'bg-rose-600 text-white hover:bg-rose-700 border-rose-600',
};

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
  const field = (key: keyof typeof blank, label: string, textarea = false) => (
    <label className="block space-y-1.5 text-xs font-bold uppercase tracking-wider text-slate-700">
      {label} <span aria-hidden="true" className="text-rose-600">*</span>
      {textarea ? (
        <textarea aria-label={label} required minLength={5} rows={4} aria-invalid={!!fields[key]} className={input} value={String(form[key])} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
      ) : (
        <input aria-label={label} required aria-invalid={!!fields[key]} className={input} maxLength={key === 'title' ? 200 : 255} value={String(form[key])} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
      )}
      {fields[key] && <span className="text-xs font-normal text-rose-600 normal-case">{fields[key][0]}</span>}
    </label>
  );
  const selectOptions = (data: Record<string, string>) => Object.entries(data).map(([value, label]) => ({ value, label }));
  const cannotSubmit = busy || conflict
    || (mode === 'create' && (!form.apartment_id || !form.reporter_user_id || !form.category_id))
    || (mode === 'workflow' && action === 'assign' && !technician)
    || ((mode === 'archive' || (mode === 'workflow' && ['cancel', 'resolve'].includes(action))) && reason.trim().length < 5);
  useTicketNotificationRefresh(() => { reload(); if (selected && !mode) void open(selected.id); });

  return <section className="mx-auto max-w-[1600px] space-y-6 p-4 sm:p-6 lg:p-8">

    {/* Page Header */}
    <div className="relative overflow-hidden rounded-3xl border border-slate-200/80 bg-gradient-to-br from-white via-slate-50/60 to-blue-50/40 p-6 sm:p-8 shadow-xs">
      <div className="absolute top-0 right-0 -mt-10 -mr-10 w-64 h-64 bg-blue-100/40 rounded-full blur-3xl pointer-events-none" />
      <header className="relative z-10 flex flex-wrap items-center justify-between gap-4">
        <div className="space-y-1.5">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider text-blue-700 bg-blue-50/90 border border-blue-200/70 shadow-2xs mb-1">
            <span>QUY TRÌNH TIẾP NHẬN & XỬ LÝ SỰ CỐ</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">
            Yêu cầu / Sự cố
          </h1>
          <p className="max-w-2xl text-sm leading-6 text-slate-500">
            Tiếp nhận và theo dõi yêu cầu sửa chữa của cư dân.
          </p>
        </div>
        <div className="flex items-center gap-2.5">
          <button className={`${button} bg-white hover:bg-slate-50 text-slate-700 shadow-2xs`} disabled={loading || busy} onClick={reload}>
            Tải lại
          </button>
          {options?.can_create && (
            <button
              className={`${buttonBase} border-slate-950 bg-slate-950 text-white hover:bg-slate-800 shadow-sm transition-all`}
              onClick={() => { setForm({ ...blank, category_id: options.categories[0]?.id || '', idempotency_key: crypto.randomUUID() }); setFields({}); setError(''); setConflict(false); setMode('create'); }}
            >
              Thêm yêu cầu
            </button>
          )}
        </div>
      </header>
    </div>

    {message && <p role="status" className="rounded-2xl border border-emerald-200/80 bg-emerald-50/80 p-4 text-xs font-semibold text-emerald-800 shadow-2xs">{message}</p>}
    {error && <div role="alert" className="rounded-2xl border border-rose-200/80 bg-rose-50/80 p-4 text-xs font-semibold text-rose-700 shadow-2xs">{error} <button className="underline cursor-pointer ml-1" onClick={reload}>Thử lại</button></div>}

    {/* Search & Filter Toolbar */}
    <div className="space-y-4 rounded-3xl border border-slate-200/80 bg-white p-5 sm:p-6 shadow-xs">
      <div className="flex items-end gap-3">
        <label className="min-w-0 flex-1 text-xs font-bold uppercase tracking-wider text-slate-700">
          Tìm yêu cầu
          <input className={`${input} mt-1`} value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Mã, tiêu đề, vị trí…" />
        </label>
        <button
          type="button"
          className={`${buttonBase} shrink-0 text-slate-700 hover:bg-slate-50 ${filtersOpen ? 'bg-slate-100 border-slate-300' : 'bg-white border-slate-200 shadow-2xs'}`}
          aria-expanded={filtersOpen}
          aria-controls="ticket-management-filters"
          onClick={() => setFiltersOpen(!filtersOpen)}
        >
          Bộ lọc{Object.values(filters).filter(Boolean).length > 0 ? ` (${Object.values(filters).filter(Boolean).length})` : ''}
        </button>
      </div>
      {filtersOpen && (
        <div id="ticket-management-filters" className="grid gap-3.5 border-t border-slate-100 pt-4 sm:grid-cols-2 2xl:grid-cols-4">
          {(['status', 'priority', 'category_id', 'block_id'] as const).map((key) => (
            <CustomSelect
              overlay
              {...selectAppearance}
              key={key}
              label={{ status: 'Trạng thái', priority: 'Ưu tiên', category_id: 'Danh mục', block_id: 'Tòa nhà' }[key]}
              value={filters[key]}
              onChange={(value) => { setFilters({ ...filters, [key]: value }); setPage(1); }}
              options={[{ value: '', label: 'Tất cả' }, ...(key === 'status' ? selectOptions(ticketStatuses) : key === 'priority' ? selectOptions(ticketPriorities) : key === 'category_id' ? options?.categories.map((c) => ({ value: c.id, label: c.category_name })) || [] : options?.blocks.map((b) => ({ value: b.id, label: b.block_name })) || [])]}
            />
          ))}
        </div>
      )}
    </div>
    {(query || Object.values(filters).some(Boolean)) && <div className="flex justify-end"><button type="button" className="min-h-11 cursor-pointer px-3 text-sm font-semibold text-blue-700 hover:text-blue-800 underline transition-colors" onClick={() => { setQuery(''); setKeyword(''); setFilters({ status: '', priority: '', category_id: '', block_id: '' }); setPage(1); }}>Xóa bộ lọc</button></div>}

    {/* Table / List Container */}
    <div aria-busy={loading} className="rounded-3xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
      {loading ? (
        <div role="status" className="p-12 text-center text-sm font-medium text-slate-500">
          <div className="inline-block h-6 w-6 animate-spin rounded-full border-2 border-blue-600 border-t-transparent mb-3" />
          <p>Đang tải yêu cầu…</p>
        </div>
      ) : !rows.length ? (
        <div className="space-y-2 p-12 text-center">
          <p className="font-semibold text-slate-800">Chưa có yêu cầu phù hợp.</p>
          <p className="text-sm text-slate-500">Thử đổi từ khóa hoặc bỏ bớt bộ lọc.</p>
        </div>
      ) : (
        <>
          <ul className="divide-y divide-slate-100 lg:hidden">
            {rows.map((ticket) => (
              <li key={ticket.id} className="space-y-3.5 p-5 hover:bg-slate-50/60 transition-colors">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-mono text-xs font-semibold px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 border border-slate-200/60">
                    {ticket.ticket_number}
                  </span>
                  {statusBadge(ticket)}
                </div>
                <h2 className="break-words text-base font-bold text-slate-900 leading-snug">{ticket.title}</h2>
                <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
                  <span className="font-medium text-slate-800">Căn hộ {ticket.apartment?.apartment_number || '—'}</span>
                  <span>·</span>
                  <span>Ưu tiên {ticketPriorities[ticket.priority]}</span>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2 pt-1 border-t border-slate-100/80">
                  <time className="text-xs text-slate-500" dateTime={ticket.created_at}>
                    {new Date(ticket.created_at).toLocaleString('vi-VN')}
                  </time>
                  <button className={`${button} bg-white hover:bg-slate-50 text-slate-700 shadow-2xs`} disabled={busy} onClick={() => void open(ticket.id)}>
                    Xem chi tiết
                  </button>
                </div>
              </li>
            ))}
          </ul>
          <div className="hidden overflow-x-auto lg:block">
            <table className="w-full min-w-[760px] text-left text-sm">
              <caption className="sr-only">Danh sách yêu cầu hỗ trợ của cư dân</caption>
              <thead className="bg-slate-50/80 border-b border-slate-200/70 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                <tr>
                  {['Mã yêu cầu', 'Nội dung', 'Căn hộ', 'Ưu tiên', 'Trạng thái', 'Ngày gửi', 'Thao tác'].map((label) => (
                    <th scope="col" className="px-5 py-3.5" key={label}>{label}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((ticket) => (
                  <tr key={ticket.id} className="hover:bg-blue-50/30 transition-colors">
                    <td className="whitespace-nowrap px-5 py-4 font-mono text-xs font-bold text-slate-800">
                      <span className="px-2 py-1 rounded-md bg-slate-100/90 border border-slate-200/70">
                        {ticket.ticket_number}
                      </span>
                    </td>
                    <td className="min-w-48 max-w-sm break-words px-5 py-4 font-semibold text-slate-900">
                      {ticket.title}
                    </td>
                    <td className="whitespace-nowrap px-5 py-4 text-slate-700 font-medium">
                      {ticket.apartment?.apartment_number || '—'}
                    </td>
                    <td className="px-5 py-4">
                      <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-xs ${
                        ticket.priority === 'URGENT' ? 'font-bold text-rose-700 bg-rose-50 border border-rose-200' :
                        ticket.priority === 'HIGH' ? 'font-semibold text-amber-700 bg-amber-50 border border-amber-200' :
                        'text-slate-600 bg-slate-50 border border-slate-200/60'
                      }`}>
                        {ticketPriorities[ticket.priority]}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-5 py-4">{statusBadge(ticket)}</td>
                    <td className="whitespace-nowrap px-5 py-4 text-xs text-slate-500 font-medium">
                      {new Date(ticket.created_at).toLocaleString('vi-VN')}
                    </td>
                    <td className="px-5 py-4">
                      <button className={`${button} whitespace-nowrap bg-white hover:bg-slate-50 text-slate-700 shadow-2xs`} disabled={busy} onClick={() => void open(ticket.id)}>
                        Xem chi tiết
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>

    {/* Pagination Bar */}
    <nav aria-label="Phân trang yêu cầu" className="flex flex-wrap items-center justify-between gap-3 text-sm rounded-2xl bg-white p-4 border border-slate-200/80 shadow-2xs">
      <span className="text-xs font-bold uppercase tracking-wider text-slate-500">{total} yêu cầu · 15 dòng/trang</span>
      <div className="flex items-center gap-3">
        <button className={button} disabled={loading || page <= 1} onClick={() => setPage(page - 1)}>
          Trước
        </button>
        <span aria-live="polite" className="text-xs font-bold text-slate-700 px-3 py-1.5 rounded-lg bg-slate-100 border border-slate-200/60">
          Trang {page}/{pages}
        </span>
        <button className={button} disabled={loading || page >= pages} onClick={() => setPage(page + 1)}>
          Sau
        </button>
      </div>
    </nav>

    {/* Detail Dialog */}
    <TicketDialog open={!!selected && !mode && !removeImageId} title={selected?.ticket_number || 'Chi tiết yêu cầu'} busy={busy} onClose={() => setSelected(null)}>
      {selected && (
        <div className="space-y-5">
          {error && <p role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 p-3.5 text-xs font-semibold text-rose-700">{error}</p>}
          <div className="space-y-2">
            <div>{statusBadge(selected)}</div>
            <h3 className="break-words text-lg font-bold text-slate-900 leading-snug">{selected.title}</h3>
          </div>
          <dl className="grid grid-cols-1 gap-3.5 rounded-2xl border border-slate-100 bg-slate-50/70 p-4 text-sm sm:grid-cols-2">
            {Object.entries({
              'Người báo': selected.creator?.full_name || '—',
              'Căn hộ': `${selected.apartment?.apartment_number || '—'}${selected.apartment?.block?.block_name ? ` · ${selected.apartment.block.block_name}` : ''}`,
              'Danh mục': selected.category?.category_name || options?.categories.find((item) => item.id === selected.category_id)?.category_name || '—',
              'Mức độ ưu tiên': ticketPriorities[selected.priority],
              'Vị trí': selected.location || '—',
              'Kỹ thuật viên': selected.technician?.full_name || 'Chưa phân công'
            }).map(([label, value]) => (
              <div key={label} className="space-y-0.5">
                <dt className="text-[11px] font-bold uppercase tracking-wider text-slate-500">{label}</dt>
                <dd className="break-words font-semibold text-slate-800">{value}</dd>
              </div>
            ))}
          </dl>
          <section className="space-y-2 rounded-2xl border border-slate-100 bg-slate-50/40 p-4">
            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700">Mô tả sự cố</h4>
            <p className="whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{selected.description}</p>
          </section>
          <section className="space-y-3">
            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700">
              Ảnh đính kèm ({selected.attachments?.length || 0}/5)
            </h4>
            {selected.attachments?.length ? (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {selected.attachments.map((att) => (
                  <div key={att.id} className="grid gap-1.5 rounded-xl border border-slate-200/80 bg-white p-2 shadow-2xs">
                    <TicketImage name={att.file_name} url={att.file_url} onPreview={setPreview} />
                    <p className="text-[11px] font-semibold text-slate-500 truncate">{att.is_resolution_proof ? 'Kết quả xử lý' : 'Hiện trường'}</p>
                    {selected.actions.edit && !busy && (
                      <button type="button" className="text-left text-xs font-semibold text-rose-600 hover:text-rose-700 underline cursor-pointer" onClick={() => { setError(''); setRemoveImageId(att.id); }}>
                        Bỏ ảnh {att.file_name}
                      </button>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-xs text-slate-500">Chưa có ảnh đính kèm.</p>
            )}
          </section>
          {selected.actions.resolve && (
            <label className="block space-y-2 text-xs font-bold uppercase tracking-wider text-slate-700">
              Ảnh kết quả xử lý
              <span className="block text-xs font-normal text-slate-500 normal-case">Tối đa 5 ảnh cho cả yêu cầu, JPG/PNG/WebP, mỗi ảnh tối đa 5 MB.</span>
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                multiple
                disabled={busy || (selected.attachments?.length || 0) >= 5}
                className="mt-2 block text-xs text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer"
                onChange={async (event) => {
                  const files = Array.from(event.target.files || []);
                  event.target.value = '';
                  if (!files.length) return;
                  if (files.length + (selected.attachments?.length || 0) > 5 || files.some((file) => file.size > 5 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
                    setError('Chỉ nhận tối đa 5 ảnh JPG, PNG, WebP cho mỗi yêu cầu, tối đa 5 MB/ảnh.');
                    return;
                  }
                  setBusy(true);
                  try {
                    await api.addTicketImages(selected.id, files, selected.version);
                    setSelected(await api.getManagedTicket(selected.id));
                  } catch (err) {
                    await fail(err);
                  } finally {
                    setBusy(false);
                  }
                }}
              />
            </label>
          )}
          <div className="flex flex-wrap gap-2.5 pt-2 border-t border-slate-100">
            {selected.actions.edit && (
              <button
                className={`${button} bg-white hover:bg-slate-50 text-slate-800 shadow-2xs`}
                disabled={busy}
                onClick={() => {
                  setForm({ ...blank, title: selected.title, description: selected.description, location: selected.location || '', category_id: selected.category_id, priority: selected.priority, preferred_service_time: selected.preferred_service_time?.slice(0, 16) || '', version: selected.version });
                  setFields({}); setError(''); setConflict(false); setMode('edit');
                }}
              >
                Sửa / Phân loại
              </button>
            )}
            {selected.actions.archive && (
              <button
                className={`${buttonBase} border-rose-200 bg-white text-rose-700 hover:bg-rose-50 shadow-2xs`}
                disabled={busy}
                onClick={() => { setReason(''); setForm({ ...blank, version: selected.version }); setError(''); setConflict(false); setMode('archive'); }}
              >
                Lưu trữ
              </button>
            )}
            {Object.entries(workflowLabels).filter(([key]) => selected.actions[key]).map(([key, label]) => (
              <button
                key={key}
                disabled={busy}
                className={`${buttonBase} ${workflowButtonStyles[key] || 'bg-blue-600 text-white hover:bg-blue-700'}`}
                onClick={async () => {
                  setAction(key); setReason(''); setTechnician(''); setError(''); setForm({ ...blank, version: selected.version });
                  if (key === 'assign') {
                    setBusy(true);
                    try { setTechnicians(await api.getTicketTechnicians(selected.id)); }
                    catch (err) { await fail(err); return; }
                    finally { setBusy(false); }
                  }
                  setFields({}); setConflict(false); setMode('workflow');
                }}
              >
                {label}
              </button>
            ))}
          </div>

          {/* Workflow Timeline Section */}
          <section className="space-y-3.5 border-t border-slate-100 pt-5">
            <div className="flex items-center justify-between">
              <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700">
                Tiến trình & Lịch sử xử lý
              </h4>
              {selected.history && selected.history.length > 0 && (
                <span className="text-[11px] font-semibold text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-full">
                  {selected.history.length} mốc sự kiện
                </span>
              )}
            </div>
            {selected.history?.length ? (
              <div className="relative pl-6 space-y-4 before:absolute before:left-2.5 before:top-2.5 before:bottom-2.5 before:w-0.5 before:bg-slate-200">
                {selected.history.map((entry, index) => {
                  const isLatest = index === 0;
                  return (
                    <div key={entry.id} className="relative">
                      <span
                        className={`absolute -left-6 top-1.5 flex h-5 w-5 items-center justify-center rounded-full ring-4 ring-white ${
                          isLatest
                            ? 'bg-blue-600 text-white shadow-2xs'
                            : 'bg-slate-300 text-slate-600'
                        }`}
                      >
                        <span className={`h-1.5 w-1.5 rounded-full ${isLatest ? 'bg-white animate-pulse' : 'bg-slate-500'}`} />
                      </span>
                      <div className={`rounded-2xl p-3.5 transition-colors ${
                        isLatest
                          ? 'bg-blue-50/70 border border-blue-200/70 shadow-2xs'
                          : 'bg-slate-50/60 border border-slate-200/60'
                      }`}>
                        <div className="flex flex-wrap items-center justify-between gap-1 mb-1">
                          <span className="text-xs font-bold text-slate-800">
                            {entry.performer?.full_name || 'Hệ thống'}
                          </span>
                          <time className="text-[11px] font-medium text-slate-500" dateTime={entry.created_at}>
                            {new Date(entry.created_at).toLocaleString('vi-VN')}
                          </time>
                        </div>
                        <p className="break-words text-sm leading-relaxed text-slate-700">
                          {entry.notes}
                        </p>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              <div className="rounded-2xl border border-dashed border-slate-200 p-6 text-center text-xs text-slate-500">
                Chưa có cập nhật xử lý.
              </div>
            )}
          </section>
        </div>
      )}
    </TicketDialog>

    {/* Form Modals (Workflow / Create / Edit / Archive) */}
    <TicketDialog
      open={!!mode}
      title={mode === 'workflow' ? workflowLabels[action] : mode === 'create' ? 'Thêm yêu cầu hộ cư dân' : mode === 'archive' ? 'Lưu trữ yêu cầu' : 'Sửa / Phân loại yêu cầu'}
      busy={busy}
      onClose={() => setMode(null)}
      footer={
        <footer className="shrink-0 flex flex-wrap justify-end gap-2 border-t border-slate-100 bg-white px-6 pb-4 pt-3">
          <button type="button" className={button} disabled={busy} onClick={() => setMode(null)}>
            Đóng
          </button>
          <button
            type="submit"
            form="ticket-management-form"
            className={`${buttonBase} ${
              mode === 'archive' || (mode === 'workflow' && action === 'cancel')
                ? 'border-rose-600 bg-rose-600 text-white hover:bg-rose-700'
                : mode === 'workflow' && workflowSubmitStyles[action]
                ? workflowSubmitStyles[action]
                : 'border-slate-950 bg-slate-950 text-white hover:bg-slate-800'
            }`}
            disabled={cannotSubmit}
          >
            {busy ? 'Đang lưu…' : mode === 'archive' ? 'Xác nhận lưu trữ' : mode === 'workflow' ? workflowLabels[action] : 'Lưu yêu cầu'}
          </button>
        </footer>
      }
    >
      <form id="ticket-management-form" onSubmit={submit} className="space-y-4">
        {error && <p role="alert" className="text-xs font-semibold text-rose-700 rounded-xl bg-rose-50 p-3">{error}</p>}
        {conflict && (
          <div role="status" className="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-xs font-medium text-amber-900">
            Yêu cầu đã được người khác cập nhật. Dữ liệu bạn nhập vẫn được giữ; hãy xem lại bản mới trước khi tiếp tục.{' '}
            <button type="button" className="min-h-11 font-bold underline ml-1 cursor-pointer" onClick={() => setMode(null)}>
              Xem bản mới nhất
            </button>
          </div>
        )}
        {mode === 'workflow' ? (
          <>
            <p className="text-sm font-medium text-slate-700">Xác nhận thao tác với {selected?.ticket_number}.</p>
            {action === 'assign' && (
              <CustomSelect
                overlay
                {...selectAppearance}
                label="Kỹ thuật viên"
                required
                disabled={!technicians.length}
                value={technician}
                onChange={setTechnician}
                options={technicians.map((user) => ({ value: user.id, label: user.full_name }))}
                placeholder={technicians.length ? 'Chọn kỹ thuật viên' : 'Chưa có kỹ thuật viên hợp lệ'}
              />
            )}
            {action === 'assign' && !technicians.length && (
              <p className="rounded-2xl border border-amber-200 bg-amber-50 p-3.5 text-xs font-semibold text-amber-900">
                Chưa có kỹ thuật viên đủ quyền trong tòa nhà này. Vui lòng kiểm tra hồ sơ nhân viên và quyền phân công.
              </p>
            )}
            <label className="block space-y-1.5 text-xs font-bold uppercase tracking-wider text-slate-700">
              {action === 'cancel' ? 'Lý do hủy (ít nhất 5 ký tự)' : action === 'resolve' ? 'Ghi chú khắc phục (ít nhất 5 ký tự)' : 'Ghi chú xử lý (không bắt buộc)'}
              <textarea
                required={['cancel', 'resolve'].includes(action)}
                minLength={5}
                maxLength={500}
                className={input}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            </label>
          </>
        ) : mode === 'archive' ? (
          <>
            <p className="text-sm text-slate-600">Giữ lại lịch sử và ảnh. Yêu cầu sẽ được ẩn khỏi danh sách đang sử dụng.</p>
            <label className="block space-y-1.5 text-xs font-bold uppercase tracking-wider text-slate-700">
              Lý do lưu trữ
              <textarea required minLength={5} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} className={input} />
            </label>
          </>
        ) : (
          <>
            {mode === 'create' && <p className="text-xs text-slate-500">Chọn tòa nhà, căn hộ và cư dân cần hỗ trợ. Các trường có dấu * là bắt buộc.</p>}
            {mode === 'create' && (
              <div className="grid gap-3.5 sm:grid-cols-2">
                <CustomSelect overlay {...selectAppearance} label="Tòa nhà" required value={form.block_id} onChange={(value) => setForm({ ...form, block_id: value, apartment_id: '', reporter_user_id: '' })} options={options?.blocks.map((b) => ({ value: b.id, label: b.block_name })) || []} />
                <CustomSelect overlay {...selectAppearance} label="Căn hộ" required disabled={!form.block_id} value={form.apartment_id} onChange={(value) => setForm({ ...form, apartment_id: value, reporter_user_id: '' })} options={options?.apartments.filter((a) => a.block_id === form.block_id).map((a) => ({ value: a.id, label: a.apartment_number })) || []} />
                <CustomSelect overlay {...selectAppearance} label="Người báo" required disabled={!form.apartment_id} value={form.reporter_user_id} onChange={(value) => setForm({ ...form, reporter_user_id: value })} options={options?.residents.filter((r) => r.apartment_id === form.apartment_id).map((r) => ({ value: r.id, label: r.full_name })) || []} />
              </div>
            )}
            {(mode === 'create' || selected?.status === 'NEW') && (
              <>
                {field('title', 'Tiêu đề')}
                {field('location', 'Vị trí')}
                {field('description', 'Mô tả', true)}
                <label className="block space-y-1.5 text-xs font-bold uppercase tracking-wider text-slate-700">
                  Thời gian thuận tiện
                  <input type="datetime-local" className={input} value={form.preferred_service_time} onChange={(e) => setForm({ ...form, preferred_service_time: e.target.value })} />
                </label>
              </>
            )}
            <div className="grid gap-3.5 sm:grid-cols-2">
              <CustomSelect overlay {...selectAppearance} label="Danh mục" required value={form.category_id} onChange={(value) => setForm({ ...form, category_id: value })} options={options?.categories.map((c) => ({ value: c.id, label: c.category_name })) || []} />
              <CustomSelect overlay {...selectAppearance} label="Mức độ ưu tiên" value={form.priority} onChange={(value) => setForm({ ...form, priority: value })} options={selectOptions(ticketPriorities)} />
            </div>
            {Object.entries(fields).filter(([key]) => !['title', 'location', 'description'].includes(key)).map(([key, errors]) => (
              <p key={key} className="text-xs text-rose-700">{errors[0]}</p>
            ))}
          </>
        )}
      </form>
    </TicketDialog>

    {/* Remove Image Confirmation Dialog */}
    <TicketDialog open={!!removeImageId} title="Bỏ ảnh sự cố" busy={busy} onClose={() => setRemoveImageId(null)}>
      {error && <p role="alert" className="mb-3 text-xs font-semibold text-rose-700">{error}</p>}
      <p className="text-sm text-slate-700">Bạn muốn bỏ ảnh này khỏi yêu cầu? Ảnh đã bỏ không thể khôi phục từ màn hình này.</p>
      <div className="mt-5 flex justify-end gap-2.5">
        <button type="button" disabled={busy} className={button} onClick={() => setRemoveImageId(null)}>
          Đóng
        </button>
        <button
          type="button"
          disabled={busy}
          className={`${buttonBase} bg-rose-600 text-white border-rose-600 hover:bg-rose-700`}
          onClick={async () => {
            if (!selected || !removeImageId || busy) return;
            setBusy(true); setError('');
            try {
              setSelected(await api.removeManagedTicketImage(selected.id, removeImageId, selected.version));
              setRemoveImageId(null);
              setMessage('Đã bỏ ảnh sự cố.');
              reload();
            } catch (err) {
              await fail(err);
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? 'Đang bỏ ảnh…' : 'Xác nhận bỏ ảnh'}
        </button>
      </div>
    </TicketDialog>

    {/* Image Preview Dialog */}
    <TicketDialog open={!!preview} title="Ảnh sự cố" onClose={() => setPreview('')}>
      <img src={preview} alt="Ảnh sự cố phóng to" className="max-h-[70vh] w-full object-contain rounded-xl" />
    </TicketDialog>
  </section>;
}

