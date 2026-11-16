import React, { useState, useEffect } from 'react';
import {
  X,
  Building,
  AlertTriangle,
  RefreshCw,
  CheckCircle2,
  Phone,
  MapPin,
  ShieldAlert,
} from 'lucide-react';

export interface Zone {
  id: string | number;
  zone_code: string;
  zone_name: string;
  floor_count: number;
  basement_count: number;
  total_apartments: number;
  status: 'ACTIVE' | 'MAINTENANCE' | 'INACTIVE';
  address_line?: string | null;
  hotline_phone?: string | null;
  description?: string | null;
  version?: number;
  created_at?: string;
  updated_at?: string;
}

export type ZoneData = Zone;

export interface ZoneFormProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: (message: string) => void;
  initialData: Zone | null;
  onReloadRequested?: (id?: string | number) => Promise<Zone | null | void> | void;
}

export const ZoneForm: React.FC<ZoneFormProps> = ({
  isOpen,
  onClose,
  onSuccess,
  initialData,
  onReloadRequested,
}) => {
  // Trạng thái dữ liệu biểu mẫu
  const [formData, setFormData] = useState({
    zone_code: '',
    zone_name: '',
    floor_count: 1,
    basement_count: 1,
    total_apartments: 0,
    status: 'ACTIVE' as 'ACTIVE' | 'MAINTENANCE' | 'INACTIVE',
    address_line: '',
    hotline_phone: '',
    description: '',
    version: 1,
    last_updated_at: '',
  });

  // Trạng thái lỗi và tương tác
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [conflictMessage, setConflictMessage] = useState<string | null>(null);
  const [conflictData, setConflictData] = useState<Zone | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isReloading, setIsReloading] = useState(false);

  const isEditMode = !!initialData?.id;

  // Điền dữ liệu khi mở form (Thêm mới hoặc Sửa)
  useEffect(() => {
    if (initialData) {
      setFormData({
        zone_code: initialData.zone_code || '',
        zone_name: initialData.zone_name || '',
        floor_count: Number(initialData.floor_count) || 1,
        basement_count: Number(initialData.basement_count) || 0,
        total_apartments: Number(initialData.total_apartments) || 0,
        status: initialData.status || 'ACTIVE',
        address_line: initialData.address_line || '',
        hotline_phone: initialData.hotline_phone || '',
        description: initialData.description || '',
        version: initialData.version || 1,
        last_updated_at: initialData.updated_at || '',
      });
    } else {
      setFormData({
        zone_code: '',
        zone_name: '',
        floor_count: 1,
        basement_count: 1,
        total_apartments: 0,
        status: 'ACTIVE',
        address_line: '',
        hotline_phone: '',
        description: '',
        version: 1,
        last_updated_at: '',
      });
    }

    setFieldErrors({});
    setConflictMessage(null);
    setConflictData(null);
  }, [initialData, isOpen]);

  if (!isOpen) return null;

  // Xử lý thay đổi input
  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>
  ) => {
    const { name, value } = e.target;
    setFormData((prev) => ({
      ...prev,
      [name]:
        name === 'floor_count' || name === 'basement_count' || name === 'total_apartments'
          ? Number(value)
          : value,
    }));

    // Tự động xóa lỗi của ô input khi người dùng nhập lại
    if (fieldErrors[name]) {
      setFieldErrors((prev) => {
        const updated = { ...prev };
        delete updated[name];
        return updated;
      });
    }
  };

  // Nút xử lý "Tải lại dữ liệu mới" khi bị xung đột HTTP 409 Conflict
  const handleReloadLatestData = async () => {
    if (!initialData?.id) return;
    setIsReloading(true);

    try {
      let latest: Zone | null = conflictData;

      // Nếu có callback reload từ component cha hoặc fetch trực tiếp
      if (!latest && onReloadRequested) {
        const reloaded = await onReloadRequested(initialData.id);
        if (reloaded) {
          latest = reloaded;
        }
      }

      if (!latest) {
        const token = localStorage.getItem('auth_token') || localStorage.getItem('smart_token') || '';
        const headers: Record<string, string> = { Accept: 'application/json' };
        if (token) headers['Authorization'] = `Bearer ${token}`;

        const res = await fetch(`/api/v1/manager/zones/${initialData.id}`, { headers });
        const data = await res.json();
        if (data.success && data.data) {
          latest = data.data;
        }
      }

      if (latest) {
        setFormData({
          zone_code: latest.zone_code || '',
          zone_name: latest.zone_name || '',
          floor_count: Number(latest.floor_count) || 1,
          basement_count: Number(latest.basement_count) || 0,
          total_apartments: Number(latest.total_apartments) || 0,
          status: latest.status || 'ACTIVE',
          address_line: latest.address_line || '',
          hotline_phone: latest.hotline_phone || '',
          description: latest.description || '',
          version: latest.version || 1,
          last_updated_at: latest.updated_at || '',
        });

        setConflictMessage(null);
        setConflictData(null);
        setFieldErrors({});
      }
    } catch (err) {
      console.error('Lỗi khi tải lại dữ liệu khối:', err);
    } finally {
      setIsReloading(false);
    }
  };

  // Submit Form với xử lý try...catch chặt chẽ
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setFieldErrors({});
    setConflictMessage(null);

    const url = isEditMode
      ? `/api/v1/manager/zones/${initialData?.id}`
      : '/api/v1/manager/zones';
    const method = isEditMode ? 'PUT' : 'POST';

    try {
      const token = localStorage.getItem('auth_token') || localStorage.getItem('smart_token') || '';
      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      };
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }

      const response = await fetch(url, {
        method,
        headers,
        body: JSON.stringify(formData),
      });

      const resData = await response.json();

      // Trường hợp 1: Thành công (HTTP 200 / 201)
      if (response.ok && resData.success) {
        onSuccess(resData.message || (isEditMode ? 'Cập nhật khối thành công!' : 'Tạo khối mới thành công!'));
        onClose();
        return;
      }

      // Trường hợp 2: Lỗi Validation (HTTP 422)
      if (response.status === 422) {
        if (resData.errors) {
          const errorsMap: Record<string, string> = {};
          Object.keys(resData.errors).forEach((key) => {
            errorsMap[key] = resData.errors[key][0];
          });
          setFieldErrors(errorsMap);
        } else {
          setFieldErrors({ global: resData.message || 'Dữ liệu không hợp lệ. Vui lòng kiểm tra lại.' });
        }
        return;
      }

      // Trường hợp 3: Lỗi Xung đột Optimistic Locking (HTTP 409 Conflict)
      if (response.status === 409) {
        setConflictMessage(
          resData.message || 'Dữ liệu đã bị thay đổi bởi người khác trong lúc bạn đang thao tác.'
        );
        if (resData.current_data) {
          setConflictData(resData.current_data);
        }
        return;
      }

      // Trường hợp 4: Lỗi không tìm thấy bản ghi (HTTP 404 Not Found)
      if (response.status === 404) {
        alert('Khối tòa nhà không tồn tại hoặc đã bị xóa khỏi hệ thống.');
        onClose();
        return;
      }

      // Các lỗi khác
      alert(resData.message || 'Đã có lỗi xảy ra. Vui lòng thử lại!');
    } catch (err: any) {
      console.error('Lỗi khi gửi form:', err);
      alert('Không thể kết nối đến máy chủ. Vui lòng kiểm tra lại đường truyền mạng!');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 overflow-y-auto">
      {/* Lớp nền tối mờ đồng bộ */}
      <div
        className="fixed inset-0 bg-slate-900/60 transition-opacity duration-300"
        onClick={onClose}
      />

      {/* Khung Modal chuẩn motif Slate / Sky */}
      <div className="relative w-full max-w-2xl bg-white border border-slate-200 shadow-2xl rounded-2xl overflow-hidden z-10 my-8 transition-all duration-300">
        {/* Header Modal */}
        <div className="flex items-center justify-between px-6 py-5 border-b border-slate-200 bg-slate-50">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-sky-600 flex items-center justify-center text-white shadow-sm">
              <Building className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-slate-900 tracking-tight">
                {isEditMode ? `Chỉnh Sửa: ${initialData?.zone_name}` : 'Thêm Khối Tòa Nhà Mới'}
              </h2>
              <p className="text-xs text-slate-500 mt-0.5">
                {isEditMode
                  ? `Mã hệ thống: ${initialData?.zone_code} • Kiểm soát xung đột phiên bản`
                  : 'Khai báo thông số quy hoạch khối tòa nhà trong khu chung cư'}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-200 transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Nội dung Form */}
        <form onSubmit={handleSubmit} className="p-6 sm:p-8 space-y-5">
          {/* CẢNH BÁO OPTIMISTIC LOCKING: HTTP 409 CONFLICT */}
          {conflictMessage && (
            <div className="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
              <div className="flex items-start gap-3">
                <AlertTriangle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div>
                  <h4 className="text-sm font-bold text-amber-900">Cảnh báo xung đột dữ liệu (HTTP 409)</h4>
                  <p className="text-xs text-amber-800 mt-0.5">{conflictMessage}</p>
                </div>
              </div>
              <button
                type="button"
                onClick={handleReloadLatestData}
                disabled={isReloading}
                className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-amber-600 hover:bg-amber-700 text-white transition-all shadow-xs shrink-0"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${isReloading ? 'animate-spin' : ''}`} />
                {isReloading ? 'Đang nạp...' : 'Tải lại dữ liệu mới'}
              </button>
            </div>
          )}

          {/* Lỗi chung nếu có */}
          {fieldErrors.global && (
            <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-center gap-2">
              <ShieldAlert className="w-4 h-4 text-rose-600 shrink-0" />
              {fieldErrors.global}
            </div>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {/* Trường 1: Mã Khối Nhà (zone_code) */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                Mã Khối / Block Code <span className="text-rose-500">*</span>
              </label>
              <input
                type="text"
                name="zone_code"
                value={formData.zone_code}
                onChange={handleChange}
                placeholder="VD: BLOCK_A, TOWER_1..."
                className={`w-full px-3.5 py-2.5 rounded-xl bg-white border ${
                  fieldErrors.zone_code
                    ? 'border-rose-300 focus:ring-rose-500/20 focus:border-rose-500'
                    : 'border-slate-300 focus:border-sky-500 focus:ring-sky-500/20'
                } text-slate-900 placeholder-slate-400 text-sm focus:outline-none focus:ring-2 transition-all font-mono uppercase`}
              />
              {/* Lỗi HTTP 422 cho zone_code */}
              {fieldErrors.zone_code && (
                <p className="text-[11px] font-medium text-rose-600 mt-1 flex items-center gap-1">
                  <span>•</span> {fieldErrors.zone_code}
                </p>
              )}
            </div>

            {/* Trường 2: Tên Khối Nhà (zone_name) */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                Tên Khối Nhà <span className="text-rose-500">*</span>
              </label>
              <input
                type="text"
                name="zone_name"
                value={formData.zone_name}
                onChange={handleChange}
                placeholder="VD: Tòa Nhà A - Ruby Tower"
                className={`w-full px-3.5 py-2.5 rounded-xl bg-white border ${
                  fieldErrors.zone_name
                    ? 'border-rose-300 focus:ring-rose-500/20 focus:border-rose-500'
                    : 'border-slate-300 focus:border-sky-500 focus:ring-sky-500/20'
                } text-slate-900 placeholder-slate-400 text-sm focus:outline-none focus:ring-2 transition-all`}
              />
              {/* Lỗi HTTP 422 cho zone_name */}
              {fieldErrors.zone_name && (
                <p className="text-[11px] font-medium text-rose-600 mt-1 flex items-center gap-1">
                  <span>•</span> {fieldErrors.zone_name}
                </p>
              )}
            </div>

            {/* Trường 3: Số Tầng Nổi (floor_count - bắt buộc > 0) */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                Số Tầng Nổi (floor_count &gt; 0) <span className="text-rose-500">*</span>
              </label>
              <input
                type="number"
                name="floor_count"
                min="1"
                value={formData.floor_count}
                onChange={handleChange}
                className={`w-full px-3.5 py-2.5 rounded-xl bg-white border ${
                  fieldErrors.floor_count
                    ? 'border-rose-300 focus:ring-rose-500/20 focus:border-rose-500'
                    : 'border-slate-300 focus:border-sky-500 focus:ring-sky-500/20'
                } text-slate-900 placeholder-slate-400 text-sm font-mono focus:outline-none focus:ring-2 transition-all`}
              />
              {/* Lỗi HTTP 422 cho floor_count */}
              {fieldErrors.floor_count && (
                <p className="text-[11px] font-medium text-rose-600 mt-1 flex items-center gap-1">
                  <span>•</span> {fieldErrors.floor_count}
                </p>
              )}
            </div>

            {/* Trường 4: Số Tầng Hầm (basement_count) */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                Số Tầng Hầm (basement_count)
              </label>
              <input
                type="number"
                name="basement_count"
                min="0"
                value={formData.basement_count}
                onChange={handleChange}
                className="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-300 text-slate-900 text-sm font-mono focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all"
              />
            </div>

            {/* Trường 5: Tổng số căn hộ (total_apartments) */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                Tổng Số Căn Hộ Dự Kiến
              </label>
              <input
                type="number"
                name="total_apartments"
                min="0"
                value={formData.total_apartments}
                onChange={handleChange}
                className="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-300 text-slate-900 text-sm font-mono focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all"
              />
            </div>

            {/* Trường 6: Trạng thái (status) */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                Trạng Thái Hoạt Động
              </label>
              <select
                name="status"
                value={formData.status}
                onChange={handleChange}
                className="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-300 text-slate-900 text-sm focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all"
              >
                <option value="ACTIVE">🟢 Đang Hoạt Động (ACTIVE)</option>
                <option value="MAINTENANCE">🟡 Đang Bảo Trì (MAINTENANCE)</option>
                <option value="INACTIVE">⚪ Tạm Ngừng (INACTIVE)</option>
              </select>
            </div>

            {/* Trường 7: Hotline Liên Hệ (hotline_phone) */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                Hotline Khối Tòa Nhà
              </label>
              <div className="relative">
                <input
                  type="text"
                  name="hotline_phone"
                  value={formData.hotline_phone}
                  onChange={handleChange}
                  placeholder="VD: 1900-1122-01"
                  className="w-full pl-9 pr-3.5 py-2.5 rounded-xl bg-white border border-slate-300 text-slate-900 placeholder-slate-400 text-sm font-mono focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all"
                />
                <Phone className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
              </div>
            </div>

            {/* Trường 8: Vị Trí / Địa Chỉ (address_line) */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
                Vị Trí / Địa Chỉ Trong Dự Án
              </label>
              <div className="relative">
                <input
                  type="text"
                  name="address_line"
                  value={formData.address_line}
                  onChange={handleChange}
                  placeholder="VD: Mặt đường chính, view công viên..."
                  className="w-full pl-9 pr-3.5 py-2.5 rounded-xl bg-white border border-slate-300 text-slate-900 placeholder-slate-400 text-sm focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all"
                />
                <MapPin className="w-4 h-4 text-slate-400 absolute left-3 top-3" />
              </div>
            </div>
          </div>

          {/* Trường 9: Mô tả / Ghi chú kỹ thuật (description) */}
          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
              Mô Tả Chi Tiết / Tiện Ích Khối Đế
            </label>
            <textarea
              name="description"
              rows={3}
              value={formData.description}
              onChange={handleChange}
              placeholder="Thông tin đặc điểm kiến trúc, tiện ích sảnh, thang máy..."
              className="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-300 text-slate-900 placeholder-slate-400 text-sm focus:outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all resize-none"
            />
          </div>

          {/* Nút thao tác dưới Form */}
          <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
            <button
              type="button"
              onClick={onClose}
              disabled={isSubmitting}
              className="px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-100 border border-slate-300 transition-colors"
            >
              Hủy Bỏ
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-sky-600 hover:bg-sky-700 shadow-sm transition-all transform active:scale-95 disabled:opacity-50"
            >
              {isSubmitting ? (
                <>
                  <RefreshCw className="w-4 h-4 animate-spin" />
                  Đang lưu...
                </>
              ) : (
                <>
                  <CheckCircle2 className="w-4 h-4" />
                  {isEditMode ? 'Lưu Thay Đổi' : 'Tạo Khối Nhà'}
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
