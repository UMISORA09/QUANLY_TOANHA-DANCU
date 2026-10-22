import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { createPortal } from 'react-dom';
import {
  CreditCard,
  Plus,
  Search,
  Filter,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
  Lock,
  Unlock,
  Trash2,
  Edit,
  Eye,
  AlertCircle,
  CheckCircle2,
  Clock,
  Radio,
  Building,
  Building2,
  User,
  Calendar,
  X,
  FileText,
  DollarSign,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  Check,
  Loader2,
} from 'lucide-react';
import {
  rfidApi,
  RfidCardItem,
  RfidApartmentOption,
  RfidResidentOption,
  RfidCardDetailResponse,
} from '../../Services/rfidApi';
import { useRealtimeSync, RealtimeEventPayload } from '../../Hooks/useRealtimeSync';

interface ApartmentComboboxProps {
  value: string;
  onChange: (apartmentId: string) => void;
  apartments: RfidApartmentOption[];
  placeholder?: string;
  emptyLabel?: string;
}

const ApartmentCombobox: React.FC<ApartmentComboboxProps> = ({
  value,
  onChange,
  apartments,
  placeholder = '-- Chưa gán căn hộ --',
  emptyLabel,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setIsOpen(false);
      }
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const selectedApartment = useMemo(() => {
    if (!value) return null;
    return apartments.find((a) => a.id === value) || null;
  }, [value, apartments]);

  const filteredApartments = useMemo(() => {
    if (!searchQuery.trim()) return apartments;
    const q = searchQuery.toLowerCase().trim();
    return apartments.filter((a) =>
      a.apartment_number.toLowerCase().includes(q)
    );
  }, [apartments, searchQuery]);

  const handleSelect = (apartmentId: string) => {
    onChange(apartmentId);
    setIsOpen(false);
    setSearchQuery('');
  };

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="truncate pr-2">
          {selectedApartment ? (
            <span className="text-slate-900 font-bold flex items-center gap-1.5">
              <Building2 className="w-3.5 h-3.5 text-indigo-600 shrink-0" />
              <span>Căn hộ {selectedApartment.apartment_number}</span>
            </span>
          ) : (
            <span className="text-slate-500 font-medium flex items-center gap-1.5">
              <Building2 className="w-3.5 h-3.5 text-slate-400 shrink-0" />
              <span className="truncate">{placeholder}</span>
            </span>
          )}
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col min-w-[200px]">
          <div className="p-2 border-b border-slate-100 bg-slate-50/90">
            <div className="relative">
              <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Tìm số căn hộ..."
                className="w-full pl-8 pr-3 py-1.5 text-xs bg-white border border-slate-200 rounded-md focus:outline-none focus:ring-1 focus:ring-indigo-500"
                autoFocus
                onClick={(e) => e.stopPropagation()}
              />
            </div>
          </div>

          <div className="max-h-48 overflow-y-auto custom-scrollbar p-1 text-xs space-y-0.5">
            <button
              type="button"
              onClick={() => handleSelect('')}
              className={`w-full px-2.5 py-1.5 rounded-lg flex items-center justify-between text-left transition ${!value
                ? 'bg-indigo-600 text-white font-bold'
                : 'hover:bg-slate-100 text-slate-600 italic'
                }`}
            >
              <span>{emptyLabel || '-- Chưa gán căn hộ --'}</span>
              {!value && <Check className="w-3.5 h-3.5 text-white shrink-0" />}
            </button>

            {filteredApartments.length === 0 ? (
              <div className="p-3 text-center text-slate-400 text-xs">
                Không tìm thấy căn hộ phù hợp
              </div>
            ) : (
              filteredApartments.map((a) => {
                const isSelected = a.id === value;
                return (
                  <button
                    key={a.id}
                    type="button"
                    onClick={() => handleSelect(a.id)}
                    className={`w-full px-2.5 py-1.5 rounded-lg flex items-center justify-between text-left transition ${isSelected
                      ? 'bg-indigo-600 text-white font-bold'
                      : 'hover:bg-slate-100 text-slate-800'
                      }`}
                  >
                    <div className="flex items-center gap-2 truncate pr-2">
                      <Building2
                        className={`w-3.5 h-3.5 shrink-0 ${isSelected ? 'text-white' : 'text-slate-400'
                          }`}
                      />
                      <span className="truncate">Căn hộ {a.apartment_number}</span>
                    </div>
                    {isSelected && <Check className="w-3.5 h-3.5 text-white shrink-0" />}
                  </button>
                );
              })
            )}
          </div>
        </div>
      )}
    </div>
  );
};

interface ResidentComboboxProps {
  value: string;
  onChange: (userId: string) => void;
  residents: RfidResidentOption[];
  disabled?: boolean;
  disabledPlaceholder?: string;
  placeholder?: string;
}

const ResidentCombobox: React.FC<ResidentComboboxProps> = ({
  value,
  onChange,
  residents,
  disabled = false,
  disabledPlaceholder = '-- Vui lòng chọn căn hộ trước --',
  placeholder = '-- Chưa gán cư dân --',
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setIsOpen(false);
      }
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const selectedResident = useMemo(() => {
    if (!value) return null;
    return residents.find((r) => r.user_id === value) || null;
  }, [value, residents]);

  const filteredResidents = useMemo(() => {
    if (!searchQuery.trim()) return residents;
    const q = searchQuery.toLowerCase().trim();
    return residents.filter((r) =>
      r.full_name.toLowerCase().includes(q) || (r.phone_number && r.phone_number.includes(q))
    );
  }, [residents, searchQuery]);

  const handleSelect = (userId: string) => {
    onChange(userId);
    setIsOpen(false);
    setSearchQuery('');
  };

  if (disabled) {
    return (
      <div className="w-full px-3 py-2 border rounded-lg bg-slate-100 text-slate-400 text-xs font-medium cursor-not-allowed border-slate-200 select-none">
        {disabledPlaceholder}
      </div>
    );
  }

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="truncate pr-2">
          {selectedResident ? (
            <span className="text-slate-900 font-bold flex items-center gap-1.5">
              <User className="w-3.5 h-3.5 text-indigo-600 shrink-0" />
              <span>{selectedResident.full_name} {selectedResident.phone_number ? `(${selectedResident.phone_number})` : ''}</span>
            </span>
          ) : (
            <span className="text-slate-400">{placeholder}</span>
          )}
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col">
          {residents.length >= 4 && (
            <div className="p-2 border-b border-slate-100 bg-slate-50/90">
              <div className="relative">
                <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Tìm tên hoặc SĐT..."
                  className="w-full pl-8 pr-3 py-1.5 text-xs bg-white border border-slate-200 rounded-md focus:outline-none focus:ring-1 focus:ring-indigo-500"
                  autoFocus
                  onClick={(e) => e.stopPropagation()}
                />
              </div>
            </div>
          )}

          <div className="max-h-48 overflow-y-auto custom-scrollbar p-1 text-xs space-y-0.5">
            <button
              type="button"
              onClick={() => handleSelect('')}
              className={`w-full px-2.5 py-1.5 rounded-lg flex items-center justify-between text-left transition ${!value
                ? 'bg-indigo-600 text-white font-bold'
                : 'hover:bg-slate-100 text-slate-600 italic'
                }`}
            >
              <span>-- Chưa gán cư dân --</span>
              {!value && <Check className="w-3.5 h-3.5 text-white shrink-0" />}
            </button>

            {filteredResidents.length === 0 ? (
              <div className="p-3 text-center text-slate-400 text-xs">
                Căn hộ chưa có cư dân đã kích hoạt
              </div>
            ) : (
              filteredResidents.map((r) => {
                const isSelected = r.user_id === value;
                return (
                  <button
                    key={r.user_id}
                    type="button"
                    onClick={() => handleSelect(r.user_id)}
                    className={`w-full px-2.5 py-1.5 rounded-lg flex items-center justify-between text-left transition ${isSelected
                      ? 'bg-indigo-600 text-white font-bold'
                      : 'hover:bg-slate-100 text-slate-800'
                      }`}
                  >
                    <div className="flex items-center gap-2 truncate pr-2">
                      <User
                        className={`w-3.5 h-3.5 shrink-0 ${isSelected ? 'text-white' : 'text-slate-400'
                          }`}
                      />
                      <span className="truncate">
                        {r.full_name} {r.phone_number ? `(${r.phone_number})` : ''}
                      </span>
                    </div>
                    {isSelected && <Check className="w-3.5 h-3.5 text-white shrink-0" />}
                  </button>
                );
              })
            )}
          </div>
        </div>
      )}
    </div>
  );
};

// --- Custom Combobox Components ---

