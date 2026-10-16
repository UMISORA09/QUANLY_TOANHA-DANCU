import React, { useState, useEffect, useCallback } from 'react';
import {
  Package,
  KeyRound,
  QrCode,
  Copy,
  Check,
  RefreshCw,
  Clock,
  Building,
  ShieldCheck,
  X,
  ExternalLink,
  Sparkles,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import api, { ParcelItem } from '../Services/api';

export const getParcelImageUrl = (url?: string | null): string => {
  if (!url) return '';
  let cleanPath = url.trim();
  if (cleanPath.includes('/storage/parcels/')) {
    const parts = cleanPath.split('/storage/parcels/');
    cleanPath = `/storage/parcels/${parts[parts.length - 1]}`;
  }
  if (cleanPath.startsWith('storage/')) {
    cleanPath = `/${cleanPath}`;
  }
  // Khi chạy dev server port 5173, tự động chuyển hướng /storage/ về backend 8000
  if (
    typeof window !== 'undefined' &&
    window.location.port === '5173' &&
    (cleanPath.startsWith('/storage/') || cleanPath.startsWith('/images/'))
  ) {
    return `http://127.0.0.1:8000${cleanPath}`;
  }
  return cleanPath;
};

export const ResidentParcelWidget: React.FC = () => {
  const [parcels, setParcels] = useState<ParcelItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [copiedPin, setCopiedPin] = useState<string | null>(null);
  const [selectedPhoto, setSelectedPhoto] = useState<string | null>(null);
  const [selectedQrCode, setSelectedQrCode] = useState<{ pin: string; qr: string; tracking: string } | null>(null);
  const [qrFailed, setQrFailed] = useState<boolean>(false);
  const [highlightedParcelId, setHighlightedParcelId] = useState<string | null>(() => {
    try {
      return new URLSearchParams(window.location.search).get('view_parcel');
    } catch {
      return null;
    }
  });
  const [currentPage, setCurrentPage] = useState<number>(1);
  const perPage = 4;
  const totalPages = Math.max(1, Math.ceil(parcels.length / perPage));
  const displayedParcels = parcels.slice((currentPage - 1) * perPage, currentPage * perPage);

  const fetchResidentParcels = useCallback(async () => {
    setLoading(true);
    try {
      // 1. Gọi API lấy bưu phẩm đang chờ nhận (PENDING maps to RECEIVED_AT_RECEPTION in backend)
      const res = await (api.getReceptionParcels
        ? api.getReceptionParcels({ status: 'PENDING', limit: 20 })
        : api.getParcels({ status: 'PENDING', limit: 20 }));

      let items: ParcelItem[] = [];
      if (res && res.success && Array.isArray(res.data)) {
        items = res.data;
      }

      // 2. Nếu có param view_parcel nhưng bưu phẩm chưa có trong danh sách top 20, fetch chi tiết
      const currentParamId = new URLSearchParams(window.location.search).get('view_parcel');
      if (currentParamId && !items.some((p) => p.id === currentParamId)) {
        try {
          const detailRes = await api.getParcelDetail(currentParamId);
          if (detailRes && detailRes.success && detailRes.data) {
            items = [detailRes.data, ...items];
          }
        } catch {
          // ignore
        }
      }

      setParcels(items);
    } catch (err) {
      console.warn('Không thể tải danh sách bưu phẩm cư dân:', err);
    } finally {
      setLoading(false);
    }
  }, []);

  // Lấy dữ liệu lúc mount
  useEffect(() => {
    fetchResidentParcels();
  }, [fetchResidentParcels]);

  // Lắng nghe thay đổi URL (popstate) để cập nhật highlightedParcelId
  useEffect(() => {
    const handleUrlChange = () => {
      try {
        const id = new URLSearchParams(window.location.search).get('view_parcel');
        if (id) {
          setHighlightedParcelId(id);
        }
      } catch {
        // ignore
      }
    };
    window.addEventListener('popstate', handleUrlChange);
    return () => window.removeEventListener('popstate', handleUrlChange);
  }, []);

  // Lắng nghe sự kiện tiếp nhận kiện hàng theo thời gian thực (Realtime & Broadcast across tabs)
  useEffect(() => {
    let bc: BroadcastChannel | null = null;
    try {
      if (typeof BroadcastChannel !== 'undefined') {
        bc = new BroadcastChannel('smartcassavas_parcels');
        bc.onmessage = (event) => {
          if (event.data?.type === 'PARCEL_RECEIVED') {
            fetchResidentParcels();
          }
        };
      }
    } catch {
      // BroadcastChannel không khả dụng
    }

    const handleCustomEvent = () => fetchResidentParcels();
    window.addEventListener('smartcassavas:parcel_received', handleCustomEvent);

    return () => {
      if (bc) bc.close();
      window.removeEventListener('smartcassavas:parcel_received', handleCustomEvent);
    };
  }, [fetchResidentParcels]);

  // Tự động chuyển tới trang chứa bưu phẩm được highlight
  useEffect(() => {
    if (highlightedParcelId && parcels.length > 0) {
      const idx = parcels.findIndex((p) => p.id === highlightedParcelId);
      if (idx >= 0) {
        const targetPage = Math.floor(idx / perPage) + 1;
        setCurrentPage(targetPage);
      }
    }
  }, [highlightedParcelId, parcels]);

  // Cuộn tới bưu phẩm được highlight từ URL query view_parcel
  useEffect(() => {
    if (highlightedParcelId && parcels.length > 0) {
      const timer = setTimeout(() => {
        const el = document.getElementById(`parcel-card-${highlightedParcelId}`);
        if (el) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }, 150);
      return () => clearTimeout(timer);
    }
  }, [highlightedParcelId, parcels, currentPage]);

  const handleCopyPin = (pin: string) => {
    try {
      navigator.clipboard.writeText(pin);
      setCopiedPin(pin);
      setTimeout(() => setCopiedPin(null), 2500);
    } catch {
      // fallback
    }
  };

  const formatDate = (dateStr?: string | null) => {
    if (!dateStr) return 'Vừa mới tiếp nhận';
    try {
      const d = new Date(dateStr);
      return (
        d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) +
        ', ' +
        d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' })
      );
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
          <p className="text-neutral-400 text-2xs mt-0.5">
            Khi có bưu phẩm mới, Lễ tân tiếp nhận và hệ thống sẽ gửi thông báo tức thì đến bạn.
          </p>
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {displayedParcels.map((parcel) => {
              const isTarget = highlightedParcelId === parcel.id;
              const photoUrl = getParcelImageUrl(parcel.parcel_photo_url);

            return (
              <div
                key={parcel.id}
                id={`parcel-card-${parcel.id}`}
                className={`p-4 rounded-xl border transition-all ${
                  isTarget
                    ? 'border-indigo-500 bg-indigo-50/50 shadow-md ring-2 ring-indigo-200 relative'
                    : 'border-neutral-200 bg-neutral-50/60 hover:bg-white hover:border-neutral-300 hover:shadow-xs'
                }`}
              >
                {isTarget && (
                  <span className="absolute -top-2.5 right-3 px-2 py-0.5 text-3xs font-bold bg-indigo-600 text-white rounded-full flex items-center gap-1 shadow-xs">
                    <Sparkles className="w-2.5 h-2.5" />
                    Kiện hàng vừa chọn
                  </span>
                )}

                <div className="flex items-start gap-3">
                  {/* Thumbnail ảnh bưu kiện */}
                  <div className="relative shrink-0">
                    {photoUrl ? (
                      <div className="group relative">
                        <img
                          src={photoUrl}
                          alt="Ảnh kiện hàng"
                          onClick={() => setSelectedPhoto(photoUrl)}
                          className="w-16 h-16 sm:w-20 sm:h-20 rounded-lg object-cover border border-neutral-200 cursor-pointer group-hover:opacity-90 shadow-2xs"
                        />
                        <span
                          onClick={() => setSelectedPhoto(photoUrl)}
                          className="absolute -bottom-1.5 left-1/2 -translate-x-1/2 text-3xs font-semibold px-1.5 py-0.2 rounded bg-neutral-900/80 text-white whitespace-nowrap cursor-pointer hover:bg-black"
                        >
                          Xem ảnh
                        </span>
                      </div>
                    ) : (
                      <div className="w-16 h-16 sm:w-20 sm:h-20 rounded-lg bg-neutral-200/70 border border-neutral-300 flex items-center justify-center text-neutral-400">
                        <Package className="w-6 h-6" />
                      </div>
                    )}
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
                        <span>
                          Căn hộ: <strong className="text-neutral-700">{parcel.apartment.apartment_number}</strong>
                        </span>
                      </div>
                    )}

                    {/* Khung Mã PIN nhận hàng cực kỳ nổi bật */}
                    <div className="pt-2 flex items-center justify-between gap-2 bg-emerald-50 border border-emerald-200 rounded-lg px-2.5 py-1.5 mt-2">
                      <div className="flex items-center gap-1.5">
                        <KeyRound className="w-3.5 h-3.5 text-emerald-600 shrink-0" />
                        <span className="text-2xs text-emerald-700 font-medium">MÃ PIN:</span>
                        <span className="text-sm sm:text-base font-extrabold text-emerald-900 font-mono tracking-wider">
                          {parcel.pickup_pin_code || '123456'}
                        </span>
                      </div>

                      <div className="flex items-center gap-1">
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
                              <span>Chép</span>
                            </>
                          )}
                        </button>

                        {parcel.pickup_qr_code && (
                          <button
                            type="button"
                            onClick={() => {
                              setQrFailed(false);
                              setSelectedQrCode({
                                pin: parcel.pickup_pin_code || '',
                                qr: parcel.pickup_qr_code || '',
                                tracking: parcel.tracking_number,
                              });
                            }}
                            className="p-1 text-emerald-700 bg-white border border-emerald-300 rounded hover:bg-emerald-100/50 transition cursor-pointer"
                            title="Xem mã QR lấy hàng"
                          >
                            <QrCode className="w-3 h-3" />
                          </button>
                        )}
                      </div>
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

        {/* Thanh phân trang hiển thị 4 kiện hàng / trang */}
        {parcels.length > perPage && (
          <div className="bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 flex items-center justify-between text-xs text-slate-500">
            <span>
              Hiển thị{' '}
              <strong className="text-slate-800">
                {(currentPage - 1) * perPage + 1}
              </strong>{' '}
              đến{' '}
              <strong className="text-slate-800">
                {Math.min(currentPage * perPage, parcels.length)}
              </strong>{' '}
              trên tổng <strong className="text-slate-800">{parcels.length}</strong> kiện
            </span>

            <div className="flex items-center gap-1.5">
              <button
                type="button"
                disabled={currentPage <= 1}
                onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                className="p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-white disabled:opacity-40 cursor-pointer"
                title="Trang trước"
              >
                <ChevronLeft className="w-4 h-4" />
              </button>
              <span className="px-2.5 py-1 font-semibold text-slate-800">
                {currentPage} / {totalPages}
              </span>
              <button
                type="button"
                disabled={currentPage >= totalPages}
                onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                className="p-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-white disabled:opacity-40 cursor-pointer"
                title="Trang sau"
              >
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </>
    )}

      {/* Modal xem ảnh bưu phẩm phóng to */}
      {selectedPhoto && (
        <div
          onClick={() => setSelectedPhoto(null)}
          className="fixed inset-0 z-[10000] bg-black/75 backdrop-blur-xs flex items-center justify-center p-4 animate-fadeIn"
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

      {/* Modal xem Mã QR & PIN nhận hàng lớn */}
      {selectedQrCode && (
        <div
          onClick={() => setSelectedQrCode(null)}
          className="fixed inset-0 z-[10000] bg-black/75 backdrop-blur-xs flex items-center justify-center p-4 animate-fadeIn"
        >
          <div
            onClick={(e) => e.stopPropagation()}
            className="relative max-w-sm w-full bg-white rounded-3xl overflow-hidden shadow-2xl p-6 text-center space-y-4"
          >
            <button
              type="button"
              onClick={() => setSelectedQrCode(null)}
              className="absolute top-4 right-4 p-1.5 rounded-full text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 transition cursor-pointer"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="w-12 h-12 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center mx-auto shadow-2xs">
              <QrCode className="w-6 h-6" />
            </div>

            <div>
              <h4 className="text-base font-bold text-neutral-900">Mã QR & PIN nhận bưu phẩm</h4>
              <p className="text-xs text-neutral-500 font-mono mt-0.5">Vận đơn: {selectedQrCode.tracking}</p>
            </div>

            {/* 1. Hình ảnh Mã QR quét nhanh (ở TRÊN "MÃ PIN 6 SỐ") */}
            <div className="p-3.5 bg-neutral-50/80 rounded-2xl border border-neutral-200 inline-block mx-auto shadow-2xs">
              <img
                src={
                  qrFailed
                    ? `https://quickchart.io/qr?text=${encodeURIComponent(selectedQrCode.qr || selectedQrCode.pin)}&size=340&margin=2`
                    : `https://api.qrserver.com/v1/create-qr-code/?size=340x340&margin=8&data=${encodeURIComponent(selectedQrCode.qr || selectedQrCode.pin)}`
                }
                alt={`Mã QR ${selectedQrCode.qr}`}
                onError={() => setQrFailed(true)}
                className="w-44 h-44 sm:w-48 sm:h-48 mx-auto object-contain bg-white p-2 rounded-xl border border-neutral-200 shadow-2xs"
              />
              <div className="mt-2 flex items-center justify-center gap-1.5 text-2xs text-neutral-500 font-mono">
                <span className="font-semibold">{selectedQrCode.qr}</span>
              </div>
            </div>

            {/* 2. MÃ PIN 6 SỐ (ở DƯỚI Mã QR) */}
            <div className="p-3.5 bg-emerald-50 rounded-2xl border border-emerald-200">
              <span className="text-xs text-emerald-700 font-semibold block mb-0.5">MÃ PIN 6 SỐ:</span>
              <span className="text-3xl font-black text-emerald-900 font-mono tracking-widest">
                {selectedQrCode.pin}
              </span>
            </div>

            <p className="text-xs text-neutral-500">
              Xuất trình mã QR này cho camera tại quầy Lễ tân hoặc đọc mã PIN 6 số để nhận kiện hàng.
            </p>
          </div>
        </div>
      )}
    </div>
  );
};
