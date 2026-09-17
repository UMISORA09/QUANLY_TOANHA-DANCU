import React, { useState, useEffect, useCallback, useMemo } from 'react';
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

interface AmenityBookingsModalProps {
  isOpen: boolean;
  amenity: Amenity | null;
  onClose: () => void;
}

export const AmenityBookingsModal: React.FC<AmenityBookingsModalProps> = ({
  isOpen,
  amenity,
  onClose,
}) => {
  const [bookings, setBookings] = useState<AmenityBooking[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('ALL');
  const [error, setError] = useState<string | null>(null);
  const [updatingId, setUpdatingId] = useState<string | null>(null);

  const fetchBookings = useCallback(async (force = true) => {
    if (!amenity) return;
    try {
      setLoading(true);
      setError(null);
      const data = await api.getAmenityBookings(amenity.id, force);
      setBookings(data);
    } catch (err: any) {
      setError(err?.message || 'Không thể tải danh sách đặt chỗ từ cơ sở dữ liệu.');
    } finally {
      setLoading(false);
    }
  }, [amenity]);

  useEffect(() => {
    if (isOpen && amenity) {
      fetchBookings(true);
    } else {
      setBookings([]);
      setSearch('');
      setStatusFilter('ALL');
      setError(null);
    }
  }, [isOpen, amenity, fetchBookings]);

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

  const handleUpdateStatus = async (bookingId: string, status: string) => {
    if (!amenity) return;
    try {
      setUpdatingId(bookingId);
      await api.patchAmenityBookingStatus(amenity.id, bookingId, status);
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
      return matchStatus && matchSearch;
    });
  }, [bookings, search, statusFilter]);

  const stats = useMemo(() => {
    const total = bookings.length;
    const active = bookings.filter((b) =>
      ['PENDING', 'APPROVED', 'CONFIRMED'].includes(b.status?.toUpperCase())
    ).length;
    const totalAttendees = bookings.reduce((sum, b) => sum + (b.attendee_count || 1), 0);
    const totalRevenue = bookings.reduce((sum, b) => sum + (b.total_amount || 0), 0);
    return { total, active, totalAttendees, totalRevenue };
  }, [bookings]);

  if (!isOpen || !amenity) return null;

  const formatCurrency = (val?: number | string) => {
    const num = Number(val) || 0;
    if (num === 0) return 'Miễn phí';
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
            Đã hủy
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
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-900/50 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="relative w-full max-w-5xl bg-white/95 backdrop-blur-2xl border border-white/90 rounded-3xl shadow-2xl p-6 flex flex-col max-h-[92vh] overflow-hidden text-neutral-900 animate-in zoom-in-95">
        {/* Header */}
        <div className="flex items-start justify-between pb-4 border-b border-neutral-200/80 gap-3">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-sky-50 border border-sky-200 text-sky-600 flex items-center justify-center shrink-0 shadow-xs">
              <Ticket className="w-5 h-5" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-base sm:text-lg font-bold text-neutral-900">
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
              disabled={loading}
              className="p-2 rounded-xl bg-neutral-100 hover:bg-neutral-200 text-neutral-600 transition-all cursor-pointer"
              title="Làm mới dữ liệu từ Database"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            </button>
            <button
              type="button"
              onClick={onClose}
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
            <span className="text-[11px] font-medium text-purple-600 block">Doanh thu ghi nhận</span>
            <span className="text-sm font-extrabold text-purple-900 mt-1 block truncate">
              {formatCurrency(stats.totalRevenue)}
            </span>
          </div>
        </div>

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

          <div className="flex items-center gap-2 w-full sm:w-auto">
            <Filter className="w-3.5 h-3.5 text-neutral-400" />
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="text-xs bg-neutral-50 border border-neutral-200 rounded-xl px-2.5 py-1.5 focus:bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 cursor-pointer"
            >
              <option value="ALL">Tất cả trạng thái</option>
              <option value="PENDING">Chờ duyệt</option>
              <option value="APPROVED">Đã duyệt</option>
              <option value="COMPLETED">Hoàn tất</option>
              <option value="CANCELLED">Đã hủy</option>
            </select>
          </div>
        </div>

        {/* Error Alert */}
        {error && (
          <div className="mb-3 p-3 bg-rose-50 border border-rose-200 rounded-xl flex items-center gap-2 text-rose-700 text-xs">
            <AlertCircle className="w-4 h-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {/* Bookings Table Body */}
        <div className="flex-1 overflow-y-auto custom-scrollbar border border-neutral-200/80 rounded-2xl bg-white">
          <table className="w-full text-left border-collapse text-xs">
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
                    </td>

                    {/* Ghi chú */}
                    <td className="py-3 px-3 text-neutral-500 max-w-xs truncate" title={b.resident_notes || ''}>
                      {b.resident_notes || '-'}
                    </td>

                    {/* Thao tác */}
                    <td className="py-3 px-3 text-right whitespace-nowrap">
                      <div className="flex items-center justify-end gap-1.5">
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
                        {['PENDING', 'APPROVED', 'CONFIRMED'].includes(b.status?.toUpperCase()) && (
                          <button
                            type="button"
                            disabled={updatingId === b.id}
                            onClick={() => handleUpdateStatus(b.id, 'CANCELLED')}
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

        {/* Footer */}
        <div className="mt-4 pt-3 border-t border-neutral-200/80 flex items-center justify-between text-xs text-neutral-500">
          <span>
            Hiển thị <strong className="text-neutral-900">{filteredBookings.length}</strong> / {bookings.length} lượt đặt chỗ
          </span>
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 rounded-xl bg-neutral-900 hover:bg-neutral-800 text-white font-semibold transition-all cursor-pointer shadow-xs"
          >
            Đóng
          </button>
        </div>
      </div>
    </div>
  );
};