interface CardTypeComboboxProps {
  value: string;
  onChange: (cardType: string) => void;
}

const CARD_TYPE_OPTIONS = [
  {
    id: 'RESIDENT_ALL_ACCESS',
    title: 'Thẻ Toàn Quyền',
    subtitle: 'Thang máy + Bãi gửi xe',
    icon: CreditCard,
    color: 'text-indigo-600',
    badgeBg: 'bg-indigo-50 text-indigo-700',
  },
  {
    id: 'ELEVATOR_ONLY',
    title: 'Thẻ Thang Máy Tòa Nhà',
    subtitle: 'Chỉ ra vào sảnh & thang máy',
    icon: Building2,
    color: 'text-blue-600',
    badgeBg: 'bg-blue-50 text-blue-700',
  },
  {
    id: 'PARKING_ONLY',
    title: 'Thẻ Bãi Gửi Xe',
    subtitle: 'Chỉ quẹt tại trạm bãi xe',
    icon: Radio,
    color: 'text-emerald-600',
    badgeBg: 'bg-emerald-50 text-emerald-700',
  },
  {
    id: 'VISITOR_PASS',
    title: 'Thẻ Khách Thăm Tạm Thời',
    subtitle: 'Thẻ quẹt vãng lai / khách hẹn',
    icon: User,
    color: 'text-amber-600',
    badgeBg: 'bg-amber-50 text-amber-700',
  },
];

const CardTypeCombobox: React.FC<CardTypeComboboxProps> = ({ value, onChange }) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const selectedOption = CARD_TYPE_OPTIONS.find((opt) => opt.id === value) || CARD_TYPE_OPTIONS[0];
  const SelectedIcon = selectedOption.icon;

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="flex items-center gap-1.5 truncate pr-2">
          <SelectedIcon className={`w-3.5 h-3.5 ${selectedOption.color} shrink-0`} />
          <span className="font-bold text-slate-800 truncate">{selectedOption.title}</span>
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col p-1 text-xs space-y-0.5 min-w-[240px]">
          {CARD_TYPE_OPTIONS.map((opt) => {
            const isSelected = opt.id === value;
            const Icon = opt.icon;
            return (
              <button
                key={opt.id}
                type="button"
                onClick={() => {
                  onChange(opt.id);
                  setIsOpen(false);
                }}
                className={`w-full px-2.5 py-2 rounded-lg flex items-center justify-between text-left transition ${isSelected
                  ? 'bg-indigo-600 text-white font-bold'
                  : 'hover:bg-slate-100 text-slate-800'
                  }`}
              >
                <div className="flex items-center gap-2 truncate pr-2">
                  <div
                    className={`w-6 h-6 rounded-md flex items-center justify-center shrink-0 ${isSelected ? 'bg-white/20 text-white' : opt.badgeBg
                      }`}
                  >
                    <Icon className="w-3.5 h-3.5" />
                  </div>
                  <div className="truncate">
                    <div className="truncate leading-tight font-medium">{opt.title}</div>
                    <div
                      className={`text-[10px] truncate ${isSelected ? 'text-indigo-100' : 'text-slate-400'
                        }`}
                    >
                      {opt.subtitle}
                    </div>
                  </div>
                </div>
                {isSelected && <Check className="w-3.5 h-3.5 text-white shrink-0 ml-1" />}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
};

interface CardStatusComboboxProps {
  value: string;
  onChange: (status: string) => void;
  isEditMode?: boolean;
}

const CARD_STATUS_OPTIONS = [
  {
    id: 'ACTIVE',
    title: 'ACTIVE (Kích hoạt ngay)',
    subtitle: 'Cho phép quẹt thẻ bình thường',
    icon: CheckCircle2,
    dotColor: 'bg-emerald-500',
    color: 'text-emerald-600',
    badgeBg: 'bg-emerald-50 text-emerald-700',
    editOnly: false,
  },
  {
    id: 'LOCKED_TEMPORARY',
    title: 'LOCKED (Tạm khóa)',
    subtitle: 'Chặn quyền ra vào tạm thời',
    icon: Lock,
    dotColor: 'bg-amber-500',
    color: 'text-amber-600',
    badgeBg: 'bg-amber-50 text-amber-700',
    editOnly: false,
  },
  {
    id: 'LOST_REPORTED',
    title: 'BÁO MẤT',
    subtitle: 'Chặn thẻ khẩn cấp do thất lạc',
    icon: AlertCircle,
    dotColor: 'bg-rose-500',
    color: 'text-rose-600',
    badgeBg: 'bg-rose-50 text-rose-700',
    editOnly: true,
  },
  {
    id: 'REVOKED',
    title: 'THU HỒI',
    subtitle: 'Hủy quyền thẻ vĩnh viễn',
    icon: Trash2,
    dotColor: 'bg-slate-500',
    color: 'text-slate-600',
    badgeBg: 'bg-slate-100 text-slate-700',
    editOnly: true,
  },
];

const CardStatusCombobox: React.FC<CardStatusComboboxProps> = ({
  value,
  onChange,
  isEditMode = false,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const availableOptions = useMemo(() => {
    return CARD_STATUS_OPTIONS.filter((opt) => isEditMode || !opt.editOnly);
  }, [isEditMode]);

  const selectedOption =
    availableOptions.find((opt) => opt.id === value) || availableOptions[0];
  const SelectedIcon = selectedOption.icon;

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="flex items-center gap-1.5 truncate pr-2">
          <span className={`w-2 h-2 rounded-full ${selectedOption.dotColor} shrink-0`} />
          <SelectedIcon className={`w-3.5 h-3.5 ${selectedOption.color} shrink-0`} />
          <span className="font-bold text-slate-800 truncate">{selectedOption.title}</span>
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col p-1 text-xs space-y-0.5 min-w-[240px]">
          {availableOptions.map((opt) => {
            const isSelected = opt.id === value;
            const Icon = opt.icon;
            return (
              <button
                key={opt.id}
                type="button"
                onClick={() => {
                  onChange(opt.id);
                  setIsOpen(false);
                }}
                className={`w-full px-2.5 py-2 rounded-lg flex items-center justify-between text-left transition ${isSelected
                  ? 'bg-indigo-600 text-white font-bold'
                  : 'hover:bg-slate-100 text-slate-800'
                  }`}
              >
                <div className="flex items-center gap-2 truncate pr-2">
                  <div
                    className={`w-6 h-6 rounded-md flex items-center justify-center shrink-0 ${isSelected ? 'bg-white/20 text-white' : opt.badgeBg
                      }`}
                  >
                    <Icon className="w-3.5 h-3.5" />
                  </div>
                  <div className="truncate">
                    <div className="flex items-center gap-1.5 truncate leading-tight font-medium">
                      <span
                        className={`w-1.5 h-1.5 rounded-full ${isSelected ? 'bg-white' : opt.dotColor
                          } shrink-0`}
                      />
                      <span>{opt.title}</span>
                    </div>
                    <div
                      className={`text-[10px] truncate ${isSelected ? 'text-indigo-100' : 'text-slate-400'
                        }`}
                    >
                      {opt.subtitle}
                    </div>
                  </div>
                </div>
                {isSelected && <Check className="w-3.5 h-3.5 text-white shrink-0 ml-1" />}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
};

interface IssuedDateComboboxProps {
  value: string;
  onChange: (date: string) => void;
}

const IssuedDateCombobox: React.FC<IssuedDateComboboxProps> = ({ value, onChange }) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const todayStr = useMemo(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }, []);

  const displayDate = useMemo(() => {
    if (!value) return '-- Chọn ngày cấp --';
    const [y, m, d] = value.split('-');
    if (y && m && d) return `${d}/${m}/${y}`;
    return value;
  }, [value]);

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="flex items-center gap-1.5 truncate pr-2">
          <Calendar className="w-3.5 h-3.5 text-indigo-600 shrink-0" />
          <span className="font-bold text-slate-800 truncate">{displayDate}</span>
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col p-2 text-xs space-y-2 min-w-[220px]">
          <div className="text-[11px] font-bold text-slate-500 px-1 uppercase tracking-wider">
            Chọn nhanh ngày cấp
          </div>
          <div className="grid grid-cols-2 gap-1.5">
            <button
              type="button"
              onClick={() => {
                onChange(todayStr);
                setIsOpen(false);
              }}
              className={`px-2.5 py-1.5 rounded-lg text-left transition text-xs font-semibold flex items-center justify-between ${value === todayStr
                ? 'bg-indigo-600 text-white'
                : 'bg-slate-50 hover:bg-slate-100 text-slate-700'
                }`}
            >
              <span>Hôm nay</span>
              {value === todayStr && <Check className="w-3.5 h-3.5 text-white" />}
            </button>
            <button
              type="button"
              onClick={() => {
                const now = new Date();
                const firstDay = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;
                onChange(firstDay);
                setIsOpen(false);
              }}
              className="px-2.5 py-1.5 rounded-lg text-left transition text-xs font-semibold bg-slate-50 hover:bg-slate-100 text-slate-700"
            >
              <span>Đầu tháng</span>
            </button>
          </div>

          <div className="pt-2 border-t border-slate-100">
            <label className="block text-[10px] font-semibold text-slate-500 mb-1">
              Chọn ngày cụ thể:
            </label>
            <input
              type="date"
              value={value}
              onChange={(e) => onChange(e.target.value)}
              className="w-full px-2.5 py-1.5 text-xs rounded-lg border border-slate-200 bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>
      )}
    </div>
  );
};

interface ExpiryDateComboboxProps {
  value: string;
  onChange: (date: string) => void;
  issuedDate?: string;
}

const ExpiryDateCombobox: React.FC<ExpiryDateComboboxProps> = ({
  value,
  onChange,
  issuedDate,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const displayDate = useMemo(() => {
    if (!value) return '-- Chọn hạn sử dụng --';
    if (value.startsWith('2099') || value.startsWith('2050')) return 'Vô thời hạn';
    const [y, m, d] = value.split('-');
    if (y && m && d) return `${d}/${m}/${y}`;
    return value;
  }, [value]);

  const calcYears = (years: number) => {
    const base = issuedDate ? new Date(issuedDate) : new Date();
    if (isNaN(base.getTime())) return;
    base.setFullYear(base.getFullYear() + years);
    const y = base.getFullYear();
    const m = String(base.getMonth() + 1).padStart(2, '0');
    const d = String(base.getDate()).padStart(2, '0');
    onChange(`${y}-${m}-${d}`);
    setIsOpen(false);
  };

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="flex items-center gap-1.5 truncate pr-2">
          <Clock className="w-3.5 h-3.5 text-indigo-600 shrink-0" />
          <span className="font-bold text-slate-800 truncate">{displayDate}</span>
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col p-2 text-xs space-y-2 min-w-[220px]">
          <div className="text-[11px] font-bold text-slate-500 px-1 uppercase tracking-wider">
            Thời hạn nhanh
          </div>
          <div className="grid grid-cols-2 gap-1.5">
            <button
              type="button"
              onClick={() => calcYears(1)}
              className="px-2.5 py-1.5 rounded-lg text-left transition text-xs font-semibold bg-slate-50 hover:bg-slate-100 text-slate-700"
            >
              1 Năm
            </button>
            <button
              type="button"
              onClick={() => calcYears(2)}
              className="px-2.5 py-1.5 rounded-lg text-left transition text-xs font-semibold bg-slate-50 hover:bg-slate-100 text-slate-700"
            >
              2 Năm
            </button>
            <button
              type="button"
              onClick={() => calcYears(3)}
              className="px-2.5 py-1.5 rounded-lg text-left transition text-xs font-semibold bg-slate-50 hover:bg-slate-100 text-slate-700"
            >
              3 Năm
            </button>
            <button
              type="button"
              onClick={() => {
                onChange('2099-12-31');
                setIsOpen(false);
              }}
              className="px-2.5 py-1.5 rounded-lg text-left transition text-xs font-semibold bg-indigo-50 hover:bg-indigo-100 text-indigo-700"
            >
              Vô thời hạn
            </button>
          </div>

          <div className="pt-2 border-t border-slate-100">
            <label className="block text-[10px] font-semibold text-slate-500 mb-1">
              Hoặc chọn ngày cụ thể:
            </label>
            <input
              type="date"
              value={value}
              onChange={(e) => onChange(e.target.value)}
              className="w-full px-2.5 py-1.5 text-xs rounded-lg border border-slate-200 bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>
        </div>
      )}
    </div>
  );
};

interface DepositFeeComboboxProps {
  value: number;
  onChange: (fee: number) => void;
}

const DEPOSIT_PRESETS = [
  { amount: 0, label: '0 đ (Miễn phí cọc)' },
  { amount: 50000, label: '50,000 đ (Tiêu chuẩn)' },
  { amount: 100000, label: '100,000 đ (Kèm gửi xe)' },
  { amount: 150000, label: '150,000 đ' },
  { amount: 200000, label: '200,000 đ (VIP)' },
];

const DepositFeeCombobox: React.FC<DepositFeeComboboxProps> = ({ value, onChange }) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const formattedValue = useMemo(() => {
    return `${new Intl.NumberFormat('vi-VN').format(value || 0)} đ`;
  }, [value]);

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="flex items-center gap-1.5 truncate pr-2">
          <DollarSign className="w-3.5 h-3.5 text-indigo-600 shrink-0" />
          <span className="font-bold text-slate-800 truncate">{formattedValue}</span>
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col p-2 text-xs space-y-1.5 min-w-[220px]">
          <div className="text-[11px] font-bold text-slate-500 px-1 uppercase tracking-wider">
            Chọn nhanh tiền cọc
          </div>
          <div className="space-y-1">
            {DEPOSIT_PRESETS.map((p) => {
              const isSelected = value === p.amount;
              return (
                <button
                  key={p.amount}
                  type="button"
                  onClick={() => {
                    onChange(p.amount);
                    setIsOpen(false);
                  }}
                  className={`w-full px-2.5 py-1.5 rounded-lg flex items-center justify-between text-left transition font-semibold text-xs ${isSelected
                    ? 'bg-indigo-600 text-white'
                    : 'bg-slate-50 hover:bg-slate-100 text-slate-800'
                    }`}
                >
                  <span>{p.label}</span>
                  {isSelected && <Check className="w-3.5 h-3.5 text-white shrink-0" />}
                </button>
              );
            })}
          </div>

          <div className="pt-2 border-t border-slate-100">
            <label className="block text-[10px] font-semibold text-slate-500 mb-1">
              Hoặc nhập số khác (VND):
            </label>
            <input
              type="number"
              step="1000"
              value={value || 0}
              onChange={(e) => onChange(Number(e.target.value))}
              className="w-full px-2.5 py-1.5 text-xs rounded-lg border border-slate-200 bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono"
            />
          </div>
        </div>
      )}
    </div>
  );
};

