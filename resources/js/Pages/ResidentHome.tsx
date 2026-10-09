import React, { useState, useEffect } from 'react';
import {
  Building2,
  Home,
  Receipt,
  AlertCircle,
  Sparkles,
  Users,
  User,
  MessageSquare,
  HelpCircle,
  PhoneCall,
  LogOut,
  ChevronDown,
  Bell,
  FileText,
  Building,
  ArrowUpRight,
  Clock,
  CheckCircle2,
  Maximize2,
  Minimize2,
  KeyRound,
  Search,
  Calendar,
  MapPin,
  SlidersHorizontal,
  Phone,
  ShieldCheck,
  X,
  ArrowRight,
  Check,
  Compass
} from 'lucide-react';
import { api, AmenityBookingNotification } from '../Services/api';
import { useAmenityNotifications } from '../Hooks/useAmenityNotifications';
import { AppLayout } from '../Components/Layout/AppLayout';
import { ResidentAmenityBookingPanel } from '../Components/ResidentAmenityBookingPanel';

export interface ResidentHomeProps {
  onLogout?: () => void;
  onNavigateHome?: () => void;
  onNavigateAdmin?: () => void;
  userRole?: string;
  residentType?: 'OWNER' | 'TENANT';
  userName?: string;
  userEmail?: string;
}

interface MenuItem {
  id: string;
  label: string;
  icon: React.ComponentType<{ className?: string }> | React.ElementType;
  badge?: string;
}

