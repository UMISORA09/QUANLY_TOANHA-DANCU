import React, { useState, useEffect } from 'react';
import { X, Plus, Trash2, CalendarOff, Check, AlertCircle, Sparkles, Clock } from 'lucide-react';
import { api, Blackout, Amenity } from '../../Services/api';

interface BlackoutModalProps {
  isOpen: boolean;
  amenity: Amenity | null;
  onClose: () => void;
  onChanged: () => void;
}

export const BlackoutModal: React.FC<BlackoutModalProps> = ({
  isOpen,
  amenity,
  onClose,
  onChanged,
}) => {
  const [blackouts, setBlackouts] = useState<Blackout[]>([]);
  const [loading, setLoading] = useState(false);

  // Form states
  const [isFullDay, setIsFullDay] = useState(true);
  const [blackoutDate, setBlackoutDate] = useState('');
  const [startTime, setStartTime] = useState('08:00');
  const [endTime, setEndTime] = useState('17:00');
  const [reason, setReason] = useState('');

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const fetchBlackouts = async () => {
    if (!amenity) return;
    setLoading(true);
    try {
      const data = await api.getBlackouts(amenity.id);
      setBlackouts(data);
    } catch (err: any) {
      setErrorMessage(err.message || 'Không thể tải danh sách ngày đóng cửa.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (isOpen && amenity) {
      fetchBlackouts();
      // Default to tomorrow's date
      const tomorrow = new Date();
      tomorrow.setDate(tomorrow.getDate() + 1);
      setBlackoutDate(tomorrow.toISOString().split('T')[0]);
      setIsFullDay(true);
      setStartTime('08:00');
      setEndTime('17:00');
      setReason('');
      setErrorMessage(null);
    }
  }, [isOpen, amenity]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!amenity) return;

    if (!blackoutDate) {
      setErrorMessage('Vui lòng chọn ngày đóng cửa/bảo trì.');
      return;
    }
    if (!reason.trim()) {
      setErrorMessage('Vui lòng nhập lý do đóng cửa.');
      return;
    }
    if (!isFullDay && startTime >= endTime) {
      setErrorMessage('Giờ bắt đầu phải nhỏ hơn giờ kết thúc.');
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);

    const payload = {
      blackout_date: blackoutDate,
      start_time: isFullDay ? null : startTime,
      end_time: isFullDay ? null : endTime,
      reason: reason.trim(),
    };

    try {
      await api.createBlackout(amenity.id, payload);
      setSuccessMessage('Đã thêm lịch đóng cửa bảo trì thành công!');
      setReason('');
      await fetchBlackouts();
      onChanged();
      setTimeout(() => setSuccessMessage(null), 3000);
    } catch (err: any) {
      setErrorMessage(err.message || 'Đã xảy ra lỗi khi lưu ngày bảo trì.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDelete = async (blackoutId: string) => {
    if (!amenity) return;
    if (!confirm('Bạn có chắc muốn xóa lịch bảo trì này?')) return;

    try {
      await api.deleteBlackout(amenity.id, blackoutId);
      setSuccessMessage('Đã hủy lịch bảo trì.');
      await fetchBlackouts();
      onChanged();
      setTimeout(() => setSuccessMessage(null), 3000);
    } catch (err: any) {
      setErrorMessage(err.message || 'Không thể xóa lịch bảo trì.');
    }
  };

  if (!isOpen || !amenity) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
      {/* Backdrop */}
      <div onClick={onClose} className="fixed inset-0 bg-neutral-950/45 backdrop-blur-md animate-in fade-in" />

      {/* Modal Card */}
      <div className="relative w-full max-w-2xl bg-white/95 backdrop-blur-2xl border border-white/90 rounded-2xl shadow-2xl p-6 text-neutral-900 z-10 max-h-[90vh] flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between pb-4 border-b border-neutral-200/80 shrink-0">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-neutral-900 text-white flex items-center justify-center shadow-xs">
              <CalendarOff className="w-4 h-4" />
            </div>
            <div>
              <h2 className="text-base font-bold text-neutral-950 leading-tight">Ngày Đóng cửa & Bảo trì</h2>
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
          {/* Create Form */}
          <form onSubmit={handleSubmit} className="p-4 bg-neutral-50/80 border border-neutral-200/70 rounded-xl space-y-3">
            <div className="text-xs font-bold text-neutral-800 uppercase tracking-wider">
              Thêm lịch bảo trì / đóng cửa mới
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Ngày đóng cửa <span className="text-rose-500">*</span>
                </label>
                <input
                  type="date"
                  required
                  value={blackoutDate}
                  onChange={(e) => setBlackoutDate(e.target.value)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">Hình thức đóng cửa</label>
                <div className="flex items-center gap-4 pt-2">
                  <label className="inline-flex items-center gap-1.5 text-xs text-neutral-800 cursor-pointer select-none">
                    <input
                      type="radio"
                      name="blackoutType"
                      checked={isFullDay}
                      onChange={() => setIsFullDay(true)}
                      className="text-neutral-900 focus:ring-neutral-900"
                    />
                    <span>Toàn bộ ngày</span>
                  </label>
                  <label className="inline-flex items-center gap-1.5 text-xs text-neutral-800 cursor-pointer select-none">
                    <input
                      type="radio"
                      name="blackoutType"
                      checked={!isFullDay}
                      onChange={() => setIsFullDay(false)}
                      className="text-neutral-900 focus:ring-neutral-900"
                    />
                    <span>Khoảng giờ</span>
                  </label>
                </div>
              </div>
            </div>

            {!isFullDay && (
              <div className="grid grid-cols-2 gap-3 pt-1 animate-in fade-in">
                <div>
                  <label className="block text-xs font-semibold text-neutral-700 mb-1">
                    Giờ bắt đầu <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="time"
                    required
                    value={startTime}
                    onChange={(e) => setStartTime(e.target.value)}
                    className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
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
                    className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                  />
                </div>
              </div>
            )}

            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Lý do đóng cửa / bảo trì <span className="text-rose-500">*</span>
              </label>
              <input
                type="text"
                required
                placeholder="VD: Thay nước hồ bơi định kỳ, Nâng cấp máy tập, Khử khuẩn..."
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
              />
            </div>

            <div className="flex items-center justify-end pt-2 border-t border-neutral-200/60">
              <button
                type="submit"
                disabled={isSubmitting}
                className="px-4 py-2 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all shadow-xs cursor-pointer"
              >
                {isSubmitting ? (
                  <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                ) : (
                  <Plus className="w-3.5 h-3.5" />
                )}
                <span>Lưu lịch bảo trì</span>
              </button>
            </div>
          </form>

          {/* List of Blackouts */}
          <div>
            <div className="text-xs font-bold text-neutral-800 uppercase tracking-wider mb-2.5">
              Lịch bảo trì đã lên ({blackouts.length})
            </div>

            {loading ? (
              <div className="py-8 text-center text-neutral-400 text-xs flex items-center justify-center gap-2">
                <span className="w-4 h-4 border-2 border-neutral-400 border-t-neutral-800 rounded-full animate-spin" />
                <span>Đang tải lịch bảo trì...</span>
              </div>
            ) : blackouts.length === 0 ? (
              <div className="py-8 text-center text-neutral-400 text-xs border border-dashed border-neutral-200 rounded-xl">
                Không có ngày bảo trì nào. Tiện ích đang hoạt động theo lịch khung giờ bình thường!
              </div>
            ) : (
              <div className="space-y-2">
                {blackouts.map((b) => (
                  <div
                    key={b.id}
                    className="p-3 bg-white border border-neutral-200/80 rounded-xl flex items-center justify-between shadow-2xs hover:border-neutral-300 transition-colors"
                  >
                    <div className="min-w-0 pr-3">
                      <div className="flex items-center gap-2">
                        <span className="font-bold text-xs font-mono text-neutral-950">{b.blackout_date}</span>
                        {b.start_time && b.end_time ? (
                          <span className="px-1.5 py-0.5 rounded text-[10px] font-mono bg-amber-50 text-amber-800 border border-amber-200">
                            {b.start_time.slice(0, 5)} - {b.end_time.slice(0, 5)}
                          </span>
                        ) : (
                          <span className="px-1.5 py-0.5 rounded text-[10px] bg-rose-50 text-rose-800 border border-rose-200 font-medium">
                            Cả ngày
                          </span>
                        )}
                      </div>
                      <p className="text-xs text-neutral-700 mt-1 font-medium">{b.reason}</p>
                    </div>

                    <button
                      type="button"
                      onClick={() => handleDelete(b.id)}
                      className="p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer shrink-0"
                      title="Xóa lịch bảo trì"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
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
            Đóng
          </button>
        </div>
      </div>
    </div>
  );
};
