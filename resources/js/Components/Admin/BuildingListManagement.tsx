import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import {
  Building2,
  Building,
  Home,
  CheckCircle2,
  AlertTriangle,
  Wrench,
  Search,
  Filter,
  ShieldCheck,
  Zap,
  Droplet,
  Layers,
  ArrowUpRight,
  Users,
  ChevronRight,
  ChevronDown,
  Eye,
  SlidersHorizontal,
  Plus,
  Sparkles,
  Trash2,
  Edit,
  RefreshCw,
  X,
  Check,
  ChevronLeft,
  KeyRound,
  FileSpreadsheet,
  LayoutGrid,
  List,
} from 'lucide-react';
import buildingStructureApi, {
  BlockItem,
  FloorItem,
  ApartmentItem,
  BatchGeneratePayload,
} from '../../Services/buildingStructureApi';
import { buildingSyncManager, BuildingSyncEvent } from '../../Services/buildingSync';

interface StatusOption {
  value: 'OCCUPIED' | 'RENTED' | 'VACANT' | 'MAINTENANCE';
  label: string;
  subLabel: string;
  dotColor: string;
  badgeBg: string;
  hoverBg: string;
  textColor: string;
  borderColor: string;
  icon: React.ComponentType<{ className?: string }>;
}

const STATUS_CONFIGS: Record<'OCCUPIED' | 'RENTED' | 'VACANT' | 'MAINTENANCE', StatusOption> = {
  OCCUPIED: {
    value: 'OCCUPIED',
    label: 'Đã bán / Cư trú',
    subLabel: 'Cư dân sinh sống',
    dotColor: 'bg-emerald-500',
    badgeBg: 'bg-emerald-50/90',
    hoverBg: 'hover:bg-emerald-100/80',
    textColor: 'text-emerald-800',
    borderColor: 'border-emerald-200/90',
    icon: CheckCircle2,
  },
  RENTED: {
    value: 'RENTED',
    label: 'Đang thuê',
    subLabel: 'Hợp đồng thuê hiệu lực',
    dotColor: 'bg-sky-500',
    badgeBg: 'bg-sky-50/90',
    hoverBg: 'hover:bg-sky-100/80',
    textColor: 'text-sky-800',
    borderColor: 'border-sky-200/90',
    icon: KeyRound,
  },
  VACANT: {
    value: 'VACANT',
    label: 'Trống',
    subLabel: 'Sẵn sàng bàn giao / bán',
    dotColor: 'bg-amber-500',
    badgeBg: 'bg-amber-50/90',
    hoverBg: 'hover:bg-amber-100/80',
    textColor: 'text-amber-800',
    borderColor: 'border-amber-200/90',
    icon: Home,
  },
  MAINTENANCE: {
    value: 'MAINTENANCE',
    label: 'Đang bảo trì',
    subLabel: 'Cấp phép thi công sửa chữa',
    dotColor: 'bg-purple-500',
    badgeBg: 'bg-purple-50/90',
    hoverBg: 'hover:bg-purple-100/80',
    textColor: 'text-purple-800',
    borderColor: 'border-purple-200/90',
    icon: Wrench,
  },
};

export const normalizeStatusKey = (s: string | undefined): 'OCCUPIED' | 'RENTED' | 'VACANT' | 'MAINTENANCE' => {
  const upper = (s || '').toUpperCase();
  if (upper === 'RENTED' || upper === 'DANG_THUE') return 'RENTED';
  if (upper === 'VACANT' || upper === 'TRONG') return 'VACANT';
  if (upper === 'MAINTENANCE' || upper === 'REPAIRING' || upper === 'RESERVED') return 'MAINTENANCE';
  return 'OCCUPIED';
};

interface ApartmentStatusDropdownProps {
  currentStatus: 'OCCUPIED' | 'RENTED' | 'VACANT' | 'MAINTENANCE' | string;
  onChange: (newStatus: 'OCCUPIED' | 'RENTED' | 'VACANT' | 'MAINTENANCE') => void;
  disabled?: boolean;
  className?: string;
  dropUp?: boolean;
}

