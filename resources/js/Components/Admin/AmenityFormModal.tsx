import React, { useState, useEffect } from 'react';
import { X, Check, Building, AlertCircle, Shield, Info, Image, Plus, Trash2, RefreshCw } from 'lucide-react';
import { api, Amenity, Category, BlockOption } from '../../Services/api';

interface AmenityFormModalProps {
  isOpen: boolean;
  amenity?: Amenity | null;
  categories: Category[];
  blocks: BlockOption[];
  onClose: () => void;
  onSuccess: () => void;
}

interface CategoryPreset {
  suggestedName: string;
  codePrefix: string;
  location: string;
  maxCapacity: number;
  hourlyRate: number;
  securityDeposit: number;
  advanceDays: number;
  minCancelHours: number;
  requiresApproval: boolean;
  rules: string;
  coverImageUrl: string;
}

const CATEGORY_PRESETS: Record<string, CategoryPreset> = {
  SINH_HOAT_CONG_DONG: {
    suggestedName: 'Phòng Sinh Hoạt Cộng Đồng & Sự Kiện',
    codePrefix: 'SHCD',
    location: 'Tầng 2 - Tòa Tháp Landmark (Khu sinh hoạt cộng đồng)',
    maxCapacity: 50,
    hourlyRate: 0,
    securityDeposit: 500000,
    advanceDays: 14,
    minCancelHours: 24,
    requiresApproval: true,
    rules: 'Cần đăng ký nội dung sự kiện trước với Ban Quản Lý. Giữ gìn trật tự sau 22:00, dọn dẹp vệ sinh và tắt các thiết bị điện sau khi sử dụng.',
    coverImageUrl: 'https://images.unsplash.com/photo-1517457373958-b7bdd4587205?auto=format&fit=crop&w=1200&q=80',
  },
  THE_THAO_SUC_KHOE: {
    suggestedName: 'Phòng Gym & Yoga Thể Thao Quốc Tế',
    codePrefix: 'GYM_YOGA',
    location: 'Tầng 3 - Tòa Nhà Trung Tâm (Khu rèn luyện thể chất)',
    maxCapacity: 20,
    hourlyRate: 0,
    securityDeposit: 0,
    advanceDays: 7,
    minCancelHours: 6,
    requiresApproval: false,
    rules: 'Vui lòng mang trang phục và giày thể thao chuyên dụng đế mềm. Khăn lau cá nhân khi tập máy, trả tạ và dụng cụ về đúng vị trí sau khi tập.',
    coverImageUrl: 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?auto=format&fit=crop&w=1200&q=80',
  },
  AM_THUC_BBQ: {
    suggestedName: 'Vườn Nướng BBQ Rooftop Ngoài Trời',
    codePrefix: 'BBQ_ROOFTOP',
    location: 'Tầng thượng - Vườn treo Horizon (Khu ẩm thực BBQ)',
    maxCapacity: 15,
    hourlyRate: 50000,
    securityDeposit: 200000,
    advanceDays: 7,
    minCancelHours: 12,
    requiresApproval: false,
    rules: 'Vệ sinh sạch sẽ bếp nướng và khu vực bàn ăn sau khi tiệc kết thúc. Không sử dụng than củi ngoài tiêu chuẩn, tuân thủ an toàn phòng cháy chữa cháy.',
    coverImageUrl: 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=1200&q=80',
  },
  TIEN_ICH_TRE_EM: {
    suggestedName: 'Khu Vui Chơi Trẻ Em KidZone',
    codePrefix: 'KIDZONE',
    location: 'Tầng 1 - Khuôn viên nội khu an toàn cho trẻ nhỏ',
    maxCapacity: 25,
    hourlyRate: 0,
    securityDeposit: 0,
    advanceDays: 3,
    minCancelHours: 2,
    requiresApproval: false,
    rules: 'Trẻ em dưới 6 tuổi cần có phụ huynh đi kèm giám sát. Không mang đồ chơi sắc nhọn hoặc thức ăn vào khu vực vui chơi vận động.',
    coverImageUrl: 'https://images.unsplash.com/photo-1596464716127-f2a82984de30?auto=format&fit=crop&w=1200&q=80',
  },
  ROOFTOP_RELAX: {
    suggestedName: 'Quán Cà Phê & Trà Chiều Panorama',
    codePrefix: 'CAFE_ROOFTOP',
    location: 'Tầng 35 - Tòa Tháp Landmark (Sky Lounge & Coffee)',
    maxCapacity: 20,
    hourlyRate: 0,
    securityDeposit: 0,
    advanceDays: 7,
    minCancelHours: 12,
    requiresApproval: false,
    rules: 'Giữ gìn vệ sinh và cảnh quan chung. Tuyệt đối không leo trèo qua lan can ban công hoặc xả rác xuống tầng dưới.',
    coverImageUrl: 'https://images.unsplash.com/photo-1519671482749-fd09be7ccebf?auto=format&fit=crop&w=1200&q=80',
  },
};

