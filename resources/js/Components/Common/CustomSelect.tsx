import React, { useState, useRef, useEffect, useLayoutEffect, useId, useMemo } from 'react';
import { ChevronDown, Check, Search, X } from 'lucide-react';

export interface SelectOption<T = string> {
    value: T;
    label: string;
    description?: string;
    icon?: React.ReactNode;
    badge?: string;
    badgeColor?: string;
    disabled?: boolean;
}

export interface CustomSelectProps<T = string> {
    value: T;
    onChange: (value: T) => void;
    options: SelectOption<T>[];
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    buttonClassName?: string;
    menuClassName?: string;
    id?: string;
    name?: string;
    label?: string;
    required?: boolean;
    prefixIcon?: React.ReactNode;
    align?: 'left' | 'right';
    overlay?: boolean;
    searchable?: boolean;
    searchPlaceholder?: string;
}

export function CustomSelect<T extends string | number | null = string>({
    value,
    onChange,
    options,
    placeholder = 'Chọn một mục...',
    disabled = false,
    className = '',
    buttonClassName = '',
    menuClassName = '',
    id,
    label,
    required = false,
    prefixIcon,
    align = 'left',
    overlay = false,
    searchable,
    searchPlaceholder = 'Tìm kiếm...',
}: CustomSelectProps<T>) {
    const [isOpen, setIsOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const containerRef = useRef<HTMLDivElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const searchInputRef = useRef<HTMLInputElement>(null);
    const generatedId = useId();
    const selectId = id || generatedId;

    // Hiển thị thanh search nếu được bật rõ ràng hoặc danh sách >= 5 mục (trừ khi searchable === false)
    const showSearch = searchable !== undefined ? searchable : options.length >= 5;

    // Reset search khi đóng mở dropdown
    useEffect(() => {
        if (!isOpen) {
            setSearchQuery('');
        } else if (showSearch) {
            // Focus vào ô tìm kiếm khi mở
            const timer = setTimeout(() => {
                searchInputRef.current?.focus();
            }, 60);
            return () => clearTimeout(timer);
        }
    }, [isOpen, showSearch]);

    // Tìm option đang được chọn
    const selectedOption = options.find((opt) => opt.value === value);

    // Lọc danh sách theo từ khóa tìm kiếm
    const filteredOptions = useMemo(() => {
        if (!searchQuery.trim()) return options;
        const q = searchQuery.toLowerCase().trim();
        return options.filter((opt) =>
            opt.label.toLowerCase().includes(q) ||
            (opt.description && opt.description.toLowerCase().includes(q))
        );
    }, [options, searchQuery]);

    useLayoutEffect(() => {
        const menu = menuRef.current;
        if (!isOpen || !overlay || !menu) return;
        // The top layer escapes scroll clipping, including inside a modal dialog.
        if (typeof menu.showPopover === 'function') {
            try {
                menu.showPopover();
            } catch {
                // Keep the regular absolute menu as a fallback when Popover API is unavailable.
                menu.style.position = 'absolute';
            }
        }
        const position = () => {
            const trigger = containerRef.current?.querySelector('button')?.getBoundingClientRect();
            if (!trigger) return;
            const below = Math.max(0, window.innerHeight - trigger.bottom - 14);
            const above = Math.max(0, trigger.top - 14);
            const maxVisibleOptions = 6;
            const optionHeight = 40;
            const maxMenuHeight = maxVisibleOptions * optionHeight + (showSearch ? 50 : 0);
            const upwards = below < Math.min(menu.scrollHeight, maxMenuHeight) && above > below;
            const height = Math.min(maxMenuHeight, upwards ? above : below);
            const width = Math.min(Math.max(trigger.width, 220), window.innerWidth - 16);
            Object.assign(menu.style, {
                width: `${width}px`,
                maxHeight: `${height}px`,
                left: `${Math.max(8, Math.min(align === 'right' ? trigger.right - width : trigger.left, window.innerWidth - width - 8))}px`,
                top: `${upwards ? Math.max(8, trigger.top - Math.min(menu.scrollHeight, height) - 6) : trigger.bottom + 6}px`,
            });
        };
        position();
        window.addEventListener('resize', position);
        window.addEventListener('scroll', position, true);
        return () => {
            window.removeEventListener('resize', position);
            window.removeEventListener('scroll', position, true);
        };
    }, [isOpen, overlay, align, options, showSearch]);

    // Đóng dropdown khi click ra ngoài
    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            const target = event.target as Node;
            if (!containerRef.current?.contains(target) && !menuRef.current?.contains(target)) {
                setIsOpen(false);
            }
        };

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
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

    const handleSelect = (option: SelectOption<T>) => {
        if (option.disabled) return;
        onChange(option.value);
        setIsOpen(false);
        containerRef.current?.querySelector('button')?.focus();
    };

    return (
        <div
            className={`relative ${isOpen ? 'z-40' : 'z-10'} ${className}`}
            ref={containerRef}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && isOpen) {
                    event.preventDefault();
                    event.stopPropagation();
                    setIsOpen(false);
                    containerRef.current?.querySelector('button')?.focus();
                    return;
                }
                if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
                // Nếu đang gõ trong search input, không can thiệp phím Home/End
                if (document.activeElement === searchInputRef.current && ['Home', 'End'].includes(event.key)) {
                    return;
                }
                event.preventDefault();
                if (!isOpen) {
                    setIsOpen(true);
                    requestAnimationFrame(() =>
                        (menuRef.current || containerRef.current)?.querySelector<HTMLButtonElement>('[role="option"]:not(:disabled)')?.focus()
                    );
                    return;
                }
                const buttons = Array.from((menuRef.current || containerRef.current)?.querySelectorAll<HTMLButtonElement>('[role="option"]:not(:disabled)') || []);
                const current = buttons.indexOf(document.activeElement as HTMLButtonElement);
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (current + (event.key === 'ArrowUp' ? -1 : 1) + buttons.length) % buttons.length;
                buttons[next]?.focus();
            }}
            onBlur={(event) => {
                if (event.currentTarget.contains(event.relatedTarget) || menuRef.current?.contains(event.relatedTarget as Node)) return;
                setIsOpen(false);
            }}
        >
            {label && (
                <label htmlFor={selectId} className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                    {label} {required && <span aria-hidden="true" className="text-rose-500">*</span>}
                </label>
            )}

            {/* Trigger Button - Chuẩn Style Ảnh */}
            <button
                type="button"
                id={selectId}
                disabled={disabled}
                onClick={() => !disabled && setIsOpen(!isOpen)}
                aria-haspopup="listbox"
                aria-label={label}
                aria-expanded={isOpen}
                className={`w-full px-3 py-2 bg-white border rounded-xl text-xs flex items-center justify-between gap-2 shadow-2xs transition-all text-left cursor-pointer select-none font-medium ${
                    disabled
                        ? 'bg-slate-50 border-slate-200 text-slate-400 cursor-not-allowed opacity-60'
                        : isOpen
                        ? 'border-indigo-500 ring-2 ring-indigo-500/20 text-slate-900'
                        : 'border-slate-200 hover:border-slate-300 text-slate-800'
                } ${buttonClassName}`}
            >
                <div className="flex items-center gap-2 min-w-0 flex-1">
                    {prefixIcon && <span className="shrink-0 text-slate-400">{prefixIcon}</span>}
                    {selectedOption?.icon && (
                        <span className="shrink-0 text-indigo-600">{selectedOption.icon}</span>
                    )}
                    <span className={`truncate ${!selectedOption ? 'text-slate-400' : 'text-slate-900 font-semibold'}`}>
                        {selectedOption ? selectedOption.label : placeholder}
                    </span>
                    {selectedOption?.badge && (
                        <span
                            className={`ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded-md ${
                                selectedOption.badgeColor || 'bg-slate-100 text-slate-600 border border-slate-200'
                            }`}
                        >
                            {selectedOption.badge}
                        </span>
                    )}
                </div>

                <ChevronDown
                    className={`w-4 h-4 text-slate-400 shrink-0 transition-transform duration-150 ${
                        isOpen ? 'rotate-180 text-indigo-600' : ''
                    }`}
                />
            </button>

            {/* Dropdown Menu Panel - Chuẩn Style Đổ xuống không hiệu ứng pop-in */}
            {isOpen && (
                <div
                    ref={menuRef}
                    popover={overlay ? 'manual' : undefined}
                    style={overlay ? { position: 'fixed', inset: 'auto', margin: 0 } : undefined}
                    role="listbox"
                    className={`absolute top-full z-50 mt-1.5 w-full min-w-[220px] bg-white border border-slate-200 rounded-xl shadow-2xl overflow-hidden flex flex-col ${
                        align === 'right' ? 'right-0' : 'left-0'
                    } ${menuClassName}`}
                >
                    {/* Search Input Box */}
                    {showSearch && (
                        <div className="p-2 border-b border-slate-100 bg-slate-50/90">
                            <div className="relative">
                                <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                                <input
                                    ref={searchInputRef}
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder={searchPlaceholder}
                                    className="w-full pl-8 pr-7 py-1.5 text-xs bg-white border border-slate-200 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-indigo-500 font-medium"
                                    onClick={(e) => e.stopPropagation()}
                                    onKeyDown={(e) => e.stopPropagation()}
                                />
                                {searchQuery && (
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setSearchQuery('');
                                            searchInputRef.current?.focus();
                                        }}
                                        className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5"
                                    >
                                        <X className="w-3 h-3" />
                                    </button>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Options List */}
                    <div className="max-h-56 overflow-y-auto custom-scrollbar p-1 text-xs space-y-0.5">
                        {filteredOptions.length ? (
                            filteredOptions.map((option, idx) => {
                                const isSelected = option.value === value;
                                return (
                                    <button
                                        key={`opt-${String(option.value)}-${idx}`}
                                        type="button"
                                        role="option"
                                        aria-selected={isSelected}
                                        disabled={option.disabled}
                                        onClick={() => handleSelect(option)}
                                        className={`w-full px-2.5 py-1.5 rounded-lg flex items-center justify-between text-left transition select-none cursor-pointer ${
                                            option.disabled
                                                ? 'opacity-40 cursor-not-allowed bg-slate-50'
                                                : isSelected
                                                ? 'bg-indigo-600 text-white font-bold shadow-xs'
                                                : 'hover:bg-slate-100 text-slate-800 font-medium'
                                        }`}
                                    >
                                        <div className="flex items-center gap-2 min-w-0 flex-1 truncate pr-2">
                                            {option.icon && (
                                                <span
                                                    className={`shrink-0 ${
                                                        isSelected ? 'text-white' : 'text-slate-400'
                                                    }`}
                                                >
                                                    {option.icon}
                                                </span>
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate">{option.label}</div>
                                                {option.description && (
                                                    <div
                                                        className={`text-[10px] font-normal truncate mt-0.5 ${
                                                            isSelected ? 'text-indigo-100' : 'text-slate-400'
                                                        }`}
                                                    >
                                                        {option.description}
                                                    </div>
                                                )}
                                            </div>
                                            {option.badge && (
                                                <span
                                                    className={`text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0 ${
                                                        isSelected
                                                            ? 'bg-white/20 text-white'
                                                            : option.badgeColor ||
                                                              'bg-slate-100 text-slate-600 border border-slate-200'
                                                    }`}
                                                >
                                                    {option.badge}
                                                </span>
                                            )}
                                        </div>

                                        {isSelected && (
                                            <Check className="w-3.5 h-3.5 text-white shrink-0 ml-1.5" />
                                        )}
                                    </button>
                                );
                            })
                        ) : (
                            <div role="status" className="p-3 text-center text-slate-400 text-xs">
                                Không tìm thấy kết quả phù hợp
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