export const ApartmentStatusDropdown: React.FC<ApartmentStatusDropdownProps> = ({
  currentStatus,
  onChange,
  disabled = false,
  className = '',
  dropUp = false,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  const normalizedStatus = useMemo<'OCCUPIED' | 'RENTED' | 'VACANT' | 'MAINTENANCE'>(() => {
    return normalizeStatusKey(currentStatus);
  }, [currentStatus]);

  const currentCfg = STATUS_CONFIGS[normalizedStatus];

  useEffect(() => {
    if (!isOpen) return;
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  return (
    <div ref={dropdownRef} className={`relative inline-block text-left ${className}`}>
      <button
        type="button"
        disabled={disabled}
        onClick={(e) => {
          e.stopPropagation();
          setIsOpen((prev) => !prev);
        }}
        className={`inline-flex items-center justify-between gap-1.5 px-2.5 py-1 rounded-xl border text-xs font-bold transition-all duration-150 cursor-pointer select-none shadow-2xs hover:shadow-xs focus:outline-none focus:ring-2 focus:ring-sky-500/20 active:scale-98 ${currentCfg.badgeBg} ${currentCfg.hoverBg} ${currentCfg.textColor} ${currentCfg.borderColor}`}
        title="Nhấn để đổi trạng thái căn hộ nhanh"
      >
        <span className="flex items-center gap-1.5 truncate">
          <span className={`w-1.5 h-1.5 rounded-full ${currentCfg.dotColor} shrink-0 animate-pulse`} />
          <span className="truncate">{currentCfg.label}</span>
        </span>
        <ChevronDown
          className={`w-3.5 h-3.5 opacity-60 transition-transform duration-200 shrink-0 ${
            isOpen ? 'rotate-180' : ''
          }`}
        />
      </button>

      {isOpen && (
        <div
          className={`absolute left-0 w-48 sm:w-52 bg-white/95 backdrop-blur-md rounded-2xl border border-slate-200/90 shadow-xl shadow-slate-900/10 p-1.5 z-50 animate-in fade-in zoom-in-95 duration-100 ${
            dropUp ? 'bottom-full mb-1.5 origin-bottom-left' : 'top-full mt-1.5 origin-top-left'
          }`}
          onClick={(e) => e.stopPropagation()}
        >
          <div className="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 mb-1">
            <span>Trạng thái căn hộ</span>
          </div>
          <div className="space-y-0.5">
            {(Object.values(STATUS_CONFIGS) as StatusOption[]).map((cfg) => {
              const isSelected = cfg.value === normalizedStatus;
              const ItemIcon = cfg.icon;
              return (
                <button
                  key={cfg.value}
                  type="button"
                  onClick={() => {
                    setIsOpen(false);
                    if (!isSelected) onChange(cfg.value);
                  }}
                  className={`w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-xl text-left text-xs transition-all cursor-pointer ${
                    isSelected
                      ? `${cfg.badgeBg} ${cfg.textColor} font-bold shadow-2xs border ${cfg.borderColor}`
                      : 'text-slate-700 hover:bg-slate-50 font-medium border border-transparent'
                  }`}
                >
                  <div className="flex items-center gap-2 truncate">
                    <div
                      className={`w-6 h-6 rounded-lg flex items-center justify-center shrink-0 ${
                        isSelected ? 'bg-white shadow-2xs' : 'bg-slate-100'
                      }`}
                    >
                      <ItemIcon className={`w-3.5 h-3.5 ${isSelected ? cfg.textColor : 'text-slate-500'}`} />
                    </div>
                    <div className="truncate">
                      <div className="leading-tight truncate font-semibold">{cfg.label}</div>
                      <div className="text-[10px] text-slate-400 leading-tight truncate">{cfg.subLabel}</div>
                    </div>
                  </div>
                  {isSelected && <Check className="w-3.5 h-3.5 shrink-0 opacity-80" />}
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
};

export interface BuildingListManagementProps {
  activeSection?: 'blocks' | 'apartments' | 'all';
  onNavigateSection?: (section: 'blocks' | 'apartments') => void;
}

// Bộ nhớ đệm toàn cục module & session storage duy trì dữ liệu qua các lần chuyển tab, F5 & unmount (Phản hồi 0ms tức thì)
const SESSION_CACHE_KEY = 'smart_building_bootstrap_cache_v2';

const loadPersistedCache = (): { blocks: BlockItem[]; apartments: ApartmentItem[]; floors: FloorItem[] } | null => {
  if (typeof window === 'undefined') return null;
  try {
    const raw = sessionStorage.getItem(SESSION_CACHE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && Array.isArray(parsed.blocks) && Array.isArray(parsed.apartments) && parsed.apartments.length > 0) {
        return parsed;
      }
    }
  } catch {
    // ignore
  }
  return null;
};

const savePersistedCache = (blocksData: BlockItem[], aptsData: ApartmentItem[], allFloors?: FloorItem[]) => {
  if (typeof window === 'undefined') return;
  try {
    sessionStorage.setItem(
      SESSION_CACHE_KEY,
      JSON.stringify({
        blocks: blocksData,
        apartments: aptsData,
        floors: allFloors || [],
        timestamp: Date.now(),
      })
    );
  } catch {
    // ignore
  }
};

const initialPersisted = loadPersistedCache();
let globalCachedBlocks: BlockItem[] = initialPersisted?.blocks || [];
let globalCachedApartments: ApartmentItem[] = initialPersisted?.apartments || [];
let globalCachedFloorsList: FloorItem[] = initialPersisted?.floors || [];

export const BuildingListManagement: React.FC<BuildingListManagementProps> = ({
  activeSection = 'all',
  onNavigateSection,
}) => {
  // Ref lưu toàn bộ danh sách tầng nhận được từ bootstrap
  const allFloorsCacheRef = useRef<FloorItem[]>(globalCachedFloorsList);

  // --- Active Section State (blocks: Chức năng #1 xuanhoa/1, apartments: Chức năng #2 xuanhoa/2) ---
  const [currentSection, setCurrentSection] = useState<'blocks' | 'apartments'>(() => {
    if (activeSection === 'apartments') return 'apartments';
    if (typeof window !== 'undefined') {
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('tab') === 'apartments') return 'apartments';
    }
    return 'blocks';
  });

  useEffect(() => {
    if (activeSection && activeSection !== 'all') {
      setCurrentSection(activeSection);
    }
  }, [activeSection]);

  const handleSectionChange = (section: 'blocks' | 'apartments') => {
    setCurrentSection(section);
    onNavigateSection?.(section);
  };

  // --- Data State (Khởi tạo từ bộ nhớ đệm module / session storage nếu đã có dữ liệu - 0ms ngay khi mở trang) ---
  const [blocks, setBlocks] = useState<BlockItem[]>(() => globalCachedBlocks);
  const [floors, setFloors] = useState<FloorItem[]>([]);
  const [allApartments, setAllApartments] = useState<ApartmentItem[]>(() => globalCachedApartments);
  const [isLoading, setIsLoading] = useState<boolean>(() => globalCachedBlocks.length === 0 || globalCachedApartments.length === 0);
  const [isActionLoading, setIsActionLoading] = useState<boolean>(false);
  const [feedbackMessage, setFeedbackMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // --- View Mode State (Table vs Grid Cards) ---
  const [viewMode, setViewMode] = useState<'table' | 'grid'>('table');

  // --- Filtering & Pagination State ---
  const [selectedBlockId, setSelectedBlockId] = useState<string>('all');
  const [selectedFloorId, setSelectedFloorId] = useState<string>('all');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [debouncedSearch, setDebouncedSearch] = useState<string>('');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const perPage = 15;

  // Helper deduplicate apartments by strictly unique ID
  const deduplicateApartments = (list: ApartmentItem[]): ApartmentItem[] => {
    const seen = new Set<string>();
    return (list || []).filter((item) => {
      if (!item || !item.id || seen.has(item.id)) return false;
      seen.add(item.id);
      return true;
    });
  };

  // 1. In-memory Instant Filtering (0ms Response Time)
  const filteredApartments = useMemo(() => {
    return allApartments.filter((apt) => {
      if (selectedBlockId !== 'all' && apt.block_id !== selectedBlockId) {
        return false;
      }
      if (selectedFloorId !== 'all' && apt.floor_id !== selectedFloorId) {
        return false;
      }
      if (statusFilter !== 'all') {
        const norm = normalizeStatusKey(apt.status);
        if (norm !== statusFilter) {
          return false;
        }
      }
      if (debouncedSearch) {
        const term = debouncedSearch.toLowerCase();
        const numMatch = apt.apartment_number?.toLowerCase().includes(term);
        const blockMatch =
          apt.block?.block_code?.toLowerCase().includes(term) ||
          apt.block?.block_name?.toLowerCase().includes(term);
        const ownerMatch =
          apt.primary_owner?.full_name?.toLowerCase().includes(term) ||
          apt.primary_owner?.phone_number?.includes(term);
        if (!numMatch && !blockMatch && !ownerMatch) {
          return false;
        }
      }
      return true;
    });
  }, [allApartments, selectedBlockId, selectedFloorId, statusFilter, debouncedSearch]);

  const totalApartmentsCount = filteredApartments.length;
  const totalPages = Math.max(1, Math.ceil(filteredApartments.length / perPage));

  // 2. In-memory Instant Pagination Slice (0ms Response Time)
  const displayedApartments = useMemo(() => {
    const validPage = Math.min(Math.max(1, currentPage), totalPages);
    const start = (validPage - 1) * perPage;
    return filteredApartments.slice(start, start + perPage);
  }, [filteredApartments, currentPage, totalPages, perPage]);

  // Adjust page if filter shrinks total pages
  useEffect(() => {
    if (currentPage > totalPages && totalPages > 0) {
      setCurrentPage(1);
    }
  }, [currentPage, totalPages]);

  // Debounce search query so typing does not trigger API requests on every single keypress
  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedSearch(searchQuery.trim());
      setCurrentPage(1);
    }, 280);
    return () => clearTimeout(handler);
  }, [searchQuery]);

  // --- Modal Visibility States ---
  const [showBatchModal, setShowBatchModal] = useState<boolean>(false);
  const [showApartmentModal, setShowApartmentModal] = useState<boolean>(false);
  const [showFloorModal, setShowFloorModal] = useState<boolean>(false);
  const [showDeleteModal, setShowDeleteModal] = useState<boolean>(false);

  // --- Editing / Selected Item State ---
  const [selectedApartment, setSelectedApartment] = useState<ApartmentItem | null>(null);
  const [apartmentToDelete, setApartmentToDelete] = useState<ApartmentItem | null>(null);

  // --- Batch Generate Form State ---
  const [batchBlockId, setBatchBlockId] = useState<string>('');
  const [batchFloorId, setBatchFloorId] = useState<string>('');
  const [batchCount, setBatchCount] = useState<number>(8);
  const [batchPrefix, setBatchPrefix] = useState<string>('');
  const [batchStartNum, setBatchStartNum] = useState<number>(1);
  const [batchRoomType, setBatchRoomType] = useState<string>('2_BEDROOM');
  const [batchGrossArea, setBatchGrossArea] = useState<number>(75.0);
  const [batchNetArea, setBatchNetArea] = useState<number>(68.5);
  const [batchStatus, setBatchStatus] = useState<'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE'>('VACANT');
  const [batchFurnished, setBatchFurnished] = useState<'RAW' | 'BASIC' | 'FULL'>('BASIC');
  const [batchFloorsList, setBatchFloorsList] = useState<FloorItem[]>([]);

  // --- Single Apartment Form State ---
  const [formAptBlockId, setFormAptBlockId] = useState<string>('');
  const [formAptFloorId, setFormAptFloorId] = useState<string>('');
  const [formAptNumber, setFormAptNumber] = useState<string>('');
  const [formAptRoomType, setFormAptRoomType] = useState<string>('2_BEDROOM');
  const [formAptGrossArea, setFormAptGrossArea] = useState<number>(75.0);
  const [formAptNetArea, setFormAptNetArea] = useState<number>(68.5);
  const [formAptBedrooms, setFormAptBedrooms] = useState<number>(2);
  const [formAptBathrooms, setFormAptBathrooms] = useState<number>(2);
  const [formAptStatus, setFormAptStatus] = useState<'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE'>('VACANT');
  const [formAptFurnished, setFormAptFurnished] = useState<'RAW' | 'BASIC' | 'FULL'>('BASIC');
  const [formAptFee, setFormAptFee] = useState<number>(800000);
  const [formAptFloorsList, setFormAptFloorsList] = useState<FloorItem[]>([]);

  // --- Add Floor Form State ---
  const [formFloorBlockId, setFormFloorBlockId] = useState<string>('');
  const [formFloorNumber, setFormFloorNumber] = useState<number>(1);
  const [formFloorCode, setFormFloorCode] = useState<string>('');
  const [formFloorName, setFormFloorName] = useState<string>('');
  const [formFloorType, setFormFloorType] = useState<string>('RESIDENTIAL');

  // Helper auto dismiss feedback
  const showNotification = (type: 'success' | 'error', text: string) => {
    setFeedbackMessage({ type, text });
    setTimeout(() => {
      setFeedbackMessage(null);
    }, 4500);
  };

  // --- 1. Master Fast Bootstrap (1 Request duy nhất tải toàn bộ Blocks, Floors, Stats & Căn hộ) ---
  const fetchBootstrap = useCallback(async (isSilent = false) => {
    if (!isSilent && allApartments.length === 0) {
      setIsLoading(true);
    }
    try {
      const data = await buildingStructureApi.getBootstrap();
      if (data) {
        if (Array.isArray(data.blocks) && data.blocks.length > 0) {
          globalCachedBlocks = data.blocks;
          setBlocks(data.blocks);
          if (!batchBlockId && data.blocks[0]?.id) {
            setBatchBlockId(data.blocks[0].id);
            setFormAptBlockId(data.blocks[0].id);
            setFormFloorBlockId(data.blocks[0].id);
          }
        }
        if (Array.isArray(data.floors)) {
          allFloorsCacheRef.current = data.floors;
          if (selectedBlockId !== 'all') {
            const matched = data.floors.filter((f) => f.block_id === selectedBlockId);
            setFloors(matched);
          }
        }
        if (Array.isArray(data.apartments) && data.apartments.length > 0) {
          const unique = deduplicateApartments(data.apartments);
          globalCachedApartments = unique;
          setAllApartments(unique);
        }
        if (data.version) {
          lastKnownVersionRef.current = data.version;
        }
        savePersistedCache(data.blocks || globalCachedBlocks, data.apartments || globalCachedApartments, data.floors || allFloorsCacheRef.current);
      }
    } catch (err: any) {
      console.warn('Bootstrap API gặp lỗi, chuyển sang tải độc lập:', err);
      // Fallback
      fetchBlocks(true);
      fetchApartments(true, true);
    } finally {
      setIsLoading(false);
    }
  }, [allApartments.length, batchBlockId, selectedBlockId]);

  // --- 2. Fetch Blocks Overview (Fallback / Polling) ---
  const fetchBlocks = useCallback(async (isSilent = false) => {
    try {
      const data = await buildingStructureApi.getBlocks();
      const safeData: BlockItem[] = Array.isArray(data)
        ? data
        : (data && typeof data === 'object' ? Object.values(data) : []);
      globalCachedBlocks = safeData;
      setBlocks(safeData);
      if (safeData.length > 0 && !batchBlockId) {
        setBatchBlockId(safeData[0].id);
        setFormAptBlockId(safeData[0].id);
        setFormFloorBlockId(safeData[0].id);
      }
      savePersistedCache(safeData, globalCachedApartments, allFloorsCacheRef.current);
    } catch (err: any) {
      console.error('Lỗi tải danh sách tòa nhà:', err);
    }
  }, [batchBlockId]);

  // --- 3. Fetch Floors for Filter (0ms từ RAM nếu đã có qua Bootstrap) ---
  const fetchFilterFloors = useCallback(async () => {
    if (selectedBlockId === 'all') {
      setFloors([]);
      setSelectedFloorId('all');
      return;
    }
    // Ưu tiên đọc tức thì 0ms từ allFloorsCacheRef
    if (allFloorsCacheRef.current.length > 0) {
      const matched = allFloorsCacheRef.current.filter((f) => f.block_id === selectedBlockId);
      if (matched.length > 0) {
        setFloors(matched);
        return;
      }
    }
    try {
      const data = await buildingStructureApi.getFloors(selectedBlockId);
      const safeFloors: FloorItem[] = Array.isArray(data)
        ? data
        : (data && typeof data === 'object' ? Object.values(data) : []);
      setFloors(safeFloors);
    } catch (err: any) {
      console.error('Lỗi tải tầng tòa nhà:', err);
    }
  }, [selectedBlockId]);

  // --- 4. Fetch All Apartments into Client Memory (Fallback) ---
  const fetchApartments = useCallback(async (bypassCache = false, isSilent = false) => {
    if (!bypassCache && globalCachedApartments.length > 0) {
      setAllApartments(globalCachedApartments);
      setIsLoading(false);
      return;
    }

    if (!isSilent && allApartments.length === 0) {
      setIsLoading(true);
    }
    try {
      const res = await buildingStructureApi.getApartments({
        per_page: 1000,
        sort_by: 'apartment_number',
        sort_order: 'asc',
      });

      const rawData = res.data || [];
      const uniqueData = deduplicateApartments(rawData);
      globalCachedApartments = uniqueData;
      setAllApartments(uniqueData);
      savePersistedCache(globalCachedBlocks, uniqueData, allFloorsCacheRef.current);
    } catch (err: any) {
      console.error('Lỗi tải danh sách căn hộ:', err);
      if (!isSilent) {
        showNotification('error', err.message || 'Không thể tải danh sách căn hộ');
      }
    } finally {
      setIsLoading(false);
    }
  }, [allApartments.length]);

  // Unified Initial Mount: Nếu đã có dữ liệu cache -> Chạy ngầm 100% không làm gián đoạn UI
  useEffect(() => {
    const hasCached = globalCachedBlocks.length > 0 && globalCachedApartments.length > 0;
    fetchBootstrap(hasCached);
  }, [fetchBootstrap]);

  useEffect(() => {
    fetchFilterFloors();
  }, [fetchFilterFloors]);

  // Keep mutable refs pointing to latest function instances to prevent stale closure bugs
  const fetchBootstrapRef = useRef(fetchBootstrap);
  fetchBootstrapRef.current = fetchBootstrap;

  const fetchApartmentsRef = useRef(fetchApartments);
  fetchApartmentsRef.current = fetchApartments;

  const fetchBlocksRef = useRef(fetchBlocks);
  fetchBlocksRef.current = fetchBlocks;

  const fetchFilterFloorsRef = useRef(fetchFilterFloors);
  fetchFilterFloorsRef.current = fetchFilterFloors;

  const lastKnownVersionRef = useRef<number>(1);
  const syncDebounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // --- 4. Cross-Tab Realtime Synchronization Listener (0ms tức thì cho toàn bộ tác vụ) ---
  useEffect(() => {
    const unsubscribe = buildingSyncManager.subscribe((event: BuildingSyncEvent) => {
      // 1. APARTMENT_STATUS_UPDATED (0ms tức thì trên tất cả tab đang mở)
      if (event.type === 'APARTMENT_STATUS_UPDATED') {
        const { apartmentId, status, oldStatus, blockId } = event;
        if (apartmentId && status) {
          const nextStatus = normalizeStatusKey(status);
          const prevStatus = oldStatus ? normalizeStatusKey(oldStatus) : null;

          setAllApartments((prev) => {
            const updated = prev.map((item) => (item.id === apartmentId ? { ...item, status: nextStatus } : item));
            globalCachedApartments = updated;
            return updated;
          });

          if (blockId && prevStatus && prevStatus !== nextStatus) {
            setBlocks((prevBlocks) => {
              const updatedList = prevBlocks.map((b) => {
                if (b.id !== blockId) return b;
                const updated = { ...b };
                if (prevStatus === 'VACANT') updated.vacant_apartments = Math.max(0, (Number(updated.vacant_apartments) || 0) - 1);
                else if (prevStatus === 'OCCUPIED') updated.occupied_apartments = Math.max(0, (Number(updated.occupied_apartments) || 0) - 1);
                else if (prevStatus === 'RENTED') updated.rented_apartments = Math.max(0, (Number(updated.rented_apartments) || 0) - 1);
                else if (prevStatus === 'MAINTENANCE') updated.maintenance_apartments = Math.max(0, (Number(updated.maintenance_apartments) || 0) - 1);

                if (nextStatus === 'VACANT') updated.vacant_apartments = (Number(updated.vacant_apartments) || 0) + 1;
                else if (nextStatus === 'OCCUPIED') updated.occupied_apartments = (Number(updated.occupied_apartments) || 0) + 1;
                else if (nextStatus === 'RENTED') updated.rented_apartments = (Number(updated.rented_apartments) || 0) + 1;
                else if (nextStatus === 'MAINTENANCE') updated.maintenance_apartments = (Number(updated.maintenance_apartments) || 0) + 1;

                return updated;
              });
              globalCachedBlocks = updatedList;
              return updatedList;
            });
          }
        }
      }

      // 2. APARTMENT_DELETED (0ms xóa tức thì trên tab còn lại)
      else if (event.type === 'APARTMENT_DELETED') {
        const { apartmentId, blockId, status } = event;
        if (apartmentId) {
          setAllApartments((prev) => {
            const updated = prev.filter((item) => item.id !== apartmentId);
            globalCachedApartments = updated;
            return updated;
          });
        }
        if (blockId) {
          const normDelStatus = status ? normalizeStatusKey(status) : null;
          setBlocks((prevBlocks) => {
            const updatedList = prevBlocks.map((b) => {
              if (b.id !== blockId) return b;
              const updated = { ...b };
              updated.total_apartments = Math.max(0, (Number(updated.total_apartments) || 0) - 1);
              if (normDelStatus === 'VACANT') updated.vacant_apartments = Math.max(0, (Number(updated.vacant_apartments) || 0) - 1);
              else if (normDelStatus === 'OCCUPIED') updated.occupied_apartments = Math.max(0, (Number(updated.occupied_apartments) || 0) - 1);
              else if (normDelStatus === 'RENTED') updated.rented_apartments = Math.max(0, (Number(updated.rented_apartments) || 0) - 1);
              else if (normDelStatus === 'MAINTENANCE') updated.maintenance_apartments = Math.max(0, (Number(updated.maintenance_apartments) || 0) - 1);
              return updated;
            });
            globalCachedBlocks = updatedList;
            return updatedList;
          });
        }
      }

      // 3. APARTMENT_UPDATED (0ms cập nhật chi tiết tức thì)
      else if (event.type === 'APARTMENT_UPDATED' && event.payload) {
        const updated = event.payload;
        setAllApartments((prev) => {
          const list = prev.map((item) => (item.id === updated.id ? { ...item, ...updated } : item));
          globalCachedApartments = list;
          return list;
        });
      }

      // 4. APARTMENT_CREATED (0ms thêm mới tức thì)
      else if (event.type === 'APARTMENT_CREATED' && event.payload) {
        const created = event.payload;
        const normCreatedStatus = normalizeStatusKey(created.status);
        setAllApartments((prev) => {
          const list = [created, ...prev.filter((a) => a.id !== created.id)];
          globalCachedApartments = list;
          return list;
        });

        if (event.blockId) {
          setBlocks((prevBlocks) => {
            const updatedList = prevBlocks.map((b) => {
              if (b.id !== event.blockId) return b;
              const up = { ...b };
              up.total_apartments = (Number(up.total_apartments) || 0) + 1;
              if (normCreatedStatus === 'OCCUPIED') up.occupied_apartments = (Number(up.occupied_apartments) || 0) + 1;
              else if (normCreatedStatus === 'RENTED') up.rented_apartments = (Number(up.rented_apartments) || 0) + 1;
              else if (normCreatedStatus === 'MAINTENANCE') up.maintenance_apartments = (Number(up.maintenance_apartments) || 0) + 1;
              else up.vacant_apartments = (Number(up.vacant_apartments) || 0) + 1;
              return up;
            });
            globalCachedBlocks = updatedList;
            return updatedList;
          });
        }
      }

      // 5. BATCH_APARTMENTS_GENERATED (0ms cập nhật tổng căn tức thì)
      else if (event.type === 'BATCH_APARTMENTS_GENERATED') {
        const count = Number(event.payload?.count) || 1;
        if (event.blockId) {
          setBlocks((prevBlocks) => {
            const updatedList = prevBlocks.map((b) => {
              if (b.id !== event.blockId) return b;
              const up = { ...b };
              up.total_apartments = (Number(up.total_apartments) || 0) + count;
              up.vacant_apartments = (Number(up.vacant_apartments) || 0) + count;
              return up;
            });
            globalCachedBlocks = updatedList;
            return updatedList;
          });
        }
        fetchApartmentsRef.current(true, true);
      }

      // 6. FLOOR_CREATED (0ms cập nhật tầng tức thì)
      else if (event.type === 'FLOOR_CREATED' && event.blockId) {
        setBlocks((prevBlocks) => {
          const updatedList = prevBlocks.map((b) => {
            if (b.id !== event.blockId) return b;
            return {
              ...b,
              total_floors: (Number(b.total_floors) || 0) + 1,
              floors_count: (Number(b.floors_count) || 0) + 1,
            };
          });
          globalCachedBlocks = updatedList;
          return updatedList;
        });
      }

      // Lưu đệm session storage tức thì khi nhận cập nhật chéo từ tab khác (F5 không mất dữ liệu)
      savePersistedCache(globalCachedBlocks, globalCachedApartments, allFloorsCacheRef.current);

      // Debounced background sync từ database (tránh race condition khi database đang ghi)
      if (syncDebounceTimerRef.current) {
        clearTimeout(syncDebounceTimerRef.current);
      }
      syncDebounceTimerRef.current = setTimeout(() => {
        fetchApartmentsRef.current(true, true);
        fetchBlocksRef.current(true);
        if (event.type === 'FLOOR_CREATED' || event.type === 'BATCH_APARTMENTS_GENERATED') {
          fetchFilterFloorsRef.current();
        }
      }, 1000);
    });

    return () => {
      unsubscribe();
      if (syncDebounceTimerRef.current) {
        clearTimeout(syncDebounceTimerRef.current);
      }
    };
  }, []);

  // --- 5. Window Focus & Visibility Change Listener (0ms sync when user switches/duplicates tabs) ---
  useEffect(() => {
    const handleSyncCheck = () => {
      if (typeof document !== 'undefined' && document.visibilityState === 'visible') {
        buildingStructureApi.getDataVersion().then(({ version }) => {
          if (version > lastKnownVersionRef.current) {
            lastKnownVersionRef.current = version;
            // Cập nhật ngầm (silent) không ngắt trải nghiệm hay làm đơ màn hình người dùng
            fetchApartmentsRef.current(true, true);
            fetchBlocksRef.current(true);
            fetchFilterFloorsRef.current();
          }
        }).catch(() => {});
      }
    };

    window.addEventListener('focus', handleSyncCheck);
    document.addEventListener('visibilitychange', handleSyncCheck);

    return () => {
      window.removeEventListener('focus', handleSyncCheck);
      document.removeEventListener('visibilitychange', handleSyncCheck);
    };
  }, []);

  // --- 6. Polling Heartbeat (every 4s khi tab active - đảm bảo đồng bộ liên tục không lag / không nghẽn) ---
  useEffect(() => {
    // Đọc phiên bản khởi tạo
    buildingStructureApi.getDataVersion().then(({ version }) => {
      lastKnownVersionRef.current = version;
    }).catch(() => {});

    const interval = setInterval(() => {
      // Chỉ kiểm tra phiên bản khi người dùng đang mở tab (tiết kiệm tài nguyên và băng thông)
      if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
        return;
      }

      buildingStructureApi.getDataVersion().then(({ version }) => {
        if (version > lastKnownVersionRef.current) {
          lastKnownVersionRef.current = version;
          // Cập nhật ngầm (silent) 100% mượt mà
          fetchApartmentsRef.current(true, true);
          fetchBlocksRef.current(true);
          fetchFilterFloorsRef.current();
        }
      }).catch(() => {});
    }, 4000);

    return () => clearInterval(interval);
  }, []);


  // Update floors when modal block selectors change
  useEffect(() => {
    if (batchBlockId) {
      buildingStructureApi.getFloors(batchBlockId).then((data) => {
        setBatchFloorsList(data);
        if (data.length > 0) {
          setBatchFloorId(data[0].id);
          const blk = blocks.find((b) => b.id === batchBlockId);
          const prefix = blk ? `${blk.block_code}-${data[0].floor_number.toString().padStart(2, '0')}` : `F${data[0].floor_number}-`;
          setBatchPrefix(prefix);
        }
      });
    }
  }, [batchBlockId, blocks]);

  useEffect(() => {
    if (formAptBlockId) {
      buildingStructureApi.getFloors(formAptBlockId).then((data) => {
        setFormAptFloorsList(data);
        if (data.length > 0 && !formAptFloorId) {
          setFormAptFloorId(data[0].id);
        }
      });
    }
  }, [formAptBlockId, formAptFloorId]);

  // Update batch prefix when batch floor changes
  const handleBatchFloorChange = (newFloorId: string) => {
    setBatchFloorId(newFloorId);
    const chosenFloor = batchFloorsList.find((f) => f.id === newFloorId);
    const blk = blocks.find((b) => b.id === batchBlockId);
    if (chosenFloor && blk) {
      const flrNum = chosenFloor.floor_number.toString().padStart(2, '0');
      setBatchPrefix(`${blk.block_code}-${flrNum}`);
    }
  };

  // --- Aggregate Stats Calculations ---
  const statsOverview = useMemo(() => {
    const list: BlockItem[] = Array.isArray(blocks)
      ? blocks
      : (blocks && typeof blocks === 'object' ? (Object.values(blocks) as BlockItem[]) : []);
    const totalAll = list.reduce((sum: number, b: BlockItem) => sum + (Number(b.total_apartments) || 0), 0);
    const occupiedAll = list.reduce((sum: number, b: BlockItem) => sum + (Number(b.occupied_apartments) || 0), 0);
    const rentedAll = list.reduce((sum: number, b: BlockItem) => sum + (Number(b.rented_apartments) || 0), 0);
    const vacantAll = list.reduce((sum: number, b: BlockItem) => sum + (Number(b.vacant_apartments) || 0), 0);
    const maintenanceAll = list.reduce((sum: number, b: BlockItem) => sum + (Number(b.maintenance_apartments) || 0), 0);
    const occupancyRate = totalAll > 0 ? (((occupiedAll + rentedAll) / totalAll) * 100).toFixed(1) : '0';

    return {
      total: totalAll,
      occupied: occupiedAll,
      rented: rentedAll,
      vacant: vacantAll,
      maintenance: maintenanceAll,
      occupancyRate,
    };
  }, [blocks]);

  // --- Actions ---

  // Fast Status Change with instant Optimistic UI update & 0ms Cross-Tab Broadcast
  const handleFastStatusChange = async (apt: ApartmentItem, newStatus: 'VACANT' | 'OCCUPIED' | 'RENTED' | 'MAINTENANCE') => {
    const oldStatus = normalizeStatusKey(apt.status);
    const targetStatus = normalizeStatusKey(newStatus);
    if (oldStatus === targetStatus) return;

    // 1. Optimistic UI: Update local in-memory dataset immediately (0ms user delay)
    setAllApartments((prev) => {
      const updated = prev.map((item) => (item.id === apt.id ? { ...item, status: targetStatus } : item));
      globalCachedApartments = updated;
      return updated;
    });

    // 2. Optimistic UI: Adjust block counter stats instantly with normalized keys
    setBlocks((prevBlocks) => {
      const updatedList = prevBlocks.map((b) => {
        if (b.id !== apt.block_id) return b;
        const updated = { ...b };
        if (oldStatus === 'VACANT') updated.vacant_apartments = Math.max(0, (Number(updated.vacant_apartments) || 0) - 1);
        else if (oldStatus === 'OCCUPIED') updated.occupied_apartments = Math.max(0, (Number(updated.occupied_apartments) || 0) - 1);
        else if (oldStatus === 'RENTED') updated.rented_apartments = Math.max(0, (Number(updated.rented_apartments) || 0) - 1);
        else if (oldStatus === 'MAINTENANCE') updated.maintenance_apartments = Math.max(0, (Number(updated.maintenance_apartments) || 0) - 1);

        if (targetStatus === 'VACANT') updated.vacant_apartments = (Number(updated.vacant_apartments) || 0) + 1;
        else if (targetStatus === 'OCCUPIED') updated.occupied_apartments = (Number(updated.occupied_apartments) || 0) + 1;
        else if (targetStatus === 'RENTED') updated.rented_apartments = (Number(updated.rented_apartments) || 0) + 1;
        else if (targetStatus === 'MAINTENANCE') updated.maintenance_apartments = (Number(updated.maintenance_apartments) || 0) + 1;

        return updated;
      });
      globalCachedBlocks = updatedList;
      return updatedList;
    });

    // Cập nhật session storage ngay lập tức (0ms phục hồi khi F5)
    savePersistedCache(globalCachedBlocks, globalCachedApartments, allFloorsCacheRef.current);

    // 3. Broadcast INSTANTLY (0ms) to other tabs/windows
    buildingSyncManager.broadcast({
      type: 'APARTMENT_STATUS_UPDATED',
      apartmentId: apt.id,
      status: targetStatus,
      oldStatus: oldStatus,
      blockId: apt.block_id,
      floorId: apt.floor_id,
    });

    try {
      await buildingStructureApi.updateApartmentStatus(apt.id, targetStatus);
      showNotification('success', `Đã cập nhật trạng thái căn hộ ${apt.apartment_number} thành: ${getStatusLabel(targetStatus)}`);
      lastKnownVersionRef.current = (lastKnownVersionRef.current || 1) + 1;
    } catch (err: any) {
      // Revert optimistic updates on error both locally and across tabs
      setAllApartments((prev) => {
        const reverted = prev.map((item) => (item.id === apt.id ? { ...item, status: oldStatus } : item));
        globalCachedApartments = reverted;
        return reverted;
      });
      buildingSyncManager.broadcast({
        type: 'APARTMENT_STATUS_UPDATED',
        apartmentId: apt.id,
        status: oldStatus,
        oldStatus: targetStatus,
        blockId: apt.block_id,
        floorId: apt.floor_id,
      });
      fetchBlocks(true);
      showNotification('error', err.message || 'Lỗi cập nhật trạng thái căn hộ');
    }
  };

  // Batch Generate Submit with 0ms Cross-Tab Broadcast
  const handleBatchGenerate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!batchBlockId || !batchFloorId) {
      showNotification('error', 'Vui lòng chọn Tòa nhà và Tầng cần khởi tạo');
      return;
    }
    if (batchCount < 1 || batchCount > 100) {
      showNotification('error', 'Số lượng căn khởi tạo phải từ 1 đến 100');
      return;
    }

    setIsActionLoading(true);
    try {
      const payload: BatchGeneratePayload = {
        block_id: batchBlockId,
        floor_id: batchFloorId,
        count: Number(batchCount),
        prefix: batchPrefix.trim(),
        start_number: Number(batchStartNum),
        room_type: batchRoomType,
        gross_floor_area_sqm: Number(batchGrossArea),
        net_usable_area_sqm: Number(batchNetArea),
        bedroom_count: batchRoomType === '1_BEDROOM' ? 1 : batchRoomType === '3_BEDROOM' ? 3 : 2,
        bathroom_count: batchRoomType === '1_BEDROOM' ? 1 : 2,
        status: batchStatus,
        furnished_status: batchFurnished,
      };

      const result = await buildingStructureApi.batchGenerateApartments(payload);
      showNotification(
        'success',
        `Khởi tạo thành công ${result.created_count} căn hộ theo tầng! (${result.skipped_existing} căn đã tồn tại được bỏ qua).`
      );
      setShowBatchModal(false);
      // Broadcast batch creation event to other tabs/windows immediately
      buildingSyncManager.broadcast({
        type: 'BATCH_APARTMENTS_GENERATED',
        blockId: batchBlockId,
        floorId: batchFloorId,
        payload: { count: result.created_count },
      });
      fetchApartments(true);
      fetchBlocks(true);
      lastKnownVersionRef.current = (lastKnownVersionRef.current || 1) + 1;
    } catch (err: any) {
      showNotification('error', err.message || 'Không thể tạo danh sách căn hộ hàng loạt');
    } finally {
      setIsActionLoading(false);
    }
  };

  // Open Create Apartment Modal
  const handleOpenCreateModal = () => {
    setSelectedApartment(null);
    setFormAptNumber('');
    setFormAptRoomType('2_BEDROOM');
    setFormAptGrossArea(75.0);
    setFormAptNetArea(68.5);
    setFormAptBedrooms(2);
    setFormAptBathrooms(2);
    setFormAptStatus('VACANT');
    setFormAptFurnished('BASIC');
    setFormAptFee(800000);
    setShowApartmentModal(true);
  };

  // Open Edit Apartment Modal
  const handleOpenEditModal = (apt: ApartmentItem) => {
    setSelectedApartment(apt);
    setFormAptBlockId(apt.block_id);
    setFormAptFloorId(apt.floor_id);
    setFormAptNumber(apt.apartment_number);
    setFormAptRoomType(apt.room_type || '2_BEDROOM');
    setFormAptGrossArea(Number(apt.gross_floor_area_sqm) || 75.0);
    setFormAptNetArea(Number(apt.net_usable_area_sqm) || 68.5);
    setFormAptBedrooms(apt.bedroom_count || 2);
    setFormAptBathrooms(apt.bathroom_count || 2);
    setFormAptStatus(normalizeStatusKey(apt.status));
    setFormAptFurnished((apt.furnished_status as any) || 'BASIC');
    setFormAptFee(Number(apt.monthly_management_fee_fixed) || 800000);
    setShowApartmentModal(true);
  };

  // Save Apartment (Create / Update) with 0ms Optimistic UI & Broadcast
  const handleSaveApartment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formAptBlockId || !formAptFloorId || !formAptNumber.trim()) {
      showNotification('error', 'Vui lòng điền đầy đủ Tòa, Tầng và Mã căn hộ');
      return;
    }

    const payload: Partial<ApartmentItem> = {
      block_id: formAptBlockId,
      floor_id: formAptFloorId,
      apartment_number: formAptNumber.trim(),
      room_type: formAptRoomType,
      gross_floor_area_sqm: Number(formAptGrossArea),
      net_usable_area_sqm: Number(formAptNetArea),
      bedroom_count: Number(formAptBedrooms),
      bathroom_count: Number(formAptBathrooms),
      status: formAptStatus,
      furnished_status: formAptFurnished,
      monthly_management_fee_fixed: Number(formAptFee),
    };

    if (selectedApartment) {
      // EDIT: 0ms Optimistic Update
      const originalApt = { ...selectedApartment };
      const updatedApt: ApartmentItem = { ...selectedApartment, ...payload } as ApartmentItem;
      const oldStatus = normalizeStatusKey(originalApt.status);
      const newStatus = normalizeStatusKey(payload.status || originalApt.status);

      // 1. Optimistic update local in-memory dataset
      setAllApartments((prev) => {
        const list = prev.map((item) => (item.id === originalApt.id ? updatedApt : item));
        globalCachedApartments = list;
        return list;
      });

      // 2. Optimistic update block stats if status changed
      if (oldStatus !== newStatus) {
        setBlocks((prevBlocks) =>
          prevBlocks.map((b) => {
            if (b.id !== originalApt.block_id) return b;
            const updated = { ...b };
            if (oldStatus === 'VACANT') updated.vacant_apartments = Math.max(0, (Number(updated.vacant_apartments) || 0) - 1);
            else if (oldStatus === 'OCCUPIED') updated.occupied_apartments = Math.max(0, (Number(updated.occupied_apartments) || 0) - 1);
            else if (oldStatus === 'RENTED') updated.rented_apartments = Math.max(0, (Number(updated.rented_apartments) || 0) - 1);
            else if (oldStatus === 'MAINTENANCE') updated.maintenance_apartments = Math.max(0, (Number(updated.maintenance_apartments) || 0) - 1);

            if (newStatus === 'VACANT') updated.vacant_apartments = (Number(updated.vacant_apartments) || 0) + 1;
            else if (newStatus === 'OCCUPIED') updated.occupied_apartments = (Number(updated.occupied_apartments) || 0) + 1;
            else if (newStatus === 'RENTED') updated.rented_apartments = (Number(updated.rented_apartments) || 0) + 1;
            else if (newStatus === 'MAINTENANCE') updated.maintenance_apartments = (Number(updated.maintenance_apartments) || 0) + 1;
            return updated;
          })
        );
      }

      // Lưu session storage tức thì cho tác vụ sửa căn hộ
      savePersistedCache(globalCachedBlocks, globalCachedApartments, allFloorsCacheRef.current);

      setShowApartmentModal(false);

      // 3. Broadcast INSTANTLY (0ms) to other tabs
      buildingSyncManager.broadcast({
        type: 'APARTMENT_UPDATED',
        apartmentId: originalApt.id,
        blockId: formAptBlockId,
        floorId: formAptFloorId,
        payload: updatedApt,
      });
      if (oldStatus !== newStatus) {
        buildingSyncManager.broadcast({
          type: 'APARTMENT_STATUS_UPDATED',
          apartmentId: originalApt.id,
          status: newStatus,
          oldStatus: oldStatus,
          blockId: originalApt.block_id,
          floorId: originalApt.floor_id,
        });
      }

      try {
        await buildingStructureApi.updateApartment(originalApt.id, payload);
        showNotification('success', `Đã cập nhật thông tin căn hộ ${formAptNumber}`);
        lastKnownVersionRef.current = (lastKnownVersionRef.current || 1) + 1;
      } catch (err: any) {
        // Revert on error
        setAllApartments((prev) => {
          const reverted = prev.map((item) => (item.id === originalApt.id ? originalApt : item));
          globalCachedApartments = reverted;
          return reverted;
        });
        buildingSyncManager.broadcast({
          type: 'APARTMENT_UPDATED',
          apartmentId: originalApt.id,
          blockId: originalApt.block_id,
          floorId: originalApt.floor_id,
          payload: originalApt,
        });
        fetchBlocks(true);
        showNotification('error', err.message || 'Lỗi lưu thông tin căn hộ');
      }
    } else {
      // CREATE
      setIsActionLoading(true);
      try {
        const createdData = await buildingStructureApi.createApartment(payload);
        showNotification('success', `Thêm mới thành công căn hộ ${formAptNumber}`);
        setShowApartmentModal(false);

        if (createdData && createdData.id) {
          setAllApartments((prev) => {
            const list = [createdData, ...prev.filter((a) => a.id !== createdData.id)];
            globalCachedApartments = list;
            return list;
          });
          savePersistedCache(globalCachedBlocks, [createdData, ...globalCachedApartments], allFloorsCacheRef.current);
        } else {
          fetchApartments(true);
        }
        fetchBlocks(true);

        lastKnownVersionRef.current = (lastKnownVersionRef.current || 1) + 1;
        // Broadcast to other tabs immediately
        buildingSyncManager.broadcast({
          type: 'APARTMENT_CREATED',
          apartmentId: createdData?.id,
          blockId: formAptBlockId,
          floorId: formAptFloorId,
          payload: createdData,
        });
      } catch (err: any) {
        showNotification('error', err.message || 'Lỗi thêm mới căn hộ');
      } finally {
        setIsActionLoading(false);
      }
    }
  };

  // Handle Delete Apartment with instant 0ms optimistic UI & broadcast
  const handleDeleteApartment = async () => {
    if (!apartmentToDelete) return;
    const deletedId = apartmentToDelete.id;
    const deletedBlockId = apartmentToDelete.block_id;
    const deletedFloorId = apartmentToDelete.floor_id;
    const deletedStatus = normalizeStatusKey(apartmentToDelete.status);
    const deletedApartmentItem = { ...apartmentToDelete };

    // 1. Optimistic local removal (0ms)
    setAllApartments((prev) => {
      const filtered = prev.filter((item) => item.id !== deletedId);
      globalCachedApartments = filtered;
      return filtered;
    });
    setBlocks((prevBlocks) =>
      prevBlocks.map((b) => {
        if (b.id !== deletedBlockId) return b;
        const updated = { ...b };
        updated.total_apartments = Math.max(0, (Number(updated.total_apartments) || 0) - 1);
        if (deletedStatus === 'VACANT') updated.vacant_apartments = Math.max(0, (Number(updated.vacant_apartments) || 0) - 1);
        else if (deletedStatus === 'OCCUPIED') updated.occupied_apartments = Math.max(0, (Number(updated.occupied_apartments) || 0) - 1);
        else if (deletedStatus === 'RENTED') updated.rented_apartments = Math.max(0, (Number(updated.rented_apartments) || 0) - 1);
        else if (deletedStatus === 'MAINTENANCE') updated.maintenance_apartments = Math.max(0, (Number(updated.maintenance_apartments) || 0) - 1);
        return updated;
      })
    );
    setShowDeleteModal(false);
    setApartmentToDelete(null);

    // Lưu session storage tức thì cho tác vụ xóa căn hộ
    savePersistedCache(globalCachedBlocks, globalCachedApartments, allFloorsCacheRef.current);

    // 2. Broadcast INSTANTLY (0ms) to other tabs
    buildingSyncManager.broadcast({
      type: 'APARTMENT_DELETED',
      apartmentId: deletedId,
      blockId: deletedBlockId,
      floorId: deletedFloorId,
      status: deletedStatus,
    });

    try {
      await buildingStructureApi.deleteApartment(deletedId);
      showNotification('success', `Đã chuyển căn hộ ${deletedApartmentItem.apartment_number} vào thùng rác`);
      lastKnownVersionRef.current = (lastKnownVersionRef.current || 1) + 1;
    } catch (err: any) {
      // Revert if delete fails
      setAllApartments((prev) => {
        const reverted = [deletedApartmentItem, ...prev];
        globalCachedApartments = reverted;
        return reverted;
      });
      fetchBlocks(true);
      showNotification('error', err.message || 'Lỗi xóa căn hộ');
    }
  };

  // Handle Add Floor with 0ms broadcast
  const handleCreateFloor = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formFloorBlockId) {
      showNotification('error', 'Vui lòng chọn Tòa nhà');
      return;
    }
    setIsActionLoading(true);
    try {
      await buildingStructureApi.createFloor(formFloorBlockId, {
        floor_number: Number(formFloorNumber),
        floor_code: formFloorCode.trim() || undefined,
        floor_name: formFloorName.trim() || `Tầng ${formFloorNumber}`,
        floor_type: formFloorType,
      });
      showNotification('success', `Đã thêm Tầng ${formFloorNumber} thành công`);
      setShowFloorModal(false);
      fetchBlocks(true);
      if (selectedBlockId === formFloorBlockId) {
        fetchFilterFloors();
      }
      lastKnownVersionRef.current = (lastKnownVersionRef.current || 1) + 1;
      // Broadcast floor creation event to other tabs/windows
      buildingSyncManager.broadcast({
        type: 'FLOOR_CREATED',
        blockId: formFloorBlockId,
      });
    } catch (err: any) {
      showNotification('error', err.message || 'Lỗi thêm tầng');
    } finally {
      setIsActionLoading(false);
    }
  };



  // Label Helpers
  const getStatusLabel = (status: string) => {
    switch (status) {
      case 'OCCUPIED':
        return 'Đã bán / Đang ở';
      case 'RENTED':
        return 'Đang thuê';
      case 'VACANT':
        return 'Trống';
      case 'MAINTENANCE':
        return 'Đang bảo trì';
      default:
        return status;
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'OCCUPIED':
        return (
          <span className="sass-db-status sass-db-status--occupied">
            <CheckCircle2 className="w-3.5 h-3.5" />
            Đã bán
          </span>
        );
      case 'RENTED':
        return (
          <span className="sass-db-status sass-db-status--rented">
            <KeyRound className="w-3.5 h-3.5" />
            Đang thuê
          </span>
        );
      case 'VACANT':
        return (
          <span className="sass-db-status sass-db-status--vacant">
            <Home className="w-3.5 h-3.5" />
            Trống
          </span>
        );
      case 'MAINTENANCE':
        return (
          <span className="sass-db-status sass-db-status--maintenance">
            <Wrench className="w-3.5 h-3.5" />
            Bảo trì
          </span>
        );
      default:
        return <span className="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-700">{status}</span>;
    }
  };

  const getRoomTypeLabel = (type: string) => {
    switch (type) {
      case 'STUDIO':
        return 'Studio';
      case '1_BEDROOM':
        return '1 Phòng ngủ';
      case '2_BEDROOM':
        return '2 Phòng ngủ';
      case '3_BEDROOM':
        return '3 Phòng ngủ (Góc)';
      case '4_BEDROOM':
        return '4 Phòng ngủ';
      case 'PENTHOUSE':
        return 'Penthouse Duplex';
      case 'DUPLEX':
        return 'Duplex';
      case 'COMMERCIAL':
        return 'Shophouse TM';
      default:
        return type || 'Tiêu chuẩn';
    }
  };

  return (
    <div className="w-full max-w-[2000px] mx-auto px-4 sm:px-6 lg:px-8 xl:px-10 py-6 sm:py-8 space-y-6 transition-all duration-300">
      {/* Toast Notification Alert */}
      {feedbackMessage && (
        <div
          className={`fixed top-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border text-sm font-medium transition-all transform animate-bounce ${
            feedbackMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 border-emerald-300'
              : 'bg-rose-50 text-rose-800 border-rose-300'
          }`}
        >
          {feedbackMessage.type === 'success' ? (
            <CheckCircle2 className="w-5 h-5 text-emerald-600" />
          ) : (
            <AlertTriangle className="w-5 h-5 text-rose-600" />
          )}
          <span>{feedbackMessage.text}</span>
          <button
            onClick={() => setFeedbackMessage(null)}
            className="ml-2 text-slate-400 hover:text-slate-600 cursor-pointer"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Top Segmented Sub-navigation Tabs (1. Quản lý Khối / Tòa nhà - xuanhoa/1 & 2. Quản lý Tầng & Căn hộ - xuanhoa/2) */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-200/90">
        <div className="inline-flex p-1 bg-slate-100 rounded-2xl border border-slate-200/80 shadow-2xs self-start">
          <button
            type="button"
            onClick={() => handleSectionChange('blocks')}
            className={`flex items-center gap-2 px-3.5 sm:px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all duration-200 cursor-pointer ${
              currentSection === 'blocks'
                ? 'bg-white text-sky-700 shadow-xs ring-1 ring-slate-200/70'
                : 'text-slate-600 hover:text-slate-900 hover:bg-white/60'
            }`}
          >
            <Building2 className={`w-4 h-4 ${currentSection === 'blocks' ? 'text-sky-600' : 'text-slate-400'}`} />
            <span>1. Quản lý Khối / Tòa nhà</span>
            <span className="text-[10px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 font-mono font-bold">
              {blocks.length} Khối
            </span>
          </button>

          <button
            type="button"
            onClick={() => handleSectionChange('apartments')}
            className={`flex items-center gap-2 px-3.5 sm:px-4 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all duration-200 cursor-pointer ${
              currentSection === 'apartments'
                ? 'bg-white text-indigo-700 shadow-xs ring-1 ring-slate-200/70'
                : 'text-slate-600 hover:text-slate-900 hover:bg-white/60'
            }`}
          >
            <Layers className={`w-4 h-4 ${currentSection === 'apartments' ? 'text-indigo-600' : 'text-slate-400'}`} />
            <span>2. Quản lý Tầng & Căn hộ</span>
            <span className="text-[10px] px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 font-mono font-bold">
              {totalApartmentsCount || statsOverview.total} Căn
            </span>
          </button>
        </div>

        {/* Action Buttons in Header depending on current section */}
        <div className="flex items-center gap-2 flex-wrap sm:flex-nowrap">
          {currentSection === 'blocks' ? (
            <>
              <button
                type="button"
                onClick={() => setShowFloorModal(true)}
                className="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-white text-slate-700 hover:bg-slate-50 border border-slate-200 shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
              >
                <Layers className="w-3.5 h-3.5 text-indigo-600 shrink-0" />
                <span>+ Thêm tầng</span>
              </button>
              <button
                type="button"
                onClick={() => setShowBatchModal(true)}
                className="inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white shadow-sm transition-all active:scale-[0.98] cursor-pointer"
              >
                <Sparkles className="w-3.5 h-3.5 shrink-0" />
                <span>Khởi tạo theo tầng</span>
              </button>
              <button
                type="button"
                onClick={() => {
                  fetchBlocks();
                  fetchApartments(true);
                }}
                title="Làm mới dữ liệu"
                className="p-2 rounded-xl text-slate-500 hover:text-slate-800 bg-white border border-slate-200 hover:bg-slate-50 shadow-2xs transition-all active:scale-[0.98] cursor-pointer shrink-0"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin text-sky-600' : ''}`} />
              </button>
            </>
          ) : (
            <>
              <button
                type="button"
                onClick={() => setShowBatchModal(true)}
                className="flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white shadow-sm transition-all active:scale-[0.98] cursor-pointer"
              >
                <Sparkles className="w-3.5 h-3.5 shrink-0" />
                <span className="truncate">Khởi tạo theo tầng</span>
              </button>

              <button
                type="button"
                onClick={handleOpenCreateModal}
                className="inline-flex items-center justify-center gap-1 px-2.5 sm:px-3 py-2 rounded-xl text-xs font-bold bg-white text-slate-700 hover:bg-slate-50 border border-slate-200 shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
              >
                <Plus className="w-3.5 h-3.5 text-sky-600 shrink-0" />
                <span>Thêm căn</span>
              </button>

              <button
                type="button"
                onClick={() => setShowFloorModal(true)}
                className="inline-flex items-center justify-center gap-1 px-2.5 sm:px-3 py-2 rounded-xl text-xs font-bold bg-white text-slate-700 hover:bg-slate-50 border border-slate-200 shadow-2xs transition-all active:scale-[0.98] cursor-pointer"
              >
                <Layers className="w-3.5 h-3.5 text-indigo-600 shrink-0" />
                <span>Thêm tầng</span>
              </button>

              <button
                type="button"
                onClick={() => {
                  fetchBlocks();
                  fetchApartments(true);
                }}
                title="Làm mới dữ liệu"
                className="p-2 rounded-xl text-slate-500 hover:text-slate-800 bg-white border border-slate-200 hover:bg-slate-50 shadow-2xs transition-all active:scale-[0.98] cursor-pointer shrink-0"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${isLoading ? 'animate-spin text-sky-600' : ''}`} />
              </button>
            </>
          )}
        </div>
      </div>

      {/* Header Info */}
      <div className="pb-1">
        <div className="flex items-center gap-1.5 sm:gap-2 text-[11px] sm:text-xs font-bold uppercase tracking-wider font-mono text-sky-600">
          <Building2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-sky-500 shrink-0" />
          <span>HỆ THỐNG KHỐI TÒA NHÀ & CĂN HỘ</span>
          <span className="text-slate-300">·</span>
          <span className="text-slate-500">
            {currentSection === 'blocks'
              ? 'CHỨC NĂNG #1 (QUẢN LÝ KHỐI / TÒA NHÀ)'
              : 'CHỨC NĂNG #2 (QUẢN LÝ TẦNG & CĂN HỘ)'}
          </span>
        </div>
        <h1 className="text-xl sm:text-2xl lg:text-3xl font-black text-neutral-900 tracking-tight mt-1">
          {currentSection === 'blocks' ? 'Quản lý Khối / Tòa nhà' : 'Quản lý Tầng & Căn hộ'}
        </h1>
        <p className="text-xs sm:text-sm text-slate-500 mt-1 max-w-3xl leading-relaxed">
          {currentSection === 'blocks'
            ? 'Tổng quan quy mô các tòa tháp cao tầng, tầng nổi, tầng hầm, cơ cấu cư trú và điều hành hạ tầng kỹ thuật.'
            : 'Khởi tạo danh sách căn hộ theo tầng hàng loạt, gắn trạng thái Đã bán - Đang thuê - Trống, quản lý sơ đồ và vận hành trực quan.'}
        </p>
      </div>

      {/* ============================================================== */}
      {/* SECTION 1: QUẢN LÝ KHỐI / TÒA NHÀ (XUANHOA/1)                  */}
      {/* ============================================================== */}
      {currentSection === 'blocks' && (
        <div className="space-y-5 animate-in fade-in duration-200">
          {/* 5 Realtime Summary Stats Cards */}
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2.5 sm:gap-3.5">
            {/* Card 1: Tổng quy mô */}
            <div className="sass-db-kpi-card">
              <div className="flex items-center justify-between">
                <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Tổng quy mô</span>
                <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                  <Building className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-neutral-900 font-mono">
                {statsOverview.total} <span className="text-xs font-normal text-slate-400">căn</span>
              </div>
              <div className="mt-1 text-[10px] sm:text-[11px] text-slate-500 truncate">
                {blocks.length} Tòa tháp cao tầng
              </div>
            </div>

            {/* Card 2: Đã bán / Đang ở */}
            <div className="sass-db-kpi-card">
              <div className="flex items-center justify-between">
                <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Đã bán / Ở</span>
                <div className="kpi-icon-wrapper bg-emerald-50 text-emerald-600">
                  <CheckCircle2 className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-emerald-600 font-mono">
                {statsOverview.occupied} <span className="text-xs font-normal text-slate-400">căn</span>
              </div>
              <div className="mt-1 text-[10px] sm:text-[11px] text-emerald-700 font-medium truncate">
                Tỷ lệ lấp đầy: {statsOverview.occupancyRate}%
              </div>
            </div>

            {/* Card 3: Đang thuê */}
            <div className="sass-db-kpi-card">
              <div className="flex items-center justify-between">
                <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Đang thuê</span>
                <div className="kpi-icon-wrapper bg-sky-50 text-sky-600">
                  <KeyRound className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-sky-600 font-mono">
                {statsOverview.rented} <span className="text-xs font-normal text-slate-400">căn</span>
              </div>
              <div className="mt-1 text-[10px] sm:text-[11px] text-sky-700 font-medium truncate">
                Hợp đồng thuê hiệu lực
              </div>
            </div>

            {/* Card 4: Căn hộ trống */}
            <div className="sass-db-kpi-card">
              <div className="flex items-center justify-between">
                <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Căn trống</span>
                <div className="kpi-icon-wrapper bg-amber-50 text-amber-600">
                  <Home className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-amber-600 font-mono">
                {statsOverview.vacant} <span className="text-xs font-normal text-slate-400">căn</span>
              </div>
              <div className="mt-1 text-[10px] sm:text-[11px] text-amber-700 font-medium truncate">
                Sẵn sàng mở bán / giao
              </div>
            </div>

            {/* Card 5: Đang bảo trì */}
            <div className="sass-db-kpi-card col-span-2 sm:col-span-1">
              <div className="flex items-center justify-between">
                <span className="text-[11px] sm:text-xs font-semibold text-slate-500">Đang bảo trì</span>
                <div className="kpi-icon-wrapper bg-purple-50 text-purple-600">
                  <Wrench className="w-4 h-4" />
                </div>
              </div>
              <div className="mt-1 sm:mt-2 text-xl sm:text-2xl font-black text-purple-600 font-mono">
                {statsOverview.maintenance} <span className="text-xs font-normal text-slate-400">căn</span>
              </div>
              <div className="mt-1 text-[10px] sm:text-[11px] text-purple-700 font-medium truncate">
                Cấp phép thi công sửa chữa
              </div>
            </div>
          </div>

          {/* Buildings Overview Grid Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3.5 sm:gap-4">
            {blocks.map((block) => {
              const occupiedCount = Number(block.occupied_apartments ?? 0);
              const rentedCount = Number(block.rented_apartments ?? 0);
              const vacantCount = Number(block.vacant_apartments ?? 0);
              const maintenanceCount = Number(block.maintenance_apartments ?? 0);
              const totalApts =
                Number(block.total_apartments) > 0
                  ? Number(block.total_apartments)
                  : Number(block.apartments_count) > 0
                  ? Number(block.apartments_count)
                  : occupiedCount + rentedCount + vacantCount + maintenanceCount;
              const livingCount = occupiedCount + rentedCount;
              const occupancy = totalApts > 0 ? ((livingCount / totalApts) * 100).toFixed(1) : '0.0';
              const isSelected = selectedBlockId === block.id;

              return (
                <div
                  key={block.id}
                  className={`sass-db-block-card flex flex-col justify-between transition-all duration-200 ${
                    isSelected ? 'is-active ring-2 ring-sky-500' : ''
                  }`}
                >
                  <div>
                    {/* Header: Badge Code on Left, Operating Status on Right */}
                    <div className="flex items-center justify-between gap-2 pb-2 border-b border-slate-100">
                      <span className="block-badge-code">
                        <Building2 className="w-3.5 h-3.5 flex-shrink-0" />
                        <span>{block.block_code}</span>
                      </span>
                      <span className="inline-flex items-center gap-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 flex-shrink-0">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        Vận hành
                      </span>
                    </div>

                    {/* Building Title & Subtitle */}
                    <div className="mt-2.5">
                      <h3
                        className="font-bold text-slate-900 text-sm leading-snug line-clamp-1"
                        title={block.block_name}
                      >
                        {block.block_name}
                      </h3>
                      <p className="text-[11px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                        <span>{block.total_floors} Tầng nổi</span>
                        <span className="text-slate-300">•</span>
                        <span>{block.total_basements} Tầng hầm</span>
                      </p>
                    </div>

                    {/* Occupancy Progress Bar */}
                    <div className="mt-3">
                      <div className="flex justify-between items-center text-xs text-slate-600 font-medium mb-1.5">
                        <span>
                          Cư trú: <strong className="text-slate-900 font-bold">{livingCount}</strong>/{totalApts}
                        </span>
                        <span className="font-bold text-sky-700 font-mono text-xs">{occupancy}%</span>
                      </div>
                      <div className="occupancy-meter">
                        <div
                          className="occupancy-meter-fill"
                          style={{ width: `${Math.min(100, Math.max(0, Number(occupancy)))}%` }}
                        />
                      </div>
                    </div>

                    {/* Status Badges 4-Grid Breakdown */}
                    <div className="mt-3 grid grid-cols-4 gap-1.5 text-center">
                      <div className="bg-emerald-50/80 border border-emerald-100/90 rounded-lg py-1 px-1">
                        <div className="text-[10px] text-emerald-600 font-medium">Ở</div>
                        <div className="text-xs font-bold text-emerald-700 font-mono">{occupiedCount}</div>
                      </div>
                      <div className="bg-sky-50/80 border border-sky-100/90 rounded-lg py-1 px-1">
                        <div className="text-[10px] text-sky-600 font-medium">Thuê</div>
                        <div className="text-xs font-bold text-sky-700 font-mono">{rentedCount}</div>
                      </div>
                      <div className="bg-amber-50/80 border border-amber-100/90 rounded-lg py-1 px-1">
                        <div className="text-[10px] text-amber-600 font-medium">Trống</div>
                        <div className="text-xs font-bold text-amber-700 font-mono">{vacantCount}</div>
                      </div>
                      <div className="bg-purple-50/80 border border-purple-100/90 rounded-lg py-1 px-1">
                        <div className="text-[10px] text-purple-600 font-medium">Sửa</div>
                        <div className="text-xs font-bold text-purple-700 font-mono">{maintenanceCount}</div>
                      </div>
                    </div>
                  </div>

                  {/* Action Link to View Apartments of this block */}
                  <div className="mt-3.5 pt-2.5 border-t border-slate-100">
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedBlockId(block.id);
                        setSelectedFloorId('all');
                        setCurrentPage(1);
                        handleSectionChange('apartments');
                      }}
                      className="w-full flex items-center justify-between py-1.5 px-2.5 rounded-xl text-xs font-bold bg-sky-50 text-sky-700 hover:bg-sky-600 hover:text-white transition-all duration-150 cursor-pointer group"
                    >
                      <span className="flex items-center gap-1.5">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>Xem căn hộ tòa này</span>
                      </span>
                      <ChevronRight className="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" />
                    </button>
                  </div>
                </div>
              );
            })}
          </div>

          {/* Detailed Table of Blocks */}
          <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div className="p-4 border-b border-slate-100 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-slate-50/70">
              <div>
                <h3 className="font-bold text-slate-900 text-sm flex items-center gap-2">
                  <Building2 className="w-4 h-4 text-sky-600" />
                  <span>Danh sách chi tiết Khối / Tòa nhà</span>
                </h3>
                <p className="text-xs text-slate-500 mt-0.5">
                  Thống kê quy mô tầng nổi, tầng hầm, tỷ lệ cư trú và trưởng ban quản lý từng khối
                </p>
              </div>

              <span className="text-xs font-semibold px-2.5 py-1 rounded-xl bg-white border border-slate-200 text-slate-700 font-mono shadow-2xs">
                Tổng cộng: {blocks.length} Khối tòa nhà
              </span>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-slate-200/80 bg-slate-100/50 text-slate-500 font-bold uppercase text-[10px] tracking-wider font-mono">
                    <th className="py-3 px-4">Mã Khối</th>
                    <th className="py-3 px-4">Tên Khối / Tòa nhà</th>
                    <th className="py-3 px-4">Quy mô tầng</th>
                    <th className="py-3 px-4">Tổng căn</th>
                    <th className="py-3 px-4">Tỷ lệ lấp đầy</th>
                    <th className="py-3 px-4">Phân bổ trạng thái</th>
                    <th className="py-3 px-4">Trưởng BQL / Hotline</th>
                    <th className="py-3 px-4 text-right">Thao tác</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {blocks.map((block) => {
                    const occupiedCount = Number(block.occupied_apartments ?? 0);
                    const rentedCount = Number(block.rented_apartments ?? 0);
                    const vacantCount = Number(block.vacant_apartments ?? 0);
                    const maintenanceCount = Number(block.maintenance_apartments ?? 0);
                    const totalApts =
                      Number(block.total_apartments) > 0
                        ? Number(block.total_apartments)
                        : Number(block.apartments_count) > 0
                        ? Number(block.apartments_count)
                        : occupiedCount + rentedCount + vacantCount + maintenanceCount;
                    const livingCount = occupiedCount + rentedCount;
                    const occupancy = totalApts > 0 ? ((livingCount / totalApts) * 100).toFixed(1) : '0.0';

                    return (
                      <tr key={block.id} className="hover:bg-slate-50/80 transition-colors">
                        <td className="py-3 px-4 font-mono font-bold text-sky-700">
                          <span className="px-2 py-0.5 rounded-lg bg-sky-50 border border-sky-100">
                            {block.block_code}
                          </span>
                        </td>
                        <td className="py-3 px-4">
                          <div className="font-bold text-slate-900">{block.block_name}</div>
                          <div className="text-[11px] text-slate-400 mt-0.5">Tiêu chuẩn cao ốc thông minh</div>
                        </td>
                        <td className="py-3 px-4 text-slate-600">
                          <span className="font-semibold text-slate-800">{block.total_floors}</span> nổi ·{' '}
                          <span className="font-semibold text-slate-800">{block.total_basements}</span> hầm
                        </td>
                        <td className="py-3 px-4 font-mono font-bold text-slate-900">
                          {totalApts} căn
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-2">
                            <span className="font-bold text-slate-800 font-mono">{occupancy}%</span>
                            <div className="w-16 h-2 rounded-full bg-slate-100 overflow-hidden">
                              <div
                                className="h-full bg-sky-600 rounded-full"
                                style={{ width: `${Math.min(100, Math.max(0, Number(occupancy)))}%` }}
                              />
                            </div>
                          </div>
                          <div className="text-[10px] text-slate-400 mt-0.5 font-mono">{livingCount}/{totalApts} đang ở</div>
                        </td>
                        <td className="py-3 px-4">
                          <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 font-bold text-[10px] border border-emerald-100">
                              {occupiedCount} ở
                            </span>
                            <span className="px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 font-bold text-[10px] border border-sky-100">
                              {rentedCount} thuê
                            </span>
                            <span className="px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 font-bold text-[10px] border border-amber-100">
                              {vacantCount} trống
                            </span>
                            <span className="px-1.5 py-0.5 rounded bg-purple-50 text-purple-700 font-bold text-[10px] border border-purple-100">
                              {maintenanceCount} sửa
                            </span>
                          </div>
                        </td>
                        <td className="py-3 px-4 text-slate-600">
                          <div className="font-medium text-slate-800">{block.building_manager_name || 'Ban Quản Lý Khối'}</div>
                          <div className="text-[11px] text-slate-500 font-mono">{block.building_manager_phone || '0901.888.999'}</div>
                        </td>
                        <td className="py-3 px-4 text-right">
                          <div className="inline-flex items-center gap-1.5">
                            <button
                              type="button"
                              onClick={() => {
                                setSelectedBlockId(block.id);
                                setSelectedFloorId('all');
                                setCurrentPage(1);
                                handleSectionChange('apartments');
                              }}
                              className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-sky-50 text-sky-700 hover:bg-sky-600 hover:text-white transition-all cursor-pointer shadow-2xs"
                              title="Chuyển đến danh sách căn hộ của khối này"
                            >
                              <span>Xem căn hộ</span>
                              <ChevronRight className="w-3.5 h-3.5" />
                            </button>
                            <button
                              type="button"
                              onClick={() => {
                                setFormFloorBlockId(block.id);
                                setShowFloorModal(true);
                              }}
                              className="p-1 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-all cursor-pointer"
                              title="Thêm tầng cho khối này"
                            >
                              <Plus className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ============================================================== */}
      {/* SECTION 2: QUẢN LÝ TẦNG & CĂN HỘ (XUANHOA/2)                  */}
      {/* ============================================================== */}
      {currentSection === 'apartments' && (
        <div className="space-y-4 animate-in fade-in duration-200">
          {/* Quick Block Filter Pills Strip */}
          <div className="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none">
            <span className="text-xs font-bold text-slate-500 whitespace-nowrap">Khối đang chọn:</span>
            <button
              type="button"
              onClick={() => {
                setSelectedBlockId('all');
                setSelectedFloorId('all');
                setCurrentPage(1);
              }}
              className={`px-3 py-1 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap ${
                selectedBlockId === 'all'
                  ? 'bg-neutral-900 text-white shadow-xs'
                  : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200'
              }`}
            >
              Tất cả khối ({blocks.length})
            </button>
            {blocks.map((b) => (
              <button
                key={b.id}
                type="button"
                onClick={() => {
                  setSelectedBlockId(b.id);
                  setSelectedFloorId('all');
                  setCurrentPage(1);
                }}
                className={`px-3 py-1 rounded-xl text-xs font-bold transition-all cursor-pointer whitespace-nowrap ${
                  selectedBlockId === b.id
                    ? 'bg-sky-600 text-white shadow-xs'
                    : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200'
                }`}
              >
                {b.block_code} · {b.block_name}
              </button>
            ))}
          </div>

      {/* Main Apartment Table & Hierarchy Control Section */}
      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        {/* Hierarchy Filter Toolbar */}
        <div className="p-3 sm:p-4 border-b border-slate-100 flex flex-col lg:flex-row gap-3 items-stretch lg:items-center justify-between bg-slate-50/70 backdrop-blur-xs">
          <div className="flex flex-col sm:flex-row flex-wrap items-stretch sm:items-center gap-2.5 w-full lg:w-auto">
            {/* Search Input */}
            <div className="relative w-full sm:w-60 lg:w-64">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
                placeholder="Tìm mã căn (vd: S1.2501, A-02)..."
                className="w-full pl-9 pr-8 py-2 sm:py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => {
                    setSearchQuery('');
                    setCurrentPage(1);
                  }}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            {/* Filter Dropdowns Grid on Mobile */}
            <div className="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2">
              {/* Block Filter Dropdown */}
              <div className="flex items-center gap-1 text-xs">
                <span className="text-slate-400 font-medium hidden sm:inline">Khối:</span>
                <select
                  value={selectedBlockId}
                  onChange={(e) => {
                    setSelectedBlockId(e.target.value);
                    setSelectedFloorId('all');
                    setCurrentPage(1);
                  }}
                  className="w-full sm:w-auto px-2.5 py-2 sm:py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 text-slate-700 font-medium cursor-pointer"
                >
                  <option value="all">Tất cả Khối ({blocks.length})</option>
                  {blocks.map((b) => (
                    <option key={b.id} value={b.id}>
                      Khối {b.block_code} - {b.block_name}
                    </option>
                  ))}
                </select>
              </div>

              {/* Floor Filter Dropdown (1-N Relationship) */}
              <div className="flex items-center gap-1 text-xs">
                <span className="text-slate-400 font-medium hidden sm:inline">Tầng:</span>
                <select
                  value={selectedFloorId}
                  onChange={(e) => {
                    setSelectedFloorId(e.target.value);
                    setCurrentPage(1);
                  }}
                  disabled={selectedBlockId === 'all'}
                  className={`w-full sm:w-auto px-2.5 py-2 sm:py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 text-slate-700 font-medium cursor-pointer ${
                    selectedBlockId === 'all' ? 'opacity-50 cursor-not-allowed' : ''
                  }`}
                >
                  <option value="all">Tất cả Tầng ({floors.length})</option>
                  {floors.map((f) => (
                    <option key={f.id} value={f.id}>
                      {f.floor_name} (Số {f.floor_number})
                    </option>
                  ))}
                </select>
              </div>

              {/* Status Filter Dropdown */}
              <div className="flex items-center gap-1 text-xs col-span-2 sm:col-span-1">
                <span className="text-slate-400 font-medium hidden sm:inline">Trạng thái:</span>
                <select
                  value={statusFilter}
                  onChange={(e) => {
                    setStatusFilter(e.target.value);
                    setCurrentPage(1);
                  }}
                  className="w-full sm:w-auto px-2.5 py-2 sm:py-1.5 text-xs bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-500/20 text-slate-700 font-medium cursor-pointer"
                >
                  <option value="all">Mọi trạng thái</option>
                  <option value="OCCUPIED">Đã bán / Cư trú</option>
                  <option value="RENTED">Đang thuê</option>
                  <option value="VACANT">Trống</option>
                  <option value="MAINTENANCE">Đang bảo trì</option>
                </select>
              </div>
            </div>
          </div>

          {/* Right Toolbar: View Mode Toggle & Total Count */}
          <div className="flex items-center justify-between sm:justify-end gap-3 text-xs text-slate-500 font-medium pt-2 lg:pt-0 border-t lg:border-t-0 border-slate-100">
            {/* View Mode Toggle Button */}
            <div className="flex items-center p-0.5 bg-slate-200/60 rounded-xl border border-slate-200">
              <button
                type="button"
                onClick={() => setViewMode('table')}
                title="Chế độ xem Bảng chi tiết"
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                  viewMode === 'table'
                    ? 'bg-white text-sky-600 shadow-2xs'
                    : 'text-slate-500 hover:text-slate-800'
                }`}
              >
                <List className="w-3.5 h-3.5" />
                <span>Bảng</span>
              </button>
              <button
                type="button"
                onClick={() => setViewMode('grid')}
                title="Chế độ xem Thẻ ô lưới"
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                  viewMode === 'grid'
                    ? 'bg-white text-sky-600 shadow-2xs'
                    : 'text-slate-500 hover:text-slate-800'
                }`}
              >
                <LayoutGrid className="w-3.5 h-3.5" />
                <span>Lưới</span>
              </button>
            </div>

            <span>
              Tổng số: <strong className="text-neutral-900 font-mono">{totalApartmentsCount}</strong> căn
            </span>
          </div>
        </div>

        {/* Real-time Submitting / Background Sync Bar */}
        {isLoading && (
          <div className="h-0.5 w-full bg-slate-100 overflow-hidden relative">
            <div className="h-full bg-sky-500 animate-pulse w-full"></div>
          </div>
        )}

        {/* ============================================================== */}
        {/* VIEW 1: Grid Cards (Thân thiện màn hình di động & Tablet)       */}
        {/* ============================================================== */}
        {viewMode === 'grid' && (
          <div className="p-3.5 sm:p-5 bg-slate-50/50">
            {isLoading && allApartments.length === 0 ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 sm:gap-4">
                {Array.from({ length: 8 }).map((_, idx) => (
                  <div key={`grid-skel-${idx}`} className="h-44 bg-slate-100/90 rounded-2xl animate-pulse p-4 flex flex-col justify-between">
                    <div>
                      <div className="h-5 bg-slate-200 rounded-md w-20 mb-3"></div>
                      <div className="h-4 bg-slate-200 rounded w-32 mb-2"></div>
                      <div className="h-3 bg-slate-200 rounded w-24"></div>
                    </div>
                    <div className="h-7 bg-slate-200 rounded-lg w-full"></div>
                  </div>
                ))}
              </div>
            ) : displayedApartments.length === 0 ? (
              <div className="py-16 text-center text-slate-400">
                <Home className="w-10 h-10 text-slate-300 mx-auto mb-2" />
                <p className="text-xs text-slate-600 font-medium">Không tìm thấy căn hộ nào phù hợp với bộ lọc.</p>
                <button
                  onClick={() => setShowBatchModal(true)}
                  className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold text-sky-600 bg-sky-50 hover:bg-sky-100 cursor-pointer"
                >
                  <Sparkles className="w-3.5 h-3.5" />
                  <span>Khởi tạo ngay danh sách căn hộ</span>
                </button>
              </div>
            ) : (
              <div className={`grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 sm:gap-4 transition-opacity duration-150 ${isLoading && allApartments.length === 0 ? 'opacity-50 pointer-events-none' : 'opacity-100'}`}>
                {displayedApartments.map((apt) => (
                  <div key={apt.id} className="sass-db-apartment-card flex flex-col justify-between">
                    <div>
                      {/* Top Bar: Room Number & Room Type */}
                      <div className="flex items-start justify-between gap-2 pb-2.5 border-b border-slate-100">
                        <div>
                          <div className="font-mono text-base sm:text-lg font-black text-neutral-900 tracking-tight">
                            {apt.apartment_number}
                          </div>
                          <div className="text-[11px] font-medium text-slate-500 mt-0.5">
                            Khối {apt.block?.block_code || '---'} · {apt.floor?.floor_name || `Tầng ${apt.floor?.floor_number || '---'}`}
                          </div>
                        </div>
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 border border-slate-200 shrink-0">
                          {getRoomTypeLabel(apt.room_type)}
                        </span>
                      </div>

                      {/* Specs Row */}
                      <div className="grid grid-cols-2 gap-2 my-2.5 text-xs text-slate-600">
                        <div className="p-2 rounded-xl bg-slate-50 border border-slate-100">
                          <span className="text-[10px] text-slate-400 block font-medium">Diện tích tim tường</span>
                          <span className="font-mono font-bold text-slate-800 text-xs">
                            {Number(apt.gross_floor_area_sqm).toFixed(1)} m²
                          </span>
                        </div>
                        <div className="p-2 rounded-xl bg-slate-50 border border-slate-100">
                          <span className="text-[10px] text-slate-400 block font-medium">Cấu trúc phòng</span>
                          <span className="font-bold text-slate-800 text-xs">
                            {apt.bedroom_count} PN · {apt.bathroom_count} WC
                          </span>
                        </div>
                      </div>

                      {/* Resident Info */}
                      <div className="text-[11px] text-slate-500 py-1.5 flex items-center justify-between">
                        <span>Chủ hộ / Cư trú:</span>
                        <span className="font-semibold text-slate-800 truncate max-w-[140px]">
                          {apt.primary_owner ? apt.primary_owner.full_name : <em className="text-slate-400 font-normal">Chưa đăng ký</em>}
                        </span>
                      </div>

                      {/* Management Fee */}
                      <div className="text-[11px] text-slate-500 pb-2 flex items-center justify-between border-b border-slate-100">
                        <span>Phí quản lý:</span>
                        <span className="font-mono font-bold text-sky-700">
                          {apt.monthly_management_fee_fixed
                            ? `${Number(apt.monthly_management_fee_fixed).toLocaleString('vi-VN')} đ`
                            : 'Mặc định'}
                        </span>
                      </div>
                    </div>

                    {/* Bottom Status Bar & Actions */}
                    <div className="mt-3 pt-1 flex items-center justify-between gap-2">
                      <ApartmentStatusDropdown
                        currentStatus={apt.status}
                        className="flex-1"
                        onChange={(newStatus) => handleFastStatusChange(apt, newStatus)}
                      />

                      <div className="flex items-center gap-1 shrink-0">
                        <button
                          type="button"
                          onClick={() => handleOpenEditModal(apt)}
                          title="Chỉnh sửa căn hộ"
                          className="p-1.5 rounded-lg text-slate-500 hover:text-sky-600 hover:bg-sky-50 transition-colors cursor-pointer border border-slate-200"
                        >
                          <Edit className="w-3.5 h-3.5" />
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            setApartmentToDelete(apt);
                            setShowDeleteModal(true);
                          }}
                          title="Xóa căn hộ"
                          className="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer border border-slate-200"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* ============================================================== */}
        {/* VIEW 2: Table of Apartments (Chế độ xem Bảng)                   */}
        {/* ============================================================== */}
        {viewMode === 'table' && (
          <div className="table-scroll-container overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 border-b border-slate-100 text-slate-500 uppercase tracking-wider font-semibold">
                <tr>
                  <th className="py-3 px-3 sm:px-4">Mã Căn hộ</th>
                  <th className="py-3 px-3 sm:px-4">Khối / Tầng</th>
                  <th className="py-3 px-3 sm:px-4">Loại Căn</th>
                  <th className="py-3 px-3 sm:px-4">Diện tích (m²)</th>
                  <th className="py-3 px-3 sm:px-4">Chủ hộ / Cư dân</th>
                  <th className="py-3 px-3 sm:px-4">Trạng thái căn hộ</th>
                  <th className="py-3 px-3 sm:px-4 text-right">Phí QL Cố định</th>
                  <th className="py-3 px-3 sm:px-4 text-center">Thao tác</th>
                </tr>
              </thead>
              <tbody className={`divide-y divide-slate-100 text-slate-700 transition-opacity duration-150 ${isLoading && allApartments.length === 0 ? 'opacity-50 pointer-events-none' : 'opacity-100'}`}>
                {isLoading && allApartments.length === 0 ? (
                  Array.from({ length: 7 }).map((_, idx) => (
                    <tr key={`skel-row-${idx}`} className="animate-pulse">
                      <td className="py-3 px-3 sm:px-4"><div className="h-4 bg-slate-200 rounded w-16"></div></td>
                      <td className="py-3 px-3 sm:px-4"><div className="h-4 bg-slate-200 rounded w-24"></div></td>
                      <td className="py-3 px-3 sm:px-4"><div className="h-4 bg-slate-100 rounded w-20"></div></td>
                      <td className="py-3 px-3 sm:px-4"><div className="h-4 bg-slate-100 rounded w-16"></div></td>
                      <td className="py-3 px-3 sm:px-4"><div className="h-4 bg-slate-100 rounded w-28"></div></td>
                      <td className="py-3 px-3 sm:px-4"><div className="h-6 bg-slate-200 rounded-lg w-24"></div></td>
                      <td className="py-3 px-3 sm:px-4 text-right"><div className="h-4 bg-slate-100 rounded w-16 ml-auto"></div></td>
                      <td className="py-3 px-3 sm:px-4 text-center"><div className="h-4 bg-slate-200 rounded w-12 mx-auto"></div></td>
                    </tr>
                  ))
                ) : displayedApartments.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-slate-400">
                      <div className="flex flex-col items-center justify-center gap-2">
                        <Home className="w-8 h-8 text-slate-300" />
                        <span>Không tìm thấy căn hộ nào phù hợp với bộ lọc hiện tại.</span>
                        <button
                          onClick={() => setShowBatchModal(true)}
                          className="mt-2 text-xs text-sky-600 font-bold hover:underline cursor-pointer"
                        >
                          + Khởi tạo ngay danh sách căn hộ cho tầng này
                        </button>
                      </div>
                    </td>
                  </tr>
                ) : (
                  displayedApartments.map((apt, index) => (
                    <tr key={apt.id} className="hover:bg-slate-50/80 transition-colors">
                      {/* Apartment Number */}
                      <td className="py-3 px-3 sm:px-4 font-bold text-neutral-900 font-mono text-sm whitespace-nowrap">
                        {apt.apartment_number}
                      </td>

                      {/* Block / Floor */}
                      <td className="py-3 px-3 sm:px-4 whitespace-nowrap">
                        <span className="font-semibold text-slate-800">
                          {apt.block?.block_code || '---'}
                        </span>
                        <span className="text-slate-400 mx-1">·</span>
                        <span className="text-slate-600">
                          {apt.floor?.floor_name || `Tầng ${apt.floor?.floor_number || '---'}`}
                        </span>
                      </td>

                      {/* Room Type */}
                      <td className="py-3 px-3 sm:px-4 whitespace-nowrap">
                        <div className="font-medium text-slate-700">{getRoomTypeLabel(apt.room_type)}</div>
                        <div className="text-[11px] text-slate-400">
                          {apt.bedroom_count} PN · {apt.bathroom_count} WC
                        </div>
                      </td>

                      {/* Area */}
                      <td className="py-3 px-3 sm:px-4 font-mono whitespace-nowrap">
                        <div className="font-semibold text-slate-800">{Number(apt.gross_floor_area_sqm).toFixed(1)} m²</div>
                        {apt.net_usable_area_sqm && (
                          <div className="text-[10px] text-slate-400">
                            Thông thủy: {Number(apt.net_usable_area_sqm).toFixed(1)} m²
                          </div>
                        )}
                      </td>

                      {/* Owner / Resident */}
                      <td className="py-3 px-3 sm:px-4 whitespace-nowrap">
                        {apt.primary_owner ? (
                          <div>
                            <div className="font-semibold text-slate-900">{apt.primary_owner.full_name}</div>
                            <div className="text-[11px] font-mono text-slate-500">
                              {apt.primary_owner.phone_number || '---'}
                            </div>
                          </div>
                        ) : (
                          <span className="text-[11px] text-slate-400 italic">Chưa đăng ký</span>
                        )}
                      </td>

                      {/* Fast Status Change Dropdown */}
                      <td className="py-3 px-3 sm:px-4 whitespace-nowrap">
                        <ApartmentStatusDropdown
                          currentStatus={apt.status}
                          dropUp={index >= Math.max(3, displayedApartments.length - 4)}
                          onChange={(newStatus) => handleFastStatusChange(apt, newStatus)}
                        />
                      </td>

                      {/* Management Fee */}
                      <td className="py-3 px-3 sm:px-4 text-right font-mono font-medium text-slate-700 whitespace-nowrap">
                        {apt.monthly_management_fee_fixed
                          ? `${Number(apt.monthly_management_fee_fixed).toLocaleString('vi-VN')} đ`
                          : 'Mặc định'}
                      </td>

                      {/* Actions */}
                      <td className="py-3 px-3 sm:px-4 text-center whitespace-nowrap">
                        <div className="flex items-center justify-center gap-1.5">
                          <button
                            type="button"
                            onClick={() => handleOpenEditModal(apt)}
                            title="Chỉnh sửa căn hộ"
                            className="p-1.5 rounded-lg text-slate-400 hover:text-sky-600 hover:bg-sky-50 transition-colors cursor-pointer"
                          >
                            <Edit className="w-4 h-4" />
                          </button>
                          <button
                            type="button"
                            onClick={() => {
                              setApartmentToDelete(apt);
                              setShowDeleteModal(true);
                            }}
                            title="Xóa căn hộ"
                            className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination Bar (0ms Instant Response with Page Buttons) */}
        <div className="p-3 sm:p-3.5 border-t border-slate-100 flex flex-col sm:flex-row gap-2.5 items-center justify-between bg-slate-50/50 text-xs">
          <div className="text-slate-500 text-center sm:text-left">
            Trang <strong className="text-neutral-900">{currentPage}</strong> / <strong className="text-neutral-900">{totalPages}</strong> (Hiển thị {displayedApartments.length} trên tổng {totalApartmentsCount} căn)
          </div>

          <div className="flex items-center gap-1 sm:gap-1.5 flex-wrap justify-center">
            {/* Prev button */}
            <button
              type="button"
              disabled={currentPage <= 1}
              onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              className={`px-2.5 py-1.5 rounded-lg border flex items-center gap-1 font-semibold text-xs transition-all ${
                currentPage <= 1
                  ? 'bg-slate-100 text-slate-300 border-slate-200 cursor-not-allowed'
                  : 'bg-white text-slate-700 hover:bg-slate-100 border-slate-200 cursor-pointer shadow-2xs active:scale-95'
              }`}
            >
              <ChevronLeft className="w-3.5 h-3.5" />
              <span className="hidden sm:inline">Trước</span>
            </button>

            {/* Numeric Page Buttons */}
            {(() => {
              const pages: (number | string)[] = [];
              if (totalPages <= 7) {
                for (let i = 1; i <= totalPages; i++) pages.push(i);
              } else if (currentPage <= 3) {
                pages.push(1, 2, 3, 4, '...', totalPages);
              } else if (currentPage >= totalPages - 2) {
                pages.push(1, '...', totalPages - 3, totalPages - 2, totalPages - 1, totalPages);
              } else {
                pages.push(1, '...', currentPage - 1, currentPage, currentPage + 1, '...', totalPages);
              }

              return pages.map((page, idx) => {
                if (typeof page === 'string') {
                  return (
                    <span key={`dots-${idx}`} className="px-2 py-1 text-slate-400 select-none">
                      ...
                    </span>
                  );
                }
                const isActive = page === currentPage;
                return (
                  <button
                    key={`page-${page}`}
                    type="button"
                    onClick={() => setCurrentPage(page)}
                    className={`min-w-[32px] h-8 px-2 rounded-lg border text-xs font-bold transition-all cursor-pointer ${
                      isActive
                        ? 'bg-sky-600 text-white border-sky-600 shadow-xs'
                        : 'bg-white text-slate-700 hover:bg-slate-100 border-slate-200'
                    }`}
                  >
                    {page}
                  </button>
                );
              });
            })()}

            {/* Next button */}
            <button
              type="button"
              disabled={currentPage >= totalPages}
              onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
              className={`px-2.5 py-1.5 rounded-lg border flex items-center gap-1 font-semibold text-xs transition-all ${
                currentPage >= totalPages
                  ? 'bg-slate-100 text-slate-300 border-slate-200 cursor-not-allowed'
                  : 'bg-white text-slate-700 hover:bg-slate-100 border-slate-200 cursor-pointer shadow-2xs active:scale-95'
              }`}
            >
              <span className="hidden sm:inline">Sau</span>
              <ChevronRight className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </div>
        </div>
      )}

      {/* ============================================================== */}
      {/* MODAL 1: Khởi tạo danh sách căn hộ theo tầng (Batch Generate)   */}
      {/* ============================================================== */}
      {showBatchModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl border border-slate-200 relative max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center">
                  <Sparkles className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="font-bold text-slate-900 text-base">Khởi tạo căn hộ theo tầng hàng loạt</h3>
                  <p className="text-xs text-slate-500">Tự động sinh mã căn hộ và gán trạng thái hàng loạt cho tầng</p>
                </div>
              </div>
              <button
                onClick={() => setShowBatchModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer p-1 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleBatchGenerate} className="mt-4 space-y-4 text-xs">
              {/* Select Block & Floor */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Khối / Tòa tháp *</label>
                  <select
                    value={batchBlockId}
                    onChange={(e) => setBatchBlockId(e.target.value)}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 font-medium"
                  >
                    {blocks.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.block_code} - {b.block_name}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Tầng cần khởi tạo *</label>
                  <select
                    value={batchFloorId}
                    onChange={(e) => handleBatchFloorChange(e.target.value)}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 font-medium"
                  >
                    {batchFloorsList.map((f) => (
                      <option key={f.id} value={f.id}>
                        {f.floor_name} (Tầng {f.floor_number})
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Prefix & Numbering Config */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Tiền tố mã căn *</label>
                  <input
                    type="text"
                    value={batchPrefix}
                    onChange={(e) => setBatchPrefix(e.target.value)}
                    placeholder="vd: S1-05 hoặc A-12"
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono font-bold text-sky-700"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Số bắt đầu *</label>
                  <input
                    type="number"
                    min={1}
                    max={99}
                    value={batchStartNum}
                    onChange={(e) => setBatchStartNum(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Số lượng căn *</label>
                  <input
                    type="number"
                    min={1}
                    max={100}
                    value={batchCount}
                    onChange={(e) => setBatchCount(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono font-bold text-neutral-900"
                  />
                </div>
              </div>

              {/* Preview of Generated Codes */}
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-100">
                <span className="font-semibold text-slate-500 text-[11px]">Xem trước định dạng mã căn hộ sinh ra:</span>
                <div className="flex flex-wrap gap-1.5 mt-1.5 font-mono text-xs">
                  {Array.from({ length: Math.min(batchCount, 6) }).map((_, i) => (
                    <span key={i} className="sass-db-batch-preview-chip">
                      {batchPrefix}
                      {String(batchStartNum + i).padStart(2, '0')}
                    </span>
                  ))}
                  {batchCount > 6 && <span className="text-slate-400 self-center">... ({batchCount} căn)</span>}
                </div>
              </div>

              {/* Status & Room Type */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Trạng thái gán ban đầu *</label>
                  <select
                    value={batchStatus}
                    onChange={(e) => setBatchStatus(e.target.value as any)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-semibold text-slate-800"
                  >
                    <option value="VACANT">Trống (Sẵn sàng bán / cho thuê)</option>
                    <option value="OCCUPIED">Đã bán / Cư dân nhận nhà</option>
                    <option value="RENTED">Đang thuê</option>
                    <option value="MAINTENANCE">Đang thi công hoàn thiện</option>
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Loại căn hộ chuẩn</label>
                  <select
                    value={batchRoomType}
                    onChange={(e) => setBatchRoomType(e.target.value)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    <option value="STUDIO">Studio</option>
                    <option value="1_BEDROOM">1 Phòng ngủ</option>
                    <option value="2_BEDROOM">2 Phòng ngủ</option>
                    <option value="3_BEDROOM">3 Phòng ngủ (Góc)</option>
                    <option value="PENTHOUSE">Penthouse</option>
                    <option value="COMMERCIAL">Shophouse TMDV</option>
                  </select>
                </div>
              </div>

              {/* Area Config */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">DT Tim tường (m²) *</label>
                  <input
                    type="number"
                    step="0.1"
                    min="1"
                    value={batchGrossArea}
                    onChange={(e) => setBatchGrossArea(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">DT Thông thủy (m²)</label>
                  <input
                    type="number"
                    step="0.1"
                    min="1"
                    value={batchNetArea}
                    onChange={(e) => setBatchNetArea(Number(e.target.value))}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Tình trạng nội thất</label>
                  <select
                    value={batchFurnished}
                    onChange={(e) => setBatchFurnished(e.target.value as any)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none"
                  >
                    <option value="RAW">Bàn giao thô</option>
                    <option value="BASIC">Cơ bản CĐT</option>
                    <option value="FULL">Đầy đủ nội thất</option>
                  </select>
                </div>
              </div>

              {/* Modal Footer Actions */}
              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowBatchModal(false)}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  disabled={isActionLoading}
                  className="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold shadow-sm transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {isActionLoading && <RefreshCw className="w-4 h-4 animate-spin" />}
                  <span>Tiến hành tạo hàng loạt</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ============================================================== */}
      {/* MODAL 2: Thêm mới hoặc Chỉnh sửa Căn hộ (Single Apartment)     */}
      {/* ============================================================== */}
      {showApartmentModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-black/50 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-lg w-full p-4 sm:p-6 shadow-2xl border border-slate-200 relative max-h-[92vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <h3 className="font-bold text-slate-900 text-base">
                {selectedApartment ? `Chỉnh sửa căn hộ: ${selectedApartment.apartment_number}` : 'Thêm mới căn hộ'}
              </h3>
              <button
                onClick={() => setShowApartmentModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer p-1 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveApartment} className="mt-4 space-y-4 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Khối / Tòa tháp *</label>
                  <select
                    value={formAptBlockId}
                    onChange={(e) => setFormAptBlockId(e.target.value)}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    {blocks.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.block_code} - {b.block_name}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Tầng *</label>
                  <select
                    value={formAptFloorId}
                    onChange={(e) => setFormAptFloorId(e.target.value)}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    {formAptFloorsList.map((f) => (
                      <option key={f.id} value={f.id}>
                        {f.floor_name} (Tầng {f.floor_number})
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Mã căn hộ *</label>
                  <input
                    type="text"
                    value={formAptNumber}
                    onChange={(e) => setFormAptNumber(e.target.value)}
                    placeholder="vd: S1.2501 hoặc A-0402"
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono font-bold text-sky-700"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Trạng thái cư trú *</label>
                  <select
                    value={formAptStatus}
                    onChange={(e) => setFormAptStatus(e.target.value as any)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-semibold text-slate-800"
                  >
                    <option value="VACANT">Trống (Sẵn sàng giao)</option>
                    <option value="OCCUPIED">Đã bán / Cư dân đang ở</option>
                    <option value="RENTED">Đang thuê</option>
                    <option value="MAINTENANCE">Đang bảo trì / Sửa</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Loại căn</label>
                  <select
                    value={formAptRoomType}
                    onChange={(e) => setFormAptRoomType(e.target.value)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    <option value="STUDIO">Studio</option>
                    <option value="1_BEDROOM">1 Phòng ngủ</option>
                    <option value="2_BEDROOM">2 Phòng ngủ</option>
                    <option value="3_BEDROOM">3 Phòng ngủ (Góc)</option>
                    <option value="PENTHOUSE">Penthouse</option>
                    <option value="COMMERCIAL">Shophouse</option>
                  </select>
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Diện tích tim tường (m²) *</label>
                  <input
                    type="number"
                    step="0.1"
                    min="1"
                    value={formAptGrossArea}
                    onChange={(e) => setFormAptGrossArea(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Phòng ngủ</label>
                  <input
                    type="number"
                    min="0"
                    max="10"
                    value={formAptBedrooms}
                    onChange={(e) => setFormAptBedrooms(Number(e.target.value))}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Phòng tắm (WC)</label>
                  <input
                    type="number"
                    min="0"
                    max="10"
                    value={formAptBathrooms}
                    onChange={(e) => setFormAptBathrooms(Number(e.target.value))}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Phí QL Cố định (đ)</label>
                  <input
                    type="number"
                    step="10000"
                    value={formAptFee}
                    onChange={(e) => setFormAptFee(Number(e.target.value))}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowApartmentModal(false)}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  disabled={isActionLoading}
                  className="px-5 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold shadow-sm transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {isActionLoading && <RefreshCw className="w-4 h-4 animate-spin" />}
                  <span>{selectedApartment ? 'Lưu thay đổi' : 'Tạo căn hộ'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ============================================================== */}
      {/* MODAL 3: Thêm Tầng mới cho Tòa nhà                            */}
      {/* ============================================================== */}
      {showFloorModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 relative">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                  <Layers className="w-4 h-4" />
                </div>
                <div>
                  <h3 className="font-bold text-slate-900 text-base">Thêm Tầng mới</h3>
                  <p className="text-xs text-slate-500">Mở rộng cấu trúc tầng cho khối tòa nhà</p>
                </div>
              </div>
              <button
                onClick={() => setShowFloorModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer p-1 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateFloor} className="mt-4 space-y-4 text-xs">
              <div>
                <label className="block font-semibold text-slate-700 mb-1">Khối / Tòa tháp *</label>
                <select
                  value={formFloorBlockId}
                  onChange={(e) => setFormFloorBlockId(e.target.value)}
                  required
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                >
                  {blocks.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.block_code} - {b.block_name}
                    </option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Số tầng (Number) *</label>
                  <input
                    type="number"
                    min={-5}
                    max={100}
                    value={formFloorNumber}
                    onChange={(e) => setFormFloorNumber(Number(e.target.value))}
                    required
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-mono"
                  />
                </div>

                <div>
                  <label className="block font-semibold text-slate-700 mb-1">Phân loại tầng</label>
                  <select
                    value={formFloorType}
                    onChange={(e) => setFormFloorType(e.target.value)}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none font-medium"
                  >
                    <option value="RESIDENTIAL">Căn hộ ở</option>
                    <option value="COMMERCIAL">Thương mại dịch vụ</option>
                    <option value="PARKING">Bãi đỗ xe</option>
                    <option value="TECHNICAL">Kỹ thuật</option>
                    <option value="AMENITY">Tiện ích</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block font-semibold text-slate-700 mb-1">Tên hiển thị tầng</label>
                <input
                  type="text"
                  value={formFloorName}
                  onChange={(e) => setFormFloorName(e.target.value)}
                  placeholder={`Tầng ${formFloorNumber}`}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowFloorModal(false)}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold cursor-pointer"
                >
                  Hủy bỏ
                </button>
                <button
                  type="submit"
                  disabled={isActionLoading}
                  className="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-sm transition-all cursor-pointer flex items-center gap-1.5"
                >
                  {isActionLoading && <RefreshCw className="w-4 h-4 animate-spin" />}
                  <span>Thêm tầng</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* ============================================================== */}
      {/* MODAL 4: Xác nhận xóa Căn hộ (Soft Delete)                     */}
      {/* ============================================================== */}
      {showDeleteModal && apartmentToDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl border border-slate-200 relative text-center">
            <div className="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center mx-auto mb-3">
              <Trash2 className="w-6 h-6" />
            </div>
            <h3 className="font-bold text-slate-900 text-base">Xác nhận xóa căn hộ</h3>
            <p className="text-xs text-slate-500 mt-1">
              Bạn có chắc chắn muốn xóa căn hộ{' '}
              <strong className="text-rose-600 font-mono">{apartmentToDelete.apartment_number}</strong>? Dữ liệu sẽ
              được chuyển vào trạng thái lưu trữ an toàn (Soft Delete).
            </p>

            <div className="flex items-center justify-center gap-2 mt-6">
              <button
                type="button"
                onClick={() => setShowDeleteModal(false)}
                className="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold text-xs cursor-pointer"
              >
                Hủy bỏ
              </button>
              <button
                type="button"
                onClick={handleDeleteApartment}
                disabled={isActionLoading}
                className="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-sm transition-all cursor-pointer flex items-center gap-1"
              >
                {isActionLoading && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                <span>Xác nhận xóa</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default BuildingListManagement;
