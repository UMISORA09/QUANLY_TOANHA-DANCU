import React, { useState, useRef, useEffect, useId } from 'react';
import { ChevronDown, Check } from 'lucide-react';

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
}: CustomSelectProps<T>) {
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const generatedId = useId();
    const selectId = id || generatedId;

    // Tìm option đang được chọn
    const selectedOption = options.find((opt) => opt.value === value);

    // Đóng dropdown khi click ra ngoài
    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
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
        <div className={`relative ${isOpen ? 'z-40' : 'z-10'} ${className}`} ref={containerRef} onKeyDown={(event) => {
            if (event.key === 'Escape' && isOpen) { event.stopPropagation(); setIsOpen(false); containerRef.current?.querySelector('button')?.focus(); return; }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            if (!isOpen) { setIsOpen(true); requestAnimationFrame(() => containerRef.current?.querySelector<HTMLButtonElement>('[role="option"]:not(:disabled)')?.focus()); return; }
            const buttons = Array.from(containerRef.current?.querySelectorAll<HTMLButtonElement>('[role="option"]:not(:disabled)') || []);
            const current = buttons.indexOf(document.activeElement as HTMLButtonElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (current + (event.key === 'ArrowUp' ? -1 : 1) + buttons.length) % buttons.length;
            buttons[next]?.focus();
        }} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setIsOpen(false); }}>
            {label && (
                <label htmlFor={selectId} className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                    {label} {required && <span className="text-rose-500">*</span>}
                </label>
            )}

            {/* Trigger Button */}
            <button
                type="button"
                id={selectId}
                disabled={disabled}
                onClick={() => !disabled && setIsOpen(!isOpen)}
                aria-haspopup="listbox"
                aria-expanded={isOpen}
                className={`w-full px-3 py-2 bg-white border rounded-xl text-xs flex items-center justify-between gap-2 shadow-2xs transition-all text-left cursor-pointer select-none ${
                    disabled
                        ? 'bg-slate-50 border-slate-200 text-slate-400 cursor-not-allowed opacity-60'
                        : isOpen
                        ? 'border-sky-500 ring-2 ring-sky-500/20 text-slate-900'
                        : 'border-slate-200 hover:border-slate-300 text-slate-800'
                } ${buttonClassName}`}
            >
                <div className="flex items-center gap-2 min-w-0 flex-1">
                    {prefixIcon && <span className="shrink-0 text-slate-400">{prefixIcon}</span>}
                    {selectedOption?.icon && (
                        <span className="shrink-0">{selectedOption.icon}</span>
                    )}
                    <span className={`truncate font-medium ${!selectedOption ? 'text-slate-400' : 'text-slate-800'}`}>
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
                    className={`w-3.5 h-3.5 text-slate-400 shrink-0 transition-transform duration-200 ${
                        isOpen ? 'rotate-180 text-sky-600' : ''
                    }`}
                />
            </button>

            {/* Dropdown Menu Panel */}
            {isOpen && (
                <div
                    role="listbox"
                    className={`absolute top-full z-50 mt-1.5 w-full min-w-[200px] bg-white border border-slate-200/90 rounded-xl shadow-xl py-1 max-h-96 overflow-y-auto animate-in fade-in slide-in-from-top-1 duration-150 ${
                        align === 'right' ? 'right-0' : 'left-0'
                    } ${menuClassName}`}
                >
                    {options.map((option, idx) => {
                        const isSelected = option.value === value;
                        return (
                            <button
                                key={`opt-${String(option.value)}-${idx}`}
                                type="button"
                                role="option"
                                aria-selected={isSelected}
                                disabled={option.disabled}
                                onClick={() => handleSelect(option)}
                                className={`w-full px-3 py-2 text-xs flex items-center justify-between gap-2 text-left transition-colors cursor-pointer select-none ${
                                    option.disabled
                                        ? 'opacity-40 cursor-not-allowed bg-slate-50'
                                        : isSelected
                                        ? 'bg-sky-50 text-sky-800 font-semibold'
                                        : 'text-slate-700 hover:bg-slate-50 hover:text-slate-900'
                                }`}
                            >
                                <div className="flex items-center gap-2 min-w-0 flex-1">
                                    {option.icon && (
                                        <span className="shrink-0 text-slate-500">{option.icon}</span>
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate">{option.label}</div>
                                        {option.description && (
                                            <div className="text-[10px] text-slate-400 font-normal truncate mt-0.5">
                                                {option.description}
                                            </div>
                                        )}
                                    </div>
                                    {option.badge && (
                                        <span
                                            className={`text-[10px] font-bold px-1.5 py-0.5 rounded-md shrink-0 ${
                                                option.badgeColor || 'bg-slate-100 text-slate-600 border border-slate-200'
                                            }`}
                                        >
                                            {option.badge}
                                        </span>
                                    )}
                                </div>

                                {isSelected && (
                                    <Check className="w-3.5 h-3.5 text-sky-600 shrink-0 ml-1.5" />
                                )}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
