import React, { useState, useRef, useEffect, useMemo } from 'react';
import { Calendar, ChevronLeft, ChevronRight, X, RotateCcw } from 'lucide-react';

export interface CustomDatePickerProps {
    value: string; // Format: YYYY-MM-DD
    onChange: (dateStr: string) => void;
    placeholder?: string;
    label?: string;
    required?: boolean;
    disabled?: boolean;
    className?: string;
    id?: string;
}

export const CustomDatePicker: React.FC<CustomDatePickerProps> = ({
    value,
    onChange,
    placeholder = 'dd/mm/yyyy',
    label,
    required = false,
    disabled = false,
    className = '',
    id,
}) => {
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    // Phân tích giá trị ngày hiện tại
    const parsedDate = useMemo(() => {
        if (!value) return null;
        const [y, m, d] = value.split('-').map(Number);
        if (y && m && d) {
            return { year: y, month: m - 1, day: d };
        }
        return null;
    }, [value]);

    const today = useMemo(() => {
        const now = new Date();
        return {
            year: now.getFullYear(),
            month: now.getMonth(),
            day: now.getDate(),
        };
    }, []);

    const [viewYear, setViewYear] = useState<number>(parsedDate?.year ?? today.year);
    const [viewMonth, setViewMonth] = useState<number>(parsedDate?.month ?? today.month);

    // Đồng bộ view khi mở lịch
    useEffect(() => {
        if (isOpen) {
            if (parsedDate) {
                setViewYear(parsedDate.year);
                setViewMonth(parsedDate.month);
            } else {
                setViewYear(today.year);
                setViewMonth(today.month);
            }
        }
    }, [isOpen, parsedDate, today]);

    // Đóng khi click ngoài hoặc bấm Escape
    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
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

    const pad = (n: number) => (n < 10 ? `0${n}` : `${n}`);

    const handleSelectDay = (day: number) => {
        const formatted = `${viewYear}-${pad(viewMonth + 1)}-${pad(day)}`;
        onChange(formatted);
        setIsOpen(false);
    };

    const handleSelectToday = () => {
        const formatted = `${today.year}-${pad(today.month + 1)}-${pad(today.day)}`;
        onChange(formatted);
        setIsOpen(false);
    };

    const handleClear = () => {
        onChange('');
        setIsOpen(false);
    };

    const handlePrevMonth = (e: React.MouseEvent) => {
        e.stopPropagation();
        if (viewMonth === 0) {
            setViewMonth(11);
            setViewYear((prev) => prev - 1);
        } else {
            setViewMonth((prev) => prev - 1);
        }
    };

    const handleNextMonth = (e: React.MouseEvent) => {
        e.stopPropagation();
        if (viewMonth === 11) {
            setViewMonth(0);
            setViewYear((prev) => prev + 1);
        } else {
            setViewMonth((prev) => prev + 1);
        }
    };

    // Tính toán ngày trong tháng (bắt đầu tuần từ Thứ 2)
    const calendarDays = useMemo(() => {
        const firstDayIndex = new Date(viewYear, viewMonth, 1).getDay();
        // Chuyển Chủ Nhật (0) thành 6, Thứ 2 (1) thành 0
        const leadingEmptyDays = (firstDayIndex + 6) % 7;
        const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

        return { leadingEmptyDays, daysInMonth };
    }, [viewYear, viewMonth]);

    const displayValue = useMemo(() => {
        if (!value) return '';
        const parts = value.split('-');
        if (parts.length === 3) {
            return `${parts[2]}/${parts[1]}/${parts[0]}`;
        }
        return value;
    }, [value]);

    const monthNames = [
        'Tháng 1',
        'Tháng 2',
        'Tháng 3',
        'Tháng 4',
        'Tháng 5',
        'Tháng 6',
        'Tháng 7',
        'Tháng 8',
        'Tháng 9',
        'Tháng 10',
        'Tháng 11',
        'Tháng 12',
    ];

    const weekDays = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

    return (
        <div className={`relative ${isOpen ? 'z-40' : 'z-10'} ${className}`} ref={containerRef}>
            {label && (
                <label className="block text-xs font-bold text-slate-700 uppercase mb-1.5">
                    {label} {required && <span className="text-rose-500">*</span>}
                </label>
            )}

            {/* Trigger Button */}
            <button
                type="button"
                id={id}
                disabled={disabled}
                onClick={() => !disabled && setIsOpen(!isOpen)}
                className={`w-full px-3 py-2 bg-white border rounded-xl text-xs flex items-center justify-between gap-2 shadow-2xs transition-all text-left cursor-pointer select-none ${
                    disabled
                        ? 'bg-slate-50 border-slate-200 text-slate-400 cursor-not-allowed opacity-60'
                        : isOpen
                        ? 'border-sky-500 ring-2 ring-sky-500/20 text-slate-900'
                        : 'border-slate-200 hover:border-slate-300 text-slate-800'
                }`}
            >
                <span className={`font-mono text-xs ${displayValue ? 'text-slate-800 font-semibold' : 'text-slate-400'}`}>
                    {displayValue || placeholder}
                </span>

                <Calendar
                    className={`w-3.5 h-3.5 transition-colors ${
                        isOpen ? 'text-sky-600' : 'text-slate-400'
                    }`}
                />
            </button>

            {/* Calendar Popup: LUÔN LUÔN XỔ XUỐNG DƯỚI (top-full mt-1.5) */}
            {isOpen && (
                <div className="absolute top-full left-0 z-50 mt-1.5 w-72 bg-white/98 backdrop-blur-xl border border-slate-200 rounded-2xl shadow-2xl p-3.5 select-none animate-in fade-in slide-in-from-top-1 duration-150">
                    {/* Header: Tháng / Năm + Nút điều hướng */}
                    <div className="flex items-center justify-between pb-2 mb-2 border-b border-slate-100">
                        <button
                            type="button"
                            onClick={handlePrevMonth}
                            className="p-1 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors cursor-pointer"
                            title="Tháng trước"
                        >
                            <ChevronLeft className="w-4 h-4" />
                        </button>

                        <div className="font-bold text-xs text-slate-800">
                            {monthNames[viewMonth]} <span className="text-sky-600 font-mono font-black">{viewYear}</span>
                        </div>

                        <button
                            type="button"
                            onClick={handleNextMonth}
                            className="p-1 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors cursor-pointer"
                            title="Tháng sau"
                        >
                            <ChevronRight className="w-4 h-4" />
                        </button>
                    </div>

                    {/* Tiêu đề các ngày trong tuần */}
                    <div className="grid grid-cols-7 gap-1 text-center mb-1">
                        {weekDays.map((wd, i) => (
                            <div
                                key={wd}
                                className={`text-[10px] font-bold py-1 ${
                                    i === 6 ? 'text-rose-500' : 'text-slate-400'
                                }`}
                            >
                                {wd}
                            </div>
                        ))}
                    </div>

                    {/* Lưới các ô ngày */}
                    <div className="grid grid-cols-7 gap-1 text-center">
                        {Array.from({ length: calendarDays.leadingEmptyDays }).map((_, idx) => (
                            <div key={`empty-${idx}`} className="h-7 w-7" />
                        ))}

                        {Array.from({ length: calendarDays.daysInMonth }).map((_, idx) => {
                            const day = idx + 1;
                            const isSelected =
                                parsedDate?.year === viewYear &&
                                parsedDate?.month === viewMonth &&
                                parsedDate?.day === day;
                            const isToday =
                                today.year === viewYear &&
                                today.month === viewMonth &&
                                today.day === day;

                            return (
                                <button
                                    key={`day-${day}`}
                                    type="button"
                                    onClick={() => handleSelectDay(day)}
                                    className={`h-7 w-7 rounded-lg text-xs font-mono transition-all flex items-center justify-center cursor-pointer ${
                                        isSelected
                                            ? 'bg-sky-600 text-white font-bold shadow-xs hover:bg-sky-700'
                                            : isToday
                                            ? 'border border-sky-400 font-bold text-sky-700 bg-sky-50/60 hover:bg-sky-100'
                                            : 'text-slate-700 hover:bg-slate-100 font-medium'
                                    }`}
                                >
                                    {day}
                                </button>
                            );
                        })}
                    </div>

                    {/* Footer: Phím tắt Hôm nay / Xóa */}
                    <div className="flex items-center justify-between pt-2.5 mt-2.5 border-t border-slate-100 text-xs">
                        <button
                            type="button"
                            onClick={handleClear}
                            className="text-slate-400 hover:text-rose-600 font-medium text-[11px] transition-colors cursor-pointer"
                        >
                            Xóa ngày
                        </button>

                        <button
                            type="button"
                            onClick={handleSelectToday}
                            className="text-sky-600 hover:text-sky-700 font-bold text-[11px] transition-colors cursor-pointer"
                        >
                            Hôm nay
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};
