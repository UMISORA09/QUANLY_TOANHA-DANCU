import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  Search,
  X,
  Users,
  FileText,
  Car,
  CreditCard,
  UserCheck,
  Package,
  Zap,
  Database,
  ArrowRight,
  Clock,
  Building,
  AlertCircle,
  Loader2,
  CheckCircle2,
} from 'lucide-react';
import {
  globalSearchApi,
  GlobalSearchResultItem,
  GlobalSearchMeta,
} from '../../Services/globalSearchApi';

export interface GlobalSearchModalProps {
  isOpen: boolean;
  onClose: () => void;
  initialType?: string;
}

type TabType = 'all' | 'resident' | 'temporary_registration' | 'vehicle' | 'access_card' | 'visitor' | 'parcel';

interface TabItem {
  id: TabType;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
}

const TABS: TabItem[] = [
  { id: 'all', label: 'Tất cả', icon: Search },
  { id: 'resident', label: 'Cư dân', icon: Users },
  { id: 'temporary_registration', label: 'Tạm trú/vắng', icon: FileText },
  { id: 'vehicle', label: 'Phương tiện', icon: Car },
  { id: 'access_card', label: 'Thẻ RFID', icon: CreditCard },
  { id: 'visitor', label: 'Khách', icon: UserCheck },
  { id: 'parcel', label: 'Bưu phẩm', icon: Package },
];

