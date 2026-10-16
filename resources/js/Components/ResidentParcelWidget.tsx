import React, { useState, useEffect, useCallback } from 'react';
import { Package, KeyRound, QrCode, Copy, Check, RefreshCw, Clock, Building, ShieldCheck, ChevronRight, X } from 'lucide-react';
import { api, ParcelItem } from '../Services/api';

export const ResidentParcelWidget: React.FC = () => {
  const [parcels, setParcels] = useState<ParcelItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [copiedPin, setCopiedPin] = useState<string | null>(null);
  const [selectedPhoto, setSelectedPhoto] = useState<string | null>(null);
  const [highlightedParcelId, setHighlightedParcelId] = useState<string | null>(() => {
    try {
      return new URLSearchParams(window.location.search).get('view_parcel');
    } catch {
      return null;
    }
  });

  const fetchResidentParcels = useCallback(async () => {
    setLoading(true);
    try {
      // Lấy các bưu kiện đang chờ nhận tại quầy lễ tân
      const res = await api.getParcels({ status: 'PENDING', limit: 10 });
      if (res.success && Array.isArray(res.data)) {
        setParcels(res.data);
      }
    } catch (err) {
      console.warn('Không thể tải danh sách bưu phẩm cư dân:', err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchResidentParcels();
  }, [fetchResidentParcels]);

  // Cuộn tới bưu phẩm được highlight từ URL query view_parcel
  useEffect(() => {
    if (highlightedParcelId) {
      const el = document.getElementById(`parcel-card-${highlightedParcelId}`);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
  }, [highlightedParcelId, parcels]);

  const handleCopyPin = (pin: string) => {
    try {
      navigator.clipboard.writeText(pin);
      setCopiedPin(pin);
      setTimeout(() => setCopiedPin(null), 2500);
    } catch {
      // fallback
    }
  };

  const formatDate = (dateStr: string) => {
    try {
      const d = new Date(dateStr);
      return d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) + ', ' + d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } catch {
      return dateStr;
    }
  };

  return (
    <div className="p-5 sm:p-6 rounded-2xl border border-neutral-200 bg-white shadow-2xs space-y-4">
      {/* Header Widget */}
      <div className="flex items-center justify-between border-b border-neutral-100 pb-3">
        <div className="flex items-center gap-2.5">
          <div className="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center border border-indigo-100 shrink-0">
            <Package className="w-5 h-5 text-indigo-600" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="text-sm sm:text-base font-bold text-neutral-900">
                Bưu phẩm chờ nhận tại Quầy Lễ tân
              </h3>
              {parcels.length > 0 && (
                <span className="px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
                  {parcels.length} kiện
                </span>
              )}
            </div>
            <p className="text-xs text-neutral-500">
              Kiện hàng được Lễ tân tiếp nhận kèm Mã PIN bảo mật để bạn nhận đồ.
            </p>
          </div>
        </div>

        <button
          type="button"
          onClick={fetchResidentParcels}
          disabled={loading}
          className="p-2 text-neutral-500 hover:text-indigo-600 hover:bg-neutral-50 rounded-xl transition cursor-pointer disabled:opacity-50"
          title="Làm mới danh sách bưu phẩm"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin text-indigo-600' : ''}`} />
        </button>
      </div>

      {/* Danh sách bưu kiện */}
      {loading && parcels.length === 0 ? (
        <div className="py-8 text-center text-xs text-neutral-400 flex flex-col items-center gap-2">
          <RefreshCw className="w-5 h-5 animate-spin text-indigo-500" />
          <span>Đang tải thông tin bưu phẩm...</span>
        </div>
      ) : parcels.length === 0 ? (
        <div className="py-6 text-center text-xs text-neutral-500 bg-neutral-50 rounded-xl border border-dashed border-neutral-200">
          <Package className="w-8 h-8 text-neutral-300 mx-auto mb-2" />
          <p className="font-medium text-neutral-600">Hiện tại không có bưu phẩm nào đang chờ nhận.</p>
          <p className="text-neutral-400 text-2xs mt-0.5">Khi có bưu phẩm mới, Lễ tân tiếp nhận và hệ thống sẽ gửi thông báo tức thì đến bạn.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {parcels.map((parcel) => {
            const isTarget = highlightedParcelId === parcel.id;
            return (
              <div
                key={parcel.id}
                id={`parcel-card-${parcel.id}`}
                className={`p-4 rounded-xl border transition-all ${
                  isTarget
                    ? 'border-indigo-500 bg-indigo-50/40 shadow-md ring-2 ring-indigo-200'
                    : 'border-neutral-200 bg-neutral-50/60 hover:bg-white hover:border-neutral-300 hover:shadow-xs'
                }`}
              >
                <div className="flex items-start gap-3">
                  {/* Thumbnail ảnh bưu kiện */}
                  <div className="relative shrink-0">
                    {parcel.parcel_photo_url ? (
                      <img
                        src={parcel.parcel_photo_url}
                        alt="Ảnh kiện hàng"
                        onClick={() => setSelectedPhoto(parcel.parcel_photo_url)}
                        className="w-16 h-16 sm:w-20 sm:h-20 rounded-lg object-cover border border-neutral-200 cursor-pointer hover:opacity-90 shadow-2xs"
                      />
                    ) : (
                      <div className="w-16 h-16 sm:w-20 sm:h-20 rounded-lg bg-neutral-200/70 border border-neutral-300 flex items-center justify-center text-neutral-400">
                        <Package className="w-6 h-6" />
                      </div>
                    )}
                    <span className="absolute -bottom-1.5 left-1/2 -translate-x-1/2 text-3xs font-semibold px-1.5 py-0.2 rounded bg-neutral-900/80 text-white whitespace-nowrap">
                      Xem ảnh
                    </span>
                  </div>

                  {/* Thông tin kiện hàng */}
                  <div className="flex-1 min-w-0 space-y-1.5">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-xs font-bold text-neutral-900 truncate font-mono">
                        {parcel.tracking_number}
                      </span>
                      <span className="text-3xs font-bold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 shrink-0">
                        {parcel.courier_company}
                      </span>
                    </div>

                    <div className="text-2xs text-neutral-500 flex items-center gap-1.5">
                      <Clock className="w-3 h-3 text-neutral-400 shrink-0" />
                      <span>Nhận lúc: {formatDate(parcel.received_at || parcel.created_at)}</span>
                    </div>

                    {parcel.apartment && (
                      <div className="text-2xs text-neutral-500 flex items-center gap-1.5">
                        <Building className="w-3 h-3 text-neutral-400 shrink-0" />
                        <span>Căn hộ: <strong className="text-neutral-700">{parcel.apartment.apartment_number}</strong></span>
                      </div>
                    )}

                    {/* Khung Mã PIN nhận hàng cực kỳ nổi bật */}
                    <div className="pt-2 flex items-center justify-between gap-2 bg-emerald-50 border border-emerald-200 rounded-lg px-2.5 py-1.5 mt-2">
                      <div className="flex items-center gap-1.5">
                        <KeyRound className="w-3.5 h-3.5 text-emerald-600 shrink-0" />
                        <span className="text-2xs text-emerald-700 font-medium">MÃ PIN:</span>
                        <span className="text-xs sm:text-sm font-extrabold text-emerald-900 font-mono tracking-wider">
                          {parcel.pickup_pin_code || '123456'}
                        </span>
                      </div>

                      <button
                        type="button"
                        onClick={() => handleCopyPin(parcel.pickup_pin_code || '')}
                        className="inline-flex items-center gap-1 px-2 py-0.5 text-3xs font-semibold text-emerald-700 bg-white border border-emerald-300 rounded hover:bg-emerald-100/50 transition cursor-pointer"
                        title="Sao chép mã PIN"
                      >
                        {copiedPin === parcel.pickup_pin_code ? (
                          <>
                            <Check className="w-3 h-3 text-emerald-600" />
                            <span>Đã chép</span>
                          </>
                        ) : (
                          <>
                            <Copy className="w-3 h-3" />
                            <span>Chép mã</span>
                          </>
                        )}
                      </button>
                    </div>

                    <p className="text-3xs text-neutral-400 pt-0.5 italic">
                      * Đọc mã PIN này cho Lễ tân tại sảnh để nhận kiện hàng.
                    </p>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Modal xem ảnh bưu phẩm phóng to */}
      {selectedPhoto && (
        <div
          onClick={() => setSelectedPhoto(null)}
          className="fixed inset-0 z-[10000] bg-black/75 backdrop-blur-xs flex items-center justify-center p-4"
        >
          <div
            onClick={(e) => e.stopPropagation()}
            className="relative max-w-lg w-full bg-white rounded-2xl overflow-hidden shadow-2xl p-2"
          >
            <button
              type="button"
              onClick={() => setSelectedPhoto(null)}
              className="absolute top-4 right-4 z-10 w-8 h-8 rounded-full bg-black/60 text-white flex items-center justify-center hover:bg-black/80 transition cursor-pointer"
            >
              <X className="w-4 h-4" />
            </button>
            <img
              src={selectedPhoto}
              alt="Ảnh bưu phẩm phóng to"
              className="w-full max-h-[80vh] object-contain rounded-xl"
            />
          </div>
        </div>
      )}
    </div>
  );
};