export const ResidentHome: React.FC<ResidentHomeProps> = ({
  onLogout,
  onNavigateHome,
  userName = 'Nguyễn Văn A',
  userEmail = 'nguyenvana@cassavas.vn',
  userRole,
  residentType = 'OWNER',
}) => {
  const notices = useAmenityNotifications('resident');
  const [isNotificationOpen, setIsNotificationOpen] = useState(false);
  const openAmenityNotice = async (item: AmenityBookingNotification) => {
    const target = new URL(item.deepLink, window.location.origin);
    if (target.origin !== window.location.origin || target.searchParams.get('tab') !== 'amenities') return;
    window.history.pushState({}, '', '/cu-dan' + target.search);
    setActiveMenuId('amenities');
    window.dispatchEvent(new PopStateEvent('popstate'));
    setIsNotificationOpen(false);
    notices.setAlert(null);
    await notices.markRead(item);
  };
  const [activeMenuId, setActiveMenuId] = useState<string>(() => {
    try {
      const urlTab = new URLSearchParams(window.location.search).get('tab');
      if (urlTab) return urlTab;
    } catch {
      // ignore
    }
    return residentType === 'TENANT' ? 'rentals' : 'overview';
  });

  useEffect(() => {
    try {
      const urlTab = new URLSearchParams(window.location.search).get('tab');
      if (urlTab) {
        setActiveMenuId(urlTab);
      } else if (residentType === 'TENANT') {
        setActiveMenuId('rentals');
      }
    } catch {
      // ignore
    }
  }, [residentType]);

  useEffect(() => {
    const syncTab = () => setActiveMenuId(new URLSearchParams(window.location.search).get('tab') || (residentType === 'TENANT' ? 'rentals' : 'overview'));
    window.addEventListener('popstate', syncTab);
    return () => window.removeEventListener('popstate', syncTab);
  }, [residentType]);

  const selectMenu = (id: string) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    window.history.pushState({}, '', url);
    setActiveMenuId(id);
  };

  const [data, setData] = useState<any>(null);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // Rental Listings State
  const [rentalBlocks, setRentalBlocks] = useState<any[]>([]);
  const [rentalListings, setRentalListings] = useState<any[]>([]);
  const [isLoadingRentals, setIsLoadingRentals] = useState<boolean>(false);
  const [selectedBlockFilter, setSelectedBlockFilter] = useState<string>('ALL');
  const [selectedBedroomFilter, setSelectedBedroomFilter] = useState<string>('ALL');
  const [searchQuery, setSearchQuery] = useState<string>('');

  // Contact / Schedule View Modal State
  const [selectedListingForSchedule, setSelectedListingForSchedule] = useState<any | null>(null);
  const [scheduleDate, setScheduleDate] = useState<string>('');
  const [scheduleTime, setScheduleTime] = useState<string>('09:30');
  const [scheduleNote, setScheduleNote] = useState<string>('');
  const [isSubmittingSchedule, setIsSubmittingSchedule] = useState<boolean>(false);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => {
      setToastMessage(null);
    }, 3000);
  };

  // Menu items including Rental Marketplace
  const menuItems: MenuItem[] = [
    { id: 'overview', label: 'Tổng quan Cư dân', icon: Home },
    { id: 'rentals', label: 'Tòa nhà & Căn hộ cho thuê', icon: Building2, badge: 'HOT' },
    { id: 'billing', label: 'Hóa đơn của tôi', icon: Receipt, badge: '1' },
    { id: 'tickets', label: 'Báo cáo sự cố', icon: AlertCircle, badge: '2' },
    { id: 'amenities', label: 'Đặt tiện ích', icon: Sparkles },
    { id: 'visitors', label: 'Khai báo khách', icon: Users },
    { id: 'profile', label: 'Thông tin cá nhân', icon: User },
    { id: 'community', label: 'Cộng đồng cư dân', icon: MessageSquare },
    { id: 'feedback', label: 'Góp ý & Khảo sát', icon: FileText },
    { id: 'contact_pet', label: 'Liên hệ & Thú cưng', icon: PhoneCall },
  ];

  // Fetch resident overview data
  useEffect(() => {
    const fetchData = async () => {
      try {
        const res = await api.getResidentOverview();
        setData(res);
      } catch (err) {
        console.warn('Sử dụng dữ liệu tĩnh khung xương:', err);
      }
    };
    fetchData();
  }, []);

  // Fetch rental listings from API
  useEffect(() => {
    if (activeMenuId !== 'rentals') return;
    let active = true;
    setIsLoadingRentals(true);
    api.getRentalListings()
      .then((res) => {
        if (active && res?.data) {
          setRentalBlocks(res.data.blocks || []);
          setRentalListings(res.data.listings || []);
        }
      })
      .catch((err) => {
        console.warn('Lỗi khi tải danh sách căn hộ cho thuê:', err);
      })
      .finally(() => {
        if (active) setIsLoadingRentals(false);
      });
    return () => { active = false; };
  }, [activeMenuId]);

  const userDisplayName = data?.user?.full_name || userName || 'Nguyễn Văn A';
  const userDisplayEmail = data?.user?.email || userEmail || 'nguyenvana@cassavas.vn';
  const apartmentNumber = residentType === 'TENANT' ? 'Khách thuê' : 'A1-05';

  // Filter listings
  const filteredListings = rentalListings.filter((item) => {
    if (selectedBlockFilter !== 'ALL' && item.block_code !== selectedBlockFilter) {
      return false;
    }
    if (selectedBedroomFilter !== 'ALL') {
      const targetBeds = parseInt(selectedBedroomFilter, 10);
      if (item.bedroom_count !== targetBeds) return false;
    }
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase();
      const matchNumber = item.apartment_number?.toLowerCase().includes(q);
      const matchBlock = item.block_name?.toLowerCase().includes(q);
      if (!matchNumber && !matchBlock) return false;
    }
    return true;
  });

  const handleOpenScheduleModal = (listing: any) => {
    setSelectedListingForSchedule(listing);
    // default tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    setScheduleDate(tomorrow.toISOString().split('T')[0]);
    setScheduleNote('');
  };

  const handleConfirmSchedule = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedListingForSchedule) return;

    setIsSubmittingSchedule(true);
    setTimeout(() => {
      setIsSubmittingSchedule(false);
      showToast(`Đã gửi yêu cầu đặt lịch xem Căn ${selectedListingForSchedule.apartment_number} (${selectedListingForSchedule.block_name}) thành công!`);
      setSelectedListingForSchedule(null);
    }, 600);
  };

  return (
    <AppLayout
      role="resident"
      userRole={userRole as any}
      activeItemId={activeMenuId}
      onItemClick={selectMenu}
      customItems={menuItems}
      userName={userDisplayName}
      userEmail={userDisplayEmail}
      onLogout={onLogout}
      onNavigateHome={onNavigateHome}
      statusText={residentType === 'TENANT' ? 'Cổng khách thuê & tìm căn hộ' : 'Cổng dịch vụ cư dân trực tuyến'}
      unreadNotificationCount={notices.unreadCount}
      onNotificationClick={() => { setIsNotificationOpen((value) => !value); notices.retry(); }}
      extraTopbarActions={
        <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-neutral-200/80 text-xs font-medium text-neutral-800 bg-white/85 backdrop-blur-md shadow-xs hover:border-sky-300 transition-colors">
          <Building className="w-3.5 h-3.5 text-sky-600" />
          <span>
            {residentType === 'TENANT' ? (
              <span className="text-emerald-700 font-semibold">Tài khoản Khách thuê</span>
            ) : (
              <>
                Căn hộ <strong className="font-semibold text-neutral-950">{apartmentNumber}</strong>
              </>
            )}
          </span>
        </div>
      }
    >
      {notices.alert && <div role="status" className="fixed bottom-5 right-4 z-[60] w-[calc(100%-2rem)] max-w-sm rounded-2xl border border-emerald-200 bg-white p-4 shadow-lg"><div className="flex items-start justify-between gap-3"><div><p className="text-sm font-bold text-emerald-800">{notices.alert.title}</p><p className="mt-1 text-xs text-neutral-600">{notices.alert.message}</p></div><button type="button" aria-label="Đóng cảnh báo tiện ích" onClick={() => notices.setAlert(null)} className="rounded-lg p-1"><X className="h-4 w-4" /></button></div><button type="button" onClick={() => void openAmenityNotice(notices.alert!)} className="mt-3 rounded-xl bg-emerald-700 px-3 py-2 text-sm font-semibold text-white">Xem đăng ký</button></div>}
      {isNotificationOpen && <section aria-label="Thông báo tiện ích cư dân" className="fixed top-20 right-4 z-[60] w-[calc(100%-2rem)] max-w-sm rounded-2xl border border-neutral-200 bg-white p-4 shadow-lg"><div className="flex justify-between"><h2 className="font-bold text-sm">Thông báo tiện ích · {notices.unreadCount} chưa đọc</h2><button type="button" aria-label="Đóng thông báo cư dân" onClick={() => setIsNotificationOpen(false)}><X className="h-4 w-4" /></button></div><label className="my-3 flex items-center gap-2 text-xs"><input type="checkbox" checked={notices.unreadOnly} onChange={(event) => notices.setUnreadOnly(event.target.checked)} />Chỉ thông báo chưa đọc</label><div className="max-h-[45vh] space-y-2 overflow-y-auto">{notices.loading && <p role="status" className="text-xs">Đang tải thông báo…</p>}{notices.error && <p role="alert" className="text-xs text-rose-700">Không thể tải thông báo. <button type="button" onClick={notices.retry} className="underline">Thử lại</button></p>}{!notices.loading && !notices.error && notices.items.length === 0 && <p className="text-xs text-neutral-500">Chưa có thông báo tiện ích.</p>}{notices.items.map((item) => <button type="button" key={item.id} onClick={() => void openAmenityNotice(item)} className={`w-full rounded-xl border p-3 text-left text-xs ${item.isRead ? 'border-neutral-200' : 'border-emerald-200 bg-emerald-50'}`}><span className="block font-semibold">{item.title}</span><span className="mt-1 block">{item.message}</span><span className="mt-1 block text-neutral-500">{item.timeAgo}</span></button>)}</div><div className="mt-3 flex justify-between text-xs"><button type="button" disabled={notices.loading || notices.page <= 1} onClick={() => notices.setPage(notices.page - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Thông báo trước</button><span>{notices.page}/{notices.pages}</span><button type="button" disabled={notices.loading || notices.page >= notices.pages} onClick={() => notices.setPage(notices.page + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">Thông báo sau</button></div></section>}
      <div className="p-6 sm:p-8 lg:p-10">
        {/* ================= VIEW 1: TÒA NHÀ & CĂN HỘ CHO THUÊ ================= */}
        {activeMenuId === 'rentals' ? (
          <div className="max-w-6xl mx-auto space-y-8 animate-in fade-in duration-300">
            {/* Header Title & Subtitle */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-1.5 text-xs text-neutral-400 uppercase tracking-wider font-semibold">
                  <Building2 className="w-3.5 h-3.5 text-emerald-600" />
                  <span>THỊ TRƯỜNG CHO THUÊ CHÍNH CHỦ</span>
                </div>
                <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight flex items-center gap-2.5">
                  Tòa nhà & Căn hộ cho thuê
                  <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300 shadow-2xs">
                    Chính chủ
                  </span>
                </h1>
                <p className="text-xs sm:text-sm text-neutral-500">
                  Xem danh sách các tòa chung cư và căn hộ do chủ sở hữu gửi cho thuê trực tiếp tại tòa nhà.
                </p>
              </div>

              {/* Action */}
              <div className="flex items-center gap-2.5">
                <div className="text-right hidden sm:block">
                  <div className="text-xs font-bold text-neutral-900">Hotline BQL hỗ trợ thuê</div>
                  <div className="text-xs text-neutral-500 font-mono">024 3999 8888 (Miễn phí)</div>
                </div>
                <a
                  href="tel:02439998888"
                  className="inline-flex items-center gap-2 px-4 py-2.5 bg-neutral-950 hover:bg-neutral-800 text-white rounded-xl text-xs font-semibold shadow-sm transition-all"
                >
                  <Phone className="w-3.5 h-3.5 text-emerald-400" />
                  <span>Gọi hỗ trợ</span>
                </a>
              </div>
            </div>

            {/* 3 Building Overview Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {rentalBlocks.length > 0 ? (
                rentalBlocks.map((block) => {
                  const isSelected = selectedBlockFilter === block.block_code;
                  return (
                    <div
                      key={block.id || block.block_code}
                      onClick={() => {
                        setSelectedBlockFilter(isSelected ? 'ALL' : block.block_code);
                      }}
                      className={`p-5 rounded-2xl border transition-all cursor-pointer relative overflow-hidden group ${
                        isSelected
                          ? 'bg-neutral-950 text-white border-neutral-950 shadow-md scale-[1.02]'
                          : 'bg-white/85 hover:bg-white border-neutral-200/90 text-neutral-900 hover:border-neutral-400 shadow-xs'
                      }`}
                    >
                      <div className="flex items-center justify-between mb-3">
                        <div
                          className={`w-10 h-10 rounded-xl flex items-center justify-center font-bold text-sm ${
                            isSelected ? 'bg-white/10 text-white' : 'bg-neutral-100 text-neutral-800'
                          }`}
                        >
                          <Building className="w-5 h-5" />
                        </div>
                        <span
                          className={`text-xs font-bold px-2 py-0.5 rounded-full ${
                            isSelected
                              ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                              : 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                          }`}
                        >
                          Đang cho thuê
                        </span>
                      </div>
                      <h3 className="font-bold text-sm mb-1">{block.block_name}</h3>
                      <p
                        className={`text-xs mb-3 truncate ${
                          isSelected ? 'text-neutral-400' : 'text-neutral-500'
                        }`}
                      >
                        {block.address_line || 'Khu phức hợp Smart Cassavas'}
                      </p>
                      <div
                        className={`pt-3 border-t flex items-center justify-between text-xs ${
                          isSelected ? 'border-white/10 text-neutral-300' : 'border-neutral-100 text-neutral-600'
                        }`}
                      >
                        <span>Quy mô: <strong>{block.total_floors} tầng</strong></span>
                        <span className="font-bold text-emerald-500">Giá từ 8.0 tr/th</span>
                      </div>
                    </div>
                  );
                })
              ) : (
                <>
                  <div className="p-5 rounded-2xl border border-neutral-200 bg-white/80 shadow-xs">
                    <div className="font-bold text-sm text-neutral-900 mb-1">Khu A - Tòa Nhà Ruby</div>
                    <div className="text-xs text-neutral-500">12 tầng · 120 căn hộ · View công viên</div>
                  </div>
                  <div className="p-5 rounded-2xl border border-neutral-200 bg-white/80 shadow-xs">
                    <div className="font-bold text-sm text-neutral-900 mb-1">Khu B - Tháp Ruby</div>
                    <div className="text-xs text-neutral-500">10 tầng · 86 căn hộ · View hồ điều hòa</div>
                  </div>
                  <div className="p-5 rounded-2xl border border-neutral-200 bg-white/80 shadow-xs">
                    <div className="font-bold text-sm text-neutral-900 mb-1">Khu C - Tháp Sapphire</div>
                    <div className="text-xs text-neutral-500">8 tầng · 64 căn hộ · View trung tâm</div>
                  </div>
                </>
              )}
            </div>

            {/* Filter & Search Bar */}
            <div className="p-4 rounded-2xl bg-white/90 border border-neutral-200/90 shadow-xs space-y-3">
              <div className="flex flex-col sm:flex-row items-center justify-between gap-3">
                {/* Search */}
                <div className="relative w-full sm:w-72">
                  <Search className="w-4 h-4 text-neutral-400 absolute left-3 top-1/2 -translate-y-1/2" />
                  <input
                    type="text"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder="Tìm theo số căn, tòa nhà..."
                    className="w-full pl-9 pr-3 py-2 bg-neutral-50 border border-neutral-200 focus:border-neutral-950 rounded-xl text-xs text-neutral-900 outline-none transition-all"
                  />
                </div>

                {/* Filter Controls */}
                <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                  {/* Tòa nhà */}
                  <select
                    value={selectedBlockFilter}
                    onChange={(e) => setSelectedBlockFilter(e.target.value)}
                    className="px-3 py-2 bg-neutral-50 border border-neutral-200 rounded-xl text-xs text-neutral-900 outline-none cursor-pointer font-medium"
                  >
                    <option value="ALL">Tất cả tòa nhà</option>
                    <option value="BLOCK_A">Tòa A - Ruby</option>
                    <option value="BLOCK_B">Tòa B - Ruby</option>
                    <option value="BLOCK_C">Tòa C - Sapphire</option>
                  </select>

                  {/* Số phòng ngủ */}
                  <select
                    value={selectedBedroomFilter}
                    onChange={(e) => setSelectedBedroomFilter(e.target.value)}
                    className="px-3 py-2 bg-neutral-50 border border-neutral-200 rounded-xl text-xs text-neutral-900 outline-none cursor-pointer font-medium"
                  >
                    <option value="ALL">Tất cả phòng ngủ</option>
                    <option value="1">1 Phòng ngủ</option>
                    <option value="2">2 Phòng ngủ</option>
                    <option value="3">3 Phòng ngủ</option>
                  </select>
                </div>
              </div>
            </div>

            {/* Listings Grid */}
            <div className="space-y-4">
              <div className="flex items-center justify-between text-xs text-neutral-500">
                <span>
                  Tìm thấy <strong className="text-neutral-950 font-bold">{filteredListings.length}</strong> căn hộ của chủ sở hữu cho thuê
                </span>
                <span className="text-[11px] text-neutral-400">Cập nhật theo thời gian thực</span>
              </div>

              {isLoadingRentals ? (
                <div className="py-20 text-center text-xs text-neutral-400">
                  <div className="w-6 h-6 border-2 border-neutral-900 border-t-transparent rounded-full animate-spin mx-auto mb-2" />
                  <span>Đang tải danh sách căn hộ cho thuê...</span>
                </div>
              ) : filteredListings.length === 0 ? (
                <div className="py-16 text-center bg-white/60 border border-neutral-200/80 rounded-2xl p-6">
                  <Building2 className="w-10 h-10 text-neutral-300 mx-auto mb-2" />
                  <div className="text-sm font-bold text-neutral-900">Không tìm thấy căn hộ phù hợp</div>
                  <div className="text-xs text-neutral-500 mt-1">
                    Vui lòng thử thay đổi bộ lọc tòa nhà hoặc số phòng ngủ.
                  </div>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                  {filteredListings.map((item) => (
                    <div
                      key={item.id}
                      className="bg-white/95 rounded-2xl border border-neutral-200/90 shadow-xs hover:shadow-xl hover:border-neutral-400 transition-all flex flex-col justify-between overflow-hidden group"
                    >
                      {/* Card Top Banner / Visual */}
                      <div className="h-32 bg-gradient-to-tr from-slate-100 via-sky-50 to-indigo-50 p-4 flex flex-col justify-between relative border-b border-neutral-100">
                        <div className="flex items-center justify-between">
                          <span className="text-xs font-extrabold px-2.5 py-1 rounded-lg bg-white/95 text-neutral-950 shadow-2xs border border-neutral-200/60 font-mono">
                            Căn {item.apartment_number}
                          </span>
                          <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-500 text-white shadow-2xs">
                            Chính chủ cho thuê
                          </span>
                        </div>
                        <div className="flex items-center gap-1.5 text-xs text-neutral-600 font-medium">
                          <MapPin className="w-3.5 h-3.5 text-neutral-500" />
                          <span className="truncate">{item.block_name}</span>
                        </div>
                      </div>

                      {/* Card Body */}
                      <div className="p-4 sm:p-5 space-y-3.5 flex-1 flex flex-col justify-between">
                        <div>
                          {/* Price */}
                          <div className="flex items-baseline justify-between mb-2">
                            <span className="text-lg font-black text-neutral-950 font-mono tracking-tight text-emerald-600">
                              {item.monthly_rent_formatted}
                            </span>
                            <span className="text-[11px] text-neutral-500">Giá thuê chính chủ</span>
                          </div>

                          {/* Key Specs */}
                          <div className="grid grid-cols-3 gap-1.5 py-2.5 px-3 rounded-xl bg-neutral-50 border border-neutral-100 text-center text-xs">
                            <div>
                              <div className="text-neutral-400 text-[10px]">Diện tích</div>
                              <div className="font-bold text-neutral-900">{item.area_sqm} m²</div>
                            </div>
                            <div>
                              <div className="text-neutral-400 text-[10px]">Phòng ngủ</div>
                              <div className="font-bold text-neutral-900">{item.bedroom_count} PN</div>
                            </div>
                            <div>
                              <div className="text-neutral-400 text-[10px]">Vệ sinh</div>
                              <div className="font-bold text-neutral-900">{item.bathroom_count} WC</div>
                            </div>
                          </div>

                          {/* Extra info */}
                          <div className="mt-3 space-y-1.5 text-xs text-neutral-600">
                            <div className="flex items-center gap-1.5">
                              <Sparkles className="w-3.5 h-3.5 text-amber-500" />
                              <span>{item.furnished_text}</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                              <Compass className="w-3.5 h-3.5 text-sky-500" />
                              <span className="truncate">{item.view_direction}</span>
                            </div>
                          </div>
                        </div>

                        {/* Actions */}
                        <div className="pt-3 border-t border-neutral-100 flex items-center gap-2">
                          <button
                            type="button"
                            onClick={() => handleOpenScheduleModal(item)}
                            className="flex-1 py-2.5 px-3 rounded-xl bg-neutral-950 hover:bg-neutral-800 text-white text-xs font-semibold transition-all flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer"
                          >
                            <Calendar className="w-3.5 h-3.5 text-emerald-400" />
                            <span>Đặt lịch xem nhà</span>
                          </button>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        ) : activeMenuId === 'amenities' ? (
          <ResidentAmenityBookingPanel />
        ) : activeMenuId === 'overview' ? (
          /* ================= VIEW 2: TỔNG QUAN CƯ DÂN ================= */
          <div className="max-w-6xl mx-auto space-y-8 animate-in fade-in duration-300">
            {/* Page Title & Breadcrumb */}
            <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-1.5 text-xs text-neutral-400 uppercase tracking-wider font-medium">
                  <Home className="w-3.5 h-3.5 text-neutral-400" />
                  <span>CỔNG CƯ DÂN</span>
                </div>
                <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight flex items-center gap-2.5">
                  Tổng quan Cư dân
                  <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 shadow-2xs">
                    {residentType === 'TENANT' ? 'Khách thuê' : 'Chủ sở hữu'}
                  </span>
                </h1>
                <p className="text-xs sm:text-sm text-neutral-500">
                  Theo dõi hóa đơn, yêu cầu hỗ trợ và tiện ích của căn hộ.
                </p>
              </div>

              {/* Action Button: Nhảy sang xem căn hộ cho thuê */}
              <button
                type="button"
                onClick={() => setActiveMenuId('rentals')}
                className="inline-flex items-center gap-2 px-4 py-2.5 bg-neutral-950 hover:bg-neutral-800 text-white rounded-xl text-xs font-semibold shadow-sm hover:shadow-md transition-all self-start cursor-pointer"
              >
                <Building2 className="w-3.5 h-3.5 text-emerald-400" />
                <span>Xem căn hộ cho thuê</span>
                <ArrowRight className="w-3.5 h-3.5" />
              </button>
            </div>

            {/* 4 Stat Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div className="p-5 rounded-2xl border border-neutral-200/80 bg-white/80 backdrop-blur-md shadow-xs hover:shadow-lg transition-all flex flex-col justify-between">
                <div className="flex items-center justify-between text-xs text-neutral-500 font-medium">
                  <span>Số dư cần thanh toán</span>
                  <div className="w-7 h-7 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center shrink-0 border border-sky-100">
                    <Receipt className="w-3.5 h-3.5" />
                  </div>
                </div>
                <div className="my-3">
                  <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight">
                    2.450.000đ
                  </span>
                </div>
                <div className="text-xs text-neutral-400 flex items-center gap-1.5">
                  <Clock className="w-3 h-3 text-amber-500" />
                  <span>Hạn thanh toán 15/09/2026</span>
                </div>
              </div>

              <div className="p-5 rounded-2xl border border-neutral-200/80 bg-white/80 backdrop-blur-md shadow-xs hover:shadow-lg transition-all flex flex-col justify-between">
                <div className="flex items-center justify-between text-xs text-neutral-500 font-medium">
                  <span>Yêu cầu đang xử lý</span>
                  <div className="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 border border-amber-100">
                    <AlertCircle className="w-3.5 h-3.5" />
                  </div>
                </div>
                <div className="my-3">
                  <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight">
                    02
                  </span>
                </div>
                <div className="text-xs text-neutral-400 flex items-center gap-1.5">
                  <span className="w-1.5 h-1.5 rounded-full bg-rose-500" />
                  <span>01 yêu cầu ưu tiên cao</span>
                </div>
              </div>

              <div className="p-5 rounded-2xl border border-neutral-200/80 bg-white/80 backdrop-blur-md shadow-xs hover:shadow-lg transition-all flex flex-col justify-between">
                <div className="flex items-center justify-between text-xs text-neutral-500 font-medium">
                  <span>Lịch tiện ích sắp tới</span>
                  <div className="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 border border-emerald-100">
                    <Sparkles className="w-3.5 h-3.5" />
                  </div>
                </div>
                <div className="my-3">
                  <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight">
                    03
                  </span>
                </div>
                <div className="text-xs text-neutral-400 flex items-center gap-1.5">
                  <CheckCircle2 className="w-3 h-3 text-emerald-500" />
                  <span>Trong 30 ngày tới</span>
                </div>
              </div>

              <div className="p-5 rounded-2xl border border-neutral-200/80 bg-white/80 backdrop-blur-md shadow-xs hover:shadow-lg transition-all flex flex-col justify-between">
                <div className="flex items-center justify-between text-xs text-neutral-500 font-medium">
                  <span>Khách đã khai báo</span>
                  <div className="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0 border border-indigo-100">
                    <Users className="w-3.5 h-3.5" />
                  </div>
                </div>
                <div className="my-3">
                  <span className="text-2xl font-extrabold text-neutral-900 font-mono tracking-tight">
                    04
                  </span>
                </div>
                <div className="text-xs text-neutral-400 flex items-center gap-1.5">
                  <span className="w-1.5 h-1.5 rounded-full bg-indigo-500" />
                  <span>Trong tháng này</span>
                </div>
              </div>
            </div>

            {/* Việc cần làm */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-sm font-bold text-neutral-900 flex items-center gap-2">
                    Việc cần làm
                    <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-600 border border-neutral-200/60">
                      3 mục cần chú ý
                    </span>
                  </h2>
                  <p className="text-xs text-neutral-400">Các hạng mục cần bạn theo dõi và thực hiện.</p>
                </div>
              </div>

              <div className="border border-neutral-200/80 bg-white/80 backdrop-blur-md rounded-2xl overflow-hidden shadow-xs">
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-xs border-collapse">
                    <thead>
                      <tr className="border-b border-neutral-200/70 bg-neutral-50/60 text-neutral-500 font-semibold uppercase text-[11px] tracking-wider">
                        <th className="py-3 px-4">HẠNG MỤC</th>
                        <th className="py-3 px-4">GIÁ TRỊ</th>
                        <th className="py-3 px-4">TRẠNG THÁI</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-neutral-200/60 text-neutral-800">
                      <tr className="hover:bg-neutral-50/80 transition-colors">
                        <td className="py-3.5 px-4 font-semibold text-neutral-900 flex items-center gap-2">
                          <span className="w-1.5 h-1.5 rounded-full bg-rose-500" />
                          Hóa đơn tháng 09/2026
                        </td>
                        <td className="py-3.5 px-4 text-neutral-600 font-mono font-medium">2.450.000đ</td>
                        <td className="py-3.5 px-4">
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-rose-50 text-rose-700 border border-rose-200/60">
                            Chưa thanh toán
                          </span>
                        </td>
                      </tr>
                      <tr className="hover:bg-neutral-50/80 transition-colors">
                        <td className="py-3.5 px-4 font-semibold text-neutral-900 flex items-center gap-2">
                          <span className="w-1.5 h-1.5 rounded-full bg-amber-500" />
                          Sửa vòi nước phòng tắm
                        </td>
                        <td className="py-3.5 px-4 text-neutral-600 font-mono font-medium">TICKET-1024</td>
                        <td className="py-3.5 px-4">
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-700 border border-amber-200/60">
                            Đang xử lý
                          </span>
                        </td>
                      </tr>
                      <tr className="hover:bg-neutral-50/80 transition-colors">
                        <td className="py-3.5 px-4 font-semibold text-neutral-900 flex items-center gap-2">
                          <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
                          Đặt sân cầu lông
                        </td>
                        <td className="py-3.5 px-4 text-neutral-600 font-mono font-medium">12/09 · 18:00</td>
                        <td className="py-3.5 px-4">
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                            Đã xác nhận
                          </span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        ) : (
          /* Other modules placeholder */
          <div className="max-w-4xl mx-auto py-16 text-center space-y-5 animate-in fade-in duration-300">
            <div className="w-14 h-14 rounded-2xl bg-white/80 backdrop-blur-md border border-neutral-200/80 text-neutral-400 mx-auto flex items-center justify-center shadow-xs">
              <FileText className="w-7 h-7 text-neutral-500" />
            </div>
            <div className="space-y-1">
              <h2 className="text-xl font-bold text-neutral-900">
                {menuItems.find((m) => m.id === activeMenuId)?.label || 'Phân hệ chức năng'}
              </h2>
              <p className="text-xs text-neutral-500 max-w-md mx-auto">
                Khung xương giao diện sẵn sàng. Chức năng chi tiết sẽ do nhân sự được phân công nhiệm vụ triển khai tiếp theo.
              </p>
            </div>
            <div>
              <button
                type="button"
                onClick={() => setActiveMenuId('overview')}
                className="px-4 py-2.5 rounded-xl bg-neutral-950 text-white text-xs font-semibold hover:bg-neutral-800 shadow-sm transition-all"
              >
                Quay lại Tổng quan Cư dân
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ================= MODAL: ĐẶT LỊCH XEM NHÀ ================= */}
      {selectedListingForSchedule && (
        <div className="fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150">
          <div className="bg-white rounded-2xl border border-neutral-200 shadow-2xl max-w-md w-full p-6 space-y-4">
            <div className="flex items-start justify-between">
              <div>
                <h3 className="font-bold text-base text-neutral-950">
                  Đặt lịch hẹn xem căn hộ
                </h3>
                <p className="text-xs text-neutral-500">
                  Căn {selectedListingForSchedule.apartment_number} • {selectedListingForSchedule.block_name}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setSelectedListingForSchedule(null)}
                className="text-neutral-400 hover:text-neutral-700 p-1 rounded-lg"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-3 bg-neutral-50 rounded-xl border border-neutral-100 text-xs space-y-1">
              <div className="flex justify-between">
                <span className="text-neutral-500">Giá thuê:</span>
                <span className="font-bold text-emerald-600 font-mono">
                  {selectedListingForSchedule.monthly_rent_formatted}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-neutral-500">Quy mô:</span>
                <span className="font-semibold text-neutral-800">
                  {selectedListingForSchedule.area_sqm} m² · {selectedListingForSchedule.bedroom_count} Phòng ngủ
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-neutral-500">Chủ hộ:</span>
                <span className="font-semibold text-neutral-800">
                  {selectedListingForSchedule.owner_name}
                </span>
              </div>
            </div>

            <form onSubmit={handleConfirmSchedule} className="space-y-3.5">
              <div>
                <label className="block text-xs font-semibold text-neutral-800 mb-1">
                  Chọn ngày xem nhà <span className="text-rose-500">*</span>
                </label>
                <input
                  type="date"
                  required
                  value={scheduleDate}
                  onChange={(e) => setScheduleDate(e.target.value)}
                  className="w-full px-3 py-2 bg-white border border-neutral-200 rounded-xl text-xs text-neutral-900 outline-none focus:border-neutral-950"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-800 mb-1">
                  Khung giờ mong muốn <span className="text-rose-500">*</span>
                </label>
                <select
                  value={scheduleTime}
                  onChange={(e) => setScheduleTime(e.target.value)}
                  className="w-full px-3 py-2 bg-white border border-neutral-200 rounded-xl text-xs text-neutral-900 outline-none focus:border-neutral-950 cursor-pointer"
                >
                  <option value="08:30">08:30 - Sáng</option>
                  <option value="09:30">09:30 - Sáng</option>
                  <option value="11:00">11:00 - Trưa</option>
                  <option value="14:30">14:30 - Chiều</option>
                  <option value="17:00">17:00 - Chiều</option>
                  <option value="18:30">18:30 - Tối</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-neutral-800 mb-1">
                  Ghi chú cho Chủ hộ & Ban Quản Lý
                </label>
                <textarea
                  rows={2}
                  value={scheduleNote}
                  onChange={(e) => setScheduleNote(e.target.value)}
                  placeholder="Ví dụ: Tôi muốn dọn vào ở từ đầu tháng tới, cần xem nội thất..."
                  className="w-full px-3 py-2 bg-white border border-neutral-200 rounded-xl text-xs text-neutral-900 outline-none focus:border-neutral-950 resize-none"
                />
              </div>

              <div className="pt-2 flex items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setSelectedListingForSchedule(null)}
                  className="px-4 py-2 border border-neutral-200 rounded-xl text-xs font-semibold text-neutral-700 hover:bg-neutral-50 transition-all cursor-pointer"
                >
                  Hủy
                </button>
                <button
                  type="submit"
                  disabled={isSubmittingSchedule}
                  className="px-5 py-2 bg-neutral-950 hover:bg-neutral-800 text-white rounded-xl text-xs font-semibold transition-all shadow-xs flex items-center gap-1.5 cursor-pointer disabled:opacity-60"
                >
                  {isSubmittingSchedule ? (
                    <div className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                  ) : (
                    <>
                      <span>Gửi yêu cầu hẹn</span>
                      <ArrowRight className="w-3.5 h-3.5" />
                    </>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed bottom-6 right-6 z-50 flex items-center gap-2.5 px-4 py-3 rounded-2xl bg-neutral-900/90 text-white text-xs font-medium shadow-2xl backdrop-blur-md border border-white/10 animate-in fade-in slide-in-from-bottom-3 duration-200">
          <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />
          <span>{toastMessage}</span>
        </div>
      )}
    </AppLayout>
  );
};

export default ResidentHome;