export const GlobalSearchModal: React.FC<GlobalSearchModalProps> = ({
  isOpen,
  onClose,
  initialType = 'all',
}) => {
  const [query, setQuery] = useState<string>('');
  const [activeTab, setActiveTab] = useState<TabType>((initialType as TabType) || 'all');
  const [results, setResults] = useState<GlobalSearchResultItem[]>([]);
  const [meta, setMeta] = useState<GlobalSearchMeta | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedIndex, setSelectedIndex] = useState<number>(-1);

  const inputRef = useRef<HTMLInputElement>(null);
  const abortControllerRef = useRef<AbortController | null>(null);
  const resultsContainerRef = useRef<HTMLDivElement>(null);

  // Focus ô tìm kiếm khi mở Modal
  useEffect(() => {
    if (isOpen) {
      setTimeout(() => {
        inputRef.current?.focus();
        inputRef.current?.select();
      }, 50);
    } else {
      setQuery('');
      setResults([]);
      setMeta(null);
      setError(null);
      setSelectedIndex(-1);
    }
  }, [isOpen]);

  // Thực hiện tìm kiếm với debounce 250ms & AbortController
  const performSearch = useCallback(
    async (searchTerm: string, type: TabType) => {
      const trimmed = searchTerm.trim();
      if (!trimmed) {
        setResults([]);
        setMeta(null);
        setIsLoading(false);
        setError(null);
        return;
      }

      // Hủy bỏ request trước đó nếu người dùng đang gõ tiếp
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      abortControllerRef.current = new AbortController();

      setIsLoading(true);
      setError(null);

      try {
        const response = await globalSearchApi.search(
          {
            q: trimmed,
            type: type === 'all' ? undefined : type,
            limit: 25,
          },
          abortControllerRef.current.signal
        );

        if (response.success) {
          setResults(response.data || []);
          setMeta(response.meta || null);
          setSelectedIndex(-1);
        }
      } catch (err: any) {
        if (err.name === 'AbortError') {
          return;
        }
        setError(err.message || 'Không thể thực hiện tìm kiếm. Vui lòng thử lại.');
      } finally {
        setIsLoading(false);
      }
    },
    []
  );

  // Debounce hiệu ứng gõ phím
  useEffect(() => {
    const timer = setTimeout(() => {
      performSearch(query, activeTab);
    }, 250);

    return () => clearTimeout(timer);
  }, [query, activeTab, performSearch]);

  // Điều khiển bàn phím (ArrowUp, ArrowDown, Enter, Esc)
  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      onClose();
      return;
    }

    if (results.length === 0) {
      return;
    }

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelectedIndex((prev) => {
        const next = prev < results.length - 1 ? prev + 1 : 0;
        scrollIntoView(next);
        return next;
      });
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelectedIndex((prev) => {
        const next = prev > 0 ? prev - 1 : results.length - 1;
        scrollIntoView(next);
        return next;
      });
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (selectedIndex >= 0 && selectedIndex < results.length) {
        handleSelectHit(results[selectedIndex]);
      }
    }
  };

  const scrollIntoView = (index: number) => {
    if (!resultsContainerRef.current) {
      return;
    }
    const element = resultsContainerRef.current.children[index] as HTMLElement;
    if (element) {
      element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  };

  const handleSelectHit = (hit: GlobalSearchResultItem) => {
    onClose();
    if (hit.url) {
      window.location.href = hit.url;
    }
  };

  if (!isOpen) {
    return null;
  }

  // Icon đại diện cho từng phân hệ
  const getItemIcon = (type: string) => {
    switch (type) {
      case 'resident':
        return <Users className="w-4 h-4 text-sky-600" />;
      case 'temporary_registration':
        return <FileText className="w-4 h-4 text-indigo-600" />;
      case 'vehicle':
        return <Car className="w-4 h-4 text-emerald-600" />;
      case 'access_card':
        return <CreditCard className="w-4 h-4 text-amber-600" />;
      case 'visitor':
        return <UserCheck className="w-4 h-4 text-purple-600" />;
      case 'parcel':
        return <Package className="w-4 h-4 text-rose-600" />;
      default:
        return <Building className="w-4 h-4 text-slate-600" />;
    }
  };

  // Badge màu sắc cho trạng thái
  const getBadgeClass = (variant: string) => {
    switch (variant) {
      case 'success':
        return 'bg-emerald-50 text-emerald-700 border-emerald-200';
      case 'warning':
        return 'bg-amber-50 text-amber-700 border-amber-200';
      case 'danger':
        return 'bg-rose-50 text-rose-700 border-rose-200';
      case 'info':
        return 'bg-sky-50 text-sky-700 border-sky-200';
      default:
        return 'bg-slate-100 text-slate-700 border-slate-200';
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center pt-12 sm:pt-20 px-3 sm:px-4 bg-slate-950/50 backdrop-blur-md transition-all duration-300 animate-in fade-in"
      onClick={onClose}
    >
      <div
        className="w-full max-w-3xl bg-white/95 backdrop-blur-2xl rounded-2xl shadow-2xl border border-slate-200/80 overflow-hidden flex flex-col max-h-[85vh] transition-all transform animate-in zoom-in-95 duration-200"
        onClick={(e) => e.stopPropagation()}
        onKeyDown={handleKeyDown}
      >
        {/* ================= HEADER & SEARCH INPUT ================= */}
        <div className="p-3 sm:p-4 border-b border-slate-100 bg-gradient-to-b from-slate-50/50 to-white">
          <div className="relative flex items-center">
            <Search className="absolute left-3.5 w-5 h-5 text-slate-400 pointer-events-none" />
            <input
              ref={inputRef}
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Tìm kiếm cư dân, CCCD, biển số xe, thẻ RFID, bưu phẩm, khách..."
              className="w-full pl-11 pr-20 py-3 text-sm sm:text-base font-medium text-slate-900 bg-slate-100/70 hover:bg-slate-100 focus:bg-white rounded-xl border border-slate-200/80 focus:border-sky-500 focus:ring-4 focus:ring-sky-500/10 outline-none transition-all placeholder:text-slate-400"
              maxLength={100}
            />
            <div className="absolute right-3 flex items-center gap-1.5">
              {isLoading ? (
                <Loader2 className="w-4 h-4 text-sky-600 animate-spin" />
              ) : query ? (
                <button
                  type="button"
                  onClick={() => setQuery('')}
                  className="p-1 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-200/60 transition-colors"
                  title="Xóa truy vấn"
                >
                  <X className="w-4 h-4" />
                </button>
              ) : null}
              <kbd className="hidden sm:inline-flex items-center px-1.5 py-0.5 text-[10px] font-mono font-semibold text-slate-400 bg-white border border-slate-200 rounded shadow-2xs">
                ESC
              </kbd>
            </div>
          </div>

          {/* ================= TABS / PHÂN HỆ FILTER ================= */}
          <div className="flex items-center gap-1 mt-3 overflow-x-auto no-scrollbar py-0.5">
            {TABS.map((tab) => {
              const TabIcon = tab.icon;
              const isActive = activeTab === tab.id;
              return (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setActiveTab(tab.id)}
                  className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition-all cursor-pointer ${
                    isActive
                      ? 'bg-sky-600 text-white shadow-xs'
                      : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                  }`}
                >
                  <TabIcon className="w-3.5 h-3.5" />
                  <span>{tab.label}</span>
                </button>
              );
            })}
          </div>
        </div>

        {/* ================= ENGINE STATUS / PERFORMANCE BAR ================= */}
        {meta && (
          <div className="px-4 py-2 bg-slate-50/80 border-b border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <div className="flex items-center gap-2">
              {meta.engine === 'elasticsearch' || meta.engine === 'meilisearch' ? (
                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-semibold border border-emerald-200/80">
                  <Zap className="w-3 h-3 text-emerald-600 fill-emerald-600" />
                  <span>Elasticsearch Dedicated Engine</span>
                </span>
              ) : (
                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 text-[11px] font-semibold border border-amber-200/80">
                  <Database className="w-3 h-3 text-amber-600" />
                  <span>MySQL Database Fallback</span>
                </span>
              )}
              <span className="hidden sm:inline text-slate-400">•</span>
              <span className="hidden sm:inline">
                Tìm thấy <strong>{meta.total}</strong> kết quả ({meta.search_time_ms}ms)
              </span>
            </div>
            <div className="text-[11px] text-slate-400 hidden sm:block">
              Sử dụng <kbd className="font-mono">↑</kbd> <kbd className="font-mono">↓</kbd> để di chuyển, <kbd className="font-mono">Enter</kbd> để mở
            </div>
          </div>
        )}

        {/* ================= RESULTS BODY ================= */}
        <div
          ref={resultsContainerRef}
          className="flex-1 overflow-y-auto p-2 sm:p-3 divide-y divide-slate-100/80"
        >
          {/* Loading Skeleton */}
          {isLoading && results.length === 0 && (
            <div className="space-y-3 p-4">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="flex items-center gap-3 p-3 rounded-xl bg-slate-50 animate-pulse">
                  <div className="w-10 h-10 rounded-lg bg-slate-200 shrink-0" />
                  <div className="flex-1 space-y-2">
                    <div className="w-1/3 h-4 bg-slate-200 rounded" />
                    <div className="w-2/3 h-3 bg-slate-200 rounded" />
                  </div>
                  <div className="w-16 h-5 bg-slate-200 rounded shrink-0" />
                </div>
              ))}
            </div>
          )}

          {/* Error State */}
          {error && !isLoading && (
            <div className="p-8 text-center">
              <AlertCircle className="w-10 h-10 text-rose-500 mx-auto mb-2" />
              <div className="text-sm font-semibold text-slate-800">{error}</div>
              <button
                type="button"
                onClick={() => performSearch(query, activeTab)}
                className="mt-3 px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-600 text-white hover:bg-sky-700 transition-colors"
              >
                Thử lại
              </button>
            </div>
          )}

          {/* Empty Query / Initial State */}
          {!query && (
            <div className="p-8 text-center">
              <div className="w-12 h-12 rounded-2xl bg-sky-50 text-sky-600 flex items-center justify-center mx-auto mb-3 shadow-inner">
                <Search className="w-6 h-6" />
              </div>
              <h3 className="text-sm font-bold text-slate-800 mb-1">Tra cứu nhanh toàn bộ tòa nhà & cư dân</h3>
              <p className="text-xs text-slate-500 max-w-md mx-auto mb-4">
                Nhập tên cư dân, số CCCD, biển số xe, mã thẻ RFID, mã vận đơn bưu phẩm hoặc mã khách ghé thăm để tìm kiếm ngay lập tức.
              </p>
              <div className="flex flex-wrap justify-center gap-2 max-w-lg mx-auto">
                <button
                  type="button"
                  onClick={() => setQuery('Air Blade')}
                  className="px-2.5 py-1 text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-md transition-colors"
                >
                  Xe Air Blade
                </button>
                <button
                  type="button"
                  onClick={() => setQuery('Minh Anh')}
                  className="px-2.5 py-1 text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-md transition-colors"
                >
                  Cư dân Minh Anh
                </button>
                <button
                  type="button"
                  onClick={() => setQuery('SPX')}
                  className="px-2.5 py-1 text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-md transition-colors"
                >
                  Bưu phẩm Shopee
                </button>
                <button
                  type="button"
                  onClick={() => setQuery('CARD')}
                  className="px-2.5 py-1 text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-md transition-colors"
                >
                  Thẻ thang máy
                </button>
              </div>
            </div>
          )}

          {/* No Results State */}
          {query && !isLoading && results.length === 0 && !error && (
            <div className="p-8 text-center">
              <div className="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
                <Search className="w-6 h-6" />
              </div>
              <div className="text-sm font-bold text-slate-700 mb-1">
                Không tìm thấy kết quả nào phù hợp với &quot;{query}&quot;
              </div>
              <p className="text-xs text-slate-400 max-w-md mx-auto">
                Hãy kiểm tra lại chính tả hoặc thử chuyển sang tab &quot;Tất cả&quot; để tìm kiếm trên mọi phân hệ.
              </p>
            </div>
          )}

          {/* Results List */}
          {results.map((hit, idx) => {
            const isSelected = idx === selectedIndex;
            return (
              <div
                key={`${hit.type}-${hit.id}`}
                onClick={() => handleSelectHit(hit)}
                onMouseEnter={() => setSelectedIndex(idx)}
                className={`p-3 rounded-xl flex items-start gap-3 transition-all cursor-pointer group ${
                  isSelected
                    ? 'bg-sky-50/90 border border-sky-200/80 shadow-xs'
                    : 'hover:bg-slate-50 border border-transparent'
                }`}
              >
                {/* Category Icon */}
                <div
                  className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 border transition-all ${
                    isSelected ? 'bg-white border-sky-300 shadow-xs' : 'bg-slate-100/80 border-slate-200/60'
                  }`}
                >
                  {getItemIcon(hit.type)}
                </div>

                {/* Content Info */}
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-0.5">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono">
                      {hit.type_label}
                    </span>
                    {hit.identifier && (
                      <span className="px-1.5 py-0.2 rounded bg-slate-100 text-slate-700 text-[10px] font-mono font-semibold border border-slate-200">
                        {hit.identifier}
                      </span>
                    )}
                  </div>

                  <h4 className="text-sm font-bold text-slate-900 group-hover:text-sky-600 transition-colors truncate">
                    {hit.title}
                  </h4>

                  <div className="text-xs text-slate-600 truncate mt-0.5">{hit.subtitle}</div>

                  {hit.detail && (
                    <div className="text-[11px] text-slate-400 truncate mt-0.5">{hit.detail}</div>
                  )}
                </div>

                {/* Right Status Badge & Navigate Action */}
                <div className="flex flex-col items-end gap-1.5 shrink-0">
                  {hit.badge && (
                    <span
                      className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border ${getBadgeClass(
                        hit.badge_variant
                      )}`}
                    >
                      {hit.badge}
                    </span>
                  )}
                  <span
                    className={`text-slate-400 group-hover:text-sky-600 transition-all ${
                      isSelected ? 'translate-x-0.5 opacity-100' : 'opacity-0 sm:opacity-60'
                    }`}
                  >
                    <ArrowRight className="w-4 h-4" />
                  </span>
                </div>
              </div>
            );
          })}
        </div>

        {/* ================= FOOTER ================= */}
        <div className="px-4 py-2.5 bg-slate-50 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400">
          <div className="flex items-center gap-3">
            <span>
              Phím tắt: <kbd className="font-mono bg-white px-1 py-0.5 border rounded">Ctrl</kbd> + <kbd className="font-mono bg-white px-1 py-0.5 border rounded">K</kbd>
            </span>
            <span className="hidden sm:inline">•</span>
            <span className="hidden sm:inline">Multi-tenant RBAC Server-Side Isolation</span>
          </div>
          <div className="flex items-center gap-1 font-medium text-slate-500">
            <span>Smart Cassavas Building OS</span>
          </div>
        </div>
      </div>
    </div>
  );
};