export const AmenityFormModal: React.FC<AmenityFormModalProps> = ({
  isOpen,
  amenity,
  categories,
  blocks,
  onClose,
  onSuccess,
}) => {
  const isEdit = !!amenity;

  // Form states
  const [categoryId, setCategoryId] = useState('');
  const [blockId, setBlockId] = useState<string>('');
  const [amenityName, setAmenityName] = useState('');
  const [amenityCode, setAmenityCode] = useState('');
  const [locationDetail, setLocationDetail] = useState('');
  const [maxCapacity, setMaxCapacity] = useState(10);
  const [hourlyRate, setHourlyRate] = useState(0);
  const [securityDeposit, setSecurityDeposit] = useState(0);
  const [advanceDays, setAdvanceDays] = useState(7);
  const [minCancelHours, setMinCancelHours] = useState(12);
  const [requiresApproval, setRequiresApproval] = useState(false);
  const [rules, setRules] = useState('');
  const [coverImageUrl, setCoverImageUrl] = useState('');
  const [galleryImages, setGalleryImages] = useState<string[]>([]);
  const [newGalleryUrl, setNewGalleryUrl] = useState('');
  const [isActive, setIsActive] = useState(true);

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isConflict, setIsConflict] = useState(false);
  const [isReloading, setIsReloading] = useState(false);

  // Auto-fill preset helper based on Category & Block
  const applyCategoryPreset = (targetCatId: string, currentBlockId?: string, force = true) => {
    if (!targetCatId) {
      setAmenityName('');
      setAmenityCode('');
      setLocationDetail('');
      return;
    }

    const cat = categories.find((c) => c.id === targetCatId);
    if (!cat) {
      setAmenityName('');
      setAmenityCode('');
      setLocationDetail('');
      return;
    }

    // Match preset by category code or name keyword
    const catCodeUpper = cat.category_code.toUpperCase();
    const catNameLower = cat.category_name.toLowerCase();

    let matchedKey: string | undefined = Object.keys(CATEGORY_PRESETS).find(
      (k) => catCodeUpper.includes(k) || k.includes(catCodeUpper)
    );

    if (!matchedKey) {
      if (catNameLower.includes('bbq') || catNameLower.includes('ẩm thực') || catNameLower.includes('nướng')) {
        matchedKey = 'AM_THUC_BBQ';
      } else if (catNameLower.includes('thể thao') || catNameLower.includes('gym') || catNameLower.includes('yoga')) {
        matchedKey = 'THE_THAO_SUC_KHOE';
      } else if (catNameLower.includes('trẻ em') || catNameLower.includes('kid')) {
        matchedKey = 'TIEN_ICH_TRE_EM';
      } else if (catNameLower.includes('sân thượng') || catNameLower.includes('thư giãn') || catNameLower.includes('rooftop')) {
        matchedKey = 'ROOFTOP_RELAX';
      } else if (catNameLower.includes('cộng đồng') || catNameLower.includes('sinh hoạt') || catNameLower.includes('hội')) {
        matchedKey = 'SINH_HOAT_CONG_DONG';
      }
    }

    const preset = matchedKey ? CATEGORY_PRESETS[matchedKey] : null;
    const blk = blocks.find((b) => b.id === (currentBlockId !== undefined ? currentBlockId : blockId));
    const blockLabel = blk ? (blk.block_name.split(' - ')[0] || blk.block_name) : '';
    const blockCodeSuffix = blk ? `_${blk.block_code}` : '_01';

    // 1. Tên tiện ích - tự động sinh theo danh mục và tòa nhà
    const baseName = preset ? preset.suggestedName : cat.category_name;
    const defaultName = blk ? `${baseName} - ${blockLabel}` : baseName;
    setAmenityName(defaultName);

    // 2. Mã tiện ích - tự động sinh theo tiền tố và mã tòa nhà
    const cleanPrefix = preset
      ? preset.codePrefix
      : cat.category_code.replace(/[^A-Za-z0-9]/g, '_').toUpperCase().slice(0, 10);
    setAmenityCode(`${cleanPrefix}${blockCodeSuffix}`);

    // 3. Vị trí chi tiết - tự động sinh theo tầng và tòa nhà
    const defaultLocation = preset
      ? (blk ? `${preset.location} (${blockLabel})` : preset.location)
      : (blk ? `Tòa nhà ${blockLabel} - Khu vực tiện ích` : 'Khuôn viên tiện ích chung toàn khu');
    setLocationDetail(defaultLocation);

    // 4. Sức chứa & Biểu phí tài chính
    if (preset) {
      setMaxCapacity(preset.maxCapacity);
      setHourlyRate(preset.hourlyRate);
      setSecurityDeposit(preset.securityDeposit);
      setAdvanceDays(preset.advanceDays);
      setMinCancelHours(preset.minCancelHours);
      setRequiresApproval(preset.requiresApproval);
      setRules(preset.rules);
      if (!coverImageUrl || force) {
        setCoverImageUrl(preset.coverImageUrl);
      }
    } else {
      setMaxCapacity(15);
      setHourlyRate(0);
      setSecurityDeposit(0);
      setAdvanceDays(7);
      setMinCancelHours(12);
      setRequiresApproval(false);
      setRules(`Tuân thủ quy chế và nội quy sử dụng của ${cat.category_name}. Giữ gìn vệ sinh và an ninh trật tự chung.`);
    }
  };

  const handleCategoryChange = (newCatId: string) => {
    setCategoryId(newCatId);
    if (newCatId) {
      applyCategoryPreset(newCatId, blockId, true);
    } else {
      setAmenityName('');
      setAmenityCode('');
      setLocationDetail('');
    }
  };

  const handleBlockChange = (newBlockId: string) => {
    setBlockId(newBlockId);
    if (categoryId) {
      applyCategoryPreset(categoryId, newBlockId, false);
    }
  };

  useEffect(() => {
    if (isOpen) {
      if (amenity) {
        setCategoryId(amenity.category_id);
        setBlockId(amenity.block_id || '');
        setAmenityName(amenity.amenity_name);
        setAmenityCode(amenity.amenity_code);
        setLocationDetail(amenity.location_detail);
        setMaxCapacity(amenity.max_capacity_per_slot);
        setHourlyRate(Number(amenity.hourly_rate) || 0);
        setSecurityDeposit(Number(amenity.security_deposit_required) || 0);
        setAdvanceDays(amenity.advance_booking_days_limit);
        setMinCancelHours(amenity.min_cancel_hours_before);
        setRequiresApproval(amenity.requires_admin_approval);
        setRules(amenity.rules_and_regulations || '');
        setCoverImageUrl(amenity.cover_image_url || '');
        setGalleryImages(Array.isArray(amenity.gallery_images) ? amenity.gallery_images : []);
        setNewGalleryUrl('');
        setIsActive(amenity.is_active);
      } else {
        // Khởi tạo ban đầu: các ô danh mục, tên, mã, vị trí hoàn toàn TRỐNG
        setCategoryId('');
        setBlockId('');
        setAmenityName('');
        setAmenityCode('');
        setLocationDetail('');
        setMaxCapacity(10);
        setHourlyRate(0);
        setSecurityDeposit(0);
        setAdvanceDays(7);
        setMinCancelHours(12);
        setRequiresApproval(false);
        setRules('');
        setCoverImageUrl('');
        setGalleryImages([]);
        setNewGalleryUrl('');
        setIsActive(true);
      }
      setErrorMessage(null);
    }
  }, [isOpen, amenity]);

  const handleAddGalleryImage = () => {
    const trimmed = newGalleryUrl.trim();
    if (!trimmed) return;
    if (galleryImages.includes(trimmed)) {
      setErrorMessage('Đường dẫn ảnh này đã có trong bộ sưu tập.');
      return;
    }
    setGalleryImages([...galleryImages, trimmed]);
    setNewGalleryUrl('');
    setErrorMessage(null);
  };

  const handleRemoveGalleryImage = (idxToRemove: number) => {
    setGalleryImages(galleryImages.filter((_, idx) => idx !== idxToRemove));
  };

  const handleReloadLatest = async () => {
    if (!amenity?.id) return;
    setIsReloading(true);
    try {
      const fresh = await api.getAmenity(amenity.id);
      setCategoryId(fresh.category_id);
      setBlockId(fresh.block_id || '');
      setAmenityName(fresh.amenity_name);
      setAmenityCode(fresh.amenity_code);
      setLocationDetail(fresh.location_detail);
      setMaxCapacity(fresh.max_capacity_per_slot);
      setHourlyRate(Number(fresh.hourly_rate) || 0);
      setSecurityDeposit(Number(fresh.security_deposit_required) || 0);
      setAdvanceDays(fresh.advance_booking_days_limit);
      setMinCancelHours(fresh.min_cancel_hours_before);
      setRequiresApproval(fresh.requires_admin_approval);
      setRules(fresh.rules_and_regulations || '');
      setCoverImageUrl(fresh.cover_image_url || '');
      setGalleryImages(Array.isArray(fresh.gallery_images) ? fresh.gallery_images : []);
      setIsActive(fresh.is_active);
      // Sync fresh version
      amenity.version = fresh.version;
      setIsConflict(false);
      setErrorMessage(null);
    } catch (err: any) {
      setErrorMessage('Không thể tải lại dữ liệu tiện ích: ' + (err.message || ''));
    } finally {
      setIsReloading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!categoryId) {
      setErrorMessage('Vui lòng chọn danh mục tiện ích.');
      return;
    }
    if (!amenityName.trim()) {
      setErrorMessage('Vui lòng nhập tên tiện ích.');
      return;
    }
    if (!amenityCode.trim()) {
      setErrorMessage('Vui lòng nhập mã tiện ích.');
      return;
    }
    if (!locationDetail.trim()) {
      setErrorMessage('Vui lòng nhập vị trí chi tiết.');
      return;
    }
    if (maxCapacity <= 0) {
      setErrorMessage('Sức chứa tối đa phải lớn hơn 0.');
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);
    setIsConflict(false);

    const payload = {
      category_id: categoryId,
      block_id: blockId ? blockId : null,
      amenity_name: amenityName.trim(),
      amenity_code: amenityCode.trim().toUpperCase(),
      location_detail: locationDetail.trim(),
      max_capacity_per_slot: Number(maxCapacity),
      hourly_rate: Number(hourlyRate),
      security_deposit_required: Number(securityDeposit),
      advance_booking_days_limit: Number(advanceDays),
      min_cancel_hours_before: Number(minCancelHours),
      requires_admin_approval: requiresApproval,
      rules_and_regulations: rules.trim() || null,
      cover_image_url: coverImageUrl.trim() || null,
      gallery_images: galleryImages,
      is_active: isActive,
    };

    try {
      if (isEdit && amenity) {
        await api.updateAmenity(amenity.id, {
          ...payload,
          version: amenity.version || 1,
        });
      } else {
        await api.createAmenity(payload);
      }
      onSuccess();
      onClose();
    } catch (err: any) {
      const msg = err.message || 'Đã xảy ra lỗi khi lưu tiện ích.';
      setErrorMessage(msg);
      if (
        msg.includes('409') ||
        msg.toLowerCase().includes('người dùng khác') ||
        msg.toLowerCase().includes('phiên bản')
      ) {
        setIsConflict(true);
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
      {/* Backdrop */}
      <div onClick={onClose} className="fixed inset-0 bg-neutral-950/45 backdrop-blur-md animate-in fade-in" />

      {/* Modal Card */}
      <div className="relative w-full max-w-3xl bg-white/95 backdrop-blur-2xl border border-white/90 rounded-2xl shadow-2xl p-6 text-neutral-900 z-10 max-h-[92vh] flex flex-col my-auto">
        {/* Header */}
        <div className="flex items-center justify-between pb-4 border-b border-neutral-200/80 shrink-0">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-neutral-900 text-white flex items-center justify-center shadow-xs">
              <Building className="w-4 h-4" />
            </div>
            <div>
              <h2 className="text-base font-bold text-neutral-950 leading-tight">
                {isEdit ? 'Chỉnh sửa Cấu hình Tiện ích' : 'Thêm Tiện ích Mới'}
              </h2>
              <p className="text-xs text-neutral-500">
                {isEdit
                  ? `Mã: ${amenity?.amenity_code} • Cấu hình mới áp dụng cho các booking sau này`
                  : 'Khai báo thông tin, sức chứa và biểu phí tiện ích'}
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

        {/* Error Alert */}
        {errorMessage && !isConflict && (
          <div className="mt-3 p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2 shrink-0">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{errorMessage}</span>
          </div>
        )}

        {/* OCC Conflict Alert Banner */}
        {isConflict && (
          <div className="mt-3 p-3.5 rounded-xl bg-amber-50 border border-amber-300 text-amber-900 text-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shrink-0 shadow-xs animate-in fade-in">
            <div className="flex items-start gap-2.5">
              <AlertCircle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
              <div>
                <span className="font-bold text-amber-950">Phát hiện xung đột cập nhật (OCC - 409 Conflict):</span>
                <p className="text-amber-800 mt-0.5">
                  {errorMessage || 'Dữ liệu tiện ích này vừa được người khác cập nhật trước đó. Vui lòng tải lại dữ liệu mới nhất để tránh ghi đè mất thông tin.'}
                </p>
              </div>
            </div>
            <button
              type="button"
              onClick={handleReloadLatest}
              disabled={isReloading}
              className="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-medium transition-colors shrink-0 flex items-center gap-1.5 shadow-xs disabled:opacity-50 text-xs cursor-pointer"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${isReloading ? 'animate-spin' : ''}`} />
              {isReloading ? 'Đang tải...' : 'Tải lại dữ liệu mới nhất'}
            </button>
          </div>
        )}

        {/* Notice Badge */}
        <div className="mt-3 p-2.5 bg-blue-50/70 border border-blue-200/80 rounded-lg text-xs text-blue-900 flex items-start gap-2 shrink-0">
          <Info className="w-4 h-4 text-blue-600 shrink-0 mt-0.5" />
          <div>
            <span className="font-semibold">Lưu ý phân biệt:</span> Sức chứa tối đa (
            <code className="font-mono bg-blue-100 px-1 py-0.2 rounded text-[11px]">max_capacity_per_slot</code>) là số
            người tối đa trong 1 lượt đặt. Số lượt booking tối đa (
            <code className="font-mono bg-blue-100 px-1 py-0.2 rounded text-[11px]">max_bookings</code>) thuộc phân hệ Cấu
            hình Khung giờ (Time Slots).
          </div>
        </div>

        {/* Form Body */}
        <form onSubmit={handleSubmit} className="mt-4 flex-1 overflow-y-auto space-y-4 pr-1">
          {/* Section 1: Basic Info */}
          <div className="p-4 bg-neutral-50/70 border border-neutral-200/70 rounded-xl space-y-3">
            <div className="text-xs font-bold text-neutral-900 uppercase tracking-wider">
              1. Thông tin định danh & vị trí
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Danh mục tiện ích <span className="text-rose-500">*</span>
                </label>
                <select
                  required
                  value={categoryId}
                  onChange={(e) => handleCategoryChange(e.target.value)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 cursor-pointer"
                >
                  <option value="">-- Chọn danh mục --</option>
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.category_name} ({c.category_code})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">Tòa nhà trực thuộc</label>
                <select
                  value={blockId}
                  onChange={(e) => handleBlockChange(e.target.value)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 cursor-pointer"
                >
                  <option value="">Toàn khu dân cư (Dùng chung)</option>
                  {blocks.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.block_name} ({b.block_code})
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div className="sm:col-span-2">
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Tên tiện ích
                </label>
                <input
                  type="text"
                  required
                  readOnly
                  placeholder="Tự động điền khi chọn danh mục & tòa nhà"
                  value={amenityName}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-neutral-100/90 text-neutral-700 cursor-not-allowed font-medium focus:outline-none select-all"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Mã tiện ích
                </label>
                <input
                  type="text"
                  required
                  readOnly
                  placeholder="Tự động điền"
                  value={amenityCode}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-neutral-100/90 text-neutral-700 cursor-not-allowed uppercase font-mono font-medium focus:outline-none select-all"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Vị trí chi tiết
              </label>
              <input
                type="text"
                required
                readOnly
                placeholder="Tự động điền khi chọn danh mục & tòa nhà"
                value={locationDetail}
                className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-neutral-100/90 text-neutral-700 cursor-not-allowed font-medium focus:outline-none select-all"
              />
            </div>
          </div>

          {/* Section 2: Capacity & Financial Config */}
          <div className="p-4 bg-neutral-50/70 border border-neutral-200/70 rounded-xl space-y-3">
            <div className="text-xs font-bold text-neutral-900 uppercase tracking-wider">
              2. Sức chứa & Cấu hình chi phí
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Sức chứa / slot (người) <span className="text-rose-500">*</span>
                </label>
                <input
                  type="number"
                  required
                  min={1}
                  value={maxCapacity}
                  onChange={(e) => setMaxCapacity(parseInt(e.target.value) || 1)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
                <span className="text-[10px] text-neutral-400">Số người tối đa trong 1 lượt</span>
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">Giá thuê / giờ (VNĐ)</label>
                <input
                  type="number"
                  min={0}
                  step={5000}
                  value={hourlyRate}
                  onChange={(e) => setHourlyRate(parseFloat(e.target.value) || 0)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
                <span className="text-[10px] text-neutral-400">0 = Miễn phí sử dụng</span>
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">Tiền cọc đảm bảo (VNĐ)</label>
                <input
                  type="number"
                  min={0}
                  step={10000}
                  value={securityDeposit}
                  onChange={(e) => setSecurityDeposit(parseFloat(e.target.value) || 0)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
                <span className="text-[10px] text-neutral-400">Hoàn trả sau khi check-out</span>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Đặt trước tối đa (ngày)
                </label>
                <input
                  type="number"
                  min={0}
                  value={advanceDays}
                  onChange={(e) => setAdvanceDays(parseInt(e.target.value) || 0)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
                <span className="text-[10px] text-neutral-400">Cho phép cư dân đăng ký trước N ngày</span>
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-700 mb-1">
                  Hủy trước tối thiểu (giờ)
                </label>
                <input
                  type="number"
                  min={0}
                  value={minCancelHours}
                  onChange={(e) => setMinCancelHours(parseInt(e.target.value) || 0)}
                  className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900 font-mono"
                />
                <span className="text-[10px] text-neutral-400">Chặn hủy nếu cận giờ dưới N tiếng</span>
              </div>
            </div>
          </div>

          {/* Section 3: Operations & Rules */}
          <div className="p-4 bg-neutral-50/70 border border-neutral-200/70 rounded-xl space-y-3">
            <div className="text-xs font-bold text-neutral-900 uppercase tracking-wider">
              3. Vận hành & Quy chế
            </div>

            <div className="flex flex-wrap items-center gap-6 py-1">
              <label className="inline-flex items-center gap-2 text-xs font-medium text-neutral-800 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={requiresApproval}
                  onChange={(e) => setRequiresApproval(e.target.checked)}
                  className="w-4 h-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900 cursor-pointer"
                />
                <span>Yêu cầu Ban Quản Lý phê duyệt trước khi xác nhận</span>
              </label>

              <label className="inline-flex items-center gap-2 text-xs font-medium text-neutral-800 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={isActive}
                  onChange={(e) => setIsActive(e.target.checked)}
                  className="w-4 h-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900 cursor-pointer"
                />
                <span>Kích hoạt trạng thái hoạt động ngay</span>
              </label>
            </div>

            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Quy định & Nội quy sử dụng
              </label>
              <textarea
                rows={3}
                placeholder="VD: Không mang giày dép vào sàn gỗ, giữ vệ sinh chung, tắt điện khi ra về..."
                value={rules}
                onChange={(e) => setRules(e.target.value)}
                className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Đường dẫn ảnh đại diện (Cover Image URL)
              </label>
              <input
                type="url"
                placeholder="https://images.unsplash.com/..."
                value={coverImageUrl}
                onChange={(e) => setCoverImageUrl(e.target.value)}
                className="w-full px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
              />
            </div>

            {/* Gallery Images */}
            <div className="pt-1">
              <label className="block text-xs font-semibold text-neutral-700 mb-1">
                Bộ sưu tập hình ảnh (Gallery Images)
              </label>
              <div className="flex gap-2">
                <input
                  type="url"
                  placeholder="Nhập URL ảnh để thêm vào bộ sưu tập..."
                  value={newGalleryUrl}
                  onChange={(e) => setNewGalleryUrl(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      handleAddGalleryImage();
                    }
                  }}
                  className="flex-1 px-3 py-2 text-xs border border-neutral-300 rounded-lg bg-white focus:outline-none focus:ring-1 focus:ring-neutral-900"
                />
                <button
                  type="button"
                  onClick={handleAddGalleryImage}
                  className="px-3 py-2 bg-neutral-100 hover:bg-neutral-200 text-neutral-800 text-xs font-semibold rounded-lg flex items-center gap-1 transition-colors cursor-pointer shrink-0"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Thêm ảnh</span>
                </button>
              </div>

              {galleryImages.length > 0 ? (
                <div className="mt-2.5 grid grid-cols-3 sm:grid-cols-4 gap-2">
                  {galleryImages.map((imgUrl, idx) => (
                    <div key={idx} className="relative group rounded-lg overflow-hidden border border-neutral-200 bg-neutral-100 aspect-video flex items-center justify-center">
                      <img
                        src={imgUrl}
                        alt={`Gallery ${idx + 1}`}
                        className="w-full h-full object-cover"
                        onError={(e) => {
                          (e.target as HTMLElement).style.display = 'none';
                        }}
                      />
                      <div className="absolute inset-0 bg-neutral-950/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                        <button
                          type="button"
                          onClick={() => handleRemoveGalleryImage(idx)}
                          className="p-1 bg-rose-600 text-white rounded hover:bg-rose-700 transition-colors shadow"
                          title="Xóa ảnh này"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-[11px] text-neutral-400 mt-1">Chưa có ảnh trong gallery. Thêm các URL ảnh minh họa tiện ích tại đây.</p>
              )}
            </div>
          </div>

          {/* Footer Submit Buttons */}
          <div className="pt-2 flex items-center justify-end gap-2.5">
            <button
              type="button"
              disabled={isSubmitting}
              onClick={onClose}
              className="px-4 py-2 text-xs font-semibold text-neutral-600 hover:bg-neutral-100 rounded-lg transition-colors cursor-pointer"
            >
              Hủy bỏ
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="px-5 py-2.5 bg-neutral-950 hover:bg-neutral-800 active:scale-95 text-white text-xs font-semibold rounded-lg flex items-center gap-1.5 transition-all shadow-sm cursor-pointer"
            >
              {isSubmitting ? (
                <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
              ) : (
                <Check className="w-3.5 h-3.5" />
              )}
              <span>{isEdit ? 'Lưu cấu hình' : 'Tạo tiện ích'}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
