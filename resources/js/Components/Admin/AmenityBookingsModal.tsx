import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import {
  X,
  Calendar,
  Clock,
  Users,
  Search,
  RefreshCw,
  Ticket,
  CheckCircle2,
  AlertCircle,
  QrCode,
  DollarSign,
  Building,
  Filter,
  Check,
  Ban
} from 'lucide-react';
import { api, Amenity, AmenityBooking } from '../../Services/api';
import { amenityCache } from '../../Services/amenityCache';
import { AmenityPaymentDialog, paymentStatusNames } from '../AmenityPaymentDialog';

interface AmenityBookingsModalProps {
  isOpen: boolean;
  amenity: Amenity | null;
  onClose: () => void;
  initialSearch?: string;
}

export const AmenityBookingsModal: React.FC<AmenityBookingsModalProps> = ({
  isOpen,
  amenity,
  onClose,
  initialSearch = '',
}) => {
  const [bookings, setBookings] = useState<AmenityBooking[]>([]);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const bookingRequest = useRef<AbortController | null>(null);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('ALL');
  const [paymentFilter, setPaymentFilter] = useState('ALL');
  const [paymentBooking, setPaymentBooking] = useState<AmenityBooking | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [updatingId, setUpdatingId] = useState<string | null>(null);
  const [rejectingId, setRejectingId] = useState<string | null>(null);
  const [rejectionReason, setRejectionReason] = useState('');
  const rejectionDialog = useRef<HTMLDialogElement>(null);
  const cancellationDialog = useRef<HTMLDialogElement>(null);
  const bookingsDialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    if (isOpen && amenity) bookingsDialog.current?.showModal();
    else bookingsDialog.current?.close();
  }, [isOpen, amenity]);
  const [cancellation, setCancellation] = useState<AmenityBooking | null>(null);
  const [cancelReason, setCancelReason] = useState('');
  useEffect(() => {
    if (isOpen && cancellation) cancellationDialog.current?.showModal();
    else cancellationDialog.current?.close();
    if (!isOpen) setCancellation(null);
  }, [isOpen, cancellation]);

  useEffect(() => {
    if (isOpen && rejectingId) rejectionDialog.current?.showModal();
    else rejectionDialog.current?.close();
    if (!isOpen) setRejectingId(null);
  }, [isOpen, rejectingId]);

  const fetchBookings = useCallback(async (force = true) => {
    if (!amenity) return;
    bookingRequest.current?.abort();
    const controller = new AbortController();
    bookingRequest.current = controller;
    const cached = amenityCache.get<AmenityBooking[]>(`amenity:${amenity.id}:bookings`);
    if (cached.exists && cached.data) setBookings(cached.data);
    try {
      setLoading(!cached.exists);
      setSyncing(true);
      setError(null);
      const data = await api.getAmenityBookings(amenity.id, force, controller.signal);
      if (!controller.signal.aborted) setBookings(data);
    } catch (err: any) {
      if (!controller.signal.aborted) {
        if ([401, 403].includes(err?.status)) setBookings([]);
        setError(err?.message || 'Không thể tải danh sách đặt chỗ từ cơ sở dữ liệu.');
      }
    } finally {
      if (!controller.signal.aborted) { setLoading(false); setSyncing(false); }
    }
  }, [amenity]);

  useEffect(() => {
    if (isOpen && amenity) {
      setSearch(initialSearch);
      if (initialSearch) { setStatusFilter('ALL'); setPaymentFilter('ALL'); }
      setSuccessMessage(null);
      fetchBookings(true);
    } else {
      setBookings([]);
      setSearch('');
      setStatusFilter('ALL');
      setPaymentFilter('ALL');
      setPaymentBooking(null);
      setError(null);
    }
    return () => bookingRequest.current?.abort();
  }, [isOpen, amenity, fetchBookings, initialSearch]);

  // Lắng nghe sự kiện đồng bộ đặt chỗ từ tab khác (Cross-Tab Synchronization)
  useEffect(() => {
    if (!isOpen || !amenity) return;

    const unsubscribe = amenityCache.subscribe((event) => {
      if (event.amenityId === amenity.id) {
        if (event.type === 'AMENITY_DELETED') {
          onClose();
        } else if (event.type === 'AMENITY_BOOKING_CHANGED') {
          fetchBookings(true);
        }
      }
    });

    return () => {
      unsubscribe();
    };
  }, [isOpen, amenity, onClose, fetchBookings]);

  const handleUpdateStatus = async (bookingId: string, status: string, reason?: string) => {
    if (!amenity) return;
    try {
      setUpdatingId(bookingId);
      setError(null);
      setSuccessMessage(null);
      if (status === 'CANCELLED') await api.cancelAmenityBooking(amenity.id, bookingId, reason);
      else await api.patchAmenityBookingStatus(amenity.id, bookingId, status, reason);
      setSuccessMessage(status === 'APPROVED' ? 'Đã duyệt đăng ký tiện ích thành công.' : status === 'REJECTED' ? 'Đã từ chối đăng ký tiện ích.' : status === 'CANCELLED' ? 'Đã hủy đăng ký tiện ích thành công.' : 'Đã cập nhật trạng thái đăng ký thành công.');
      if (status === 'REJECTED') setRejectingId(null);
      if (status === 'CANCELLED') setCancellation(null);
      await fetchBookings(true);
    } catch (err: any) {
      setError(err?.message || 'Không thể cập nhật trạng thái đặt chỗ.');
    } finally {
      setUpdatingId(null);
    }
  };

  const filteredBookings = useMemo(() => {
    return bookings.filter((b) => {
      const matchStatus =
        statusFilter === 'ALL' || b.status?.toUpperCase() === statusFilter;
      const term = search.toLowerCase().trim();
      const matchSearch =
        !term ||
        b.booking_code.toLowerCase().includes(term) ||
        b.resident_name.toLowerCase().includes(term) ||
        (b.apartment_number && b.apartment_number.toLowerCase().includes(term)) ||
        (b.resident_phone && b.resident_phone.includes(term));
      return matchStatus && matchSearch && (paymentFilter === 'ALL' || b.payment?.status === paymentFilter);
    });
  }, [bookings, search, statusFilter, paymentFilter]);

  const stats = useMemo(() => {
    const total = bookings.length;
    const active = bookings.filter((b) =>
      ['PENDING', 'APPROVED', 'CONFIRMED', 'CHECKED_IN'].includes(b.status?.toUpperCase())
    ).length;
    const totalAttendees = bookings.reduce((sum, b) => sum + (b.attendee_count || 1), 0);
    const totalReceived = bookings.reduce((sum, b) => sum + (b.payment?.received_amount ?? (b.is_paid ? b.total_amount + b.deposit_amount : 0)), 0);
    const retained = bookings.filter((b) => b.is_paid && !b.payment?.refund_required && !['CANCELLED', 'REJECTED'].includes(b.status));
    const feeReceived = retained.reduce((sum, b) => sum + b.total_amount, 0);
    const depositsHeld = retained.reduce((sum, b) => sum + b.deposit_amount, 0);
    const refundPending = bookings.filter((b) => b.payment?.refund_required || (!b.payment && b.is_paid && ['CANCELLED', 'REJECTED'].includes(b.status))).reduce((sum, b) => sum + (b.payment?.received_amount ?? b.total_amount + b.deposit_amount), 0);
    const pending = bookings.filter((b) => b.status?.toUpperCase() === 'PENDING').length;
    const bookingValue = bookings.filter((b) => !['CANCELLED', 'REJECTED'].includes(b.status)).reduce((sum, b) => sum + b.total_amount + b.deposit_amount, 0);
    return { total, active, totalAttendees, totalReceived, feeReceived, depositsHeld, refundPending, pending, bookingValue };
  }, [bookings]);

  if (!isOpen || !amenity) return null;

  const formatCurrency = (val?: number | string) => {
    const num = Number(val) || 0;
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(num);
  };

  const getStatusBadge = (status: string) => {
    const s = status?.toUpperCase();
    switch (s) {
      case 'APPROVED':
      case 'CONFIRMED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
            Đã duyệt
          </span>
        );
      case 'PENDING':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
            <span className="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse" />
            Chờ duyệt
          </span>
        );
      case 'COMPLETED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-sky-50 text-sky-700 border border-sky-200">
            <CheckCircle2 className="w-3 h-3" />
            Hoàn tất
          </span>
        );
      case 'CANCELLED':
      case 'REJECTED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
            <span className="w-1.5 h-1.5 rounded-full bg-rose-500" />
            {s === 'REJECTED' ? 'Bị từ chối' : 'Đã hủy'}
          </span>
        );
      default:
        return (
          <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700">
            {status}
          </span>
        );
    }
  };

  return (
    <dialog ref={bookingsDialog} aria-labelledby="amenity-bookings-title" onCancel={(event) => { event.preventDefault(); if (!updatingId) onClose(); }} className="amenity-booking-controls m-auto w-[calc(100%-2rem)] max-w-5xl max-h-[92vh] rounded-3xl border-0 p-0 shadow-2xl backdrop:bg-slate-950/50">
      <div className="relative w-full bg-white p-4 sm:p-6 flex flex-col max-h-[92vh] overflow-y-auto text-neutral-900">
        {/* Header */}
        <div className="flex items-start justify-between pb-4 border-b border-neutral-200/80 gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-sky-50 border border-sky-200 text-sky-600 flex items-center justify-center shrink-0 shadow-xs">
              <Ticket className="w-5 h-5" />
            </div>
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h2 id="amenity-bookings-title" className="text-base sm:text-lg font-bold text-neutral-900">
                  Thông tin đặt chỗ: {amenity.amenity_name}
                </h2>
                <span className="font-mono text-xs px-2 py-0.5 rounded bg-neutral-100 border border-neutral-200 font-bold text-neutral-700">
                  {amenity.amenity_code}
                </span>
              </div>
              <p className="text-xs text-neutral-400 mt-0.5 flex items-center gap-2">
                <span>Vị trí: {amenity.location_detail}</span>
                <span>•</span>
                <span>Sức chứa: {amenity.max_capacity_per_slot} người/lượt</span>
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => fetchBookings()}
              disabled={loading || syncing}
              className="p-2 rounded-xl bg-neutral-100 hover:bg-neutral-200 text-neutral-600 transition-all cursor-pointer"
              title="Làm mới dữ liệu từ Database"
            >
              <RefreshCw className={`w-4 h-4 ${loading || syncing ? 'animate-spin' : ''}`} />
            </button>
            <button
              type="button"
              onClick={onClose}
              aria-label="Đóng danh sách đăng ký"
              disabled={Boolean(updatingId)}
              className="p-2 rounded-xl text-neutral-400 hover:text-neutral-700 hover:bg-neutral-100 transition-all cursor-pointer"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* 4 Stats Cards */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 my-4">
          <div className="p-3 bg-neutral-50/80 border border-neutral-200/70 rounded-2xl">
            <span className="text-[11px] font-medium text-neutral-500 block">Tổng lượt đặt</span>
            <span className="text-lg font-extrabold text-neutral-900 mt-0.5 block">{stats.total}</span>
          </div>
          <div className="p-3 bg-sky-50/80 border border-sky-100 rounded-2xl">
            <span className="text-[11px] font-medium text-sky-600 block">Đang hoạt động</span>
            <span className="text-lg font-extrabold text-sky-900 mt-0.5 block">{stats.active}</span>
          </div>
          <div className="p-3 bg-emerald-50/80 border border-emerald-100 rounded-2xl">
            <span className="text-[11px] font-medium text-emerald-600 block">Tổng người tham gia</span>
            <span className="text-lg font-extrabold text-emerald-900 mt-0.5 block">{stats.totalAttendees}</span>
          </div>
          <div className="p-3 bg-purple-50/80 border border-purple-100 rounded-2xl">
            <span className="text-[11px] font-medium text-purple-600 block">Đã nhận phí và cọc</span>
            <span className="text-sm font-extrabold text-purple-900 mt-1 block truncate">
              {formatCurrency(stats.totalReceived)}
            </span>
          </div>
        </div>

        {successMessage && <p role="status" className="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{successMessage}</p>}
        <div className="mb-3 flex flex-wrap items-center gap-3">
          <button type="button" aria-pressed={statusFilter === 'PENDING'} onClick={() => { setStatusFilter(statusFilter === 'PENDING' ? 'ALL' : 'PENDING'); setPaymentFilter('ALL'); setSearch(''); }} className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">Chờ duyệt ({stats.pending})</button>
          <p className="text-xs text-neutral-500">Duyệt đăng ký trước, sau đó đối soát khoản thanh toán của cư dân.</p>
        </div>
        <dl className="mb-4 grid grid-cols-1 gap-2 rounded-xl border border-neutral-200 bg-neutral-50 p-3 text-xs sm:grid-cols-2 lg:grid-cols-4"><div><dt>Giá trị đăng ký chưa hủy (phí và cọc)</dt><dd className="mt-1 font-bold">{formatCurrency(stats.bookingValue)}</dd></div><div><dt>Phí đã thanh toán, còn hiệu lực</dt><dd className="mt-1 font-bold">{formatCurrency(stats.feeReceived)}</dd></div><div><dt>Tiền cọc đang giữ</dt><dd className="mt-1 font-bold">{formatCurrency(stats.depositsHeld)}</dd></div><div><dt>Cần xử lý hoàn tiền</dt><dd className="mt-1 font-bold text-amber-800">{formatCurrency(stats.refundPending)}</dd></div></dl>
        {syncing && !loading && <p role="status" className="mb-2 text-xs text-neutral-500">Đang cập nhật dữ liệu từ máy chủ…</p>}
        {/* Filter Toolbar */}
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pb-3">
          <div className="relative w-full sm:w-72">
            <Search className="w-3.5 h-3.5 text-neutral-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              placeholder="Tìm mã booking, cư dân, căn hộ..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-8 pr-3 py-1.5 text-xs bg-neutral-50 border border-neutral-200 rounded-xl focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto">
            <Filter className="w-3.5 h-3.5 text-neutral-400" />
            <select aria-label="Lọc thanh toán tiện ích" value={paymentFilter} onChange={(event) => setPaymentFilter(event.target.value)} className="amenity-booking-select rounded-xl border border-neutral-200 bg-neutral-50 px-2 py-1.5 text-xs"><option value="ALL">Tất cả thanh toán</option>{Object.entries(paymentStatusNames).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
            <select
              aria-label="Lọc trạng thái đăng ký"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="amenity-booking-select text-xs bg-neutral-50 border border-neutral-200 rounded-xl px-2.5 py-1.5 focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 cursor-pointer"
            >
              <option value="ALL">Tất cả trạng thái</option>
              <option value="PENDING">Chờ duyệt</option>
              <option value="APPROVED">Đã duyệt</option>
              <option value="CONFIRMED">Đã xác nhận</option>
              <option value="CHECKED_IN">Đang sử dụng</option>
              <option value="COMPLETED">Hoàn tất</option>
              <option value="CANCELLED">Đã hủy</option>
              <option value="REJECTED">Bị từ chối</option>
            </select>
          </div>
        </div>

        {/* Error Alert */}
        {error && (
          <div role="alert" className="mb-3 p-3 bg-rose-50 border border-rose-200 rounded-xl flex items-center gap-2 text-rose-700 text-xs">
            <AlertCircle className="w-4 h-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {/* Bookings Table Body */}
        <div role="region" aria-label="Bảng đăng ký tiện ích" tabIndex={0} className="amenity-booking-table min-h-[180px] max-h-[55vh] flex-1 overflow-auto border border-neutral-200/80 rounded-2xl bg-white">
          <table className="w-full min-w-[1100px] text-left border-collapse text-xs">
            <thead className="sticky top-0 bg-neutral-50/95 backdrop-blur-xs border-b border-neutral-200/80 text-[11px] font-bold text-neutral-600 uppercase tracking-wider z-10">
              <tr>
                <th className="py-3 px-3.5">Mã Booking</th>
                <th className="py-3 px-3">Cư dân</th>
                <th className="py-3 px-3">Căn hộ</th>
                <th className="py-3 px-3">Thời gian đặt</th>
                <th className="py-3 px-3 text-center">Số người</th>
                <th className="py-3 px-3">Chi phí / Cọc</th>
                <th className="py-3 px-3 text-center">Trạng thái</th>
                <th className="py-3 px-3">Ghi chú</th>
                <th className="py-3 px-3 text-right">Thao tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-neutral-100">
              {loading ? (
                <tr>
                  <td colSpan={9} className="py-12 text-center text-neutral-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <span className="w-6 h-6 border-2 border-neutral-300 border-t-neutral-900 rounded-full animate-spin" />
                      <span className="text-xs">Đang tải danh sách đặt chỗ từ Database...</span>
                    </div>
                  </td>
                </tr>
              ) : filteredBookings.length === 0 ? (
                <tr>
                  <td colSpan={9} className="py-12 text-center text-neutral-400">
                    <div className="flex flex-col items-center justify-center gap-1.5">
                      <Ticket className="w-8 h-8 text-neutral-300 stroke-1" />
                      <p className="font-semibold text-neutral-700">Chưa có lượt đặt chỗ nào</p>
                      <p className="text-[11px] text-neutral-400">
                        {search || statusFilter !== 'ALL'
                          ? 'Không tìm thấy kết quả phù hợp với bộ lọc.'
                          : 'Hiện chưa có cư dân nào đăng ký sử dụng tiện ích này.'}
                      </p>
                    </div>
                  </td>
                </tr>
              ) : (
                filteredBookings.map((b) => (
                  <tr key={b.id} className="hover:bg-neutral-50/70 transition-colors">
                    {/* Mã booking & QR code */}
                    <td className="py-3 px-3.5 whitespace-nowrap">
                      <div className="flex items-center gap-1.5">
                        <span className="font-mono font-bold text-neutral-900">{b.booking_code}</span>
                      </div>
                      <div className="text-[10px] text-neutral-400 font-mono mt-0.5">
                        {b.checkin_qr_code}
                      </div>
                    </td>

                    {/* Cư dân */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      <div className="font-semibold text-neutral-900">{b.resident_name}</div>
                      {b.resident_phone && (
                        <div className="text-[10px] text-neutral-400 font-mono">{b.resident_phone}</div>
                      )}
                    </td>

                    {/* Căn hộ */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-neutral-100 font-medium text-neutral-700">
                        <Building className="w-3 h-3 text-neutral-400" />
                        {b.apartment_number || 'Chưa gán'}
                      </span>
                    </td>

                    {/* Ngày & Giờ */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      <div className="font-medium text-neutral-800 flex items-center gap-1">
                        <Calendar className="w-3 h-3 text-sky-500" />
                        {b.booking_date}
                      </div>
                      <div className="text-[10px] text-neutral-400 flex items-center gap-1 mt-0.5">
                        <Clock className="w-3 h-3 text-neutral-400" />
                        {b.start_time} - {b.end_time}
                      </div>
                    </td>

                    {/* Số người */}
                    <td className="py-3 px-3 text-center whitespace-nowrap">
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 font-semibold text-neutral-800">
                        <Users className="w-3 h-3 text-slate-500" />
                        {b.attendee_count}
                      </span>
                    </td>

                    {/* Chi phí & Cọc */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      <div className="font-semibold text-neutral-900">
                        {formatCurrency(b.total_amount)}
                      </div>
                      {b.deposit_amount > 0 && (
                        <div className="text-[10px] text-neutral-400">
                          Cọc: {formatCurrency(b.deposit_amount)}
                        </div>
                      )}
                    </td>

                    {/* Trạng thái */}
                    <td className="py-3 px-3 text-center whitespace-nowrap">
                      {getStatusBadge(b.status)}
                      {b.payment && <p className="mt-2 text-[11px] text-neutral-600">{paymentStatusNames[b.payment.status]}</p>}
                    </td>

                    {/* Ghi chú */}
                    <td className="py-3 px-3 text-neutral-500 max-w-xs truncate" title={b.resident_notes || ''}>
                      {b.resident_notes || '-'}
                    </td>

                    {/* Thao tác */}
                    <td className="py-3 px-3 text-right whitespace-nowrap">
                      <div className="flex items-center justify-end gap-1.5">{b.payment && <button type="button" onClick={() => setPaymentBooking(b)} className="rounded-lg bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-800">Đối soát</button>}
                        {['PENDING'].includes(b.status?.toUpperCase()) && (
                          <button
                            type="button"
                            disabled={updatingId === b.id}
                            onClick={() => handleUpdateStatus(b.id, 'APPROVED')}
                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-semibold text-[11px] transition-colors cursor-pointer disabled:opacity-50"
                            title="Duyệt đặt chỗ này"
                          >
                            <Check className="w-3 h-3" />
                            <span>Duyệt</span>
                          </button>
                        )}
                        {b.status === 'PENDING' && <button type="button" disabled={updatingId === b.id} onClick={() => { setRejectingId(b.id); setRejectionReason(''); setError(null); }} className="rounded-lg bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-rose-700 disabled:opacity-50">Từ chối</button>}
                        {['PENDING', 'APPROVED', 'CONFIRMED'].includes(b.status?.toUpperCase()) && (
                          <button
                            type="button"
                            disabled={updatingId === b.id}
                            onClick={() => { setCancellation(b); setCancelReason(''); setError(null); }}
                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold text-[11px] transition-colors cursor-pointer disabled:opacity-50"
                            title="Hủy đặt chỗ này"
                          >
                            <Ban className="w-3 h-3" />
                            <span>Hủy</span>
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <dialog ref={rejectionDialog} aria-labelledby="amenity-rejection-title" onCancel={(event) => { if (updatingId === rejectingId) event.preventDefault(); else setRejectingId(null); }} onClose={() => setRejectingId(null)} className="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-neutral-200 bg-white p-6 shadow-xl backdrop:bg-neutral-950/50">
          <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); if (rejectingId && !updatingId) void handleUpdateStatus(rejectingId, 'REJECTED', rejectionReason.trim()); }}>
            <h3 id="amenity-rejection-title" className="font-bold text-neutral-900">Từ chối đăng ký tiện ích</h3>
            <label className="block space-y-2 text-sm">Lý do từ chối<textarea required maxLength={500} value={rejectionReason} onChange={(event) => setRejectionReason(event.target.value)} disabled={Boolean(updatingId)} className="w-full rounded-xl border border-neutral-200 p-3" /></label>
            {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
            <div className="flex justify-end gap-2"><button type="button" disabled={Boolean(updatingId)} onClick={() => setRejectingId(null)} className="rounded-lg border px-3 py-2 text-sm">Đóng</button><button type="submit" disabled={Boolean(updatingId) || !rejectionReason.trim()} className="rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{updatingId ? 'Đang xử lý…' : 'Xác nhận từ chối'}</button></div>
          </form>
        </dialog>
        <dialog ref={cancellationDialog} aria-labelledby="amenity-cancellation-title" onCancel={(event) => { if (updatingId) event.preventDefault(); else setCancellation(null); }} onClose={() => setCancellation(null)} className="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-neutral-200 bg-white p-6 shadow-xl backdrop:bg-neutral-950/50"><form className="space-y-4" onSubmit={(event) => { event.preventDefault(); if (cancellation && !updatingId) void handleUpdateStatus(cancellation.id, 'CANCELLED', cancelReason.trim()); }}><h3 id="amenity-cancellation-title" className="font-bold">Hủy đăng ký tiện ích</h3><p className="text-sm">Hủy {cancellation?.booking_code}? Chỗ đã đăng ký sẽ được giải phóng.</p>{(cancellation?.is_paid || cancellation?.payment?.received_amount || cancellation?.payment?.status === 'REPORTED') && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Cư dân đã thanh toán hoặc báo chuyển khoản. Bạn cần đối soát và xử lý hoàn tiền sau khi hủy.</p>}<label className="block space-y-2 text-sm">Lý do hủy của quản lý<textarea required maxLength={500} disabled={Boolean(updatingId)} value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} className="w-full rounded-xl border border-neutral-200 p-3" /></label>{error && <p role="alert" className="text-sm text-rose-700">{error}</p>}<div className="flex justify-end gap-2"><button type="button" disabled={Boolean(updatingId)} onClick={() => setCancellation(null)} className="rounded-xl border px-3 py-2 text-sm">Giữ đăng ký</button><button type="submit" disabled={Boolean(updatingId) || !cancelReason.trim()} className="rounded-xl bg-rose-600 px-3 py-2 text-sm font-semibold text-white disabled:opacity-40">{updatingId ? 'Đang hủy…' : 'Xác nhận hủy'}</button></div></form></dialog>
        {/* Footer */}
        {paymentBooking && <AmenityPaymentDialog key={paymentBooking.id} manager booking={paymentBooking} onClose={() => setPaymentBooking(null)} onChanged={() => void fetchBookings(true)} />}
        <div className="mt-4 pt-3 border-t border-neutral-200/80 flex items-center justify-between text-xs text-neutral-500">
          <span>
            Hiển thị <strong className="text-neutral-900">{filteredBookings.length}</strong> / {bookings.length} lượt đặt chỗ
          </span>
          <button
            type="button"
            onClick={onClose}
            disabled={Boolean(updatingId)}
            className="px-4 py-2 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white font-semibold transition-all cursor-pointer shadow-xs"
          >
            Đóng
          </button>
        </div>
      </div>
    </dialog>
  );
};
