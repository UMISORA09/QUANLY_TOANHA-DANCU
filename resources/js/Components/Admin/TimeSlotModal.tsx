import React, { useState, useEffect } from 'react';
import { X, Plus, Edit2, Trash2, Clock, Check, AlertCircle, Sparkles, Power } from 'lucide-react';
import { api, TimeSlot, Amenity } from '../../Services/api';

interface TimeSlotModalProps {
  isOpen: boolean;
  amenity: Amenity | null;
  onClose: () => void;
  onChanged: () => void;
}

const DAYS_OF_WEEK = [
  { value: 1, label: 'Thứ 2' },
  { value: 2, label: 'Thứ 3' },
  { value: 3, label: 'Thứ 4' },
  { value: 4, label: 'Thứ 5' },
  { value: 5, label: 'Thứ 6' },
  { value: 6, label: 'Thứ 7' },
  { value: 0, label: 'Chủ nhật' },
];

export const TimeSlotModal: React.FC<TimeSlotModalProps> = ({
  isOpen,
  amenity,
  onClose,
  onChanged,
}) => {
  const [slots, setSlots] = useState<TimeSlot[]>([]);
  const [loading, setLoading] = useState(false);

  // Form states
  const [editingId, setEditingId] = useState<string | null>(null);
  const [dayOfWeek, setDayOfWeek] = useState<number>(1);
  const [startTime, setStartTime] = useState('09:00');
  const [endTime, setEndTime] = useState('11:00');
  const [label, setLabel] = useState('');
  const [maxBookings, setMaxBookings] = useState(1);
  const [isActive, setIsActive] = useState(true);

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const fetchSlots = async () => {
    if (!amenity) return;
    setLoading(true);
    try {
      const data = await api.getTimeSlots(amenity.id);
      setSlots(data);
    } catch (err: any) {
      setErrorMessage(err.message || 'Không thể tải danh sách khung giờ.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (isOpen && amenity) {
      fetchSlots();
      resetForm();
    }
  }, [isOpen, amenity]);

  const resetForm = () => {
    setEditingId(null);
    setDayOfWeek(1);
    setStartTime('09:00');
    setEndTime('11:00');
    setLabel('');
    setMaxBookings(1);
    setIsActive(true);
    setErrorMessage(null);
  };

  const handleEdit = (s: TimeSlot) => {
    setEditingId(s.id);
    setDayOfWeek(s.day_of_week);
    setStartTime(s.slot_start_time.slice(0, 5));
    setEndTime(s.slot_end_time.slice(0, 5));
    setLabel(s.slot_label || '');
    setMaxBookings(s.max_bookings);
    setIsActive(s.is_active);
    setErrorMessage(null);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!amenity) return;

    if (startTime >= endTime) {
      setErrorMessage('Giờ bắt đầu phải nhỏ hơn giờ kết thúc.');
      return;
    }
    if (maxBookings <= 0) {
      setErrorMessage('Số lượng booking tối đa phải lớn hơn 0.');
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    const payload = {
      day_of_week: dayOfWeek,
      slot_start_time: startTime,
      slot_end_time: endTime,
      slot_label: label.trim() || null,
      max_bookings: Number(maxBookings),
      is_active: isActive,
    };

    try {
      if (editingId) {
        await api.updateTimeSlot(amenity.id, editingId, payload);
        setSuccessMessage('Đã cập nhật khung giờ thành công!');
      } else {
        await api.createTimeSlot(amenity.id, payload);
        setSuccessMessage('Đã thêm khung giờ mới thành công!');
      }
      resetForm();
      await fetchSlots();
      onChanged();
      setTimeout(() => setSuccessMessage(null), 3000);
    } catch (err: any) {
      setErrorMessage(err.message || 'Đã xảy ra lỗi khi lưu khung giờ.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggleStatus = async (slot: TimeSlot) => {
    if (!amenity) return;
    try {
      await api.patchTimeSlotStatus(amenity.id, slot.id, !slot.is_active);
      await fetchSlots();
      onChanged();
    } catch (err: any) {
      setErrorMessage(err.message || 'Không thể đổi trạng thái khung giờ.');
    }
  };

  const handleDelete = async (slotId: string) => {
    if (!amenity) return;
    if (!confirm('Bạn có chắc muốn xóa khung giờ này?')) return;

    try {
      await api.deleteTimeSlot(amenity.id, slotId);
      setSuccessMessage('Đã xóa khung giờ.');
      await fetchSlots();
      onChanged();
      setTimeout(() => setSuccessMessage(null), 3000);
    } catch (err: any) {
      setErrorMessage(err.message || 'Không thể xóa khung giờ.');
    }
  };

  if (!isOpen || !amenity) return null;

  // Group slots by day of week
  const groupedSlots = DAYS_OF_WEEK.map((day) => ({
    ...day,
    slots: slots.filter((s) => s.day_of_week === day.value),
  }));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      {/* Backdrop */}
      <div onClick={onClose} className="fixed inset-0 bg-neutral-950/45 backdrop-blur-md animate-in fade-in" />

      {/* Modal */}
      <div className="relative w-full max-w-3xl bg-white/95 backdrop-blur-2xl border border-white/90 rounded-2xl shadow-2xl p-6 text-neutral-900 z-10 max-h-[92vh] flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between pb-4 border-b border-neutral-200/80 shrink-0">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-neutral-900 text-white flex items-center justify-center shadow-xs">
              <Clock className="w-4 h-4" />
            </div>
            <div>
              <h2 className="text-base font-bold text-neutral-950 leading-tight">Cấu hình Khung giờ Hoạt động</h2>
              <p className="text-xs text-neutral-500">
                Tiện ích: <span className="font-semibold text-neutral-900">{amenity.amenity_name}</span> (
                {amenity.amenity_code})
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 text-neutral-400 hover:text-neutral-900 rounded-lg hover:bg-neutral-100 transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Alerts */}
        {errorMessage && (
          <div className="mt-3 p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2 shrink-0">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{errorMessage}</span>
          </div>
        )}
        {successMessage && (
          <div className="mt-3 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs flex items-center gap-2 shrink-0">
            <Sparkles className="w-4 h-4 text-emerald-600 shrink-0" />
            <span>{successMessage}</span>
          </div>
        )}

        <div className="mt-4 flex-1 overflow-y-auto space-y-5 pr-1">
          {/* Slot Form */}
          <form onSubmit={handleSubmit} className="p-4 bg-neutral-50/80 border border-neutral-200/70 rounded-xl space-y-3">
            <div className="text-xs font-bold text-neutral-800 uppercase tracking-wider">
              {editingId ? 'Cập nhật khung giờ' : 'Thêm khung giờ mới'}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Thứ trong tuần <span className="text-rose-500">*</span>
                </label>
                <select
                  value={dayOfWeek}
                  onChange={(e) => setDayOfWeek(parseInt(e.target.value))}
                  className="w-full px-2.5 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
                >
                  {DAYS_OF_WEEK.map((d) => (
                    <option key={d.value} value={d.value}>
                      {d.label}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Giờ bắt đầu <span className="text-rose-500">*</span>
                </label>
                <input
                  type="time"
                  required
                  value={startTime}
                  onChange={(e) => setStartTime(e.target.value)}
                  className="w-full px-2.5 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Giờ kết thúc <span className="text-rose-500">*</span>
                </label>
                <input
                  type="time"
                  required
                  value={endTime}
                  onChange={(e) => setEndTime(e.target.value)}
                  className="w-full px-2.5 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Số booking tối đa <span className="text-rose-500">*</span>
                </label>
                <input
                  type="number"
                  min={1}
                  required
                  value={maxBookings}
                  onChange={(e) => setMaxBookings(parseInt(e.target.value) || 1)}
                  className="w-full px-2.5 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
              <div className="sm:col-span-2">
                <label className="block text-xs font-semibold text-neutral-700 mb-1">Nhãn khung giờ (tùy chọn)</label>
                <input
                  type="text"
                  placeholder="VD: Khung giờ sáng 1, Ca trưa, Buổi tối gia đình..."
                  value={label}
                  onChange={(e) => setLabel(e.target.value)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
                />
              </div>

              <div className="flex items-center gap-2 pt-5">
                <label className="inline-flex items-center gap-2 text-xs font-medium text-neutral-800 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={isActive}
                    onChange={(e) => setIsActive(e.target.checked)}
                    className="w-4 h-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900"
                  />
                  <span>Kích hoạt slot</span>
                </label>
              </div>
            </div>

            <div className="flex items-center justify-end gap-2 pt-2 border-t border-neutral-200/60">
              {editingId && (
                <button
                  type="button"
                  onClick={resetForm}
                  className="px-3 py-1.5 text-xs text-neutral-600 hover:bg-neutral-200/70 rounded-lg transition-colors cursor-pointer"
                >
                  Hủy sửa
                </button>
              )}
              <button
                type="submit"
                disabled={isSubmitting}
                className="px-4 py-2 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all shadow-xs cursor-pointer"
              >
                {isSubmitting ? (
                  <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                ) : editingId ? (
                  <Check className="w-3.5 h-3.5" />
                ) : (
                  <Plus className="w-3.5 h-3.5" />
                )}
                <span>{editingId ? 'Cập nhật slot' : 'Thêm khung giờ'}</span>
              </button>
            </div>
          </form>

          {/* Grouped Weekday Slots View */}
          <div className="space-y-4">
            <div className="text-xs font-bold text-neutral-800 uppercase tracking-wider flex items-center justify-between">
              <span>Lịch khung giờ theo ngày trong tuần</span>
              <span className="text-neutral-500 font-normal">Tổng: {slots.length} khung giờ</span>
            </div>

            {loading ? (
              <div className="py-8 text-center text-neutral-400 text-xs flex items-center justify-center gap-2">
                <span className="w-4 h-4 border-2 border-neutral-400 border-t-neutral-800 rounded-full animate-spin" />
                <span>Đang tải khung giờ...</span>
              </div>
            ) : slots.length === 0 ? (
              <div className="py-8 text-center text-neutral-400 text-xs border border-dashed border-neutral-200 rounded-xl">
                Chưa có khung giờ hoạt động nào. Hãy thiết lập khung giờ đầu tiên ở biểu mẫu trên!
              </div>
            ) : (
              <div className="space-y-3">
                {groupedSlots.map((dayGroup) => (
                  <div key={dayGroup.value} className="p-3.5 bg-white border border-neutral-200/80 rounded-xl shadow-2xs">
                    <div className="flex items-center justify-between mb-2">
                      <div className="font-bold text-xs text-neutral-900 flex items-center gap-2">
                        <span className="w-2 h-2 rounded-full bg-neutral-900" />
                        <span>{dayGroup.label}</span>
                        <span className="text-[11px] font-normal text-neutral-500">
                          ({dayGroup.slots.length} khung giờ)
                        </span>
                      </div>
                    </div>

                    {dayGroup.slots.length === 0 ? (
                      <p className="text-[11px] text-neutral-400 italic">Không có lịch hoạt động trong ngày này</p>
                    ) : (
                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        {dayGroup.slots.map((s) => (
                          <div
                            key={s.id}
                            className={`p-2.5 rounded-lg border flex items-center justify-between transition-colors ${
                              s.is_active
                                ? 'bg-neutral-50/70 border-neutral-200 text-neutral-900'
                                : 'bg-neutral-100/50 border-neutral-200/60 text-neutral-400'
                            }`}
                          >
                            <div className="min-w-0 pr-2">
                              <div className="flex items-center gap-1.5 font-mono text-xs font-bold">
                                <span>
                                  {s.slot_start_time.slice(0, 5)} - {s.slot_end_time.slice(0, 5)}
                                </span>
                                {!s.is_active && (
                                  <span className="text-[10px] font-sans font-normal px-1 py-0.2 bg-neutral-200 text-neutral-600 rounded">
                                    Tắt
                                  </span>
                                )}
                              </div>
                              <div className="text-[11px] text-neutral-500 truncate mt-0.5">
                                {s.slot_label || 'Khung giờ tiêu chuẩn'} • Max {s.max_bookings} booking
                              </div>
                            </div>

                            <div className="flex items-center gap-1 shrink-0">
                              <button
                                type="button"
                                onClick={() => handleToggleStatus(s)}
                                className={`p-1 rounded-md transition-colors cursor-pointer ${
                                  s.is_active
                                    ? 'text-emerald-600 hover:bg-emerald-50'
                                    : 'text-neutral-400 hover:bg-neutral-200'
                                }`}
                                title={s.is_active ? 'Đang bật • Bấm để tắt' : 'Đang tắt • Bấm để bật'}
                              >
                                <Power className="w-3.5 h-3.5" />
                              </button>
                              <button
                                type="button"
                                onClick={() => handleEdit(s)}
                                className="p-1 text-neutral-500 hover:text-neutral-900 hover:bg-neutral-100 rounded-md transition-colors cursor-pointer"
                                title="Chỉnh sửa"
                              >
                                <Edit2 className="w-3.5 h-3.5" />
                              </button>
                              <button
                                type="button"
                                onClick={() => handleDelete(s.id)}
                                className="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-md transition-colors cursor-pointer"
                                title="Xóa"
                              >
                                <Trash2 className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="mt-4 pt-3 border-t border-neutral-200/80 flex justify-end shrink-0">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 text-xs font-semibold text-neutral-700 hover:bg-neutral-100 rounded-lg transition-colors cursor-pointer"
          >
            Hoàn tất & Đóng
          </button>
        </div>
      </div>
    </div>
  );
};