interface StatusFilterComboboxProps {
  value: string;
  onChange: (status: string) => void;
}

const STATUS_FILTER_OPTIONS = [
  {
    id: 'ALL',
    label: '-- Tất cả trạng thái --',
    icon: Filter,
    color: 'text-slate-400',
    dotColor: null,
  },
  {
    id: 'ACTIVE',
    label: 'ACTIVE (Đang hoạt động)',
    icon: CheckCircle2,
    color: 'text-emerald-600',
    dotColor: 'bg-emerald-500',
  },
  {
    id: 'LOCKED',
    label: 'LOCKED (Tạm khóa)',
    icon: Lock,
    color: 'text-amber-600',
    dotColor: 'bg-amber-500',
  },
  {
    id: 'LOST_REPORTED',
    label: 'LOST (Báo mất)',
    icon: AlertCircle,
    color: 'text-rose-600',
    dotColor: 'bg-rose-500',
  },
  {
    id: 'REVOKED',
    label: 'REVOKED (Thu hồi)',
    icon: Trash2,
    color: 'text-slate-600',
    dotColor: 'bg-slate-500',
  },
];

const StatusFilterCombobox: React.FC<StatusFilterComboboxProps> = ({ value, onChange }) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const selectedOption =
    STATUS_FILTER_OPTIONS.find((opt) => opt.id === value) || STATUS_FILTER_OPTIONS[0];
  const SelectedIcon = selectedOption.icon;

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="truncate pr-2">
          {value === 'ALL' ? (
            <span className="text-slate-500 font-medium flex items-center gap-1.5 truncate">
              <Filter className="w-3.5 h-3.5 text-slate-400 shrink-0" />
              <span className="truncate">{selectedOption.label}</span>
            </span>
          ) : (
            <span className="text-slate-900 font-bold flex items-center gap-1.5 truncate">
              {selectedOption.dotColor && (
                <span className={`w-2 h-2 rounded-full ${selectedOption.dotColor} shrink-0`} />
              )}
              <SelectedIcon className={`w-3.5 h-3.5 ${selectedOption.color} shrink-0`} />
              <span className="truncate">{selectedOption.label}</span>
            </span>
          )}
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col p-1 text-xs space-y-0.5 min-w-[210px]">
          {STATUS_FILTER_OPTIONS.map((opt) => {
            const isSelected = opt.id === value;
            const Icon = opt.icon;
            return (
              <button
                key={opt.id}
                type="button"
                onClick={() => {
                  onChange(opt.id);
                  setIsOpen(false);
                }}
                className={`w-full px-2.5 py-2 rounded-lg flex items-center justify-between text-left transition ${isSelected
                  ? 'bg-indigo-600 text-white font-bold'
                  : 'hover:bg-slate-100 text-slate-800'
                  }`}
              >
                <div className="flex items-center gap-2 truncate pr-2">
                  {opt.dotColor ? (
                    <span
                      className={`w-2 h-2 rounded-full shrink-0 ${isSelected ? 'bg-white' : opt.dotColor
                        }`}
                    />
                  ) : (
                    <Icon
                      className={`w-3.5 h-3.5 shrink-0 ${isSelected ? 'text-white' : opt.color
                        }`}
                    />
                  )}
                  <span className="truncate">{opt.label}</span>
                </div>
                {isSelected && <Check className="w-3.5 h-3.5 text-white shrink-0 ml-1" />}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
};

interface CardTypeFilterComboboxProps {
  value: string;
  onChange: (cardType: string) => void;
}

const CARD_TYPE_FILTER_OPTIONS = [
  {
    id: 'ALL',
    label: '-- Tất cả loại thẻ --',
    icon: Filter,
    color: 'text-slate-400',
  },
  {
    id: 'RESIDENT_ALL_ACCESS',
    label: 'Thẻ Toàn Quyền',
    icon: CreditCard,
    color: 'text-indigo-600',
  },
  {
    id: 'ELEVATOR_ONLY',
    label: 'Thẻ Thang Máy',
    icon: Building2,
    color: 'text-blue-600',
  },
  {
    id: 'PARKING_ONLY',
    label: 'Thẻ Gửi Xe',
    icon: Radio,
    color: 'text-emerald-600',
  },
  {
    id: 'VISITOR_PASS',
    label: 'Thẻ Khách Thăm',
    icon: User,
    color: 'text-amber-600',
  },
];

const CardTypeFilterCombobox: React.FC<CardTypeFilterComboboxProps> = ({ value, onChange }) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false);
      }
    };
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsOpen(false);
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      document.addEventListener('keydown', handleKeyDown);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen]);

  const selectedOption =
    CARD_TYPE_FILTER_OPTIONS.find((opt) => opt.id === value) || CARD_TYPE_FILTER_OPTIONS[0];
  const SelectedIcon = selectedOption.icon;

  return (
    <div className={`relative w-full ${isOpen ? 'z-40' : 'z-10'}`} ref={dropdownRef}>
      <button
        type="button"
        onClick={() => setIsOpen((prev) => !prev)}
        className={`w-full px-3 py-2 border rounded-lg bg-white text-xs flex items-center justify-between text-left transition font-medium shadow-sm ${isOpen
          ? 'border-indigo-500 ring-2 ring-indigo-500/20'
          : 'border-slate-200 hover:border-slate-300'
          }`}
      >
        <span className="truncate pr-2">
          {value === 'ALL' ? (
            <span className="text-slate-500 font-medium flex items-center gap-1.5 truncate">
              <Filter className="w-3.5 h-3.5 text-slate-400 shrink-0" />
              <span className="truncate">{selectedOption.label}</span>
            </span>
          ) : (
            <span className="text-slate-900 font-bold flex items-center gap-1.5 truncate">
              <SelectedIcon className={`w-3.5 h-3.5 ${selectedOption.color} shrink-0`} />
              <span className="truncate">{selectedOption.label}</span>
            </span>
          )}
        </span>
        <ChevronDown
          className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${isOpen ? 'rotate-180 text-indigo-600' : ''
            }`}
        />
      </button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 mt-1.5 z-50 bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col p-1 text-xs space-y-0.5 min-w-[210px]">
          {CARD_TYPE_FILTER_OPTIONS.map((opt) => {
            const isSelected = opt.id === value;
            const Icon = opt.icon;
            return (
              <button
                key={opt.id}
                type="button"
                onClick={() => {
                  onChange(opt.id);
                  setIsOpen(false);
                }}
                className={`w-full px-2.5 py-2 rounded-lg flex items-center justify-between text-left transition ${isSelected
                  ? 'bg-indigo-600 text-white font-bold'
                  : 'hover:bg-slate-100 text-slate-800'
                  }`}
              >
                <div className="flex items-center gap-2 truncate pr-2">
                  <Icon
                    className={`w-3.5 h-3.5 shrink-0 ${isSelected ? 'text-white' : opt.color
                      }`}
                  />
                  <span className="truncate">{opt.label}</span>
                </div>
                {isSelected && <Check className="w-3.5 h-3.5 text-white shrink-0 ml-1" />}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
};

interface RfidManagementProps {
  embedded?: boolean;
  portalMode?: 'admin' | 'manager' | 'receptionist';
}

export const RfidManagement: React.FC<RfidManagementProps> = ({
  embedded = false,
  portalMode = 'admin',
}) => {
  // 1. Data States
  const [cards, setCards] = useState<RfidCardItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [pagination, setPagination] = useState({
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: 0,
  });

  // 2. Filter & Search States
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');
  const [cardTypeFilter, setCardTypeFilter] = useState<string>('ALL');
  const [apartmentFilter, setApartmentFilter] = useState<string>('');
  const [toastMessage, setToastMessage] = useState<{ type: 'success' | 'error' | 'info'; text: string } | null>(null);

  // 3. Metadata Dropdowns
  const [apartments, setApartments] = useState<RfidApartmentOption[]>([]);
  const [residents, setResidents] = useState<RfidResidentOption[]>([]);

  // 4. Modal States
  const [isCreateModalOpen, setIsCreateModalOpen] = useState<boolean>(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState<boolean>(false);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);
  const [isDeleteModalOpen, setIsDeleteModalOpen] = useState<boolean>(false);

  const [selectedCard, setSelectedCard] = useState<RfidCardItem | null>(null);
  const [cardDetail, setCardDetail] = useState<RfidCardDetailResponse | null>(null);
  const [detailLoading, setDetailLoading] = useState<boolean>(false);
  const [actionLoading, setActionLoading] = useState<boolean>(false);

  // 5. Form States
  const [formData, setFormData] = useState({
    card_uid: '',
    card_number: '',
    card_type: 'RESIDENT_ALL_ACCESS',
    assigned_apartment_id: '',
    assigned_user_id: '',
    issued_date: new Date().toISOString().split('T')[0],
    expiry_date: '',
    status: 'ACTIVE',
    deposit_fee: 50000,
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const showToast = useCallback((type: 'success' | 'error' | 'info', text: string) => {
    setToastMessage({ type, text });
    setTimeout(() => setToastMessage(null), 4000);
  }, []);

  // 7. Load Data
  const loadCards = useCallback(async (page: number = 1, showSpinner: boolean = true) => {
    if (showSpinner) setLoading(true);
    try {
      const res = await rfidApi.listCards({
        page,
        per_page: pagination.perPage,
        search: searchTerm.trim() || undefined,
        status: statusFilter !== 'ALL' ? statusFilter : undefined,
        card_type: cardTypeFilter !== 'ALL' ? cardTypeFilter : undefined,
        apartment_id: apartmentFilter || undefined,
      });

      if (res.success) {
        setCards(res.data);
        setPagination({
          currentPage: res.meta.current_page,
          lastPage: res.meta.last_page,
          perPage: res.meta.per_page,
          total: res.meta.total,
        });
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể tải danh sách thẻ RFID.');
    } finally {
      if (showSpinner) setLoading(false);
    }
  }, [pagination.perPage, searchTerm, statusFilter, cardTypeFilter, apartmentFilter, showToast]);

  // Load Metadata
  useEffect(() => {
    const fetchMetadata = async () => {
      try {
        const aptRes = await rfidApi.getApartments();
        if (aptRes.success) setApartments(aptRes.data);
        const resRes = await rfidApi.getResidents();
        if (resRes.success) setResidents(resRes.data);
      } catch {
        // ignore
      }
    };
    fetchMetadata();
  }, []);

  // Filter apartment residents when apartment changes in form
  const filteredFormResidents = useMemo(() => {
    if (!formData.assigned_apartment_id) return [];
    const list = residents.filter(r => String(r.apartment_id) === String(formData.assigned_apartment_id));
    if (selectedCard?.assigned_user && formData.assigned_user_id === selectedCard.assigned_user.id) {
      const exists = list.some(r => r.user_id === selectedCard.assigned_user!.id);
      if (!exists) {
        list.unshift({
          user_id: selectedCard.assigned_user.id,
          full_name: selectedCard.assigned_user.full_name,
          phone_number: selectedCard.assigned_user.phone_number,
          apartment_id: formData.assigned_apartment_id,
        });
      }
    }
    return list;
  }, [residents, formData.assigned_apartment_id, selectedCard, formData.assigned_user_id]);

  // Debounced search
  const searchTimeoutRef = useRef<any>(null);
  useEffect(() => {
    if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);
    searchTimeoutRef.current = setTimeout(() => {
      loadCards(1, false);
    }, 300);
    return () => clearTimeout(searchTimeoutRef.current);
  }, [searchTerm, statusFilter, cardTypeFilter, apartmentFilter]);

  // Initial load
  useEffect(() => {
    loadCards(1, true);
  }, []);

  // 8. Realtime Event Listener via SSE
  const handleRealtimeEvent = useCallback((event: RealtimeEventPayload) => {
    if (event.module !== 'rfid_cards' && event.module !== 'rfid') {
      return;
    }

    const action = (event.action || '').toUpperCase();
    const entityId = event.entity_id;

    if (action === 'CREATED') {
      showToast('info', `Hệ thống vừa cấp mới thẻ RFID.`);
      loadCards(pagination.currentPage, false);
    } else if (action === 'STATUS_CHANGED' || action === 'UPDATED') {
      const newStatus = event.status;
      setCards(prev =>
        prev.map(c => {
          if (c.id === entityId) {
            return {
              ...c,
              ...(event.status ? { status: event.status as any } : {}),
              ...(event.card_type ? { card_type: event.card_type as any } : {}),
            };
          }
          return c;
        })
      );
      showToast('info', `Thẻ RFID ${event.card_number || ''} vừa được cập nhật trạng thái.`);
    } else if (action === 'DELETED') {
      if (event.is_revoked) {
        setCards(prev =>
          prev.map(c => (c.id === entityId ? { ...c, status: 'REVOKED' } : c))
        );
      } else {
        setCards(prev => prev.filter(c => c.id !== entityId));
      }
      showToast('info', `Thẻ RFID đã được cập nhật/thu hồi.`);
    }
  }, [pagination.currentPage, loadCards, showToast]);

  useRealtimeSync({
    channel: 'quoc-tin.rfid-cards',
    onEvent: handleRealtimeEvent,
    onReconnect: () => {
      loadCards(pagination.currentPage, false);
    },
  });

  // 9. Actions
  const handleToggleStatus = async (card: RfidCardItem) => {
    try {
      setActionLoading(true);
      const res = await rfidApi.toggleStatus(card.id);
      if (res.success) {
        setCards(prev => prev.map(c => (c.id === card.id ? res.data : c)));
        showToast('success', res.message);
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể đổi trạng thái thẻ.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleOpenDetail = async (card: RfidCardItem) => {
    setSelectedCard(card);
    setIsDetailModalOpen(true);
    setDetailLoading(true);
    try {
      const res = await rfidApi.getCard(card.id);
      if (res.success) {
        setCardDetail(res.data);
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể tải chi tiết thẻ.');
    } finally {
      setDetailLoading(false);
    }
  };

  const handleOpenEdit = (card: RfidCardItem) => {
    setSelectedCard(card);
    setFormData({
      card_uid: card.card_uid,
      card_number: card.card_number,
      card_type: card.card_type,
      assigned_apartment_id: card.assigned_apartment_id || '',
      assigned_user_id: card.assigned_user_id || '',
      issued_date: card.issued_date,
      expiry_date: card.expiry_date || '',
      status: card.status,
      deposit_fee: card.deposit_fee || 50000,
    });
    setFormErrors({});
    setIsEditModalOpen(true);
  };

  const handleOpenDelete = (card: RfidCardItem) => {
    setSelectedCard(card);
    setIsDeleteModalOpen(true);
  };

  const handleDeleteConfirm = async () => {
    if (!selectedCard) return;
    try {
      setActionLoading(true);
      const res = await rfidApi.deleteCard(selectedCard.id);
      if (res.success) {
        showToast('success', res.message);
        setIsDeleteModalOpen(false);
        loadCards(pagination.currentPage, false);
      }
    } catch (err: any) {
      showToast('error', err.message || 'Không thể xóa thẻ.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormErrors({});
    try {
      setActionLoading(true);
      const res = await rfidApi.createCard({
        card_uid: formData.card_uid,
        card_number: formData.card_number,
        card_type: formData.card_type,
        assigned_apartment_id: formData.assigned_apartment_id || null,
        assigned_user_id: formData.assigned_user_id || null,
        issued_date: formData.issued_date,
        expiry_date: formData.expiry_date || null,
        status: formData.status,
        deposit_fee: Number(formData.deposit_fee) || 0,
      });

      if (res.success) {
        showToast('success', res.message);
        setIsCreateModalOpen(false);
        setFormData({
          card_uid: '',
          card_number: '',
          card_type: 'RESIDENT_ALL_ACCESS',
          assigned_apartment_id: '',
          assigned_user_id: '',
          issued_date: new Date().toISOString().split('T')[0],
          expiry_date: '',
          status: 'ACTIVE',
          deposit_fee: 50000,
        });
        loadCards(1, false);
      }
    } catch (err: any) {
      if (err.data?.errors) {
        const errs: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.data.errors)) {
          errs[k] = Array.isArray(v) ? (v[0] as string) : (v as string);
        }
        setFormErrors(errs);
      } else {
        showToast('error', err.message || 'Lỗi cấp thẻ.');
      }
    } finally {
      setActionLoading(false);
    }
  };

  const handleEditSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedCard) return;

    setFormErrors({});
    try {
      setActionLoading(true);
      const res = await rfidApi.updateCard(selectedCard.id, {
        card_uid: formData.card_uid,
        card_number: formData.card_number,
        card_type: formData.card_type as any,
        assigned_apartment_id: formData.assigned_apartment_id || null,
        assigned_user_id: formData.assigned_user_id || null,
        issued_date: formData.issued_date,
        expiry_date: formData.expiry_date || null,
        status: formData.status as any,
        deposit_fee: Number(formData.deposit_fee) || 0,
      });

      if (res.success) {
        showToast('success', res.message);
        setIsEditModalOpen(false);
        setCards(prev => prev.map(c => (c.id === selectedCard.id ? res.data : c)));
      }
    } catch (err: any) {
      if (err.data?.errors) {
        const errs: Record<string, string> = {};
        for (const [k, v] of Object.entries(err.data.errors)) {
          errs[k] = Array.isArray(v) ? (v[0] as string) : (v as string);
        }
        setFormErrors(errs);
      } else {
        showToast('error', err.message || 'Lỗi cập nhật thẻ.');
      }
    } finally {
      setActionLoading(false);
    }
  };

  // Helper Card Type Label
  const getCardTypeBadge = (type: string) => {
    switch (type) {
      case 'RESIDENT_ALL_ACCESS':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200">Toàn Quyền</span>;
      case 'ELEVATOR_ONLY':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-sky-100 text-sky-800 border border-sky-200">Thang Máy</span>;
      case 'PARKING_ONLY':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800 border border-indigo-200">Gửi Xe</span>;
      case 'VISITOR_PASS':
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200">Khách Thăm</span>;
      default:
        return <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-800 border border-slate-200">{type}</span>;
    }
  };

  // Helper Status Badge
  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'ACTIVE':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800 border border-green-200">
            <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span>
            ACTIVE
          </span>
        );
      case 'LOCKED_TEMPORARY':
      case 'LOCKED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
            <Lock className="w-3 h-3 text-amber-600" />
            LOCKED
          </span>
        );
      case 'LOST_REPORTED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-800 border border-red-200">
            <AlertCircle className="w-3 h-3 text-red-600" />
            BÁO MẤT
          </span>
        );
      case 'REVOKED':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-200 text-slate-700 border border-slate-300">
            THU HỒI
          </span>
        );
      default:
        return <span className="px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-700">{status}</span>;
    }
  };

  // 9.5. Statistics Memo for KPI Cards
  const stats = useMemo(() => {
    let activeCount = 0;
    let lockedCount = 0;
    let totalDeposit = 0;

    cards.forEach(card => {
      if (card.status === 'ACTIVE') {
        activeCount++;
      }
      if (card.status === 'LOCKED' || card.status === 'LOCKED_TEMPORARY') {
        lockedCount++;
      }
      totalDeposit += Number(card.deposit_fee || 0);
    });

    return {
      totalCount: pagination.total || cards.length,
      activeCount,
      lockedCount,
      totalDeposit,
    };
  }, [cards, pagination.total]);

  return (
    <div className={`space-y-6 ${embedded ? '' : 'p-6 max-w-7xl mx-auto'}`}>
      {/* Toast Notification */}
      {toastMessage && typeof document !== 'undefined' && createPortal(
        <div
          className={`fixed top-5 right-5 z-[10000] flex items-center gap-2 px-4 py-3 rounded-lg shadow-xl text-sm font-medium border transition-all duration-300 ${toastMessage.type === 'success'
            ? 'bg-emerald-50 text-emerald-900 border-emerald-300'
            : toastMessage.type === 'error'
              ? 'bg-rose-50 text-rose-900 border-rose-300'
              : 'bg-sky-50 text-sky-900 border-sky-300'
            }`}
        >
          {toastMessage.type === 'success' ? (
            <CheckCircle2 className="w-5 h-5 text-emerald-600" />
          ) : toastMessage.type === 'error' ? (
            <AlertCircle className="w-5 h-5 text-rose-600" />
          ) : (
            <Radio className="w-5 h-5 text-sky-600 animate-pulse" />
          )}
          <span>{toastMessage.text}</span>
        </div>,
        document.body
      )}

      {/* 1. Header & Title */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-2 border-b border-slate-200">
        <div>
          <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider font-mono text-indigo-600">
            <CreditCard className="w-4 h-4 text-indigo-600" />
            <span>
              {portalMode === 'receptionist'
                ? 'CỔNG LỄ TÂN · THẺ TỪ THÔNG MINH'
                : 'PHÂN HỆ AN NINH · KIỂM SOÁT RA VÀO'}
            </span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-neutral-900 tracking-tight mt-1">
            Quản Lý Mã Thẻ RFID
          </h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-1">
            Kiểm soát mã thẻ từ ra vào thang máy, barie cổng tòa nhà & bãi đỗ xe (Chuyển trạng thái ACTIVE / LOCKED tức thì).
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            onClick={() => loadCards(pagination.currentPage, true)}
            className="flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition shadow-sm"
            title="Làm mới dữ liệu"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            <span>Tải lại</span>
          </button>

          <button
            onClick={() => {
              setFormData({
                card_uid: '',
                card_number: '',
                card_type: 'RESIDENT_ALL_ACCESS',
                assigned_apartment_id: '',
                assigned_user_id: '',
                issued_date: new Date().toISOString().split('T')[0],
                expiry_date: '',
                status: 'ACTIVE',
                deposit_fee: 50000,
              });
              setFormErrors({});
              setIsCreateModalOpen(true);
            }}
            className="flex items-center gap-2 px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 hover:shadow rounded-lg transition shadow-sm"
            title="Cấp Thẻ Mới"
          >
            <Plus className="w-4 h-4" />
            <span>Cấp Thẻ Mới</span>
          </button>
        </div>
      </div>

      {/* 2. KPI Statistics Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
            <CreditCard className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tổng số thẻ</div>
            <div className="text-2xl font-black text-slate-900">{stats.totalCount}</div>
            <div className="text-[11px] text-blue-600 font-semibold mt-0.5">Thẻ lưu trên hệ thống</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <ShieldCheck className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Đang hoạt động</div>
            <div className="text-2xl font-black text-emerald-700">{stats.activeCount}</div>
            <div className="text-[11px] text-emerald-600 font-semibold mt-0.5">Quyền ra vào hợp lệ</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <Lock className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Đang tạm khóa</div>
            <div className="text-2xl font-black text-amber-700">{stats.lockedCount}</div>
            <div className="text-[11px] text-amber-600 font-semibold mt-0.5">Chặn quẹt thẻ tức thì</div>
          </div>
        </div>

        <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center flex-shrink-0">
            <DollarSign className="w-6 h-6" />
          </div>
          <div>
            <div className="text-xs text-slate-500 font-medium">Tiền đặt cọc thẻ</div>
            <div className="text-xl sm:text-2xl font-black text-purple-700">
              {new Intl.NumberFormat('vi-VN').format(stats.totalDeposit)} đ
            </div>
            <div className="text-[11px] text-purple-600 font-semibold mt-0.5">Tiền cọc giữ thẻ cư dân</div>
          </div>
        </div>
      </div>

      {/* 3. Filter & Search Bar */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm space-y-3 relative z-20">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
          {/* Search Input */}
          <div className="relative lg:col-span-2">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Tìm theo UID, số thẻ, cư dân, căn hộ..."
              className="w-full pl-9 pr-8 py-2 text-xs border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white"
            />
            {searchTerm && (
              <button
                type="button"
                onClick={() => setSearchTerm('')}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                title="Xóa tìm kiếm"
              >
                <X className="w-3.5 h-3.5" />
              </button>
            )}
          </div>

          {/* Trạng thái filter */}
          <div>
            <StatusFilterCombobox value={statusFilter} onChange={setStatusFilter} />
          </div>

          {/* Loại thẻ filter */}
          <div>
            <CardTypeFilterCombobox value={cardTypeFilter} onChange={setCardTypeFilter} />
          </div>

          {/* Căn hộ filter */}
          <div>
            <ApartmentCombobox
              value={apartmentFilter}
              onChange={setApartmentFilter}
              apartments={apartments}
              placeholder="-- Lọc theo căn hộ --"
              emptyLabel="-- Tất cả căn hộ --"
            />
          </div>
        </div>

        {/* Clear filters row if active */}
        {(searchTerm || statusFilter !== 'ALL' || cardTypeFilter !== 'ALL' || apartmentFilter) && (
          <div className="flex items-center justify-between pt-2 border-t border-slate-100 text-xs text-slate-500">
            <span>Đang áp dụng bộ lọc tùy chỉnh</span>
            <button
              onClick={() => {
                setSearchTerm('');
                setStatusFilter('ALL');
                setCardTypeFilter('ALL');
                setApartmentFilter('');
              }}
              className="text-indigo-600 hover:text-indigo-700 font-semibold flex items-center gap-1"
            >
              <X className="w-3.5 h-3.5" />
              <span>Xóa bộ lọc</span>
            </button>
          </div>
        )}
      </div>

      {/* 4. RFID Cards Table */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="bg-slate-50/80 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider font-mono">
                <th className="py-3 px-4">Mã Thẻ (UID & Mặt Thẻ)</th>
                <th className="py-3 px-4">Loại Thẻ</th>
                <th className="py-3 px-4">Cư Dân Sở Hữu</th>
                <th className="py-3 px-4">Căn Hộ</th>
                <th className="py-3 px-4">Ngày Cấp & Hạn Dùng</th>
                <th className="py-3 px-4">Đặt Cọc</th>
                <th className="py-3 px-4 text-center">Trạng Thái</th>
                <th className="py-3 px-4 text-right">Thao Tác</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading && cards.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Loader2 className="w-6 h-6 animate-spin text-indigo-600" />
                      <span>Đang tải danh sách thẻ RFID...</span>
                    </div>
                  </td>
                </tr>
              ) : cards.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <CreditCard className="w-8 h-8 text-slate-300" />
                      <span className="font-medium">Không tìm thấy thẻ RFID nào phù hợp với bộ lọc.</span>
                    </div>
                  </td>
                </tr>
              ) : (
                cards.map((card) => {
                  return (
                    <tr key={card.id} className="hover:bg-slate-50/70 transition">
                      {/* UID & Card Number */}
                      <td className="py-3 px-4 font-mono">
                        <div className="font-extrabold text-slate-900 tracking-wider text-xs bg-slate-100 px-2 py-0.5 rounded border border-slate-300 inline-block">
                          {card.card_number}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1 flex items-center gap-1 font-sans">
                          <CreditCard className="w-3 h-3 text-slate-400" />
                          <span>UID:</span>
                          <span className="font-mono text-slate-700 font-semibold">{card.card_uid}</span>
                        </div>
                      </td>

                      {/* Loại Thẻ */}
                      <td className="py-3 px-4">
                        {getCardTypeBadge(card.card_type)}
                      </td>

                      {/* Cư Dân */}
                      <td className="py-3 px-4">
                        {card.assigned_user ? (
                          <div>
                            <div className="font-semibold text-slate-900 flex items-center gap-1">
                              <User className="w-3.5 h-3.5 text-slate-400" />
                              <span>{card.assigned_user.full_name}</span>
                            </div>
                            {card.assigned_user.phone_number && (
                              <div className="text-[11px] text-slate-400 mt-0.5">
                                {card.assigned_user.phone_number}
                              </div>
                            )}
                          </div>
                        ) : (
                          <span className="text-slate-400 italic">Chưa gán cư dân</span>
                        )}
                      </td>

                      {/* Căn Hộ */}
                      <td className="py-3 px-4">
                        {card.assigned_apartment ? (
                          <div className="flex items-center gap-1.5 font-semibold text-slate-800">
                            <Building className="w-3.5 h-3.5 text-slate-400" />
                            <span>Căn {card.assigned_apartment.apartment_number}</span>
                          </div>
                        ) : (
                          <span className="text-slate-400 italic">—</span>
                        )}
                      </td>

                      {/* Ngày Cấp & Hạn */}
                      <td className="py-3 px-4 text-slate-600">
                        <div>Cấp: <span className="font-medium text-slate-700">{card.issued_date}</span></div>
                        <div className="text-slate-400 text-[11px] mt-0.5">
                          {card.expiry_date ? `Hạn: ${card.expiry_date}` : 'Vô thời hạn'}
                        </div>
                      </td>

                      {/* Tiền Cọc */}
                      <td className="py-3 px-4 font-semibold text-slate-900">
                        {new Intl.NumberFormat('vi-VN').format(Number(card.deposit_fee || 0))} đ
                      </td>

                      {/* Trạng Thái */}
                      <td className="py-3 px-4 text-center">
                        {getStatusBadge(card.status)}
                      </td>

                      {/* Thao Tác */}
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          {/* Toggle Active / Lock Button */}
                          <button
                            onClick={() => handleToggleStatus(card)}
                            disabled={actionLoading || card.status === 'REVOKED'}
                            className={`p-1.5 rounded-lg transition ${card.status === 'ACTIVE'
                              ? 'text-slate-600 hover:text-amber-600 hover:bg-amber-50'
                              : 'text-slate-600 hover:text-emerald-600 hover:bg-emerald-50'
                              }`}
                            title={card.status === 'ACTIVE' ? 'Tạm khóa thẻ tức thì' : 'Mở kích hoạt thẻ'}
                          >
                            {card.status === 'ACTIVE' ? (
                              <Lock className="w-4 h-4" />
                            ) : (
                              <Unlock className="w-4 h-4" />
                            )}
                          </button>

                          {/* Detail Button */}
                          <button
                            onClick={() => handleOpenDetail(card)}
                            className="p-1.5 text-slate-600 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition"
                            title="Xem chi tiết & lịch sử quẹt"
                          >
                            <Eye className="w-4 h-4" />
                          </button>

                          {/* Edit Button */}
                          <button
                            onClick={() => handleOpenEdit(card)}
                            className="p-1.5 text-slate-600 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition"
                            title="Chỉnh sửa thông tin thẻ"
                          >
                            <Edit className="w-4 h-4" />
                          </button>

                          {/* Delete Button */}
                          <button
                            onClick={() => handleOpenDelete(card)}
                            disabled={actionLoading}
                            className="p-1.5 text-slate-600 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                            title="Xóa / Thu hồi thẻ"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {/* Phân trang */}
        <div className="flex items-center justify-between px-4 py-3 bg-slate-50 border-t border-slate-200 text-xs text-slate-500">
          <div>
            Hiển thị trang <span className="font-bold text-slate-700">{pagination.currentPage}</span> / {pagination.lastPage} (Tổng{' '}
            <span className="font-bold text-slate-700">{pagination.total}</span> thẻ)
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={() => loadCards(pagination.currentPage - 1)}
              disabled={pagination.currentPage <= 1 || loading}
              className="p-1.5 border border-slate-200 rounded-lg bg-white disabled:opacity-40 hover:bg-slate-100 transition"
              title="Trang trước"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <button
              onClick={() => loadCards(pagination.currentPage + 1)}
              disabled={pagination.currentPage >= pagination.lastPage || loading}
              className="p-1.5 border border-slate-200 rounded-lg bg-white disabled:opacity-40 hover:bg-slate-100 transition"
              title="Trang tiếp"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Modal: Create Card */}
      {isCreateModalOpen && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in py-6 sm:py-8">
          <div className="bg-white rounded-2xl max-w-xl w-full flex flex-col shadow-2xl border border-slate-200 my-auto animate-in fade-in zoom-in-95 duration-150 relative">
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-slate-200 bg-slate-50/80 rounded-t-2xl shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center">
                  <CreditCard className="w-4 h-4" />
                </div>
                <h3 className="font-extrabold text-slate-900 text-base">Cấp Mã Thẻ RFID Mới</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsCreateModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateSubmit} className="flex flex-col">
              <div className="p-5 sm:p-6 space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 relative z-40">
                  {/* UID */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Mã Chip RFID (UID) <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="VD: RFID-ELEV-9901"
                      value={formData.card_uid}
                      onChange={e => setFormData({ ...formData, card_uid: e.target.value.toUpperCase() })}
                      className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 uppercase"
                    />
                    {formErrors.card_uid && <p className="text-xs text-rose-500 mt-1">{formErrors.card_uid}</p>}
                  </div>

                  {/* Card Number */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Số In Trên Mặt Thẻ <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="VD: CARD-9901"
                      value={formData.card_number}
                      onChange={e => setFormData({ ...formData, card_number: e.target.value.toUpperCase() })}
                      className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 uppercase"
                    />
                    {formErrors.card_number && <p className="text-xs text-rose-500 mt-1">{formErrors.card_number}</p>}
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 relative z-30">
                  {/* Card Type */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Loại Thẻ
                    </label>
                    <CardTypeCombobox
                      value={formData.card_type}
                      onChange={type => setFormData(prev => ({ ...prev, card_type: type }))}
                    />
                  </div>

                  {/* Initial Status */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Trạng Thái Ban Đầu
                    </label>
                    <CardStatusCombobox
                      value={formData.status}
                      onChange={status => setFormData(prev => ({ ...prev, status }))}
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 relative z-20">
                  {/* Apartment Combobox */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Gán Căn Hộ
                    </label>
                    <ApartmentCombobox
                      value={formData.assigned_apartment_id}
                      onChange={apartmentId => {
                        setFormData(prev => ({
                          ...prev,
                          assigned_apartment_id: apartmentId,
                          assigned_user_id: '',
                        }));
                      }}
                      apartments={apartments}
                    />
                  </div>

                  {/* Resident Combobox */}
                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <label className="block text-xs font-bold text-slate-700">
                        Gán Cư Dân
                      </label>
                      {!formData.assigned_apartment_id && (
                        <span className="text-[10px] text-amber-600 font-medium">
                          (Chọn căn hộ trước)
                        </span>
                      )}
                    </div>
                    <ResidentCombobox
                      value={formData.assigned_user_id}
                      onChange={userId => setFormData(prev => ({ ...prev, assigned_user_id: userId }))}
                      residents={filteredFormResidents}
                      disabled={!formData.assigned_apartment_id}
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5 relative z-10">
                  {/* Issued Date */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Ngày Cấp
                    </label>
                    <IssuedDateCombobox
                      value={formData.issued_date}
                      onChange={date => setFormData(prev => ({ ...prev, issued_date: date }))}
                    />
                  </div>

                  {/* Expiry Date */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Hạn Sử Dụng
                    </label>
                    <ExpiryDateCombobox
                      value={formData.expiry_date}
                      onChange={date => setFormData(prev => ({ ...prev, expiry_date: date }))}
                      issuedDate={formData.issued_date}
                    />
                  </div>

                  {/* Deposit Fee */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Tiền Cọc (VND)
                    </label>
                    <DepositFeeCombobox
                      value={formData.deposit_fee}
                      onChange={fee => setFormData(prev => ({ ...prev, deposit_fee: fee }))}
                    />
                  </div>
                </div>
              </div>

              <div className="p-4 sm:px-6 bg-slate-50 border-t border-slate-100 flex items-center justify-end gap-2.5 rounded-b-2xl shrink-0 relative z-0">
                <button
                  type="button"
                  onClick={() => setIsCreateModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Hủy Bỏ
                </button>
                <button
                  type="submit"
                  disabled={actionLoading}
                  className="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition shadow-sm flex items-center gap-1.5 disabled:opacity-50"
                >
                  {actionLoading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
                  <span>Xác Nhận Cấp Thẻ</span>
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.body
      )}

      {/* Modal: Edit Card */}
      {isEditModalOpen && selectedCard && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in py-6 sm:py-8">
          <div className="bg-white rounded-2xl max-w-xl w-full flex flex-col shadow-2xl border border-slate-200 my-auto animate-in fade-in zoom-in-95 duration-150 relative">
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-slate-200 bg-slate-50/80 rounded-t-2xl shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center">
                  <Edit className="w-4 h-4" />
                </div>
                <h3 className="font-extrabold text-slate-900 text-base">Cập Nhật Thẻ RFID: {selectedCard.card_number}</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsEditModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleEditSubmit} className="flex flex-col">
              <div className="p-5 sm:p-6 space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 relative z-40">
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">Mã UID</label>
                    <input
                      type="text"
                      required
                      value={formData.card_uid}
                      onChange={e => setFormData({ ...formData, card_uid: e.target.value.toUpperCase() })}
                      className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 uppercase"
                    />
                    {formErrors.card_uid && <p className="text-xs text-rose-500 mt-1">{formErrors.card_uid}</p>}
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">Số Thẻ</label>
                    <input
                      type="text"
                      required
                      value={formData.card_number}
                      onChange={e => setFormData({ ...formData, card_number: e.target.value.toUpperCase() })}
                      className="w-full px-3 py-2 rounded-lg border border-slate-200 text-xs font-mono focus:ring-2 focus:ring-indigo-500 bg-slate-50/50 uppercase"
                    />
                    {formErrors.card_number && <p className="text-xs text-rose-500 mt-1">{formErrors.card_number}</p>}
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 relative z-30">
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">Loại Thẻ</label>
                    <CardTypeCombobox
                      value={formData.card_type}
                      onChange={type => setFormData(prev => ({ ...prev, card_type: type }))}
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">Trạng Thái</label>
                    <CardStatusCombobox
                      value={formData.status}
                      onChange={status => setFormData(prev => ({ ...prev, status }))}
                      isEditMode
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 relative z-20">
                  {/* Apartment Combobox */}
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">Căn Hộ</label>
                    <ApartmentCombobox
                      value={formData.assigned_apartment_id}
                      onChange={apartmentId => {
                        setFormData(prev => ({
                          ...prev,
                          assigned_apartment_id: apartmentId,
                          assigned_user_id: '',
                        }));
                      }}
                      apartments={apartments}
                    />
                  </div>

                  {/* Resident Combobox */}
                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <label className="block text-xs font-bold text-slate-700">Cư Dân</label>
                      {!formData.assigned_apartment_id && (
                        <span className="text-[10px] text-amber-600 font-medium">
                          (Chọn căn hộ trước)
                        </span>
                      )}
                    </div>
                    <ResidentCombobox
                      value={formData.assigned_user_id}
                      onChange={userId => setFormData(prev => ({ ...prev, assigned_user_id: userId }))}
                      residents={filteredFormResidents}
                      disabled={!formData.assigned_apartment_id}
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 relative z-10">
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">Hạn Sử Dụng</label>
                    <ExpiryDateCombobox
                      value={formData.expiry_date}
                      onChange={date => setFormData(prev => ({ ...prev, expiry_date: date }))}
                      issuedDate={formData.issued_date}
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">Tiền Cọc (VND)</label>
                    <DepositFeeCombobox
                      value={formData.deposit_fee}
                      onChange={fee => setFormData(prev => ({ ...prev, deposit_fee: fee }))}
                    />
                  </div>
                </div>
              </div>

              <div className="p-4 sm:px-6 bg-slate-50 border-t border-slate-100 flex items-center justify-end gap-2.5 rounded-b-2xl shrink-0 relative z-0">
                <button
                  type="button"
                  onClick={() => setIsEditModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Hủy Bỏ
                </button>
                <button
                  type="submit"
                  disabled={actionLoading}
                  className="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition shadow-sm flex items-center gap-1.5 disabled:opacity-50"
                >
                  {actionLoading ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : (
                    <Edit className="w-4 h-4" />
                  )}
                  <span>Lưu Thay Đổi</span>
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.body
      )}

      {/* Modal: View Detail & Recent Access Logs */}
      {isDetailModalOpen && selectedCard && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-xl w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-slate-200 bg-slate-50/80 shrink-0">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center">
                  <FileText className="w-4 h-4" />
                </div>
                <h3 className="font-extrabold text-slate-900 text-base">Chi Tiết Thẻ & Lịch Sử Quẹt</h3>
              </div>
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg p-1.5 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="p-5 sm:p-6 space-y-5 overflow-y-auto flex-1 max-h-[calc(90vh-130px)]">
              {detailLoading ? (
                <div className="py-8 text-center text-slate-400">
                  <Loader2 className="w-6 h-6 animate-spin mx-auto mb-2 text-indigo-600" />
                  <span>Đang tải thông tin...</span>
                </div>
              ) : (
                <>
                  <div className="p-4 bg-slate-50/80 rounded-xl border border-slate-200 space-y-2.5 text-xs">
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Số in trên thẻ:</span>
                      <strong className="text-slate-900 font-mono text-sm">{selectedCard.card_number}</strong>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Mã chip RFID (UID):</span>
                      <strong className="text-slate-900 font-mono">{selectedCard.card_uid}</strong>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Loại thẻ:</span>
                      <span>{getCardTypeBadge(selectedCard.card_type)}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Trạng thái:</span>
                      <span>{getStatusBadge(selectedCard.status)}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Căn hộ:</span>
                      <span className="font-semibold text-slate-900">{selectedCard.assigned_apartment?.apartment_number || 'Chưa gán'}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="text-slate-500 font-medium">Cư dân sở hữu:</span>
                      <span className="font-semibold text-slate-900">{selectedCard.assigned_user?.full_name || 'Chưa gán'}</span>
                    </div>
                  </div>

                  <div>
                    <h4 className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 font-mono">
                      Nhật Ký Quẹt Thẻ Gần Nhất
                    </h4>
                    {cardDetail?.recent_logs && cardDetail.recent_logs.length > 0 ? (
                      <div className="space-y-1.5 text-xs max-h-48 overflow-y-auto">
                        {cardDetail.recent_logs.map(log => (
                          <div key={log.id} className="p-2.5 rounded-lg bg-slate-50 border border-slate-200 flex justify-between items-center">
                            <span className="font-medium text-slate-700">Hướng: {log.access_direction}</span>
                            <span className="text-slate-500 font-mono text-[11px]">{log.log_timestamp}</span>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <p className="text-xs text-slate-400 italic">Chưa có bản ghi quẹt thẻ nào.</p>
                    )}
                  </div>
                </>
              )}
            </div>

            <div className="p-4 sm:px-6 bg-slate-50 border-t border-slate-100 text-right shrink-0">
              <button
                type="button"
                onClick={() => setIsDetailModalOpen(false)}
                className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
              >
                Đóng
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}

      {/* Modal: Delete Confirmation */}
      {isDeleteModalOpen && selectedCard && typeof document !== 'undefined' && createPortal(
        <div className="fixed inset-0 z-[9999] flex items-center justify-center p-3 sm:p-4 bg-slate-950/65 backdrop-blur-sm overflow-y-auto animate-fade-in">
          <div className="bg-white rounded-2xl max-w-md w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden my-auto animate-in fade-in zoom-in-95 duration-150">
            <div className="p-6 text-center space-y-4 overflow-y-auto flex-1">
              <div className="w-14 h-14 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mx-auto border-4 border-rose-100">
                <Trash2 className="w-7 h-7" />
              </div>
              <div>
                <h3 className="text-lg font-black text-slate-900">Xác Nhận Xóa / Thu Hồi Thẻ?</h3>
                <p className="text-xs sm:text-sm text-slate-500 mt-1">
                  Bạn có chắc chắn muốn xóa hoặc thu hồi thẻ <strong>{selectedCard.card_number}</strong> (UID: {selectedCard.card_uid}) không?
                </p>
                <p className="text-xs text-amber-700 bg-amber-50 p-2.5 rounded-lg border border-amber-200 mt-3 text-left">
                  💡 <strong>Lưu ý:</strong> Nếu thẻ này đã từng quẹt ra vào trong quá khứ, hệ thống sẽ tự động chuyển trạng thái sang <strong>THU HỒI (REVOKED)</strong> để bảo toàn tính toàn vẹn dữ liệu.
                </p>
              </div>

              <div className="flex items-center justify-center gap-2.5 pt-2">
                <button
                  type="button"
                  onClick={() => setIsDeleteModalOpen(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition"
                >
                  Hủy Bỏ
                </button>
                <button
                  type="submit"
                  onClick={handleDeleteConfirm}
                  disabled={actionLoading}
                  className="px-4 py-2 text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 rounded-lg transition shadow-sm disabled:opacity-50 flex items-center gap-1.5"
                >
                  {actionLoading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Trash2 className="w-4 h-4" />}
                  <span>Đồng Ý Xóa</span>
                </button>
              </div>
            </div>
          </div>
        </div>,
        document.body
      )}
    </div>
  );
};

export default RfidManagement;
